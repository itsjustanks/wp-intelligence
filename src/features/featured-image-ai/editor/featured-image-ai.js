(function (wp) {
  const { registerPlugin } = wp.plugins;
  const { PluginPrePublishPanel } = wp.editPost;
  const { useState, useEffect, useCallback, createElement: el, Fragment } = wp.element;
  const {
    Button, Spinner, Notice, PanelRow, SelectControl,
    TextControl, TextareaControl, Flex, FlexItem, FlexBlock,
    Icon, Modal,
  } = wp.components;
  const { useSelect, useDispatch } = wp.data;
  const { __ } = wp.i18n;
  const apiFetch = wp.apiFetch;

  const config = window.wpiFeaturedImageAIConfig || {};
  const defaults = config.defaults || {};

  const STYLE_OPTIONS = [
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

  const RATIO_OPTIONS = [
    { label: __('Landscape (1792x1024)', 'wp-intelligence'), value: 'landscape' },
    { label: __('Portrait (1024x1792)', 'wp-intelligence'),  value: 'portrait' },
    { label: __('Square (1024x1024)', 'wp-intelligence'),    value: 'square' },
  ];

  function FeaturedImageAIPanel() {
    const { postId, postTitle, hasFeaturedImage, featuredImageUrl } = useSelect(function (select) {
      const editor = select('core/editor');
      const post = editor.getCurrentPost();
      const featMediaId = editor.getEditedPostAttribute('featured_media');
      let thumbUrl = '';

      if (featMediaId) {
        const media = select('core').getMedia(featMediaId);
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

    const { editPost } = useDispatch('core/editor');
    const { createSuccessNotice, createErrorNotice } = useDispatch('core/notices');

    const [generating, setGenerating] = useState(false);
    const [generatedImage, setGeneratedImage] = useState(null);
    const [showOptions, setShowOptions] = useState(false);
    const [fallbacks, setFallbacks] = useState([]);
    const [fallbackLoaded, setFallbackLoaded] = useState(false);
    const [error, setError] = useState('');

    const [imageStyle, setImageStyle] = useState(defaults.image_style || 'photo-realistic');
    const [aspectRatio, setAspectRatio] = useState(defaults.aspect_ratio || 'landscape');
    const [brandColors, setBrandColors] = useState(defaults.brand_colors || '');
    const [customInstructions, setCustomInstructions] = useState(defaults.custom_instructions || '');

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
          custom_instructions: customInstructions,
        },
      }).then(function (res) {
        setGenerating(false);
        if (res.success) {
          setGeneratedImage(res);
          editPost({ featured_media: res.attachment_id });
          createSuccessNotice(
            __('Featured image generated and set.', 'wp-intelligence'),
            { type: 'snackbar' }
          );
        }
      }).catch(function (err) {
        setGenerating(false);
        var msg = (err && err.message) || __('Failed to generate image.', 'wp-intelligence');
        setError(msg);
        createErrorNotice(msg, { type: 'snackbar' });
      });
    }, [postId, generating, imageStyle, aspectRatio, brandColors, customInstructions]);

    var handleRegenerate = useCallback(function () {
      setGeneratedImage(null);
      handleGenerate();
    }, [handleGenerate]);

    if (!config.providerReady) {
      return el(
        PluginPrePublishPanel,
        {
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
      return el(
        PluginPrePublishPanel,
        {
          title: __('Featured Image AI', 'wp-intelligence'),
          icon: 'format-image',
          initialOpen: false,
        },
        el(
          'div', { className: 'wpi-fia-has-image' },
          el(Icon, { icon: 'yes-alt', size: 20 }),
          el('span', null, __('Featured image is set.', 'wp-intelligence'))
        )
      );
    }

    return el(
      PluginPrePublishPanel,
      {
        title: hasFeaturedImage
          ? __('Featured Image AI', 'wp-intelligence')
          : __('No Featured Image', 'wp-intelligence'),
        icon: 'format-image',
        initialOpen: !hasFeaturedImage,
      },
      el(
        'div', { className: 'wpi-fia-panel' },

        !hasFeaturedImage && !generatedImage && !generating && el(
          Fragment, null,
          el('p', { className: 'wpi-fia-prompt-text' },
            __('This post has no featured image. Would you like to generate one with AI?', 'wp-intelligence')
          ),

          fallbacks.length > 0 && el(
            'div', { className: 'wpi-fia-fallbacks' },
            el('p', { className: 'wpi-fia-fallback-label' },
              __('Detected fallback images:', 'wp-intelligence')
            ),
            fallbacks.map(function (fb, i) {
              return el(
                'div', { className: 'wpi-fia-fallback-item', key: i },
                el('img', {
                  src: fb.url,
                  alt: fb.label,
                  className: 'wpi-fia-fallback-thumb',
                }),
                el(
                  'div', { className: 'wpi-fia-fallback-meta' },
                  el('span', { className: 'wpi-fia-fallback-source' }, fb.source),
                  el('span', { className: 'wpi-fia-fallback-desc' }, fb.label)
                )
              );
            })
          )
        ),

        error && el(
          Notice, { status: 'error', isDismissible: true, onDismiss: function () { setError(''); } },
          el('p', null, error)
        ),

        generating && el(
          'div', { className: 'wpi-fia-generating' },
          el(Spinner, null),
          el('p', null, __('Generating your featured image...', 'wp-intelligence')),
          el('p', { className: 'wpi-fia-generating-sub' },
            __('AI is analyzing your content and creating an image. This may take 15-30 seconds.', 'wp-intelligence')
          ),
          config.overlay && config.overlay.show_title === '1' && el(
            'p', { className: 'wpi-fia-generating-sub' },
            __('Post title will be overlaid onto the image.', 'wp-intelligence')
          )
        ),

        generatedImage && el(
          'div', { className: 'wpi-fia-result' },
          el('p', { className: 'wpi-fia-success-text' },
            __('Image generated and set as featured image.', 'wp-intelligence')
          ),
          el('img', {
            src: generatedImage.attachment_url,
            alt: __('Generated featured image', 'wp-intelligence'),
            className: 'wpi-fia-preview',
          }),
          el(
            Flex, { justify: 'flex-start', gap: 2, className: 'wpi-fia-result-actions' },
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

        !generating && !generatedImage && el(
          Fragment, null,
          el(
            'div', { className: 'wpi-fia-actions' },
            el(Button, {
              variant: 'primary',
              onClick: handleGenerate,
              disabled: !postTitle,
              className: 'wpi-fia-generate-btn',
            },
              el(Icon, { icon: 'format-image', size: 18 }),
              __('Generate with AI', 'wp-intelligence')
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

          showOptions && el(
            'div', { className: 'wpi-fia-options' },
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
              value: customInstructions,
              onChange: setCustomInstructions,
              placeholder: __('Any additional style guidance for the AI...', 'wp-intelligence'),
              rows: 3,
              __nextHasNoMarginBottom: true,
            })
          )
        )
      )
    );
  }

  registerPlugin('wpi-featured-image-ai', {
    render: FeaturedImageAIPanel,
    icon: 'format-image',
  });
})(window.wp);
