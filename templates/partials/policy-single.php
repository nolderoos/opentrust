<?php
/**
 * Single policy view partial.
 *
 * Variables available from parent: $data, $settings, $base_url, $logo_url, $company_name
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$policy          = $data['current_policy'];
$content         = $data['policy_content'];
$version         = $data['policy_version'];
$meta            = $data['policy_meta'];
$is_old_version  = $data['is_old_version'] ?? false;
$is_pdf          = ($data['view'] ?? '') === 'policy_pdf';

$category_labels = OpenTrust_Render::policy_category_labels();
$category_label  = $category_labels[$meta['category']] ?? $meta['category'];

$effective_date = $meta['effective_date']
    ? wp_date(get_option('date_format'), strtotime($meta['effective_date']))
    : '';
$modified_date = wp_date(get_option('date_format'), strtotime($policy->post_modified));

$pdf_url     = trailingslashit($base_url) . 'policy/' . $policy->post_name . '/pdf/';
$current_url = trailingslashit($base_url) . 'policy/' . $policy->post_name . '/';
?>

<?php if (!$is_pdf): ?>
<header class="ot-hero" style="padding-block: var(--ot-space-8);">
    <div class="ot-container ot-hero__inner" style="gap: var(--ot-space-2);">
        <?php if ($logo_url): ?>
            <a href="<?php echo esc_url($base_url); ?>">
                <img class="ot-hero__logo" src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($company_name); ?>" style="max-height:40px;">
            </a>
        <?php endif; ?>
    </div>
</header>
<?php endif; ?>

<main id="ot-main" class="ot-policy-single">
    <div class="ot-container ot-container--narrow">

        <?php if (!$is_pdf): ?>
        <a href="<?php echo esc_url($base_url); ?>" class="ot-policy-single__back">
            &larr; <?php esc_html_e('Back to Trust Center', 'opentrust'); ?>
        </a>
        <?php endif; ?>

        <?php if ($is_old_version): ?>
        <div class="ot-version-banner">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><path d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1zm0 12.5A5.5 5.5 0 1 1 8 2.5a5.5 5.5 0 0 1 0 11zM7.25 5h1.5v4h-1.5V5zm0 5h1.5v1.5h-1.5V10z"/></svg>
            <?php
            printf(
                /* translators: %1$s: version number, %2$s: link to current version */
                esc_html__('You are viewing version %1$s. %2$s', 'opentrust'),
                esc_html((string) $version),
                '<a href="' . esc_url($current_url) . '">' . esc_html__('View current version', 'opentrust') . '</a>'
            );
            ?>
        </div>
        <?php endif; ?>

        <div class="ot-policy-single__header">
            <h1 class="ot-policy-single__title"><?php echo esc_html($policy->post_title); ?></h1>

            <div class="ot-policy-single__meta">
                <span class="ot-pill ot-pill--category"><?php echo esc_html($category_label); ?></span>

                <span class="ot-policy-single__meta-item">
                    <?php printf(esc_html__('Version %s', 'opentrust'), esc_html((string) $version)); ?>
                </span>

                <?php if ($effective_date): ?>
                <span class="ot-policy-single__meta-item">
                    <?php printf(esc_html__('Effective: %s', 'opentrust'), esc_html($effective_date)); ?>
                </span>
                <?php endif; ?>

                <span class="ot-policy-single__meta-item">
                    <?php printf(esc_html__('Updated: %s', 'opentrust'), esc_html($modified_date)); ?>
                </span>
            </div>

            <?php if (!$is_pdf && $meta['downloadable']): ?>
            <div class="ot-policy-single__actions">
                <a href="<?php echo esc_url($pdf_url); ?>" class="ot-btn ot-btn--outline" target="_blank">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><path d="M8 12l-4-4h2.5V3h3v5H12L8 12zm-6 2h12v1.5H2V14z"/></svg>
                    <?php esc_html_e('Download PDF', 'opentrust'); ?>
                </a>
                <button class="ot-btn ot-btn--outline" onclick="window.print()">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><path d="M13 5H3a2 2 0 0 0-2 2v4h3v3h8v-3h3V7a2 2 0 0 0-2-2zm-1 8H4v-3h8v3zm1-5a1 1 0 1 1 0-2 1 1 0 0 1 0 2zM4 1h8v3H4V1z"/></svg>
                    <?php esc_html_e('Print', 'opentrust'); ?>
                </button>
            </div>
            <?php endif; ?>
        </div>

        <article class="ot-policy-content">
            <?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $content;
            ?>
        </article>
    </div>
</main>
