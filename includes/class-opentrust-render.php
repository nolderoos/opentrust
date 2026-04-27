<?php
/**
 * Frontend rendering engine.
 *
 * Outputs a complete, standalone HTML document for the trust center,
 * completely bypassing the active theme.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OpenTrust_Render {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    // ──────────────────────────────────────────────
    // Dispatch
    // ──────────────────────────────────────────────

    public function dispatch(string $page): void {
        match ($page) {
            'main'           => $this->render_trust_center(),
            'policy'         => $this->render_policy_single(),
            'policy_version' => $this->render_policy_version(),
            'ask'            => $this->render_chat_page(),
            default          => $this->render_404(),
        };
    }

    // ──────────────────────────────────────────────
    // AI chat page
    // ──────────────────────────────────────────────

    private function render_chat_page(): void {
        $ot_settings = OpenTrust::get_settings();
        $ot_data     = $this->gather_data($ot_settings);
        $ot_data['view']        = 'chat';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only prefill of search box on public chat page.
        $ot_data['prefill_q']   = isset($_GET['q']) ? sanitize_text_field((string) wp_unslash($_GET['q'])) : '';
        $ot_data['source_counts'] = [
            'certifications' => count($ot_data['certifications'] ?? []),
            'policies'       => count($ot_data['policies']       ?? []),
            'subprocessors'  => count($ot_data['subprocessors']  ?? []),
            'data_practices' => count($ot_data['data_practices'] ?? []),
        ];

        // Determine page state: unconfigured | ready | unavailable.
        $ot_data['chat_state'] = $this->compute_chat_state($ot_settings);

        // No-JS fallback: handle a synchronous HTML form POST here.
        $ot_data['noscript_response'] = null;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Literal string comparison, never stored.
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $ot_data['chat_state'] === 'ready') {
            $ot_data['noscript_response'] = $this->handle_chat_noscript_post($ot_settings);
        }

        // Never cache the chat page (fresh nonce required every load).
        // Set session cookie BEFORE any header() / body output.
        if (class_exists('OpenTrust_Chat_Budget')) {
            OpenTrust_Chat_Budget::ensure_session_cookie();
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');

        include OPENTRUST_PLUGIN_DIR . 'templates/chat.php';
    }

    /**
     * No-JS fallback: processes a synchronous POST from the chat page form
     * and returns a structured response array to render inline.
     */
    private function handle_chat_noscript_post(array $settings): array {
        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field((string) wp_unslash($_POST['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'opentrust_chat_noscript')) {
            return ['error' => __('Session expired. Please reload the page and try again.', 'opentrust')];
        }

        $question = isset($_POST['question']) ? sanitize_textarea_field((string) wp_unslash($_POST['question'])) : '';
        $max_len  = (int) ($settings['ai_max_message_length'] ?? OpenTrust_Chat::DEFAULT_MAX_MESSAGE_LENGTH);
        if ($question === '') {
            return ['error' => __('Please enter a question.', 'opentrust')];
        }
        if (strlen($question) > $max_len) {
            $question = substr($question, 0, $max_len);
        }

        $adapter = OpenTrust_Chat_Provider::for((string) $settings['ai_provider']);
        $api_key = OpenTrust_Chat_Secrets::get((string) $settings['ai_provider']);
        if (!$adapter || $api_key === null) {
            return ['error' => __('AI chat is not configured.', 'opentrust')];
        }

        $locale = (string) determine_locale();
        $corpus = OpenTrust_Chat_Corpus::get_or_build($locale);

        // Build a chat request identical to the REST handler's blocking path.
        // The system prompt + index + tool surface come from the same shared
        // builder so the noscript path is byte-equivalent to the streaming
        // one — we just don't get to stream the response.
        $args = [
            'system'   => OpenTrust_Chat::build_system_prompt($settings, $corpus),
            'corpus'   => $corpus,
            'messages' => [['role' => 'user', 'content' => $question]],
            'tools'    => OpenTrust_Chat::tool_definitions(),
            'model'    => (string) $settings['ai_model'],
            'api_key'  => $api_key,
            'settings' => $settings,
        ];

        $collector = new OpenTrust_Chat_Stream_Collector($corpus['urls'] ?? []);

        $on_chunk = static function (array $event) use ($collector): void {
            $collector->ingest($event); // noscript path is blocking; never forwards
        };

        // Per-request loop detection map — same contract as the REST path.
        $seen_calls = [];
        $tool_resolver = static function (string $name, array $args) use ($corpus, &$seen_calls): array {
            return OpenTrust_Chat::resolve_tool($name, $args, $corpus, $seen_calls);
        };

        try {
            $adapter->stream_chat($args, $on_chunk, $tool_resolver);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        if ($collector->error !== null) {
            return ['error' => $collector->error];
        }

        // Strip inline [[cite:...]] tags from the answer for clean display.
        $answer = preg_replace('/\[\[cite:[a-z0-9_\-]+\]\]/i', '', $collector->answer);

        return [
            'question'  => $question,
            'answer'    => trim((string) $answer),
            'citations' => $collector->citations,
            'refused'   => empty($collector->citations),
        ];
    }

    /**
     * Determine which state the chat page should render.
     */
    private function compute_chat_state(array $settings): string {
        if (empty($settings['ai_enabled']) || empty($settings['ai_provider']) || empty($settings['ai_model'])) {
            return 'unconfigured';
        }
        if (OpenTrust_Chat_Secrets::get((string) $settings['ai_provider']) === null) {
            return 'unconfigured';
        }
        return 'ready';
    }

    // ──────────────────────────────────────────────
    // Main trust center page
    // ──────────────────────────────────────────────

    private function render_trust_center(): void {
        $ot_settings = OpenTrust::get_settings();
        $ot_data     = $this->gather_data($ot_settings);

        header('Content-Type: text/html; charset=utf-8');
        include OPENTRUST_PLUGIN_DIR . 'templates/trust-center.php';
    }

    // ──────────────────────────────────────────────
    // Single policy view
    // ──────────────────────────────────────────────

    private function render_policy_single(): void {
        $slug   = sanitize_title(get_query_var('ot_policy_slug', ''));
        $policy = $this->find_policy_by_slug($slug);

        if (!$policy) {
            $this->render_404();
            return;
        }

        $ot_settings = OpenTrust::get_settings();
        $ot_data     = $this->gather_data($ot_settings);
        $ot_data['current_policy']   = $policy;
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress filter
        $ot_data['policy_content']   = apply_filters('the_content', $policy->post_content);
        $ot_data['policy_version']   = (int) get_post_meta($policy->ID, '_ot_version', true) ?: 1;
        $ot_data['policy_meta']      = $this->get_policy_meta($policy->ID);
        $ot_data['policy_versions']  = $this->get_policy_versions($policy);
        $ot_data['is_pending']       = $this->is_future_dated($policy);
        $ot_data['view']             = 'policy_single';

        header('Content-Type: text/html; charset=utf-8');
        include OPENTRUST_PLUGIN_DIR . 'templates/trust-center.php';
    }

    // ──────────────────────────────────────────────
    // Historical policy version
    // ──────────────────────────────────────────────

    private function render_policy_version(): void {
        $slug    = sanitize_title(get_query_var('ot_policy_slug', ''));
        $version = (int) get_query_var('ot_version', '0');
        $policy  = $this->find_policy_by_slug($slug);

        if (!$policy || $version < 1) {
            $this->render_404();
            return;
        }

        // Current version — redirect to canonical.
        $current_version = (int) get_post_meta($policy->ID, '_ot_version', true) ?: 1;
        if ($version === $current_version) {
            $settings = OpenTrust::get_settings();
            $base     = home_url('/' . $settings['endpoint_slug'] . '/policy/' . $policy->post_name . '/');
            wp_safe_redirect($base, 301);
            exit;
        }

        // Find the revision matching this version.
        $revisions = wp_get_post_revisions($policy->ID, ['order' => 'ASC']);
        $target    = null;
        foreach ($revisions as $rev) {
            if ((int) get_post_meta($rev->ID, '_ot_version', true) === $version) {
                $target = $rev;
                break;
            }
        }

        if (!$target) {
            $this->render_404();
            return;
        }

        $ot_settings = OpenTrust::get_settings();
        $ot_data     = $this->gather_data($ot_settings);
        $ot_data['current_policy']   = $policy;
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress filter
        $ot_data['policy_content']   = apply_filters('the_content', $target->post_content);
        $ot_data['policy_version']   = $version;
        $ot_data['policy_meta']      = $this->get_policy_meta($policy->ID);
        $ot_data['policy_versions']  = $this->get_policy_versions($policy);
        $ot_data['is_old_version']   = true;
        $ot_data['view']             = 'policy_single';

        header('Content-Type: text/html; charset=utf-8');
        include OPENTRUST_PLUGIN_DIR . 'templates/trust-center.php';
    }

    // ──────────────────────────────────────────────
    // 404
    // ──────────────────────────────────────────────

    private function render_404(): void {
        status_header(404);
        $settings = OpenTrust::get_settings();
        $hsl      = OpenTrust::hex_to_hsl($settings['accent_color'] ?? '#2563EB');

        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Not Found</title></head>';
        echo '<body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Inter,Roboto,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f8f9fa;color:#374151">';
        echo '<div style="text-align:center"><h1 style="font-size:4rem;margin:0;color:#d1d5db">404</h1>';
        echo '<p style="font-size:1.125rem;margin:1rem 0">' . esc_html__('Page not found.', 'opentrust') . '</p>';
        echo '<a href="' . esc_url(home_url('/' . ($settings['endpoint_slug'] ?? OpenTrust::DEFAULT_ENDPOINT_SLUG) . '/')) . '" style="color:hsl(' . (int) $hsl['h'] . ',' . (int) $hsl['s'] . '%,' . (int) $hsl['l'] . '%);text-decoration:none">' . esc_html__('Back to Trust Center', 'opentrust') . '</a>';
        echo '</div></body></html>';
    }

    // ──────────────────────────────────────────────
    // Data gathering
    // ──────────────────────────────────────────────

    public function gather_data(array $settings): array {
        $hsl  = OpenTrust::hex_to_hsl($settings['accent_color'] ?? '#2563EB');
        $repo = OpenTrust_Repository::instance();

        $data = [
            'settings'        => $settings,
            'hsl'             => $hsl,
            'logo_url'        => '',
            'avatar_url'      => '',
            'base_url'        => home_url('/' . ($settings['endpoint_slug'] ?? OpenTrust::DEFAULT_ENDPOINT_SLUG) . '/'),
            'view'            => 'main',
            'certifications'  => [],
            'policies'        => [],
            'subprocessors'   => [],
            'data_practices'  => [],
            'faqs'            => [],
            'section_updated' => [],
        ];

        // Logo.
        $logo_id = (int) ($settings['logo_id'] ?? 0);
        if ($logo_id) {
            $data['logo_url'] = wp_get_attachment_image_url($logo_id, 'medium') ?: '';
        }

        // AI chat avatar.
        $avatar_id = (int) ($settings['avatar_id'] ?? 0);
        if ($avatar_id) {
            $data['avatar_url'] = wp_get_attachment_image_url($avatar_id, 'thumbnail') ?: '';
        }

        // Section data + last-updated timestamps. Visibility is applied here
        // so a hidden section yields an empty array and the template renders
        // nothing. Repository returns all-published unconditionally; this
        // gate is consumer-layer concern.
        $visible = $settings['sections_visible'] ?? [];
        $section_cpt_map = [
            'certifications' => 'ot_certification',
            'policies'       => 'ot_policy',
            'subprocessors'  => 'ot_subprocessor',
            'data_practices' => 'ot_data_practice',
            'faqs'           => 'ot_faq',
        ];

        if (!empty($visible['certifications'])) {
            $data['certifications'] = $repo->fetch_certifications();
        }
        if (!empty($visible['policies'])) {
            $data['policies'] = $repo->fetch_policies();
        }
        if (!empty($visible['subprocessors'])) {
            $data['subprocessors'] = $repo->fetch_subprocessors();
        }
        if (!empty($visible['data_practices'])) {
            $data['data_practices'] = $repo->fetch_data_practices();
        }
        if (!empty($visible['faqs'])) {
            $data['faqs'] = $repo->fetch_faqs();
        }

        foreach ($section_cpt_map as $section => $cpt) {
            if (!empty($visible[$section])) {
                $data['section_updated'][$section] = $repo->section_last_updated($cpt);
            }
        }

        return $data;
    }

    // ──────────────────────────────────────────────
    // Per-policy view helpers
    // ──────────────────────────────────────────────

    /**
     * Whether a policy's effective date is in the future.
     */
    private function is_future_dated(\WP_Post $policy): bool {
        $effective_date = get_post_meta($policy->ID, '_ot_policy_effective_date', true);
        if (!$effective_date) {
            return false;
        }
        return strtotime($effective_date) > time();
    }

    // ──────────────────────────────────────────────
    // Policy version history
    // ──────────────────────────────────────────────

    /**
     * Build a version history list for a policy, newest first.
     *
     * Returns an array of ['version' => int, 'date' => string, 'url' => string, 'current' => bool].
     */
    private function get_policy_versions(\WP_Post $policy): array {
        $settings        = OpenTrust::get_settings();
        $endpoint        = $settings['endpoint_slug'] ?? OpenTrust::DEFAULT_ENDPOINT_SLUG;
        $current_version = (int) get_post_meta($policy->ID, '_ot_version', true) ?: 1;
        $current_url     = home_url('/' . $endpoint . '/policy/' . $policy->post_name . '/');

        // Start with the current version.
        $date_fmt    = get_option('date_format');
        $current_eff = get_post_meta($policy->ID, '_ot_policy_effective_date', true);
        $versions    = [];
        $versions[]  = [
            'version' => $current_version,
            'date'    => $current_eff
                ? wp_date($date_fmt, strtotime($current_eff))
                : wp_date($date_fmt, strtotime($policy->post_modified)),
            'url'     => $current_url,
            'current' => true,
            'summary' => get_post_meta($policy->ID, '_ot_version_summary', true) ?: '',
        ];

        // Add past versions from revisions.
        $revisions = wp_get_post_revisions($policy->ID, [
            'orderby' => 'ID',
            'order'   => 'DESC',
        ]);

        $seen = [$current_version => true];
        foreach ($revisions as $rev) {
            $rev_version = (int) get_post_meta($rev->ID, '_ot_version', true);
            if (!$rev_version || isset($seen[$rev_version])) {
                continue;
            }
            $seen[$rev_version] = true;

            $rev_eff = get_post_meta($rev->ID, '_ot_policy_effective_date', true);
            $versions[] = [
                'version' => $rev_version,
                'date'    => $rev_eff
                    ? wp_date($date_fmt, strtotime($rev_eff))
                    : wp_date($date_fmt, strtotime($rev->post_modified)),
                'url'     => home_url('/' . $endpoint . '/policy/' . $policy->post_name . '/version/' . $rev_version . '/'),
                'current' => false,
                'summary' => get_post_meta($rev->ID, '_ot_version_summary', true) ?: '',
            ];
        }

        // Sort descending by version number.
        usort($versions, fn($a, $b) => $b['version'] <=> $a['version']);

        return $versions;
    }

    // ──────────────────────────────────────────────
    // Section timestamps
    // ──────────────────────────────────────────────

    /**
     * Render an "Updated X ago" pill. Returns HTML string.
     */
    public static function updated_pill(string $section_key, array $data): string {
        $timestamp = $data['section_updated'][$section_key] ?? '';
        if (!$timestamp) {
            return '';
        }

        $diff = time() - (int) $timestamp;

        if ($diff < 60) {
            $text = __('Updated just now', 'opentrust');
        } elseif ($diff < 3600) {
            $minutes = (int) floor($diff / 60);
            $text = sprintf(
                /* translators: %d: number of minutes since last update */
                _n('Updated %d minute ago', 'Updated %d minutes ago', $minutes, 'opentrust'),
                $minutes
            );
        } elseif ($diff < 86400) {
            $hours = (int) floor($diff / 3600);
            $text = sprintf(
                /* translators: %d: number of hours since last update */
                _n('Updated %d hour ago', 'Updated %d hours ago', $hours, 'opentrust'),
                $hours
            );
        } elseif ($diff < 2592000) {
            $days = (int) floor($diff / 86400);
            $text = sprintf(
                /* translators: %d: number of days since last update */
                _n('Updated %d day ago', 'Updated %d days ago', $days, 'opentrust'),
                $days
            );
        } else {
            $text = sprintf(
                /* translators: %s = formatted date */
                __('Updated %s', 'opentrust'),
                wp_date('M j, Y', (int) $timestamp)
            );
        }

        return '<span class="ot-updated-pill">'
            . '<svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>'
            . '<span>' . esc_html($text) . '</span>'
            . '</span>';
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function find_policy_by_slug(string $slug): ?\WP_Post {
        if (!$slug) {
            return null;
        }

        $posts = get_posts([
            'post_type'      => 'ot_policy',
            'name'           => $slug,
            'posts_per_page' => 1,
            'post_status'    => 'publish',
        ]);

        return $posts[0] ?? null;
    }

    private function get_policy_meta(int $post_id): array {
        $repo = OpenTrust_Repository::instance();
        return [
            'ref_id'         => (string) (get_post_meta($post_id, '_ot_policy_ref_id', true) ?: ''),
            'category'       => get_post_meta($post_id, '_ot_policy_category', true) ?: 'other',
            'citations'      => $repo->normalize_citations(get_post_meta($post_id, '_ot_policy_citations', true)),
            'effective_date' => get_post_meta($post_id, '_ot_policy_effective_date', true) ?: '',
            'review_date'    => get_post_meta($post_id, '_ot_policy_review_date', true) ?: '',
            'attachment'     => $repo->resolve_policy_attachment($post_id),
        ];
    }

    /**
     * Category labels for display.
     */
    public static function policy_category_labels(): array {
        return [
            'security'    => __('Security', 'opentrust'),
            'privacy'     => __('Privacy', 'opentrust'),
            'compliance'  => __('Compliance', 'opentrust'),
            'operational' => __('Operational', 'opentrust'),
            'other'       => __('General', 'opentrust'),
        ];
    }

    /**
     * Pill labels for third-party-audited certifications. "Certified"
     * replaces the older "Active" wording so the tier distinction lives
     * in the label text itself rather than in a separate visual marker.
     */
    public static function cert_status_labels(): array {
        return [
            'active'      => __('Certified', 'opentrust'),
            'in_progress' => __('In audit', 'opentrust'),
            'expired'     => __('Expired', 'opentrust'),
        ];
    }

    /**
     * Pill labels for self-attested frameworks.
     */
    public static function cert_aligned_status_labels(): array {
        return [
            'active'      => __('Compliant', 'opentrust'),
            'in_progress' => __('Working toward', 'opentrust'),
            'expired'     => __('Lapsed', 'opentrust'),
        ];
    }

    public static function legal_basis_labels(): array {
        return [
            'consent'             => __('Consent', 'opentrust'),
            'contract'            => __('Contractual Necessity', 'opentrust'),
            'legitimate_interest' => __('Legitimate Interest', 'opentrust'),
            'legal_obligation'    => __('Legal Obligation', 'opentrust'),
            'vital_interest'      => __('Vital Interest', 'opentrust'),
            'public_interest'     => __('Public Interest', 'opentrust'),
        ];
    }

}
