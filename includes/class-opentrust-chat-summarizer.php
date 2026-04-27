<?php
/**
 * AI-generated 2–3 sentence summaries for ot_policy posts.
 *
 * The agentic chat engine reads a slim corpus index in its system prompt;
 * each policy line carries a one-paragraph summary used for routing decisions
 * (which document to fetch). For long-form policies the title alone isn't
 * descriptive enough — "Privacy Policy" doesn't reveal whether it covers
 * data subject rights or only retention. Auto-generated summaries close that
 * gap at ~1¢ per policy, lifetime.
 *
 * Lifecycle:
 *   - Operator opts in via the `ai_auto_summarize` setting (off by default).
 *   - On every meaningful save_post for an ot_policy, a wp_schedule_single_event
 *     fires ~5 seconds later (debounce; doesn't block the editor save).
 *   - The cron handler calls whichever AI provider the operator configured
 *     for chat, using the same key. Result is persisted to postmeta and the
 *     corpus transient is invalidated so the next chat request rebuilds the
 *     index with the fresh summary.
 *   - One-time sweep is exposed as a button on the AI Chat settings tab so
 *     existing installs can backfill summaries without saving every policy.
 *
 * Failure modes degrade gracefully: if the API call fails, the previous
 * summary stays in postmeta (or the corpus falls back to the post excerpt).
 * The chat works without summaries — they're a routing-quality optimization,
 * not a hard dependency.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OpenTrust_Chat_Summarizer {

    /**
     * Postmeta key. Mirror of OpenTrust_Chat_Corpus::POLICY_SUMMARY_META —
     * declared in both classes so neither file requires the other to load.
     */
    public const META_KEY            = '_ot_policy_chat_summary';
    public const META_KEY_UPDATED_AT = '_ot_policy_chat_summary_updated_at';
    public const META_KEY_ORIGIN     = '_ot_policy_chat_summary_origin'; // 'auto' | 'manual'

    public const CRON_HOOK           = 'opentrust_generate_policy_summary';
    public const SUMMARY_MAX_CHARS   = 320;
    public const SUMMARY_DEBOUNCE_S  = 5;
    public const SWEEP_STAGGER_S     = 2;

    /**
     * Char ceiling for the policy text we ship to the model. Longer policies
     * still get a useful summary from the head; we don't need every sentence
     * to write three about it.
     */
    private const POLICY_INPUT_MAX_CHARS = 12_000;

    public static function bootstrap(): void {
        add_action('save_post_ot_policy',           [self::class, 'on_save_post'], 20, 3);
        add_action(self::CRON_HOOK,                 [self::class, 'generate']);
    }

    /**
     * Hook handler. Decides whether a summary regeneration is warranted and
     * schedules a debounced cron call when it is. Skip conditions mirror the
     * fallback ladder in OpenTrust_Chat_Corpus::policy_summary().
     */
    public static function on_save_post(int $post_id, \WP_Post $post, bool $update): void {
        if ($post->post_status !== 'publish') {
            return;
        }
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        $settings = OpenTrust::get_settings();
        if (empty($settings['ai_auto_summarize'])) {
            return;
        }
        if (empty($settings['ai_provider']) || empty($settings['ai_model'])) {
            return;
        }
        // Manual override: respect the operator's hand-edited summary. They
        // can re-flip origin via the explicit "Regenerate" admin action.
        $origin = (string) get_post_meta($post_id, self::META_KEY_ORIGIN, true);
        if ($origin === 'manual') {
            return;
        }
        // Idempotency: skip if the existing summary is already up to date.
        $updated_at = (string) get_post_meta($post_id, self::META_KEY_UPDATED_AT, true);
        if ($updated_at !== '' && strtotime($updated_at) >= strtotime($post->post_modified_gmt . ' UTC')) {
            return;
        }

        // Already-scheduled? Don't enqueue a duplicate.
        if (wp_next_scheduled(self::CRON_HOOK, [$post_id])) {
            return;
        }
        wp_schedule_single_event(time() + self::SUMMARY_DEBOUNCE_S, self::CRON_HOOK, [$post_id]);
    }

    /**
     * Cron callback: fetch the post, build the prompt, call the configured
     * provider, persist the result. Bails silently on every reachable error
     * — the previous summary (if any) stays in place; chat falls back to
     * the post excerpt for documents that lack one.
     */
    public static function generate(int $post_id): void {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post || $post->post_type !== 'ot_policy' || $post->post_status !== 'publish') {
            return;
        }

        // Re-check skip conditions at run-time. Settings may have changed
        // between scheduling and execution.
        $settings = OpenTrust::get_settings();
        if (empty($settings['ai_auto_summarize'])) {
            return;
        }
        $provider_slug = (string) ($settings['ai_provider'] ?? '');
        $model         = (string) ($settings['ai_model']    ?? '');
        if ($provider_slug === '' || $model === '') {
            return;
        }
        $origin = (string) get_post_meta($post_id, self::META_KEY_ORIGIN, true);
        if ($origin === 'manual') {
            return;
        }

        $adapter = OpenTrust_Chat_Provider::for($provider_slug);
        if (!$adapter) {
            self::log_failure($post_id, 'unknown provider: ' . $provider_slug);
            return;
        }
        $api_key = OpenTrust_Chat_Secrets::get($provider_slug);
        if ($api_key === null || $api_key === '') {
            self::log_failure($post_id, 'no API key on file for ' . $provider_slug);
            return;
        }

        // Strip the policy down to plain text and clip the head.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
        $rendered = apply_filters('the_content', $post->post_content);
        $plain    = wp_strip_all_tags($rendered);
        $plain    = (string) preg_replace('/\s+/', ' ', trim($plain));
        if ($plain === '') {
            return; // nothing to summarize
        }
        if (strlen($plain) > self::POLICY_INPUT_MAX_CHARS) {
            $plain = rtrim(substr($plain, 0, self::POLICY_INPUT_MAX_CHARS)) . '…';
        }

        $system = 'Summarize the following trust center policy in 2 to 3 plain sentences that together describe what topics the policy covers. Total length must be under '
            . self::SUMMARY_MAX_CHARS . ' characters. Do not editorialize, do not hedge, do not use bullet points, and do not include marketing language. Output ONLY the summary text — no preamble, no headers, no commentary about the task.';
        $user   = 'Policy title: ' . (string) $post->post_title . "\n\nPolicy text:\n" . $plain;

        $args = [
            'system'   => $system,
            'corpus'   => [], // no corpus needed for summaries
            'messages' => [['role' => 'user', 'content' => $user]],
            'tools'    => [],  // no tool calls; just text
            'model'    => $model,
            'api_key'  => $api_key,
            'settings' => $settings,
        ];

        // Reuse the streaming path with a token-accumulating callback. The
        // upstream HTTP is still chunked but the response is small enough
        // (~80 tokens) that the round-trip completes in ~1s.
        $collected = '';
        $error_msg = null;
        $on_chunk  = function (array $event) use (&$collected, &$error_msg): void {
            $type = (string) ($event['type'] ?? '');
            if ($type === 'token') {
                $collected .= (string) ($event['data']['text'] ?? '');
            } elseif ($type === 'error') {
                $error_msg = (string) ($event['data']['message'] ?? 'provider error');
            }
        };
        $tool_resolver = static fn(string $name, array $args): array => [];

        try {
            $adapter->stream_chat($args, $on_chunk, $tool_resolver);
        } catch (\Throwable $e) {
            self::log_failure($post_id, 'provider exception: ' . $e->getMessage());
            return;
        }

        if ($error_msg !== null) {
            self::log_failure($post_id, $error_msg);
            return;
        }

        $summary = self::clean_summary($collected);
        if ($summary === '') {
            self::log_failure($post_id, 'empty response from provider');
            return;
        }

        update_post_meta($post_id, self::META_KEY,            $summary);
        update_post_meta($post_id, self::META_KEY_UPDATED_AT, current_time('mysql', true));
        update_post_meta($post_id, self::META_KEY_ORIGIN,     'auto');

        // Force the next chat request to rebuild the corpus index with the
        // fresh summary.
        if (class_exists('OpenTrust_Chat_Corpus')) {
            OpenTrust_Chat_Corpus::invalidate();
        }
    }

    /**
     * Enqueue summary generation for every published policy that is missing
     * an up-to-date summary. Returns the number of jobs enqueued.
     */
    public static function sweep_all(): int {
        $posts = get_posts([
            'post_type'      => 'ot_policy',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);
        if (empty($posts)) {
            return 0;
        }

        $enqueued = 0;
        $offset   = self::SWEEP_STAGGER_S;
        foreach ($posts as $post_id) {
            $post_id    = (int) $post_id;
            $modified   = (string) get_post_field('post_modified_gmt', $post_id);
            $updated_at = (string) get_post_meta($post_id, self::META_KEY_UPDATED_AT, true);
            if ($updated_at !== '' && strtotime($updated_at) >= strtotime($modified . ' UTC')) {
                continue; // already up to date
            }
            $origin = (string) get_post_meta($post_id, self::META_KEY_ORIGIN, true);
            if ($origin === 'manual') {
                continue;
            }
            if (wp_next_scheduled(self::CRON_HOOK, [$post_id])) {
                continue;
            }
            wp_schedule_single_event(time() + $offset, self::CRON_HOOK, [$post_id]);
            $offset += self::SWEEP_STAGGER_S;
            $enqueued++;
        }
        return $enqueued;
    }

    /**
     * Count of published policies missing an up-to-date AI summary. Drives
     * the admin notice on the AI Chat tab.
     */
    public static function missing_summary_count(): int {
        $posts = get_posts([
            'post_type'      => 'ot_policy',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ]);
        if (empty($posts)) {
            return 0;
        }
        $missing = 0;
        foreach ($posts as $post_id) {
            $post_id    = (int) $post_id;
            $summary    = (string) get_post_meta($post_id, self::META_KEY, true);
            $modified   = (string) get_post_field('post_modified_gmt', $post_id);
            $updated_at = (string) get_post_meta($post_id, self::META_KEY_UPDATED_AT, true);

            if ($summary === '') {
                $missing++;
                continue;
            }
            if ($updated_at !== '' && $modified !== '' && strtotime($updated_at) < strtotime($modified . ' UTC')) {
                $missing++;
            }
        }
        return $missing;
    }

    // ──────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────

    /**
     * Trim model output to one tidy paragraph under SUMMARY_MAX_CHARS.
     * Strips wrapping quotes, code fences, and "Summary:" preambles that
     * occasionally leak through despite the system prompt's instruction.
     */
    private static function clean_summary(string $raw): string {
        $s = trim($raw);
        if ($s === '') {
            return '';
        }
        // Remove common preamble patterns.
        $s = (string) preg_replace('/^\s*(here(?:\'|’)s|here is|summary)[:\s]\s*/i', '', $s);
        // Strip a leading/trailing matched pair of quotes, if any.
        if ((str_starts_with($s, '"') && str_ends_with($s, '"'))
            || (str_starts_with($s, '“') && str_ends_with($s, '”'))) {
            $s = substr($s, 1, -1);
        }
        // Collapse whitespace.
        $s = (string) preg_replace('/\s+/', ' ', $s);
        $s = trim($s);

        if (function_exists('mb_strlen') && mb_strlen($s) > self::SUMMARY_MAX_CHARS) {
            // Cut at a sentence boundary inside the cap when possible.
            $head     = mb_substr($s, 0, self::SUMMARY_MAX_CHARS);
            $break_at = max(mb_strrpos($head, '. '), mb_strrpos($head, '! '), mb_strrpos($head, '? '));
            if ($break_at !== false && $break_at > self::SUMMARY_MAX_CHARS / 2) {
                $s = rtrim(mb_substr($head, 0, $break_at + 1));
            } else {
                $s = rtrim($head) . '…';
            }
        }
        return $s;
    }

    private static function log_failure(int $post_id, string $reason): void {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for upstream provider failures
        error_log(sprintf('[OpenTrust] policy summary generation failed for post %d: %s', $post_id, $reason));
    }
}
