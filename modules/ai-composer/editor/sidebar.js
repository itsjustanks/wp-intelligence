(function () {
  'use strict';

  var el             = wp.element.createElement;
  var useState       = wp.element.useState;
  var useCallback    = wp.element.useCallback;
  var Fragment       = wp.element.Fragment;
  var registerPlugin = wp.plugins.registerPlugin;
  var PluginSidebar  = wp.editor.PluginSidebar || wp.editPost.PluginSidebar;
  var PluginSidebarMoreMenuItem = wp.editor.PluginSidebarMoreMenuItem || wp.editPost.PluginSidebarMoreMenuItem;
  var useSelect      = wp.data.useSelect;
  var useDispatch    = wp.data.useDispatch;
  var apiFetch       = wp.apiFetch;
  var __ = wp.i18n.__;

  var Panel          = wp.components.Panel;
  var PanelBody      = wp.components.PanelBody;
  var PanelRow       = wp.components.PanelRow;
  var TextareaControl = wp.components.TextareaControl;
  var Button         = wp.components.Button;
  var SelectControl  = wp.components.SelectControl;
  var Notice         = wp.components.Notice;
  var Spinner        = wp.components.Spinner;
  var Icon           = wp.components.Icon;

  var SIDEBAR_NAME = 'ai-composer-sidebar';

  function AiComposerIcon() {
    return el('svg', {
      width: 24,
      height: 24,
      viewBox: '0 0 24 24',
      fill: 'none',
      xmlns: 'http://www.w3.org/2000/svg'
    },
      el('path', {
        d: 'M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5',
        stroke: 'currentColor',
        strokeWidth: '2',
        strokeLinecap: 'round',
        strokeLinejoin: 'round',
        fill: 'none'
      })
    );
  }

  function ComposerSidebar() {
    var config = window.aiComposerConfig || {};

    var _promptState = useState('');
    var prompt = _promptState[0];
    var setPrompt = _promptState[1];

    var _modeState = useState('append');
    var insertMode = _modeState[0];
    var setInsertMode = _modeState[1];

    var _composeModeState = useState('new_content');
    var composeMode = _composeModeState[0];
    var setComposeMode = _composeModeState[1];

    var _loadingState = useState(false);
    var isLoading = _loadingState[0];
    var setIsLoading = _loadingState[1];

    var _errorState = useState(null);
    var error = _errorState[0];
    var setError = _errorState[1];

    var _resultState = useState(null);
    var result = _resultState[0];
    var setResult = _resultState[1];

    var blockEditor = useDispatch('core/block-editor');
    var insertBlocks = blockEditor.insertBlocks;
    var replaceBlocks = blockEditor.replaceBlocks;
    var resetBlocks = blockEditor.resetBlocks;

    var editorState = useSelect(function (select) {
      var editor = select('core/block-editor');
      var selectedId = editor.getSelectedBlockClientId();
      var selectedBlock = selectedId ? editor.getBlock(selectedId) : null;

      return {
        blockCount: editor.getBlockCount(),
        blockOrder: editor.getBlockOrder(),
        selectedBlockId: selectedId,
        selectedBlock: selectedBlock,
        allBlocks: editor.getBlocks(),
        currentTemplate: select('core/editor')
          ? select('core/editor').getEditedPostAttribute('template') || ''
          : '',
      };
    }, []);

    function buildBlockFromTreeNode(node) {
      if (!node || typeof node !== 'object' || !node.name) {
        return null;
      }

      var attrs = (node.attributes && typeof node.attributes === 'object')
        ? node.attributes
        : {};

      var innerNodes = Array.isArray(node.innerBlocks) ? node.innerBlocks : [];
      var innerBlocks = innerNodes
        .map(buildBlockFromTreeNode)
        .filter(function (block) { return !!block; });

      return wp.blocks.createBlock(node.name, attrs, innerBlocks);
    }

    function buildBlocksFromTree(tree) {
      if (!Array.isArray(tree)) {
        return [];
      }

      return tree
        .map(buildBlockFromTreeNode)
        .filter(function (block) { return !!block; });
    }

    function serializeBlockForContext(block) {
      if (!block) return null;
      return {
        name: block.name,
        attributes: block.attributes,
        innerBlocks: (block.innerBlocks || []).map(serializeBlockForContext).filter(Boolean),
      };
    }

    function serializePageContext(blocks) {
      if (!Array.isArray(blocks) || blocks.length === 0) return null;
      return blocks.map(serializeBlockForContext).filter(Boolean);
    }

    var handleCompose = useCallback(function () {
      if (!prompt.trim()) return;

      setIsLoading(true);
      setError(null);
      setResult(null);

      var requestData = {
        prompt: prompt,
        template: editorState.currentTemplate,
        compose_mode: composeMode,
        insert_mode: insertMode,
      };

      if (composeMode === 'selected_block' && editorState.selectedBlock) {
        requestData.selected_block_context = serializeBlockForContext(editorState.selectedBlock);
      }

      if (composeMode === 'page' && editorState.allBlocks.length > 0) {
        requestData.page_context = serializePageContext(editorState.allBlocks);
      }

      apiFetch({
        path: '/' + config.restNamespace + '/compose',
        method: 'POST',
        data: requestData,
      })
      .then(function (response) {
        setIsLoading(false);

        if (!response.success) {
          setError(response.message || __('Composition failed.', 'wp-intelligence'));
          return;
        }

        setResult(response);

        var parsed = [];

        if (Array.isArray(response.blockTree) && response.blockTree.length > 0) {
          parsed = buildBlocksFromTree(response.blockTree);
        } else if (response.blocks) {
          parsed = wp.blocks.parse(response.blocks);
        }

        if (!parsed || parsed.length === 0) {
          setError(__('No valid blocks were generated.', 'wp-intelligence'));
          return;
        }

        if (composeMode === 'selected_block' && editorState.selectedBlockId) {
          replaceBlocks([editorState.selectedBlockId], parsed);
        } else if (composeMode === 'page') {
          var allIds = editorState.blockOrder;
          if (allIds.length > 0) {
            replaceBlocks(allIds, parsed);
          } else {
            insertBlocks(parsed);
          }
        } else if (insertMode === 'replace_all') {
          var allIds = editorState.blockOrder;
          if (allIds.length > 0) {
            replaceBlocks(allIds, parsed);
          } else {
            insertBlocks(parsed);
          }
        } else if (insertMode === 'insert_after' && editorState.selectedBlockId) {
          var idx = editorState.blockOrder.indexOf(editorState.selectedBlockId);
          insertBlocks(parsed, idx + 1);
        } else {
          insertBlocks(parsed);
        }
      })
      .catch(function (err) {
        setIsLoading(false);
        var msg = err.message || err.data?.message || __('An unexpected error occurred.', 'wp-intelligence');
        setError(msg);
      });
    }, [prompt, insertMode, composeMode, editorState]);

    var providerReady = config.providerReady;

    return el(Fragment, null,
      el(PluginSidebarMoreMenuItem, {
        target: SIDEBAR_NAME,
        icon: el(AiComposerIcon),
      }, __('WP Intelligence', 'wp-intelligence')),

      el(PluginSidebar, {
        name: SIDEBAR_NAME,
        title: __('WP Intelligence', 'wp-intelligence'),
        icon: el(AiComposerIcon),
      },
        el(Panel, null,
          el(PanelBody, {
            title: __('Compose Page', 'wp-intelligence'),
            initialOpen: true,
          },

            !providerReady && el(Notice, {
              status: 'warning',
              isDismissible: false,
              className: 'ai-composer-notice',
            }, __('No AI provider configured. Add an API key in Settings → WP Intelligence.', 'wp-intelligence')),

            el(TextareaControl, {
              label: __('Describe the page you want to build', 'wp-intelligence'),
              help: __('Be specific about sections, layout, and content. Reference patterns by name if you know them.', 'wp-intelligence'),
              value: prompt,
              onChange: setPrompt,
              rows: 6,
              placeholder: __('e.g. Create a landing page with a hero section, three feature columns, a testimonial quote, and a call-to-action button.', 'wp-intelligence'),
              disabled: isLoading,
            }),

            el(SelectControl, {
              label: __('Compose mode', 'wp-intelligence'),
              value: composeMode,
              options: [
                { label: __('New content', 'wp-intelligence'), value: 'new_content' },
                { label: __('Optimize selected block', 'wp-intelligence'), value: 'selected_block' },
                { label: __('Optimize entire page', 'wp-intelligence'), value: 'page' },
              ],
              onChange: setComposeMode,
              disabled: isLoading,
            }),

            composeMode === 'selected_block' && !editorState.selectedBlockId && el(Notice, {
              status: 'info',
              isDismissible: false,
              className: 'ai-composer-notice',
            }, __('Select a block in the editor to optimize it.', 'wp-intelligence')),

            composeMode === 'new_content' && el(SelectControl, {
              label: __('Insert mode', 'wp-intelligence'),
              value: insertMode,
              options: [
                { label: __('Append to page', 'wp-intelligence'), value: 'append' },
                { label: __('Replace all content', 'wp-intelligence'), value: 'replace_all' },
                { label: __('Insert after selected block', 'wp-intelligence'), value: 'insert_after' },
              ],
              onChange: setInsertMode,
              disabled: isLoading,
            }),

            el(PanelRow, null,
              el(Button, {
                variant: 'primary',
                onClick: handleCompose,
                disabled: isLoading || !prompt.trim() || !providerReady,
                isBusy: isLoading,
                className: 'ai-composer-generate-btn',
              },
                isLoading
                  ? el(Fragment, null, el(Spinner), ' ', __('Composing…', 'wp-intelligence'))
                  : __('Compose', 'wp-intelligence')
              )
            ),

            error && el(Notice, {
              status: 'error',
              isDismissible: true,
              onDismiss: function () { setError(null); },
              className: 'ai-composer-notice',
            }, error),

            result && el(Notice, {
              status: 'success',
              isDismissible: true,
              onDismiss: function () { setResult(null); },
              className: 'ai-composer-notice',
            }, result.summary || __('Page composed successfully.', 'wp-intelligence'))
          ),

          el(PanelBody, {
            title: __('Tips', 'wp-intelligence'),
            initialOpen: false,
          },
            el('ul', { className: 'ai-composer-tips' },
              el('li', null, __('Mention specific sections: "hero", "features grid", "testimonials", "pricing table".', 'wp-intelligence')),
              el('li', null, __('Reference your patterns by name for production-ready layouts.', 'wp-intelligence')),
              el('li', null, __('Use "Replace all" mode to start fresh, or "Append" to add to existing content.', 'wp-intelligence')),
              el('li', null, __('After composing, you can edit any generated block normally.', 'wp-intelligence'))
            )
          )
        )
      )
    );
  }

  registerPlugin('wp-intelligence', {
    render: ComposerSidebar,
    icon: el(AiComposerIcon),
  });
})();
