(function (wp) {
  const { registerPlugin } = wp.plugins;
  const { PluginPrePublishPanel } = wp.editPost;
  const { useState, useEffect, useCallback, createElement: el, Fragment } = wp.element;
  const {
    Button, Spinner, Notice, SelectControl,
    TextControl, TextareaControl, Flex, FlexItem,
    Icon, ToggleControl, CheckboxControl,
  } = wp.components;
  const { useSelect, useDispatch } = wp.data;
  const { __ } = wp.i18n;
  const apiFetch = wp.apiFetch;

  const config = window.wpiFeaturedImageAIConfig || {};
  const defaults = config.defaults || {};

  var STYLE_OPTIONS = [
    { label: __('Photo-realistic', 'wp-intelligence'), value: 'photo-realistic' },
    { label: __('Illustration', 'wp-intelligence'),    value: 'illustration' },
    { label: __('Flat Design', 'wp-intelligence'),     value: 'flat-design' },
    { label: __('Abstract', 'wp-intelligence'),        value: 'abstract' },
    { label: __('3D Render', 'wp-intelligence'),       value: '3d-render' },
    { label: __('Watercolor', 'wp-intelligence'),      value: 'watercolor' },
    { label: __('Minimal', 'wp-intelligence'),         value: 'minimal' },
    { label: __('Cinematic', 'wp-intelligence'),       value: 'cinematic' },
    { label: __('Digital Art', 'wp-intelligence'),     value: 'digital-art' },
  ];

  var RATIO_OPTIONS = [
    { label: __('Landscape (1792x1024)', 'wp-intelligence'), value: 'landscape' },
    { label: __('Portrait (1024x1792)', 'wp-intelligence'),  value: 'portrait' },
    { label: __('Square (1024x1024)', 'wp-intelligence'),    value: 'square' },
  ];

  /* ──────────────────────────────────────────────
   *  Featured Image AI Panel
   * ────────────────────────────────────────────── */

  function FeaturedImageAIPanel() {
    var editorData = useSelect(function (select) {
      var editor = select('core/editor');
      var post = editor.getCurrentPost();
      var featMediaId = editor.getEditedPostAttribute('featured_media');
      var thumbUrl = '';

      if (featMediaId) {
        var media = select('core').getMedia(featMediaId);
        if (media && media.source_url) {
          thumbUrl = media.source_url;
        }
      }

      return {
        postId: post.id,
        postTitle: editor.getEditedPostAttribute('title') || '',
        hasFeaturedImage: !!featMediaId,
        featuredImageUrl: thumbUrl,
      };
    }, []);

    var postId = editorData.postId;
    var postTitle = editorData.postTitle;
    var hasFeaturedImage = editorData.hasFeaturedImage;

    var dispatchers = useDispatch('core/editor');
    var editPost = dispatchers.editPost;
    var noticeDispatchers = useDispatch('core/notices');
    var createSuccessNotice = noticeDispatchers.createSuccessNotice;
    var createErrorNotice = noticeDispatchers.createErrorNotice;

    var overlayDefault = (config.overlay && config.overlay.show_title === '1');

    var _gen = useState(false);         var generating = _gen[0];         var setGenerating = _gen[1];
    var _img = useState(null);          var generatedImage = _img[0];     var setGeneratedImage = _img[1];
    var _opt = useState(false);         var showOptions = _opt[0];        var setShowOptions = _opt[1];
    var _fb  = useState([]);            var fallbacks = _fb[0];           var setFallbacks = _fb[1];
    var _fbl = useState(false);         var fallbackLoaded = _fbl[0];     var setFallbackLoaded = _fbl[1];
    var _err = useState('');            var error = _err[0];              var setError = _err[1];

    var _sty = useState(defaults.image_style || 'photo-realistic');  var imageStyle = _sty[0];     var setImageStyle = _sty[1];
    var _rat = useState(defaults.aspect_ratio || 'landscape');       var aspectRatio = _rat[0];    var setAspectRatio = _rat[1];
    var _brd = useState(defaults.brand_colors || '');                var brandColors = _brd[0];    var setBrandColors = _brd[1];
    var _cus = useState(defaults.custom_instructions || '');         var customInstr = _cus[0];    var setCustomInstr = _cus[1];
    var _ovr = useState(overlayDefault);                             var applyOverlay = _ovr[0];   var setApplyOverlay = _ovr[1];

    useEffect(function () {
      if (!postId || fallbackLoaded) return;
      apiFetch({
        path: '/' + config.restNamespace + '/detect-fallback-image?post_id=' + postId,
        method: 'GET',
      }).then(function (res) {
        setFallbacks(res.fallbacks || []);
        setFallbackLoaded(true);
      }).catch(function () {
        setFallbackLoaded(true);
      });
    }, [postId, fallbackLoaded]);

    var handleGenerate = useCallback(function () {
      if (!postId || generating) return;
      setGenerating(true);
      setError('');
      setGeneratedImage(null);

      apiFetch({
        path: '/' + config.restNamespace + '/generate-featured-image',
        method: 'POST',
        data: {
          post_id: postId,
          image_style: imageStyle,
          aspect_ratio: aspectRatio,
          brand_colors: brandColors,
          custom_instructions: customInstr,
          apply_overlay: applyOverlay ? 'yes' : 'no',
        },
      }).then(function (res) {
        setGenerating(false);
        if (res.success) {
          setGeneratedImage(res);
          editPost({ featured_media: res.attachment_id });
          var msg = res.og_set
            ? __('OG image generated, set as featured image and social sharing image.', 'wp-intelligence')
            : __('Featured image generated and set.', 'wp-intelligence');
          createSuccessNotice(msg, { type: 'snackbar' });
        }
      }).catch(function (err) {
        setGenerating(false);
        var msg = (err && err.message) || __('Failed to generate image.', 'wp-intelligence');
        setError(msg);
        createErrorNotice(msg, { type: 'snackbar' });
      });
    }, [postId, generating, imageStyle, aspectRatio, brandColors, customInstr, applyOverlay]);

    var handleRegenerate = useCallback(function () {
      setGeneratedImage(null);
      handleGenerate();
    }, [handleGenerate]);

    if (!config.providerReady) {
      return el(PluginPrePublishPanel, {
        title: __('Featured Image AI', 'wp-intelligence'),
        icon: 'format-image',
        initialOpen: true,
      },
        el(Notice, { status: 'warning', isDismissible: false },
          el('p', null, config.readiness ? config.readiness.message : __('AI provider not configured.', 'wp-intelligence'))
        )
      );
    }

    if (hasFeaturedImage && !generatedImage) {
      return el(PluginPrePublishPanel, {
        title: __('Featured Image AI', 'wp-intelligence'),
        icon: 'format-image',
        initialOpen: false,
      },
        el('div', { className: 'wpi-fia-has-image' },
          el(Icon, { icon: 'yes-alt', size: 20 }),
          el('span', null, __('Featured image is set.', 'wp-intelligence'))
        )
      );
    }

    return el(PluginPrePublishPanel, {
      title: hasFeaturedImage
        ? __('Featured Image AI', 'wp-intelligence')
        : __('No Featured Image', 'wp-intelligence'),
      icon: 'format-image',
      initialOpen: !hasFeaturedImage,
    },
      el('div', { className: 'wpi-fia-panel' },

        !hasFeaturedImage && !generatedImage && !generating && el(Fragment, null,
          el('p', { className: 'wpi-fia-prompt-text' },
            __('This post has no featured image. Would you like to generate one with AI?', 'wp-intelligence')
          ),

          fallbacks.length > 0 && el('div', { className: 'wpi-fia-fallbacks' },
            el('p', { className: 'wpi-fia-fallback-label' },
              __('Detected fallback images:', 'wp-intelligence')
            ),
            fallbacks.map(function (fb, i) {
              return el('div', { className: 'wpi-fia-fallback-item', key: i },
                el('img', { src: fb.url, alt: fb.label, className: 'wpi-fia-fallback-thumb' }),
                el('div', { className: 'wpi-fia-fallback-meta' },
                  el('span', { className: 'wpi-fia-fallback-source' }, fb.source),
                  el('span', { className: 'wpi-fia-fallback-desc' }, fb.label)
                )
              );
            })
          )
        ),

        error && el(Notice, {
          status: 'error', isDismissible: true,
          onDismiss: function () { setError(''); },
        }, el('p', null, error)),

        generating && el('div', { className: 'wpi-fia-generating' },
          el(Spinner, null),
          el('p', null, __('Generating your featured image...', 'wp-intelligence')),
          el('p', { className: 'wpi-fia-generating-sub' },
            __('AI is analyzing your content and creating an image. This may take 15-30 seconds.', 'wp-intelligence')
          ),
          applyOverlay && el('p', { className: 'wpi-fia-generating-sub' },
            __('Post title will be overlaid onto the image.', 'wp-intelligence')
          )
        ),

        generatedImage && el('div', { className: 'wpi-fia-result' },
          el('p', { className: 'wpi-fia-success-text' },
            generatedImage.og_set
              ? __('OG image set as featured image + social sharing image.', 'wp-intelligence')
              : __('Image generated and set as featured image.', 'wp-intelligence')
          ),
          el('img', {
            src: generatedImage.attachment_url,
            alt: __('Generated featured image', 'wp-intelligence'),
            className: 'wpi-fia-preview',
          }),
          el(Flex, { justify: 'flex-start', gap: 2, className: 'wpi-fia-result-actions' },
            el(FlexItem, null,
              el(Button, {
                variant: 'secondary',
                onClick: handleRegenerate,
                disabled: generating,
                size: 'compact',
              }, __('Regenerate', 'wp-intelligence'))
            )
          )
        ),

        !generating && !generatedImage && el(Fragment, null,

          el(ToggleControl, {
            label: __('Overlay post title on image (OG style)', 'wp-intelligence'),
            checked: applyOverlay,
            onChange: setApplyOverlay,
            help: applyOverlay
              ? __('The post title will be composited onto the generated image for social sharing.', 'wp-intelligence')
              : __('Generate a clean image without text overlay.', 'wp-intelligence'),
            __nextHasNoMarginBottom: true,
          }),

          el('div', { className: 'wpi-fia-actions' },
            el(Button, {
              variant: 'primary',
              onClick: handleGenerate,
              disabled: !postTitle,
              className: 'wpi-fia-generate-btn',
            },
              el(Icon, { icon: 'format-image', size: 18 }),
              applyOverlay
                ? __('Generate OG Image', 'wp-intelligence')
                : __('Generate Featured Image', 'wp-intelligence')
            ),

            el(Button, {
              variant: 'tertiary',
              onClick: function () { setShowOptions(!showOptions); },
              size: 'compact',
              className: 'wpi-fia-options-toggle',
            }, showOptions
              ? __('Hide options', 'wp-intelligence')
              : __('Customize style', 'wp-intelligence')
            )
          ),

          showOptions && el('div', { className: 'wpi-fia-options' },
            el(SelectControl, {
              label: __('Image Style', 'wp-intelligence'),
              value: imageStyle,
              options: STYLE_OPTIONS,
              onChange: setImageStyle,
              __nextHasNoMarginBottom: true,
            }),
            el(SelectControl, {
              label: __('Aspect Ratio', 'wp-intelligence'),
              value: aspectRatio,
              options: RATIO_OPTIONS,
              onChange: setAspectRatio,
              __nextHasNoMarginBottom: true,
            }),
            el(TextControl, {
              label: __('Brand Colors', 'wp-intelligence'),
              value: brandColors,
              onChange: setBrandColors,
              placeholder: __('e.g. navy blue, gold, white', 'wp-intelligence'),
              __nextHasNoMarginBottom: true,
            }),
            el(TextareaControl, {
              label: __('Custom Instructions', 'wp-intelligence'),
              value: customInstr,
              onChange: setCustomInstr,
              placeholder: __('Any additional style guidance for the AI...', 'wp-intelligence'),
              rows: 3,
              __nextHasNoMarginBottom: true,
            })
          )
        )
      )
    );
  }

  /* ──────────────────────────────────────────────
   *  SEO Pre-Publish Checklist Panel
   * ────────────────────────────────────────────── */

  function SeoChecklistPanel() {
    var postId = useSelect(function (select) {
      return select('core/editor').getCurrentPost().id;
    }, []);

    var _chk = useState(null);   var checks = _chk[0];       var setChecks = _chk[1];
    var _plg = useState('');     var seoPlugin = _plg[0];     var setSeoPlugin = _plg[1];
    var _ld  = useState(false);  var loaded = _ld[0];         var setLoaded = _ld[1];
    var _ldg = useState(false);  var loading = _ldg[0];       var setLoading = _ldg[1];

    useEffect(function () {
      if (!postId || loaded) return;
      setLoading(true);

      apiFetch({
        path: '/' + config.restNamespace + '/seo-checklist?post_id=' + postId,
        method: 'GET',
      }).then(function (res) {
        setChecks(res.checks || []);
        setSeoPlugin(res.seo_plugin || 'none');
        setLoaded(true);
        setLoading(false);
      }).catch(function () {
        setLoaded(true);
        setLoading(false);
      });
    }, [postId, loaded]);

    if (!checks || checks.length === 0) {
      if (loading) {
        return el(PluginPrePublishPanel, {
          title: __('Publishing Checklist', 'wp-intelligence'),
          icon: 'clipboard',
          initialOpen: false,
        }, el(Spinner, null));
      }
      return null;
    }

    var failCount = checks.filter(function (c) { return c.status === 'fail'; }).length;
    var warnCount = checks.filter(function (c) { return c.status === 'warn'; }).length;
    var passCount = checks.filter(function (c) { return c.status === 'pass'; }).length;

    var panelTitle = __('Publishing Checklist', 'wp-intelligence');
    if (seoPlugin !== 'none') {
      panelTitle += ' (' + seoPlugin + ')';
    }

    var STATUS_ICONS = {
      pass: 'yes-alt',
      warn: 'warning',
      fail: 'dismiss',
    };

    var STATUS_CLASSES = {
      pass: 'wpi-seo-pass',
      warn: 'wpi-seo-warn',
      fail: 'wpi-seo-fail',
    };

    return el(PluginPrePublishPanel, {
      title: panelTitle,
      icon: 'clipboard',
      initialOpen: failCount > 0,
    },
      el('div', { className: 'wpi-seo-panel' },

        el('div', { className: 'wpi-seo-summary' },
          passCount > 0 && el('span', { className: 'wpi-seo-badge wpi-seo-pass' },
            el(Icon, { icon: 'yes-alt', size: 14 }), ' ' + passCount
          ),
          warnCount > 0 && el('span', { className: 'wpi-seo-badge wpi-seo-warn' },
            el(Icon, { icon: 'warning', size: 14 }), ' ' + warnCount
          ),
          failCount > 0 && el('span', { className: 'wpi-seo-badge wpi-seo-fail' },
            el(Icon, { icon: 'dismiss', size: 14 }), ' ' + failCount
          )
        ),

        checks.map(function (check) {
          return el('div', {
            key: check.id,
            className: 'wpi-seo-check ' + (STATUS_CLASSES[check.status] || ''),
          },
            el('div', { className: 'wpi-seo-check-header' },
              el(Icon, { icon: STATUS_ICONS[check.status] || 'marker', size: 18 }),
              el('span', { className: 'wpi-seo-check-label' }, check.label)
            ),
            el('p', { className: 'wpi-seo-check-detail' }, check.detail)
          );
        })
      )
    );
  }

  /* ──────────────────────────────────────────────
   *  Register plugins
   * ────────────────────────────────────────────── */

  registerPlugin('wpi-featured-image-ai', {
    render: FeaturedImageAIPanel,
    icon: 'format-image',
  });

  registerPlugin('wpi-seo-checklist', {
    render: SeoChecklistPanel,
    icon: 'clipboard',
  });

})(window.wp);
