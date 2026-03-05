<?php
if (! defined('ABSPATH')) {
  exit;
}

class WPI_Canvas_Mode {

  public static function boot(): void {
    add_action('enqueue_block_editor_assets', [self::class, 'enqueue_editor_assets']);
  }

  public static function enqueue_editor_assets(): void {
    $build_dir  = WPI_DIR . '/build';
    $asset_file = $build_dir . '/canvas-mode-editor.asset.php';

    if (file_exists($asset_file)) {
      $asset = require $asset_file;

      wp_enqueue_script(
        'wpi-canvas-mode-editor',
        WPI_URL . 'build/canvas-mode-editor.js',
        $asset['dependencies'],
        $asset['version'],
        true
      );

      wp_localize_script('wpi-canvas-mode-editor', 'wpiCanvasModeConfig', self::get_editor_config());

      if (file_exists($build_dir . '/canvas-mode-editor.css')) {
        wp_enqueue_style(
          'wpi-canvas-mode-editor',
          WPI_URL . 'build/canvas-mode-editor.css',
          [],
          $asset['version']
        );
      }
    }

    $css_path = __DIR__ . '/canvas-mode.css';
    if (file_exists($css_path)) {
      wp_enqueue_style(
        'wpi-canvas-mode',
        WPI_URL . 'src/features/canvas-mode/canvas-mode.css',
        [],
        (string) filemtime($css_path)
      );
    }
  }

  public static function get_editor_config(): array {
    $settings   = AI_Composer_Settings::get_settings();
    $cm         = is_array($settings['canvas_mode'] ?? null) ? $settings['canvas_mode'] : [];
    $post_types = ! empty($cm['default_post_types']) && is_array($cm['default_post_types'])
      ? $cm['default_post_types']
      : ['page'];

    $content_selector = apply_filters( 'wpi_canvas_content_selector', '.entry-content' );

    return [
      'defaultPostTypes'  => $post_types,
      'contentSelector'   => $content_selector,
    ];
  }

  public static function sanitize(array $input): array {
    $clean = [];
    if (isset($input['default_post_types']) && is_array($input['default_post_types'])) {
      $clean['default_post_types'] = array_values(array_map('sanitize_key', $input['default_post_types']));
    } else {
      $clean['default_post_types'] = ['page'];
    }
    return $clean;
  }
}
