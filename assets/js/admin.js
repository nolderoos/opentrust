/**
 * OpenTrust admin scripts.
 *
 * Handles the colour picker and media uploader on the settings page,
 * plus any CPT meta-box interactions.
 */
(function ($) {
    'use strict';

    // ── Accent contrast helpers ─────────────────
    // Mirrors OpenTrust::accent_safe_lightness() — clamps HSL lightness just
    // far enough to hit WCAG 4.5:1 contrast against white, preserving hue and
    // saturation so the adjusted colour stays on-brand.
    var ACCENT_TARGET_CONTRAST = 4.5;

    function normalizeHex(hex) {
        if (!hex) return null;
        hex = String(hex).replace('#', '');
        if (hex.length === 3) {
            hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
        }
        if (!/^[0-9a-fA-F]{6}$/.test(hex)) return null;
        return hex;
    }

    function hexToRgb(hex) {
        hex = normalizeHex(hex);
        if (!hex) return null;
        return [
            parseInt(hex.substr(0, 2), 16),
            parseInt(hex.substr(2, 2), 16),
            parseInt(hex.substr(4, 2), 16)
        ];
    }

    function hexToHsl(hex) {
        var rgb = hexToRgb(hex);
        if (!rgb) return null;
        var r = rgb[0] / 255, g = rgb[1] / 255, b = rgb[2] / 255;

        var max = Math.max(r, g, b);
        var min = Math.min(r, g, b);
        var l = (max + min) / 2;
        var d = max - min;
        var h = 0;
        var s = 0;

        if (d !== 0) {
            s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
            switch (max) {
                case r: h = ((g - b) / d + (g < b ? 6 : 0)) / 6; break;
                case g: h = ((b - r) / d + 2) / 6; break;
                default: h = ((r - g) / d + 4) / 6;
            }
        }

        return {
            h: Math.round(h * 360),
            s: Math.round(s * 100),
            l: Math.round(l * 100)
        };
    }

    function hslToRgb(h, s, l) {
        s /= 100;
        l /= 100;
        var c = (1 - Math.abs(2 * l - 1)) * s;
        var x = c * (1 - Math.abs(((h / 60) % 2) - 1));
        var m = l - c / 2;
        var r = 0, g = 0, b = 0;
        if (h < 60)      { r = c; g = x; }
        else if (h < 120){ r = x; g = c; }
        else if (h < 180){ g = c; b = x; }
        else if (h < 240){ g = x; b = c; }
        else if (h < 300){ r = x; b = c; }
        else             { r = c; b = x; }
        return [
            Math.round((r + m) * 255),
            Math.round((g + m) * 255),
            Math.round((b + m) * 255)
        ];
    }

    function rgbToHex(rgb) {
        var toHex = function (v) {
            var hx = v.toString(16);
            return hx.length === 1 ? '0' + hx : hx;
        };
        return '#' + toHex(rgb[0]) + toHex(rgb[1]) + toHex(rgb[2]);
    }

    function relativeLuminance(rgb) {
        var chan = function (c) {
            var v = c / 255;
            return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        };
        return 0.2126 * chan(rgb[0]) + 0.7152 * chan(rgb[1]) + 0.0722 * chan(rgb[2]);
    }

    function contrastVsWhite(rgb) {
        return 1.05 / (relativeLuminance(rgb) + 0.05);
    }

    // Returns { safeL, adjustedHex } — safeL equals the original L when the
    // colour already passes 4.5:1 against white.
    function accentSafe(hex) {
        var rgb = hexToRgb(hex);
        var hsl = hexToHsl(hex);
        if (!rgb || !hsl) return null;

        if (contrastVsWhite(rgb) >= ACCENT_TARGET_CONTRAST) {
            return { safeL: hsl.l, adjustedHex: rgbToHex(rgb), adjusted: false };
        }

        for (var l = hsl.l; l >= 0; l--) {
            var candidate = hslToRgb(hsl.h, hsl.s, l);
            if (contrastVsWhite(candidate) >= ACCENT_TARGET_CONTRAST) {
                return { safeL: l, adjustedHex: rgbToHex(candidate), adjusted: true };
            }
        }
        return { safeL: 0, adjustedHex: '#000000', adjusted: true };
    }

    function updateAccentWarning(hex) {
        var $warning = $('#opentrust-accent-warning');
        if (!$warning.length) return;

        var result = accentSafe(hex);
        // Colour already meets 4.5:1 — nothing to warn about. The override
        // checkbox state is intentionally preserved so the user's preference
        // survives swapping colours back and forth.
        if (!result || !result.adjusted) {
            $warning.attr('hidden', true);
            return;
        }

        var chosenHex = (String(hex).charAt(0) === '#' ? String(hex) : '#' + hex).toUpperCase();
        var adjustedHex = result.adjustedHex.toUpperCase();

        $warning.find('.ot-accent-warning__swatch--chosen').css('background', chosenHex);
        $warning.find('.ot-accent-warning__swatch--adjusted').css('background', adjustedHex);
        $warning.find('.ot-accent-warning__hex--chosen').text(chosenHex);
        $warning.find('.ot-accent-warning__hex--adjusted').text(adjustedHex);
        $warning.removeAttr('hidden');
    }

    $(function () {
        // ── Colour picker ──────────────────────────
        var $accentInput  = $('#opentrust_accent_color');
        var $forceExact   = $('#opentrust_accent_force_exact');
        var $accentWarning = $('#opentrust-accent-warning');

        $('.ot-color-picker').wpColorPicker({
            change: function (event, ui) {
                // wpColorPicker fires `change` before the input is updated,
                // so defer a tick before reading the value.
                setTimeout(function () {
                    updateAccentWarning(ui.color.toString());
                }, 0);
            },
            clear: function () {
                setTimeout(function () {
                    updateAccentWarning($accentInput.val());
                }, 0);
            }
        });

        // Live-toggle the override class so the warning tone updates without
        // a page reload. The actual clamping still happens server-side — the
        // class only drives the admin copy/colour swap.
        $forceExact.on('change', function () {
            $accentWarning.toggleClass('ot-accent-warning--override', this.checked);
        });

        // Initial check on page load.
        if ($accentInput.length) {
            updateAccentWarning($accentInput.val());
        }

        // ── Media uploader (logo + avatar) ─────────
        $('[data-ot-media-field]').each(function () {
            var $field     = $(this);
            var $input     = $field.find('[data-ot-media-input]');
            var $preview   = $field.find('.ot-logo-preview');
            var $uploadBtn = $field.find('[data-ot-media-upload]');
            var $removeBtn = $field.find('[data-ot-media-remove]');
            var frame;

            $uploadBtn.on('click', function (e) {
                e.preventDefault();

                if (!frame) {
                    frame = wp.media({
                        title:    $uploadBtn.text(),
                        multiple: false,
                        library:  { type: 'image' },
                        button:   { text: $uploadBtn.text() }
                    });

                    frame.on('select', function () {
                        var attachment = frame.state().get('selection').first().toJSON();
                        var src = (attachment.sizes && attachment.sizes.medium)
                            ? attachment.sizes.medium.url
                            : attachment.url;
                        $input.val(attachment.id);
                        $preview.find('img').attr('src', src);
                        $preview.show();
                        $removeBtn.show();
                    });
                }

                frame.open();
            });

            $removeBtn.on('click', function (e) {
                e.preventDefault();
                $input.val('0');
                $preview.hide();
                $removeBtn.hide();
            });
        });

        // ── Certification badge uploader ───────────
        $('.ot-upload-badge').on('click', function (e) {
            e.preventDefault();
            var $btn   = $(this);
            var $input = $btn.siblings('.ot-badge-input');
            var $img   = $btn.siblings('.ot-badge-preview');
            var $rm    = $btn.siblings('.ot-remove-badge');

            var adminI18n = (window.OpenTrustAdmin && window.OpenTrustAdmin.i18n) || {};
            var frame = wp.media({
                title:    adminI18n.selectBadgeImage || 'Select Badge Image',
                multiple: false,
                library:  { type: 'image' },
                button:   { text: adminI18n.useAsBadge || 'Use as Badge' }
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                $input.val(attachment.id);
                $img.attr('src', attachment.sizes.thumbnail
                    ? attachment.sizes.thumbnail.url
                    : attachment.url).show();
                $rm.show();
            });

            frame.open();
        });

        $(document).on('click', '.ot-remove-badge', function (e) {
            e.preventDefault();
            $(this).siblings('.ot-badge-input').val('0');
            $(this).siblings('.ot-badge-preview').hide();
            $(this).hide();
        });

        // ── Certification artifact uploader (PDF report / certificate) ──
        // Scoped to the parent meta field so the selectors don't collide
        // with the badge uploader above. Accepts any attachment type.
        $(document).on('click', '.ot-upload-artifact', function (e) {
            e.preventDefault();
            var $btn     = $(this);
            var $wrap    = $btn.closest('[data-ot-cert-artifact]');
            var $input   = $wrap.find('.ot-artifact-input');
            var $preview = $wrap.find('.ot-artifact-preview');
            var $link    = $preview.find('.ot-artifact-preview__link');
            var $remove  = $wrap.find('.ot-remove-artifact');

            var adminI18n = (window.OpenTrustAdmin && window.OpenTrustAdmin.i18n) || {};
            var frame = wp.media({
                title:    adminI18n.selectArtifact || 'Select Proof Artifact',
                multiple: false,
                button:   { text: adminI18n.useAsArtifact || 'Use This File' }
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                $input.val(attachment.id);
                $link.attr('href', attachment.url).text(attachment.title || attachment.filename || attachment.url);
                $preview.show();
                $remove.show();
                $btn.text(adminI18n.replaceArtifact || 'Replace File');
            });

            frame.open();
        });

        $(document).on('click', '.ot-remove-artifact', function (e) {
            e.preventDefault();
            var $wrap = $(this).closest('[data-ot-cert-artifact]');
            $wrap.find('.ot-artifact-input').val('0');
            $wrap.find('.ot-artifact-preview').hide();
            var adminI18n = (window.OpenTrustAdmin && window.OpenTrustAdmin.i18n) || {};
            $wrap.find('.ot-upload-artifact').text(adminI18n.uploadArtifact || 'Upload File');
            $(this).hide();
        });

        // ── Policy PDF attachment uploader ──
        // Mirrors the certification artifact uploader, scoped via the
        // [data-ot-policy-attachment] wrapper so the selectors don't collide.
        $(document).on('click', '.ot-upload-policy-attachment', function (e) {
            e.preventDefault();
            var $btn     = $(this);
            var $wrap    = $btn.closest('[data-ot-policy-attachment]');
            var $input   = $wrap.find('.ot-policy-attachment-input');
            var $preview = $wrap.find('.ot-artifact-preview');
            var $link    = $preview.find('.ot-artifact-preview__link');
            var $remove  = $wrap.find('.ot-remove-policy-attachment');

            var adminI18n = (window.OpenTrustAdmin && window.OpenTrustAdmin.i18n) || {};
            var frame = wp.media({
                title:    adminI18n.selectPolicyPdf || 'Select Policy PDF',
                multiple: false,
                library:  { type: 'application/pdf' },
                button:   { text: adminI18n.usePolicyPdf || 'Use This PDF' }
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                $input.val(attachment.id);
                $link.attr('href', attachment.url).text(attachment.title || attachment.filename || attachment.url);
                $preview.show();
                $remove.show();
                $btn.text(adminI18n.replacePolicyPdf || 'Replace PDF');
            });

            frame.open();
        });

        $(document).on('click', '.ot-remove-policy-attachment', function (e) {
            e.preventDefault();
            var $wrap = $(this).closest('[data-ot-policy-attachment]');
            $wrap.find('.ot-policy-attachment-input').val('0');
            $wrap.find('.ot-artifact-preview').hide();
            var adminI18n = (window.OpenTrustAdmin && window.OpenTrustAdmin.i18n) || {};
            $wrap.find('.ot-upload-policy-attachment').text(adminI18n.uploadPolicyPdf || 'Upload PDF');
            $(this).hide();
        });

        // ── Tag input for Data Practice repeaters ──
        function otReindexTags($container) {
            var fieldName = $container.data('ot-tags');
            $container.find('.ot-tag').each(function (i) {
                $(this).find('input[type="hidden"]').attr('name', fieldName + '[' + i + '][name]');
            });
        }

        function otAddTag($container, value) {
            var text = $.trim(value);
            if (!text) return;

            // Prevent duplicates.
            var exists = false;
            $container.find('.ot-tag__text').each(function () {
                if ($(this).text().toLowerCase() === text.toLowerCase()) {
                    exists = true;
                    return false;
                }
            });
            if (exists) return;

            var fieldName = $container.data('ot-tags');
            var idx = $container.find('.ot-tag').length;
            var $tag = $(
                '<span class="ot-tag">' +
                    '<span class="ot-tag__text"></span>' +
                    '<input type="hidden" name="' + fieldName + '[' + idx + '][name]" value="">' +
                    '<button type="button" class="ot-tag__remove" aria-label="Remove">&times;</button>' +
                '</span>'
            );
            $tag.find('.ot-tag__text').text(text);
            $tag.find('input').val(text);
            $container.find('.ot-tags__input').before($tag);
        }

        // Click container to focus input.
        $(document).on('click', '.ot-tags', function (e) {
            if ($(e.target).hasClass('ot-tags')) {
                $(this).find('.ot-tags__input').trigger('focus');
            }
        });

        // Add tag on Enter or comma.
        $(document).on('keydown', '.ot-tags__input', function (e) {
            var $input = $(this);
            var $container = $input.closest('.ot-tags');

            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                otAddTag($container, $input.val());
                $input.val('');
            }

            // Backspace on empty input removes last tag.
            if (e.key === 'Backspace' && $input.val() === '') {
                $container.find('.ot-tag').last().remove();
                otReindexTags($container);
            }
        });

        // Also add on blur (if text remains).
        $(document).on('blur', '.ot-tags__input', function () {
            var $input = $(this);
            var $container = $input.closest('.ot-tags');
            if ($.trim($input.val())) {
                otAddTag($container, $input.val());
                $input.val('');
            }
        });

        // Remove tag.
        $(document).on('click', '.ot-tag__remove', function (e) {
            e.preventDefault();
            var $container = $(this).closest('.ot-tags');
            $(this).closest('.ot-tag').remove();
            otReindexTags($container);
        });
    });
})(jQuery);

