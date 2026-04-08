/**
 * OpenTrust admin scripts.
 *
 * Handles the colour picker and media uploader on the settings page,
 * plus any CPT meta-box interactions.
 */
(function ($) {
    'use strict';

    $(function () {
        // ── Colour picker ──────────────────────────
        $('.ot-color-picker').wpColorPicker();

        // ── Logo uploader ──────────────────────────
        var $logoInput   = $('#opentrust_logo_id');
        var $preview     = $('.ot-logo-preview');
        var $removeBtn   = $('.ot-remove-logo');

        $('.ot-upload-logo').on('click', function (e) {
            e.preventDefault();

            var frame = wp.media({
                title:    'Select Company Logo',
                multiple: false,
                library:  { type: 'image' },
                button:   { text: 'Use as Logo' }
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                $logoInput.val(attachment.id);
                $preview.find('img').attr('src', attachment.sizes.medium
                    ? attachment.sizes.medium.url
                    : attachment.url);
                $preview.show();
                $removeBtn.show();
            });

            frame.open();
        });

        $removeBtn.on('click', function (e) {
            e.preventDefault();
            $logoInput.val('0');
            $preview.hide();
            $(this).hide();
        });

        // ── Certification badge uploader ───────────
        $('.ot-upload-badge').on('click', function (e) {
            e.preventDefault();
            var $btn   = $(this);
            var $input = $btn.siblings('.ot-badge-input');
            var $img   = $btn.siblings('.ot-badge-preview');
            var $rm    = $btn.siblings('.ot-remove-badge');

            var frame = wp.media({
                title:    'Select Badge Image',
                multiple: false,
                library:  { type: 'image' },
                button:   { text: 'Use as Badge' }
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
    });
})(jQuery);
