<?php
/**
 * Policies section partial.
 *
 * Variables available from parent: $data, $base_url
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$policies        = $data['policies'] ?? [];
$category_labels = OpenTrust_Render::policy_category_labels();
?>
<section id="ot-policies" class="ot-section">
    <div class="ot-section__header">
        <h2 class="ot-section__title"><?php esc_html_e('Policies', 'opentrust'); ?></h2>
        <p class="ot-section__description"><?php esc_html_e('Our published security and compliance policies.', 'opentrust'); ?></p>
    </div>

    <div class="ot-card-grid">
        <?php foreach ($policies as $policy):
            $category       = $policy['category'] ?? 'other';
            $category_label = $category_labels[$category] ?? $category;
            $policy_url     = trailingslashit($base_url) . 'policy/' . $policy['slug'] . '/';
            $date_formatted = $policy['effective_date']
                ? wp_date(get_option('date_format'), strtotime($policy['effective_date']))
                : wp_date(get_option('date_format'), strtotime($policy['last_modified']));
        ?>
        <a href="<?php echo esc_url($policy_url); ?>" class="ot-card ot-card--clickable ot-policy-card">
            <div class="ot-policy-card__header">
                <div>
                    <h3 class="ot-policy-card__title"><?php echo esc_html($policy['title']); ?></h3>
                </div>
                <span class="ot-policy-card__arrow">&rarr;</span>
            </div>

            <span class="ot-pill ot-pill--category"><?php echo esc_html($category_label); ?></span>

            <?php if ($policy['excerpt']): ?>
                <p class="ot-policy-card__excerpt"><?php echo esc_html($policy['excerpt']); ?></p>
            <?php endif; ?>

            <div class="ot-policy-card__meta">
                <span>
                    <?php printf(esc_html__('v%s', 'opentrust'), esc_html((string) $policy['version'])); ?>
                </span>
                <span>
                    <?php echo esc_html($date_formatted); ?>
                </span>
                <?php if ($policy['downloadable']): ?>
                    <span>
                        <svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><path d="M8 12l-4-4h2.5V3h3v5H12L8 12zm-6 2h12v1.5H2V14z"/></svg>
                        <?php esc_html_e('PDF', 'opentrust'); ?>
                    </span>
                <?php endif; ?>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</section>
