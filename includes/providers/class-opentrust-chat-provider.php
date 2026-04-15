<?php
/**
 * Abstract base class for chat providers.
 *
 * Concrete implementations:
 *  - OpenTrust_Chat_Provider_Anthropic
 *  - OpenTrust_Chat_Provider_OpenAI
 *  - OpenTrust_Chat_Provider_OpenRouter
 *
 * The base class provides the factory, shared HTTP helpers, and a
 * host allowlist for SSRF prevention. Subclasses implement the three
 * provider-specific methods: validate_and_list_models(), stream_chat(),
 * curate_models(), and citation_strategy().
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class OpenTrust_Chat_Provider {

    /**
     * Factory: return a provider instance for a settings slug.
     */
    public static function for(string $provider_slug): ?self {
        return match ($provider_slug) {
            'anthropic'  => new OpenTrust_Chat_Provider_Anthropic(),
            'openai'     => new OpenTrust_Chat_Provider_OpenAI(),
            'openrouter' => new OpenTrust_Chat_Provider_OpenRouter(),
            default      => null,
        };
    }

    /**
     * Return the known provider slugs in display order.
     *
     * @return array<int, array{slug: string, label: string, key_url: string, recommended: bool}>
     */
    public static function available(): array {
        return [
            [
                'slug'        => 'anthropic',
                'label'       => __('Anthropic', 'opentrust'),
                'key_url'     => 'https://console.anthropic.com/settings/keys',
                'recommended' => true,
            ],
            [
                'slug'        => 'openai',
                'label'       => __('OpenAI', 'opentrust'),
                'key_url'     => 'https://platform.openai.com/api-keys',
                'recommended' => false,
            ],
            [
                'slug'        => 'openrouter',
                'label'       => __('OpenRouter', 'opentrust'),
                'key_url'     => 'https://openrouter.ai/settings/keys',
                'recommended' => false,
            ],
        ];
    }

    /**
     * Provider slug (e.g. 'anthropic'). Must match the factory key.
     */
    abstract public function slug(): string;

    /**
     * Human-readable provider name for admin display.
     */
    abstract public function label(): string;

    /**
     * Hard-coded list of allowed hosts this provider may call.
     * Used for SSRF prevention in addition to wp_safe_remote_*.
     *
     * @return array<int, string>
     */
    abstract public function allowed_hosts(): array;

    /**
     * Validate a key by calling the provider's /models endpoint.
     *
     * @return array{ok: bool, models?: array<int, array{id: string, display_name: string, recommended: bool}>, error?: string}
     */
    abstract public function validate_and_list_models(string $key): array;

    /**
     * Stream a chat completion. Wired in story 02.
     *
     * @param array    $args         [ 'system' => string, 'corpus' => array, 'messages' => array, 'tools' => array, 'model' => string ]
     * @param callable $on_chunk     Called with a normalized event: [ 'type' => 'token'|'citation'|'tool_call'|'done'|'error', 'data' => mixed ]
     * @param callable $tool_resolver Called with (string $name, array $args) — returns a string result for the model.
     */
    abstract public function stream_chat(array $args, callable $on_chunk, callable $tool_resolver): void;

    /**
     * Which citation strategy this provider uses:
     *  - 'native'       → Anthropic Citations API
     *  - 'structured'   → Structured output { answer, citations[] }
     */
    abstract public function citation_strategy(): string;

    /**
     * Transform a raw /models response into the admin-facing picker list.
     * Filters out deprecated / non-tool-capable / vision-only models.
     * Marks recommended defaults.
     *
     * @param mixed $raw Raw decoded response body.
     * @return array<int, array{id: string, display_name: string, recommended: bool}>
     */
    abstract public function curate_models(mixed $raw): array;

    // ──────────────────────────────────────────────
    // Shared helpers
    // ──────────────────────────────────────────────

    /**
     * Verify that a URL's host is on this provider's allowlist.
     * Call this BEFORE every outbound HTTP request — belt-and-braces with wp_safe_remote_*.
     */
    final protected function host_allowed(string $url): bool {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return false;
        }
        if ($parts['scheme'] !== 'https') {
            return false;
        }
        return in_array(strtolower($parts['host']), $this->allowed_hosts(), true);
    }

    /**
     * Standard HTTP GET with timeout + host allowlist + consistent error shape.
     *
     * @return array{ok: bool, code?: int, body?: array|string, error?: string}
     */
    final protected function http_get(string $url, array $headers = [], int $timeout = 15): array {
        if (!$this->host_allowed($url)) {
            return ['ok' => false, 'error' => __('Refused outbound request to disallowed host.', 'opentrust')];
        }

        $response = wp_safe_remote_get($url, [
            'timeout'     => $timeout,
            'redirection' => 0,
            'headers'     => $headers,
        ]);

        return $this->normalize_response($response);
    }

    /**
     * Standard HTTP POST with timeout + host allowlist + consistent error shape.
     *
     * @return array{ok: bool, code?: int, body?: array|string, error?: string}
     */
    final protected function http_post(string $url, array $payload, array $headers = [], int $timeout = 60): array {
        if (!$this->host_allowed($url)) {
            return ['ok' => false, 'error' => __('Refused outbound request to disallowed host.', 'opentrust')];
        }

        $headers = array_merge(['Content-Type' => 'application/json'], $headers);

        $response = wp_safe_remote_post($url, [
            'timeout'     => $timeout,
            'redirection' => 0,
            'headers'     => $headers,
            'body'        => wp_json_encode($payload),
        ]);

        return $this->normalize_response($response);
    }

    /**
     * Normalize a WP HTTP response into { ok, code, body, error }.
     * Decodes JSON bodies when present.
     */
    final protected function normalize_response(mixed $response): array {
        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);
        $body    = is_array($decoded) ? $decoded : $raw;

        if ($code < 200 || $code >= 300) {
            return [
                'ok'    => false,
                'code'  => $code,
                'body'  => $body,
                'error' => $this->extract_error_message($body, $code),
            ];
        }

        return ['ok' => true, 'code' => $code, 'body' => $body];
    }

    /**
     * Perform a streaming POST via cURL and invoke $on_line for each complete
     * SSE event (or newline-terminated line). Honors the host allowlist.
     * Returns ['ok' => bool, 'code' => int, 'error' => ?string].
     *
     * $on_line is called with each raw line (including "event: foo" and "data: {...}").
     * The caller is responsible for state tracking between event lines.
     */
    final protected function stream_post(string $url, array $payload, array $headers, callable $on_line, int $timeout = 90): array {
        if (!$this->host_allowed($url)) {
            return ['ok' => false, 'error' => __('Refused outbound request to disallowed host.', 'opentrust')];
        }

        $body = wp_json_encode($payload);
        if ($body === false) {
            return ['ok' => false, 'error' => 'Payload JSON encoding failed.'];
        }

        $curl_headers = ['Content-Type: application/json', 'Accept: text/event-stream'];
        foreach ($headers as $k => $v) {
            $curl_headers[] = $k . ': ' . $v;
        }

        $buffer = '';

        $ch = curl_init();
        // phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt
        curl_setopt($ch, CURLOPT_URL,            $url);
        curl_setopt($ch, CURLOPT_POST,           true);
        curl_setopt($ch, CURLOPT_POSTFIELDS,     $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER,     $curl_headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_HEADER,         false);
        curl_setopt($ch, CURLOPT_TIMEOUT,        $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION,  function ($ch, string $chunk) use (&$buffer, $on_line): int {
            $buffer .= $chunk;

            // Process complete lines. SSE line terminator is LF; events separated by blank line.
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line   = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line   = rtrim($line, "\r");
                $on_line($line);
            }

            // Honor visitor aborts.
            if (function_exists('connection_aborted') && connection_aborted()) {
                return 0; // return value < chunk length aborts curl
            }

            return strlen($chunk);
        });
        // phpcs:enable

        $ok         = curl_exec($ch);
        $http_code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_errno = curl_errno($ch);
        $curl_err   = curl_error($ch);
        curl_close($ch);

        // Flush any trailing line not followed by newline.
        if ($buffer !== '') {
            $on_line(rtrim($buffer, "\r"));
        }

        if ($ok === false && $curl_errno !== 0) {
            return ['ok' => false, 'code' => $http_code, 'error' => $curl_err ?: 'cURL request failed.'];
        }

        if ($http_code < 200 || $http_code >= 300) {
            /* translators: %d: HTTP status code returned by the provider */
            return ['ok' => false, 'code' => $http_code, 'error' => sprintf(__('Provider returned HTTP %d', 'opentrust'), $http_code)];
        }

        return ['ok' => true, 'code' => $http_code];
    }

    /**
     * Pull a human-readable error message out of a provider error body.
     * Subclasses may override for provider-specific shapes.
     */
    protected function extract_error_message(mixed $body, int $code): string {
        if (is_array($body)) {
            // Anthropic: { error: { type, message } }
            if (isset($body['error']) && is_array($body['error']) && !empty($body['error']['message'])) {
                return (string) $body['error']['message'];
            }
            // OpenAI / OpenRouter: { error: { message, type } } or { error: "string" }
            if (isset($body['error']) && is_string($body['error'])) {
                return $body['error'];
            }
            // OpenRouter: { message: "..." }
            if (!empty($body['message']) && is_string($body['message'])) {
                return $body['message'];
            }
        }
        /* translators: %d: HTTP status code returned by the provider */
        return sprintf(__('Provider returned HTTP %d', 'opentrust'), $code);
    }
}
