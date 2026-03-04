/**
 * Query Loop Load More — editor UI.
 *
 * Extends core/query-pagination with a toolbar toggle and inspector panel
 * for load-more / infinite-scroll settings. Written with createElement
 * (no build step required).
 */
(function () {
  'use strict';

  var el       = wp.element.createElement;
  var Fragment = wp.element.Fragment;
  var __       = wp.i18n.__;

  var InspectorControls = wp.blockEditor.InspectorControls;
  var BlockControls     = wp.blockEditor.BlockControls;

  var ToolbarGroup  = wp.components.ToolbarGroup;
  var ToolbarButton = wp.components.ToolbarButton;
  var ToggleControl = wp.components.ToggleControl;
  var PanelBody     = wp.components.PanelBody;
  var TextControl   = wp.components.TextControl;
  var ColorPicker   = wp.components.ColorPicker;
  var BaseControl   = wp.components.BaseControl;

  var addFilter                = wp.hooks.addFilter;
  var createHigherOrderComponent = wp.compose.createHigherOrderComponent;

  var ARROW_MAP = { none: '', arrow: '\u2192', chevron: '\u00BB' };

  addFilter(
    'editor.BlockEdit',
    'wpi-load-more/controls',
    createHigherOrderComponent(function (BlockEdit) {
      return function (props) {
        if (props.name !== 'core/query-pagination') {
          return el(BlockEdit, props);
        }

        var attrs         = props.attributes;
        var set           = props.setAttributes;
        var loadMore      = attrs.loadMore;
        var infiniteScroll = attrs.infiniteScroll;
        var loadMoreText  = attrs.loadMoreText;
        var loadingText   = attrs.loadingText;
        var updateUrl     = attrs.updateUrl;
        var scrollColor   = attrs.infiniteScrollColor;
        var layout        = attrs.layout;
        var arrow         = attrs.paginationArrow;
        var cls           = attrs.className || '';
        var justify       = layout && layout.justifyContent
          ? 'is-content-justification-' + layout.justifyContent
          : '';

        function toggleLoadMore() {
          var next = loadMore
            ? cls.split(' ').filter(function (c) { return c !== 'load-more' && c.trim(); }).join(' ')
            : cls.trim() + ' load-more';
          set({ loadMore: !loadMore, className: next });
        }

        function toggleInfiniteScroll() {
          var next = infiniteScroll
            ? cls.split(' ').filter(function (c) { return c !== 'infinite-scroll' && c.trim(); }).join(' ')
            : cls.trim() + ' infinite-scroll';
          set({ infiniteScroll: !infiniteScroll, className: next });
        }

        var displayArrow = ARROW_MAP[arrow] || '';

        // --- Build children ---
        var children = [];

        // Toolbar toggle
        children.push(
          el(BlockControls, { key: 'tb' },
            el(ToolbarGroup, null,
              el(ToolbarButton, {
                icon: 'controls-repeat',
                label: __('Use load more button?', 'wp-intelligence'),
                onClick: toggleLoadMore,
                className: loadMore ? 'is-pressed' : undefined
              })
            )
          )
        );

        // Original block
        children.push(el(BlockEdit, Object.assign({ key: 'be' }, props)));

        // Preview
        if (loadMore && !infiniteScroll) {
          var btnKids = [loadMoreText];
          if (displayArrow) {
            btnKids.push(
              el('span', {
                className: 'wp-block-query-pagination-next-arrow is-arrow-' + arrow,
                'aria-hidden': true
              }, displayArrow)
            );
          }
          children.push(
            el('div', { key: 'btn', className: 'is-layout-flex wp-block-buttons load-more-button-wrap ' + justify },
              el('div', { className: 'wp-block-button' },
                el('a', { className: 'wp-block-button__link wp-load-more__button', href: '#' }, btnKids)
              )
            )
          );
        }

        if (loadMore && infiniteScroll) {
          children.push(
            el('div', { key: 'inf', className: 'is-layout-flex wp-load-more__infinite-scroll ' + justify },
              el('div', { className: 'animation-wrapper', style: { borderColor: scrollColor } },
                el('div'), el('div')
              )
            )
          );
        }

        // Inspector
        var panelKids = [];

        panelKids.push(
          el(ToggleControl, {
            key: 'lm',
            label: __('Use load more button?', 'wp-intelligence'),
            checked: !!loadMore,
            onChange: toggleLoadMore
          })
        );

        if (loadMore) {
          panelKids.push(
            el(ToggleControl, {
              key: 'is',
              label: __('Use infinite scroll?', 'wp-intelligence'),
              checked: !!infiniteScroll,
              onChange: toggleInfiniteScroll
            })
          );

          if (infiniteScroll) {
            panelKids.push(
              el(BaseControl, {
                key: 'cl',
                label: __('Loading animation color', 'wp-intelligence'),
                id: 'wpi-infinite-scroll-color'
              }),
              el(ColorPicker, {
                key: 'cp',
                color: scrollColor,
                onChange: function (v) { set({ infiniteScrollColor: v }); },
                enableAlpha: true,
                defaultValue: '#000'
              })
            );
          } else {
            panelKids.push(
              el(TextControl, {
                key: 'lt',
                label: __('Loading text', 'wp-intelligence'),
                value: loadingText,
                onChange: function (v) { set({ loadingText: v }); }
              })
            );
          }

          panelKids.push(
            el(TextControl, {
              key: 'bt',
              label: __('Load more button text', 'wp-intelligence'),
              help: __('Text to display on the load more button. Also used as the button text for screen readers when infinite scroll is enabled.', 'wp-intelligence'),
              value: loadMoreText,
              onChange: function (v) { set({ loadMoreText: v }); }
            }),
            el(ToggleControl, {
              key: 'uu',
              label: __('Update URL', 'wp-intelligence'),
              help: __("Updates the browser's URL when loading more posts.", 'wp-intelligence'),
              checked: !!updateUrl,
              onChange: function (v) { set({ updateUrl: v }); }
            })
          );
        }

        children.push(
          el(InspectorControls, { key: 'ins' },
            el(PanelBody, { title: __('Load More', 'wp-intelligence') }, panelKids)
          )
        );

        return el(Fragment, null, children);
      };
    })
  );
})();
