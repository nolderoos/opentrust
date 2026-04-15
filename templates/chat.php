<?php
/**
 * AI chat page template.
 *
 * Outputs a complete, standalone HTML document for /trust-center/ask/.
 * Variables available: $ot_data (settings, hsl, logo_url, base_url, chat_state,
 *                       prefill_q, source_counts, certifications, policies, ...)
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local scope via include()

$ot_settings     = $ot_data['settings'];
$ot_hsl          = $ot_data['hsl'];
$ot_company_name = esc_html($ot_settings['company_name'] ?? '');
$ot_logo_url     = $ot_data['logo_url'] ?? '';
$ot_avatar_url   = $ot_data['avatar_url'] ?? '';
$ot_base_url     = $ot_data['base_url'] ?? '/';
$ot_state        = $ot_data['chat_state'] ?? 'unconfigured';
$ot_prefill_q    = $ot_data['prefill_q'] ?? '';
$ot_counts       = $ot_data['source_counts'] ?? [];

$ot_model_id     = (string) ($ot_settings['ai_model'] ?? '');
$ot_show_attrib  = !empty($ot_settings['ai_show_model_attribution']);
$ot_contact_url  = (string) ($ot_settings['ai_contact_url'] ?? '');
if ($ot_contact_url === '') {
    $ot_contact_url = $ot_base_url;
}
$ot_max_len   = (int) ($ot_settings['ai_max_message_length'] ?? 1000);
$ot_ts_key    = !empty($ot_settings['ai_turnstile_enabled']) ? (string) ($ot_settings['turnstile_site_key'] ?? '') : '';

$ot_rest_url  = esc_url_raw(rest_url('opentrust/v1/chat'));
$ot_nonce     = wp_create_nonce('wp_rest');

// Contrast-safe text color against the user's accent color.
$ot_accent_contrast = ((int) $ot_hsl['l'] < 55) ? '#ffffff' : '#111827';

// Build nav items so we inherit the same header as the main trust center.
$ot_visible   = $ot_settings['sections_visible'] ?? [];
$ot_nav_items = [];
if (!empty($ot_visible['policies']) && !empty($ot_data['policies']))                 $ot_nav_items['policies']       = __('Policies', 'opentrust');
if (!empty($ot_visible['certifications']) && !empty($ot_data['certifications']))    $ot_nav_items['certifications'] = __('Certifications', 'opentrust');
if (!empty($ot_visible['subprocessors']) && !empty($ot_data['subprocessors']))      $ot_nav_items['subprocessors']  = __('Subprocessors', 'opentrust');
if (!empty($ot_visible['data_practices']) && !empty($ot_data['data_practices']))    $ot_nav_items['data-practices'] = __('Data Practices', 'opentrust');
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html(sprintf(
        /* translators: %s: company name */
        __('Ask %s — Trust Center', 'opentrust'),
        $ot_company_name ?: get_bloginfo('name')
    )); ?></title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <link rel="canonical" href="<?php echo esc_url(trailingslashit($ot_base_url) . 'ask/'); ?>">
    <style>
        :root {
            --ot-accent-h: <?php echo (int) $ot_hsl['h']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integer cast ?>;
            --ot-accent-s: <?php echo (int) $ot_hsl['s']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integer cast ?>%;
            --ot-accent-l: <?php echo (int) $ot_hsl['l']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integer cast ?>%;
            --ot-accent-contrast: <?php echo esc_attr($ot_accent_contrast); ?>;
        }
        <?php
        $ot_base_css_path = OPENTRUST_PLUGIN_DIR . 'assets/css/frontend.css';
        if (file_exists($ot_base_css_path)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo file_get_contents($ot_base_css_path);
        }
        $ot_chat_css_path = OPENTRUST_PLUGIN_DIR . 'assets/css/chat.css';
        if (file_exists($ot_chat_css_path)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo file_get_contents($ot_chat_css_path);
        }
        ?>
    </style>
    <?php if ($ot_ts_key !== ''): ?>
        <?php
        // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent, PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent, WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Turnstile must load from Cloudflare CDN
        wp_register_script('opentrust-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', [], null, ['strategy' => 'defer']);
        wp_print_scripts('opentrust-turnstile');
        ?>
    <?php endif; ?>
