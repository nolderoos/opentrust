<?php
/**
 * OpenRouter provider adapter.
 *
 * OpenRouter is OpenAI-compatible so we extend the OpenAI adapter and override
 * only the endpoints, host allowlist, and model curation (OpenRouter's /models
 * endpoint returns richer metadata we use to filter to tool-capable models).
 *
 * API reference: https://openrouter.ai/docs/api-reference/overview
 *                https://openrouter.ai/docs/api-reference/list-available-models
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OpenTrust_Chat_Provider_OpenRouter extends OpenTrust_Chat_Provider_OpenAI {

    protected const API_BASE             = 'https://openrouter.ai';
    protected const MODELS_ENDPOINT      = 'https://openrouter.ai/api/v1/models';
    protected const CHAT_COMPLETIONS_URL = 'https://openrouter.ai/api/v1/chat/completions';

    /**
     * Recommended default models on OpenRouter — use the provider-prefixed IDs.
     */
    protected const RECOMMENDED_IDS = [
        'anthropic/claude-sonnet-4-5',
        'openai/gpt-4o',
    ];

    public function slug(): string {
        return 'openrouter';
    }

    public function label(): string {
        return __('OpenRouter', 'opentrust');
    }

    public function allowed_hosts(): array {
        return ['openrouter.ai'];
    }

    /**
     * OpenRouter returns ~300 models. Filter to tool-capable chat models only —
     * the chat feature is worthless without tool calling for citation fidelity.
     */
    public function curate_models(mixed $raw): array {
        if (!is_array($raw) || empty($raw['data']) || !is_array($raw['data'])) {
            return [];
        }

        $models = [];
        foreach ($raw['data'] as $row) {
            if (!is_array($row) || empty($row['id'])) {
                continue;
            }
            $id = (string) $row['id'];

            // Require tool calling + response_format support.
            $supported = $row['supported_parameters'] ?? [];
            if (!is_array($supported) || !in_array('tools', $supported, true)) {
                continue;
            }

            // Require text modality (skip image-gen and audio-only).
            $architecture = $row['architecture'] ?? [];
            $modality     = is_array($architecture) && isset($architecture['modality'])
                ? (string) $architecture['modality']
                : '';
            if ($modality !== '' && !str_contains($modality, 'text->text') && !str_contains($modality, '->text')) {
                continue;
            }

            $display = isset($row['name']) && is_string($row['name'])
                ? (string) $row['name']
                : $id;

            $models[] = [
                'id'           => $id,
                'display_name' => $display,
                'recommended'  => $this->is_recommended($id),
            ];
        }

        // Sort: recommended first, then by display name alphabetically.
        usort($models, function ($a, $b) {
            if ($a['recommended'] !== $b['recommended']) {
                return $a['recommended'] ? -1 : 1;
            }
            return strcasecmp($a['display_name'], $b['display_name']);
        });

        return $models;
    }

    protected function is_recommended(string $id): bool {
        return in_array($id, static::RECOMMENDED_IDS, true);
    }

    /**
     * OpenRouter requires HTTP-Referer and X-Title headers to identify the
     * source application (optional but strongly recommended for credit/rate limits).
     */
    protected function extra_stream_headers(): array {
        return [
            'HTTP-Referer' => home_url('/'),
            'X-Title'      => (string) (OpenTrust::get_settings()['company_name'] ?? 'OpenTrust'),
        ];
    }

    // stream_chat() is inherited from OpenTrust_Chat_Provider_OpenAI.
}
