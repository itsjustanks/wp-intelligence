<?php
/**
 * Main Block Visibility module class.
 *
 * @package wp-intelligence
 * @since   0.3.0
 */

defined('ABSPATH') || exit;

class WPI_Block_Visibility {

  public function init() {
    $this->includes();
    $this->init_hooks();
  }

  public function includes() {
    $dir = WPI_BV_DIR;

    include_once $dir . '/settings/register-settings.php';
    include_once $dir . '/api/register-routes.php';
    include_once $dir . '/presets/register-presets.php';
    include_once $dir . '/utils/user-functions.php';

    if (is_admin() && ! (defined('DOING_AJAX') && DOING_AJAX)) {
      include_once $dir . '/admin/editor.php';
      include_once $dir . '/admin/settings.php';
      include_once $dir . '/utils/get-asset-file.php';
    }

    if (! is_admin()) {
      include_once $dir . '/frontend/render-block.php';
    }
  }

  public function init_hooks() {
    if (did_action('init')) {
      $this->load_textdomain();
    } else {
      add_action('init', [$this, 'load_textdomain']);
    }
    add_action('enqueue_block_editor_assets', [$this, 'editor_scripts_localization']);
    add_action('admin_enqueue_scripts', [$this, 'setting_scripts_localization']);
    add_action('wp_loaded', [$this, 'add_attributes_to_registered_blocks'], 999);
    add_filter('rest_pre_dispatch', [$this, 'conditionally_remove_attributes'], 10, 3);
  }

  /**
   * Add the blockVisibility attribute to all registered blocks.
   */
  public function add_attributes_to_registered_blocks() {
    $registered_blocks = WP_Block_Type_Registry::get_instance()->get_all_registered();

    foreach ($registered_blocks as $name => $block) {
      $block->attributes['blockVisibility'] = ['type' => 'object'];
    }
  }

  /**
   * Fix REST API issue with server-side-rendered blocks.
   */
  public function conditionally_remove_attributes($result, $server, $request) {
    if (strpos($request->get_route(), '/wp/v2/block-renderer') !== false) {
      if (isset($request['attributes']) && isset($request['attributes']['blockVisibility'])) {
        $attributes = $request['attributes'];
        unset($attributes['blockVisibility']);
        $request['attributes'] = $attributes;
      }
    }

    return $result;
  }

  public function load_textdomain() {
    load_plugin_textdomain(
      'block-visibility',
      false,
      dirname(plugin_basename(WPI_FILE)) . '/src/features/block-visibility/languages'
    );
  }

  public function editor_scripts_localization() {
    if (function_exists('wp_set_script_translations')) {
      wp_set_script_translations(
        'block-visibility-editor-scripts',
        'block-visibility',
        WPI_BV_DIR . '/languages'
      );
    }
  }

  public function setting_scripts_localization() {
    if (function_exists('wp_set_script_translations')) {
      wp_set_script_translations(
        'block-visibility-setting-scripts',
        'block-visibility',
        WPI_BV_DIR . '/languages'
      );
    }
  }
}
