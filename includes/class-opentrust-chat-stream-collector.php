<?php
/**
 * Streaming-event collector shared by the three chat dispatch paths.
 *
 * Before this class existed, the citation URL-allowlist gate, doc-id de-dup,
 * usage accumulator, and tool-name capture lived as a hand-rolled `$on_chunk`
 * closure repeated three times: in OpenTrust_Chat::drive_stream, ::drive_blocking,
 * and OpenTrust_Render::handle_chat_noscript_post. The audit flagged this as
 * the highest-leverage drift hazard in the codebase: each closure was the
 * security boundary for which citations make it back to the visitor, and the
 * doc-id de-dup comment was already copy-pasted verbatim three times — exactly
 * how that kind of logic drifts.
 *
 * Now all three callers funnel events through one ingest() method, so a fix
 * to the allowlist, the de-dup key, or the refusal detector lands in one place.
 *
 * Usage:
 *   $collector = new OpenTrust_Chat_Stream_Collector($corpus['urls'] ?? []);
 *   $on_chunk  = static function (array $event) use ($collector, $sse_emit) {
 *       if ($collector->ingest($event)) {
 *           $sse_emit($event);   // forward to the client
 *       }
 *   };
 *   $adapter->stream_chat($args, $on_chunk, $tool_resolver);
 *
 * The `bool` return of ingest() distinguishes events the collector consumes
 * internally (citation, usage — never forwarded raw because citations need
 * post-stream whitelist verification) from events callers may want to forward
 * live (token, tool_call, error). Blocking callers ignore the return value.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OpenTrust_Chat_Stream_Collector {

    /** @var array<int, string> */
    private array $whitelist;

    public string $answer = '';

    /** @var array<int, array<string, mixed>> */
    public array $citations = [];

    /** @var array<string, true> */
    private array $seen_keys = [];

    public int $tokens_in      = 0;
    public int $tokens_out     = 0;
    public int $cache_creation = 0;
    public int $cache_read     = 0;

    /** @var array<int, string> */
    public array $tool_names = [];

    public ?string $error = null;

    /**
     * @param array<int, string> $whitelist URL allowlist from the corpus build.
     */
    public function __construct(array $whitelist) {
        $this->whitelist = $whitelist;
    }

    /**
     * Process a normalized event from a provider's stream_chat callback.
     *
     * @param array{type?: string, data?: array<string, mixed>} $event
     * @return bool true if the event should also be forwarded to the client
     *              (token, tool_call, error, unknown); false for events the
     *              collector consumes internally (citation, usage).
     */
    public function ingest(array $event): bool {
        $type = (string) ($event['type'] ?? '');
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];

        switch ($type) {
            case 'token':
                $this->answer .= (string) ($data['text'] ?? '');
                return true;

            case 'citation':
                $url = (string) ($data['url'] ?? '');
                if (!OpenTrust_Chat::url_allowed($url, $this->whitelist)) {
                    return false; // dropped by allowlist
                }
                // De-dup by DOCUMENT ID (each corpus doc has a unique id like
                // "sub-amazon-web-services"), NOT by URL — multiple docs of the
                // same type share a single anchor URL (/trust-center/#subprocessors)
                // so URL-based de-dup would collapse all subprocessors into one.
                // Fall back to URL only if the provider didn't include an id.
                $dedup_key = (string) ($data['id'] ?? '');
                if ($dedup_key === '') {
                    $dedup_key = $url;
                }
                if ($dedup_key === '' || isset($this->seen_keys[$dedup_key])) {
                    return false;
                }
                $this->seen_keys[$dedup_key] = true;
                $this->citations[] = $data;
                return false; // never forward citations live — emit after the stream settles

            case 'usage':
                $this->tokens_in      += (int) ($data['tokens_in']      ?? 0);
                $this->tokens_out     += (int) ($data['tokens_out']     ?? 0);
                $this->cache_creation += (int) ($data['cache_creation'] ?? 0);
                $this->cache_read     += (int) ($data['cache_read']     ?? 0);
                return false; // internal accounting only

            case 'tool_call':
                if (!empty($data['names']) && is_array($data['names'])) {
                    foreach ($data['names'] as $n) {
                        $this->tool_names[] = (string) $n;
                    }
                } elseif (!empty($data['name'])) {
                    $this->tool_names[] = (string) $data['name'];
                }
                return true;

            case 'error':
                $this->error = (string) ($data['message'] ?? 'error');
                return true;
        }

        // Unknown event types: forward by default; the collector ignores them.
        return true;
    }

    /**
     * Two-signal refusal heuristic: canonical phrase + zero citations.
     */
    public function detect_refusal(): bool {
        return OpenTrust_Chat::detect_refusal($this->answer, $this->citations);
    }

    /**
     * Build the stats array for budget commit + chat-log row.
     *
     * @return array{
     *     actual_tokens:int, tokens_in:int, tokens_out:int,
     *     cache_creation:int, cache_read:int, citation_count:int,
     *     refused:bool, tool_turns:int, tool_names:string
     * }
     */
    public function stats(): array {
        return [
            'actual_tokens'  => $this->tokens_in + $this->tokens_out,
            'tokens_in'      => $this->tokens_in,
            'tokens_out'     => $this->tokens_out,
            'cache_creation' => $this->cache_creation,
            'cache_read'     => $this->cache_read,
            'citation_count' => count($this->citations),
            'refused'        => $this->detect_refusal(),
            'tool_turns'     => count($this->tool_names),
            'tool_names'     => OpenTrust_Chat::format_tool_names_for_log($this->tool_names),
        ];
    }
}
