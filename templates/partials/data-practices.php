<?php
/**
 * Data practices section partial.
 *
 * Variables available from parent: $data
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$practices       = $data['data_practices'] ?? [];
$category_labels = OpenTrust_Render::dp_category_labels();
$basis_labels    = OpenTrust_Render::legal_basis_labels();

// Group by category.
$grouped = [];
foreach ($practices as $dp) {
    $cat = $dp['category'] ?? 'personal';
    $grouped[$cat][] = $dp;
}
?>
<section id="ot-data-practices" class="ot-section">
    <div class="ot-section__header">
        <h2 class="ot-section__title"><?php esc_html_e('Data Practices', 'opentrust'); ?></h2>
        <p class="ot-section__description"><?php esc_html_e('How we collect, use, and manage your data.', 'opentrust'); ?></p>
    </div>

    <?php foreach ($grouped as $category => $items):
        $cat_label = $category_labels[$category] ?? $category;
    ?>
        <h3 class="ot-section__subtitle"><?php echo esc_html($cat_label); ?></h3>
        <div class="ot-card-grid">
            <?php foreach ($items as $dp):
                $basis_label = $basis_labels[$dp['legal_basis']] ?? $dp['legal_basis'];
            ?>
            <div class="ot-card ot-dp-card">
                <h4 class="ot-dp-card__title"><?php echo esc_html($dp['title']); ?></h4>
                <dl class="ot-dp-card__details">
                    <?php if ($dp['data_type']): ?>
                        <dt><?php esc_html_e('Data Type', 'opentrust'); ?></dt>
                        <dd><?php echo esc_html($dp['data_type']); ?></dd>
                    <?php endif; ?>
                    <?php if ($dp['purpose']): ?>
                        <dt><?php esc_html_e('Purpose', 'opentrust'); ?></dt>
                        <dd><?php echo esc_html($dp['purpose']); ?></dd>
                    <?php endif; ?>
                    <?php if ($dp['legal_basis']): ?>
                        <dt><?php esc_html_e('Legal Basis', 'opentrust'); ?></dt>
                        <dd><?php echo esc_html($basis_label); ?></dd>
                    <?php endif; ?>
                    <?php if ($dp['retention_period']): ?>
                        <dt><?php esc_html_e('Retention', 'opentrust'); ?></dt>
                        <dd><?php echo esc_html($dp['retention_period']); ?></dd>
                    <?php endif; ?>
                    <?php if ($dp['shared_with']): ?>
                        <dt><?php esc_html_e('Shared With', 'opentrust'); ?></dt>
                        <dd><?php echo esc_html($dp['shared_with']); ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</section>
