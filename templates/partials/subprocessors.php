<?php
/**
 * Subprocessors section partial.
 *
 * Variables available from parent: $ot_data
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local scope via include()

$ot_subprocessors = $ot_data['subprocessors'] ?? [];
?>
<section id="ot-subprocessors" class="ot-section">
    <div class="ot-container">
        <div class="ot-section__header">
            <?php echo OpenTrust_Render::updated_pill('subprocessors', $ot_data); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output escaped within method ?>
            <h2 class="ot-section__title"><?php esc_html_e('Subprocessors', 'opentrust'); ?></h2>
            <p class="ot-section__description"><?php esc_html_e('Third-party services that process data on our behalf, along with their purposes and data handling agreements.', 'opentrust'); ?></p>
        </div>

        <div class="ot-table-wrapper">
            <table class="ot-table" role="table">
                <colgroup>
                    <col style="width:14%">
                    <col style="width:25%">
                    <col style="width:25%">
                    <col style="width:10%">
                    <col style="width:10%">
                    <col style="width:16%">
                </colgroup>
                <thead>
                    <tr>
                        <th data-ot-sort="name" scope="col"><?php esc_html_e('Name', 'opentrust'); ?></th>
                        <th data-ot-sort="purpose" scope="col"><?php esc_html_e('Purpose', 'opentrust'); ?></th>
                        <th data-ot-sort="data" scope="col"><?php esc_html_e('Data Processed', 'opentrust'); ?></th>
                        <th data-ot-sort="location" scope="col"><?php esc_html_e('Location', 'opentrust'); ?></th>
                        <th scope="col"><?php esc_html_e('DPA', 'opentrust'); ?></th>
                        <th scope="col"><?php esc_html_e('Website', 'opentrust'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ot_subprocessors as $ot_sub):
                        $ot_website = $ot_sub['website'] ?? '';
                        $ot_domain  = $ot_website ? wp_parse_url($ot_website, PHP_URL_HOST) ?: $ot_website : '';
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($ot_sub['name']); ?></strong></td>
                        <td class="ot-table__clamp">
                            <span class="ot-table__clamp-text"><?php echo esc_html($ot_sub['purpose']); ?></span>
                            <button class="ot-table__more" data-ot-clamp-toggle><?php esc_html_e('more', 'opentrust'); ?></button>
                        </td>
                        <td class="ot-table__clamp">
                            <span class="ot-table__clamp-text"><?php echo esc_html($ot_sub['data_processed']); ?></span>
                            <button class="ot-table__more" data-ot-clamp-toggle><?php esc_html_e('more', 'opentrust'); ?></button>
                        </td>
                        <td><?php echo esc_html($ot_sub['country']); ?></td>
                        <td>
                            <?php if ($ot_sub['dpa_signed']): ?>
                                <span class="ot-dpa-badge ot-dpa-badge--signed">
                                    <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0a8 8 0 1 0 0 16A8 8 0 0 0 8 0zm3.78 5.97l-4.5 5a.75.75 0 0 1-1.06.04l-2.5-2.25a.75.75 0 1 1 1.06-1.06l1.94 1.75 3.97-4.43a.75.75 0 1 1 1.1 1.02l-.01-.07z"/></svg>
                                    <?php esc_html_e('Signed', 'opentrust'); ?>
                                </span>
                            <?php else: ?>
                                <span class="ot-dpa-badge ot-dpa-badge--pending">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($ot_website): ?>
                                <a href="<?php echo esc_url($ot_website); ?>" target="_blank" rel="noopener"><?php echo esc_html($ot_domain); ?></a>
                            <?php else: ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
