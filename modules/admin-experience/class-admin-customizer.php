<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Configurable admin experience customizer.
 *
 * Every feature is driven by filters so themes provide config arrays
 * without needing to duplicate menu-walking logic.
 */
class AI_Composer_Admin_Customizer {

  public static function boot(): void {
    self::init();
  }

  public static function init(): void {
    $settings = AI_Composer_Settings::get_admin_experience_settings();

    if (! empty($settings['remove_wp_logo'])) {
      add_action('wp_before_admin_bar_render', [self::class, 'remove_admin_bar_logo'], 0);
    }

    if (! empty($settings['custom_login_logo'])) {
      add_action('login_head', [self::class, 'render_login_logo']);
    }

    if (! empty($settings['hide_update_notices'])) {
      add_action('admin_head', [self::class, 'hide_update_notices_for_non_admins'], 1);
    }

    if (! empty($settings['custom_footer'])) {
      add_filter('admin_footer_text', [self::class, 'render_footer_text']);
    }

    if (! empty($settings['menu_reorganization'])) {
      add_action('admin_menu', [self::class, 'reorganize_menus'], 999);
    }

    if (($settings['editor_enhancements'] ?? '0') === '1') {
      add_action('enqueue_block_editor_assets', [self::class, 'enqueue_editor_enhancements']);
    }
  }

  // ------------------------------------------------------------------
  // Editor enhancements
  // ------------------------------------------------------------------

  public static function enqueue_editor_enhancements(): void {
    if (! current_user_can('edit_posts')) {
      return;
    }

    $settings = AI_Composer_Settings::get_admin_experience_settings();
    $js_path  = __DIR__ . '/editor/block-editor-enhancements.js';
    $js_url   = defined('WPI_URL') ? WPI_URL . 'modules/admin-experience/editor/block-editor-enhancements.js' : '';

    if ($js_url === '' || ! file_exists($js_path)) {
      return;
    }

    wp_enqueue_script(
      'wpi-editor-enhancements',
      $js_url,
      ['wp-dom-ready', 'wp-data'],
      (string) filemtime($js_path),
      true
    );

    $autocomplete_source = '';
    if (($settings['class_autocomplete'] ?? '0') === '1') {
      $autocomplete_source = (string) apply_filters('ai_composer_class_autocomplete_source', '');
    }

    wp_localize_script('wpi-editor-enhancements', 'aiComposerEditorEnhancements', [
      'forceFullscreen'         => ($settings['force_fullscreen'] ?? '0') === '1',
      'autoOpenListView'        => ($settings['auto_open_list_view'] ?? '0') === '1',
      'listViewLabel'           => (string) ($settings['list_view_label'] ?? ''),
      'classAutocomplete'       => ($settings['class_autocomplete'] ?? '0') === '1',
      'classAutocompleteSource' => $autocomplete_source,
    ]);
  }

  // ------------------------------------------------------------------
  // Admin bar
  // ------------------------------------------------------------------

  public static function remove_admin_bar_logo(): void {
    global $wp_admin_bar;
    $wp_admin_bar->remove_menu('wp-logo');

    do_action('ai_composer_admin_bar_cleanup', $wp_admin_bar);
  }

  // ------------------------------------------------------------------
  // Login logo
  // ------------------------------------------------------------------

  public static function render_login_logo(): void {
    $defaults = [
      'image_url'  => '',
      'width'      => 234,
      'height'     => 67,
    ];

    $logo = apply_filters('ai_composer_login_logo', $defaults);
    $url  = esc_url((string) ($logo['image_url'] ?? ''));
    $w    = absint($logo['width'] ?? 234);
    $h    = absint($logo['height'] ?? 67);

    if ($url === '') {
      return;
    }

    printf(
      '<style>.login h1 a{background-image:url(%s)!important;background-size:%dpx %dpx;width:%dpx;height:%dpx;display:block}</style>',
      $url,
      $w,
      $h,
      $w,
      $h
    );
  }

  // ------------------------------------------------------------------
  // Update notices
  // ------------------------------------------------------------------

  public static function hide_update_notices_for_non_admins(): void {
    $capability = apply_filters('ai_composer_update_notice_capability', 'update_core');
    if (! current_user_can($capability)) {
      remove_action('admin_notices', 'update_nag', 3);
    }
  }

  // ------------------------------------------------------------------
  // Footer
  // ------------------------------------------------------------------

  public static function render_footer_text(): string {
    $default = '';
    return (string) apply_filters('ai_composer_admin_footer_text', $default);
  }

  // ------------------------------------------------------------------
  // Menu reorganization
  // ------------------------------------------------------------------

