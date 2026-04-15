<?php
/**
 * OpenAI provider adapter.
 *
 * API reference: https://platform.openai.com/docs/api-reference/models
 *                https://platform.openai.com/docs/api-reference/chat/create
 *                https://platform.openai.com/docs/guides/structured-outputs
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OpenTrust_Chat_Provider_OpenAI extends OpenTrust_Chat_Provider {

    protected const API_BASE               = 'https://api.openai.com';
    protected const MODELS_ENDPOINT        = 'https://api.openai.com/v1/models';
    protected const CHAT_COMPLETIONS_URL   = 'https://api.openai.com/v1/chat/completions';

    /**
     * Model IDs we recommend as defaults.
     * GPT-4o / 4.1 family — tool calling + JSON mode + solid structured output.
     */
    protected const RECOMMENDED_IDS = [
        'gpt-4o',
        'gpt-4.1',
    ];

    /**
     * Prefixes that identify chat models we want to show in the picker.
     * Everything else (audio, embeddings, moderation, fine-tuning, tts, whisper, dall-e)
     * is filtered out.
     */
    protected const CHAT_PREFIXES = [
        'gpt-4',
        'gpt-5',
        'o1',
        'o3',
        'o4',
    ];

    /**
     * Substrings that mark a model as excluded from the picker
     * even if its prefix matches (vision-only variants, transcription,
     * search-only, realtime audio, image generation, preview, etc).
     */
    protected const EXCLUDE_SUBSTRINGS = [
        'audio',
        'realtime',
        'transcribe',
        'tts',
        'image',
        'embedding',
        'moderation',
        'instruct',
        'search',
    ];

    public function slug(): string {
        return 'openai';
    }

    public function label(): string {
        return __('OpenAI', 'opentrust');
    }

    public function allowed_hosts(): array {
        return ['api.openai.com'];
    }

    public function citation_strategy(): string {
        return 'structured';
    }

    public function validate_and_list_models(string $key): array {
        $key = trim($key);
        if ($key === '') {
            return ['ok' => false, 'error' => __('API key is empty.', 'opentrust')];
        }

        $response = $this->http_get(
            static::MODELS_ENDPOINT,
            ['Authorization' => 'Bearer ' . $key],
            15
        );

        if (!$response['ok']) {
            return ['ok' => false, 'error' => $response['error'] ?? __('Request failed.', 'opentrust')];
        }

        $models = $this->curate_models($response['body']);

        if (empty($models)) {
            return ['ok' => false, 'error' => __('No chat models available for this key.', 'opentrust')];
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

            if (!$this->is_chat_model($id)) {
                continue;
            }

            $models[] = [
                'id'           => $id,
                'display_name' => $this->pretty_name($id),
                'recommended'  => $this->is_recommended($id),
            ];
        }

        // Sort: recommended first, then alphabetical descending (newest versions typically sort high).
        usort($models, function ($a, $b) {
            if ($a['recommended'] !== $b['recommended']) {
                return $a['recommended'] ? -1 : 1;
            }
            return strcmp($b['id'], $a['id']);
        });

        return $models;
    }

    protected function is_chat_model(string $id): bool {
        $has_prefix = false;
        foreach (static::CHAT_PREFIXES as $prefix) {
            if (str_starts_with($id, $prefix)) {
                $has_prefix = true;
                break;
            }
        }
        if (!$has_prefix) {
            return false;
        }

        foreach (static::EXCLUDE_SUBSTRINGS as $needle) {
            if (str_contains($id, $needle)) {
                return false;
            }
        }

        return true;
    }

    protected function is_recommended(string $id): bool {
        // Exact-prefix match, and we only recommend the "bare" variant (gpt-4o, not gpt-4o-mini-2024-07-18).
        // The bare variant is the latest auto-upgraded pointer on OpenAI.
        foreach (static::RECOMMENDED_IDS as $needle) {
            if ($id === $needle) {
                return true;
            }
        }
        return false;
    }

    protected function pretty_name(string $id): string {
        // OpenAI /models doesn't return display names; humanize the ID.
        $pretty = str_replace(['-', '_'], ' ', $id);
        return ucwords($pretty);
    }

    public function stream_chat(array $args, callable $on_chunk, callable $tool_resolver): void {
        $api_key  = (string) ($args['api_key']  ?? '');
        $model    = (string) ($args['model']    ?? '');
        $system   = (string) ($args['system']   ?? '');
        $corpus   = is_array($args['corpus'] ?? null) ? $args['corpus'] : [];
        $messages = is_array($args['messages'] ?? null) ? $args['messages'] : [];
        $tools    = is_array($args['tools'] ?? null) ? $args['tools'] : [];

        if ($api_key === '' || $model === '' || empty($messages)) {
            $on_chunk(['type' => 'error', 'data' => ['message' => __('OpenAI adapter missing required args.', 'opentrust')]]);
            return;
        }

        // OpenAI / OpenRouter use plain streaming with inline citation tags.
        // The system prompt embeds the full corpus and instructs the model to
        // emit references as `[[cite:<doc-id>]]` tags inline. We parse these
        // tags out of the streamed text post-hoc and emit citation events.
        $system_full = $this->build_system_prompt_with_corpus($system, $corpus);

        // OpenAI tool definitions wrap each tool in { type: 'function', function: {...} }.
        $openai_tools = [];
        foreach ($tools as $tool) {
            $openai_tools[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => (string) $tool['name'],
                    'description' => (string) $tool['description'],
                    'parameters'  => $tool['parameters'] ?? ['type' => 'object', 'properties' => (object) []],
                ],
            ];
        }

        // Build the running message list.
        $api_messages = [['role' => 'system', 'content' => $system_full]];
        foreach ($messages as $msg) {
            $api_messages[] = [
                'role'    => (string) $msg['role'],
                'content' => (string) $msg['content'],
            ];
        }

        $full_answer_buffer = '';

        $turn = 0;
        while ($turn < OpenTrust_Chat::MAX_TOOL_TURNS) {
            $turn++;

            $payload = [
                'model'          => $model,
                'stream'         => true,
                'stream_options' => ['include_usage' => true],
                'messages'       => $api_messages,
            ];
            if (!empty($openai_tools)) {
                $payload['tools']       = $openai_tools;
                $payload['tool_choice'] = 'auto';
            }

            $state = [
                'answer_buffer'   => '',
                'tool_calls'      => [], // index => { id, name, arguments_json }
                'finish_reason'   => '',
                'usage'           => ['in' => 0, 'out' => 0],
                'on_chunk'        => $on_chunk,
            ];

            $extra_headers = $this->extra_stream_headers();
            $headers = array_merge(
                ['Authorization' => 'Bearer ' . $api_key],
                $extra_headers
            );

            $response = $this->stream_post(
                static::CHAT_COMPLETIONS_URL,
                $payload,
                $headers,
                function (string $line) use (&$state): void {
                    $this->handle_openai_sse_line($line, $state);
                },
                90
            );

            if (empty($response['ok'])) {
                $on_chunk([
                    'type' => 'error',
                    'data' => ['message' => $response['error'] ?? __('OpenAI request failed.', 'opentrust')],
                ]);
                return;
            }

            $full_answer_buffer .= $state['answer_buffer'];

            $on_chunk(['type' => 'usage', 'data' => [
                'tokens_in'  => $state['usage']['in'],
                'tokens_out' => $state['usage']['out'],
            ]]);

            // Tool calls? Resolve and continue.
            if ($state['finish_reason'] === 'tool_calls' && !empty($state['tool_calls'])) {
                // Append the assistant message with the tool_calls it made.
                $assistant_tool_calls = [];
                foreach ($state['tool_calls'] as $tc) {
                    $assistant_tool_calls[] = [
                        'id'       => (string) $tc['id'],
                        'type'     => 'function',
                        'function' => [
                            'name'      => (string) $tc['name'],
                            'arguments' => (string) $tc['arguments_json'],
                        ],
                    ];
                }
                $api_messages[] = [
                    'role'       => 'assistant',
                    'content'    => $state['answer_buffer'] !== '' ? $state['answer_buffer'] : null,
                    'tool_calls' => $assistant_tool_calls,
                ];

                // Append tool results.
                foreach ($state['tool_calls'] as $tc) {
                    $input = json_decode((string) $tc['arguments_json'], true);
                    if (!is_array($input)) {
                        $input = [];
                    }
                    $result = $tool_resolver((string) $tc['name'], $input);
                    $api_messages[] = [
                        'role'         => 'tool',
                        'tool_call_id' => (string) $tc['id'],
                        'content'      => $result,
                    ];
                }

                continue;
            }

            // Stream ended without tool call — parse citations from the full answer.
            $this->extract_and_emit_citations($full_answer_buffer, $corpus, $on_chunk);
            return;
        }

        $on_chunk([
            'type' => 'error',
            'data' => ['message' => __('Conversation exceeded tool-use depth limit.', 'opentrust')],
        ]);
    }

    /**
     * Extra headers subclasses can inject (OpenRouter needs HTTP-Referer / X-Title).
     */
    protected function extra_stream_headers(): array {
        return [];
    }

    /**
     * Embed the corpus into the system prompt and instruct the model to emit
     * inline citation tags like [[cite:policy-privacy]] that we convert to
     * citation events server-side.
     */
    private function build_system_prompt_with_corpus(string $base_prompt, array $corpus): string {
        $chunks = [$base_prompt, '', '<documents>'];
        foreach ($corpus as $doc) {
            $chunks[] = sprintf(
                '<document id="%s" type="%s" url="%s" title="%s">',
                esc_attr((string) $doc['id']),
                esc_attr((string) $doc['type']),
                esc_attr((string) $doc['url']),
                esc_attr((string) $doc['title'])
            );
            $chunks[] = (string) $doc['content'];
            $chunks[] = '</document>';
        }
        $chunks[] = '</documents>';
        $chunks[] = '';
        $chunks[] = 'CITATION FORMAT: When referencing a document, embed the tag [[cite:<document-id>]] inline in your answer at the exact point the claim is made. The <document-id> must match one of the id attributes in the documents above exactly. Example: "Our data is encrypted at rest [[cite:policy-infosec]] and in transit."';
        $chunks[] = 'Do not invent document IDs. If you cannot cite a specific document for a claim, do not make the claim.';

        return implode("\n", $chunks);
    }

    /**
     * Parse an OpenAI SSE line and update $state in place.
     */
    private function handle_openai_sse_line(string $line, array &$state): void {
        if ($line === '' || str_starts_with($line, ':')) {
            return;
        }

        // OpenAI prefixes every event with `data: `.
        if (!str_starts_with($line, 'data: ')) {
            return;
        }
        $json = substr($line, 6);

        if ($json === '[DONE]') {
            return;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return;
        }

        // Usage can arrive in its own "usage" chunk at the end.
        if (isset($data['usage']) && is_array($data['usage'])) {
            $state['usage']['in']  += (int) ($data['usage']['prompt_tokens']     ?? 0);
            $state['usage']['out'] += (int) ($data['usage']['completion_tokens'] ?? 0);
        }

        $choice = $data['choices'][0] ?? null;
        if (!is_array($choice)) {
            return;
        }

        if (!empty($choice['finish_reason'])) {
            $state['finish_reason'] = (string) $choice['finish_reason'];
        }

        $delta = $choice['delta'] ?? [];
        if (!is_array($delta)) {
            return;
        }

        // Token content.
        if (isset($delta['content']) && is_string($delta['content']) && $delta['content'] !== '') {
            $text = $delta['content'];
            $state['answer_buffer'] .= $text;
            ($state['on_chunk'])(['type' => 'token', 'data' => ['text' => $text]]);
        }

        // Tool call deltas. Arguments arrive in partial JSON chunks per tool call index.
        if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
            foreach ($delta['tool_calls'] as $tc) {
                $idx = (int) ($tc['index'] ?? 0);
                if (!isset($state['tool_calls'][$idx])) {
                    $state['tool_calls'][$idx] = [
                        'id'             => '',
                        'name'           => '',
                        'arguments_json' => '',
                    ];
                }
                if (!empty($tc['id'])) {
                    $state['tool_calls'][$idx]['id'] = (string) $tc['id'];
                }
                if (isset($tc['function']['name']) && is_string($tc['function']['name']) && $tc['function']['name'] !== '') {
                    $state['tool_calls'][$idx]['name'] = $tc['function']['name'];
                }
                if (isset($tc['function']['arguments']) && is_string($tc['function']['arguments'])) {
                    $state['tool_calls'][$idx]['arguments_json'] .= $tc['function']['arguments'];
                }
            }
        }
    }

    /**
     * Scan the full answer for [[cite:doc-id]] tags and emit citation events
     * with URLs resolved from the corpus. Unknown IDs are ignored here;
     * URL whitelist enforcement happens upstream in OpenTrust_Chat.
     */
    private function extract_and_emit_citations(string $answer, array $corpus, callable $on_chunk): void {
        if ($answer === '' || empty($corpus)) {
            return;
        }

        // Index corpus by id for O(1) lookup.
        $by_id = [];
        foreach ($corpus as $doc) {
            $by_id[(string) $doc['id']] = $doc;
        }

        $seen = [];
        if (!preg_match_all('/\[\[cite:([a-z0-9_\-]+)\]\]/i', $answer, $matches)) {
            return;
        }

        foreach ($matches[1] as $id) {
            $id = (string) $id;
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            if (!isset($by_id[$id])) {
                continue;
            }
            $doc = $by_id[$id];
            $on_chunk([
                'type' => 'citation',
                'data' => [
                    'id'    => $id,
                    'url'   => (string) $doc['url'],
                    'title' => (string) $doc['title'],
                    'quote' => '',
                ],
            ]);
        }
    }
}
