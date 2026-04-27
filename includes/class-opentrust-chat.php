<?php
/**
 * Chat orchestration singleton.
 *
 * Registers the POST /wp-json/opentrust/v1/chat REST route, wires corpus
 * invalidation to CPT save events, provides the tool surface (static method),
 * and implements the handler that dispatches to the configured provider
 * adapter via either a streaming (SSE) or a blocking (JSON fallback) transport.
 *
 * The permission_callback runs four gates in order: REST nonce → Turnstile
 * verification (when enabled) → per-IP sliding-window rate limit → per-session
 * sliding-window rate limit. Token-budget reservation happens inside
 * handle_chat() so failed pre-flight checks never consume the operator's quota.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OpenTrust_Chat {

    public const REST_NAMESPACE = 'opentrust/v1';
    public const REST_ROUTE     = '/chat';
    /**
     * Cap on how many round-trips the model can make in one chat request.
     * 8 covers: search → refine → fetch A → fetch B → answer, with two
     * rounds of recovery from a missed search or 404'd id. Loop detection
     * inside resolve_tool() prevents the same call recurring within a
     * request, so the cap is rarely the actual limiter.
     */
    public const MAX_TOOL_TURNS = 8;

    /**
     * Default character cap on a single visitor message. Operators can override
     * via the ai_max_message_length setting (clamped to 100..4000 in
     * OpenTrust_Admin::sanitize_settings).
     */
    public const DEFAULT_MAX_MESSAGE_LENGTH = 1000;

    /**
     * Canonical refusal opening sentence. The system prompt instructs the model
     * to use this exact phrase when it cannot answer (assessment requests,
     * abusive input, off-topic). REFUSAL_MARKER below is the lowercased prefix
     * detect_refusal() searches for — keep the two in sync. If you reword
     * REFUSAL_PHRASE, verify REFUSAL_MARKER still appears at the start of the
     * new wording, or the contact-CTA escalation will silently stop firing.
     */
    public const REFUSAL_PHRASE = 'I can only share the factual information published in this trust center';
    public const REFUSAL_MARKER = 'i can only share';

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);

        // Corpus invalidation. Reuse the centralized CPT-event registrar so
        // saves, deletes, trash/untrash, and publish transitions all flush
        // through a single canonical CPT list (CORPUS = the four indexed CPTs;
        // FAQs are deliberately not in the chat corpus).
        OpenTrust_CPT::register_invalidator(
            OpenTrust_CPT::CORPUS,
            [OpenTrust_Chat_Corpus::class, 'invalidate']
        );
        add_action('update_option_opentrust_settings', [OpenTrust_Chat_Corpus::class, 'invalidate']);

        // Auto-summarize hooks. Independent of corpus invalidation — the
        // summarizer schedules a debounced cron call rather than running
        // inline on save_post, so it doesn't block the editor.
        if (class_exists('OpenTrust_Chat_Summarizer')) {
            OpenTrust_Chat_Summarizer::bootstrap();
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
        $max_len      = (int) ($settings['ai_max_message_length'] ?? self::DEFAULT_MAX_MESSAGE_LENGTH);
        $messages     = $this->sanitize_messages(is_array($raw_messages) ? $raw_messages : [], $max_len);

        if (empty($messages)) {
            return new WP_Error(
                'ai_empty_messages',
                __('Your message is empty.', 'opentrust'),
                ['status' => 400]
            );
        }

        // Gate 3: build (or fetch) the corpus for the visitor's locale. With
        // agentic retrieval the corpus has no top-level size cap — the model
        // never sees the full content, only the slim index in the system
        // prompt and whatever it pulls in via tool calls. Per-document
        // truncation lives inside the corpus formatter.
        $locale = (string) determine_locale();
        $corpus = OpenTrust_Chat_Corpus::get_or_build($locale);

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
            'system'   => self::build_system_prompt($settings, $corpus),
            // Pass the full corpus struct (documents + url_to_id + bm25 + …).
            // Providers pluck what they need: Anthropic uses url_to_id for
            // citation reverse-lookup; OpenAI consumes documents for
            // [[cite:id]] resolution. The shape was previously the documents
            // array directly; the agentic engine needs richer context.
            'corpus'   => $corpus,
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
                    'tool_turns'     => (int)    ($result['tool_turns'] ?? 0),
                    'tool_names'     => (string) ($result['tool_names'] ?? ''),
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
                'tool_turns'     => (int)    ($blocking_stats['tool_turns'] ?? 0),
                'tool_names'     => (string) ($blocking_stats['tool_names'] ?? ''),
            ]));
            return $response;
        } catch (\Throwable $e) {
            OpenTrust_Chat_Budget::release($estimated);
            throw $e;
        }
    }

    /**
     * Reserve token budget for one chat request. Post-migration the request
     * footprint scales with: conversation history, the (cached) index in the
     * system prompt, and an upper bound for tool round-trips and the answer.
     * Intentionally generous — over-reservation is reclaimed on commit; an
     * under-reservation lets concurrent requests collectively exceed the cap.
     */
    private function estimate_request_tokens(array $messages, array $corpus): int {
        $history_chars = 0;
        foreach ($messages as $msg) {
            $history_chars += strlen((string) ($msg['content'] ?? ''));
        }
        $history_tokens = (int) ceil($history_chars / 4);
        $index_tokens   = (int) ($corpus['index_tokens'] ?? 0);
        // ~3K headroom per tool turn covers a search_result block plus the
        // model's intermediate thinking. The cap rarely binds in practice.
        $tool_headroom  = self::MAX_TOOL_TURNS * 3000;
        $answer_room    = 2000;
        $overhead       = 512; // tool schemas + framing
        return $history_tokens + $index_tokens + $tool_headroom + $answer_room + $overhead;
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
     * Run the provider in streaming mode. Emits SSE events directly to the
     * client. Returns usage stats for budget commit + log row. The collector
     * owns the citation allowlist, doc-id de-dup, usage accounting, refusal
     * detection, and tool-name capture — see OpenTrust_Chat_Stream_Collector.
     *
     * @return array{
     *     actual_tokens:int, tokens_in:int, tokens_out:int,
     *     cache_creation:int, cache_read:int, citation_count:int,
     *     refused:bool, tool_turns:int, tool_names:string
     * }
     */
    private function drive_stream(OpenTrust_Chat_Provider $adapter, array $args, array $corpus): array {
        $collector = new OpenTrust_Chat_Stream_Collector($corpus['urls'] ?? []);

        $on_chunk = static function (array $event) use ($collector): void {
            // ingest() returns true for events the client should also see live
            // (token, tool_call, error) — citations and usage are kept inside
            // the collector and emitted after the stream settles.
            if ($collector->ingest($event)) {
                self::send_sse((string) ($event['type'] ?? ''), $event['data'] ?? []);
            }
        };

        // Per-request loop detection: same name + canonical args → guidance
        // result instead of running the same query a second time. Ref-passed
        // into the resolver so state spans every turn of this request.
        $seen_calls = [];

        $tool_resolver = function (string $name, array $tool_args) use ($corpus, &$seen_calls): array {
            return self::resolve_tool($name, $tool_args, $corpus, $seen_calls);
        };

        try {
            $adapter->stream_chat($args, $on_chunk, $tool_resolver);
        } catch (\Throwable $e) {
            self::send_sse('error', [
                'message' => __('Chat provider failed unexpectedly.', 'opentrust'),
                'detail'  => $e->getMessage(),
            ]);
        }

        // Emit validated citation list inline now that they've been
        // whitelist-checked. (The collector held them through the stream
        // because each citation needed allowlist + de-dup before it could
        // be trusted as a user-visible event.)
        foreach ($collector->citations as $cite) {
            self::send_sse('citation', $cite);
        }

        $stats = $collector->stats();

        self::send_sse('done', [
            'tokens_in'  => $collector->tokens_in,
            'tokens_out' => $collector->tokens_out,
            'refused'    => $stats['refused'],
            'citations'  => $collector->citations,
        ]);

        return $stats;
    }

    /**
     * Run the provider and return a single JSON response (no streaming).
     * Used when the client cannot accept SSE (JS disabled). Same collector
     * as drive_stream — only the post-loop wrap-up differs.
     */
    private function drive_blocking(OpenTrust_Chat_Provider $adapter, array $args, array $corpus, ?array &$stats = null): WP_REST_Response|WP_Error {
        $collector = new OpenTrust_Chat_Stream_Collector($corpus['urls'] ?? []);

        $on_chunk = static function (array $event) use ($collector): void {
            $collector->ingest($event); // blocking path doesn't forward live
        };

        $seen_calls = [];

        $tool_resolver = function (string $name, array $tool_args) use ($corpus, &$seen_calls): array {
            return self::resolve_tool($name, $tool_args, $corpus, $seen_calls);
        };

        try {
            $adapter->stream_chat($args, $on_chunk, $tool_resolver);
        } catch (\Throwable $e) {
            return new WP_Error('ai_provider_failed', $e->getMessage(), ['status' => 502]);
        }

        if ($collector->error !== null) {
            return new WP_Error('ai_provider_error', $collector->error, ['status' => 502]);
        }

        $stats = $collector->stats();

        return new WP_REST_Response([
            'answer'     => $collector->answer,
            'citations'  => $collector->citations,
            'refused'    => $stats['refused'],
            'tokens_in'  => $collector->tokens_in,
            'tokens_out' => $collector->tokens_out,
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
            self::REFUSAL_MARKER,      // canonical phrase from REFUSAL_PHRASE
            'do not contain',          // "documents do not contain…"
            "don't contain",
            'does not contain',
            "doesn't contain",
            "don't have information",
            "i didn't find",           // post-tool-call: "I didn't find any reference to …"
            "i couldn't find",         // also: cap-hit soft refusal we synthesize ourselves
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

    /**
     * Build the system prompt sent on every chat request. Includes the
     * baseline role + behavior rules, the corpus index (table of contents),
     * and the retrieval-grounding rules specific to the agentic engine.
     *
     * Public + static so the noscript chat path in OpenTrust_Render can use
     * the same prompt as the streaming path — they're functionally identical.
     */
    public static function build_system_prompt(array $settings, array $corpus): string {
        $company = (string) ($settings['company_name'] ?? get_bloginfo('name'));
        $contact = (string) ($settings['ai_contact_url'] ?? '');
        if ($contact === '') {
            $contact = home_url('/' . ($settings['endpoint_slug'] ?? OpenTrust::DEFAULT_ENDPOINT_SLUG) . '/');
        }

        $lines = [];
        $lines[] = "You are {$company}'s trust center assistant.";
        $lines[] = "Your ONLY job is to report facts that are literally present in the published trust center documents you can retrieve via the tools below. You are a retrieval tool, not an advisor, auditor, or evaluator.";
        $lines[] = '';
        $lines[] = 'Rules:';
        $lines[] = '- Answer concisely and accurately in plain language. No marketing fluff.';
        $lines[] = '- Ground every factual claim in the documents returned by your tool calls. Use the citation mechanism — do not write inline "Source:" lines, bracketed URLs, or markdown links pointing at trust-center pages inside your answer text. The front end renders an automatic Sources panel beneath your reply from the citations you attach, so inline source callouts are duplicate noise. Write the answer naturally, as if the sources list appears on its own below your text.';
        $lines[] = '- NEVER express opinions, concerns, worries, risks, red flags, gaps, or recommendations — even if directly asked. You do not assess, evaluate, judge, or interpret. You only report what the documents say.';
        $lines[] = '- NEVER infer compliance status, security posture, or adequacy from the data. Facts only, not implications.';
        $lines[] = '- If a visitor asks for your opinion, whether something is concerning, whether something is a risk, or what they should do, reply: "' . self::REFUSAL_PHRASE . '. For an assessment or recommendation, please contact ' . $contact . '." Then, if appropriate, offer to show them the underlying facts.';
        $lines[] = '- Do not speculate. Do not invent URLs, policies, certifications, subprocessors, dates, or statuses not in the documents your tools have returned.';
        $lines[] = '- When a visitor asks for a plain-language definition of a common security, privacy, or compliance term (e.g., SOC 2, GDPR, DPA, ISMS, subprocessor, encryption at rest), you may provide a brief one-sentence neutral definition from general industry knowledge, then pivot to what the trust center says about it for ' . $company . '. Never editorialize about whether the term applies well or poorly. Never use this clause to introduce assessment, risk, or recommendation language.';
        $lines[] = '- If retrieval does not confidently answer the question, say so plainly and point the visitor to ' . $contact . '.';
        $lines[] = '- If the visitor asks something unrelated to the trust center, politely redirect them to their question about security, privacy, or compliance.';
        $lines[] = '- If the visitor\'s message contains slurs, hate speech, profanity, threats, personal attacks, or other hostile or abusive content — whether directed at a person, group, or the assistant — do not engage with the content and do not treat it as a question to answer. Reply with exactly: "' . self::REFUSAL_PHRASE . '. Please keep your questions focused on ' . $company . '\'s security, privacy, or compliance." Do not repeat the abusive content, do not moralize, do not explain why it was unacceptable, and do not offer a list of topics.';
        $lines[] = '';
        $lines[] = 'Retrieval rules:';
        $lines[] = '- ALWAYS call get_document or search_documents BEFORE answering any question about this company. Never answer from prior knowledge or training data.';
        $lines[] = '- After every tool call, base your answer ONLY on the search_result content that came back. Do not extrapolate beyond it.';
        $lines[] = '- If your first search returns nothing relevant, retry once with broader keywords or alternative phrasings before giving up.';
        $lines[] = '- Do not invent document ids. The only valid ids are the ones listed in the index below.';
        $lines[] = '- Before each tool call you may write ONE short, natural sentence stating what you\'re about to look up — for example, "Let me check our subprocessors list." or "Looking at the data retention policy." Keep it under 12 words, skip it when the visitor\'s question makes your intent obvious, and never write more than one such sentence per tool call. Do not say "I\'ll do X then Y" — just do X first. After the tool result returns, continue with the answer.';

        // Append the corpus index (table of contents). The model uses this to
        // decide which document to fetch.
        $index = is_array($corpus['index'] ?? null) ? $corpus['index'] : [];
        if (!empty($index)) {
            $lines[] = '';
            $lines[] = OpenTrust_Chat_Corpus::format_index_for_prompt($index, $company);
        }

        return implode("\n", $lines);
    }

    /**
     * Format the per-request list of tool names into the column shape the
     * chat log uses: comma-separated, capped at MAX_TOOL_TURNS entries to
     * stay under the VARCHAR(255) column.
     *
     * @param array<int, string> $names
     */
    public static function format_tool_names_for_log(array $names): string {
        if (empty($names)) {
            return '';
        }
        return implode(',', array_slice($names, 0, self::MAX_TOOL_TURNS));
    }

    // ──────────────────────────────────────────────
    // Tool surface
    // ──────────────────────────────────────────────

    /**
     * Two-tool surface for the agentic chat engine. Returned in
     * OpenAI-compatible shape; each provider adapter renames `parameters`
     * (Anthropic uses `input_schema`) and wraps as needed.
     *
     * Descriptions are deliberately specific — the model uses them as the
     * primary signal for which tool to pick. Vague descriptions cause it to
     * default to search when get_document would have been one round-trip
     * shorter (or vice versa).
     *
     * @return array<int, array{name:string, description:string, parameters:array}>
     */
    public static function tool_definitions(): array {
        return [
            [
                'name'        => 'get_document',
                'description' => 'Fetch the full text of one specific trust center document by its id. Use this when the corpus index lists a document whose title obviously matches the question — e.g. for "Are you SOC 2 certified?" call get_document with the id of the SOC 2 certification entry. Always prefer this over search_documents when an id is clearly identifiable from the index.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'id' => [
                            'type'        => 'string',
                            'description' => 'Document id from the corpus index (e.g. "policy-privacy", "sub-amazon-web-services", "cert-iso-27001"). Must match exactly. Do not invent ids that are not in the index.',
                        ],
                    ],
                    'required'             => ['id'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'search_documents',
                'description' => 'Keyword-search across every trust center document. Use this when no document id in the index obviously matches the question, when the question uses a synonym (e.g. "data deletion" might map to a "Data Retention Policy"), or when the answer might span several documents. Returns up to `limit` matching documents ranked by relevance. Always check search_documents results before declaring that information is not available — if the first query returns nothing, try a broader query or alternative phrasings.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'query' => [
                            'type'        => 'string',
                            'description' => 'Two to five keywords describing what to find. Examples: "data retention deletion", "encryption at rest", "incident response notification".',
                        ],
                        'limit' => [
                            'type'        => 'integer',
                            'description' => 'Maximum number of documents to return (1–10, default 5). Use a smaller limit for narrow questions.',
                        ],
                    ],
                    'required'             => ['query'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * Execute a tool call against the cached corpus. Returns an array of
     * `search_result` content blocks that providers feed back to the model.
     *
     * Anthropic consumes the array verbatim as the `tool_result.content`
     * field. OpenAI/OpenRouter serialize it to an XML-tagged string before
     * stuffing into the OpenAI `tool` role message.
     *
     * Loop detection: the same name + canonical-arg-json combination cannot
     * fire twice in one request. The second hit returns a guidance result
     * pointing the model at alternative tactics. This makes MAX_TOOL_TURNS
     * a soft ceiling rather than a hard limit on legitimate work.
     *
     * @param array<int, mixed>     $corpus      Full corpus struct (documents + bm25 + …).
     * @param array<string, bool>   $seen_calls  Per-request seen-calls map (passed by reference).
     * @return array<int, array{type:string,source:string,title:string,content:array,citations:array}>
     */
    public static function resolve_tool(string $name, array $args, array $corpus, array &$seen_calls = []): array {
        $documents = is_array($corpus['documents'] ?? null) ? $corpus['documents'] : [];
        $bm25      = is_array($corpus['bm25']      ?? null) ? $corpus['bm25']      : null;

        // Canonicalize the args for the seen-calls signature so trivial key-
        // order differences don't fool the loop detector.
        ksort($args);
        $signature = $name . '|' . (string) wp_json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (isset($seen_calls[$signature])) {
            return [self::error_search_result(sprintf(
                /* translators: %s is the tool name (get_document or search_documents). */
                __('You already called %s with the same arguments earlier in this conversation. Pick a different document id from the index, or rephrase the search query with different keywords.', 'opentrust'),
                $name
            ))];
        }
        $seen_calls[$signature] = true;

        switch ($name) {
            case 'get_document':
                $id = trim((string) ($args['id'] ?? ''));
                if ($id === '') {
                    return [self::error_search_result(__('Document id is required.', 'opentrust'))];
                }
                foreach ($documents as $doc) {
                    if ((string) ($doc['id'] ?? '') === $id) {
                        return [self::doc_to_search_result($doc)];
                    }
                }
                return [self::error_search_result(sprintf(
                    /* translators: %s is the requested document id. */
                    __('No document with id "%s". Pick one of the ids listed in the corpus index above.', 'opentrust'),
                    $id
                ))];

            case 'search_documents':
                $query = trim((string) ($args['query'] ?? ''));
                $limit = max(1, min(10, (int) ($args['limit'] ?? 5)));
                if ($query === '') {
                    return [self::error_search_result(__('Search query is empty.', 'opentrust'))];
                }
                if ($bm25 === null) {
                    return [self::error_search_result(__('Search index unavailable.', 'opentrust'))];
                }
                $hits = OpenTrust_Chat_Search::search($bm25, $query, $limit);
                if (empty($hits)) {
                    return [self::error_search_result(sprintf(
                        /* translators: %s is the search query. */
                        __('No documents matched "%s". Try broader keywords or pick a document id from the corpus index above.', 'opentrust'),
                        $query
                    ))];
                }
                $results = [];
                foreach ($hits as $idx) {
                    if (isset($documents[$idx])) {
                        $results[] = self::doc_to_search_result($documents[$idx]);
                    }
                }
                return $results !== []
                    ? $results
                    : [self::error_search_result(__('Search ranking returned no usable results.', 'opentrust'))];
        }

        return [self::error_search_result(sprintf(
            /* translators: %s is the unknown tool name. */
            __('Unknown tool: %s', 'opentrust'),
            $name
        ))];
    }

    /**
     * Convert a corpus document into an Anthropic-compatible search_result
     * content block. The OpenAI provider further serializes this to an XML-
     * tagged string before passing it to the model.
     *
     * @param array{id?:string,url?:string,title?:string,content?:string} $doc
     */
    private static function doc_to_search_result(array $doc): array {
        $content = (string) ($doc['content'] ?? '');
        if ($content === '') {
            // Anthropic rejects empty `text` inside search_result.content[].
            // Fall back to the title or a one-word stub so the request is valid.
            $content = (string) ($doc['title'] ?? '(empty document)');
        }
        return [
            'type'      => 'search_result',
            'source'    => (string) ($doc['url']   ?? ''),
            'title'     => (string) ($doc['title'] ?? ''),
            'content'   => [
                ['type' => 'text', 'text' => $content],
            ],
            'citations' => ['enabled' => true],
        ];
    }

    /**
     * Build a "no result" search_result block. The `about:none` source is
     * structurally invalid for citation (url_allowed() rejects it), so the
     * model sees the explanation but cannot accidentally cite the error.
     *
     * Anthropic enforces an all-or-nothing rule on citations.enabled across
     * a single request, so error blocks set the same `enabled: true` value
     * as real result blocks. The URL whitelist is what keeps them inert.
     */
    private static function error_search_result(string $msg): array {
        return [
            'type'      => 'search_result',
            'source'    => 'about:none',
            'title'     => 'Retrieval error',
            'content'   => [
                ['type' => 'text', 'text' => $msg],
            ],
            'citations' => ['enabled' => true],
        ];
    }

    // ──────────────────────────────────────────────
    // URL whitelist check
    // ──────────────────────────────────────────────

    public static function url_allowed(string $url, array $whitelist): bool {
        // about:none is the inert source set on retrieval-error blocks. Reject
        // explicitly so a future refactor can't accidentally let one through.
        if ($url === '' || $url === 'about:none' || empty($whitelist)) {
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
