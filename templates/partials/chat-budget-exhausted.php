<?php
/**
 * Chat budget-exhausted state partial. Rendered when the daily/monthly
 * token budget has been spent or the corpus is over the hard cap.
 * Variables inherited from chat.php: $ot_settings, $ot_base_url, $ot_company_name.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local scope via include()
?>
<section class="ot-chat-state ot-chat-state--exhausted">
    <div class="ot-chat-state__icon" aria-hidden="true">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    </div>
    <h1><?php esc_html_e('Ask AI is taking a breather', 'opentrust'); ?></h1>
    <p><?php esc_html_e('We\'ve hit the daily question limit. Chat will be back soon — in the meantime, you can still browse the full trust center.', 'opentrust'); ?></p>
    <div class="ot-chat-state__actions">
        <a class="ot-button ot-button--primary" href="<?php echo esc_url($ot_base_url); ?>">
            <?php esc_html_e('Browse policies', 'opentrust'); ?> →
        </a>
        <?php
        $ot_contact = $ot_settings['ai_contact_url'] ?? '';
        if ($ot_contact !== ''):
            ?>
            <a class="ot-button ot-button--ghost" href="<?php echo esc_url($ot_contact); ?>">
                <?php esc_html_e('Contact us', 'opentrust'); ?>
            </a>
        <?php endif; ?>
    </div>
</section>
