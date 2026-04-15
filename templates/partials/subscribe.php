<?php
/**
 * Subscribe, confirm, unsubscribe, and preferences pages.
 *
 * Variables available: $ot_data, $ot_settings, $ot_view, $ot_base_url, $ot_company_name
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local scope via include()

$ot_categories = OpenTrust_Notify::category_labels();
?>
<main id="ot-main" class="ot-main">
    <div class="ot-container">
        <div class="ot-subscribe-page">
            <a href="<?php echo esc_url($ot_base_url); ?>" class="ot-back-link">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                <?php esc_html_e('Back to Trust Center', 'opentrust'); ?>
            </a>

            <?php if ($ot_view === 'subscribe'): ?>
                <?php
                $ot_result = $ot_data['subscribe_result'] ?? null;
                $ot_show_form = !$ot_result || !$ot_result['success'];
                ?>

                <h1 class="ot-subscribe-page__title"><?php esc_html_e('Subscribe to updates', 'opentrust'); ?></h1>
                <p class="ot-subscribe-page__desc">
                    <?php printf(
                        /* translators: %s: company name */
                        esc_html__('Get email notifications when %s updates their trust center.', 'opentrust'),
                        esc_html($ot_company_name ?: get_bloginfo('name'))
                    ); ?>
                </p>

                <?php if ($ot_result): ?>
                    <div class="ot-notice ot-notice--<?php echo esc_attr( $ot_result['success'] ? 'success' : 'error' ); ?>">
                        <?php echo esc_html($ot_result['message']); ?>
                    </div>
                <?php endif; ?>

                <?php if ($ot_show_form): ?>
                <form method="post" action="<?php echo esc_url($ot_base_url . 'subscribe/'); ?>" class="ot-subscribe-form">
                    <?php wp_nonce_field('opentrust_subscribe', '_ot_subscribe_nonce'); ?>

                    <div class="ot-subscribe-form__field">
                        <label for="ot_email"><?php esc_html_e('Email address', 'opentrust'); ?> <span class="ot-required">*</span></label>
                        <input type="email" id="ot_email" name="ot_email" required
                               placeholder="<?php esc_attr_e('you@company.com', 'opentrust'); ?>"
                               value="<?php echo esc_attr( sanitize_email( wp_unslash( $_POST['ot_email'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handler before template loads ?>">
                    </div>

                    <div class="ot-subscribe-form__row">
                        <div class="ot-subscribe-form__field">
                            <label for="ot_name"><?php esc_html_e('Name', 'opentrust'); ?></label>
                            <input type="text" id="ot_name" name="ot_name"
                                   placeholder="<?php esc_attr_e('Jane Doe', 'opentrust'); ?>"
                                   value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_POST['ot_name'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handler before template loads ?>">
                        </div>
                        <div class="ot-subscribe-form__field">
                            <label for="ot_company"><?php esc_html_e('Company', 'opentrust'); ?></label>
                            <input type="text" id="ot_company" name="ot_company"
                                   placeholder="<?php esc_attr_e('Acme Inc.', 'opentrust'); ?>"
                                   value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_POST['ot_company'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handler before template loads ?>">
                        </div>
                    </div>

                    <fieldset class="ot-subscribe-form__categories">
                        <legend><?php esc_html_e('Notify me about', 'opentrust'); ?></legend>
                        <?php foreach ($ot_categories as $ot_key => $ot_label): ?>
                            <label class="ot-subscribe-form__checkbox">
                                <input type="checkbox" name="ot_categories[]" value="<?php echo esc_attr($ot_key); ?>" checked>
                                <span><?php echo esc_html($ot_label); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>

                    <?php
                    $ot_turnstile_site_key = $ot_settings['turnstile_site_key'] ?? '';
                    if ($ot_turnstile_site_key !== ''):
                    ?>
                    <div class="cf-turnstile" data-sitekey="<?php echo esc_attr($ot_turnstile_site_key); ?>" data-theme="light"></div>
                    <?php endif; ?>

                    <button type="submit" class="ot-btn ot-btn--primary ot-btn--full">
                        <?php esc_html_e('Subscribe', 'opentrust'); ?>
                    </button>

                    <p class="ot-subscribe-form__privacy">
                        <?php esc_html_e('We will only use your email to send trust center updates. You can unsubscribe at any time.', 'opentrust'); ?>
                    </p>
                </form>
                <?php endif; ?>

            <?php elseif ($ot_view === 'confirm'): ?>

                <?php if (!empty($ot_data['confirmed'])): ?>
                    <div class="ot-status-page ot-status-page--success">
                        <div class="ot-status-page__icon">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        </div>
                        <h1><?php esc_html_e('Subscription confirmed!', 'opentrust'); ?></h1>
                        <p><?php esc_html_e('You will now receive email notifications when our trust center is updated.', 'opentrust'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="ot-status-page ot-status-page--error">
                        <div class="ot-status-page__icon">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                        </div>
                        <h1><?php esc_html_e('Invalid or expired link', 'opentrust'); ?></h1>
                        <p><?php esc_html_e('This confirmation link is no longer valid. Please subscribe again.', 'opentrust'); ?></p>
                        <a href="<?php echo esc_url($ot_base_url . 'subscribe/'); ?>" class="ot-btn ot-btn--primary"><?php esc_html_e('Subscribe', 'opentrust'); ?></a>
                    </div>
                <?php endif; ?>

            <?php elseif ($ot_view === 'unsubscribe'): ?>

                <?php if (!empty($ot_data['unsubscribed'])): ?>
                    <div class="ot-status-page ot-status-page--success">
                        <div class="ot-status-page__icon">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                        </div>
                        <h1><?php esc_html_e('Unsubscribed', 'opentrust'); ?></h1>
                        <p><?php esc_html_e('You have been unsubscribed and will no longer receive trust center updates.', 'opentrust'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="ot-status-page ot-status-page--error">
                        <div class="ot-status-page__icon">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                        </div>
                        <h1><?php esc_html_e('Invalid link', 'opentrust'); ?></h1>
                        <p><?php esc_html_e('This unsubscribe link is not valid.', 'opentrust'); ?></p>
                    </div>
                <?php endif; ?>

            <?php elseif ($ot_view === 'preferences'): ?>
                <?php
                $ot_subscriber = $ot_data['subscriber'];
                $ot_sub_cats   = json_decode($ot_subscriber->categories, true) ?: [];
                $ot_result     = $ot_data['preferences_result'] ?? null;
                ?>

                <h1 class="ot-subscribe-page__title"><?php esc_html_e('Notification preferences', 'opentrust'); ?></h1>
                <p class="ot-subscribe-page__desc">
                    <?php printf(
                        /* translators: %s: email address */
                        esc_html__('Manage notifications for %s.', 'opentrust'),
                        '<strong>' . esc_html($ot_subscriber->email) . '</strong>'
                    ); ?>
                </p>

                <?php if ($ot_result): ?>
                    <div class="ot-notice ot-notice--<?php echo esc_attr( $ot_result['success'] ? 'success' : 'error' ); ?>">
                        <?php echo esc_html($ot_result['message']); ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="ot-subscribe-form">
                    <?php wp_nonce_field('opentrust_preferences', '_ot_preferences_nonce'); ?>

                    <fieldset class="ot-subscribe-form__categories">
                        <legend><?php esc_html_e('Notify me about', 'opentrust'); ?></legend>
                        <?php foreach ($ot_categories as $ot_key => $ot_label): ?>
                            <label class="ot-subscribe-form__checkbox">
                                <input type="checkbox" name="ot_categories[]" value="<?php echo esc_attr($ot_key); ?>"
                                    <?php checked(in_array($ot_key, $ot_sub_cats, true)); ?>>
                                <span><?php echo esc_html($ot_label); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>

                    <button type="submit" class="ot-btn ot-btn--primary ot-btn--full">
                        <?php esc_html_e('Save preferences', 'opentrust'); ?>
                    </button>
                </form>

                <hr class="ot-subscribe-form__divider">

                <p class="ot-subscribe-form__unsubscribe">
                    <?php esc_html_e('Want to stop all notifications?', 'opentrust'); ?>
                    <a href="<?php echo esc_url($ot_base_url . 'unsubscribe/' . esc_attr($ot_subscriber->token) . '/'); ?>">
                        <?php esc_html_e('Unsubscribe', 'opentrust'); ?>
                    </a>
                </p>

            <?php endif; ?>
        </div>
    </div>
</main>