</head>
<body class="ot-body ot-chat-body">

    <a class="ot-skip-link" href="#ot-chat-main"><?php esc_html_e('Skip to content', 'opentrust'); ?></a>

    <nav class="ot-nav" aria-label="<?php esc_attr_e('Trust center navigation', 'opentrust'); ?>">
        <div class="ot-container ot-nav__inner">
            <a href="<?php echo esc_url($ot_base_url); ?>" class="ot-nav__brand">
                <?php if ($ot_logo_url): ?>
                    <img class="ot-nav__brand-logo"
                         src="<?php echo esc_url($ot_logo_url); ?>"
                         alt="<?php echo esc_attr($ot_company_name); ?>">
                <?php else: ?>
                    <span class="ot-nav__brand-name"><?php echo esc_html($ot_company_name ?: get_bloginfo('name')); ?></span>
                <?php endif; ?>
            </a>
            <div class="ot-nav__links">
                <?php foreach ($ot_nav_items as $ot_id => $ot_label): ?>
                    <a href="<?php echo esc_url(trailingslashit($ot_base_url) . '#ot-' . $ot_id); ?>" class="ot-nav__link">
                        <?php echo esc_html($ot_label); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </nav>

    <main id="ot-chat-main" class="ot-chat-main">
        <div class="ot-chat-container">

            <?php if ($ot_state === 'unconfigured'): ?>
                <section class="ot-chat-state ot-chat-state--unavailable">
                    <div class="ot-chat-state__icon" aria-hidden="true">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    </div>
                    <h1><?php esc_html_e('Ask AI is not configured', 'opentrust'); ?></h1>
                    <p><?php esc_html_e('The site administrator has not enabled the AI chat feature yet.', 'opentrust'); ?></p>
                    <p><a href="<?php echo esc_url($ot_base_url); ?>" class="ot-button ot-button--primary">
                        <?php esc_html_e('Browse trust center', 'opentrust'); ?>
                    </a></p>
                </section>

            <?php elseif ($ot_state === 'unavailable'): ?>
                <?php include OPENTRUST_PLUGIN_DIR . 'templates/partials/chat-budget-exhausted.php'; ?>

            <?php else: /* ready */ ?>
                <?php include OPENTRUST_PLUGIN_DIR . 'templates/partials/chat-empty-state.php'; ?>
            <?php endif; ?>

            <?php if ($ot_state === 'ready'):
                $ot_ns_response = $ot_data['noscript_response'] ?? null;
                $ot_ns_nonce    = wp_create_nonce('opentrust_chat_noscript');
                ?>
                <?php if (is_array($ot_ns_response)): ?>
                    <div class="ot-chat-noscript">
                        <?php if (!empty($ot_ns_response['error'])): ?>
                            <div class="ot-chat-banner ot-chat-banner--error">
                                <?php echo esc_html((string) $ot_ns_response['error']); ?>
                            </div>
                        <?php else: ?>
                            <div class="ot-chat-msg ot-chat-msg--user">
                                <div class="ot-chat-msg__avatar" aria-hidden="true">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                </div>
                                <div class="ot-chat-msg__content">
                                    <header class="ot-chat-msg__header">
                                        <strong class="ot-chat-msg__name"><?php esc_html_e('You', 'opentrust'); ?></strong>
                                        <span class="ot-chat-msg__separator">·</span>
                                        <time class="ot-chat-msg__time"><?php esc_html_e('just now', 'opentrust'); ?></time>
                                    </header>
                                    <div class="ot-chat-msg__body"><?php echo esc_html((string) ($ot_ns_response['question'] ?? '')); ?></div>
                                </div>
                            </div>
                            <div class="ot-chat-msg ot-chat-msg--assistant<?php echo !empty($ot_ns_response['refused']) ? ' ot-chat-msg--refused' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
                                <div class="ot-chat-msg__avatar" aria-hidden="true">
                                    <?php if ($ot_avatar_url): ?>
                                        <img class="ot-chat-msg__avatar-img" src="<?php echo esc_url($ot_avatar_url); ?>" alt="">
                                    <?php else: ?>
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.39 4.84L20 8l-4 3.9.94 5.5L12 14.77 7.06 17.4 8 11.9 4 8l5.61-1.16L12 2z"/></svg>
                                    <?php endif; ?>
                                </div>
                                <div class="ot-chat-msg__content">
                                    <header class="ot-chat-msg__header">
                                        <strong class="ot-chat-msg__name"><?php echo esc_html($ot_company_name); ?></strong>
                                        <span class="ot-chat-msg__separator">·</span>
                                        <time class="ot-chat-msg__time"><?php esc_html_e('just now', 'opentrust'); ?></time>
                                    </header>
                                    <div class="ot-chat-msg__body">
                                        <?php echo nl2br(esc_html((string) ($ot_ns_response['answer'] ?? ''))); ?>
                                    </div>
                                    <?php if (!empty($ot_ns_response['citations'])): ?>
                                        <div class="ot-chat-msg__sources">
                                            <h4><?php esc_html_e('Sources', 'opentrust'); ?></h4>
                                            <ol>
                                                <?php foreach ($ot_ns_response['citations'] as $ot_cite): ?>
                                                    <li><a href="<?php echo esc_url((string) ($ot_cite['url'] ?? '')); ?>"><?php echo esc_html((string) ($ot_cite['title'] ?? $ot_cite['url'] ?? '')); ?></a></li>
                                                <?php endforeach; ?>
                                            </ol>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (empty($ot_ns_response['refused'])): ?>
                                        <p class="ot-chat-msg__disclaimer">
                                            <?php esc_html_e('AI-generated answer. Not legal, security, or compliance advice. Verify against the sources above.', 'opentrust'); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <noscript>
                    <div class="ot-chat-noscript">
                        <p><?php esc_html_e('JavaScript is disabled — you can still ask one question below. The answer will load as a regular page.', 'opentrust'); ?></p>
                        <form method="post" action="" class="ot-chat-noscript__form">
                            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($ot_ns_nonce); ?>">
                            <label for="ot-chat-noscript-input"><?php esc_html_e('Your question', 'opentrust'); ?></label>
                            <textarea
                                id="ot-chat-noscript-input"
                                name="question"
                                maxlength="<?php echo (int) $ot_max_len; ?>"
                                rows="3"
                                required><?php echo esc_textarea($ot_prefill_q); ?></textarea>
                            <button type="submit"><?php esc_html_e('Ask', 'opentrust'); ?></button>
                        </form>
                    </div>
                </noscript>
            <?php endif; ?>

        </div>
    </main>

    <?php if ($ot_state === 'ready'):
        $ot_suggested = [
            ['label' => __('Are you SOC 2 compliant?', 'opentrust'),           'icon' => 'shield'],
            ['label' => __('Where is customer data stored?', 'opentrust'),      'icon' => 'database'],
            ['label' => __('What\'s your incident response process?', 'opentrust'), 'icon' => 'alert'],
            ['label' => __('Which subprocessors do you use?', 'opentrust'),     'icon' => 'share'],
        ];
        $ot_icons = [
            'shield'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
            'database' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></svg>',
            'alert'    => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
            'share'    => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>',
        ];
        ?>
        <div class="ot-chat-dock" data-ot-chat-dock>
            <div class="ot-container ot-chat-dock__inner">

                <div class="ot-chat-chips" data-ot-chat-chips aria-label="<?php esc_attr_e('Suggested questions', 'opentrust'); ?>">
                    <?php foreach ($ot_suggested as $ot_q): ?>
                        <button type="button" class="ot-chat-chip" data-ot-chat-chip="<?php echo esc_attr($ot_q['label']); ?>">
                            <span class="ot-chat-chip__icon" aria-hidden="true"><?php
                                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hard-coded static SVG
                                echo $ot_icons[$ot_q['icon']] ?? '';
                            ?></span>
                            <?php echo esc_html($ot_q['label']); ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="ot-chat-inputbar">
                    <form class="ot-chat-form" data-ot-chat-form autocomplete="off">
                        <div class="ot-chat-inputbar__field">
                            <label for="ot-chat-input" class="ot-visually-hidden">
                                <?php esc_html_e('Ask a question', 'opentrust'); ?>
                            </label>
                            <textarea
                                id="ot-chat-input"
                                data-ot-chat-input
                                rows="1"
                                maxlength="<?php echo (int) $ot_max_len; ?>"
                                placeholder="<?php esc_attr_e('Ask anything about our security and compliance…', 'opentrust'); ?>"
                            ><?php echo esc_textarea($ot_prefill_q); ?></textarea>
                            <button type="button" class="ot-chat-reset" data-ot-chat-reset aria-label="<?php esc_attr_e('Start a new conversation', 'opentrust'); ?>" title="<?php esc_attr_e('Start a new conversation', 'opentrust'); ?>" disabled>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 0 1 15.5-6.2L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15.5 6.2L3 16"/><path d="M3 21v-5h5"/></svg>
                            </button>
                            <button type="submit" class="ot-chat-send" data-ot-chat-send aria-label="<?php esc_attr_e('Send', 'opentrust'); ?>">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                            </button>
                        </div>
                    </form>
                </div>

                <p class="ot-chat-dock__legal">
                    <?php
                    printf(
                        /* translators: 1: link to trust center, 2: company name */
                        esc_html__('Grounded in the published %1$s for %2$s. AI-generated, not legal, security, or compliance advice. Always check the sources.', 'opentrust'),
                        '<a href="' . esc_url($ot_base_url) . '">' . esc_html__('trust center', 'opentrust') . '</a>',
                        esc_html($ot_company_name)
                    );
                    ?>
                </p>

            </div>
        </div>
    <?php endif; ?>

    <?php if ($ot_state === 'ready'): ?>
        <script id="ot-chat-config" type="application/json"><?php
            // Config block consumed by chat.js. We build it as a JSON string so the
            // JS layer never has to parse HTML-escaped PHP values.
            echo wp_json_encode([
                'rest_url'       => $ot_rest_url,
                'nonce'          => $ot_nonce,
                'prefill_q'      => $ot_prefill_q,
                'model'          => $ot_show_attrib ? $ot_model_id : '',
                'contact_url'    => esc_url_raw($ot_contact_url),
                'max_length'     => $ot_max_len,
                'base_url'       => esc_url_raw($ot_base_url),
                'company_name'   => $ot_company_name,
                'avatar_url'     => esc_url_raw($ot_avatar_url),
                'turnstile_key'  => $ot_ts_key,
                'turnstile_required' => $ot_ts_key !== '',
                'strings'        => [
                    'placeholder'       => __('Ask anything about our security and compliance…', 'opentrust'),
                    'send'              => __('Send', 'opentrust'),
                    'stop'              => __('Stop', 'opentrust'),
                    'thinking'          => __('Thinking…', 'opentrust'),
                    'retry'             => __('Connection lost. Retry?', 'opentrust'),
                    'refused_contact'   => __('Contact security team →', 'opentrust'),
                    'copy'              => __('Copy', 'opentrust'),
                    'copied'            => __('Copied', 'opentrust'),
                    'share'             => __('Share', 'opentrust'),
                    'print'             => __('Print', 'opentrust'),
                    'link_copied'       => __('Link copied', 'opentrust'),
                    'start_new'         => __('Start a new conversation', 'opentrust'),
                    'long_hint'         => __('This conversation is getting long. Start fresh for better answers.', 'opentrust'),
                    'sources_label'     => __('Sources', 'opentrust'),
                    'refused_headline'  => __("I don't see enough information in our trust center to answer that confidently.", 'opentrust'),
                    'provider_error'    => __('The AI provider returned an error. Please try again.', 'opentrust'),
                    'unavailable'       => __('AI is temporarily unavailable. Please try again in a few minutes or browse our published content.', 'opentrust'),
                    'message_too_long'  => __('Message is too long.', 'opentrust'),
                    'rate_limited'      => __('Please wait a moment before asking again.', 'opentrust'),
                    'cite'              => __('Cite source', 'opentrust'),
                    'user_name'         => __('You', 'opentrust'),
                    'just_now'          => __('just now', 'opentrust'),
                    'empty_response'    => __('No content returned by the model.', 'opentrust'),
                    'disclaimer'        => __('AI-generated answer. Not legal, security, or compliance advice. Verify against the sources above.', 'opentrust'),
                ],
            ]);
        ?></script>
        <script>
            <?php
            $ot_chat_js_path = OPENTRUST_PLUGIN_DIR . 'assets/js/chat.js';
            if (file_exists($ot_chat_js_path)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo file_get_contents($ot_chat_js_path);
            }
            ?>
        </script>
    <?php endif; ?>

</body>
</html>
