<?php
/**
 * Single policy view partial.
 *
 * Variables available from parent: $ot_data, $ot_settings, $ot_base_url, $ot_logo_url, $ot_company_name
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local scope via include()

$ot_policy          = $ot_data['current_policy'];
$ot_content         = $ot_data['policy_content'];
$ot_version         = $ot_data['policy_version'];
$ot_meta            = $ot_data['policy_meta'];
$ot_is_old_version  = $ot_data['is_old_version'] ?? false;
$ot_is_pending      = $ot_data['is_pending'] ?? false;

$ot_versions        = $ot_data['policy_versions'] ?? [];
$ot_category_labels = OpenTrust_Render::policy_category_labels();
$ot_category_label  = $ot_category_labels[$ot_meta['category']] ?? $ot_meta['category'];
$ot_ref_id          = (string) ($ot_meta['ref_id'] ?? '');
$ot_citations       = $ot_meta['citations'] ?? [];
$ot_attachment      = $ot_meta['attachment'] ?? null;

$ot_effective_date = $ot_meta['effective_date']
    ? wp_date(get_option('date_format'), strtotime($ot_meta['effective_date']))
    : '';
$ot_modified_date = wp_date(get_option('date_format'), strtotime($ot_policy->post_modified));

$ot_current_url = trailingslashit($ot_base_url) . 'policy/' . $ot_policy->post_name . '/';
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
    </div>
</nav>

<main id="ot-main" class="ot-policy-single">
    <div class="ot-container ot-container--narrow">

        <a href="<?php echo esc_url($ot_base_url); ?>" class="ot-policy-single__back">
            &larr; <?php esc_html_e('Back to Trust Center', 'opentrust'); ?>
        </a>

        <?php if ($ot_is_old_version): ?>
        <div class="ot-version-banner">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><path d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1zm0 12.5A5.5 5.5 0 1 1 8 2.5a5.5 5.5 0 0 1 0 11zM7.25 5h1.5v4h-1.5V5zm0 5h1.5v1.5h-1.5V10z"/></svg>
            <?php
            printf(
                /* translators: %1$s: version number, %2$s: link to current version */
                esc_html__('You are viewing version %1$s. %2$s', 'opentrust'),
                esc_html((string) $ot_version),
                '<a href="' . esc_url($ot_current_url) . '">' . esc_html__('View current version', 'opentrust') . '</a>'
            );
            ?>
        </div>
        <?php elseif ($ot_is_pending): ?>
        <div class="ot-version-banner ot-version-banner--pending">
            <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg>
            <?php
            printf(
                /* translators: %1$s: effective date, %2$s: link to previous version */
                esc_html__('This version takes effect on %1$s. %2$s', 'opentrust'),
                esc_html($ot_effective_date),
                $ot_version > 1
                    ? '<a href="' . esc_url($ot_current_url . 'version/' . ($ot_version - 1) . '/') . '">' . esc_html__('View current version', 'opentrust') . '</a>'
                    : ''
            );
            ?>
        </div>
        <?php endif; ?>

        <div class="ot-policy-single__header">
            <div class="ot-policy-single__eyebrow">
                <?php if ($ot_ref_id !== ''): ?>
                <span class="ot-policy-single__ref"><?php echo esc_html($ot_ref_id); ?></span>
                <?php endif; ?>
                <span class="ot-pill ot-pill--category"><?php echo esc_html($ot_category_label); ?></span>
                <span class="ot-policy-single__version">
                    <?php
                    /* translators: %s: policy version number */
                    printf(esc_html__('v%s', 'opentrust'), esc_html((string) $ot_version)); ?>
                </span>
            </div>

            <h1 class="ot-policy-single__title"><?php echo esc_html($ot_policy->post_title); ?></h1>

            <div class="ot-policy-single__meta">
                <?php if ($ot_effective_date): ?>
                <span class="ot-policy-single__meta-item">
                    <?php
                    /* translators: %s: policy effective date */
                    printf(esc_html__('Effective %s', 'opentrust'), esc_html($ot_effective_date)); ?>
                </span>
                <?php endif; ?>
                <span class="ot-policy-single__meta-item">
                    <?php
                    /* translators: %s: policy last updated date */
                    printf(esc_html__('Updated %s', 'opentrust'), esc_html($ot_modified_date)); ?>
                </span>
            </div>

            <?php if (!empty($ot_citations)): ?>
            <ul class="ot-policy-single__citations" role="list" aria-label="<?php esc_attr_e('Framework citations', 'opentrust'); ?>">
                <?php foreach ($ot_citations as $ot_citation): ?>
                <li class="ot-policy-single__citation"><?php echo esc_html($ot_citation); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <div class="ot-policy-single__actions">
                <?php if ($ot_attachment): ?>
                <a href="<?php echo esc_url($ot_attachment['url']); ?>" class="ot-btn ot-btn--primary" download>
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 12l-4-4h2.5V3h3v5H12L8 12zm-6 2h12v1.5H2V14z"/></svg>
                    <?php
                    if (!empty($ot_attachment['size_human'])) {
                        /* translators: %s: human-readable file size */
                        printf(esc_html__('Download PDF (%s)', 'opentrust'), esc_html($ot_attachment['size_human']));
                    } else {
                        esc_html_e('Download PDF', 'opentrust');
                    }
                    ?>
                </a>
                <?php endif; ?>
                <button type="button" class="ot-btn ot-btn--outline" onclick="window.print()">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M13 5H3a2 2 0 0 0-2 2v4h3v3h8v-3h3V7a2 2 0 0 0-2-2zm-1 8H4v-3h8v3zm1-5a1 1 0 1 1 0-2 1 1 0 0 1 0 2zM4 1h8v3H4V1z"/></svg>
                    <?php esc_html_e('Print', 'opentrust'); ?>
                </button>
            </div>
        </div>

        <?php if (count($ot_versions) > 1): ?>
        <div class="ot-version-history-public">
            <button class="ot-version-history-toggle" data-ot-version-toggle aria-expanded="false">
                <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg>
                <?php
                /* translators: %d: number of policy versions */
                printf(esc_html__('Version history (%d)', 'opentrust'), count($ot_versions)); ?>
                <svg class="ot-version-history-chevron" width="14" height="14" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            </button>
            <ul class="ot-version-list" hidden>
                <?php foreach ($ot_versions as $ot_v):
                    $ot_is_active = $ot_v['version'] === $ot_version;
                    $ot_tag       = $ot_is_active ? 'span' : 'a';
                    $ot_href      = $ot_is_active ? '' : ' href="' . esc_url($ot_v['url']) . '"';
                    $ot_classes   = 'ot-version-list__link' . ($ot_is_active ? ' ot-version-list__link--current' : '');
                ?>
                <li class="ot-version-list__item<?php echo esc_attr( $ot_is_active ? ' ot-version-list__item--active' : '' ); ?>">
                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $ot_tag is hardcoded 'span'/'a', $ot_href uses esc_url() ?>
                    <<?php echo $ot_tag . $ot_href; ?> class="<?php echo esc_attr( $ot_classes ); ?>">
                        <span class="ot-version-list__number"><?php
                        /* translators: %d: version number */
                        printf(esc_html__('v%d', 'opentrust'), intval($ot_v['version'])); ?></span>
                        <span class="ot-version-list__sep">&middot;</span>
                        <span class="ot-version-list__date"><?php echo esc_html($ot_v['date']); ?></span>
                        <?php if (!empty($ot_v['summary'])): ?>
                            <span class="ot-version-list__sep">&middot;</span>
                            <span class="ot-version-list__summary"><?php echo esc_html($ot_v['summary']); ?></span>
                        <?php endif; ?>
                        <?php if ($ot_v['current']): ?>
                            <span class="ot-version-list__badge"><?php esc_html_e('Current', 'opentrust'); ?></span>
                        <?php endif; ?>
                    </<?php echo $ot_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded 'span' or 'a' ?>>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <article class="ot-policy-content">
            <?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $ot_content;
            ?>
        </article>
    </div>
</main>
