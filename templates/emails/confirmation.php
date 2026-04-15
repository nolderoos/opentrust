<?php
/**
 * Email template: Subscription confirmation (double opt-in).
 *
 * Variables: $ot_company_name, $ot_confirm_url, $ot_greeting, $ot_accent_color
 */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local scope via include()
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html($ot_company_name); ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Inter,Roboto,sans-serif;-webkit-text-size-adjust:100%">
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f3f4f6">
        <tr>
            <td align="center" style="padding:40px 16px">
                <table role="presentation" cellpadding="0" cellspacing="0" width="560" style="max-width:560px;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08)">
                    <!-- Header -->
                    <tr>
                        <td style="background-color:<?php echo esc_attr($ot_accent_color); ?>;padding:32px 40px;text-align:center">
                            <h1 style="margin:0;color:#ffffff;font-size:20px;font-weight:700;letter-spacing:-0.02em">
                                <?php echo esc_html($ot_company_name); ?>
                            </h1>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding:40px">
                            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151">
                                <?php echo wp_kses_post( $ot_greeting ); ?>
                            </p>
                            <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#374151">
                                <?php printf(
                                    /* translators: %s: company name */
                                    esc_html__('Thank you for subscribing to trust center updates from %s. Please confirm your subscription by clicking the button below.', 'opentrust'),
                                    '<strong>' . esc_html($ot_company_name) . '</strong>'
                                ); ?>
                            </p>
                            <!-- CTA -->
                            <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td align="center" style="padding:8px 0 24px">
                                        <a href="<?php echo esc_url($ot_confirm_url); ?>"
                                           style="display:inline-block;padding:14px 32px;background-color:<?php echo esc_attr($ot_accent_color); ?>;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;border-radius:8px;letter-spacing:-0.01em">
                                            <?php esc_html_e('Confirm subscription', 'opentrust'); ?>
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:0 0 8px;font-size:13px;line-height:1.5;color:#6b7280">
                                <?php esc_html_e('If the button above does not work, copy and paste this URL into your browser:', 'opentrust'); ?>
                            </p>
                            <p style="margin:0 0 24px;font-size:12px;line-height:1.5;color:#9ca3af;word-break:break-all">
                                <?php echo esc_url($ot_confirm_url); ?>
                            </p>
                            <p style="margin:0;font-size:13px;line-height:1.5;color:#9ca3af">
                                <?php esc_html_e('If you did not request this subscription, you can safely ignore this email.', 'opentrust'); ?>
                            </p>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="padding:24px 40px;border-top:1px solid #f3f4f6;text-align:center">
                            <p style="margin:0;font-size:12px;color:#9ca3af">
                                &copy; <?php echo esc_html(wp_date('Y')); ?> <?php echo esc_html($ot_company_name); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
