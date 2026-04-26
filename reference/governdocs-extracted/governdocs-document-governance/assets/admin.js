jQuery(function ($) {
  function setVisibility(val) {
    var isWeb = val === 'web';
    var isDoc = val === 'document';
    var isBoth = val === 'both';

    // Document sections (your file upload + versions groups)
    var $docSections = $('.governdocs-section-doc');

    // Classic editor box (visual/text editor wrapper)
    var $classicEditor = $('#postdivrich');

    // Body state hook (optional)
    //$('body').toggleClass('governdocs-is-document', isDoc);

    // Classic-editor-only show/hide (do not touch title/metaboxes)
    if (isWeb) {
      $docSections.addClass('governdocs-hidden');
      $classicEditor.removeClass('governdocs-hidden').show();
    } else if (isDoc) {
      $docSections.removeClass('governdocs-hidden');
      $classicEditor.addClass('governdocs-hidden').hide();
    } else if (isBoth) {
      $docSections.removeClass('governdocs-hidden');
      $classicEditor.removeClass('governdocs-hidden').show();
    }
  }

  function init() {
    var $field = $('#governdocs_policy_format, #governdocs_meeting_format, #governdocs_report_format');

    // Fallback if CMB2 renders differently
    if (!$field.length) {
      $field = $('[name="governdocs_policy_format"], [name="governdocs_meeting_format"], [name="governdocs_report_format"]');
    }

    if (!$field.length) return;

    // Default to "web" on first load if empty/unset.
    // (Does not overwrite an existing saved value.)
    if (!$field.val()) {
      $field.val('web');
    }

    setVisibility($field.val());

    $field.on('change', function () {
      setVisibility($(this).val());
    });
  }

  init();
});