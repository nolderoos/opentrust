<?php
/**
 * Subprocessors section partial.
 *
 * Variables available from parent: $data
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$subprocessors = $data['subprocessors'] ?? [];
?>
<section id="ot-subprocessors" class="ot-section">
    <div class="ot-section__header">
        <h2 class="ot-section__title"><?php esc_html_e('Subprocessors', 'opentrust'); ?></h2>
        <p class="ot-section__description"><?php esc_html_e('Third-party services that process data on our behalf.', 'opentrust'); ?></p>
    </div>

    <div class="ot-table-wrapper">
        <table class="ot-table" role="table">
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
                <?php foreach ($subprocessors as $sub):
                    $website = $sub['website'] ?? '';
                    $domain  = $website ? wp_parse_url($website, PHP_URL_HOST) ?: $website : '';
                ?>
                <tr>
                    <td><strong><?php echo esc_html($sub['name']); ?></strong></td>
                    <td><?php echo esc_html($sub['purpose']); ?></td>
                    <td><?php echo esc_html($sub['data_processed']); ?></td>
                    <td><?php echo esc_html($sub['country']); ?></td>
                    <td>
                        <?php if ($sub['dpa_signed']): ?>
                            <span class="ot-dpa-badge ot-dpa-badge--signed">
                                <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0a8 8 0 1 0 0 16A8 8 0 0 0 8 0zm3.78 5.97l-4.5 5a.75.75 0 0 1-1.06.04l-2.5-2.25a.75.75 0 1 1 1.06-1.06l1.94 1.75 3.97-4.43a.75.75 0 1 1 1.1 1.02l-.01-.07z"/></svg>
                                <?php esc_html_e('Signed', 'opentrust'); ?>
                            </span>
                        <?php else: ?>
                            <span class="ot-dpa-badge ot-dpa-badge--pending">&mdash;</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($website): ?>
                            <a href="<?php echo esc_url($website); ?>" target="_blank" rel="noopener"><?php echo esc_html($domain); ?></a>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
