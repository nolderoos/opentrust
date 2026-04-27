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

    protected function models_endpoint(): string {
        return static::MODELS_ENDPOINT;
    }

    protected function auth_headers(string $key): array {
        return ['Authorization' => 'Bearer ' . $key];
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

    protected function initialize_turn_loop(array $args, callable $on_chunk): ?array {
        $api_key   = (string) ($args['api_key']  ?? '');
        $model     = (string) ($args['model']    ?? '');
        $system    = (string) ($args['system']   ?? '');
        // Full corpus struct (documents + url_to_id + bm25 + …). OpenAI uses
        // documents for [[cite:id]] resolution and the corpus struct for the
        // search_results_to_xml id reverse-lookup.
        $corpus    = is_array($args['corpus']    ?? null) ? $args['corpus']   : [];
        $documents = is_array($corpus['documents'] ?? null) ? $corpus['documents'] : [];
        $messages  = is_array($args['messages']  ?? null) ? $args['messages'] : [];
        $tools     = is_array($args['tools']     ?? null) ? $args['tools']    : [];

        if ($api_key === '' || $model === '' || empty($messages)) {
            $on_chunk(['type' => 'error', 'data' => ['message' => __('OpenAI adapter missing required args.', 'opentrust')]]);
            return null;
        }

        // The system prompt arrives pre-built with the corpus index already
        // appended (see OpenTrust_Chat::build_system_prompt). Tack on a tail
        // that sets up the inline-citation contract — Anthropic uses native
        // search_result_location citations and doesn't need this block.
        $system_full = $this->append_citation_instructions($system);

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

        $api_messages = [['role' => 'system', 'content' => $system_full]];
        foreach ($messages as $msg) {
            $api_messages[] = [
                'role'    => (string) $msg['role'],
                'content' => (string) $msg['content'],
            ];
        }

        return [
            'api_key'            => $api_key,
            'model'              => $model,
            'openai_tools'       => $openai_tools,
            'api_messages'       => $api_messages,
            'corpus'             => $corpus,
            'documents'          => $documents,
            // Accumulated across turns — OpenAI's [[cite:id]] post-parse
            // (in finalize_after_loop) needs the full assistant text from
            // every turn, since the model may emit citations during a
            // pre-tool-call preamble as well as in the final answer.
            'full_answer_buffer' => '',
        ];
    }

    protected function stream_one_turn(array &$turn_loop_state, bool $has_called_tool, callable $on_chunk): ?array {
        $payload = [
            'model'          => $turn_loop_state['model'],
            'stream'         => true,
            'stream_options' => ['include_usage' => true],
            'messages'       => $turn_loop_state['api_messages'],
        ];
        if (!empty($turn_loop_state['openai_tools'])) {
            $payload['tools']       = $turn_loop_state['openai_tools'];
            // Force a tool call on turn 1 so the model can never bypass
            // retrieval; relax to auto after any tool call.
            $payload['tool_choice'] = $has_called_tool ? 'auto' : 'required';
        }

        $turn_state = [
            'answer_buffer' => '',
            'tool_calls'    => [], // index => { id, name, arguments_json }
            'finish_reason' => '',
            'usage'         => ['in' => 0, 'out' => 0],
            'on_chunk'      => $on_chunk,
        ];

        $headers = array_merge(
            ['Authorization' => 'Bearer ' . $turn_loop_state['api_key']],
            $this->extra_stream_headers() // OpenRouter overrides; OpenAI returns []
        );

        $response = $this->stream_post(
            // Late-bound — OpenRouter inherits this hook and gets its own URL.
            static::CHAT_COMPLETIONS_URL,
            $payload,
            $headers,
            function (string $line) use (&$turn_state): void {
                $this->handle_openai_sse_line($line, $turn_state);
            },
            90
        );

        if (empty($response['ok'])) {
            $on_chunk([
                'type' => 'error',
                'data' => ['message' => $response['error'] ?? __('OpenAI request failed.', 'opentrust')],
            ]);
            return null;
        }

        // Persist this turn's answer text so finalize_after_loop can scan
        // every turn's text for [[cite:id]] markers, not just the final one.
        $turn_loop_state['full_answer_buffer'] .= $turn_state['answer_buffer'];

        return $turn_state;
    }

    protected function extract_usage(array $stream_state): array {
        $u = is_array($stream_state['usage'] ?? null) ? $stream_state['usage'] : [];
        return [
            'tokens_in'  => (int) ($u['in']  ?? 0),
            'tokens_out' => (int) ($u['out'] ?? 0),
        ];
    }

    protected function extract_pending_tool_calls(array $stream_state): array {
        if (($stream_state['finish_reason'] ?? '') !== 'tool_calls') {
            return [];
        }
        $calls = is_array($stream_state['tool_calls'] ?? null) ? $stream_state['tool_calls'] : [];
        if (empty($calls)) {
            return [];
        }

        $normalized = [];
        foreach ($calls as $tc) {
            $args_raw = (string) ($tc['arguments_json'] ?? '');
            $args     = $args_raw !== '' ? json_decode($args_raw, true) : [];
            $normalized[] = [
                'name'           => (string) ($tc['name'] ?? ''),
                'args'           => is_array($args) ? $args : [],
                // OpenAI-only fields the message-append hooks reach for.
                'id'             => (string) ($tc['id'] ?? ''),
                'arguments_json' => $args_raw,
            ];
        }
        return $normalized;
    }

    protected function append_assistant_message(array &$turn_loop_state, array $stream_state): void {
        $assistant_tool_calls = [];
        foreach (($stream_state['tool_calls'] ?? []) as $tc) {
            if (!is_array($tc)) {
                continue;
            }
            $assistant_tool_calls[] = [
                'id'       => (string) ($tc['id'] ?? ''),
                'type'     => 'function',
                'function' => [
                    'name'      => (string) ($tc['name'] ?? ''),
                    'arguments' => (string) ($tc['arguments_json'] ?? ''),
                ],
            ];
        }
        $answer_text = (string) ($stream_state['answer_buffer'] ?? '');
        $turn_loop_state['api_messages'][] = [
            'role'       => 'assistant',
            'content'    => $answer_text !== '' ? $answer_text : null,
            'tool_calls' => $assistant_tool_calls,
        ];
    }

    protected function resolve_and_append_tool_results(array &$turn_loop_state, array $pending, callable $tool_resolver): void {
        $corpus = is_array($turn_loop_state['corpus'] ?? null) ? $turn_loop_state['corpus'] : [];
        // Resolver returns an array of search_result blocks; OpenAI's `tool`
        // message takes a string, so we serialize to <document>-tagged XML
        // that the model recognizes from the system prompt's CITATION FORMAT
        // instructions.
        foreach ($pending as $p) {
            $name      = (string) ($p['name'] ?? '');
            $args      = is_array($p['args'] ?? null) ? $p['args'] : [];
            $id        = (string) ($p['id'] ?? '');
            $result    = $tool_resolver($name, $args);
            $blocks    = is_array($result)
                ? $result
                : [['type' => 'text', 'text' => (string) $result]];
            $turn_loop_state['api_messages'][] = [
                'role'         => 'tool',
                'tool_call_id' => $id,
                'content'      => $this->search_results_to_xml($blocks, $corpus),
            ];
        }
    }

    protected function finalize_after_loop(array $turn_loop_state, array $stream_state, array $documents, callable $on_chunk): void {
        // Stream ended without another tool call — scan the accumulated
        // assistant text from EVERY turn for [[cite:id]] markers and emit
        // citation events. (Anthropic emits citations live during the stream
        // via emit_citation_from_delta, so it inherits the no-op default.)
        $answer = (string) ($turn_loop_state['full_answer_buffer'] ?? '');
        $this->extract_and_emit_citations($answer, $documents, $on_chunk);
    }

    /**
     * Extra headers subclasses can inject (OpenRouter needs HTTP-Referer / X-Title).
     */
    protected function extra_stream_headers(): array {
        return [];
    }

    /**
     * Append the OpenAI/OpenRouter-specific citation contract to the base
     * system prompt. The base prompt already contains the corpus index and
     * the role rules — see OpenTrust_Chat::build_system_prompt(). All we
     * add is how this provider should mark citations inline so the server
     * can convert them into structured citation events.
     *
     * Anthropic doesn't need this block — its native search_result_location
     * citations come through the streaming envelope automatically.
     */
    private function append_citation_instructions(string $base_prompt): string {
        $chunks = [
            $base_prompt,
            '',
            'CITATION FORMAT: When referencing a document in your answer, embed the tag [[cite:<document-id>]] inline at the exact point the claim is made. The <document-id> must match exactly an id from a document the tools returned to you. Example: "Our data is encrypted at rest [[cite:policy-infosec]] and in transit."',
            'Do not invent document ids. The only ids you may cite are those the tools have explicitly returned in their <document> blocks. If a tool returned no usable result, do not cite anything for that claim.',
        ];
        return implode("\n", $chunks);
    }

    /**
     * Convert an array of search_result content blocks (the resolver's return
     * shape) into the XML-tagged string OpenAI's `tool` role message takes.
     * The model parses the <document> tags to recover the doc id needed for
     * its inline [[cite:id]] citations.
     *
     * Error blocks (source = `about:none`) become <retrieval-error> tags so
     * the model has explicit "no result" framing without an associated id.
     *
     * @param array<int, array<string, mixed>> $blocks Search-result blocks.
     * @param array<string, mixed>             $corpus Full corpus struct.
     */
    private function search_results_to_xml(array $blocks, array $corpus): string {
        $url_to_id = is_array($corpus['url_to_id'] ?? null) ? $corpus['url_to_id'] : [];
        $parts     = [];

        foreach ($blocks as $b) {
            if (!is_array($b)) {
                continue;
            }
            $title  = (string) ($b['title']  ?? '');
            $source = (string) ($b['source'] ?? '');

            // Concatenate every text content fragment into one body.
            $body = '';
            foreach ((array) ($b['content'] ?? []) as $c) {
                if (is_array($c) && ($c['type'] ?? '') === 'text') {
                    $body .= (string) ($c['text'] ?? '');
                }
            }

            // Error block → no id, distinct tag so the model treats it as
            // an explicit "nothing found" framing rather than a citation
            // candidate.
            if ($source === 'about:none' || $source === '') {
                $parts[] = sprintf(
                    "<retrieval-error>%s</retrieval-error>",
                    $body
                );
                continue;
            }

            $id = (string) ($url_to_id[$source] ?? '');

            $parts[] = sprintf(
                "<document id=\"%s\" url=\"%s\" title=\"%s\">\n%s\n</document>",
                esc_attr($id),
                esc_attr($source),
                esc_attr($title),
                $body
            );
        }

        return implode("\n", $parts);
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
    private function extract_and_emit_citations(string $answer, array $documents, callable $on_chunk): void {
        if ($answer === '' || empty($documents)) {
            return;
        }

        // Index documents by id for O(1) lookup.
        $by_id = [];
        foreach ($documents as $doc) {
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
