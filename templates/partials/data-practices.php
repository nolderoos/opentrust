<?php
/**
 * Data practices section — Vanta-style category cards.
 *
 * Each card = one data practice category (e.g. "Account Information").
 * Inside: a list of data items with green checkmark icons.
 * Click the card/chevron to expand details (purpose, legal basis, retention, shared-with).
 *
 * Variables available from parent: $ot_data
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local scope via include()

$ot_practices    = $ot_data['data_practices'] ?? [];
$ot_basis_labels = OpenTrust_Render::legal_basis_labels();

if (empty($ot_practices)) {
    return;
}

// How many items to show before "View N more".
$ot_preview_limit = 5;
?>
<section id="ot-data-practices" class="ot-section">
    <div class="ot-container">

        <!-- Section header -->
        <div class="ot-section__header">
            <?php echo OpenTrust_Render::updated_pill('data_practices', $ot_data); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output escaped within method ?>
            <h2 class="ot-section__title"><?php esc_html_e('Data Practices', 'opentrust'); ?></h2>
            <p class="ot-section__description"><?php esc_html_e('What we collect and how we handle your data.', 'opentrust'); ?></p>
        </div>

        <!-- Card grid -->
        <div class="ot-dp-cards">
            <?php foreach ($ot_practices as $ot_dp):
                $ot_items       = $ot_dp['data_items'] ?? [];
                $ot_total_items = count($ot_items);
                $ot_preview     = array_slice($ot_items, 0, $ot_preview_limit);
                $ot_overflow    = $ot_total_items - $ot_preview_limit;
                $ot_has_details = $ot_dp['purpose'] || $ot_dp['legal_basis'] || $ot_dp['retention_period'] || !empty($ot_dp['shared_with']);
                $ot_card_id     = 'ot-dp-detail-' . $ot_dp['id'];
            ?>
            <div class="ot-dp-card" data-ot-dp-card>
                <!-- Card header -->
                <div class="ot-dp-card__head"<?php if ($ot_has_details): ?> data-ot-dp-toggle="<?php echo esc_attr($ot_card_id); ?>" role="button" tabindex="0" aria-expanded="false"<?php endif; ?>>
                    <h3 class="ot-dp-card__title"><?php echo esc_html($ot_dp['title']); ?></h3>
                    <?php if ($ot_has_details): ?>
                    <svg class="ot-dp-card__arrow" width="20" height="20" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
                    <?php endif; ?>
                </div>

                <!-- Checkmark list -->
                <?php if ($ot_total_items > 0): ?>
                <ul class="ot-dp-card__list">
                    <?php foreach ($ot_preview as $ot_item): ?>
                    <li class="ot-dp-card__item">
                        <svg class="ot-dp-card__check" width="20" height="20" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                        <span><?php echo esc_html($ot_item['name']); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>

                <?php if ($ot_overflow > 0): ?>
                <button class="ot-dp-card__more" data-ot-dp-more>
                    <?php
                    printf(
                        /* translators: %1$d = number, %2$s = category name */
                        esc_html__('View %1$d more %2$s items', 'opentrust'),
                        intval( $ot_overflow ),
                        esc_html($ot_dp['title'])
                    );
                    ?>
                </button>
                <ul class="ot-dp-card__list ot-dp-card__list--overflow" hidden>
                    <?php foreach (array_slice($ot_items, $ot_preview_limit) as $ot_item): ?>
                    <li class="ot-dp-card__item">
                        <svg class="ot-dp-card__check" width="20" height="20" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                        <span><?php echo esc_html($ot_item['name']); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <?php endif; ?>

                <!-- Expandable details -->
                <?php if ($ot_has_details): ?>
                <div class="ot-dp-card__details" id="<?php echo esc_attr($ot_card_id); ?>" hidden>
                    <div class="ot-dp-card__details-inner">
                        <?php if ($ot_dp['purpose']): ?>
                        <div class="ot-dp-card__detail">
                            <dt><?php esc_html_e('Purpose', 'opentrust'); ?></dt>
                            <dd><?php echo esc_html($ot_dp['purpose']); ?></dd>
                        </div>
                        <?php endif; ?>

                        <?php if ($ot_dp['legal_basis']): ?>
                        <div class="ot-dp-card__detail">
                            <dt><?php esc_html_e('Legal Basis', 'opentrust'); ?></dt>
                            <dd><?php echo esc_html($ot_basis_labels[$ot_dp['legal_basis']] ?? $ot_dp['legal_basis']); ?></dd>
                        </div>
                        <?php endif; ?>

                        <?php if ($ot_dp['retention_period']): ?>
                        <div class="ot-dp-card__detail">
                            <dt><?php esc_html_e('Retention', 'opentrust'); ?></dt>
                            <dd><?php echo esc_html($ot_dp['retention_period']); ?></dd>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($ot_dp['shared_with'])): ?>
                        <div class="ot-dp-card__detail">
                            <dt><?php esc_html_e('Shared With', 'opentrust'); ?></dt>
                            <dd>
                                <?php
                                $ot_names = array_map(fn($ot_s) => $ot_s['name'], $ot_dp['shared_with']);
                                echo esc_html(implode(', ', $ot_names));
                                ?>
                            </dd>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

    </div>
</section>
