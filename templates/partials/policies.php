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

// Show the ID column only when at least one policy has a Policy ID set.
$ot_has_ref_col = false;
foreach ($ot_policies as $ot_p) {
    if (!empty($ot_p['ref_id'])) { $ot_has_ref_col = true; break; }
}
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
                    <?php if ($ot_has_ref_col): ?>
                    <col style="width:10%">
                    <col style="width:32%">
                    <col style="width:16%">
                    <col style="width:10%">
                    <col style="width:22%">
                    <col style="width:10%">
                    <?php else: ?>
                    <col style="width:40%">
                    <col style="width:18%">
                    <col style="width:10%">
                    <col style="width:22%">
                    <col style="width:10%">
                    <?php endif; ?>
                </colgroup>
                <thead>
                    <tr>
                        <?php if ($ot_has_ref_col): ?>
                        <th data-ot-sort="ref" scope="col"><?php esc_html_e('ID', 'opentrust'); ?></th>
                        <?php endif; ?>
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
                        $ot_attachment     = $ot_policy['attachment'] ?? null;
                        $ot_citations      = $ot_policy['citations'] ?? [];
                        $ot_ref_id         = (string) ($ot_policy['ref_id'] ?? '');
                        $ot_date_formatted = $ot_policy['effective_date']
                            ? wp_date(get_option('date_format'), strtotime($ot_policy['effective_date']))
                            : wp_date(get_option('date_format'), strtotime($ot_policy['last_modified']));
                    ?>
                    <tr>
                        <?php if ($ot_has_ref_col): ?>
                        <td>
                            <?php if ($ot_ref_id !== ''): ?>
                                <code class="ot-policy-ref"><?php echo esc_html($ot_ref_id); ?></code>
                            <?php else: ?>
                                <span class="ot-policy-ref ot-policy-ref--empty" aria-hidden="true">—</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td>
                            <a href="<?php echo esc_url($ot_policy_url); ?>" class="ot-policy-link">
                                <svg class="ot-policy-link__icon" viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13zM6 20V4h6v6h6v10H6z"/></svg>
                                <strong><?php echo esc_html($ot_policy['title']); ?></strong>
                            </a>
                            <?php if (!empty($ot_citations)): ?>
                            <ul class="ot-policy-citations" role="list" aria-label="<?php esc_attr_e('Framework citations', 'opentrust'); ?>">
                                <?php foreach ($ot_citations as $ot_citation): ?>
                                <li class="ot-policy-citation"><?php echo esc_html($ot_citation); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($ot_category_label); ?></td>
                        <td><?php
                        /* translators: %s: policy version number */
                        printf(esc_html__('v%s', 'opentrust'), esc_html((string) $ot_policy['version'])); ?></td>
                        <td><?php echo esc_html($ot_date_formatted); ?></td>
                        <td class="ot-policy-actions">
                            <?php if ($ot_attachment): ?>
                                <a href="<?php echo esc_url($ot_attachment['url']); ?>" class="ot-policy-actions__pdf" title="<?php
                                if (!empty($ot_attachment['size_human'])) {
                                    /* translators: %s: human-readable file size */
                                    echo esc_attr(sprintf(__('Download PDF (%s)', 'opentrust'), $ot_attachment['size_human']));
                                } else {
                                    esc_attr_e('Download PDF', 'opentrust');
                                }
                                ?>" download>
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
