(function () {
  'use strict';

  var el              = wp.element.createElement;
  var useState        = wp.element.useState;
  var useEffect       = wp.element.useEffect;
  var useRef          = wp.element.useRef;
  var useCallback     = wp.element.useCallback;
  var Fragment        = wp.element.Fragment;
  var createPortal    = wp.element.createPortal;
  var registerPlugin  = wp.plugins.registerPlugin;
  var useSelect       = wp.data.useSelect;
  var useDispatch     = wp.data.useDispatch;
  var apiFetch        = wp.apiFetch;
  var __              = wp.i18n.__;
  var TextControl     = wp.components.TextControl;
  var TextareaControl = wp.components.TextareaControl;
  var Button          = wp.components.Button;
  var Notice          = wp.components.Notice;
  var Spinner         = wp.components.Spinner;
  var RangeControl    = wp.components.RangeControl;
  var Panel           = wp.components.Panel;
  var PanelBody       = wp.components.PanelBody;
  var PanelRow        = wp.components.PanelRow;
  var createBlock     = wp.blocks.createBlock;

  var config = window.wpiSyndicationConfig || window.aiComposerSyndicationConfig || {};

  var LOADER_STAGES = [
    __('Fetching article from source...', 'wp-intelligence'),
    __('Extracting article content...', 'wp-intelligence'),
    __('Analysing article structure...', 'wp-intelligence'),
    __('Generating draft content...', 'wp-intelligence'),
    __('Downloading images...', 'wp-intelligence'),
    __('Almost there...', 'wp-intelligence'),
  ];

  var STATUS_ICONS = { ok: '\u2705', warn: '\u26A0\uFE0F', error: '\u274C', skip: '\u23ED\uFE0F' };

  // ── Log accordion ──────────────────────────────────────────────────

  function LogAccordion(props) {
    var log = props.log;
    if (!log || !log.length) return null;

    return el('details', {
      style: {
        marginTop: '8px',
        fontSize: '12px',
        lineHeight: '1.6',
        border: '1px solid #ddd',
        borderRadius: '3px',
        overflow: 'hidden',
      },
    },
      el('summary', {
        style: {
          padding: '6px 10px',
          cursor: 'pointer',
          background: '#f6f7f7',
          fontWeight: 500,
          userSelect: 'none',
        },
      }, __('Pipeline log', 'wp-intelligence')),
      el('div', null,
        log.map(function (entry, i) {
          var icon = STATUS_ICONS[entry.status] || '';
          var timeLabel = entry.ms > 0 ? ' (' + (entry.ms / 1000).toFixed(1) + 's)' : '';
          return el('div', {
            key: i,
            style: {
              display: 'flex',
              gap: '6px',
              padding: '4px 10px',
              background: i % 2 === 0 ? '#fff' : '#fafafa',
              borderTop: '1px solid #f0f0f0',
              alignItems: 'flex-start',
            },
          },
            el('span', { style: { flexShrink: 0, width: '16px', textAlign: 'center' } }, icon),
            el('span', { style: { fontWeight: 600, flexShrink: 0, minWidth: '90px' } }, entry.step),
            el('span', { style: { color: '#555', wordBreak: 'break-word' } }, entry.detail + timeLabel)
          );
        })
      )
    );
  }

  // ── Metabox-style panel (portalled below title) ────────────────────

  var PORTAL_ID = 'wpi-syndication-metabox';

  var TITLE_SELECTORS = [
    '.editor-post-title__block',
    '.editor-post-title',
    '.edit-post-visual-editor__post-title-wrapper',
  ];

  function findOrCreatePortalTarget() {
    var existing = document.getElementById(PORTAL_ID);
    if (existing) return existing;

    var anchor = null;
    for (var i = 0; i < TITLE_SELECTORS.length; i++) {
      anchor = document.querySelector(TITLE_SELECTORS[i]);
      if (anchor) break;
    }
    if (!anchor) return null;

    var container = document.createElement('div');
    container.id = PORTAL_ID;
    anchor.parentNode.insertBefore(container, anchor.nextSibling);
    return container;
  }

  // ── Main component ─────────────────────────────────────────────────

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

    var _url       = useState('');
    var _prompt    = useState('');
    var _wordCount = useState(600);
    var _loading   = useState(false);
    var _stage     = useState(0);
    var _status    = useState(null);
    var _log       = useState(null);
    var _portal    = useState(null);

    var url        = _url[0],       setUrl        = _url[1];
    var prompt     = _prompt[0],    setPrompt     = _prompt[1];
    var wordCount  = _wordCount[0], setWordCount  = _wordCount[1];
    var loading    = _loading[0],   setLoading    = _loading[1];
    var loaderStage = _stage[0],    setLoaderStage = _stage[1];
    var status     = _status[0],    setStatus     = _status[1];
    var log        = _log[0],       setLog        = _log[1];
    var portalEl   = _portal[0],    setPortalEl   = _portal[1];

    var timerRef = useRef(null);

    useEffect(function () {
      var attempts = 0;
      var poll = setInterval(function () {
        var target = findOrCreatePortalTarget();
        if (target) {
          setPortalEl(target);
          clearInterval(poll);
        }
        if (++attempts > 40) clearInterval(poll);
      }, 250);
      return function () {
        clearInterval(poll);
        var node = document.getElementById(PORTAL_ID);
        if (node && node.parentNode) node.parentNode.removeChild(node);
      };
    }, []);

    useEffect(function () {
      if (loading) {
        setLoaderStage(0);
        var stage = 0;
        timerRef.current = setInterval(function () {
          stage++;
          if (stage < LOADER_STAGES.length) setLoaderStage(stage);
        }, 4000);
      } else if (timerRef.current) {
        clearInterval(timerRef.current);
        timerRef.current = null;
      }
      return function () { if (timerRef.current) clearInterval(timerRef.current); };
    }, [loading]);

    if (config.enabledPostTypes && config.enabledPostTypes.length > 0) {
      if (config.enabledPostTypes.indexOf(postType) === -1) return null;
    }

    var handleSyndicate = useCallback(function () {
      if (!url || !url.trim()) {
        setStatus({ type: 'error', message: __('Please enter an article URL.', 'wp-intelligence') });
        return;
      }
      setLoading(true);
      setStatus(null);
      setLog(null);

      apiFetch({
        path: '/ai-composer/v1/syndicate',
        method: 'POST',
        data: { url: url.trim(), prompt: prompt.trim(), post_id: postId || 0, word_count: wordCount },
      })
        .then(function (res) {
          setLoading(false);
          setLog(res.log || null);
          applyResponse(res);
        })
        .catch(function (err) {
          setLoading(false);
          var msg = (err && err.message) ? err.message : __('Something went wrong.', 'wp-intelligence');
          if (err && err.data && err.data.log) setLog(err.data.log);
          setStatus({ type: 'error', message: msg });
        });
    }, [url, prompt, postId, wordCount]);

    function applyResponse(res) {
      var messages = [];

      if (res.title && (!currentTitle || currentTitle.trim() === '')) {
        dispatch.editPost({ title: res.title });
        messages.push(__('Title set.', 'wp-intelligence'));
      }
      if (res.excerpt) {
        dispatch.editPost({ excerpt: res.excerpt });
        messages.push(__('Excerpt set.', 'wp-intelligence'));
      }
      if (res.content) {
        var block = createBlock('core/freeform', { content: res.content });
        blockDispatch.insertBlocks([block]);
        messages.push(__('Content inserted.', 'wp-intelligence'));
      }
      if (res.featuredImageSet || res.dateSet) {
        wp.data.dispatch('core').invalidateResolution('getEntityRecord', ['postType', postType, postId]);
        wp.data.dispatch('core/editor').refreshPost();
      }
      if (res.featuredImageSet) {
        messages.push(__('Featured image set.', 'wp-intelligence'));
      }
      if (res.dateSet) {
        messages.push(__('Post date set.', 'wp-intelligence'));
      }
      if (res.imagesImported > 0) {
        messages.push(wp.i18n.sprintf(__('%d image(s) imported.', 'wp-intelligence'), res.imagesImported));
      }
      if (res.newsSource) messages.push(res.newsSource);
      if (res.publishedDate) messages.push(res.publishedDate);

      setStatus({ type: 'success', message: messages.join(' \u00B7 ') });
    }

    // ── Render ───────────────────────────────────────────────────────

    if (!portalEl) return null;

    var strategyLabel = config.hasFirecrawl
      ? __('Built-in + Firecrawl fallback', 'wp-intelligence')
      : __('Built-in only', 'wp-intelligence');

    var panel = el(Panel, null,
      el(PanelBody, {
        title: __('Content Syndication', 'wp-intelligence'),
        initialOpen: true,
      },
        el('div', { className: 'wpi-syndication-fields' },

          el('span', { style: { fontSize: '11px', color: '#757575' } }, strategyLabel),

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
            help: __('Guide the rewrite, e.g. "Focus on property investment insights".', 'wp-intelligence'),
            value: prompt,
            onChange: setPrompt,
            rows: 2,
            disabled: loading,
            __nextHasNoMarginBottom: true,
          }),

          el(RangeControl, {
            label: __('Target word count', 'wp-intelligence'),
            value: wordCount,
            onChange: setWordCount,
            min: 200,
            max: 1500,
            step: 50,
            disabled: loading,
            marks: [
              { value: 200, label: '200' },
              { value: 600, label: '600' },
              { value: 1000, label: '1000' },
              { value: 1500, label: '1500' },
            ],
            __nextHasNoMarginBottom: true,
          }),

          el(Button, {
            variant: 'primary',
            onClick: handleSyndicate,
            disabled: loading || !url.trim(),
            isBusy: loading,
            style: { width: '100%', justifyContent: 'center' },
          },
            loading
              ? el(Fragment, null, el(Spinner, null), ' ', el('span', null, LOADER_STAGES[loaderStage] || LOADER_STAGES[LOADER_STAGES.length - 1]))
              : __('Syndicate', 'wp-intelligence')
          ),

          status && el(Notice, {
            status: status.type === 'success' ? 'success' : 'error',
            isDismissible: true,
            onRemove: function () { setStatus(null); setLog(null); },
          }, status.message),

          log && el(LogAccordion, { log: log })
        )
      )
    );

    return createPortal(panel, portalEl);
  }

  registerPlugin('ai-composer-syndication', {
    render: SyndicationPanel,
    icon: 'download',
  });
})();
