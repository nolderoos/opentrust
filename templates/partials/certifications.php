<?php
/**
 * Certifications section partial.
 *
 * Variables available from parent: $data
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$certifications = $data['certifications'] ?? [];
$status_labels  = OpenTrust_Render::cert_status_labels();
?>
<section id="ot-certifications" class="ot-section">
    <div class="ot-section__header">
        <h2 class="ot-section__title"><?php esc_html_e('Certifications & Compliance', 'opentrust'); ?></h2>
        <p class="ot-section__description"><?php esc_html_e('Our active certifications and compliance frameworks.', 'opentrust'); ?></p>
    </div>

    <div class="ot-card-grid">
        <?php foreach ($certifications as $cert):
            $status      = $cert['status'] ?? 'active';
            $status_text = $status_labels[$status] ?? $status;
            $badge_url   = $cert['badge_url'] ?? '';
        ?>
        <div class="ot-card ot-cert-card">
            <div class="ot-cert-card__top">
                <?php if ($badge_url): ?>
                    <img class="ot-cert-card__badge"
                         src="<?php echo esc_url($badge_url); ?>"
                         alt="<?php echo esc_attr($cert['title']); ?>"
                         loading="lazy">
                <?php else: ?>
                    <div class="ot-cert-card__badge--placeholder">
                        <svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-1 16l-4-4 1.41-1.41L11 14.17l6.59-6.59L19 9l-8 8z"/></svg>
                    </div>
                <?php endif; ?>

                <div class="ot-cert-card__info">
                    <h3 class="ot-cert-card__title"><?php echo esc_html($cert['title']); ?></h3>
                    <?php if ($cert['issuing_body']): ?>
                        <p class="ot-cert-card__issuer"><?php echo esc_html($cert['issuing_body']); ?></p>
                    <?php endif; ?>
                </div>

                <span class="ot-pill ot-pill--<?php echo esc_attr($status); ?>">
                    <span class="ot-pill__dot"></span>
                    <?php echo esc_html($status_text); ?>
                </span>
            </div>

            <?php if ($cert['description']): ?>
                <p class="ot-cert-card__description"><?php echo esc_html($cert['description']); ?></p>
            <?php endif; ?>

            <?php if ($cert['issue_date'] || $cert['expiry_date']): ?>
                <div class="ot-cert-card__dates">
                    <?php if ($cert['issue_date']): ?>
                        <?php printf(esc_html__('Issued: %s', 'opentrust'), esc_html(wp_date(get_option('date_format'), strtotime($cert['issue_date'])))); ?>
                    <?php endif; ?>
                    <?php if ($cert['issue_date'] && $cert['expiry_date']): ?> · <?php endif; ?>
                    <?php if ($cert['expiry_date']): ?>
                        <?php printf(esc_html__('Expires: %s', 'opentrust'), esc_html(wp_date(get_option('date_format'), strtotime($cert['expiry_date'])))); ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</section>