  public static function reorganize_menus(): void {
    global $menu, $submenu;

    /**
     * Main menu config. Each entry can contain:
     *   target   => 'title' | 'url'
     *   new_name => string
     *   position => parent slug to nest under
     *   order    => int
     *   hide     => bool
     */
    $menu_config = (array) apply_filters('ai_composer_admin_menu_config', []);

    /**
     * Submenu moves. Structure:
     *   parent_slug => [ submenu_slug => [ 'new_parent_slug' => ..., 'new_title' => ... ] ]
     */
    $submenu_moves = (array) apply_filters('ai_composer_admin_submenu_moves', []);

    if (empty($menu_config) && empty($submenu_moves)) {
      return;
    }

    self::apply_menu_config($menu, $submenu, $menu_config);
    self::apply_submenu_moves($submenu, $submenu_moves);
    self::apply_menu_ordering($menu, $menu_config);
  }

  private static function apply_menu_config(array &$menu, array &$submenu, array $config): void {
    foreach ($menu as $key => &$item) {
      $title = $item[0];
      $slug  = $item[2];

      foreach ($config as $identifier => $settings) {
        $match = false;
        if (($settings['target'] ?? '') === 'url' && $slug === $identifier) {
          $match = true;
        } elseif (($settings['target'] ?? '') === 'title' && $title === $identifier) {
          $match = true;
        }

        if (! $match) {
          continue;
        }

        if (isset($settings['new_name'])) {
          $item[0] = $settings['new_name'];
        }

        if (isset($settings['position'])) {
          self::move_to_submenu($menu, $submenu, $key, $item, (string) $settings['position']);
        }

        if (! empty($settings['hide'])) {
          unset($menu[$key]);
        }

        break;
      }
    }
    unset($item);
  }

  private static function move_to_submenu(array &$menu, array &$submenu, int|string $key, array $item, string $parent_slug): void {
    $menu_slugs = array_column($menu, 2);

    if (! in_array($parent_slug, $menu_slugs, true)) {
      if (strpos($parent_slug, 'admin.php?page=') === 0) {
        $normalized = substr($parent_slug, strlen('admin.php?page='));
        if ($normalized !== '' && in_array($normalized, $menu_slugs, true)) {
          $parent_slug = $normalized;
        }
      } else {
        $query = 'admin.php?page=' . $parent_slug;
        if (in_array($query, $menu_slugs, true)) {
          $parent_slug = $query;
        }
      }
    }

    if (! isset($submenu[$parent_slug])) {
      $submenu[$parent_slug] = [];
    }

    $parent_item = null;
    foreach ($menu as $candidate) {
      if (isset($candidate[2]) && (string) $candidate[2] === $parent_slug) {
        $parent_item = $candidate;
        break;
      }
    }

    if ($parent_item !== null) {
      $exists = false;
      foreach ($submenu[$parent_slug] as $sub) {
        if (isset($sub[2]) && (string) $sub[2] === $parent_slug) {
          $exists = true;
          break;
        }
      }

      if (! $exists) {
        array_unshift($submenu[$parent_slug], [
          $parent_item[0] ?? '',
          $parent_item[1] ?? 'manage_options',
          $parent_slug,
          $parent_item[3] ?? ($parent_item[0] ?? ''),
        ]);
      }
    }

    $item[4] = '';
    unset($item[5]);
    $submenu[$parent_slug][] = $item;
    unset($menu[$key]);
  }

  private static function apply_submenu_moves(array &$submenu, array $moves): void {
    foreach ($moves as $parent => $items) {
      if (! isset($submenu[$parent])) {
        continue;
      }

      foreach ($items as $slug => $cfg) {
        foreach ($submenu[$parent] as $idx => $sub) {
          if ($sub[2] !== $slug) {
            continue;
          }

          unset($submenu[$parent][$idx]);

          if (! empty($cfg['new_title'])) {
            $sub[0] = $cfg['new_title'];
          }

          $new_parent = (string) ($cfg['new_parent_slug'] ?? '');
          if ($new_parent === '') {
            continue;
          }

          if (! isset($submenu[$new_parent])) {
            $submenu[$new_parent] = [];
          }
          $submenu[$new_parent][] = $sub;
        }
      }
    }
  }

  private static function apply_menu_ordering(array &$menu, array $config): void {
    $has_order = false;
    foreach ($config as $settings) {
      if (isset($settings['order'])) {
        $has_order = true;
        break;
      }
    }

    if (! $has_order) {
      return;
    }

    add_filter('custom_menu_order', '__return_true');
    add_filter('menu_order', function (array $menu_order) use ($menu, $config): array {
      $slugs = array_column($menu, 2);
      $order = array_flip($slugs);

      foreach ($config as $slug => $settings) {
        if (isset($settings['order']) && in_array($slug, $slugs, true)) {
          $order[$slug] = (int) $settings['order'];
        }
      }

      asort($order);
      return array_keys($order);
    });
  }
}
