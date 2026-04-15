<?php
/**
 * Email template: policy change broadcast.
 *
 * Variables: $ot_company_name, $ot_greeting, $ot_policy_title, $ot_policy_url,
 *            $ot_effective_date, $ot_base_url, $ot_unsubscribe_url,
 *            $ot_preferences_url, $ot_accent_color
 */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local scope via include()
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html($ot_policy_title); ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Inter,Roboto,sans-serif;-webkit-text-size-adjust:100%">
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f3f4f6">
        <tr>
            <td align="center" style="padding:40px 16px">
                <table role="presentation" cellpadding="0" cellspacing="0" width="560" style="max-width:560px;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08)">
                    <tr>
                        <td style="background-color:<?php echo esc_attr($ot_accent_color); ?>;padding:32px 40px;text-align:center">
                            <h1 style="margin:0;color:#ffffff;font-size:20px;font-weight:700;letter-spacing:-0.02em">
                                <?php echo esc_html($ot_company_name); ?>
                            </h1>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:40px">
                            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151">
                                <?php echo wp_kses_post( $ot_greeting ); ?>
                            </p>

                            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151">
                                <?php printf(
                                    /* translators: 1: company name, 2: policy title */
                                    esc_html__('%1$s has updated the following policy on our trust center:', 'opentrust'),
                                    '<strong>' . esc_html($ot_company_name) . '</strong>',
                                    esc_html($ot_policy_title)
                                ); ?>
                            </p>

                            <h2 style="margin:20px 0 12px;font-size:20px;font-weight:700;letter-spacing:-0.02em;color:#111827;line-height:1.3">
                                <?php echo esc_html($ot_policy_title); ?>
                            </h2>

                            <?php if (!empty($ot_effective_date)): ?>
                            <p style="margin:0 0 24px;padding:10px 14px;background-color:#f9fafb;border-left:3px solid <?php echo esc_attr($ot_accent_color); ?>;border-radius:4px;font-size:13px;color:#374151">
                                <strong><?php esc_html_e('Effective date:', 'opentrust'); ?></strong>
                                <?php echo esc_html($ot_effective_date); ?>
                            </p>
                            <?php endif; ?>

                            <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td align="center" style="padding:8px 0 24px">
                                        <a href="<?php echo esc_url($ot_policy_url); ?>"
                                           style="display:inline-block;padding:14px 32px;background-color:<?php echo esc_attr($ot_accent_color); ?>;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;border-radius:8px;letter-spacing:-0.01em">
                                            <?php esc_html_e('View the updated policy', 'opentrust'); ?>
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:24px 40px;border-top:1px solid #f3f4f6;text-align:center">
                            <p style="margin:0 0 8px;font-size:12px;color:#9ca3af">
                                <a href="<?php echo esc_url($ot_preferences_url); ?>" style="color:#9ca3af;text-decoration:underline">
                                    <?php esc_html_e('Manage preferences', 'opentrust'); ?>
                                </a>
                                &nbsp;&middot;&nbsp;
                                <a href="<?php echo esc_url($ot_unsubscribe_url); ?>" style="color:#9ca3af;text-decoration:underline">
                                    <?php esc_html_e('Unsubscribe', 'opentrust'); ?>
                                </a>
                            </p>
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
