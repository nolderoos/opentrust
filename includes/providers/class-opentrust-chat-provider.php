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
 * host allowlist for SSRF prevention. Subclasses implement the
 * provider-specific methods: slug(), label(), allowed_hosts(),
 * validate_and_list_models(), stream_chat(), curate_models(), and
 * citation_strategy().
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
     * Auth headers to attach to provider API calls signed with $key.
     * Anthropic uses x-api-key + anthropic-version; OpenAI / OpenRouter use
     * a Bearer token. Subclasses return whatever shape their /models call needs.
     *
     * @return array<string, string>
     */
    abstract protected function auth_headers(string $key): array;

    /**
     * URL of the provider's /models endpoint. Pulled out of the validation
     * method so the shared validate_and_list_models() loop can reach it.
     */
    abstract protected function models_endpoint(): string;

    /**
     * User-facing message when the key validates but the curated model list
     * comes back empty (account not authorized, no chat-capable models, …).
     * Subclasses override when they want a more specific phrasing.
     */
    protected function no_models_message(): string {
        return __('No chat models available for this key.', 'opentrust');
    }

    /**
     * Validate a key by calling the provider's /models endpoint and normalize
     * the response shape. Identical across providers — only auth_headers,
     * models_endpoint, and no_models_message() differ.
     *
     * @return array{ok: bool, models?: array<int, array{id: string, display_name: string, recommended: bool}>, error?: string}
     */
    public function validate_and_list_models(string $key): array {
        $key = trim($key);
        if ($key === '') {
            return ['ok' => false, 'error' => __('API key is empty.', 'opentrust')];
        }

        $response = $this->http_get($this->models_endpoint(), $this->auth_headers($key), 15);

        if (!$response['ok']) {
            return ['ok' => false, 'error' => $response['error'] ?? __('Request failed.', 'opentrust')];
        }

        $models = $this->curate_models($response['body']);

        if (empty($models)) {
            return ['ok' => false, 'error' => $this->no_models_message()];
        }

        return ['ok' => true, 'models' => $models];
    }

    /**
     * Stream a chat completion through the provider, driving the multi-turn
     * tool loop and emitting normalized events to the caller.
     *
     * The loop body is generic: build a per-turn payload, stream it, decide
     * whether the model wants another tool round, append the assistant +
     * tool-result messages, repeat until end_turn or the cap is hit. Provider-
     * specific work (envelope grammars, payload shape, message accumulation)
     * lives in the protected hooks below.
     *
     * Subclasses may override stream_chat() entirely while a phased migration
     * is in flight; once every subclass implements the hooks, the base body
     * becomes the single source of truth.
     *
     * @param array    $args         [ 'system' => string, 'corpus' => array, 'messages' => array, 'tools' => array, 'model' => string ]
     * @param callable $on_chunk     Called with a normalized event: [ 'type' => 'token'|'citation'|'tool_call'|'done'|'error', 'data' => mixed ]
     * @param callable $tool_resolver Called with (string $name, array $args) — returns search_result blocks.
     */
    public function stream_chat(array $args, callable $on_chunk, callable $tool_resolver): void {
        $turn_loop_state = $this->initialize_turn_loop($args, $on_chunk);
        if ($turn_loop_state === null) {
            return; // initialize_turn_loop already emitted an error event
        }

        $documents = is_array($args['corpus']['documents'] ?? null)
            ? $args['corpus']['documents']
            : [];

        // Force a tool call on turn 1 so the model can never bypass retrieval
        // and answer from training data. After any tool call we relax to auto.
        $has_called_tool = false;

        for ($turn = 1; $turn <= OpenTrust_Chat::MAX_TOOL_TURNS; $turn++) {
            $stream_state = $this->stream_one_turn($turn_loop_state, $has_called_tool, $on_chunk);
            if ($stream_state === null) {
                return; // stream_one_turn already emitted an error event
            }

            $on_chunk(['type' => 'usage', 'data' => $this->extract_usage($stream_state)]);

            $pending = $this->extract_pending_tool_calls($stream_state);
            if (empty($pending)) {
                $this->finalize_after_loop($turn_loop_state, $stream_state, $documents, $on_chunk);
                return;
            }

            $has_called_tool = true;

            // ONE aggregated tool_call event per turn so the UI can render a
            // single morphing pill rather than N pills on parallel-tool turns.
            $on_chunk([
                'type' => 'tool_call',
                'data' => $this->build_tool_call_event_data($pending, $documents),
            ]);

            $this->append_assistant_message($turn_loop_state, $stream_state);
            $this->resolve_and_append_tool_results($turn_loop_state, $pending, $tool_resolver);
        }

        $this->emit_cap_refusal($on_chunk);
    }

    // ──────────────────────────────────────────────
    // Turn-loop hooks (provider-implementable)
    // ──────────────────────────────────────────────

    /**
     * Build provider-specific turn-loop state from the request args. The
     * returned array is mutated by reference across hooks (api_messages,
     * tools payload, system_full, model, …). Return null after emitting an
     * 'error' event when the args are invalid.
     */
    protected function initialize_turn_loop(array $args, callable $on_chunk): ?array {
        return null;
    }

    /**
     * Stream one turn through the provider. Build the payload from
     * $turn_loop_state, POST it via stream_post, and let the provider-specific
     * SSE parser fill a state array (token deltas may be emitted live to
     * $on_chunk during this call). Return null after emitting an 'error' event
     * on hard failure.
     */
    protected function stream_one_turn(array &$turn_loop_state, bool $has_called_tool, callable $on_chunk): ?array {
        return null;
    }

    /**
     * Convert provider-shaped usage from one turn into the canonical shape the
     * Stream_Collector expects: {tokens_in, tokens_out, cache_creation, cache_read}.
     */
    protected function extract_usage(array $stream_state): array {
        return ['tokens_in' => 0, 'tokens_out' => 0];
    }

    /**
     * Extract the tool calls the model wants to make this turn, normalized
     * for the base loop. Each entry must include 'name' and 'args' (decoded
     * argument array). Subclasses may stash provider-specific fields under
     * other keys (e.g. 'id', 'raw') for the message-append hooks to read.
     *
     * @return array<int, array{name:string, args:array}>
     */
    protected function extract_pending_tool_calls(array $stream_state): array {
        return [];
    }

    /**
     * Append the assistant message produced by this turn to the running
     * message list inside $turn_loop_state. Anthropic serializes from its
     * content_blocks state into assistant.content[]; OpenAI emits a single
     * assistant message with tool_calls + content.
     */
    protected function append_assistant_message(array &$turn_loop_state, array $stream_state): void {
    }

    /**
     * Resolve each pending tool via $tool_resolver and append the results to
     * $turn_loop_state in the provider's message shape (Anthropic: user-role
     * with tool_result content blocks; OpenAI: one 'tool'-role message per
     * call with XML-serialized text).
     *
     * @param array<int, array{name:string, args:array}> $pending
     */
    protected function resolve_and_append_tool_results(array &$turn_loop_state, array $pending, callable $tool_resolver): void {
    }

    /**
     * Run when the loop ended without another tool call — i.e. the model
     * produced a final answer. Anthropic emits citations live during the
     * stream so this is a no-op for it; OpenAI scans the accumulated answer
     * for [[cite:id]] markers and emits citation events here.
     */
    protected function finalize_after_loop(array $turn_loop_state, array $stream_state, array $documents, callable $on_chunk): void {
    }

    /**
     * Soft refusal token emitted when the per-request tool-turn cap is hit.
     * The wording starts with "I couldn't find" so detect_refusal() trips and
     * the UI shows the contact-team CTA instead of an error banner. Final on
     * the base so a misbehaving subclass cannot drift the wording away from
     * the detect_refusal() marker list.
     */
    final protected function emit_cap_refusal(callable $on_chunk): void {
        $on_chunk([
            'type' => 'token',
            'data' => [
                'text' => __("I couldn't find a confident answer in the published trust center documents. Please contact the team for help.", 'opentrust'),
            ],
        ]);
    }

    /**
     * Build the data block for a 'tool_call' SSE event. Always emits both
     * `summary` (active tense) and `settled_summary` (past tense) so the JS
     * pill can morph from "Searching for X" to "Searched for X" uniformly
     * across providers. Subclasses may override if they need a different
     * shape, but the default works for both Anthropic and OpenAI.
     *
     * @param array<int, array{name:string, args:array}> $pending  Normalized.
     * @param array<int, array<string, mixed>>           $documents Corpus docs.
     */
    protected function build_tool_call_event_data(array $pending, array $documents): array {
        $count = count($pending);
        $names = array_map(static fn(array $p): string => (string) ($p['name'] ?? ''), $pending);

        if ($count === 1) {
            $only            = $pending[0];
            $only_args       = is_array($only['args'] ?? null) ? $only['args'] : [];
            $only_name       = (string) ($only['name'] ?? '');
            $summary         = self::summarize_tool_call($only_name, $only_args, $documents, 'pending');
            $settled_summary = self::summarize_tool_call($only_name, $only_args, $documents, 'settled');
        } else {
            $summary         = self::summarize_turn_batch($names, $count, 'pending');
            $settled_summary = self::summarize_turn_batch($names, $count, 'settled');
        }

        return [
            'name'            => $names[0] ?? 'tool_call',
            'summary'         => $summary,
            'settled_summary' => $settled_summary,
            'count'           => $count,
            'names'           => $names,
        ];
    }

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
    // Tool-call summarizers (shared across providers)
    // ──────────────────────────────────────────────

    /**
     * Build a short user-facing label for a `tool_call` SSE event so the UI
     * can replace "Thinking…" with something specific. Pure formatting; never
     * fails — falls back to the tool name if anything is missing.
     *
     * @param 'pending'|'settled' $tense Verb tense for the rendered phrase.
     * @param array<int, array<string, mixed>> $documents Corpus document list,
     *        used to look up a friendly title for `get_document` calls.
     */
    protected static function summarize_tool_call(string $name, array $args, array $documents, string $tense = 'pending'): string {
        $is_settled = $tense === 'settled';

        if ($name === 'get_document') {
            $id = trim((string) ($args['id'] ?? ''));
            if ($id !== '') {
                foreach ($documents as $doc) {
                    if ((string) ($doc['id'] ?? '') === $id) {
                        $title = (string) ($doc['title'] ?? '');
                        if ($title !== '') {
                            return $is_settled
                                /* translators: %s is the document title. */
                                ? sprintf(__('Read "%s"', 'opentrust'), $title)
                                /* translators: %s is the document title. */
                                : sprintf(__('Reading "%s"', 'opentrust'), $title);
                        }
                    }
                }
                return $is_settled
                    /* translators: %s is the document id. */
                    ? sprintf(__('Read %s', 'opentrust'), $id)
                    /* translators: %s is the document id. */
                    : sprintf(__('Reading %s', 'opentrust'), $id);
            }
            return $is_settled ? __('Read a document', 'opentrust') : __('Reading a document', 'opentrust');
        }

        if ($name === 'search_documents') {
            $q = trim((string) ($args['query'] ?? ''));
            if ($q !== '') {
                if (function_exists('mb_strlen') && mb_strlen($q) > 30) {
                    $q = rtrim(mb_substr($q, 0, 30)) . '…';
                } elseif (strlen($q) > 30) {
                    $q = rtrim(substr($q, 0, 30)) . '…';
                }
                return $is_settled
                    /* translators: %s is the search query. */
                    ? sprintf(__('Searched for "%s"', 'opentrust'), $q)
                    /* translators: %s is the search query. */
                    : sprintf(__('Searching for "%s"', 'opentrust'), $q);
            }
            return $is_settled ? __('Searched documents', 'opentrust') : __('Searching documents', 'opentrust');
        }

        return $name;
    }

    /**
     * Aggregate label for a turn carrying multiple parallel tool calls.
     * Single-tool turns keep the specific label ("Reading Privacy Policy");
     * multi-tool turns collapse to a count ("Reading 8 documents") so the UI
     * can show one morphing pill instead of a wall of per-tool pills.
     *
     * @param array<int, string> $names Tool names emitted in this turn.
     * @param 'pending'|'settled' $tense
     */
    protected static function summarize_turn_batch(array $names, int $count, string $tense = 'pending'): string {
        $is_settled = $tense === 'settled';

        $all_get    = true;
        $all_search = true;
        foreach ($names as $n) {
            if ($n !== 'get_document')     { $all_get    = false; }
            if ($n !== 'search_documents') { $all_search = false; }
        }

        if ($all_get) {
            return $is_settled
                /* translators: %d is the number of documents that were read in parallel. */
                ? sprintf(__('Read %d documents', 'opentrust'), $count)
                /* translators: %d is the number of documents being read in parallel. */
                : sprintf(__('Reading %d documents', 'opentrust'), $count);
        }
        if ($all_search) {
            return $is_settled
                /* translators: %d is the number of search queries that were fired in parallel. */
                ? sprintf(__('Ran %d searches', 'opentrust'), $count)
                /* translators: %d is the number of search queries fired in parallel. */
                : sprintf(__('Running %d searches', 'opentrust'), $count);
        }
        return $is_settled
            /* translators: %d is the number of parallel retrieval calls (mixed types). */
            ? sprintf(__('Ran %d retrievals', 'opentrust'), $count)
            /* translators: %d is the number of parallel retrieval calls (mixed types). */
            : sprintf(__('Running %d retrievals', 'opentrust'), $count);
    }

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

        $buffer           = '';
        $error_body       = '';
        $response_headers = [];
        $early_status     = 0;

        // phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_errno, WordPress.WP.AlternativeFunctions.curl_curl_error, WordPress.WP.AlternativeFunctions.curl_curl_close -- SSE streaming requires CURLOPT_WRITEFUNCTION; wp_remote_* does not support streaming callbacks.
        $ch = curl_init();
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
        curl_setopt($ch, CURLOPT_HEADERFUNCTION,  function ($ch, string $line) use (&$early_status, &$response_headers): int {
            $len     = strlen($line);
            $trimmed = trim($line);
            if ($trimmed === '') {
                return $len;
            }
            if (preg_match('#^HTTP/[\d.]+\s+(\d{3})#', $trimmed, $m)) {
                $early_status = (int) $m[1];
                return $len;
            }
            $colon = strpos($trimmed, ':');
            if ($colon !== false) {
                $k = strtolower(trim(substr($trimmed, 0, $colon)));
                $v = trim(substr($trimmed, $colon + 1));
                $response_headers[$k] = $v;
            }
            return $len;
        });
        curl_setopt($ch, CURLOPT_WRITEFUNCTION,  function ($ch, string $chunk) use (&$buffer, &$error_body, &$early_status, $on_line): int {
            // Non-2xx response — accumulate the body as an error payload
            // instead of feeding it to the SSE parser. Anthropic returns
            // plain JSON like {"type":"error","error":{"type":"...","message":"..."}}
            // on errors, which doesn't match the SSE line shape and would
            // otherwise be silently dropped.
            if ($early_status >= 300) {
                $error_body .= $chunk;
                if (function_exists('connection_aborted') && connection_aborted()) {
                    return 0;
                }
                return strlen($chunk);
            }

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

        $ok         = curl_exec($ch);
        $http_code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_errno = curl_errno($ch);
        $curl_err   = curl_error($ch);
        curl_close($ch);
        // phpcs:enable

        // Flush any trailing line not followed by newline — but only on success;
        // on an error response the buffer holds the JSON error body, which the
        // SSE parser would mis-interpret.
        if ($buffer !== '' && $http_code >= 200 && $http_code < 300) {
            $on_line(rtrim($buffer, "\r"));
        }

        if ($ok === false && $curl_errno !== 0) {
            return ['ok' => false, 'code' => $http_code, 'error' => $curl_err ?: 'cURL request failed.'];
        }

        if ($http_code < 200 || $http_code >= 300) {
            // Some hosts return the body via WRITEFUNCTION before
            // HEADERFUNCTION has set $early_status; fall back to $buffer.
            if ($error_body === '' && $buffer !== '') {
                $error_body = $buffer;
            }
            $detail = $this->describe_streaming_error($error_body, $response_headers, $http_code);
            // Log unconditionally so post-mortem doesn't depend on whether
            // the message survived translation to the client.
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for upstream provider failures
            error_log('[OpenTrust] ' . $detail);
            return ['ok' => false, 'code' => $http_code, 'error' => $detail];
        }

        return ['ok' => true, 'code' => $http_code];
    }

    /**
     * Build a human-readable error string from a streaming provider's non-2xx
     * response body + headers. Falls through several common JSON shapes
     * (Anthropic, OpenAI, OpenRouter) before giving up and showing a truncated
     * raw body. Surfaces rate-limit hints (retry-after, anthropic-ratelimit-*)
     * inline so the user / log immediately shows which limit was hit.
     */
    final protected function describe_streaming_error(string $body, array $headers, int $code): string {
        /* translators: %d: HTTP status code returned by the provider */
        $parts = [sprintf(__('Provider returned HTTP %d', 'opentrust'), $code)];

        $body = trim($body);
        if ($body !== '') {
            $decoded = json_decode($body, true);
            $detail  = '';
            if (is_array($decoded)) {
                if (isset($decoded['error']) && is_array($decoded['error'])) {
                    $type = (string) ($decoded['error']['type']    ?? '');
                    $msg  = (string) ($decoded['error']['message'] ?? '');
                    $detail = trim(($type !== '' ? "{$type}: " : '') . $msg);
                } elseif (isset($decoded['error']) && is_string($decoded['error'])) {
                    $detail = (string) $decoded['error'];
                } elseif (!empty($decoded['message']) && is_string($decoded['message'])) {
                    $detail = (string) $decoded['message'];
                }
            }
            if ($detail === '') {
                $detail = strlen($body) > 500 ? substr($body, 0, 500) . '…' : $body;
            }
            $parts[] = $detail;
        }

        if (!empty($headers['retry-after'])) {
            $parts[] = 'retry-after: ' . $headers['retry-after'];
        }
        foreach ($headers as $k => $v) {
            if (str_starts_with($k, 'anthropic-ratelimit-') || str_starts_with($k, 'x-ratelimit-')) {
                $parts[] = $k . ': ' . $v;
            }
        }

        return implode(' | ', $parts);
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
