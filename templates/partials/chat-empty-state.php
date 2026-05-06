<?php
/**
 * Chat shell — single DOM tree used both before and during a conversation.
 * JS toggles `.is-chatting` on the shell; the intro hides via CSS and the
 * thread reveals below it. The input form + suggested chips both live in
 * the docked footer in `templates/chat.php`, not here.
 *
 * Variables inherited from chat.php: $ot_settings, $ot_company_name,
 * $ot_counts, $ot_model_id, $ot_show_attrib.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local scope via include()

$ot_cert_count   = (int) ($ot_counts['certifications'] ?? 0);
$ot_policy_count = (int) ($ot_counts['policies']       ?? 0);
$ot_sub_count    = (int) ($ot_counts['subprocessors']  ?? 0);
$ot_dp_count     = (int) ($ot_counts['data_practices'] ?? 0);
?>
<div class="ot-chat-shell" data-ot-chat-shell>

    <section class="ot-chat-intro" data-ot-chat-intro>
        <h1 class="ot-chat-intro__title">
            <?php
            /* translators: %s: company name */
            printf(esc_html__('Ask about %s\'s security and compliance', 'opentrust'), esc_html($ot_company_name));
            ?>
        </h1>
        <p class="ot-chat-intro__help">
            <?php if ($ot_show_attrib && $ot_model_id): ?>
                <?php
                printf(
                    /* translators: 1: model identifier, 2: sources summary */
                    esc_html__('Using model %1$s. Grounded in %2$s.', 'opentrust'),
                    '<strong>' . esc_html($ot_model_id) . '</strong>',
                    esc_html(sprintf(
                        '%d policies, %d certifications, %d subprocessors, %d data practices',
                        $ot_policy_count, $ot_cert_count, $ot_sub_count, $ot_dp_count
                    ))
                );
                ?>
            <?php else: ?>
                <?php
                printf(
                    /* translators: %s: sources summary */
                    esc_html__('Grounded in %s.', 'opentrust'),
                    esc_html(sprintf(
                        '%d policies, %d certifications, %d subprocessors, %d data practices',
                        $ot_policy_count, $ot_cert_count, $ot_sub_count, $ot_dp_count
                    ))
                );
                ?>
            <?php endif; ?>
        </p>
    </section>

    <section class="ot-chat-thread" aria-label="<?php esc_attr_e('Conversation', 'opentrust'); ?>">
        <div class="ot-chat-messages" data-ot-chat-messages aria-live="polite" aria-atomic="false"></div>
    </section>

</div>
