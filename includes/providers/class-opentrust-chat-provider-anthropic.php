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

    public function citation_strategy(): string {
        return 'native';
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

    public function stream_chat(array $args, callable $on_chunk, callable $tool_resolver): void {
        $api_key  = (string) ($args['api_key']  ?? '');
        $model    = (string) ($args['model']    ?? '');
        $system   = (string) ($args['system']   ?? '');
        // The corpus is now the full struct (documents + url_to_id + bm25 + …)
        // rather than just the document array. Pluck what this provider needs.
        $corpus   = is_array($args['corpus'] ?? null) ? $args['corpus'] : [];
        $documents = is_array($corpus['documents'] ?? null) ? $corpus['documents'] : [];
        $url_to_id = is_array($corpus['url_to_id'] ?? null) ? $corpus['url_to_id'] : [];
        $messages  = is_array($args['messages'] ?? null) ? $args['messages'] : [];
        $tools     = is_array($args['tools']    ?? null) ? $args['tools']    : [];

        if ($api_key === '' || $model === '' || empty($messages)) {
            $on_chunk(['type' => 'error', 'data' => ['message' => __('Anthropic adapter missing required args.', 'opentrust')]]);
            return;
        }

        // Anthropic tool definitions use 'input_schema' instead of 'parameters'.
        // The system prompt + index is the only large stable block now, so we
        // also place a cache breakpoint on the tools array — that pair is what
        // remains stable across turns and across visitors.
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

        // No more document-block attachment on the first user message. The
        // corpus now lives in the cached system prompt as an index, and the
        // model retrieves full content via tools. Pass conversation history
        // through unchanged.
        $api_messages = $this->build_messages($messages);

        // Force a tool call on turn 1 so the model can never bypass retrieval
        // and answer from training data. After the model has called any tool
        // we relax to default (auto), so it can synthesize from results.
        $has_called_tool = false;

        // Loop: make a request, handle tool uses, repeat until end_turn.
        $turn = 0;
        while ($turn < OpenTrust_Chat::MAX_TOOL_TURNS) {
            $turn++;

            $payload = [
                'model'      => $model,
                'max_tokens' => 4096,
                'stream'     => true,
                'system'     => [
                    [
                        'type'          => 'text',
                        'text'          => $system,
                        'cache_control' => ['type' => 'ephemeral'],
                    ],
                ],
                'messages'   => $api_messages,
            ];
            if (!empty($anthropic_tools)) {
                $payload['tools'] = $anthropic_tools;
                if (!$has_called_tool) {
                    $payload['tool_choice'] = ['type' => 'any'];
                }
            }

            $state = [
                'current_event'       => '',
                'content_blocks'      => [], // accumulated for next turn (if tool_use)
                'active_block_idx'    => -1,
                'stop_reason'         => '',
                'pending_tool_use'    => [],
                // Per-turn flag: have we already emitted the early `tool_intent`
                // SSE event? We fire it the moment Anthropic's stream signals
                // its FIRST tool_use content block so the visitor sees the pill
                // within ~1-2s of submit instead of waiting 6-8s for the model
                // to finish generating all parallel tool_use blocks. Reset
                // every turn (state is rebuilt at the top of the while loop).
                'tool_intent_emitted' => false,
                'usage'               => ['in' => 0, 'out' => 0, 'cache_create' => 0, 'cache_read' => 0],
                'corpus'              => $documents,
                'url_to_id'           => $url_to_id,
                'on_chunk'            => $on_chunk,
            ];

            $response = $this->stream_post(
                self::MESSAGES_ENDPOINT,
                $payload,
                [
                    'x-api-key'         => $api_key,
                    'anthropic-version' => self::API_VERSION,
                ],
                function (string $line) use (&$state): void {
                    $this->handle_anthropic_sse_line($line, $state);
                },
                90
            );

            if (empty($response['ok'])) {
                $on_chunk([
                    'type' => 'error',
                    'data' => ['message' => $response['error'] ?? __('Anthropic request failed.', 'opentrust')],
                ]);
                return;
            }

            // Emit usage tracking (internal, not forwarded to client).
            $on_chunk(['type' => 'usage', 'data' => [
                'tokens_in'      => $state['usage']['in'],
                'tokens_out'     => $state['usage']['out'],
                'cache_creation' => $state['usage']['cache_create'],
                'cache_read'     => $state['usage']['cache_read'],
            ]]);

            // Did the model call any tools?
            if ($state['stop_reason'] !== 'tool_use' || empty($state['pending_tool_use'])) {
                return;
            }
            $has_called_tool = true;

            // Emit ONE aggregated tool_call SSE event per turn so the UI can
            // show a single morphing status pill rather than a wall of N
            // separate pills when the model fires parallel tool uses (e.g.
            // 8 get_document calls in one response). The event carries the
            // active-state summary, the count, and the per-tool names for
            // logging.
            $turn_names = [];
            foreach ($state['pending_tool_use'] as $pending) {
                $turn_names[] = (string) $pending['name'];
            }
            $on_chunk([
                'type' => 'tool_call',
                'data' => [
                    'name'            => $turn_names[0] ?? 'tool_call',
                    'summary'         => $this->summarize_pending_turn($state['pending_tool_use'], $documents),
                    // Past-tense form for the settled-pill state — used by
                    // the JS when the single-turn case keeps the pill's
                    // specific label (e.g. "Searched for X" instead of
                    // "Searching for X"). For multi-turn flows the JS
                    // builds a cross-turn count aggregate of its own.
                    'settled_summary' => $this->summarize_settled_turn($state['pending_tool_use'], $documents),
                    'count'           => count($state['pending_tool_use']),
                    'names'           => $turn_names,
                ],
            ]);

            // Serialize our internal content_blocks shape into Anthropic's
            // API-expected shape for echo-back. Critical transforms:
            //  - tool_use: `input_json` (string) → `input` (parsed object)
            //  - text: drop `citations` (output-only; API rejects it on input)
            $assistant_content = [];
            ksort($state['content_blocks']);
            foreach ($state['content_blocks'] as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $btype = (string) ($block['type'] ?? '');
                if ($btype === 'text') {
                    $text = (string) ($block['text'] ?? '');
                    if ($text === '') {
                        continue; // skip empty text blocks — Anthropic rejects them
                    }
                    $assistant_content[] = [
                        'type' => 'text',
                        'text' => $text,
                    ];
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
                        // Anthropic requires `input` to be an object, not []
                        // — use stdClass for an empty input so JSON encodes {}.
                        'input' => empty($input) ? (object) [] : $input,
                    ];
                }
            }
            if (empty($assistant_content)) {
                // Shouldn't happen if stop_reason is tool_use, but bail out
                // with a friendly error rather than a hard crash.
                $on_chunk(['type' => 'error', 'data' => ['message' => __('Tool call produced no content blocks.', 'opentrust')]]);
                return;
            }

            $api_messages[] = [
                'role'    => 'assistant',
                'content' => $assistant_content,
            ];

            $tool_results = [];
            foreach ($state['pending_tool_use'] as $pending) {
                $input = is_string($pending['input_json']) && $pending['input_json'] !== ''
                    ? (json_decode($pending['input_json'], true) ?: [])
                    : [];
                $result = $tool_resolver((string) $pending['name'], is_array($input) ? $input : []);

                // Resolver returns an array of search_result blocks; pass
                // through verbatim. Defensively coerce a stray string return
                // (legacy callers) into a plain text content array.
                $tool_content = is_array($result)
                    ? array_values($result)
                    : [['type' => 'text', 'text' => (string) $result]];

                $tool_results[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => (string) $pending['id'],
                    'content'     => $tool_content,
                ];
            }
            $api_messages[] = ['role' => 'user', 'content' => $tool_results];
            // Loop again for the next turn.
        }

        $this->emit_cap_refusal($on_chunk);
    }

    /**
     * Aggregate label for one Anthropic turn that may carry multiple
     * parallel tool_use blocks. Single-tool turns keep the specific label
     * ("Reading Privacy Policy"); multi-tool turns collapse to a count
     * ("Reading 8 documents") so the UI can show one morphing pill instead
     * of a wall of per-tool pills.
     *
     * @param array<int, array{id?:string, name?:string, input_json?:string}> $pending
     * @param array<int, array<string, mixed>>                                 $documents
     */
    private function summarize_pending_turn(array $pending, array $documents): string {
        return $this->summarize_turn(/* tense */ 'pending', $pending, $documents);
    }

    /**
     * Past-tense mirror of summarize_pending_turn — used as the
     * `settled_summary` field on the tool_call SSE event so the JS can
     * morph the pill's label from active ("Searching for X") to past
     * ("Searched for X") when the conversation settles. Multi-turn flows
     * are handled JS-side with a cross-turn count aggregate; this helper
     * is what the JS uses when only one turn fired tool calls.
     *
     * @param array<int, array{id?:string, name?:string, input_json?:string}> $pending
     * @param array<int, array<string, mixed>>                                 $documents
     */
    private function summarize_settled_turn(array $pending, array $documents): string {
        return $this->summarize_turn(/* tense */ 'settled', $pending, $documents);
    }

    /**
     * Shared body for summarize_pending_turn / summarize_settled_turn. Single-
     * tool turns get a specific label via the base summarize_tool_call helper;
     * multi-tool turns collapse to a count via summarize_turn_batch.
     *
     * @param 'pending'|'settled' $tense
     * @param array<int, array{id?:string, name?:string, input_json?:string}> $pending
     * @param array<int, array<string, mixed>>                                 $documents
     */
    private function summarize_turn(string $tense, array $pending, array $documents): string {
        $count = count($pending);
        if ($count === 1) {
            $only      = $pending[0];
            $input_raw = is_string($only['input_json'] ?? null) ? (string) $only['input_json'] : '';
            $input     = $input_raw !== '' ? (json_decode($input_raw, true) ?: []) : [];
            return self::summarize_tool_call(
                (string) ($only['name'] ?? ''),
                is_array($input) ? $input : [],
                $documents,
                $tense
            );
        }

        $names = array_map(static fn(array $p): string => (string) ($p['name'] ?? ''), $pending);
        return self::summarize_turn_batch($names, $count, $tense);
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
