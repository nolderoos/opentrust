<?php
/**
 * Chat orchestration singleton.
 *
 * Registers the POST /wp-json/opentrust/v1/chat REST route, wires corpus
 * invalidation to CPT save events, provides the tool surface (static method),
 * and implements the handler that dispatches to the configured provider
 * adapter via either a streaming (SSE) or a blocking (JSON fallback) transport.
 *
 * Budget enforcement, rate limiting, and Turnstile verification hook into
 * `permission_callback` in story 04. This story only verifies the REST nonce.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OpenTrust_Chat {

    public const REST_NAMESPACE = 'opentrust/v1';
    public const REST_ROUTE     = '/chat';
    public const MAX_TOOL_TURNS = 4;

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);

        // Corpus cache invalidation.
        foreach (['ot_policy', 'ot_certification', 'ot_subprocessor', 'ot_data_practice'] as $cpt) {
            add_action("save_post_{$cpt}", [OpenTrust_Chat_Corpus::class, 'invalidate']);
        }
        add_action('deleted_post',  [OpenTrust_Chat_Corpus::class, 'invalidate']);
        add_action('trashed_post',  [OpenTrust_Chat_Corpus::class, 'invalidate']);
        add_action('untrashed_post',[OpenTrust_Chat_Corpus::class, 'invalidate']);
        add_action('transition_post_status', [$this, 'maybe_invalidate_corpus_on_transition'], 10, 3);
        add_action('update_option_opentrust_settings', [OpenTrust_Chat_Corpus::class, 'invalidate']);
    }

    /**
     * Flush the corpus only when an OpenTrust CPT transitions into or out of publish.
     */
    public function maybe_invalidate_corpus_on_transition(string $new, string $old, \WP_Post $post): void {
        if (!str_starts_with($post->post_type, 'ot_')) {
            return;
        }
        if ($new === 'publish' || $old === 'publish') {
            OpenTrust_Chat_Corpus::invalidate();
        }
    }

    // ──────────────────────────────────────────────
    // REST
    // ──────────────────────────────────────────────

    public function register_routes(): void {
        register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, [
            'methods'             => WP_REST_Server::CREATABLE, // POST
            'callback'            => [$this, 'handle_chat'],
            'permission_callback' => [$this, 'permission_callback'],
            'args'                => [
                'messages' => [
                    'required' => true,
                    'type'     => 'array',
                ],
                'turnstile_token' => [
                    'required' => false,
                    'type'     => 'string',
                ],
            ],
        ]);
    }

    /**
     * Four-gate check: nonce → Turnstile → rate limits → (budget is checked inside handle_chat
     * using a reserve/commit/release pattern so the cap is enforced atomically).
     */
    public function permission_callback(WP_REST_Request $request): bool|WP_Error {
        // Gate 1: REST nonce.
        $nonce = $request->get_header('x_wp_nonce');
        if ($nonce === null || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error(
                'rest_forbidden',
                __('Invalid nonce — refresh the page and try again.', 'opentrust'),
                ['status' => 403]
            );
        }

        $settings     = OpenTrust::get_settings();
        $ip_hash      = OpenTrust_Chat_Budget::hash_ip(OpenTrust_Chat_Budget::visitor_ip());
        $session_tok  = OpenTrust_Chat_Budget::session_token();
        $session_hash = OpenTrust_Chat_Budget::hash_session($session_tok);

        // Gate 2: Turnstile (if enabled and session not yet verified).
        if (OpenTrust_Chat_Budget::turnstile_required($settings)) {
            if (!OpenTrust_Chat_Budget::turnstile_session_verified($session_hash)) {
                // The secret is stored as a libsodium ciphertext blob in
                // opentrust_settings; decrypt at the edge — if decryption
                // fails we fail closed and surface the challenge error.
                $stored_secret = (string) ($settings['turnstile_secret_key'] ?? '');
                $secret        = OpenTrust_Chat_Secrets::decrypt($stored_secret) ?? '';
                $token         = (string) $request->get_param('turnstile_token');
                $ok = $secret !== '' && OpenTrust_Chat_Budget::verify_turnstile_token(
                    $token,
                    $secret,
                    $session_hash,
                    $ip_hash
                );
                if (!$ok) {
                    return new WP_Error(
                        'ai_turnstile_required',
                        __('Please complete the anti-abuse challenge and try again.', 'opentrust'),
                        ['status' => 403]
                    );
                }
            }
        }

        // Gate 3a: per-IP rate limit.
        $ip_check = OpenTrust_Chat_Budget::check_ip_rate_limit($ip_hash);
        if (empty($ip_check['ok'])) {
            return new WP_Error(
                'ai_rate_limited_ip',
                __('You are sending messages too fast. Please wait a moment and try again.', 'opentrust'),
                ['status' => 429, 'retry_after' => $ip_check['retry_after'] ?? 30]
            );
        }

        // Gate 3b: per-session rate limit.
        if ($session_hash !== '') {
            $sess_check = OpenTrust_Chat_Budget::check_session_rate_limit($session_hash);
            if (empty($sess_check['ok'])) {
                return new WP_Error(
                    'ai_rate_limited_session',
                    __('You have reached the per-session message limit. Please wait a bit and try again.', 'opentrust'),
                    ['status' => 429, 'retry_after' => $sess_check['retry_after'] ?? 60]
                );
            }
        }

        return true;
    }

    // ──────────────────────────────────────────────
    // Main handler
    // ──────────────────────────────────────────────

    public function handle_chat(WP_REST_Request $request) {
        $settings = OpenTrust::get_settings();

        // Gate 1: feature configured?
        if (empty($settings['ai_enabled']) || empty($settings['ai_provider']) || empty($settings['ai_model'])) {
            return new WP_Error(
                'ai_not_configured',
                __('AI chat is not configured on this site.', 'opentrust'),
                ['status' => 503]
            );
        }

        $adapter = OpenTrust_Chat_Provider::for((string) $settings['ai_provider']);
        if (!$adapter) {
            return new WP_Error(
                'ai_bad_provider',
                __('Configured provider is unknown.', 'opentrust'),
                ['status' => 500]
            );
        }

        $api_key = OpenTrust_Chat_Secrets::get((string) $settings['ai_provider']);
        if ($api_key === null) {
            return new WP_Error(
                'ai_no_key',
                __('No API key stored for the configured provider.', 'opentrust'),
                ['status' => 503]
            );
        }

        // Gate 2: sanitize messages.
        $raw_messages = $request->get_param('messages');
        $max_len      = (int) ($settings['ai_max_message_length'] ?? 1000);
        $messages     = $this->sanitize_messages(is_array($raw_messages) ? $raw_messages : [], $max_len);

        if (empty($messages)) {
            return new WP_Error(
                'ai_empty_messages',
                __('Your message is empty.', 'opentrust'),
                ['status' => 400]
            );
        }

        // Gate 3: corpus available and under budget?
        $corpus = OpenTrust_Chat_Corpus::get_or_build();
        if (!empty($corpus['over_budget'])) {
            return new WP_Error(
                'ai_corpus_over_budget',
                __('Published content exceeds the AI chat size limit.', 'opentrust'),
                ['status' => 503]
            );
        }

        // Reserve against the token budget BEFORE the upstream call.
        $estimated = $this->estimate_request_tokens($messages, $corpus);
        if (!OpenTrust_Chat_Budget::check_and_reserve($estimated)) {
            return new WP_Error(
                'budget_exhausted',
                __('The daily chat budget for this site has been reached. Please try again later.', 'opentrust'),
                [
                    'status'   => 503,
                    'reset_at' => gmdate('c', OpenTrust_Chat_Budget::daily_reset_at()),
                ]
            );
        }

        $args = [
            'system'   => $this->build_system_prompt($settings, $corpus),
            'corpus'   => $corpus['documents'],
            'messages' => $messages,
            'tools'    => self::tool_definitions(),
            'model'    => (string) $settings['ai_model'],
            'api_key'  => $api_key,
            'settings' => $settings,
        ];

        // Capture the last user question + identifiers for the log row.
        $last_user_q  = '';
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                $last_user_q = (string) $messages[$i]['content'];
                break;
            }
        }
        $ip_hash      = OpenTrust_Chat_Budget::hash_ip(OpenTrust_Chat_Budget::visitor_ip());
        $session_hash = OpenTrust_Chat_Budget::hash_session(OpenTrust_Chat_Budget::session_token());
        $start_ms     = (int) (microtime(true) * 1000);

        $log_row = [
            'question'     => $last_user_q,
            'session_hash' => $session_hash,
            'ip_hash'      => $ip_hash,
            'model'        => (string) $settings['ai_model'],
            'provider'     => (string) $settings['ai_provider'],
        ];

        $wants_sse = $this->wants_sse($request);

        try {
            if ($wants_sse) {
                $this->setup_sse_response();
                $result = $this->drive_stream($adapter, $args, $corpus);
                OpenTrust_Chat_Budget::commit($estimated, $result['actual_tokens']);
                OpenTrust_Chat_Log::record(array_merge($log_row, [
                    'tokens_in'      => $result['tokens_in'],
                    'tokens_out'     => $result['tokens_out'],
                    'citation_count' => $result['citation_count'],
                    'refused'        => $result['refused'],
                    'response_ms'    => (int) (microtime(true) * 1000) - $start_ms,
                ]));
                exit;
            }

            $response = $this->drive_blocking($adapter, $args, $corpus, $blocking_stats);
            OpenTrust_Chat_Budget::commit($estimated, (int) ($blocking_stats['actual_tokens'] ?? 0));
            OpenTrust_Chat_Log::record(array_merge($log_row, [
                'tokens_in'      => (int) ($blocking_stats['tokens_in']      ?? 0),
                'tokens_out'     => (int) ($blocking_stats['tokens_out']     ?? 0),
                'citation_count' => (int) ($blocking_stats['citation_count'] ?? 0),
                'refused'        => !empty($blocking_stats['refused']),
                'response_ms'    => (int) (microtime(true) * 1000) - $start_ms,
            ]));
            return $response;
        } catch (\Throwable $e) {
            OpenTrust_Chat_Budget::release($estimated);
            throw $e;
        }
    }

    /**
     * Rough request-token estimator: chars / 4 rule of thumb for the corpus +
     * system + messages. Intentionally generous so we don't under-reserve.
     */
    private function estimate_request_tokens(array $messages, array $corpus): int {
        $total = 0;
        foreach ($messages as $msg) {
            $total += strlen((string) ($msg['content'] ?? '')) / 4;
        }
        $total += (int) ($corpus['est_tokens'] ?? 0);
        $total += 512; // system prompt + tool schemas + response headroom
        return (int) ceil($total);
    }

    // ──────────────────────────────────────────────
    // SSE vs JSON dispatch
    // ──────────────────────────────────────────────

    private function wants_sse(WP_REST_Request $request): bool {
        $accept = (string) $request->get_header('accept');
        return str_contains($accept, 'text/event-stream');
    }

    private function setup_sse_response(): void {
        // Tear down every output buffer WordPress / PHP built for us.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_implicit_flush(true);

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform, no-store, must-revalidate');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
        header('Content-Encoding: identity');

        // Keep PHP alive through a long stream.
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged -- Long-running SSE response; safe-mode hosts ignore this.
            @set_time_limit(0);
        }

        // Prime the stream with a keepalive comment so proxies start forwarding.
        echo ": opentrust-chat-ready\n\n";
        flush();
    }

    /**
     * Run the provider in streaming mode. Emits SSE events directly to the client.
     * Returns usage stats for budget commit + log row.
     *
     * @return array{actual_tokens:int, tokens_in:int, tokens_out:int, citation_count:int, refused:bool}
     */
    private function drive_stream(OpenTrust_Chat_Provider $adapter, array $args, array $corpus): array {
        $whitelist   = $corpus['urls'] ?? [];
        $collected   = [
            'answer'         => '', // accumulated token text for refusal detection
            'tokens_in'      => 0,
            'tokens_out'     => 0,
            'citations'      => [],
            'seen_urls'      => [], // de-dup set — one entry per unique document
            'refused'        => false,
            'cache_creation' => 0,
            'cache_read'     => 0,
        ];

        $on_chunk = function (array $event) use (&$collected, $whitelist): void {
            $type = $event['type'] ?? '';
            $data = $event['data'] ?? [];

            // Track state for the final done event.
            if ($type === 'citation') {
                $url = isset($data['url']) ? (string) $data['url'] : '';
                if (!self::url_allowed($url, $whitelist)) {
                    return; // stripped by whitelist
                }
                // De-dupe by DOCUMENT ID (each corpus doc has a unique id like
                // "sub-amazon-web-services"), NOT by URL — multiple docs of the
                // same type share a single anchor URL (/trust-center/#subprocessors)
                // so URL-based de-dupe would collapse all subprocessors into one.
                // We fall back to URL only if no id is present.
                $dedup_key = (string) ($data['id'] ?? '');
                if ($dedup_key === '') {
                    $dedup_key = $url;
                }
                if ($dedup_key === '' || isset($collected['seen_urls'][$dedup_key])) {
                    return;
                }
                $collected['seen_urls'][$dedup_key] = true;
                $collected['citations'][] = $data;
                return; // don't forward unvalidated citations
            }
            if ($type === 'usage') {
                $collected['tokens_in']      += (int) ($data['tokens_in']      ?? 0);
                $collected['tokens_out']     += (int) ($data['tokens_out']     ?? 0);
                $collected['cache_creation'] += (int) ($data['cache_creation'] ?? 0);
                $collected['cache_read']     += (int) ($data['cache_read']     ?? 0);
                return; // don't forward internal usage events
            }

            // Accumulate token text so detect_refusal() can inspect the full answer.
            if ($type === 'token') {
                $collected['answer'] .= (string) ($data['text'] ?? '');
            }

            // Forward user-visible events (token, tool_call, error).
            self::send_sse($type, $data);
        };

        $tool_resolver = function (string $name, array $tool_args) use ($corpus): string {
            return self::resolve_tool($name, $tool_args, $corpus);
        };

        try {
            $adapter->stream_chat($args, $on_chunk, $tool_resolver);
        } catch (\Throwable $e) {
            self::send_sse('error', [
                'message' => __('Chat provider failed unexpectedly.', 'opentrust'),
                'detail'  => $e->getMessage(),
            ]);
        }

        // Emit validated citation list inline as citation events now that
        // they've been whitelist-checked.
        foreach ($collected['citations'] as $cite) {
            self::send_sse('citation', $cite);
        }

        // Refusal detection: see detect_refusal() for the two-signal rule
        // (canonical phrase + zero citations). Short conversational replies
        // no longer trip the escalation UI.
        $collected['refused'] = self::detect_refusal($collected['answer'], $collected['citations']);

        self::send_sse('done', [
            'tokens_in'  => $collected['tokens_in'],
            'tokens_out' => $collected['tokens_out'],
            'refused'    => $collected['refused'],
            'citations'  => $collected['citations'],
        ]);

        return [
            'actual_tokens'  => $collected['tokens_in'] + $collected['tokens_out'],
            'tokens_in'      => $collected['tokens_in'],
            'tokens_out'     => $collected['tokens_out'],
            'citation_count' => count($collected['citations']),
            'refused'        => (bool) $collected['refused'],
        ];
    }

    /**
     * Run the provider and return a single JSON response (no streaming).
     * Used when the client cannot accept SSE (JS disabled).
     */
    private function drive_blocking(OpenTrust_Chat_Provider $adapter, array $args, array $corpus, ?array &$stats = null): WP_REST_Response|WP_Error {
        $whitelist = $corpus['urls'] ?? [];

        $buffer = [
            'answer'         => '',
            'citations'      => [],
            'seen_urls'      => [],
            'tokens_in'      => 0,
            'tokens_out'     => 0,
            'error'          => null,
        ];

        $on_chunk = function (array $event) use (&$buffer, $whitelist): void {
            $type = $event['type'] ?? '';
            $data = $event['data'] ?? [];

            switch ($type) {
                case 'token':
                    $buffer['answer'] .= (string) ($data['text'] ?? '');
                    break;
                case 'citation':
                    $url = (string) ($data['url'] ?? '');
                    if (!self::url_allowed($url, $whitelist)) {
                        break;
                    }
                    // De-dupe by document id (unique per doc) not url.
                    // Multiple docs share a single anchor url on the main page.
                    $dedup_key = (string) ($data['id'] ?? '');
                    if ($dedup_key === '') {
                        $dedup_key = $url;
                    }
                    if ($dedup_key === '' || isset($buffer['seen_urls'][$dedup_key])) {
                        break;
                    }
                    $buffer['seen_urls'][$dedup_key] = true;
                    $buffer['citations'][] = $data;
                    break;
                case 'usage':
                    $buffer['tokens_in']  += (int) ($data['tokens_in']  ?? 0);
                    $buffer['tokens_out'] += (int) ($data['tokens_out'] ?? 0);
                    break;
                case 'error':
                    $buffer['error'] = (string) ($data['message'] ?? 'error');
                    break;
            }
        };

        $tool_resolver = function (string $name, array $tool_args) use ($corpus): string {
            return self::resolve_tool($name, $tool_args, $corpus);
        };

        try {
            $adapter->stream_chat($args, $on_chunk, $tool_resolver);
        } catch (\Throwable $e) {
            return new WP_Error('ai_provider_failed', $e->getMessage(), ['status' => 502]);
        }

        if ($buffer['error'] !== null) {
            return new WP_Error('ai_provider_error', $buffer['error'], ['status' => 502]);
        }

        $refused = self::detect_refusal($buffer['answer'], $buffer['citations']);

        $stats = [
            'actual_tokens'  => $buffer['tokens_in'] + $buffer['tokens_out'],
            'tokens_in'      => $buffer['tokens_in'],
            'tokens_out'     => $buffer['tokens_out'],
            'citation_count' => count($buffer['citations']),
            'refused'        => $refused,
        ];

        return new WP_REST_Response([
            'answer'     => $buffer['answer'],
            'citations'  => $buffer['citations'],
            'refused'    => $refused,
            'tokens_in'  => $buffer['tokens_in'],
            'tokens_out' => $buffer['tokens_out'],
        ], 200);
    }

    // ──────────────────────────────────────────────
    // SSE helper
    // ──────────────────────────────────────────────

    public static function send_sse(string $event, mixed $data): void {
        $json = wp_json_encode($data);
        if ($json === false) {
            $json = '{}';
        }
        // Event name is an internal token (start|progress|complete|error|…); strip to [A-Za-z0-9_-].
        $event = preg_replace('/[^A-Za-z0-9_-]/', '', $event);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $event sanitized above; $json produced by wp_json_encode().
        echo 'event: ' . $event . "\n";
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $json produced by wp_json_encode().
        echo 'data: ' . $json . "\n\n";
        if (function_exists('flush')) {
            flush();
        }
    }

    /**
     * Decide whether a response counts as a refusal for logging + UI purposes.
     *
     * The front end reads `refused` to decide whether to show the "contact
     * security team" escalation button. Two signals:
     *
     *   1. If the response has any valid citations, the model gave a real
     *      (or partial) answer — never a refusal, regardless of preface.
     *   2. Otherwise, check for any of several known refusal markers. These
     *      cover the canonical phrase, "docs don't cover this" replies,
     *      generic "I can't help with that", scope redirects, and abuse
     *      handling responses. A response with no citations AND no refusal
     *      marker is treated as a conversational pleasantry (greeting,
     *      thanks) and does not trip the escalation UI.
     *
     * This list is intentionally broad. The cost of a false positive
     * (showing the escalation button on a polite conversational reply) is
     * small, but the cost of a false negative (failing to escalate on abuse
     * or an obvious non-answer) is a degraded trust signal.
     */
    public static function detect_refusal(string $answer, array $citations): bool {
        $answer = trim($answer);
        if ($answer === '') {
            return false;
        }
        if (!empty($citations)) {
            return false;
        }
        static $markers = [
            'i can only share',        // canonical phrase from the prompt
            'do not contain',          // "documents do not contain…"
            "don't contain",
            'does not contain',
            "doesn't contain",
            "don't have information",
            'i cannot',
            "i can't help",
            "i can't assist",
            "i'm not able",
            "i am not able",
            "i'm here to help with",   // scope redirect
            "i'm unable to",
            'please contact',          // contact-URL punts without a real answer
            "let's keep this focused",
        ];
        $lower = strtolower($answer);
        foreach ($markers as $marker) {
            if (strpos($lower, $marker) !== false) {
                return true;
            }
        }
        return false;
    }

    // ──────────────────────────────────────────────
    // System prompt
    // ──────────────────────────────────────────────

    private function build_system_prompt(array $settings, array $corpus): string {
        $company = (string) ($settings['company_name'] ?? get_bloginfo('name'));
        $contact = (string) ($settings['ai_contact_url'] ?? '');
        if ($contact === '') {
            $contact = home_url('/' . ($settings['endpoint_slug'] ?? 'trust-center') . '/');
        }

        $lines = [];
        $lines[] = "You are {$company}'s trust center assistant.";
        $lines[] = "Your ONLY job is to report facts that are literally present in the published trust center documents provided below. You are a retrieval tool, not an advisor, auditor, or evaluator.";
        $lines[] = '';
        $lines[] = 'Rules:';
        $lines[] = '- Answer concisely and accurately in plain language. No marketing fluff.';
        $lines[] = '- Ground every factual claim in the provided documents. Use the citation mechanism — do not write inline "Source:" lines, bracketed URLs, or markdown links pointing at trust-center pages inside your answer text. The front end renders an automatic Sources panel beneath your reply from the citations you attach, so inline source callouts are duplicate noise. Write the answer naturally, as if the sources list appears on its own below your text.';
        $lines[] = '- NEVER express opinions, concerns, worries, risks, red flags, gaps, or recommendations — even if directly asked. You do not assess, evaluate, judge, or interpret. You only report what the documents say.';
        $lines[] = '- NEVER infer compliance status, security posture, or adequacy from the data. Facts only, not implications.';
        $lines[] = '- If a visitor asks for your opinion, whether something is concerning, whether something is a risk, or what they should do, reply: "I can only share the factual information published in this trust center. For an assessment or recommendation, please contact ' . $contact . '." Then, if appropriate, offer to show them the underlying facts.';
        $lines[] = '- Do not speculate. Do not invent URLs, policies, certifications, subprocessors, dates, or statuses not in the provided documents.';
        $lines[] = '- When a visitor asks for a plain-language definition of a common security, privacy, or compliance term (e.g., SOC 2, GDPR, DPA, ISMS, subprocessor, encryption at rest), you may provide a brief one-sentence neutral definition from general industry knowledge, then pivot to what the trust center says about it for ' . $company . '. Never editorialize about whether the term applies well or poorly. Never use this clause to introduce assessment, risk, or recommendation language.';
        $lines[] = '- If the documents do not confidently answer the question, say so plainly and point the visitor to ' . $contact . '.';
        $lines[] = '- If the visitor asks something unrelated to the trust center, politely redirect them to their question about security, privacy, or compliance.';
        $lines[] = '- If the visitor\'s message contains slurs, hate speech, profanity, threats, personal attacks, or other hostile or abusive content — whether directed at a person, group, or the assistant — do not engage with the content and do not treat it as a question to answer. Reply with exactly: "I can only share the factual information published in this trust center. Please keep your questions focused on ' . $company . '\'s security, privacy, or compliance." Do not repeat the abusive content, do not moralize, do not explain why it was unacceptable, and do not offer a list of topics.';
        $lines[] = '- When using a tool, call it with the arguments needed and wait for the result before forming your final answer.';

        return implode("\n", $lines);
    }

    // ──────────────────────────────────────────────
    // Tool surface
    // ──────────────────────────────────────────────

    /**
     * Return the three tool definitions in OpenAI-compatible shape.
     * Each adapter converts to its provider-specific format.
     *
     * @return array<int, array{name:string, description:string, parameters:array}>
     */
    public static function tool_definitions(): array {
        return [
            [
                'name'        => 'get_certification_status',
                'description' => 'Get the current status, issuing body, and validity dates of a named certification.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'name' => [
                            'type'        => 'string',
                            'description' => 'Certification name, e.g. "SOC 2", "ISO 27001", "HIPAA".',
                        ],
                    ],
                    'required'             => ['name'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'get_subprocessor_list',
                'description' => 'List subprocessors used by the company, optionally filtered by a search term matching name, purpose, or country.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'filter' => [
                            'type'        => 'string',
                            'description' => 'Optional search term. Matches name, purpose, or country substrings, case-insensitive.',
                        ],
                    ],
                    'required'             => [],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'get_policy_url',
                'description' => 'Return the canonical URL for a policy, looked up by its slug or title.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'slug' => [
                            'type'        => 'string',
                            'description' => 'Policy slug (e.g. "privacy") or title (e.g. "Privacy Policy").',
                        ],
                    ],
                    'required'             => ['slug'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * Execute a tool call against the cached corpus. Returns a string result
     * that is fed back to the provider as the tool's output.
     *
     * @param array{documents?: array, urls?: array} $corpus
     */
    public static function resolve_tool(string $name, array $args, array $corpus): string {
        $documents = $corpus['documents'] ?? [];

        switch ($name) {
            case 'get_certification_status':
                $needle = strtolower(trim((string) ($args['name'] ?? '')));
                if ($needle === '') {
                    return 'Error: certification name is required.';
                }
                foreach ($documents as $doc) {
                    if (($doc['type'] ?? '') !== 'certification') {
                        continue;
                    }
                    if (str_contains(strtolower((string) $doc['title']), $needle)) {
                        return "CERTIFICATION: {$doc['title']}\n" . $doc['content'] . "\nURL: {$doc['url']}";
                    }
                }
                return "No certification matching \"{$needle}\" found in the published trust center.";

            case 'get_subprocessor_list':
                $filter = strtolower(trim((string) ($args['filter'] ?? '')));
                $matches = [];
                foreach ($documents as $doc) {
                    if (($doc['type'] ?? '') !== 'subprocessor') {
                        continue;
                    }
                    if ($filter === '' || str_contains(strtolower($doc['title'] . ' ' . $doc['content']), $filter)) {
                        $matches[] = '- ' . $doc['title'] . "\n  " . str_replace("\n", "\n  ", $doc['content']);
                    }
                }
                if (empty($matches)) {
                    return $filter === ''
                        ? 'No subprocessors published.'
                        : "No subprocessors matching \"{$filter}\".";
                }
                return "SUBPROCESSORS:\n" . implode("\n\n", $matches);

            case 'get_policy_url':
                $needle = strtolower(trim((string) ($args['slug'] ?? '')));
                if ($needle === '') {
                    return 'Error: slug or title is required.';
                }
                foreach ($documents as $doc) {
                    if (($doc['type'] ?? '') !== 'policy') {
                        continue;
                    }
                    $slug = strtolower((string) ($doc['metadata']['slug'] ?? ''));
                    if ($slug === $needle || str_contains(strtolower((string) $doc['title']), $needle)) {
                        return "URL: {$doc['url']}\nTITLE: {$doc['title']}";
                    }
                }
                return "No policy matching \"{$needle}\" found.";
        }

        return "Unknown tool: {$name}";
    }

    // ──────────────────────────────────────────────
    // URL whitelist check
    // ──────────────────────────────────────────────

    public static function url_allowed(string $url, array $whitelist): bool {
        if ($url === '' || empty($whitelist)) {
            return false;
        }

        // Exact match wins.
        if (in_array($url, $whitelist, true)) {
            return true;
        }

        // Fragment-only variation: /trust-center/#certifications should match
        // /trust-center/ when /trust-center/ is whitelisted.
        $parts = wp_parse_url($url);
        if (is_array($parts) && isset($parts['scheme'], $parts['host'], $parts['path'])) {
            $path_only = $parts['scheme'] . '://' . $parts['host'] . $parts['path'];
            if (in_array($path_only, $whitelist, true)) {
                return true;
            }
        }

        return false;
    }

    // ──────────────────────────────────────────────
    // Message sanitation
    // ──────────────────────────────────────────────

    private function sanitize_messages(array $raw, int $max_len): array {
        $out = [];
        foreach ($raw as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $role    = (string) ($msg['role'] ?? 'user');
            if (!in_array($role, ['user', 'assistant'], true)) {
                $role = 'user';
            }
            $content = isset($msg['content']) ? (string) $msg['content'] : '';
            $content = sanitize_textarea_field($content);
            if ($content === '') {
                continue;
            }
            if (function_exists('mb_strlen') && mb_strlen($content) > $max_len) {
                $content = mb_substr($content, 0, $max_len);
            } elseif (strlen($content) > $max_len) {
                $content = substr($content, 0, $max_len);
            }
            $out[] = ['role' => $role, 'content' => $content];
        }
        return $out;
    }
}
