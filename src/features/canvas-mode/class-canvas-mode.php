<?php
if (! defined('ABSPATH')) {
  exit;
}

class WPI_Canvas_Mode {

  public static function boot(): void {
    add_action('enqueue_block_editor_assets', [self::class, 'enqueue_editor_assets']);
    add_action('rest_api_init', [self::class, 'register_rest_routes']);
  }

  public static function register_rest_routes(): void {
    register_rest_route('ai-composer/v1', '/template-chrome', [
      'methods'             => 'GET',
      'callback'            => [self::class, 'rest_template_chrome'],
      'permission_callback' => function () {
        return current_user_can('edit_posts');
      },
      'args' => [
        'post_id' => [
          'type'              => 'integer',
          'required'          => true,
          'sanitize_callback' => 'absint',
        ],
      ],
    ]);
  }

  /**
   * Return rendered header and footer HTML for a post's page template.
   *
   * Fetches the post's frontend URL via an internal loopback request,
   * then extracts the regions before and after the main content area.
   */
  public static function rest_template_chrome(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
    $post_id = (int) $request->get_param('post_id');
    $post    = get_post($post_id);

    if (! $post || ! current_user_can('edit_post', $post_id)) {
      return new \WP_Error('not_found', 'Post not found.', ['status' => 404]);
    }

    $url = get_permalink($post_id);
    if (! $url) {
      return new \WP_Error('no_permalink', 'Could not resolve permalink.', ['status' => 400]);
    }

    $response = wp_remote_get($url, [
      'timeout'   => 15,
      'sslverify' => false,
      'cookies'   => $_COOKIE,
    ]);

    if (is_wp_error($response)) {
      return new \WP_Error('fetch_failed', $response->get_error_message(), ['status' => 502]);
    }

    $html = wp_remote_retrieve_body($response);
    if (empty($html)) {
      return new \WP_Error('empty_response', 'Empty page response.', ['status' => 502]);
    }

    $result = self::extract_chrome($html);

    return new \WP_REST_Response($result, 200);
  }

  /**
   * Extract header, footer, and head styles from rendered page HTML.
   */
  private static function extract_chrome(string $html): array {
    $header_html = '';
    $footer_html = '';
    $head_styles = '';

    // Extract <head> stylesheets and inline styles for visual fidelity.
    if (preg_match('/<head[^>]*>(.*?)<\/head>/si', $html, $head_match)) {
      $head_content = $head_match[1];
      preg_match_all('/<link[^>]+rel=["\']stylesheet["\'][^>]*>/i', $head_content, $link_matches);
      preg_match_all('/<style[^>]*>.*?<\/style>/si', $head_content, $style_matches);

      $head_styles = implode("\n", $link_matches[0] ?? []) . "\n" . implode("\n", $style_matches[0] ?? []);
    }

    // Split on <main> or common content-area markers.
    $content_markers = [
      '<main',
      '<div id="content"',
      '<div id="primary"',
      '<div class="entry-content"',
      '<article',
    ];

    $split_before = null;
    $split_after  = null;

    foreach ($content_markers as $marker) {
      $pos = stripos($html, $marker);
      if ($pos !== false) {
        $split_before = $pos;
        break;
      }
    }

    $closing_markers = ['</main>', '</article>'];
    foreach ($closing_markers as $marker) {
      $pos = strripos($html, $marker);
      if ($pos !== false) {
        $split_after = $pos + strlen($marker);
        break;
      }
    }

    if ($split_before !== null) {
      $raw_header = substr($html, 0, $split_before);
      // Extract only the visible body content (skip <head>, <html>, <body> tags).
      $body_start = stripos($raw_header, '<body');
      if ($body_start !== false) {
        $body_tag_end = strpos($raw_header, '>', $body_start);
        if ($body_tag_end !== false) {
          $raw_header = substr($raw_header, $body_tag_end + 1);
        }
      }
      $header_html = trim($raw_header);
    }

    if ($split_after !== null) {
      $raw_footer = substr($html, $split_after);
      $raw_footer = preg_replace('/<\/body>.*$/si', '', $raw_footer);
      $footer_html = trim($raw_footer);
    }

    return [
      'header'      => $header_html,
      'footer'      => $footer_html,
      'head_styles' => $head_styles,
    ];
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

    return [
      'defaultPostTypes'       => $post_types,
      'templateChromeEndpoint' => rest_url('ai-composer/v1/template-chrome'),
      'templateChromeNonce'    => wp_create_nonce('wp_rest'),
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
