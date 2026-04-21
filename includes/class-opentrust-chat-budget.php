<?php
/**
 * Budget + rate-limit enforcement for the chat REST route.
 *
 * Three layers, from inner to outer:
 *
 *  1. Hard token budget (daily + monthly) — the only *guaranteed* cost ceiling.
 *     Uses a reserve / commit / release pattern so concurrent requests cannot
 *     both pass the cap check and collectively exceed it by more than a handful
 *     of requests.
 *
 *  2. Per-IP sliding window (60s) and per-session sliding window (3600s) rate
 *     limits, implemented as transients of timestamp arrays.
 *
 *  3. Optional Cloudflare Turnstile verification on the first message of every
 *     session. Successful verification sets a 1h transient that skips the
 *     challenge on subsequent messages.
 *
 * Identifiers (IP / session) are hashed with a per-site salt so raw values are
 * never persisted anywhere.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OpenTrust_Chat_Budget {

    // ──────────────────────────────────────────────
    // Token budget
    // ──────────────────────────────────────────────

    /**
     * Reserve $tokens against today's and this month's budget. Returns true if
     * both caps are still under their limit AFTER the reservation.
     * If the reservation would exceed either cap, nothing is consumed and false
     * is returned so the caller can emit a budget-exhausted response.
     */
    public static function check_and_reserve(int $tokens): bool {
        if ($tokens <= 0) {
            $tokens = 1;
        }

        $settings = OpenTrust::get_settings();
        $daily_cap   = (int) ($settings['ai_daily_token_budget']   ?? 500000);
        $monthly_cap = (int) ($settings['ai_monthly_token_budget'] ?? 10000000);

        // Both caps equal to 0 = unlimited (admin opt-out). Treated as unlimited.
        if ($daily_cap === 0 && $monthly_cap === 0) {
            return true;
        }

        $day_key   = self::daily_key();
        $month_key = self::monthly_key();

        $day_used   = (int) get_transient($day_key);
        $month_used = (int) get_transient($month_key);

        if ($daily_cap > 0 && $day_used + $tokens > $daily_cap) {
            return false;
        }
        if ($monthly_cap > 0 && $month_used + $tokens > $monthly_cap) {
            return false;
        }

        set_transient($day_key,   $day_used   + $tokens, self::daily_ttl());
        set_transient($month_key, $month_used + $tokens, self::monthly_ttl());
        return true;
    }

    /**
     * Commit the actual token cost after a successful stream.
     * Adjusts the reserved amount upward or downward to match.
     */
    public static function commit(int $reserved, int $actual): void {
        $diff = $actual - $reserved;
        if ($diff === 0) {
            return;
        }
        self::adjust_by($diff);
    }

    /**
     * Release a prior reservation when the request failed before consuming
     * any tokens.
     */
    public static function release(int $reserved): void {
        if ($reserved <= 0) {
            return;
        }
        self::adjust_by(-$reserved);
    }

    private static function adjust_by(int $delta): void {
        $day_key   = self::daily_key();
        $month_key = self::monthly_key();

        $day_used   = max(0, (int) get_transient($day_key)   + $delta);
        $month_used = max(0, (int) get_transient($month_key) + $delta);

        set_transient($day_key,   $day_used,   self::daily_ttl());
        set_transient($month_key, $month_used, self::monthly_ttl());
    }

    public static function daily_key(): string {
        return 'opentrust_chat_budget_day_' . gmdate('Y-m-d');
    }
    public static function monthly_key(): string {
        return 'opentrust_chat_budget_month_' . gmdate('Y-m');
    }
    public static function daily_ttl(): int {
        return 25 * HOUR_IN_SECONDS;
    }
    public static function monthly_ttl(): int {
        return 32 * DAY_IN_SECONDS;
    }

    /**
     * Unix timestamp at which the daily budget resets (for Retry-After headers).
     */
    public static function daily_reset_at(): int {
        return strtotime('tomorrow 00:00 UTC') ?: (time() + DAY_IN_SECONDS);
    }

    // ──────────────────────────────────────────────
    // Rate limits
    // ──────────────────────────────────────────────

    /**
     * Check + record a rate-limit hit for the given IP hash.
     * Returns ['ok' => true] or ['ok' => false, 'retry_after' => seconds].
     */
    public static function check_ip_rate_limit(string $ip_hash): array {
        $settings = OpenTrust::get_settings();
        $limit    = (int) ($settings['ai_rate_limit_per_ip'] ?? 10);
        if ($limit <= 0) {
            return ['ok' => true];
        }

        $key  = 'opentrust_chat_rl_ip_' . $ip_hash;
        $now  = time();
        $list = get_transient($key);
        if (!is_array($list)) {
            $list = [];
        }

        // Drop timestamps older than 60s.
        $window_start = $now - 60;
        $list = array_values(array_filter($list, static fn($t) => (int) $t >= $window_start));

        if (count($list) >= $limit) {
            $oldest = (int) $list[0];
            return ['ok' => false, 'retry_after' => max(1, $oldest + 60 - $now)];
        }

        $list[] = $now;
        set_transient($key, $list, 2 * MINUTE_IN_SECONDS);
        return ['ok' => true];
    }

    /**
     * Check + record a rate-limit hit for the given session hash.
     */
    public static function check_session_rate_limit(string $session_hash): array {
        $settings = OpenTrust::get_settings();
        $limit    = (int) ($settings['ai_rate_limit_per_session'] ?? 50);
        if ($limit <= 0) {
            return ['ok' => true];
        }

        $key  = 'opentrust_chat_rl_session_' . $session_hash;
        $now  = time();
        $list = get_transient($key);
        if (!is_array($list)) {
            $list = [];
        }

        $window_start = $now - 3600;
        $list = array_values(array_filter($list, static fn($t) => (int) $t >= $window_start));

        if (count($list) >= $limit) {
            $oldest = (int) $list[0];
            return ['ok' => false, 'retry_after' => max(1, $oldest + 3600 - $now)];
        }

        $list[] = $now;
        set_transient($key, $list, 2 * HOUR_IN_SECONDS);
        return ['ok' => true];
    }

    // ──────────────────────────────────────────────
    // Turnstile
    // ──────────────────────────────────────────────

    public static function turnstile_required(array $settings): bool {
        return !empty($settings['ai_turnstile_enabled'])
            && !empty($settings['turnstile_site_key'])
            && !empty($settings['turnstile_secret_key']);
    }

    /**
     * Return true if this session has a valid Turnstile verification on file.
     */
    public static function turnstile_session_verified(string $session_hash): bool {
        return (bool) get_transient('opentrust_chat_turnstile_' . $session_hash);
    }

    /**
     * Verify a Turnstile token against Cloudflare's siteverify endpoint.
     * On success, marks the session as verified for 1 hour.
     */
    public static function verify_turnstile_token(string $token, string $secret, string $session_hash, string $remote_ip_hash): bool {
        $token = trim($token);
        if ($token === '' || $secret === '') {
            return false;
        }

        $response = wp_safe_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'timeout' => 10,
            'body'    => [
                'secret'   => $secret,
                'response' => $token,
                // Do NOT send remoteip — we don't have raw IP, only hash.
            ],
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['success'])) {
            return false;
        }

        set_transient('opentrust_chat_turnstile_' . $session_hash, 1, HOUR_IN_SECONDS);
        return true;
    }

    // ──────────────────────────────────────────────
    // Hashing helpers
    // ──────────────────────────────────────────────

    /**
     * Return the per-site salt used for IP / session hashing. Lazy-generated
     * and persisted in opentrust_settings on first use.
     */
    public static function site_salt(): string {
        $settings = OpenTrust::get_settings();
        $salt     = (string) ($settings['opentrust_site_salt'] ?? '');
        if ($salt === '') {
            $salt = wp_generate_password(64, true, true);
            $settings['opentrust_site_salt'] = $salt;
            update_option('opentrust_settings', $settings);
        }
        return $salt;
    }

    public static function hash_ip(string $ip): string {
        if ($ip === '') {
            $ip = '0.0.0.0';
        }
        return substr(hash('sha256', $ip . '|' . self::site_salt()), 0, 16);
    }

    public static function hash_session(string $session_token): string {
        if ($session_token === '') {
            return '';
        }
        return substr(hash('sha256', $session_token . '|' . self::site_salt()), 0, 16);
    }

    /**
     * Best-effort visitor IP extraction. We only care about it to compute a hash.
     */
    public static function visitor_ip(): string {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- preg_replace below strips to [0-9a-f\.:].
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $ip = preg_replace('/[^0-9a-f\.:]/i', '', $ip);
        return (string) $ip;
    }

    /**
     * Read the short-lived session cookie (or empty string if none set).
     */
    public static function session_token(): string {
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- preg_replace below strips to [a-zA-Z0-9].
        return isset($_COOKIE['opentrust_chat_session'])
            ? preg_replace('/[^a-zA-Z0-9]/', '', (string) $_COOKIE['opentrust_chat_session'])
            : '';
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    }

    /**
     * Ensure the visitor has a session cookie. Called from render_chat_page()
     * (so it runs before headers are sent for the page).
     */
    public static function ensure_session_cookie(): string {
        $existing = self::session_token();
        if ($existing !== '' && strlen($existing) >= 32) {
            return $existing;
        }

        $token = wp_generate_password(48, false, false);
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Setting a generated token, not reading user input
        $_COOKIE['opentrust_chat_session'] = $token;

        setcookie('opentrust_chat_session', $token, [
            'expires'  => time() + DAY_IN_SECONDS,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return $token;
    }
}
