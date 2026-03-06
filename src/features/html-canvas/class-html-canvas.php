<?php
if (! defined('ABSPATH')) {
  exit;
}

class WPI_HTML_Canvas {

  public static function boot(): void {
    add_action('init', [self::class, 'register_block']);
  }

  public static function register_block(): void {
    $build_dir  = WPI_DIR . '/build';
    $asset_file = $build_dir . '/html-canvas-editor.asset.php';

    if (! file_exists($asset_file)) {
      return;
    }

    $asset = require $asset_file;

    wp_register_script(
      'wpi-html-canvas-editor',
      WPI_URL . 'build/html-canvas-editor.js',
      $asset['dependencies'],
      $asset['version'],
      true
    );

    if (file_exists($build_dir . '/html-canvas-editor.css')) {
      wp_register_style(
        'wpi-html-canvas-editor',
        WPI_URL . 'build/html-canvas-editor.css',
        [],
        $asset['version']
      );
    }

    register_block_type(__DIR__, [
      'render_callback' => [self::class, 'render_block'],
    ]);
  }

  /**
   * Server-side render: outputs the HTML content in a sandboxed iframe
   * with auto-resize via postMessage.
   */
  public static function render_block(array $attributes, string $content, WP_Block $block): string {
    $html = $attributes['content'] ?? '';
    if (empty(trim($html))) {
      return '';
    }

    if (
      class_exists('WPI_Merge_Tag_Engine')
      && strpos($html, '{{') !== false
      && (! is_admin() || wp_doing_ajax())
    ) {
      $context = ['post_id' => get_the_ID() ?: 0];
      $context = apply_filters('wpi_dynamic_data_render_context', $context, ['blockName' => 'wpi/html-canvas', 'attrs' => $attributes]);
      $html = WPI_Merge_Tag_Engine::resolve($html, $context, true);
    }

    $resize_script =
      '<script>(function(){function r(){var h=Math.max(document.documentElement.scrollHeight,document.body.scrollHeight);'
      . 'parent.postMessage({wpiHC:1,h:h},"*")}if(typeof ResizeObserver!=="undefined"){'
      . 'new ResizeObserver(r).observe(document.documentElement)}else{setInterval(r,500)}r()})()</script>';

    if (stripos($html, '</body>') !== false) {
      $srcdoc_raw = str_ireplace('</body>', $resize_script . '</body>', $html);
    } else {
      $srcdoc_raw = $html . $resize_script;
    }

    $srcdoc = htmlspecialchars($srcdoc_raw, ENT_QUOTES, 'UTF-8');
    $uid    = 'wpi-hc-' . wp_unique_id();

    $wrapper_attrs = get_block_wrapper_attributes(['id' => $uid]);

    return sprintf(
      '<div %s>'
      . '<iframe sandbox="allow-scripts" srcdoc="%s" '
      . 'style="border:none;width:100%%;min-height:120px;display:block" '
      . 'title="%s"></iframe>'
      . '</div>'
      . '<script>(function(){var w=document.getElementById(%s);if(!w)return;'
      . 'var f=w.querySelector("iframe");if(!f)return;'
      . 'window.addEventListener("message",function(e){'
      . 'if(e.source===f.contentWindow&&e.data&&e.data.wpiHC){'
      . 'f.style.height=Math.max(120,e.data.h)+"px"}})})()</script>',
      $wrapper_attrs,
      $srcdoc,
      esc_attr__('HTML Canvas content', 'wp-intelligence'),
      wp_json_encode($uid)
    );
  }
}
