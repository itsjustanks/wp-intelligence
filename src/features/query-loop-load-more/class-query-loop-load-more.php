<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Adds load-more / infinite-scroll to the core Query Loop pagination block.
 *
 * Hooks into register_block_type_args to extend core/query-pagination with
 * custom attributes and a replacement render callback that emits an AJAX-
 * powered "load more" button instead of standard pagination links.
 */
class WPI_Query_Loop_Load_More {

  private static string $mod_dir = '';
  private static string $mod_url = '';

  public static function boot(): void {
    self::$mod_dir = __DIR__;
    self::$mod_url = WPI_URL . 'src/features/query-loop-load-more/';

    add_filter('register_block_type_args', [self::class, 'register_attributes'], 10, 2);
    add_action('enqueue_block_editor_assets', [self::class, 'enqueue_editor_assets']);
    add_action('wp_enqueue_scripts', [self::class, 'register_frontend_assets']);
    add_filter('render_block_core/query', [self::class, 'tag_query_region'], 20);
  }

  /**
   * Extend core/query-pagination with load-more attributes, render callback,
   * and conditional asset handles.
   */
  public static function register_attributes(array $settings, string $name): array {
    if ('core/query-pagination' !== $name || ! function_exists('render_block_core_query_pagination')) {
      return $settings;
    }

    $settings['render_callback'] = [self::class, 'render'];

    $settings['attributes']['loadMore'] = [
      'type'    => 'boolean',
      'default' => false,
    ];
    $settings['attributes']['infiniteScroll'] = [
      'type'    => 'boolean',
      'default' => false,
    ];
    $settings['attributes']['infiniteScrollColor'] = [
      'type'    => 'string',
      'default' => '#000',
    ];
    $settings['attributes']['loadMoreText'] = [
      'type'    => 'string',
      'default' => __('Load More', 'wp-intelligence'),
    ];
    $settings['attributes']['loadingText'] = [
      'type'    => 'string',
      'default' => __('Loading...', 'wp-intelligence'),
    ];
    $settings['attributes']['updateUrl'] = [
      'type'    => 'boolean',
      'default' => false,
    ];

    $settings['style_handles'][]  = 'wpi-load-more';
    $settings['script_handles'][] = 'wpi-load-more';

    return $settings;
  }

  /**
   * Render the query-pagination block as a load-more button (or fall back
   * to the core renderer when load-more is disabled).
   */
  public static function render(array $attributes, string $content, \WP_Block $block): string {
    if (empty($attributes['loadMore'])) {
      return render_block_core_query_pagination($attributes, $content);
    }

    global $wp_query;

    $arrow_map = [
      'none'    => '',
      'arrow'   => '→',
      'chevron' => '»',
    ];

    $query_id       = (int) ($block->context['queryId'] ?? 0);
    $page_key       = isset($block->context['queryId']) ? 'query-' . $query_id . '-page' : 'query-page';
    $inherit        = $block->context['query']['inherit'] ?? false;
    $is_infinite    = ! empty($attributes['infiniteScroll']);
    $is_update_url  = ! empty($attributes['updateUrl']);
    $page_parameter = $inherit ? 'paged' : $page_key;

    if ($inherit) {
      $page = $wp_query->is_paged ? (int) $wp_query->get('paged') : 1;
    } else {
      // phpcs:ignore WordPress.Security.NonceVerification.Recommended
      $page = (int) ($_GET[$page_key] ?? 1);
    }

    $block_query = $inherit
      ? $wp_query
      : new \WP_Query(build_query_vars_from_query_block($block, $page));

    $max_pages = empty($block->context['query']['pages'])
      ? $block_query->max_num_pages
      : (int) $block->context['query']['pages'];

    if ($page >= $max_pages) {
      return '';
    }

    $button_classes = $is_infinite
      ? 'wp-load-more__button wp-load-more__infinite-scroll'
      : 'wp-block-button__link wp-element-button wp-load-more__button';

    $pagination_arrow = ('none' !== $attributes['paginationArrow'])
      ? '<span class="wp-block-query-pagination__arrow">' . esc_html($arrow_map[$attributes['paginationArrow']] ?? '') . '</span>'
      : '';

    $infinite_markup = '';
    if ($is_infinite) {
      $infinite_markup = sprintf(
        '<div class="animation-wrapper" style="border-color: %s"><div></div><div></div></div>',
        esc_attr($attributes['infiniteScrollColor'])
      );
    }

    $link = sprintf(
      '<a class="%1$s" href="?%2$s=%3$d" data-query-next-page="%3$d" data-query-key="%4$d" data-query-max-page="%5$d" data-query-url="%2$s" data-update-url="%6$s"><span class="wpi-lm-loading">%7$s%8$s</span><span class="wpi-lm-label%9$s">%10$s</span></a>',
      esc_attr($button_classes),
      esc_attr($page_parameter),
      $page + 1,
      $query_id,
      $max_pages,
      $is_update_url ? 'true' : '',
      $is_infinite ? '' : esc_html($attributes['loadingText']),
      $infinite_markup,
      $is_infinite ? ' screen-reader-text' : '',
      $is_infinite
        ? esc_html($attributes['loadMoreText'])
        : esc_html($attributes['loadMoreText']) . $pagination_arrow
    );

    return '<div class="is-layout-flex wp-block-buttons"><div class="wp-block-button aligncenter">' . $link . '</div></div>';
  }

  /**
   * Stamp each core/query wrapper with a region index so the frontend JS
   * can match fetched content to the correct query loop on the page.
   */
  public static function tag_query_region(string $block_content): string {
    static $counter = 1;

    $p = new WP_HTML_Tag_Processor($block_content);
    if ($p->next_tag(['class_name' => 'wp-block-query'])) {
      $p->set_attribute('data-wpi-query-region', $counter++);
      $block_content = $p->get_updated_html();
    }

    return $block_content;
  }

  public static function enqueue_editor_assets(): void {
    $js_path = self::$mod_dir . '/editor/editor.js';
    wp_enqueue_script(
      'wpi-load-more-editor',
      self::$mod_url . 'editor/editor.js',
      ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-compose', 'wp-hooks', 'wp-i18n'],
      file_exists($js_path) ? (string) filemtime($js_path) : WPI_VERSION,
      true
    );

    $css_path = self::$mod_dir . '/editor/editor.css';
    wp_enqueue_style(
      'wpi-load-more-editor',
      self::$mod_url . 'editor/editor.css',
      [],
      file_exists($css_path) ? (string) filemtime($css_path) : WPI_VERSION
    );
  }

  /**
   * Register (not enqueue) frontend assets. They are loaded conditionally
   * via the block type's style_handles / script_handles.
   */
  public static function register_frontend_assets(): void {
    $js_path = self::$mod_dir . '/frontend/frontend.js';
    wp_register_script(
      'wpi-load-more',
      self::$mod_url . 'frontend/frontend.js',
      ['wp-dom-ready'],
      file_exists($js_path) ? (string) filemtime($js_path) : WPI_VERSION,
      true
    );

    $css_path = self::$mod_dir . '/frontend/frontend.css';
    wp_register_style(
      'wpi-load-more',
      self::$mod_url . 'frontend/frontend.css',
      [],
      file_exists($css_path) ? (string) filemtime($css_path) : WPI_VERSION
    );
  }
}
