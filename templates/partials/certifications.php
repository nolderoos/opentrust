<?php
/**
 * Certifications section partial.
 *
 * Variables available from parent: $ot_data
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local scope via include()

$ot_certifications = $ot_data['certifications'] ?? [];
$ot_audited_labels = OpenTrust_Render::cert_status_labels();
$ot_aligned_labels = OpenTrust_Render::cert_aligned_status_labels();
?>
<section id="ot-certifications" class="ot-section">
    <div class="ot-container">
        <div class="ot-section__header">
            <?php echo OpenTrust_Render::updated_pill('certifications', $ot_data); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output escaped within method ?>
            <h2 class="ot-section__title"><?php esc_html_e('Certifications & Compliance', 'opentrust'); ?></h2>
            <p class="ot-section__description"><?php esc_html_e('Our active certifications and compliance frameworks demonstrate our commitment to protecting your data.', 'opentrust'); ?></p>
        </div>

        <div class="ot-cert-grid">
            <?php foreach ($ot_certifications as $ot_cert):
                $ot_cert_type   = $ot_cert['type'] ?? 'certified';
                $ot_is_audited  = $ot_cert_type === 'certified';
                $ot_status      = $ot_cert['status'] ?? 'active';
                $ot_status_text = $ot_is_audited
                    ? ($ot_audited_labels[$ot_status] ?? $ot_status)
                    : ($ot_aligned_labels[$ot_status] ?? $ot_status);
                // Tier lives in the pill wording ("Certified" vs "Compliant"),
                // not a separate marker. Audited-active uses the green token,
                // everything else uses gray/amber — same palette as the rest
                // of the plugin, no new colors.
                $ot_pill_state = match (true) {
                    $ot_is_audited && $ot_status === 'active'      => 'active',
                    $ot_is_audited && $ot_status === 'in_progress' => 'in_progress',
                    $ot_is_audited && $ot_status === 'expired'     => 'expired',
                    !$ot_is_audited && $ot_status === 'in_progress' => 'in_progress',
                    default                                         => 'neutral',
                };
                $ot_badge_url    = $ot_cert['badge_url'] ?? '';
                $ot_description  = trim((string) ($ot_cert['description'] ?? ''));
                $ot_artifact_url = $ot_cert['artifact_url'] ?? '';
                $ot_issue_date   = $ot_cert['issue_date']
                    ? wp_date('M Y', strtotime($ot_cert['issue_date']))
                    : '';
                $ot_expiry_date  = $ot_cert['expiry_date']
                    ? wp_date('M Y', strtotime($ot_cert['expiry_date']))
                    : '';
                // For self-attested cards the issuer slot is repurposed so
                // the card never renders empty and the tier signal lives in
                // the same typographic slot audited cards use for the auditor.
                $ot_subline = $ot_is_audited
                    ? ($ot_cert['issuing_body'] ?: '')
                    : __('Self-attested framework', 'opentrust');
            ?>
            <div class="ot-cert-tile">
                <div class="ot-cert-tile__badge">
                    <?php if ($ot_badge_url): ?>
                        <img src="<?php echo esc_url($ot_badge_url); ?>"
                             alt=""
                             loading="lazy"
                             width="44"
                             height="44">
                    <?php else: ?>
                        <div class="ot-cert-tile__badge-placeholder">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-1 16l-4-4 1.41-1.41L11 14.17l6.59-6.59L19 9l-8 8z"/></svg>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="ot-cert-tile__body">
                    <div class="ot-cert-tile__header">
                        <h3 class="ot-cert-tile__name"><?php echo esc_html($ot_cert['title']); ?></h3>
                        <span class="ot-status-indicator ot-status-indicator--<?php echo esc_attr($ot_pill_state); ?>">
                            <span class="ot-status-indicator__dot"></span>
                            <?php echo esc_html($ot_status_text); ?>
                        </span>
                    </div>

                    <?php if ($ot_subline): ?>
                        <p class="ot-cert-tile__issuer"><?php echo esc_html($ot_subline); ?></p>
                    <?php endif; ?>

                    <?php if ($ot_description): ?>
                        <p class="ot-cert-tile__description"><?php echo esc_html($ot_description); ?></p>
                    <?php endif; ?>

                    <?php if ($ot_is_audited && ($ot_issue_date || $ot_expiry_date)): ?>
                        <p class="ot-cert-tile__dates">
                            <?php
                            $ot_date_parts = [];
                            if ($ot_issue_date) {
                                /* translators: %s: certification issue date */
                                $ot_date_parts[] = sprintf(esc_html__('Issued %s', 'opentrust'), esc_html($ot_issue_date));
                            }
                            if ($ot_expiry_date) {
                                /* translators: %s: certification expiry date */
                                $ot_date_parts[] = sprintf(esc_html__('Expires %s', 'opentrust'), esc_html($ot_expiry_date));
                            }
                            echo implode(' &middot; ', $ot_date_parts); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each part escaped via esc_html() above
                            ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($ot_artifact_url): ?>
                        <a class="ot-cert-tile__artifact" href="<?php echo esc_url($ot_artifact_url); ?>" target="_blank" rel="noopener">
                            <svg viewBox="0 0 24 24" aria-hidden="true" width="14" height="14"><path d="M5 20h14v-2H5v2zm7-18l-5.5 5.5 1.41 1.41L11 5.83V16h2V5.83l3.09 3.08 1.41-1.41L12 2z" transform="rotate(180 12 12)"/></svg>
                            <?php echo $ot_is_audited
                                ? esc_html__('Download report', 'opentrust')
                                : esc_html__('View documentation', 'opentrust'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
