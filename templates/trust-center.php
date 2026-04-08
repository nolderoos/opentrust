<?php
/**
 * Main trust center template.
 *
 * Outputs a complete, standalone HTML document.
 * Variables available: $data (array with settings, hsl, logo_url, base_url, view, certifications, policies, etc.)
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$settings = $data['settings'];
$hsl      = $data['hsl'];
$view     = $data['view'] ?? 'main';
$is_pdf   = $view === 'policy_pdf';

$page_title   = esc_html($settings['page_title'] ?? 'Trust Center');
$company_name = esc_html($settings['company_name'] ?? '');
$tagline      = esc_html($settings['tagline'] ?? '');
$logo_url     = $data['logo_url'] ?? '';
$base_url     = $data['base_url'] ?? '/';

$visible = $settings['sections_visible'] ?? [];

// Count stats for the hero.
$cert_count   = count($data['certifications'] ?? []);
$policy_count = count($data['policies'] ?? []);
$sub_count    = count($data['subprocessors'] ?? []);
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php
        if ($view === 'policy_single' && !empty($data['current_policy'])) {
            echo esc_html($data['current_policy']->post_title) . ' — ';
        }
        echo $page_title;
        if ($company_name) {
            echo ' | ' . $company_name;
        }
    ?></title>
    <meta name="description" content="<?php echo esc_attr($tagline); ?>">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?php echo esc_url($base_url); ?>">
    <style>
        :root {
            --ot-accent-h: <?php echo (int) $hsl['h']; ?>;
            --ot-accent-s: <?php echo (int) $hsl['s']; ?>%;
            --ot-accent-l: <?php echo (int) $hsl['l']; ?>%;
        }
        <?php
        // Inline the frontend CSS for zero-request rendering.
        $css_path = OPENTRUST_PLUGIN_DIR . 'assets/css/frontend.css';
        if (file_exists($css_path)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo file_get_contents($css_path);
        }
        ?>
    </style>
</head>
<body class="ot-body<?php echo $is_pdf ? ' ot-body--pdf' : ''; ?>">

    <a class="ot-skip-link" href="#ot-main"><?php esc_html_e('Skip to content', 'opentrust'); ?></a>

    <?php
    if ($view === 'main') {
        // ── Hero ──
        include OPENTRUST_PLUGIN_DIR . 'templates/partials/hero.php';

        // ── Navigation ──
        $nav_items = [];
        if (!empty($visible['certifications']) && $cert_count)  $nav_items['certifications'] = __('Certifications', 'opentrust');
        if (!empty($visible['policies']) && $policy_count)       $nav_items['policies']       = __('Policies', 'opentrust');
        if (!empty($visible['subprocessors']) && $sub_count)     $nav_items['subprocessors']   = __('Subprocessors', 'opentrust');
        if (!empty($visible['data_practices']) && count($data['data_practices'] ?? [])) $nav_items['data-practices'] = __('Data Practices', 'opentrust');

        if (count($nav_items) > 1): ?>
            <nav class="ot-nav" aria-label="<?php esc_attr_e('Trust center sections', 'opentrust'); ?>">
                <div class="ot-container ot-nav__inner">
                    <?php foreach ($nav_items as $id => $label): ?>
                        <a href="#ot-<?php echo esc_attr($id); ?>" class="ot-nav__link" data-ot-nav>
                            <?php echo esc_html($label); ?>
                        </a>
                    <?php endforeach; ?>
                    <div class="ot-nav__search">
                        <input type="search"
                               class="ot-search-input"
                               id="ot-search"
                               placeholder="<?php esc_attr_e('Search...', 'opentrust'); ?>"
                               aria-label="<?php esc_attr_e('Search trust center', 'opentrust'); ?>">
                    </div>
                </div>
            </nav>
        <?php endif; ?>

        <main id="ot-main" class="ot-main">
            <div class="ot-container">
                <?php
                // ── Certifications ──
                if (!empty($visible['certifications']) && $cert_count) {
                    include OPENTRUST_PLUGIN_DIR . 'templates/partials/certifications.php';
                }

                // ── Policies ──
                if (!empty($visible['policies']) && $policy_count) {
                    include OPENTRUST_PLUGIN_DIR . 'templates/partials/policies.php';
                }

                // ── Subprocessors ──
                if (!empty($visible['subprocessors']) && $sub_count) {
                    include OPENTRUST_PLUGIN_DIR . 'templates/partials/subprocessors.php';
                }

                // ── Data Practices ──
                if (!empty($visible['data_practices']) && count($data['data_practices'] ?? [])) {
                    include OPENTRUST_PLUGIN_DIR . 'templates/partials/data-practices.php';
                }

                // Show empty state if nothing is published yet.
                if (!$cert_count && !$policy_count && !$sub_count && empty($data['data_practices'])):
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
            </div>
        </main>

    <?php } elseif ($view === 'policy_single' || $view === 'policy_pdf') {
        // ── Single Policy View ──
        include OPENTRUST_PLUGIN_DIR . 'templates/partials/policy-single.php';
    } ?>

    <?php if (!$is_pdf): ?>
    <footer class="ot-footer">
        <div class="ot-container">
            <p>
                <?php
                printf(
                    /* translators: %s: company name */
                    esc_html__('© %1$s %2$s. All rights reserved.', 'opentrust'),
                    esc_html(wp_date('Y')),
                    esc_html($company_name ?: get_bloginfo('name'))
                );
                ?>
                &nbsp;·&nbsp;
                <a href="https://github.com/opentrust/opentrust" target="_blank" rel="noopener">
                    <?php esc_html_e('Powered by OpenTrust', 'opentrust'); ?>
                </a>
            </p>
        </div>
    </footer>
    <?php endif; ?>

    <script>
        <?php
        $js_path = OPENTRUST_PLUGIN_DIR . 'assets/js/frontend.js';
        if (file_exists($js_path)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo file_get_contents($js_path);
        }
        ?>
    </script>

</body>
</html>
