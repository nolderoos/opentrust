<?php
/**
 * "Get in touch" section partial.
 *
 * Variables available from parent: $ot_data, $ot_settings, $ot_base_url
 *
 * Renders a dark accent-colored section containing (in order):
 *   1. Section title + optional intro + optional company description
 *   2. A vertical stack of contact rows (icon, label, stacked value lines)
 *   3. The existing Stay Informed subscribe card — inline at full container
 *      width — when notifications are enabled.
 *
 * No white container wraps the contact rows; they sit directly on the dark
 * accent background. The subscribe card below is the existing subscribe-cta
 * markup and stays exactly as designed (white card with Subscribe + RSS).
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local scope via include()

$ot_company_description = trim((string) ($ot_settings['company_description'] ?? ''));
$ot_dpo_name            = trim((string) ($ot_settings['dpo_name']            ?? ''));
$ot_dpo_email           = trim((string) ($ot_settings['dpo_email']           ?? ''));
$ot_security_email      = trim((string) ($ot_settings['security_email']      ?? ''));
$ot_contact_form_url    = trim((string) ($ot_settings['contact_form_url']    ?? ''));
$ot_contact_address     = trim((string) ($ot_settings['contact_address']     ?? ''));
$ot_pgp_key_url         = trim((string) ($ot_settings['pgp_key_url']         ?? ''));
$ot_company_reg         = trim((string) ($ot_settings['company_registration'] ?? ''));
$ot_vat_number          = trim((string) ($ot_settings['vat_number']          ?? ''));

$ot_notifications_on = !empty($ot_settings['notifications_enabled']);

// Build the rows we will render. Each row is a definition-list entry: a
// label on the left column, a stack of value lines on the right.
$ot_rows = [];

if ($ot_dpo_name || $ot_dpo_email) {
    $lines = [];
    if ($ot_dpo_name) {
        $lines[] = '<span class="ot-get-row__strong">' . esc_html($ot_dpo_name) . '</span>';
    }
    if ($ot_dpo_email) {
        $lines[] = '<a href="' . esc_url('mailto:' . $ot_dpo_email) . '">' . esc_html($ot_dpo_email) . '</a>';
    }
    $ot_rows[] = [
        'label' => __('Data Protection Officer', 'opentrust'),
        'lines' => $lines,
    ];
}

if ($ot_security_email) {
    $ot_rows[] = [
        'label' => __('Security Team', 'opentrust'),
        'lines' => [
            '<a href="' . esc_url('mailto:' . $ot_security_email) . '">' . esc_html($ot_security_email) . '</a>',
        ],
    ];
}

if ($ot_contact_form_url) {
    $ot_rows[] = [
        'label' => __('Contact Form', 'opentrust'),
        'lines' => [
            '<a href="' . esc_url($ot_contact_form_url) . '" target="_blank" rel="noopener">' . esc_html__('Open the contact form', 'opentrust') . ' &rarr;</a>',
        ],
    ];
}

if ($ot_pgp_key_url) {
    $ot_rows[] = [
        'label' => __('PGP Public Key', 'opentrust'),
        'lines' => [
            '<a href="' . esc_url($ot_pgp_key_url) . '" target="_blank" rel="noopener">' . esc_html__('Download public key', 'opentrust') . '</a>',
        ],
    ];
}

if ($ot_contact_address) {
    // Preserve line breaks as stacked lines.
    $addr_lines = array_filter(array_map('trim', preg_split('/\r?\n/', $ot_contact_address) ?: []));
    $lines = [];
    foreach ($addr_lines as $line) {
        $lines[] = '<span>' . esc_html($line) . '</span>';
    }
    if (empty($lines)) {
        $lines[] = '<span>' . esc_html($ot_contact_address) . '</span>';
    }
    $ot_rows[] = [
        'label' => __('Mailing Address', 'opentrust'),
        'lines' => $lines,
    ];
}

if ($ot_company_reg) {
    $ot_rows[] = [
        'label' => __('Company Registration', 'opentrust'),
        'lines' => [
            '<span class="ot-get-row__strong">' . esc_html($ot_company_reg) . '</span>',
        ],
    ];
}

if ($ot_vat_number) {
    $ot_rows[] = [
        'label' => __('VAT / Tax ID', 'opentrust'),
        'lines' => [
            '<span class="ot-get-row__strong">' . esc_html($ot_vat_number) . '</span>',
        ],
    ];
}
?>
<section id="ot-contact" class="ot-section ot-section--getintouch">
    <div class="ot-container">
        <div class="ot-get-inner">
            <div class="ot-get-header">
                <h2 class="ot-get-header__title"><?php esc_html_e('Get in touch', 'opentrust'); ?></h2>
                <?php if ($ot_company_description): ?>
                    <p class="ot-get-header__description"><?php echo esc_html($ot_company_description); ?></p>
                <?php endif; ?>
            </div>

            <?php if (!empty($ot_rows)): ?>
                <dl class="ot-get-list">
                    <?php foreach ($ot_rows as $ot_row): ?>
                        <div class="ot-get-row">
                            <dt class="ot-get-row__label"><?php echo esc_html($ot_row['label']); ?></dt>
                            <dd class="ot-get-row__lines">
                                <?php foreach ($ot_row['lines'] as $ot_line): ?>
                                    <?php echo $ot_line; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each line was pre-escaped above ?>
                                <?php endforeach; ?>
                            </dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
        </div>

        <?php if ($ot_notifications_on):
            // Stay Informed — embedded inside the same section so the two
            // read as one "get in touch" block. Uses the existing
            // .ot-subscribe-cta__inner design exactly as before.
            $ot_subscribe_url = esc_url($ot_base_url . 'subscribe/');
            $ot_feed_url      = esc_url($ot_base_url . 'feed/');
        ?>
            <div class="ot-get-subscribe">
                <div class="ot-subscribe-cta__inner">
                    <div class="ot-subscribe-cta__content">
                        <h3 class="ot-subscribe-cta__title"><?php esc_html_e('Stay informed', 'opentrust'); ?></h3>
                        <p class="ot-subscribe-cta__text">
                            <?php esc_html_e('Get notified when we update our policies, add subprocessors, or change our compliance posture.', 'opentrust'); ?>
                        </p>
                    </div>
                    <div class="ot-subscribe-cta__actions">
                        <a href="<?php echo $ot_subscribe_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped via esc_url() ?>" class="ot-btn ot-btn--primary">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                            <?php esc_html_e('Subscribe to updates', 'opentrust'); ?>
                        </a>
                        <a href="<?php echo $ot_feed_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped via esc_url() ?>" class="ot-btn ot-btn--ghost" title="<?php esc_attr_e('RSS Feed', 'opentrust'); ?>">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 11a9 9 0 0 1 9 9"/><path d="M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1"/></svg>
                            <?php esc_html_e('RSS', 'opentrust'); ?>
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
