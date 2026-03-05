(function (wp) {
  'use strict';

  var el = wp.element.createElement;
  var Fragment = wp.element.Fragment;
  var useState = wp.element.useState;
  var useEffect = wp.element.useEffect;
  var useCallback = wp.element.useCallback;
  var registerPlugin = wp.plugins.registerPlugin;
  var PluginSidebar = wp.editPost.PluginSidebar;
  var PluginSidebarMoreMenuItem = wp.editPost.PluginSidebarMoreMenuItem;
  var registerFormatType = wp.richText.registerFormatType;
  var insert = wp.richText.insert;
  var create = wp.richText.create;
  var PanelBody = wp.components.PanelBody;
  var Button = wp.components.Button;
  var TextControl = wp.components.TextControl;
  var SelectControl = wp.components.SelectControl;
  var Modal = wp.components.Modal;
  var SearchControl = wp.components.SearchControl;
  var Icon = wp.components.Icon;
  var __ = wp.i18n.__;
  var RichTextToolbarButton = wp.blockEditor.RichTextToolbarButton;
  var addFilter = wp.hooks.addFilter;

  var config = window.wpiDynamicDataConfig || { sources: [], tags: [] };

  /* ──────────────────────────────────────────────
   *  {{ autocomplete completer for rich text blocks
   * ────────────────────────────────────────────── */

  addFilter(
    'editor.Autocomplete.completers',
    'wpi/merge-tag-completer',
    function (completers) {
      var tags = config.tags || [];
      return [].concat(completers, [{
        name: 'wpi-merge-tags',
        triggerPrefix: '{{',
        options: tags.map(function (t) {
          return {
            tag: t.tag,
            label: t.label || t.tag,
            group: t.group || '',
          };
        }),
        getOptionLabel: function (option) {
          return el(
            'span',
            { className: 'wpi-autocomplete-option' },
            el('code', null, '{{' + option.tag + '}}'),
            el('span', { style: { marginLeft: '8px', opacity: 0.7, fontSize: '12px' } }, option.label)
          );
        },
        getOptionKeywords: function (option) {
          return [option.tag, option.label, option.group];
        },
        getOptionCompletion: function (option) {
          return '{{' + option.tag + '}}';
        },
      }]);
    }
  );

  /* ──────────────────────────────────────────────
   *  Merge Tag Picker Modal
   * ────────────────────────────────────────────── */

  function MergeTagPicker(props) {
    var onSelect = props.onSelect;
    var onClose = props.onClose;
    var searchTerm = useState('');
    var search = searchTerm[0];
    var setSearch = searchTerm[1];

    var tags = config.tags || [];

    var groups = {};
    tags.forEach(function (tag) {
      var group = tag.group || 'Other';
      if (!groups[group]) groups[group] = [];
      groups[group].push(tag);
    });

    var filteredGroups = {};
    Object.keys(groups).forEach(function (groupName) {
      var filtered = groups[groupName].filter(function (tag) {
        if (!search) return true;
        var s = search.toLowerCase();
        return (
          tag.tag.toLowerCase().indexOf(s) !== -1 ||
          tag.label.toLowerCase().indexOf(s) !== -1 ||
          groupName.toLowerCase().indexOf(s) !== -1
        );
      });
      if (filtered.length > 0) {
        filteredGroups[groupName] = filtered;
      }
    });

    return el(
      Modal,
      {
        title: __('Insert Merge Tag', 'wp-intelligence'),
        onRequestClose: onClose,
        className: 'wpi-merge-tag-modal',
      },
      el(
        'div',
        { className: 'wpi-merge-tag-picker' },
        el(SearchControl, {
          value: search,
          onChange: setSearch,
          placeholder: __('Search merge tags…', 'wp-intelligence'),
        }),
        el(
          'div',
          { className: 'wpi-merge-tag-list' },
          Object.keys(filteredGroups).map(function (groupName) {
            return el(
              'div',
              { key: groupName, className: 'wpi-tag-group' },
              el('h4', { className: 'wpi-tag-group-title' }, groupName),
              filteredGroups[groupName].map(function (tag) {
                return el(
                  'button',
                  {
                    key: tag.tag,
                    className: 'wpi-tag-item',
                    type: 'button',
                    onClick: function () {
                      onSelect('{{' + tag.tag + '}}');
                      onClose();
                    },
                    title: tag.label,
                  },
                  el('code', { className: 'wpi-tag-code' }, '{{' + tag.tag + '}}'),
                  el('span', { className: 'wpi-tag-label' }, tag.label)
                );
              })
            );
          }),
          Object.keys(filteredGroups).length === 0 &&
            el(
              'p',
              { className: 'wpi-no-tags' },
              __('No matching merge tags found.', 'wp-intelligence')
            )
        )
      )
    );
  }

  /* ──────────────────────────────────────────────
   *  Rich Text Format Button — insert merge tags inline
   * ────────────────────────────────────────────── */

  registerFormatType('wpi/merge-tag', {
    title: __('Dynamic Data', 'wp-intelligence'),
    tagName: 'span',
    className: 'wpi-merge-tag',
    edit: function (props) {
      var isOpen = useState(false);
      var open = isOpen[0];
      var setOpen = isOpen[1];

      return el(
        Fragment,
        null,
        el(RichTextToolbarButton, {
          icon: 'database',
          title: __('Insert Merge Tag', 'wp-intelligence'),
          onClick: function () {
            setOpen(true);
          },
        }),
        open &&
          el(MergeTagPicker, {
            onClose: function () {
              setOpen(false);
            },
            onSelect: function (tag) {
              props.onChange(
                insert(props.value, tag)
              );
            },
          })
      );
    },
  });

  /* ──────────────────────────────────────────────
   *  Sidebar Plugin — data sources overview + tag reference
   * ────────────────────────────────────────────── */

  function DynamicDataSidebar() {
    var sources = config.sources || [];
    var tags = config.tags || [];

    var groups = {};
    tags.forEach(function (tag) {
      var group = tag.group || 'Other';
      if (!groups[group]) groups[group] = [];
      groups[group].push(tag);
    });

    return el(
      Fragment,
      null,
      el(
        PluginSidebarMoreMenuItem,
        { target: 'wpi-dynamic-data-sidebar', icon: 'database' },
        __('Dynamic Data', 'wp-intelligence')
      ),
      el(
        PluginSidebar,
        {
          name: 'wpi-dynamic-data-sidebar',
          title: __('Dynamic Data', 'wp-intelligence'),
          icon: 'database',
        },
        el(
          PanelBody,
          { title: __('Data Sources', 'wp-intelligence'), initialOpen: true },
          sources.length === 0
            ? el('p', null, __('No data sources configured.', 'wp-intelligence'))
            : sources.map(function (source) {
                return el(
                  'div',
                  { key: source.name, className: 'wpi-source-item' },
                  el(
                    'div',
                    { className: 'wpi-source-header' },
                    el('strong', null, source.label || source.name),
                    el(
                      'span',
                      { className: 'wpi-source-type-badge' },
                      source.type
                    )
                  ),
                  el(
                    'code',
                    { className: 'wpi-source-name' },
                    '{{' + source.name + '.…}}'
                  )
                );
              })
        ),
        el(
          PanelBody,
          { title: __('Merge Tag Reference', 'wp-intelligence'), initialOpen: false },
          el(
            'p',
            { className: 'description' },
            __(
              'Use these tags in text blocks. They resolve to dynamic values on the frontend.',
              'wp-intelligence'
            )
          ),
          Object.keys(groups).map(function (groupName) {
            return el(
              'div',
              { key: groupName, className: 'wpi-tag-reference-group' },
              el('h4', null, groupName),
              groups[groupName].map(function (tag) {
                return el(
                  'div',
                  { key: tag.tag, className: 'wpi-tag-reference-item' },
                  el('code', null, '{{' + tag.tag + '}}'),
                  el('span', null, ' — ' + tag.label)
                );
              })
            );
          })
        ),
        el(
          PanelBody,
          { title: __('How to Use', 'wp-intelligence'), initialOpen: false },
          el(
            'div',
            { className: 'wpi-help-content' },
            el('h4', null, __('Value Tags', 'wp-intelligence')),
            el('p', null, __('Type merge tags directly in any text block:', 'wp-intelligence')),
            el('code', { style: { display: 'block', padding: '8px', background: '#f0f0f0', borderRadius: '4px', marginBottom: '8px' } }, '{{wp.post.title}}'),
            el('code', { style: { display: 'block', padding: '8px', background: '#f0f0f0', borderRadius: '4px', marginBottom: '8px' } }, '{{storage.user_plan}}'),
            el('p', null, __('Add a fallback with the pipe character:', 'wp-intelligence')),
            el('code', { style: { display: 'block', padding: '8px', background: '#f0f0f0', borderRadius: '4px', marginBottom: '12px' } }, '{{url.name|Visitor}}'),

            el('h4', { style: { marginTop: '16px' } }, __('Conditionals', 'wp-intelligence')),
            el('p', null, __('Show content based on conditions:', 'wp-intelligence')),
            el('code', { style: { display: 'block', padding: '8px', background: '#f0f0f0', borderRadius: '4px', marginBottom: '8px', whiteSpace: 'pre-wrap', fontSize: '12px' } },
              '{{#if wp.user.role == "administrator"}}\n  Admin content here\n{{#else}}\n  Regular content\n{{/if}}'
            ),
            el('p', null, __('Supported operators: ==, !=, >, <, contains, !contains', 'wp-intelligence')),
            el('p', null, __('Truthy check (non-empty):', 'wp-intelligence')),
            el('code', { style: { display: 'block', padding: '8px', background: '#f0f0f0', borderRadius: '4px', marginBottom: '12px', whiteSpace: 'pre-wrap', fontSize: '12px' } },
              '{{#if storage.onboarded}}\n  Welcome back!\n{{/if}}'
            ),

            el('h4', { style: { marginTop: '16px' } }, __('Browser Storage', 'wp-intelligence')),
            el('p', null, __('Read from localStorage/sessionStorage:', 'wp-intelligence')),
            el('code', { style: { display: 'block', padding: '8px', background: '#f0f0f0', borderRadius: '4px', marginBottom: '8px' } }, '{{storage.preferred_lang|en}}'),
            el('p', { style: { fontSize: '12px', color: '#757575' } },
              __('Storage tags are resolved client-side via JavaScript.', 'wp-intelligence')
            ),

            el('h4', { style: { marginTop: '16px' } }, __('Block Visibility', 'wp-intelligence')),
            el('p', null,
              __('Use any data source in block visibility conditions. Select "Dynamic Data" in the visibility controls panel to show/hide blocks based on webhook responses, URL params, cookies, or browser storage.', 'wp-intelligence')
            )
          )
        )
      )
    );
  }

  registerPlugin('wpi-dynamic-data', {
    render: DynamicDataSidebar,
    icon: 'database',
  });
})(window.wp);
