<?php
/**
 * Subscribe CTA section shown on the main trust center page.
 *
 * Variables available from parent: $ot_data, $ot_settings, $ot_base_url
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local scope via include()

$ot_subscribe_url = esc_url($ot_base_url . 'subscribe/');
$ot_feed_url      = esc_url($ot_base_url . 'feed/');
?>
<section class="ot-section ot-subscribe-cta" id="ot-subscribe">
    <div class="ot-container">
        <div class="ot-subscribe-cta__inner">
            <div class="ot-subscribe-cta__content">
                <h2 class="ot-subscribe-cta__title"><?php esc_html_e('Stay informed', 'opentrust'); ?></h2>
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
</section>
