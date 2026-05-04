<?php
/**
 * Import/export service. Builds and reads the ZIP+manifest archives.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OpenTrust_IO {

    public const SCHEMA_VERSION  = 1;
    public const FORMAT_SETTINGS = 'opentrust-settings';
    public const FORMAT_CONTENT  = 'opentrust-content';

    public const STRATEGY_SKIP       = 'skip';
    public const STRATEGY_OVERWRITE  = 'overwrite';
    public const STRATEGY_CREATE_NEW = 'create_new';

    // Encrypted secrets, per-site salt, and server-controlled flags. Never exported.
    public const SETTINGS_EXCLUDE = [
        'turnstile_secret_key',
        'opentrust_site_salt',
        'ai_enabled',
        'ai_provider',
        'ai_model_list_cached_at',
    ];

    // Keep in sync with class-opentrust-cpt.php save handlers.
    private const META_KEYS = [
        'ot_policy' => [
            '_ot_uuid',
            '_ot_policy_ref_id',
            '_ot_policy_category',
            '_ot_policy_effective_date',
            '_ot_policy_review_date',
            '_ot_policy_sort_order',
            '_ot_policy_citations',
            '_ot_policy_attachment_id',
            '_ot_version',
            '_ot_version_summary',
            '_ot_policy_chat_summary',
            '_ot_policy_chat_summary_updated_at',
            '_ot_policy_chat_summary_origin',
        ],
        'ot_certification' => [
            '_ot_uuid',
            '_ot_cert_type',
            '_ot_cert_status',
            '_ot_cert_issuing_body',
            '_ot_cert_issue_date',
            '_ot_cert_expiry_date',
            '_ot_cert_badge_id',
            '_ot_cert_artifact_id',
            '_ot_cert_description',
        ],
        'ot_subprocessor' => [
            '_ot_uuid',
            '_ot_sub_purpose',
            '_ot_sub_data_processed',
            '_ot_sub_country',
            '_ot_sub_website',
            '_ot_sub_dpa_signed',
        ],
        'ot_data_practice' => [
            '_ot_uuid',
            '_ot_dp_data_items',
            '_ot_dp_purpose',
            '_ot_dp_legal_basis',
            '_ot_dp_retention_period',
            '_ot_dp_shared_with',
            '_ot_dp_sort_order',
            '_ot_dp_collected',
            '_ot_dp_stored',
            '_ot_dp_shared',
            '_ot_dp_sold',
            '_ot_dp_encrypted',
        ],
        'ot_faq' => [
            '_ot_uuid',
            '_ot_faq_related_policy',
        ],
    ];

    // Meta keys whose value is an attachment ID; serialized as __media_ref.
    private const ATTACHMENT_META_KEYS = [
        'ot_policy'        => ['_ot_policy_attachment_id'],
        'ot_certification' => ['_ot_cert_badge_id', '_ot_cert_artifact_id'],
        'ot_subprocessor'  => [],
        'ot_data_practice' => [],
        'ot_faq'           => [],
    ];

    // meta_key => target_cpt_slug. Cross-CPT refs serialized as __post_ref.
    private const POST_REF_META_KEYS = [
        'ot_faq' => [
            '_ot_faq_related_policy' => 'ot_policy',
        ],
    ];

    private const SETTINGS_ATTACHMENT_KEYS = ['logo_id', 'avatar_id'];

    // ──────────────────────────────────────────────
    // Public: build manifests
    // ──────────────────────────────────────────────

    public static function build_settings_manifest(bool $include_media = true): array {
        $settings = OpenTrust::get_settings();
        foreach (self::SETTINGS_EXCLUDE as $k) {
            unset($settings[$k]);
        }

        $media = [];
        foreach (self::SETTINGS_ATTACHMENT_KEYS as $k) {
            $att_id = (int) ($settings[$k] ?? 0);
            if ($att_id <= 0 || !$include_media) {
                $settings[$k] = 0;
                continue;
            }
            $ref = self::collect_attachment($att_id, $media);
            $settings[$k] = $ref ? ['__media_ref' => $ref] : 0;
        }

        return [
            'format'            => self::FORMAT_SETTINGS,
            'schema'            => self::SCHEMA_VERSION,
            'opentrust_version' => OPENTRUST_VERSION,
            'db_version'        => OPENTRUST_DB_VERSION,
            'exported_at'       => gmdate('c'),
            'site_url'          => home_url('/'),
            'site_locale'       => get_locale(),
            'settings'          => $settings,
            'media'             => $media,
        ];
    }

    /**
     * @param array<string, list<int>|true> $selection Map of CPT slug to either
     *        true (all published) or a list of post IDs.
     */
    public static function build_content_manifest(array $selection, bool $include_media = true): array {
        $records = [];
        $media   = [];

        foreach (self::META_KEYS as $cpt => $_keys) {
            if (!array_key_exists($cpt, $selection)) {
                continue;
            }
            $picks = $selection[$cpt];
            $ids = $picks === true
                ? get_posts([
                    'post_type'      => $cpt,
                    'post_status'    => ['publish', 'draft', 'private'],
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                  ])
                : array_map('intval', (array) $picks);

            $records[$cpt] = [];
            foreach ($ids as $id) {
                $rec = self::record_for_post((int) $id, $include_media, $media);
                if ($rec) {
                    $records[$cpt][] = $rec;
                }
            }
        }

        return [
            'format'            => self::FORMAT_CONTENT,
            'schema'            => self::SCHEMA_VERSION,
            'opentrust_version' => OPENTRUST_VERSION,
            'db_version'        => OPENTRUST_DB_VERSION,
            'exported_at'       => gmdate('c'),
            'site_url'          => home_url('/'),
            'site_locale'       => get_locale(),
            'records'           => $records,
            'media'             => $media,
        ];
    }

    // Returns the path to a temp ZIP. Caller deletes it when done.
    public static function write_zip(array $manifest, string $filename_prefix): string {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException(__('PHP ZipArchive extension is required for export.', 'opentrust'));
        }

        $tmp = wp_tempnam($filename_prefix . '.zip');
        if (!$tmp) {
            throw new \RuntimeException(__('Could not create temp file for export.', 'opentrust'));
        }

        // Source paths are local-only — pull them out before the manifest is encoded.
        $source_paths = [];
        foreach ($manifest['media'] ?? [] as $hash => $entry) {
            if (!empty($entry['__source_path'])) {
                $source_paths[$hash] = (string) $entry['__source_path'];
                unset($manifest['media'][$hash]['__source_path']);
            }
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE | \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException(__('Could not open ZIP for writing.', 'opentrust'));
        }

        $zip->addFromString('manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        foreach ($manifest['media'] ?? [] as $hash => $entry) {
            $src = $source_paths[$hash] ?? null;
            if ($src && file_exists($src)) {
                $zip->addFile($src, (string) $entry['path']);
            }
        }

        $zip->close();
        return $tmp;
    }

    // ──────────────────────────────────────────────
    // Public: validation + preview
    // ──────────────────────────────────────────────

    /**
     * @return array{ok:bool, errors:list<string>, warnings:list<string>}
     */
    public static function validate_manifest(array $manifest): array {
        $errors = [];

        $format = (string) ($manifest['format'] ?? '');
        if (!in_array($format, [self::FORMAT_SETTINGS, self::FORMAT_CONTENT], true)) {
            $errors[] = __('Unrecognised export format.', 'opentrust');
        }

        if ((int) ($manifest['schema'] ?? 0) !== self::SCHEMA_VERSION) {
            $errors[] = sprintf(
                /* translators: %1$d: schema version found, %2$d: schema version expected */
                __('Schema version mismatch (found %1$d, expected %2$d).', 'opentrust'),
                (int) ($manifest['schema'] ?? 0),
                self::SCHEMA_VERSION
            );
        }

        $their_major = (int) explode('.', (string) ($manifest['opentrust_version'] ?? '0.0.0'))[0];
        $our_major   = (int) explode('.', OPENTRUST_VERSION)[0];
        if ($their_major !== $our_major) {
            $errors[] = sprintf(
                /* translators: %1$s: their version, %2$s: our version */
                __('Plugin major version mismatch (export: %1$s, this site: %2$s).', 'opentrust'),
                (string) ($manifest['opentrust_version'] ?? '?'),
                OPENTRUST_VERSION
            );
        }

        return [
            'ok'       => empty($errors),
            'errors'   => $errors,
            'warnings' => [],
        ];
    }

    // Dry-run: returns per-CPT counts and per-record actions, no DB writes.
    public static function preview_import(array $manifest, string $strategy = self::STRATEGY_SKIP): array {
        $records = $manifest['records'] ?? [];
        $summary = [];
        $detail  = [];

        foreach ($records as $cpt => $recs) {
            $summary[$cpt] = ['create' => 0, 'update' => 0, 'skip' => 0];
            $detail[$cpt]  = [];
            foreach ($recs as $rec) {
                $existing_id = self::find_existing_post($cpt, $rec);
                $action      = self::resolve_action($existing_id, $strategy);
                $summary[$cpt][$action]++;
                $detail[$cpt][] = [
                    'uuid'        => $rec['uuid'] ?? null,
                    'slug'        => (string) ($rec['slug'] ?? ''),
                    'title'       => (string) ($rec['title'] ?? ''),
                    'action'      => $action,
                    'existing_id' => $existing_id,
                ];
            }
        }

        return [
            'summary' => $summary,
            'records' => $detail,
        ];
    }

    // ──────────────────────────────────────────────
    // Public: apply import
    // ──────────────────────────────────────────────

    public static function apply_content_import(array $manifest, string $zip_path, string $strategy): array {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors  = [];

        // Suppress the chat summarizer for the duration of the import, otherwise
        // every imported policy queues a fresh summary generation (real cost).
        $had_summarizer = remove_action('save_post_ot_policy', ['OpenTrust_Chat_Summarizer', 'on_save_post'], 20);

        $media_map = self::sideload_bundled_media($manifest['media'] ?? [], $zip_path, $errors);

        // Policies first so FAQs can resolve their policy refs in one pass.
        $cpt_order = ['ot_policy', 'ot_certification', 'ot_subprocessor', 'ot_data_practice', 'ot_faq'];
        $uuid_to_new_id = [];

        foreach ($cpt_order as $cpt) {
            $recs = $manifest['records'][$cpt] ?? [];
            foreach ($recs as $rec) {
                try {
                    [$action, $post_id] = self::upsert_record($cpt, $rec, $strategy, $media_map, $uuid_to_new_id);
                    if ($action === 'create') $created++;
                    elseif ($action === 'update') $updated++;
                    else $skipped++;

                    if ($post_id > 0 && !empty($rec['uuid'])) {
                        $uuid_to_new_id[$rec['uuid']] = $post_id;
                    }
                } catch (\Throwable $e) {
                    $errors[] = sprintf('%s "%s": %s', $cpt, (string) ($rec['title'] ?? ''), $e->getMessage());
                }
            }
        }

        // Final pass for any cross-CPT refs that landed before their target.
        self::remap_post_references($manifest['records'] ?? [], $uuid_to_new_id);

        if ($had_summarizer) {
            add_action('save_post_ot_policy', ['OpenTrust_Chat_Summarizer', 'on_save_post'], 20, 3);
        }

        OpenTrust::instance()->invalidate_cache();

        return compact('created', 'updated', 'skipped', 'errors');
    }

    public static function apply_settings_import(array $manifest, string $zip_path): array {
        $errors = [];
        $imported = $manifest['settings'] ?? [];

        // Belt-and-suspenders against an older export that carried excluded keys.
        foreach (self::SETTINGS_EXCLUDE as $k) {
            unset($imported[$k]);
        }

        $media_map = self::sideload_bundled_media($manifest['media'] ?? [], $zip_path, $errors);
        foreach (self::SETTINGS_ATTACHMENT_KEYS as $k) {
            $val = $imported[$k] ?? 0;
            if (is_array($val) && isset($val['__media_ref'])) {
                $imported[$k] = (int) ($media_map[$val['__media_ref']] ?? 0);
            }
        }

        $current = OpenTrust::get_settings();
        $merged  = array_merge($current, $imported);

        foreach (self::SETTINGS_EXCLUDE as $k) {
            $merged[$k] = $current[$k] ?? '';
        }

        update_option('opentrust_settings', $merged, false);

        // Slug change → flush rewrites on next admin load.
        if (isset($imported['endpoint_slug']) && $imported['endpoint_slug'] !== ($current['endpoint_slug'] ?? '')) {
            set_transient('opentrust_flush_rewrite', true);
        }

        OpenTrust::instance()->invalidate_cache();

        return ['updated' => count($imported), 'errors' => $errors];
    }

    // ──────────────────────────────────────────────
    // Public: read manifest from an uploaded zip
    // ──────────────────────────────────────────────

    public static function read_zip(string $zip_path): array {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException(__('PHP ZipArchive extension is required.', 'opentrust'));
        }
        $zip = new \ZipArchive();
        if ($zip->open($zip_path) !== true) {
            throw new \RuntimeException(__('Could not open uploaded archive.', 'opentrust'));
        }
        $raw = $zip->getFromName('manifest.json');
        $zip->close();
        if ($raw === false) {
            throw new \RuntimeException(__('Archive is missing manifest.json.', 'opentrust'));
        }
        $manifest = json_decode($raw, true);
        if (!is_array($manifest)) {
            throw new \RuntimeException(__('manifest.json could not be parsed.', 'opentrust'));
        }
        return ['manifest' => $manifest, 'zip_path' => $zip_path];
    }

    // ──────────────────────────────────────────────
    // Internals: build records
    // ──────────────────────────────────────────────

    private static function record_for_post(int $post_id, bool $include_media, array &$media): ?array {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return null;
        }
        $cpt = $post->post_type;
        if (!isset(self::META_KEYS[$cpt])) {
            return null;
        }

        $meta_out = [];
        foreach (self::META_KEYS[$cpt] as $key) {
            $val = get_post_meta($post_id, $key, true);
            if ($val === '' || $val === null || $val === []) {
                continue;
            }
            $meta_out[$key] = $val;
        }

        if ($include_media) {
            foreach (self::ATTACHMENT_META_KEYS[$cpt] ?? [] as $att_key) {
                $att_id = (int) ($meta_out[$att_key] ?? 0);
                if ($att_id > 0) {
                    $ref = self::collect_attachment($att_id, $media);
                    $meta_out[$att_key] = $ref ? ['__media_ref' => $ref] : 0;
                }
            }
        } else {
            foreach (self::ATTACHMENT_META_KEYS[$cpt] ?? [] as $att_key) {
                if (isset($meta_out[$att_key])) {
                    unset($meta_out[$att_key]);
                }
            }
        }

        foreach (self::POST_REF_META_KEYS[$cpt] ?? [] as $meta_key => $_target_cpt) {
            $ref_id = (int) ($meta_out[$meta_key] ?? 0);
            if ($ref_id > 0) {
                $ref_uuid = (string) get_post_meta($ref_id, '_ot_uuid', true);
                if ($ref_uuid !== '') {
                    $meta_out[$meta_key] = ['__post_ref' => $ref_uuid];
                } else {
                    unset($meta_out[$meta_key]); // dangling ref, drop
                }
            }
        }

        return [
            'uuid'       => $meta_out['_ot_uuid'] ?? null,
            'slug'       => $post->post_name,
            'title'      => $post->post_title,
            'content'    => $post->post_content,
            'excerpt'    => $post->post_excerpt,
            'status'     => $post->post_status,
            'menu_order' => $post->menu_order,
            'meta'       => $meta_out,
        ];
    }

    private static function collect_attachment(int $att_id, array &$media): ?string {
        $path = get_attached_file($att_id);
        if (!$path || !file_exists($path)) {
            return null;
        }
        $hash = hash_file('sha256', $path);
        if (!$hash || isset($media[$hash])) {
            return $hash ?: null;
        }
        // Stamp the source so a same-site re-import dedupes by hash instead
        // of re-uploading (and tripping WP's MIME allowlist on SVG, etc.).
        if (!get_post_meta($att_id, '_ot_import_sha256', true)) {
            update_post_meta($att_id, '_ot_import_sha256', $hash);
        }
        $att = get_post($att_id);
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $media[$hash] = [
            'filename'      => $att ? basename($path) : ($hash . ($ext ? '.' . $ext : '')),
            'mime'          => (string) get_post_mime_type($att_id),
            'size'          => filesize($path) ?: 0,
            'sha256'        => $hash,
            'path'          => 'media/' . $hash . ($ext ? '.' . $ext : ''),
            'title'         => $att ? $att->post_title : '',
            'alt'           => (string) get_post_meta($att_id, '_wp_attachment_image_alt', true),
            '__source_path' => $path, // local-only, scrubbed before manifest is written
        ];
        return $hash;
    }

    // ──────────────────────────────────────────────
    // Internals: import
    // ──────────────────────────────────────────────

    private static function find_existing_post(string $cpt, array $rec): int {
        $uuid = (string) ($rec['uuid'] ?? '');
        if ($uuid !== '') {
            $hits = get_posts([
                'post_type'      => $cpt,
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => [
                    ['key' => '_ot_uuid', 'value' => $uuid],
                ],
            ]);
            if (!empty($hits)) return (int) $hits[0];
        }

        $slug = (string) ($rec['slug'] ?? '');
        if ($slug !== '') {
            $found = get_page_by_path($slug, OBJECT, $cpt);
            if ($found instanceof \WP_Post) return $found->ID;
        }

        return 0;
    }

    private static function resolve_action(int $existing_id, string $strategy): string {
        if ($existing_id === 0) {
            return 'create';
        }
        return match ($strategy) {
            self::STRATEGY_OVERWRITE  => 'update',
            self::STRATEGY_CREATE_NEW => 'create',
            default                   => 'skip',
        };
    }

    // Returns [action, post_id]. post_id is 0 if no match found and we skipped.
    private static function upsert_record(string $cpt, array $rec, string $strategy, array $media_map, array $uuid_to_new_id): array {
        $existing_id = self::find_existing_post($cpt, $rec);
        $action      = self::resolve_action($existing_id, $strategy);

        if ($action === 'skip') {
            return ['skip', $existing_id];
        }

        $postarr = [
            'post_type'    => $cpt,
            'post_title'   => (string) ($rec['title']   ?? ''),
            'post_content' => (string) ($rec['content'] ?? ''),
            'post_excerpt' => (string) ($rec['excerpt'] ?? ''),
            'post_status'  => (string) ($rec['status']  ?? 'publish'),
            'menu_order'   => (int)    ($rec['menu_order'] ?? 0),
        ];

        if ($action === 'update') {
            $postarr['ID'] = $existing_id;
            $post_id = wp_update_post($postarr, true);
        } else {
            if ($existing_id > 0 && $strategy === self::STRATEGY_CREATE_NEW) {
                // Suffix avoids the slug clash with the post we just stepped past.
                $postarr['post_name'] = ($rec['slug'] ?? '') . '-import-' . wp_generate_password(4, false);
            } elseif (!empty($rec['slug'])) {
                $postarr['post_name'] = $rec['slug'];
            }
            $post_id = wp_insert_post($postarr, true);
        }

        if (is_wp_error($post_id)) {
            throw new \RuntimeException($post_id->get_error_message());
        }
        $post_id = (int) $post_id;

        $meta = (array) ($rec['meta'] ?? []);

        foreach (self::ATTACHMENT_META_KEYS[$cpt] ?? [] as $att_key) {
            if (isset($meta[$att_key]) && is_array($meta[$att_key]) && isset($meta[$att_key]['__media_ref'])) {
                $hash = (string) $meta[$att_key]['__media_ref'];
                $meta[$att_key] = (int) ($media_map[$hash] ?? 0);
            }
        }

        foreach (self::POST_REF_META_KEYS[$cpt] ?? [] as $meta_key => $_target) {
            if (isset($meta[$meta_key]) && is_array($meta[$meta_key]) && isset($meta[$meta_key]['__post_ref'])) {
                $ref_uuid = (string) $meta[$meta_key]['__post_ref'];
                $meta[$meta_key] = (int) ($uuid_to_new_id[$ref_uuid] ?? 0);
                // Target not imported yet — leave the ref tag for the final pass.
                if ($meta[$meta_key] === 0) {
                    $meta[$meta_key] = ['__post_ref' => $ref_uuid];
                }
            }
        }

        // Fresh UUID on create_new so we don't collide with the existing post.
        if (empty($meta['_ot_uuid']) || $strategy === self::STRATEGY_CREATE_NEW) {
            $meta['_ot_uuid'] = wp_generate_uuid4();
        }

        foreach ($meta as $key => $val) {
            update_post_meta($post_id, $key, $val);
        }

        return [$action, $post_id];
    }

    private static function remap_post_references(array $records, array $uuid_to_new_id): void {
        foreach (self::POST_REF_META_KEYS as $cpt => $keys) {
            foreach ($records[$cpt] ?? [] as $rec) {
                $post_id = self::find_existing_post($cpt, $rec);
                if ($post_id === 0) continue;

                foreach ($keys as $meta_key => $_target) {
                    $stored = get_post_meta($post_id, $meta_key, true);
                    if (!is_array($stored) || !isset($stored['__post_ref'])) continue;

                    $resolved = (int) ($uuid_to_new_id[(string) $stored['__post_ref']] ?? 0);
                    update_post_meta($post_id, $meta_key, $resolved);
                }
            }
        }
    }

    private static function sideload_bundled_media(array $media, string $zip_path, array &$errors): array {
        if (empty($media) || !file_exists($zip_path) || !class_exists('ZipArchive')) {
            return [];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $map = [];
        $zip = new \ZipArchive();
        if ($zip->open($zip_path) !== true) {
            $errors[] = __('Could not reopen archive for media import.', 'opentrust');
            return [];
        }

        foreach ($media as $hash => $entry) {
            $existing = self::find_attachment_by_hash($hash);
            if ($existing > 0) {
                $map[$hash] = $existing;
                continue;
            }

            $contents = $zip->getFromName((string) $entry['path']);
            if ($contents === false) {
                $errors[] = sprintf(
                    /* translators: %s: media path */
                    __('Bundled media missing during import: %s', 'opentrust'),
                    (string) $entry['path']
                );
                continue;
            }

            // Direct write — wp_upload_bits / wp_handle_sideload reject types
            // (SVG, etc.) that were fine on the source. The import-panel
            // warning is the trust gate.
            $upload_dir = wp_upload_dir();
            if (!empty($upload_dir['error'])) {
                $errors[] = (string) $upload_dir['error'];
                continue;
            }
            $safe_name = sanitize_file_name((string) $entry['filename']);
            $filename  = wp_unique_filename($upload_dir['path'], $safe_name);
            $dest_path = $upload_dir['path'] . '/' . $filename;
            if (file_put_contents($dest_path, $contents) === false) {
                $errors[] = sprintf(
                    /* translators: %s: filename */
                    __('Could not write attachment file: %s', 'opentrust'),
                    $filename
                );
                continue;
            }

            $att_id = wp_insert_attachment([
                'post_mime_type' => (string) ($entry['mime'] ?? ''),
                'post_title'     => (string) ($entry['title'] ?? $entry['filename'] ?? ''),
                'post_status'    => 'inherit',
            ], $dest_path);

            if (is_wp_error($att_id) || !$att_id) {
                $errors[] = is_wp_error($att_id) ? $att_id->get_error_message() : __('Could not create attachment.', 'opentrust');
                @unlink($dest_path);
                continue;
            }

            $att_id = (int) $att_id;
            $metadata = wp_generate_attachment_metadata($att_id, $dest_path);
            wp_update_attachment_metadata($att_id, $metadata);

            if (!empty($entry['alt'])) {
                update_post_meta($att_id, '_wp_attachment_image_alt', (string) $entry['alt']);
            }

            update_post_meta($att_id, '_ot_import_sha256', $hash);

            $map[$hash] = $att_id;
        }
        $zip->close();
        return $map;
    }

    private static function find_attachment_by_hash(string $hash): int {
        $hits = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                ['key' => '_ot_import_sha256', 'value' => $hash],
            ],
        ]);
        return !empty($hits) ? (int) $hits[0] : 0;
    }

    // ──────────────────────────────────────────────
    // Internals: misc
    // ──────────────────────────────────────────────

    public static function exportable_summary(): array {
        $out = [];
        foreach (array_keys(self::META_KEYS) as $cpt) {
            $posts = get_posts([
                'post_type'      => $cpt,
                'post_status'    => ['publish', 'draft', 'private'],
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ]);
            $out[$cpt] = array_map(static fn(\WP_Post $p) => [
                'id'     => $p->ID,
                'title'  => $p->post_title ?: '(untitled)',
                'status' => $p->post_status,
                'slug'   => $p->post_name,
            ], $posts);
        }
        return $out;
    }
}
