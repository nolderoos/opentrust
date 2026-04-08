<?php
/**
 * Hero section partial.
 *
 * Variables available from parent: $data, $settings, $logo_url, $company_name, $tagline,
 * $cert_count, $policy_count, $sub_count
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$active_certs = 0;
foreach ($data['certifications'] as $cert) {
    if (($cert['status'] ?? '') === 'active') {
        $active_certs++;
    }
}
?>
<header class="ot-hero">
    <div class="ot-container ot-hero__inner">
        <?php if ($logo_url): ?>
            <img class="ot-hero__logo"
                 src="<?php echo esc_url($logo_url); ?>"
                 alt="<?php echo esc_attr($company_name); ?>">
        <?php endif; ?>

        <h1 class="ot-hero__title"><?php echo esc_html($settings['page_title'] ?? __('Trust Center', 'opentrust')); ?></h1>

        <?php if ($tagline): ?>
            <p class="ot-hero__tagline"><?php echo esc_html($tagline); ?></p>
        <?php endif; ?>

        <?php if ($active_certs || $policy_count || $sub_count): ?>
        <div class="ot-hero__stats">
            <?php if ($active_certs): ?>
            <div class="ot-hero__stat">
                <span class="ot-hero__stat-value"><?php echo (int) $active_certs; ?></span>
                <span class="ot-hero__stat-label"><?php esc_html_e('Active Certifications', 'opentrust'); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($policy_count): ?>
            <div class="ot-hero__stat">
                <span class="ot-hero__stat-value"><?php echo (int) $policy_count; ?></span>
                <span class="ot-hero__stat-label"><?php esc_html_e('Published Policies', 'opentrust'); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($sub_count): ?>
            <div class="ot-hero__stat">
                <span class="ot-hero__stat-value"><?php echo (int) $sub_count; ?></span>
                <span class="ot-hero__stat-label"><?php esc_html_e('Subprocessors', 'opentrust'); ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</header>