/**
 * Catalog typeahead (subprocessor / data practice new-post screen).
 *
 * Transforms the post title field into a combobox that searches a bundled
 * catalog and, on selection, prefills the meta box. Two field tiers:
 *   - `fields`        → facts, green highlight, "Auto-filled" chip
 *   - `fields_review` → templates, amber highlight, "Verify" chip + notice
 *
 * All state is client-side. The bundle at window.OpenTrustCatalog is injected
 * only on post-new.php for ot_subprocessor / ot_data_practice screens.
 */
(function () {
    'use strict';

    if (!window.OpenTrustCatalog) return;

    var data = window.OpenTrustCatalog;
    var entries = (data.catalog && data.catalog.entries) || [];
    if (!entries.length) return;

    var I18N = data.i18n || {};

    var titleInput = document.getElementById('title');
    if (!titleInput) return;

    // Meta keys (with leading underscore) map to DOM ids by stripping the
    // leading "_ot_" and re-prefixing with "ot_".
    var metaKeyToDomId = function (metaKey) {
        return metaKey.replace(/^_ot_/, 'ot_');
    };

    var normalize = function (value) {
        return String(value || '').toLowerCase().replace(/[^a-z0-9]/g, '');
    };

    // ── Build dropdown DOM ───────────────────────────────────────
    var titleWrap = document.getElementById('titlewrap') || titleInput.parentNode;
    var shell = document.createElement('div');
    shell.className = 'ot-typeahead';

    var panel = document.createElement('ul');
    panel.className = 'ot-typeahead__panel';
    panel.setAttribute('role', 'listbox');
    panel.id = 'ot-typeahead-listbox';
    panel.setAttribute('aria-label', I18N.suggestions || 'Catalog suggestions');
    panel.hidden = true;

    shell.appendChild(panel);
    titleWrap.parentNode.insertBefore(shell, titleWrap.nextSibling);

    titleInput.setAttribute('role', 'combobox');
    titleInput.setAttribute('aria-autocomplete', 'list');
    titleInput.setAttribute('aria-expanded', 'false');
    titleInput.setAttribute('aria-controls', panel.id);
    // Suppress Safari and mobile browser heuristic autofill. Safari ignores
    // `autocomplete="off"` when it thinks a field asks for a personal name,
    // so we also disable autocorrect/capitalization/spellcheck and set the
    // name hint to one it won't match against the address book.
    titleInput.setAttribute('autocomplete', 'off');
    titleInput.setAttribute('autocorrect', 'off');
    titleInput.setAttribute('autocapitalize', 'off');
    titleInput.setAttribute('spellcheck', 'false');
    titleInput.setAttribute('data-1p-ignore', 'true');
    titleInput.setAttribute('data-lpignore', 'true');
    titleInput.setAttribute('data-form-type', 'other');

    // ── Match logic ──────────────────────────────────────────────
    var MAX_RESULTS = 6;

    var search = function (raw) {
        var q = normalize(raw);
        if (q.length < 2) return [];
        var results = [];
        for (var i = 0; i < entries.length; i++) {
            var entry = entries[i];
            var hay = entry.haystack || '';
            // Score: prefix match in any token = 2, substring = 1, none = 0.
            var score = 0;
            var tokens = hay.split('|');
            for (var t = 0; t < tokens.length; t++) {
                if (!tokens[t]) continue;
                if (tokens[t].indexOf(q) === 0) { score = Math.max(score, 2); break; }
                if (tokens[t].indexOf(q) !== -1) { score = Math.max(score, 1); }
            }
            if (score > 0) {
                results.push({ entry: entry, score: score });
            }
        }
        results.sort(function (a, b) {
            if (b.score !== a.score) return b.score - a.score;
            return a.entry.name.localeCompare(b.entry.name);
        });
        return results.slice(0, MAX_RESULTS).map(function (r) { return r.entry; });
    };

    // ── Render dropdown ──────────────────────────────────────────
    var activeIndex = -1;
    var currentResults = [];
    // Set while applyEntry is running so the synthetic `input` event it
    // fires on the title field does not re-trigger the search and re-open
    // the dropdown with the row we just selected.
    var isApplying = false;

    var openPanel = function () {
        if (!currentResults.length) return closePanel();
        panel.hidden = false;
        titleInput.setAttribute('aria-expanded', 'true');
    };

    var closePanel = function () {
        panel.hidden = true;
        titleInput.setAttribute('aria-expanded', 'false');
        titleInput.removeAttribute('aria-activedescendant');
        activeIndex = -1;
    };

    var renderPanel = function (results) {
        currentResults = results;
        panel.innerHTML = '';
        if (!results.length) {
            // No-match hint, a single non-selectable footer row so the user
            // knows manual entry still works.
            var hint = document.createElement('li');
            hint.className = 'ot-typeahead__hint';
            hint.textContent = I18N.noMatchHint || 'No match in catalog, just keep typing to add manually.';
            panel.appendChild(hint);
            openPanel();
            return;
        }
        var hintText = I18N.optionHint || 'Click to autofill';
        for (var i = 0; i < results.length; i++) {
            var r = results[i];
            var li = document.createElement('li');
            li.className = 'ot-typeahead__option';
            li.id = 'ot-ta-opt-' + i;
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', 'false');
            li.dataset.index = String(i);

            var nameEl = document.createElement('span');
            nameEl.className = 'ot-typeahead__option-name';
            nameEl.textContent = r.name;

            var hintEl = document.createElement('span');
            hintEl.className = 'ot-typeahead__option-hint';
            hintEl.textContent = hintText;

            li.appendChild(nameEl);
            li.appendChild(hintEl);
            panel.appendChild(li);
        }
        activeIndex = -1;
        openPanel();
    };

    var setActive = function (idx) {
        var opts = panel.querySelectorAll('.ot-typeahead__option');
        if (!opts.length) return;
        if (idx < 0) idx = opts.length - 1;
        if (idx >= opts.length) idx = 0;
        for (var i = 0; i < opts.length; i++) {
            opts[i].classList.toggle('is-active', i === idx);
            opts[i].setAttribute('aria-selected', i === idx ? 'true' : 'false');
        }
        activeIndex = idx;
        titleInput.setAttribute('aria-activedescendant', opts[idx].id);
        opts[idx].scrollIntoView({ block: 'nearest' });
    };

    // ── Apply selection to the meta box ──────────────────────────
    var markPrefilled = function (fieldEl, tier) {
        // Apply a single-line colored border to the field wrapper, append a
        // short helper message in the matching tint below it, and re-trigger
        // the flash animation so the user sees what changed.
        var wrap = fieldEl.closest('.ot-meta-field');
        if (!wrap) return;

        var existing = wrap.querySelector('.ot-typeahead-help');
        if (existing) existing.remove();

        // Remove-then-add forces the browser to replay the flash keyframes.
        wrap.classList.remove('ot-typeahead-filled', 'is-fact', 'is-review');
        // Force a reflow so the next class add re-starts the animation.
        void wrap.offsetWidth;
        wrap.classList.add('ot-typeahead-filled', tier === 'review' ? 'is-review' : 'is-fact');
        fieldEl.dataset.otPrefilled = tier;

        var help = document.createElement('p');
        help.className = 'ot-typeahead-help' + (tier === 'review' ? ' is-review' : '');
        help.textContent = tier === 'review'
            ? (I18N.helpReview || 'Auto-filled template, please verify this matches how you use this service.')
            : (I18N.helpFact || 'Auto-filled from catalog, you may want to verify this.');

        wrap.appendChild(help);
    };

    // Wipe every field that was previously filled by a catalog entry so
    // that a new selection produces a clean replacement. User-typed values
    // (without the data-ot-prefilled marker) are left untouched.
    var clearAllPrefilled = function () {
        var filled = document.querySelectorAll('[data-ot-prefilled]');
        for (var i = 0; i < filled.length; i++) {
            var el = filled[i];
            if (el.tagName === 'TEXTAREA' || el.tagName === 'INPUT') {
                el.value = '';
            } else if (el.tagName === 'SELECT') {
                el.selectedIndex = 0;
            } else if (el.classList && el.classList.contains('ot-tags')) {
                var tags = el.querySelectorAll('.ot-tag');
                for (var t = 0; t < tags.length; t++) tags[t].remove();
            }
            delete el.dataset.otPrefilled;
            var wrap = el.closest('.ot-meta-field');
            if (wrap) {
                wrap.classList.remove('ot-typeahead-filled', 'is-fact', 'is-review');
                var help = wrap.querySelector('.ot-typeahead-help');
                if (help) help.remove();
            }
        }
    };

    // Add a tag to an `.ot-tags` container, matching the existing DOM shape
    // in assets/js/admin.js (fieldName[i][name]).
    var addTagToContainer = function (container, value) {
        var fieldName = container.getAttribute('data-ot-tags');
        if (!fieldName) return;
        var idx = container.querySelectorAll('.ot-tag').length;
        var tag = document.createElement('span');
        tag.className = 'ot-tag';
        var text = document.createElement('span');
        text.className = 'ot-tag__text';
        text.textContent = value;
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = fieldName + '[' + idx + '][name]';
        hidden.value = value;
        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'ot-tag__remove';
        remove.setAttribute('aria-label', 'Remove');
        remove.innerHTML = '&times;';
        tag.appendChild(text);
        tag.appendChild(hidden);
        tag.appendChild(remove);
        var input = container.querySelector('.ot-tags__input');
        if (input) {
            container.insertBefore(tag, input);
        } else {
            container.appendChild(tag);
        }
    };

    var applyField = function (metaKey, value, tier) {
        var domId = metaKeyToDomId(metaKey);

        // Tag-array fields render as `.ot-tags[data-ot-tags="<postName>"]`.
        // Locate by the data attribute — there is no element with id=domId.
        var tagContainer = document.querySelector('.ot-tags[data-ot-tags="' + domId + '"]');
        if (tagContainer) {
            if (!Array.isArray(value)) return;
            // Respect any existing user-typed tags; the catalog-prefilled
            // ones were already wiped in clearAllPrefilled().
            if (tagContainer.querySelectorAll('.ot-tag').length > 0) return;
            for (var i = 0; i < value.length; i++) {
                addTagToContainer(tagContainer, String(value[i]));
            }
            markPrefilled(tagContainer, tier);
            return;
        }

        var el = document.getElementById(domId);
        if (!el) return;

        // Don't stomp values the user typed themselves. Previous catalog
        // prefills were cleared in clearAllPrefilled() before we got here,
        // so anything still present is user input.
        if (el.tagName === 'SELECT') {
            if (el.value && el.value !== '') return;
            var hasOption = false;
            for (var j = 0; j < el.options.length; j++) {
                if (el.options[j].value === String(value)) { hasOption = true; break; }
            }
            if (!hasOption) return;
            el.value = String(value);
        } else {
            if (el.value && el.value.trim() !== '') return;
            el.value = String(value);
        }
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
        markPrefilled(el, tier);
    };

    var applyEntry = function (entry) {
        isApplying = true;
        clearAllPrefilled();

        titleInput.value = entry.name;
        titleInput.dispatchEvent(new Event('input', { bubbles: true }));

        var facts = entry.fields || {};
        for (var key in facts) {
            if (Object.prototype.hasOwnProperty.call(facts, key)) {
                applyField(key, facts[key], 'fact');
            }
        }

        var review = entry.fields_review || {};
        for (var rkey in review) {
            if (Object.prototype.hasOwnProperty.call(review, rkey)) {
                applyField(rkey, review[rkey], 'review');
            }
        }

        closePanel();
        titleInput.blur();
        isApplying = false;
    };

    // ── Event wiring ─────────────────────────────────────────────
    var debounceId = 0;
    titleInput.addEventListener('input', function () {
        if (isApplying) return;
        window.clearTimeout(debounceId);
        debounceId = window.setTimeout(function () {
            renderPanel(search(titleInput.value));
        }, 80);
    });

    titleInput.addEventListener('focus', function () {
        if (titleInput.value.length >= 2) {
            renderPanel(search(titleInput.value));
        }
    });

    titleInput.addEventListener('keydown', function (e) {
        if (panel.hidden) {
            if (e.key === 'ArrowDown' && currentResults.length) {
                e.preventDefault();
                openPanel();
                setActive(0);
            }
            return;
        }
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive(activeIndex + 1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive(activeIndex - 1);
        } else if (e.key === 'Enter') {
            if (activeIndex >= 0 && currentResults[activeIndex]) {
                e.preventDefault();
                applyEntry(currentResults[activeIndex]);
            }
        } else if (e.key === 'Escape') {
            closePanel();
        } else if (e.key === 'Tab') {
            closePanel();
        }
    });

    panel.addEventListener('mousedown', function (e) {
        // mousedown (not click) so it fires before the title's blur
        var opt = e.target.closest('.ot-typeahead__option');
        if (!opt) return;
        e.preventDefault();
        var idx = parseInt(opt.dataset.index || '0', 10);
        if (currentResults[idx]) applyEntry(currentResults[idx]);
    });

    document.addEventListener('click', function (e) {
        if (e.target === titleInput) return;
        if (panel.contains(e.target)) return;
        closePanel();
    });
})();

/**
 * Certification type toggle.
 *
 * Shows or hides the "certified only" fields (issuing body, status, dates)
 * based on the `_ot_cert_type` select. A compliant certification has no
 * audit and therefore no auditor, no issue date, no expiry, and no status
 * state machine, so those fields are hidden from the form entirely.
 * Hidden inputs still submit their values, which matches the "keep data
 * when flipping back" behavior we want.
 */
(function () {
    'use strict';

    var select = document.querySelector('[data-ot-cert-type]');
    if (!select) return;

    var certifiedOnlyFields = document.querySelectorAll('[data-ot-cert-certified-only]');
    if (!certifiedOnlyFields.length) return;

    var apply = function () {
        var isCompliant = select.value === 'compliant';
        for (var i = 0; i < certifiedOnlyFields.length; i++) {
            certifiedOnlyFields[i].style.display = isCompliant ? 'none' : '';
        }
    };

    select.addEventListener('change', apply);
    apply();
})();

