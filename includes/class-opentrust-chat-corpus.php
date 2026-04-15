<?php
/**
 * Corpus builder: turns published OpenTrust CPT content into a normalized
 * document array ready to pass to a chat model.
 *
 * - Reads only published posts (drafts and revisions never appear).
 * - Reuses OpenTrust_Render::gather_data() so there are zero new DB queries
 *   in the hot path.
 * - Caches the assembled corpus as a transient that is invalidated on any
 *   relevant CPT save or settings change.
 * - A safety valve disables the chat feature if the corpus exceeds 120K tokens
 *   (estimated).
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OpenTrust_Chat_Corpus {

    public const TRANSIENT_KEY   = 'opentrust_chat_corpus';
    public const TTL             = 12 * HOUR_IN_SECONDS;
    public const MAX_TOKENS      = 120000;

    /**
     * Return the cached corpus, or build and cache it if missing.
     *
     * @return array{
     *     documents: array<int, array{id:string,type:string,url:string,title:string,content:string,metadata:array}>,
     *     urls:      array<int, string>,
     *     est_tokens: int,
     *     over_budget: bool,
     *     built_at:  int
     * }
     */
    public static function get_or_build(): array {
        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_array($cached) && isset($cached['documents'], $cached['urls'])) {
            return $cached;
        }
        $corpus = self::build();
        set_transient(self::TRANSIENT_KEY, $corpus, self::TTL);
        return $corpus;
    }

    /**
     * Build the corpus fresh from the database.
     */
    public static function build(): array {
        $settings = OpenTrust::get_settings();
        $data     = OpenTrust_Render::instance()->gather_data($settings);

        $documents = [];

        // Certifications.
        foreach ($data['certifications'] ?? [] as $cert) {
            $documents[] = self::format_certification($cert, $settings);
        }

        // Policies — load full post content since gather_data only returns excerpts.
        $policy_posts = get_posts([
            'post_type'      => 'ot_policy',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);
        foreach ($policy_posts as $post) {
            $documents[] = self::format_policy($post, $settings);
        }

        // Subprocessors.
        foreach ($data['subprocessors'] ?? [] as $sub) {
            $documents[] = self::format_subprocessor($sub);
        }

        // Data practices.
        foreach ($data['data_practices'] ?? [] as $dp) {
            $documents[] = self::format_data_practice($dp);
        }

        // Contact / Get-in-touch block. Only included when the admin has
        // populated at least one field in the settings.
        $contact_doc = self::format_contact($settings);
        if ($contact_doc !== null) {
            $documents[] = $contact_doc;
        }

        // Build URL whitelist: every canonical URL the model is allowed to cite.
        $urls = [];
        foreach ($documents as $doc) {
            if (!empty($doc['url'])) {
                $urls[] = $doc['url'];
            }
        }
        // Also whitelist the main trust center URL with common anchor fragments.
        // Anchors on the main page are prefixed `ot-` (see trust-center.php nav).
        $base = home_url('/' . ($settings['endpoint_slug'] ?? 'trust-center') . '/');
        $urls[] = $base;
        foreach (['ot-certifications', 'ot-policies', 'ot-subprocessors', 'ot-data-practices', 'ot-contact'] as $anchor) {
            $urls[] = $base . '#' . $anchor;
        }
        $urls = array_values(array_unique($urls));

        // Rough token estimate: 4 chars per token is the conventional English heuristic.
        $total_chars = 0;
        foreach ($documents as $doc) {
            $total_chars += strlen($doc['content']) + strlen($doc['title']) + 64;
        }
        $est_tokens = (int) ceil($total_chars / 4);

        return [
            'documents'   => $documents,
            'urls'        => $urls,
            'est_tokens'  => $est_tokens,
            'over_budget' => $est_tokens > self::MAX_TOKENS,
            'built_at'    => time(),
        ];
    }

    /**
     * URL whitelist for post-stream citation validation.
     * Any cited URL not in this set is stripped before rendering.
     *
     * @return array<int, string>
     */
    public static function url_whitelist(): array {
        $corpus = self::get_or_build();
        return $corpus['urls'] ?? [];
    }

    /**
     * Drop the cached corpus. Next request will rebuild it.
     */
    public static function invalidate(): void {
        delete_transient(self::TRANSIENT_KEY);
    }

    /**
     * Convenience: is the corpus currently over the token budget?
     */
    public static function is_over_budget(): bool {
        $corpus = self::get_or_build();
        return !empty($corpus['over_budget']);
    }

    // ──────────────────────────────────────────────
    // Formatters — one per content type
    // ──────────────────────────────────────────────

    private static function format_policy(\WP_Post $post, array $settings): array {
        $endpoint = $settings['endpoint_slug'] ?? 'trust-center';
        $url      = home_url('/' . $endpoint . '/policy/' . $post->post_name . '/');

        $category_labels = OpenTrust_Render::policy_category_labels();
        $category        = (string) (get_post_meta($post->ID, '_ot_policy_category', true) ?: 'other');
        $category_label  = $category_labels[$category] ?? $category;
        $effective       = (string) get_post_meta($post->ID, '_ot_policy_effective_date', true);
        $version         = (int) (get_post_meta($post->ID, '_ot_version', true) ?: 1);

        // Strip all HTML and collapse whitespace.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress filter
        $rendered = apply_filters('the_content', $post->post_content);
        $plain    = wp_strip_all_tags($rendered);
        $plain    = preg_replace('/\s+/', ' ', trim($plain));
        $plain    = html_entity_decode((string) $plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $header = sprintf(
            "Category: %s\nVersion: %d%s\n\n",
            $category_label,
            $version,
            $effective ? "\nEffective: " . $effective : ''
        );

        return [
            'id'       => 'policy-' . $post->post_name,
            'type'     => 'policy',
            'url'      => $url,
            'title'    => $post->post_title,
            'content'  => $header . $plain,
            'metadata' => [
                'slug'     => $post->post_name,
                'category' => $category,
                'version'  => $version,
                'updated'  => $post->post_modified,
            ],
        ];
    }

    private static function format_certification(array $cert, array $settings): array {
        $endpoint = $settings['endpoint_slug'] ?? 'trust-center';
        $url      = home_url('/' . $endpoint . '/#ot-certifications');

        $status_labels = ($cert['type'] ?? 'certified') === 'compliant'
            ? OpenTrust_Render::cert_aligned_status_labels()
            : OpenTrust_Render::cert_status_labels();
        $status_label  = $status_labels[$cert['status']] ?? $cert['status'];

        $lines = [
            'Title: ' . $cert['title'],
            'Status: ' . $status_label,
        ];
        if (!empty($cert['issuing_body'])) {
            $lines[] = 'Issuing body: ' . $cert['issuing_body'];
        }
        if (!empty($cert['issue_date'])) {
            $lines[] = 'Valid from: ' . $cert['issue_date'];
        }
        if (!empty($cert['expiry_date'])) {
            $lines[] = 'Valid until: ' . $cert['expiry_date'];
        }
        if (!empty($cert['description'])) {
            $lines[] = '';
            $lines[] = 'Description: ' . wp_strip_all_tags((string) $cert['description']);
        }

        return [
            'id'       => 'cert-' . sanitize_title((string) $cert['title']),
            'type'     => 'certification',
            'url'      => $url,
            'title'    => $cert['title'],
            'content'  => implode("\n", $lines),
            'metadata' => [
                'status'      => $cert['status'],
                'issuing_body'=> $cert['issuing_body'] ?? '',
                'expiry_date' => $cert['expiry_date'] ?? '',
            ],
        ];
    }

    private static function format_subprocessor(array $sub): array {
        $endpoint = OpenTrust::get_settings()['endpoint_slug'] ?? 'trust-center';
        $url      = home_url('/' . $endpoint . '/#ot-subprocessors');

        $lines = [
            'Name: ' . $sub['name'],
        ];
        if (!empty($sub['purpose'])) {
            $lines[] = 'Purpose: ' . $sub['purpose'];
        }
        if (!empty($sub['data_processed'])) {
            $lines[] = 'Data processed: ' . $sub['data_processed'];
        }
        if (!empty($sub['country'])) {
            $lines[] = 'Country: ' . $sub['country'];
        }
        if (!empty($sub['website'])) {
            $lines[] = 'Website: ' . $sub['website'];
        }
        $lines[] = 'DPA signed: ' . (!empty($sub['dpa_signed']) ? 'Yes' : 'No');

        return [
            'id'       => 'sub-' . sanitize_title((string) $sub['name']),
            'type'     => 'subprocessor',
            'url'      => $url,
            'title'    => $sub['name'],
            'content'  => implode("\n", $lines),
            'metadata' => [
                'country'    => $sub['country'] ?? '',
                'dpa_signed' => !empty($sub['dpa_signed']),
            ],
        ];
    }

    private static function format_data_practice(array $dp): array {
        $endpoint = OpenTrust::get_settings()['endpoint_slug'] ?? 'trust-center';
        $url      = home_url('/' . $endpoint . '/#ot-data-practices');

        $legal_labels = OpenTrust_Render::legal_basis_labels();

        $lines = [
            'Category: ' . (string) $dp['title'],
        ];
        if (!empty($dp['data_items']) && is_array($dp['data_items'])) {
            $rendered_items = [];
            foreach ($dp['data_items'] as $item) {
                if (is_array($item)) {
                    $name = (string) ($item['name'] ?? '');
                    $desc = (string) ($item['description'] ?? '');
                    if ($name === '' && $desc === '') {
                        continue;
                    }
                    $rendered_items[] = $desc !== '' ? "{$name} ({$desc})" : $name;
                } else {
                    $rendered_items[] = (string) $item;
                }
            }
            if (!empty($rendered_items)) {
                $lines[] = 'Data items: ' . implode(', ', $rendered_items);
            }
        }
        if (!empty($dp['purpose'])) {
            $lines[] = 'Purpose: ' . (string) $dp['purpose'];
        }
        if (!empty($dp['legal_basis'])) {
            $label = $legal_labels[$dp['legal_basis']] ?? $dp['legal_basis'];
            $lines[] = 'Legal basis: ' . (string) $label;
        }
        if (!empty($dp['retention_period'])) {
            $lines[] = 'Retention: ' . (string) $dp['retention_period'];
        }
        if (!empty($dp['shared_with']) && is_array($dp['shared_with'])) {
            $rendered_shared = [];
            foreach ($dp['shared_with'] as $item) {
                if (is_array($item)) {
                    $name    = (string) ($item['name']    ?? '');
                    $purpose = (string) ($item['purpose'] ?? '');
                    if ($name === '' && $purpose === '') {
                        continue;
                    }
                    $rendered_shared[] = $purpose !== '' ? "{$name} ({$purpose})" : $name;
                } else {
                    $rendered_shared[] = (string) $item;
                }
            }
            if (!empty($rendered_shared)) {
                $lines[] = 'Shared with: ' . implode(', ', $rendered_shared);
            }
        }

        // Property flags. Always emitted (Yes/No) so the model can answer
        // direct procurement questions like "Do you sell customer data?" with
        // a cited factual answer. These flags are authoritative: the admin
        // has explicitly saved them, so "unchecked" means an explicit No.
        $yn = static fn(mixed $v): string => !empty($v) ? 'Yes' : 'No';
        $lines[] = 'Data collected: '              . $yn($dp['prop_collected'] ?? false);
        $lines[] = 'Data stored: '                 . $yn($dp['prop_stored']    ?? false);
        $lines[] = 'Shared with third parties: '   . $yn($dp['prop_shared']    ?? false);
        $lines[] = 'Sold to third parties: '       . $yn($dp['prop_sold']      ?? false);
        $lines[] = 'Encrypted: '                   . $yn($dp['prop_encrypted'] ?? false);

        return [
            'id'       => 'dp-' . sanitize_title((string) $dp['title']),
            'type'     => 'data_practice',
            'url'      => $url,
            'title'    => $dp['title'],
            'content'  => implode("\n", $lines),
            'metadata' => [
                'legal_basis' => $dp['legal_basis'] ?? '',
                'sold'        => !empty($dp['prop_sold']),
                'shared'      => !empty($dp['prop_shared']),
                'encrypted'   => !empty($dp['prop_encrypted']),
            ],
        ];
    }

    /**
     * Build a single synthetic document from the Get-in-touch settings block.
     *
     * Returns null if the admin has not populated any contact field, so the
     * chat corpus doesn't carry an empty "Contact Information" card that the
     * model would cite as if it were real data. When populated, the document
     * reads as a flat list of "Label: value" lines so the model can cite any
     * specific contact point (DPO email, security email, contact form URL,
     * etc.) instead of falling back to the single ai_contact_url.
     */
    private static function format_contact(array $settings): ?array {
        $fields = [
            'company_description'  => 'About',
            'dpo_name'             => 'Data Protection Officer',
            'dpo_email'            => 'DPO email',
            'security_email'       => 'Security contact email',
            'contact_form_url'     => 'Contact form',
            'contact_address'      => 'Mailing address',
            'pgp_key_url'          => 'PGP public key',
            'company_registration' => 'Company registration number',
            'vat_number'           => 'VAT / Tax ID',
        ];

        $lines = [];
        foreach ($fields as $key => $label) {
            $value = trim((string) ($settings[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            // Textarea fields (description, address) may contain newlines.
            // Collapse all whitespace to single spaces so each field renders
            // as one clean "Label: value" line for the model to quote.
            $value = (string) preg_replace('/\s+/', ' ', $value);
            $lines[] = $label . ': ' . $value;
        }

        if (empty($lines)) {
            return null;
        }

        $endpoint = $settings['endpoint_slug'] ?? 'trust-center';
        $url      = home_url('/' . $endpoint . '/#ot-contact');

        return [
            'id'       => 'contact',
            'type'     => 'contact',
            'url'      => $url,
            'title'    => 'Contact Information',
            'content'  => implode("\n", $lines),
            'metadata' => [
                'has_dpo'      => !empty($settings['dpo_email']),
                'has_security' => !empty($settings['security_email']),
                'has_form'     => !empty($settings['contact_form_url']),
            ],
        ];
    }
}
