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

    public function validate_and_list_models(string $key): array {
        $key = trim($key);
        if ($key === '') {
            return ['ok' => false, 'error' => __('API key is empty.', 'opentrust')];
        }

        $response = $this->http_get(
            self::MODELS_ENDPOINT,
            [
                'x-api-key'         => $key,
                'anthropic-version' => self::API_VERSION,
            ],
            15
        );

        if (!$response['ok']) {
            return ['ok' => false, 'error' => $response['error'] ?? __('Request failed.', 'opentrust')];
        }

        $models = $this->curate_models($response['body']);

        if (empty($models)) {
            return ['ok' => false, 'error' => __('No models available — your account may not be authorized.', 'opentrust')];
        }

        return ['ok' => true, 'models' => $models];
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
        $corpus   = is_array($args['corpus'] ?? null) ? $args['corpus'] : [];
        $messages = is_array($args['messages'] ?? null) ? $args['messages'] : [];
        $tools    = is_array($args['tools'] ?? null) ? $args['tools'] : [];

        if ($api_key === '' || $model === '' || empty($messages)) {
            $on_chunk(['type' => 'error', 'data' => ['message' => __('Anthropic adapter missing required args.', 'opentrust')]]);
            return;
        }

        // Anthropic tool definitions use 'input_schema' instead of 'parameters'.
        $anthropic_tools = [];
        foreach ($tools as $tool) {
            $anthropic_tools[] = [
                'name'         => (string) $tool['name'],
                'description'  => (string) $tool['description'],
                'input_schema' => $tool['parameters'] ?? ['type' => 'object', 'properties' => (object) []],
            ];
        }

        // Build the initial messages array. Attach the corpus as `document` content
        // blocks on the FIRST user message so the Citations API can cite them.
        // The base system prompt is sent separately with prompt caching.
        $api_messages = $this->build_initial_messages($messages, $corpus);

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
            }

            $state = [
                'current_event'    => '',
                'content_blocks'   => [], // accumulated for next turn (if tool_use)
                'active_block_idx' => -1,
                'stop_reason'      => '',
                'pending_tool_use' => [],
                'usage'            => ['in' => 0, 'out' => 0, 'cache_create' => 0, 'cache_read' => 0],
                'corpus'           => $corpus,
                'on_chunk'         => $on_chunk,
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
                $tool_results[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => (string) $pending['id'],
                    'content'     => (string) $result,
                ];
            }
            $api_messages[] = ['role' => 'user', 'content' => $tool_results];
            // Loop again for the next turn.
        }

        // Exceeded tool turn cap without concluding.
        $on_chunk([
            'type' => 'error',
            'data' => ['message' => __('Conversation exceeded tool-use depth limit.', 'opentrust')],
        ]);
    }

    /**
     * Build the messages array sent to Anthropic on the first turn.
     * Attaches the corpus as `document` content blocks to the first user message.
     */
    private function build_initial_messages(array $messages, array $corpus): array {
        // Find the first user message in the conversation.
        $first_user_idx = null;
        foreach ($messages as $i => $msg) {
            if (($msg['role'] ?? '') === 'user') {
                $first_user_idx = $i;
                break;
            }
        }
        if ($first_user_idx === null) {
            // No user message — shouldn't happen, but handle gracefully.
            return [];
        }

        $document_blocks = [];
        foreach ($corpus as $idx => $doc) {
            $document_blocks[] = [
                'type'      => 'document',
                'source'    => [
                    'type'       => 'text',
                    'media_type' => 'text/plain',
                    'data'       => (string) $doc['content'],
                ],
                'title'     => (string) $doc['title'],
                'context'   => sprintf('%s | %s | %s', $doc['id'], $doc['type'], $doc['url']),
                'citations' => ['enabled' => true],
            ];
        }
        // Attach cache_control to the LAST document block to make the whole
        // system+docs prefix cacheable.
        if (!empty($document_blocks)) {
            $document_blocks[count($document_blocks) - 1]['cache_control'] = ['type' => 'ephemeral'];
        }

        $out = [];
        foreach ($messages as $i => $msg) {
            $role    = (string) $msg['role'];
            $content = (string) $msg['content'];

            if ($i === $first_user_idx) {
                $combined   = $document_blocks;
                $combined[] = ['type' => 'text', 'text' => $content];
                $out[] = ['role' => 'user', 'content' => $combined];
            } else {
                $out[] = ['role' => $role, 'content' => $content];
            }
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
     * Transform a citation delta payload into a normalized citation event.
     * Looks up the canonical URL from the corpus via `document_index`.
     */
    private function emit_citation_from_delta(array $cite, array $state): void {
        $doc_idx = isset($cite['document_index']) ? (int) $cite['document_index'] : -1;
        $corpus  = $state['corpus'] ?? [];
        if ($doc_idx < 0 || !isset($corpus[$doc_idx])) {
            return;
        }

        $doc = $corpus[$doc_idx];
        ($state['on_chunk'])([
            'type' => 'citation',
            'data' => [
                'id'    => (string) ($doc['id']    ?? ''),
                'url'   => (string) ($doc['url']   ?? ''),
                'title' => (string) ($doc['title'] ?? ($cite['document_title'] ?? '')),
                'quote' => (string) ($cite['cited_text'] ?? ''),
            ],
        ]);
    }
}
