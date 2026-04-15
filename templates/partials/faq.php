<?php
/**
 * FAQ section partial.
 *
 * Variables available from parent: $ot_data
 *
 * Renders a compact accordion list plus FAQPage JSON-LD for search engines.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local scope via include()

$ot_faqs = $ot_data['faqs'] ?? [];

// FAQPage JSON-LD — plain-text answers only, per schema.org guidelines.
$ot_faq_ld = [
    '@context'   => 'https://schema.org',
    '@type'      => 'FAQPage',
    'mainEntity' => [],
];
foreach ($ot_faqs as $ot_faq_item) {
    $ot_answer = trim((string) ($ot_faq_item['answer_text'] ?? ''));
    if ($ot_answer === '') {
        continue;
    }
    $ot_faq_ld['mainEntity'][] = [
        '@type'          => 'Question',
        'name'           => (string) $ot_faq_item['title'],
        'acceptedAnswer' => [
            '@type' => 'Answer',
            'text'  => $ot_answer,
        ],
    ];
}
?>
<section id="ot-faqs" class="ot-section ot-section--faqs">
    <div class="ot-container">
        <div class="ot-section__header">
            <h2 class="ot-section__title"><?php esc_html_e('Frequently Asked Questions', 'opentrust'); ?></h2>
            <p class="ot-section__description"><?php esc_html_e('Quick answers to the questions we hear most.', 'opentrust'); ?></p>
        </div>

        <div class="ot-faq-list">
            <?php foreach ($ot_faqs as $ot_faq_item): ?>
                <details class="ot-faq-item" id="faq-<?php echo esc_attr($ot_faq_item['slug']); ?>" data-ot-card>
                    <summary class="ot-faq-item__question">
                        <span class="ot-faq-item__question-text"><?php echo esc_html($ot_faq_item['title']); ?></span>
                        <svg class="ot-faq-item__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                    </summary>
                    <div class="ot-faq-item__answer">
                        <?php echo wp_kses_post($ot_faq_item['answer_html']); ?>
                        <?php if (!empty($ot_faq_item['related_url']) && !empty($ot_faq_item['related_title'])): ?>
                            <p class="ot-faq-item__related">
                                <?php esc_html_e('Related:', 'opentrust'); ?>
                                <a href="<?php echo esc_url($ot_faq_item['related_url']); ?>"><?php echo esc_html($ot_faq_item['related_title']); ?></a>
                            </p>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (!empty($ot_faq_ld['mainEntity'])): ?>
        <script type="application/ld+json">
            <?php echo wp_json_encode($ot_faq_ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output is safe inside <script type="application/ld+json"> ?>
        </script>
    <?php endif; ?>
</section>
