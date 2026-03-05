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
  var ButtonGroup     = wp.components.ButtonGroup;
  var Notice          = wp.components.Notice;
  var Spinner         = wp.components.Spinner;
  var RangeControl    = wp.components.RangeControl;
  var SelectControl   = wp.components.SelectControl;
  var CheckboxControl = wp.components.CheckboxControl;
  var Panel           = wp.components.Panel;
  var PanelBody       = wp.components.PanelBody;
  var PanelRow        = wp.components.PanelRow;
  var createBlock     = wp.blocks.createBlock;
  var rawHandler      = wp.blocks.rawHandler;

  var SOURCE_TYPES = [
    { value: 'url', label: __('URL', 'wp-intelligence') },
    { value: 'text', label: __('Paste text', 'wp-intelligence') },
    { value: 'file', label: __('Upload file', 'wp-intelligence') },
  ];

  function getModesForSource(sourceType) {
    var allStyles = (config.contentStyles || []);
    if (!allStyles.length) {
      allStyles = [
        { value: 'featured_in', label: 'As Featured In', sourceType: 'all' },
        { value: 'original_post', label: 'Original Post', sourceType: 'all' },
        { value: 'summary', label: 'Summary', sourceType: 'all' },
        { value: 'commentary', label: 'Commentary', sourceType: 'all' },
      ];
    }
    return allStyles.filter(function (s) {
      return s.sourceType === 'all' || s.sourceType === sourceType;
    });
  }

  var config = window.wpiSyndicationConfig || window.aiComposerSyndicationConfig || {};

  var YT_REGEX = /^https?:\/\/(www\.)?(youtube\.com\/(watch|shorts)|youtu\.be\/)/i;
  function isYouTubeUrl(u) { return YT_REGEX.test((u || '').trim()); }

  var LOADER_STAGES_URL = [
    __('Fetching source content...', 'wp-intelligence'),
    __('Extracting article...', 'wp-intelligence'),
    __('Generating draft...', 'wp-intelligence'),
    __('Downloading images...', 'wp-intelligence'),
    __('Almost there...', 'wp-intelligence'),
  ];

  var LOADER_STAGES_VIDEO = [
    __('Fetching video transcript...', 'wp-intelligence'),
    __('Processing captions...', 'wp-intelligence'),
    __('Generating draft...', 'wp-intelligence'),
    __('Downloading thumbnail...', 'wp-intelligence'),
    __('Almost there...', 'wp-intelligence'),
  ];

  var LOADER_STAGES_TEXT = [
    __('Processing source text...', 'wp-intelligence'),
    __('Generating draft...', 'wp-intelligence'),
    __('Almost there...', 'wp-intelligence'),
  ];

  var STATUS_ICONS = { ok: '\u2705', warn: '\u26A0\uFE0F', error: '\u274C', skip: '\u23ED\uFE0F' };

  function LogAccordion(props) {
    var log = props.log;
    if (!log || !log.length) return null;

    return el('details', {
      style: { marginTop: '8px', fontSize: '12px', lineHeight: '1.6', border: '1px solid #ddd', borderRadius: '3px', overflow: 'hidden' },
    },
      el('summary', {
        style: { padding: '6px 10px', cursor: 'pointer', background: '#f6f7f7', fontWeight: 500, userSelect: 'none' },
      }, __('Pipeline log', 'wp-intelligence')),
      el('div', null,
        log.map(function (entry, i) {
          var icon = STATUS_ICONS[entry.status] || '';
          var timeLabel = entry.ms > 0 ? ' (' + (entry.ms / 1000).toFixed(1) + 's)' : '';
          return el('div', {
            key: i,
            style: { display: 'flex', gap: '6px', padding: '4px 10px', background: i % 2 === 0 ? '#fff' : '#fafafa', borderTop: '1px solid #f0f0f0', alignItems: 'flex-start' },
          },
            el('span', { style: { flexShrink: 0, width: '16px', textAlign: 'center' } }, icon),
            el('span', { style: { fontWeight: 600, flexShrink: 0, minWidth: '90px' } }, entry.step),
            el('span', { style: { color: '#555', wordBreak: 'break-word' } }, entry.detail + timeLabel)
          );
        })
      )
    );
  }

  var PORTAL_ID = 'wpi-syndication-metabox';
  var TITLE_SELECTORS = ['.editor-post-title__block', '.editor-post-title', '.edit-post-visual-editor__post-title-wrapper'];

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

  function SyndicationPanel() {
    var postType = useSelect(function (select) { return select('core/editor').getCurrentPostType(); }, []);
    var postId = useSelect(function (select) { return select('core/editor').getCurrentPostId(); }, []);
    var currentTitle = useSelect(function (select) { return select('core/editor').getEditedPostAttribute('title'); }, []);

    var dispatch = useDispatch('core/editor');
    var blockDispatch = useDispatch('core/block-editor');

    var _srcType   = useState('url');
    var _url       = useState('');
    var _srcText   = useState('');
    var _srcFile   = useState('');
    var _srcFName  = useState('');
    var _prompt    = useState('');
    var _wordCount = useState(600);
    var _mode      = useState('featured_in');
    var _refUrls   = useState('');
    var _loading   = useState(false);
    var _stage     = useState(0);
    var _status    = useState(null);
    var _log       = useState(null);
    var _portal    = useState(null);
    var _acfFields = useState([]);
    var _selFields = useState([]);
    var _fieldInst = useState({});
    var _extraCtx  = useState('');
    var _ctxFName  = useState('');
    var _outputFmt = useState(function () {
      var defaults = (config.outputDefaults || {});
      return defaults[postType] || 'blocks';
    });

    var srcType    = _srcType[0],   setSrcType    = _srcType[1];
    var url        = _url[0],       setUrl        = _url[1];
    var srcText    = _srcText[0],   setSrcText    = _srcText[1];
    var srcFile    = _srcFile[0],   setSrcFile    = _srcFile[1];
    var srcFName   = _srcFName[0],  setSrcFName   = _srcFName[1];
    var prompt     = _prompt[0],    setPrompt     = _prompt[1];
    var wordCount  = _wordCount[0], setWordCount  = _wordCount[1];
    var mode       = _mode[0],      setMode       = _mode[1];
    var refUrls    = _refUrls[0],   setRefUrls    = _refUrls[1];
    var extraCtx   = _extraCtx[0],  setExtraCtx   = _extraCtx[1];
    var ctxFName   = _ctxFName[0],  setCtxFName   = _ctxFName[1];
    var outputFmt  = _outputFmt[0], setOutputFmt  = _outputFmt[1];
    var loading    = _loading[0],   setLoading    = _loading[1];
    var loaderStage = _stage[0],    setLoaderStage = _stage[1];
    var status     = _status[0],    setStatus     = _status[1];
    var log        = _log[0],       setLog        = _log[1];
    var portalEl   = _portal[0],    setPortalEl   = _portal[1];
    var acfFields  = _acfFields[0], setAcfFields  = _acfFields[1];
    var selFields  = _selFields[0], setSelFields   = _selFields[1];
    var fieldInst  = _fieldInst[0], setFieldInst   = _fieldInst[1];

    var timerRef = useRef(null);

    useEffect(function () {
      var attempts = 0;
      var poll = setInterval(function () {
        var target = findOrCreatePortalTarget();
        if (target) { setPortalEl(target); clearInterval(poll); }
        if (++attempts > 40) clearInterval(poll);
      }, 250);
      return function () {
        clearInterval(poll);
        var node = document.getElementById(PORTAL_ID);
        if (node && node.parentNode) node.parentNode.removeChild(node);
      };
    }, []);

    useEffect(function () {
      if (!postId || postId <= 0) return;
      apiFetch({ path: '/ai-composer/v1/syndicate/fields?post_id=' + postId })
        .then(function (res) { if (res && res.fields && res.fields.length > 0) setAcfFields(res.fields); })
        .catch(function () {});
    }, [postId]);

    var isVideo = srcType === 'url' && isYouTubeUrl(url);
    var loaderStages = isVideo ? LOADER_STAGES_VIDEO : srcType !== 'url' ? LOADER_STAGES_TEXT : LOADER_STAGES_URL;

    useEffect(function () {
      if (loading) {
        setLoaderStage(0);
        var stage = 0;
        timerRef.current = setInterval(function () {
          stage++;
          if (stage < loaderStages.length) setLoaderStage(stage);
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

    function getSourceContent() {
      if (srcType === 'url') return '';
      if (srcType === 'text') return srcText;
      if (srcType === 'file') return srcFile;
      return '';
    }

    function hasSource() {
      if (srcType === 'url') return url.trim() !== '';
      if (srcType === 'text') return srcText.trim().length > 50;
      if (srcType === 'file') return srcFile.length > 50;
      return false;
    }

    function getModes() {
      if (srcType === 'url' && isVideo) return getModesForSource('video');
      if (srcType !== 'url') return getModesForSource('text');
      return getModesForSource('url');
    }

    var handleGenerate = useCallback(function () {
      if (!hasSource()) {
        setStatus({ type: 'error', message: __('Please provide source content.', 'wp-intelligence') });
        return;
      }
      setLoading(true);
      setStatus(null);
      setLog(null);

      var data = {
        prompt: prompt.trim(),
        post_id: postId || 0,
        word_count: wordCount,
        mode: mode,
        reference_urls: refUrls.trim(),
          selected_fields: selFields.join(','),
          field_instructions: JSON.stringify(fieldInst),
        extra_context: extraCtx,
      };

      if (srcType === 'url') {
        data.url = url.trim();
      } else {
        data.url = '';
        data.source_text = getSourceContent();
      }

      apiFetch({ path: '/ai-composer/v1/syndicate', method: 'POST', data: data })
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
    }, [url, srcType, srcText, srcFile, prompt, postId, wordCount, mode, refUrls, selFields, extraCtx]);

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
        var blocks;
        if (outputFmt === 'blocks' && typeof rawHandler === 'function') {
          blocks = rawHandler({ HTML: res.content });
        } else {
          blocks = [createBlock('core/freeform', { content: res.content })];
        }
        if (blocks && blocks.length > 0) {
          blockDispatch.insertBlocks(blocks);
        }
        messages.push(__('Content inserted.', 'wp-intelligence'));
      }
      if (res.featuredImageSet || res.dateSet) {
        wp.data.dispatch('core').invalidateResolution('getEntityRecord', ['postType', postType, postId]);
        wp.data.dispatch('core/editor').refreshPost();
      }
      if (res.featuredImageSet) messages.push(__('Featured image set.', 'wp-intelligence'));
      if (res.dateSet) messages.push(__('Post date set.', 'wp-intelligence'));
      if (res.fieldsWritten > 0) messages.push(wp.i18n.sprintf(__('%d ACF field(s) populated.', 'wp-intelligence'), res.fieldsWritten));
      if (res.imagesImported > 0) messages.push(wp.i18n.sprintf(__('%d image(s) imported.', 'wp-intelligence'), res.imagesImported));
      if (res.newsSource) messages.push(res.newsSource);
      setStatus({ type: 'success', message: messages.join(' \u00B7 ') });
    }

    if (!portalEl) return null;

    var panel = el(Panel, null,
      el(PanelBody, {
        title: __('Generate Content', 'wp-intelligence'),
        initialOpen: true,
      },
        el('div', { className: 'wpi-syndication-fields' },

          el('div', { className: 'wpi-source-type-tabs' },
            SOURCE_TYPES.map(function (t) {
              return el(Button, {
                key: t.value,
                variant: srcType === t.value ? 'primary' : 'secondary',
                size: 'compact',
                onClick: function () {
                  setSrcType(t.value);
                  if (t.value !== 'url') setMode('original_post');
                  else setMode('featured_in');
                },
                disabled: loading,
              }, t.label);
            })
          ),

          srcType === 'url' && el(TextControl, {
            label: __('Source URL', 'wp-intelligence'),
            value: url,
            onChange: function (v) {
              setUrl(v);
              var wasVid = isYouTubeUrl(url);
              var isVid = isYouTubeUrl(v);
              if (isVid && !wasVid) setMode('video_recap');
              if (!isVid && wasVid) setMode('featured_in');
            },
            placeholder: 'https://example.com/article... or YouTube URL',
            disabled: loading,
            __nextHasNoMarginBottom: true,
          }),

          srcType === 'url' && isVideo && el('span', {
            style: { fontSize: '11px', color: '#1e7e34', fontWeight: 500 },
          }, __('YouTube video detected — transcript will be extracted', 'wp-intelligence')),

          srcType === 'text' && el(TextareaControl, {
            label: __('Source content', 'wp-intelligence'),
            help: __('Paste article text, press release, interview transcript, research notes, etc.', 'wp-intelligence'),
            value: srcText,
            onChange: setSrcText,
            rows: 6,
            disabled: loading,
            __nextHasNoMarginBottom: true,
          }),

          srcType === 'file' && el('div', { className: 'wpi-syndication-file-upload' },
            el('span', {
              style: { fontSize: '12px', fontWeight: 600, color: '#1e1e1e', display: 'block', marginBottom: '4px' },
            }, __('Upload source file', 'wp-intelligence')),
            el('input', {
              type: 'file',
              accept: '.txt,.md,.csv,.rtf',
              disabled: loading,
              style: { fontSize: '12px' },
              onChange: function (e) {
                var file = e.target.files && e.target.files[0];
                if (!file) { setSrcFile(''); setSrcFName(''); return; }
                if (file.size > 2097152) {
                  setStatus({ type: 'error', message: __('File must be under 2MB.', 'wp-intelligence') });
                  e.target.value = '';
                  return;
                }
                setSrcFName(file.name);
                var reader = new FileReader();
                reader.onload = function (ev) { setSrcFile(ev.target.result || ''); };
                reader.readAsText(file);
              },
            }),
            srcFName && el('span', { style: { fontSize: '11px', color: '#1e7e34', marginTop: '4px', display: 'block' } }, srcFName + ' loaded'),
            el('span', {
              style: { fontSize: '11px', color: '#757575', display: 'block', marginTop: '4px' },
            }, __('.txt, .md, .csv, .rtf — max 2MB', 'wp-intelligence'))
          ),

          el(SelectControl, {
            label: __('Style', 'wp-intelligence'),
            value: mode,
            options: getModes(),
            onChange: setMode,
            disabled: loading,
            __nextHasNoMarginBottom: true,
          }),

          el(TextareaControl, {
            label: __('Prompt (optional)', 'wp-intelligence'),
            help: __('Guide the output, e.g. "Focus on investment insights" or "Write for first-home buyers".', 'wp-intelligence'),
            value: prompt,
            onChange: setPrompt,
            rows: 2,
            disabled: loading,
            __nextHasNoMarginBottom: true,
          }),

          el(TextareaControl, {
            label: __('Reference URLs (optional)', 'wp-intelligence'),
            help: __('Additional URLs for the AI to cross-reference and cite. One per line.', 'wp-intelligence'),
            value: refUrls,
            onChange: setRefUrls,
            rows: 2,
            disabled: loading,
            __nextHasNoMarginBottom: true,
          }),

          srcType === 'url' && el('div', { className: 'wpi-syndication-file-upload' },
            el('span', {
              style: { fontSize: '12px', fontWeight: 600, color: '#1e1e1e', display: 'block', marginBottom: '4px' },
            }, __('Extra context file (optional)', 'wp-intelligence')),
            el('input', {
              type: 'file',
              accept: '.txt,.md,.csv',
              disabled: loading,
              style: { fontSize: '12px' },
              onChange: function (e) {
                var file = e.target.files && e.target.files[0];
                if (!file) { setExtraCtx(''); setCtxFName(''); return; }
                if (file.size > 1048576) {
                  setStatus({ type: 'error', message: __('File must be under 1MB.', 'wp-intelligence') });
                  e.target.value = '';
                  return;
                }
                setCtxFName(file.name);
                var reader = new FileReader();
                reader.onload = function (ev) { setExtraCtx(ev.target.result || ''); };
                reader.readAsText(file);
              },
            }),
            ctxFName && el('span', { style: { fontSize: '11px', color: '#1e7e34', marginTop: '4px', display: 'block' } }, ctxFName + ' loaded'),
            el('span', {
              style: { fontSize: '11px', color: '#757575', display: 'block', marginTop: '4px' },
            }, __('Additional background info for the AI. .txt, .md, .csv — max 1MB', 'wp-intelligence'))
          ),

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

          acfFields.length > 0 && el('div', { className: 'wpi-syndication-acf-fields' },
            el('span', {
              style: { fontSize: '12px', fontWeight: 600, color: '#1e1e1e', display: 'block', marginBottom: '6px' },
            }, __('Populate ACF fields', 'wp-intelligence')),
            el('span', {
              style: { fontSize: '11px', color: '#757575', display: 'block', marginBottom: '8px' },
            }, __('Select fields to auto-fill from the source content.', 'wp-intelligence')),
            (function () {
              var groups = {};
              acfFields.forEach(function (f) {
                var g = f.group || 'Fields';
                if (!groups[g]) groups[g] = [];
                groups[g].push(f);
              });
              return Object.keys(groups).map(function (groupName) {
                return el('div', { key: groupName, style: { marginBottom: '8px' } },
                  Object.keys(groups).length > 1 && el('span', {
                    style: { fontSize: '11px', color: '#757575', fontWeight: 500, display: 'block', marginBottom: '4px' },
                  }, groupName),
                  groups[groupName].map(function (field) {
                    var checked = selFields.indexOf(field.name) !== -1;
                    return el('div', { key: field.name, style: { marginBottom: checked ? '8px' : '0' } },
                      el(CheckboxControl, {
                        label: field.label + ' (' + field.type + ')',
                        checked: checked,
                        onChange: function (val) {
                          if (val) setSelFields(selFields.concat([field.name]));
                          else setSelFields(selFields.filter(function (n) { return n !== field.name; }));
                        },
                        disabled: loading,
                        __nextHasNoMarginBottom: true,
                      }),
                      checked && el('input', {
                        type: 'text',
                        value: fieldInst[field.name] || '',
                        onChange: function (e) {
                          var copy = Object.assign({}, fieldInst);
                          copy[field.name] = e.target.value;
                          setFieldInst(copy);
                        },
                        placeholder: __('Instructions for this field, e.g. "Extract the client name"', 'wp-intelligence'),
                        disabled: loading,
                        style: { width: '100%', fontSize: '11px', marginTop: '2px', marginLeft: '28px', boxSizing: 'border-box', maxWidth: 'calc(100% - 28px)' },
                      })
                    );
                  })
                );
              });
            })()
          ),

          el('div', { style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '8px' } },
            el('span', { style: { fontSize: '12px', fontWeight: 600, color: '#1e1e1e' } }, __('Output as', 'wp-intelligence')),
            el('div', { className: 'wpi-source-type-tabs', style: { flex: 'none' } },
              el(Button, {
                variant: outputFmt === 'blocks' ? 'primary' : 'secondary',
                size: 'compact',
                onClick: function () { setOutputFmt('blocks'); },
                disabled: loading,
              }, __('Blocks', 'wp-intelligence')),
              el(Button, {
                variant: outputFmt === 'wysiwyg' ? 'primary' : 'secondary',
                size: 'compact',
                onClick: function () { setOutputFmt('wysiwyg'); },
                disabled: loading,
              }, __('WYSIWYG', 'wp-intelligence'))
            )
          ),

          el(Button, {
            variant: 'primary',
            onClick: handleGenerate,
            disabled: loading || !hasSource(),
            isBusy: loading,
            style: { width: '100%', justifyContent: 'center' },
          },
            loading
              ? el(Fragment, null, el(Spinner, null), ' ', el('span', null, loaderStages[loaderStage] || loaderStages[loaderStages.length - 1]))
              : __('Generate', 'wp-intelligence')
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
    icon: 'welcome-write-blog',
  });
})();
