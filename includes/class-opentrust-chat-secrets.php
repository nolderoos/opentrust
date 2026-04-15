<?php
/**
 * Secure encryption for provider API keys.
 *
 * Uses libsodium (sodium_crypto_secretbox) with a key derived from wp_salt('auth').
 * Ciphertext is stored as "ot_enc_v1:<base64(nonce || ciphertext)>" so future
 * key-derivation rotations can be detected via the sentinel prefix.
 *
 * Properties:
 * - Authenticated encryption (Poly1305 MAC) — tampered ciphertext returns null.
 * - Encryption key lives in wp-config.php (AUTH_KEY), never in the database.
 * - Rotating AUTH_KEY invalidates all stored keys; admin re-enters them.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OpenTrust_Chat_Secrets {

    private const SENTINEL = 'ot_enc_v1:';

    /**
     * Derive the 32-byte encryption key from wp_salt('auth').
     */
    private static function key(): string {
        return hash('sha256', wp_salt('auth') . 'opentrust-chat-v1', true);
    }

    /**
     * Encrypt a plaintext string. Returns a sentinel-prefixed base64 blob.
     */
    public static function encrypt(string $plain): string {
        if ($plain === '') {
            return '';
        }

        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, self::key());

        return self::SENTINEL . base64_encode($nonce . $cipher);
    }

    /**
     * Decrypt a stored ciphertext blob. Returns null on any failure
     * (tampered ciphertext, wrong sentinel, invalid base64, wrong key).
     */
    public static function decrypt(string $stored): ?string {
        if ($stored === '' || !str_starts_with($stored, self::SENTINEL)) {
            return null;
        }

        $raw = base64_decode(substr($stored, strlen(self::SENTINEL)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        try {
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::key());
        } catch (\SodiumException $e) {
            return null;
        }

        return $plain === false ? null : $plain;
    }

    /**
     * Return a masked representation of a plaintext key for admin display.
     * Never echoes more than the first 3 and last 4 characters.
     */
    public static function mask(string $plain): string {
        $len = strlen($plain);
        if ($len === 0) {
            return '';
        }
        if ($len <= 8) {
            return str_repeat('•', max(4, $len));
        }
        return substr($plain, 0, 3) . str_repeat('•', 8) . substr($plain, -4);
    }

    /**
     * Short, non-reversible fingerprint of a key — used in transient cache keys
     * (opentrust_models_{provider}_{key_hash}) so we never persist the raw key.
     */
    public static function fingerprint(string $plain): string {
        return substr(hash('sha256', $plain), 0, 16);
    }

    /**
     * Read all stored provider keys (plaintext). Always hits the DB —
     * the containing option has autoload=no so this is not called per-request.
     *
     * @return array<string, string> Keys by provider slug.
     */
    public static function get_all(): array {
        $stored = get_option('opentrust_provider_keys', []);
        if (!is_array($stored)) {
            return [];
        }

        $out = [];
        foreach ($stored as $provider => $cipher) {
            if (!is_string($provider) || !is_string($cipher)) {
                continue;
            }
            $plain = self::decrypt($cipher);
            if ($plain !== null) {
                $out[$provider] = $plain;
            }
        }
        return $out;
    }

    /**
     * Read a single provider's plaintext key, or null if not set or undecryptable.
     */
    public static function get(string $provider): ?string {
        $all = self::get_all();
        return $all[$provider] ?? null;
    }

    /**
     * Store an encrypted key for a provider. Preserves other providers' keys.
     */
    public static function put(string $provider, string $plain): bool {
        if ($provider === '' || $plain === '') {
            return false;
        }

        $stored = get_option('opentrust_provider_keys', []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $stored[$provider] = self::encrypt($plain);

        return update_option('opentrust_provider_keys', $stored, false);
    }

    /**
     * Remove a provider's stored key. Returns true if the key existed and was removed.
     */
    public static function forget(string $provider): bool {
        $stored = get_option('opentrust_provider_keys', []);
        if (!is_array($stored) || !isset($stored[$provider])) {
            return false;
        }

        unset($stored[$provider]);
        update_option('opentrust_provider_keys', $stored, false);
        return true;
    }
}
