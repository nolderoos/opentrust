<?php
/**
 * Main trust center template.
 *
 * Outputs a complete, standalone HTML document.
 * Variables available: $ot_data (array with settings, hsl, logo_url, base_url, view, certifications, policies, etc.)
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local scope via include()

$ot_settings = $ot_data['settings'];
$ot_hsl      = $ot_data['hsl'];
$ot_view     = $ot_data['view'] ?? 'main';

$ot_page_title   = esc_html($ot_settings['page_title'] ?: __('Trust Center', 'opentrust'));
$ot_company_name = esc_html($ot_settings['company_name'] ?? '');
$ot_tagline      = esc_html($ot_settings['tagline'] ?: __('Transparency and security you can trust.', 'opentrust'));
$ot_logo_url     = $ot_data['logo_url'] ?? '';
$ot_base_url     = $ot_data['base_url'] ?? '/';

$ot_visible = $ot_settings['sections_visible'] ?? [];

// Count stats for the hero.
$ot_cert_count   = count($ot_data['certifications'] ?? []);
$ot_policy_count = count($ot_data['policies'] ?? []);
$ot_sub_count    = count($ot_data['subprocessors'] ?? []);
$ot_faq_count    = count($ot_data['faqs'] ?? []);

// Contact block is present only when the admin filled in at least one
// field. Matches the "don't render empty sections" rule used elsewhere.
$ot_contact_has_content = (bool) (
    trim((string) ($ot_settings['company_description'] ?? ''))
    || trim((string) ($ot_settings['dpo_name']         ?? ''))
    || trim((string) ($ot_settings['dpo_email']        ?? ''))
    || trim((string) ($ot_settings['security_email']   ?? ''))
    || trim((string) ($ot_settings['contact_form_url'] ?? ''))
    || trim((string) ($ot_settings['contact_address']  ?? ''))
    || trim((string) ($ot_settings['pgp_key_url']      ?? ''))
    || trim((string) ($ot_settings['company_registration'] ?? ''))
    || trim((string) ($ot_settings['vat_number']       ?? ''))
);

// AI chat availability + contrast-safe text color against the user's accent.
$ot_ai_enabled = !empty($ot_settings['ai_enabled'])
    && !empty($ot_settings['ai_provider'])
    && !empty($ot_settings['ai_model'])
    && class_exists('OpenTrust_Chat_Secrets')
    && OpenTrust_Chat_Secrets::get((string) $ot_settings['ai_provider']) !== null;

$ot_accent_contrast = ((int) $ot_hsl['l'] < 55) ? '#ffffff' : '#111827';

// Lightness used anywhere the accent sits on a white/light background
// (buttons, links, borders). Darkened in HSL space just far enough to hit
// 4.5:1 WCAG contrast against white; identical to the raw L when the user's
// pick is already readable. The hero/nav keep the raw L separately.
//
// When `accent_force_exact` is set, the user has explicitly opted out of the
// WCAG adjustment — keep their exact colour everywhere, contrast be damned.
$ot_accent_l_safe = !empty($ot_settings['accent_force_exact'])
    ? (int) $ot_hsl['l']
    : OpenTrust::accent_safe_lightness((string) ($ot_settings['accent_color'] ?? '#2563EB'));
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php
        if ($ot_view === 'policy_single' && !empty($ot_data['current_policy'])) {
            echo esc_html($ot_data['current_policy']->post_title) . ' — ';
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped via esc_html() in render class
        echo $ot_page_title;
        if ($ot_company_name) {
            echo ' | ' . $ot_company_name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped via esc_html() in render class
        }
    ?></title>
    <meta name="description" content="<?php echo esc_attr($ot_tagline); ?>">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?php echo esc_url($ot_base_url); ?>">
    <style>
        :root {
            --ot-accent-h: <?php echo (int) $ot_hsl['h']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integer cast ?>;
            --ot-accent-s: <?php echo (int) $ot_hsl['s']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integer cast ?>%;
            --ot-accent-l: <?php echo (int) $ot_hsl['l']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integer cast ?>%;
            --ot-accent-l-safe: <?php echo (int) $ot_accent_l_safe; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integer cast ?>%;
            --ot-accent-contrast: <?php echo esc_attr($ot_accent_contrast); ?>;
        }
        <?php
        // Inline the frontend CSS for zero-request rendering.
        $ot_css_path = OPENTRUST_PLUGIN_DIR . 'assets/css/frontend.css';
        if (file_exists($ot_css_path)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo file_get_contents($ot_css_path);
        }
        ?>
    </style>
</head>
<body class="ot-body">

    <a class="ot-skip-link" href="#ot-main"><?php esc_html_e('Skip to content', 'opentrust'); ?></a>

    <?php
    if ($ot_view === 'main') {
        // ── Navigation (above hero) ──
        $ot_nav_items = [];
        if (!empty($ot_visible['policies']) && $ot_policy_count)       $ot_nav_items['policies']       = __('Policies', 'opentrust');
        if (!empty($ot_visible['certifications']) && $ot_cert_count)  $ot_nav_items['certifications'] = __('Certifications', 'opentrust');
        if (!empty($ot_visible['subprocessors']) && $ot_sub_count)     $ot_nav_items['subprocessors']   = __('Subprocessors', 'opentrust');
        if (!empty($ot_visible['data_practices']) && count($ot_data['data_practices'] ?? [])) $ot_nav_items['data-practices'] = __('Data Practices', 'opentrust');
        if (!empty($ot_visible['contact']) && $ot_contact_has_content) $ot_nav_items['contact']        = __('Contact', 'opentrust');
        if (!empty($ot_visible['faqs']) && $ot_faq_count)              $ot_nav_items['faqs']           = __('FAQ', 'opentrust');
        ?>
            <nav class="ot-nav" aria-label="<?php esc_attr_e('Trust center navigation', 'opentrust'); ?>">
                <div class="ot-container ot-nav__inner">
                    <a href="<?php echo esc_url($ot_base_url); ?>" class="ot-nav__brand">
                        <?php if ($ot_logo_url): ?>
                            <img class="ot-nav__brand-logo"
                                 src="<?php echo esc_url($ot_logo_url); ?>"
                                 alt="<?php echo esc_attr($ot_company_name); ?>">
                        <?php else: ?>
                            <span class="ot-nav__brand-name"><?php echo esc_html($ot_company_name ?: get_bloginfo('name')); ?></span>
                        <?php endif; ?>
                    </a>
                    <?php if (count($ot_nav_items) > 1): ?>
                        <div class="ot-nav__links">
                            <?php foreach ($ot_nav_items as $ot_id => $ot_label): ?>
                                <a href="#ot-<?php echo esc_attr($ot_id); ?>" class="ot-nav__link" data-ot-nav>
                                    <?php echo esc_html($ot_label); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($ot_ai_enabled): ?>
                        <div class="ot-nav__cta">
                            <a href="<?php echo esc_url(trailingslashit($ot_base_url) . 'ask/'); ?>" class="ot-nav__ask">
                                <svg class="ot-nav__ask-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/>
                                    <path d="M20 3v4"/>
                                    <path d="M22 5h-4"/>
                                    <path d="M4 17v2"/>
                                    <path d="M5 18H3"/>
                                </svg>
                                <span class="ot-nav__ask-label"><?php esc_html_e('Ask AI', 'opentrust'); ?></span>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </nav>
        <?php
        // ── Hero ──
        include OPENTRUST_PLUGIN_DIR . 'templates/partials/hero.php';
        ?>

        <main id="ot-main" class="ot-main">
                <?php
                // ── Policies ──
                if (!empty($ot_visible['policies']) && $ot_policy_count) {
                    include OPENTRUST_PLUGIN_DIR . 'templates/partials/policies.php';
                }

                // ── Certifications ──
                if (!empty($ot_visible['certifications']) && $ot_cert_count) {
                    include OPENTRUST_PLUGIN_DIR . 'templates/partials/certifications.php';
                }

                // ── Subprocessors ──
                if (!empty($ot_visible['subprocessors']) && $ot_sub_count) {
                    include OPENTRUST_PLUGIN_DIR . 'templates/partials/subprocessors.php';
                }

                // ── Data Practices ──
                if (!empty($ot_visible['data_practices']) && count($ot_data['data_practices'] ?? [])) {
                    include OPENTRUST_PLUGIN_DIR . 'templates/partials/data-practices.php';
                }

                // ── Get in touch ──
                if (!empty($ot_visible['contact']) && $ot_contact_has_content) {
                    include OPENTRUST_PLUGIN_DIR . 'templates/partials/contact.php';
                }

                // ── FAQ ──
                if (!empty($ot_visible['faqs']) && $ot_faq_count) {
                    include OPENTRUST_PLUGIN_DIR . 'templates/partials/faq.php';
                }

                // Show empty state if nothing is published yet.
                if (!$ot_cert_count && !$ot_policy_count && !$ot_sub_count && empty($ot_data['data_practices']) && !$ot_faq_count && !$ot_contact_has_content):
                ?>
                    <div class="ot-empty">
                        <div class="ot-empty__icon">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/>
                            </svg>
                        </div>
                        <p class="ot-empty__text"><?php esc_html_e('Trust center content is being prepared. Check back soon.', 'opentrust'); ?></p>
                    </div>
                <?php endif; ?>
        </main>

    <?php } elseif ($ot_view === 'policy_single') {
        // ── Single Policy View ──
        include OPENTRUST_PLUGIN_DIR . 'templates/partials/policy-single.php';
    } ?>

    <footer class="ot-footer">
        <div class="ot-container">
            <p>
                <?php
                printf(
                    /* translators: %s: company name */
                    esc_html__('© %1$s %2$s. All rights reserved.', 'opentrust'),
                    esc_html(wp_date('Y')),
                    esc_html($ot_company_name ?: get_bloginfo('name'))
                );
                ?>
                &nbsp;·&nbsp;
                <a href="https://github.com/opentrust/opentrust" target="_blank" rel="noopener">
                    <?php esc_html_e('Powered by OpenTrust', 'opentrust'); ?>
                </a>
            </p>
        </div>
    </footer>

    <script>
        <?php
        $ot_js_path = OPENTRUST_PLUGIN_DIR . 'assets/js/frontend.js';
        if (file_exists($ot_js_path)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo file_get_contents($ot_js_path);
        }
        ?>
    </script>

</body>
</html>
