<?php
/**
 * Policies section partial.
 *
 * Variables available from parent: $ot_data, $ot_base_url
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local scope via include()

$ot_policies        = $ot_data['policies'] ?? [];
$ot_category_labels = OpenTrust_Render::policy_category_labels();
?>
<section id="ot-policies" class="ot-section">
    <div class="ot-container">
        <div class="ot-section__header">
            <?php echo OpenTrust_Render::updated_pill('policies', $ot_data); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output escaped within method ?>
            <h2 class="ot-section__title"><?php esc_html_e('Security Policies', 'opentrust'); ?></h2>
            <p class="ot-section__description"><?php esc_html_e('Our published security and compliance policies are regularly reviewed and updated.', 'opentrust'); ?></p>
        </div>

        <div class="ot-table-wrapper">
            <table class="ot-table" role="table">
                <colgroup>
                    <col style="width:40%">
                    <col style="width:18%">
                    <col style="width:10%">
                    <col style="width:22%">
                    <col style="width:10%">
                </colgroup>
                <thead>
                    <tr>
                        <th data-ot-sort="name" scope="col"><?php esc_html_e('Policy', 'opentrust'); ?></th>
                        <th data-ot-sort="category" scope="col"><?php esc_html_e('Category', 'opentrust'); ?></th>
                        <th scope="col"><?php esc_html_e('Version', 'opentrust'); ?></th>
                        <th data-ot-sort="date" scope="col"><?php esc_html_e('Last Updated', 'opentrust'); ?></th>
                        <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'opentrust'); ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ot_policies as $ot_policy):
                        $ot_category       = $ot_policy['category'] ?? 'other';
                        $ot_category_label = $ot_category_labels[$ot_category] ?? $ot_category;
                        $ot_policy_url     = trailingslashit($ot_base_url) . 'policy/' . $ot_policy['slug'] . '/';
                        $ot_pdf_url        = trailingslashit($ot_base_url) . 'policy/' . $ot_policy['slug'] . '/pdf/';
                        $ot_date_formatted = $ot_policy['effective_date']
                            ? wp_date(get_option('date_format'), strtotime($ot_policy['effective_date']))
                            : wp_date(get_option('date_format'), strtotime($ot_policy['last_modified']));
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url($ot_policy_url); ?>" class="ot-policy-link">
                                <svg class="ot-policy-link__icon" viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13zM6 20V4h6v6h6v10H6z"/></svg>
                                <strong><?php echo esc_html($ot_policy['title']); ?></strong>
                            </a>
                        </td>
                        <td><?php echo esc_html($ot_category_label); ?></td>
                        <td><?php
                        /* translators: %s: policy version number */
                        printf(esc_html__('v%s', 'opentrust'), esc_html((string) $ot_policy['version'])); ?></td>
                        <td><?php echo esc_html($ot_date_formatted); ?></td>
                        <td class="ot-policy-actions">
                            <?php if ($ot_policy['downloadable']): ?>
                                <a href="<?php echo esc_url($ot_pdf_url); ?>" class="ot-policy-actions__pdf" title="<?php esc_attr_e('Download PDF', 'opentrust'); ?>">
                                    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><path d="M8 12l-4-4h2.5V3h3v5H12L8 12zm-6 2h12v1.5H2V14z"/></svg>
                                </a>
                            <?php endif; ?>
                            <a href="<?php echo esc_url($ot_policy_url); ?>" class="ot-policy-actions__view" aria-label="<?php
                            /* translators: %s: policy title */
                            echo esc_attr(sprintf(__('View %s', 'opentrust'), $ot_policy['title'])); ?>">&rarr;</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
