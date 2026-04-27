<?php
/**
 * Anthropic provider adapter.
 *
 * API reference: https://docs.anthropic.com/en/api/models-list
 *                https://docs.anthropic.com/en/api/messages
 *                https://docs.anthropic.com/en/docs/build-with-claude/citations
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OpenTrust_Chat_Provider_Anthropic extends OpenTrust_Chat_Provider {

    private const API_BASE        = 'https://api.anthropic.com';
    private const MODELS_ENDPOINT = 'https://api.anthropic.com/v1/models';
    private const MESSAGES_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION     = '2023-06-01';

    /**
     * Model IDs (or ID prefixes) we recommend as defaults.
     * Sonnet 4.5 is the top recommendation — best Citations API + tool calling.
     */
    private const RECOMMENDED_IDS = [
        'claude-sonnet-4-5',
        'claude-opus-4-6',
    ];

    public function slug(): string {
        return 'anthropic';
    }

    public function label(): string {
        return __('Anthropic', 'opentrust');
    }

    public function allowed_hosts(): array {
        return ['api.anthropic.com'];
    }

    protected function models_endpoint(): string {
        return self::MODELS_ENDPOINT;
    }

    protected function auth_headers(string $key): array {
        return [
            'x-api-key'         => $key,
            'anthropic-version' => self::API_VERSION,
        ];
    }

    protected function no_models_message(): string {
        return __('No models available — your account may not be authorized.', 'opentrust');
    }

    public function curate_models(mixed $raw): array {
        if (!is_array($raw) || empty($raw['data']) || !is_array($raw['data'])) {
            return [];
        }

        $models = [];
        foreach ($raw['data'] as $row) {
            if (!is_array($row) || empty($row['id'])) {
                continue;
            }
            $id = (string) $row['id'];

            // Skip deprecated / non-chat models.
            if (str_starts_with($id, 'claude-instant') || str_contains($id, 'embed')) {
                continue;
            }

            $display = isset($row['display_name']) && is_string($row['display_name'])
                ? $row['display_name']
                : $id;

            $models[] = [
                'id'           => $id,
                'display_name' => $display,
                'recommended'  => $this->is_recommended($id),
            ];
        }

        // Sort: recommended first, then by ID descending (newest first).
        usort($models, function ($a, $b) {
            if ($a['recommended'] !== $b['recommended']) {
                return $a['recommended'] ? -1 : 1;
            }
            return strcmp($b['id'], $a['id']);
        });

        return $models;
    }

    private function is_recommended(string $id): bool {
        foreach (self::RECOMMENDED_IDS as $needle) {
            if (str_starts_with($id, $needle)) {
                return true;
            }
        }
        return false;
    }

    protected function initialize_turn_loop(array $args, callable $on_chunk): ?array {
        $api_key   = (string) ($args['api_key']  ?? '');
        $model     = (string) ($args['model']    ?? '');
        $system    = (string) ($args['system']   ?? '');
        // The corpus is the full struct (documents + url_to_id + bm25 + …)
        // rather than just the document array. Pluck what this provider needs.
        $corpus    = is_array($args['corpus']    ?? null) ? $args['corpus']   : [];
        $documents = is_array($corpus['documents'] ?? null) ? $corpus['documents'] : [];
        $url_to_id = is_array($corpus['url_to_id'] ?? null) ? $corpus['url_to_id'] : [];
        $messages  = is_array($args['messages']  ?? null) ? $args['messages'] : [];
        $tools     = is_array($args['tools']     ?? null) ? $args['tools']    : [];

        if ($api_key === '' || $model === '' || empty($messages)) {
            $on_chunk(['type' => 'error', 'data' => ['message' => __('Anthropic adapter missing required args.', 'opentrust')]]);
            return null;
        }

        // Anthropic tool definitions use 'input_schema' instead of 'parameters'.
        // System prompt + tools is the stable per-request block worth caching
        // — drop a cache breakpoint on the last tool entry.
        $anthropic_tools = [];
        foreach ($tools as $tool) {
            $anthropic_tools[] = [
                'name'         => (string) $tool['name'],
                'description'  => (string) $tool['description'],
                'input_schema' => $tool['parameters'] ?? ['type' => 'object', 'properties' => (object) []],
            ];
        }
        if (!empty($anthropic_tools)) {
            $last = count($anthropic_tools) - 1;
            $anthropic_tools[$last]['cache_control'] = ['type' => 'ephemeral'];
        }

        // No document-block attachment on the first user message. The corpus
        // lives in the cached system prompt as an index, and the model retrieves
        // full content via tools. Pass conversation history through unchanged.
        return [
            'api_key'         => $api_key,
            'model'           => $model,
            'system'          => $system,
            'anthropic_tools' => $anthropic_tools,
            'api_messages'    => $this->build_messages($messages),
            'documents'       => $documents,
            'url_to_id'       => $url_to_id,
        ];
    }

    protected function stream_one_turn(array &$turn_loop_state, bool $has_called_tool, callable $on_chunk): ?array {
        $payload = [
            'model'      => $turn_loop_state['model'],
            'max_tokens' => 4096,
            'stream'     => true,
            'system'     => [
                [
                    'type'          => 'text',
                    'text'          => $turn_loop_state['system'],
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
            'messages'   => $turn_loop_state['api_messages'],
        ];
        if (!empty($turn_loop_state['anthropic_tools'])) {
            $payload['tools'] = $turn_loop_state['anthropic_tools'];
            if (!$has_called_tool) {
                // Force a tool call on turn 1 so the model can never bypass
                // retrieval and answer from training data. After any tool
                // call we relax to auto.
                $payload['tool_choice'] = ['type' => 'any'];
            }
        }

        $turn_state = [
            'current_event'       => '',
            'content_blocks'      => [],
            'active_block_idx'    => -1,
            'stop_reason'         => '',
            'pending_tool_use'    => [],
            // Per-turn flag: have we already emitted the early `tool_intent`
            // SSE event? Fire it the moment Anthropic's stream signals its
            // FIRST tool_use content block so the visitor sees the pill in
            // ~1-2s instead of waiting 6-8s on parallel-tool turns. Reset
            // every turn.
            'tool_intent_emitted' => false,
            'usage'               => ['in' => 0, 'out' => 0, 'cache_create' => 0, 'cache_read' => 0],
            'corpus'              => $turn_loop_state['documents'],
            'url_to_id'           => $turn_loop_state['url_to_id'],
            'on_chunk'            => $on_chunk,
        ];

        $response = $this->stream_post(
            self::MESSAGES_ENDPOINT,
            $payload,
            [
                'x-api-key'         => $turn_loop_state['api_key'],
                'anthropic-version' => self::API_VERSION,
            ],
            function (string $line) use (&$turn_state): void {
                $this->handle_anthropic_sse_line($line, $turn_state);
            },
            90
        );

        if (empty($response['ok'])) {
            $on_chunk([
                'type' => 'error',
                'data' => ['message' => $response['error'] ?? __('Anthropic request failed.', 'opentrust')],
            ]);
            return null;
        }

        return $turn_state;
    }

    protected function extract_usage(array $stream_state): array {
        $u = is_array($stream_state['usage'] ?? null) ? $stream_state['usage'] : [];
        return [
            'tokens_in'      => (int) ($u['in']           ?? 0),
            'tokens_out'     => (int) ($u['out']          ?? 0),
            'cache_creation' => (int) ($u['cache_create'] ?? 0),
            'cache_read'     => (int) ($u['cache_read']   ?? 0),
        ];
    }

    protected function extract_pending_tool_calls(array $stream_state): array {
        if (($stream_state['stop_reason'] ?? '') !== 'tool_use') {
            return [];
        }
        $pending = is_array($stream_state['pending_tool_use'] ?? null) ? $stream_state['pending_tool_use'] : [];
        if (empty($pending)) {
            return [];
        }

        $normalized = [];
        foreach ($pending as $p) {
            $input_raw = (string) ($p['input_json'] ?? '');
            $args      = $input_raw !== '' ? json_decode($input_raw, true) : [];
            $normalized[] = [
                'name'       => (string) ($p['name'] ?? ''),
                'args'       => is_array($args) ? $args : [],
                // Anthropic-only fields the message-append hooks reach for.
                'id'         => (string) ($p['id'] ?? ''),
                'input_json' => $input_raw,
            ];
        }
        return $normalized;
    }

    protected function append_assistant_message(array &$turn_loop_state, array $stream_state): void {
        // Serialize the parsed content_blocks back to Anthropic's API-input
        // shape. Critical transforms:
        //   - tool_use: `input_json` (string) → `input` (parsed object)
        //   - text: drop `citations` (output-only; API rejects it on input)
        $assistant_content = [];
        $blocks = is_array($stream_state['content_blocks'] ?? null) ? $stream_state['content_blocks'] : [];
        ksort($blocks);
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $btype = (string) ($block['type'] ?? '');
            if ($btype === 'text') {
                $text = (string) ($block['text'] ?? '');
                if ($text === '') {
                    continue; // Anthropic rejects empty text blocks
                }
                $assistant_content[] = ['type' => 'text', 'text' => $text];
            } elseif ($btype === 'tool_use') {
                $input_raw = (string) ($block['input_json'] ?? '');
                $input     = $input_raw !== '' ? json_decode($input_raw, true) : [];
                if (!is_array($input)) {
                    $input = [];
                }
                $assistant_content[] = [
                    'type'  => 'tool_use',
                    'id'    => (string) ($block['id']   ?? ''),
                    'name'  => (string) ($block['name'] ?? ''),
                    // Anthropic requires `input` to be an object — use stdClass
                    // for empty input so JSON encodes {} not [].
                    'input' => empty($input) ? (object) [] : $input,
                ];
            }
        }
        if (empty($assistant_content)) {
            return;
        }
        $turn_loop_state['api_messages'][] = [
            'role'    => 'assistant',
            'content' => $assistant_content,
        ];
    }

    protected function resolve_and_append_tool_results(array &$turn_loop_state, array $pending, callable $tool_resolver): void {
        $tool_results = [];
        foreach ($pending as $p) {
            $name   = (string) ($p['name'] ?? '');
            $args   = is_array($p['args'] ?? null) ? $p['args'] : [];
            $id     = (string) ($p['id'] ?? '');
            $result = $tool_resolver($name, $args);
            // Resolver returns an array of search_result blocks; pass through
            // verbatim. Defensively coerce a stray string return into a plain
            // text content array.
            $content = is_array($result)
                ? array_values($result)
                : [['type' => 'text', 'text' => (string) $result]];
            $tool_results[] = [
                'type'        => 'tool_result',
                'tool_use_id' => $id,
                'content'     => $content,
            ];
        }
        $turn_loop_state['api_messages'][] = ['role' => 'user', 'content' => $tool_results];
    }

    /**
     * Pass the conversation history to Anthropic as plain role/content pairs.
     * No corpus attachment — the agentic engine retrieves on demand via tools.
     *
     * @param array<int, array{role:string, content:string}> $messages
     */
    private function build_messages(array $messages): array {
        $out = [];
        foreach ($messages as $msg) {
            $role    = (string) ($msg['role']    ?? 'user');
            $content = (string) ($msg['content'] ?? '');
            if ($content === '') {
                continue;
            }
            $out[] = ['role' => $role, 'content' => $content];
        }
        return $out;
    }

    /**
     * Parse a single line from Anthropic's SSE stream and update $state in place.
     */
    private function handle_anthropic_sse_line(string $line, array &$state): void {
        if ($line === '' || str_starts_with($line, ':')) {
            return; // empty separator or comment
        }

        if (str_starts_with($line, 'event: ')) {
            $state['current_event'] = substr($line, 7);
            return;
        }

        if (!str_starts_with($line, 'data: ')) {
            return;
        }

        $json = substr($line, 6);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return;
        }

        $event = $state['current_event'];

        switch ($event) {
            case 'message_start':
                if (isset($data['message']['usage'])) {
                    $u = $data['message']['usage'];
                    $state['usage']['in']          += (int) ($u['input_tokens']  ?? 0);
                    $state['usage']['cache_create']+= (int) ($u['cache_creation_input_tokens'] ?? 0);
                    $state['usage']['cache_read']  += (int) ($u['cache_read_input_tokens'] ?? 0);
                }
                break;

            case 'content_block_start':
                $idx   = (int) ($data['index'] ?? 0);
                $block = $data['content_block'] ?? [];
                $state['active_block_idx'] = $idx;
                $state['content_blocks'][$idx] = [
                    'type' => (string) ($block['type'] ?? 'text'),
                ];
                if (($block['type'] ?? '') === 'text') {
                    $state['content_blocks'][$idx]['text'] = '';
                    if (!empty($block['citations']) && is_array($block['citations'])) {
                        $state['content_blocks'][$idx]['citations'] = $block['citations'];
                    } else {
                        $state['content_blocks'][$idx]['citations'] = [];
                    }
                } elseif (($block['type'] ?? '') === 'tool_use') {
                    $state['content_blocks'][$idx]['id']         = (string) ($block['id']   ?? '');
                    $state['content_blocks'][$idx]['name']       = (string) ($block['name'] ?? '');
                    $state['content_blocks'][$idx]['input_json'] = '';

                    // Early intent signal: emit a generic `tool_intent` SSE
                    // event the moment Anthropic signals its first tool_use
                    // block of this turn. Without this, the pill can only
                    // appear after the entire turn is parsed (a single
                    // aggregated `tool_call` event at the end), which on
                    // parallel-tool turns means the visitor sits on three
                    // pulsing dots for 6-8s. With this, the pill appears
                    // in ~1-2s and the existing reuse path morphs its
                    // label to the specific count when the aggregated
                    // tool_call fires later in this turn.
                    if (!$state['tool_intent_emitted']) {
                        $state['tool_intent_emitted'] = true;
                        $tool_name = (string) ($block['name'] ?? '');
                        $intent_label = $tool_name === 'search_documents'
                            ? __('Searching documents…', 'opentrust')
                            : __('Reading documents…', 'opentrust');
                        ($state['on_chunk'])([
                            'type' => 'tool_intent',
                            'data' => [
                                'summary' => $intent_label,
                                'name'    => $tool_name,
                            ],
                        ]);
                    }
                }
                break;

            case 'content_block_delta':
                $idx   = (int) ($data['index'] ?? 0);
                $delta = $data['delta'] ?? [];
                $dtype = (string) ($delta['type'] ?? '');

                if ($dtype === 'text_delta') {
                    $text = (string) ($delta['text'] ?? '');
                    if ($text !== '') {
                        if (!isset($state['content_blocks'][$idx]['text'])) {
                            $state['content_blocks'][$idx]['text'] = '';
                        }
                        $state['content_blocks'][$idx]['text'] .= $text;
                        ($state['on_chunk'])(['type' => 'token', 'data' => ['text' => $text]]);
                    }
                } elseif ($dtype === 'citations_delta') {
                    $cite = $delta['citation'] ?? [];
                    $this->emit_citation_from_delta($cite, $state);
                } elseif ($dtype === 'input_json_delta') {
                    $state['content_blocks'][$idx]['input_json'] =
                        ($state['content_blocks'][$idx]['input_json'] ?? '') . (string) ($delta['partial_json'] ?? '');
                }
                break;

            case 'content_block_stop':
                $idx = (int) ($data['index'] ?? 0);
                $block = $state['content_blocks'][$idx] ?? [];
                if (($block['type'] ?? '') === 'tool_use') {
                    $state['pending_tool_use'][] = [
                        'id'         => (string) ($block['id']         ?? ''),
                        'name'       => (string) ($block['name']       ?? ''),
                        'input_json' => (string) ($block['input_json'] ?? ''),
                    ];
                }
                break;

            case 'message_delta':
                if (isset($data['delta']['stop_reason'])) {
                    $state['stop_reason'] = (string) $data['delta']['stop_reason'];
                }
                if (isset($data['usage']['output_tokens'])) {
                    $state['usage']['out'] += (int) $data['usage']['output_tokens'];
                }
                break;

            case 'message_stop':
                // Nothing to do; the loop in stream_chat checks stop_reason.
                break;

            case 'error':
                ($state['on_chunk'])([
                    'type' => 'error',
                    'data' => ['message' => (string) ($data['error']['message'] ?? 'Anthropic error')],
                ]);
                break;
        }
    }

    /**
     * Transform a citation delta into a normalized OpenTrust citation event.
     *
     * Anthropic emits `search_result_location` citations for content returned
     * by tools that produced search_result blocks. The canonical URL lives on
     * the citation object directly as `source` — no corpus lookup needed for
     * the URL. We do still reverse-look-up the doc id from the URL so the
     * front-end de-dup-by-id keeps subprocessors that share an anchor URL
     * (`/trust-center/#ot-subprocessors`) as separate citations.
     *
     * Error blocks (source = `about:none`) drop silently.
     */
    private function emit_citation_from_delta(array $cite, array $state): void {
        $type = (string) ($cite['type'] ?? '');

        if ($type !== 'search_result_location') {
            // Unknown citation type — ignore rather than emit a half-formed
            // event. Future Anthropic shapes can be added here defensively.
            return;
        }

        $url = (string) ($cite['source'] ?? '');
        if ($url === '' || $url === 'about:none') {
            return;
        }

        $url_to_id = is_array($state['url_to_id'] ?? null) ? $state['url_to_id'] : [];
        $id        = (string) ($url_to_id[$url] ?? '');

        $title = (string) ($cite['title'] ?? '');
        if ($title === '' && $id !== '') {
            // Anthropic returns `title: null` for docs whose source URL was
            // missing a title field; recover from the corpus when possible.
            foreach (($state['corpus'] ?? []) as $doc) {
                if ((string) ($doc['id'] ?? '') === $id) {
                    $title = (string) ($doc['title'] ?? '');
                    break;
                }
            }
        }

        ($state['on_chunk'])([
            'type' => 'citation',
            'data' => [
                'id'    => $id,
                'url'   => $url,
                'title' => $title,
                'quote' => (string) ($cite['cited_text'] ?? ''),
            ],
        ]);
    }
}
