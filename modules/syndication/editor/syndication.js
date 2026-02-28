(function () {
  'use strict';

  var el              = wp.element.createElement;
  var useState        = wp.element.useState;
  var Fragment        = wp.element.Fragment;
  var registerPlugin  = wp.plugins.registerPlugin;
  var PluginDocumentSettingPanel = wp.editor.PluginDocumentSettingPanel || (wp.editPost && wp.editPost.PluginDocumentSettingPanel);
  var useSelect       = wp.data.useSelect;
  var useDispatch     = wp.data.useDispatch;
  var apiFetch        = wp.apiFetch;
  var __              = wp.i18n.__;
  var TextControl     = wp.components.TextControl;
  var TextareaControl = wp.components.TextareaControl;
  var Button          = wp.components.Button;
  var Notice          = wp.components.Notice;
  var Spinner         = wp.components.Spinner;
  var parse           = wp.blocks.parse;

  var config = window.aiComposerSyndicationConfig || {};

  function SyndicationPanel() {
    var postType = useSelect(function (select) {
      return select('core/editor').getCurrentPostType();
    }, []);

    var postId = useSelect(function (select) {
      return select('core/editor').getCurrentPostId();
    }, []);

    var currentTitle = useSelect(function (select) {
      return select('core/editor').getEditedPostAttribute('title');
    }, []);

    var dispatch = useDispatch('core/editor');
    var blockDispatch = useDispatch('core/block-editor');

    var _state = useState('');
    var url = _state[0];
    var setUrl = _state[1];

    var _prompt = useState('');
    var prompt = _prompt[0];
    var setPrompt = _prompt[1];

    var _loading = useState(false);
    var loading = _loading[0];
    var setLoading = _loading[1];

    var _status = useState(null);
    var status = _status[0];
    var setStatus = _status[1];

    if (config.enabledPostTypes && config.enabledPostTypes.length > 0) {
      if (config.enabledPostTypes.indexOf(postType) === -1) {
        return null;
      }
    }

    function handleGetContents() {
      if (!url || !url.trim()) {
        setStatus({ type: 'error', message: __('Please enter an article URL.', 'wp-intelligence') });
        return;
      }

      setLoading(true);
      setStatus(null);

      apiFetch({
        path: '/ai-composer/v1/syndicate',
        method: 'POST',
        data: {
          url: url.trim(),
          prompt: prompt.trim(),
          postId: postId || 0,
          postType: postType || 'post',
        },
      })
        .then(function (res) {
          setLoading(false);
          applyResponse(res, dispatch, blockDispatch, currentTitle);
        })
        .catch(function (err) {
          setLoading(false);
          var msg = (err && err.message) ? err.message : __('Something went wrong.', 'wp-intelligence');
          setStatus({ type: 'error', message: msg });
        });
    }

    function applyResponse(res, editorDispatch, blockEditorDispatch, existingTitle) {
      var messages = [];

      if (res.title && (!existingTitle || existingTitle.trim() === '')) {
        editorDispatch.editPost({ title: res.title });
        messages.push(__('Title set.', 'wp-intelligence'));
      }

      if (res.excerpt) {
        editorDispatch.editPost({ excerpt: res.excerpt });
        messages.push(__('Excerpt set.', 'wp-intelligence'));
      }

      if (res.content) {
        var blocks = parse(res.content);
        if (blocks && blocks.length > 0) {
          blockEditorDispatch.insertBlocks(blocks);
          messages.push(__('Content inserted.', 'wp-intelligence'));
        }
      }

      if (res.newsSource) {
        messages.push(
          wp.i18n.sprintf(
            __('Source: %s', 'wp-intelligence'),
            res.newsSource
          )
        );
      }

      if (res.publishedDate) {
        messages.push(
          wp.i18n.sprintf(
            __('Published: %s', 'wp-intelligence'),
            res.publishedDate
          )
        );
      }

      if (res.taxonomyAssigned) {
        messages.push(__('Source taxonomy term assigned.', 'wp-intelligence'));
      }

      var aiLabel = res.usedAI
        ? __('AI-generated.', 'wp-intelligence')
        : __('Fallback mode (no AI).', 'wp-intelligence');

      setStatus({
        type: 'success',
        message: aiLabel + ' ' + messages.join(' '),
      });
    }

    return el(
      PluginDocumentSettingPanel,
      {
        name: 'ai-composer-syndication',
        title: __('Content Syndication', 'wp-intelligence'),
        className: 'ai-composer-syndication-panel',
      },
      el(
        'div',
        { style: { display: 'flex', flexDirection: 'column', gap: '12px' } },

        el(TextControl, {
          label: __('Article URL', 'wp-intelligence'),
          value: url,
          onChange: setUrl,
          placeholder: 'https://example.com/article...',
          disabled: loading,
          __nextHasNoMarginBottom: true,
        }),

        el(TextareaControl, {
          label: __('Prompt (optional)', 'wp-intelligence'),
          help: __('Guide the AI rewrite, e.g. "Summarize in 150 words" or "Focus on property investment insights".', 'wp-intelligence'),
          value: prompt,
          onChange: setPrompt,
          rows: 3,
          disabled: loading,
          __nextHasNoMarginBottom: true,
        }),

        el(
          Button,
          {
            variant: 'primary',
            onClick: handleGetContents,
            disabled: loading || !url.trim(),
            isBusy: loading,
            style: { width: '100%', justifyContent: 'center' },
          },
          loading
            ? el(Fragment, null, el(Spinner, null), ' ', __('Fetching...', 'wp-intelligence'))
            : __('Get Contents', 'wp-intelligence')
        ),

        status &&
          el(
            Notice,
            {
              status: status.type === 'success' ? 'success' : 'error',
              isDismissible: true,
              onRemove: function () { setStatus(null); },
            },
            status.message
          )
      )
    );
  }

  registerPlugin('ai-composer-syndication', {
    render: SyndicationPanel,
    icon: 'download',
  });
})();
