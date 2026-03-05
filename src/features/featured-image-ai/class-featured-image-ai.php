<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Featured Image AI — orchestrator.
 *
 * Registers editor assets, REST endpoint, and coordinates
 * between the image provider and WordPress media library.
 */
class WPI_Featured_Image_AI {

  private static bool $booted = false;
  private static ?WPI_Image_Provider $provider = null;

  private const REST_NAMESPACE = 'ai-composer/v1';

  public static function boot(): void {
    if (self::$booted) {
      return;
    }
    self::$booted = true;

    self::$provider = new WPI_Image_Provider();

    add_action('enqueue_block_editor_assets', [self::class, 'enqueue_editor_assets']);
    add_action('rest_api_init', [self::class, 'register_routes']);
  }

  public static function provider(): WPI_Image_Provider {
    if (self::$provider === null) {
      self::$provider = new WPI_Image_Provider();
    }
    return self::$provider;
  }

  /* ──────────────────────────────────────────────
   *  Editor assets
   * ────────────────────────────────────────────── */

  public static function enqueue_editor_assets(): void {
    if (! defined('WPI_URL') || ! defined('WPI_DIR')) {
      return;
    }

    $js_path = WPI_DIR . '/src/features/featured-image-ai/editor/featured-image-ai.js';
    if (! file_exists($js_path)) {
      return;
    }

    wp_enqueue_script(
      'wpi-featured-image-ai',
      WPI_URL . 'src/features/featured-image-ai/editor/featured-image-ai.js',
      [
        'wp-plugins',
        'wp-edit-post',
        'wp-element',
        'wp-components',
        'wp-data',
        'wp-api-fetch',
        'wp-i18n',
        'wp-notices',
      ],
      filemtime($js_path),
      true
    );

    $css_path = WPI_DIR . '/src/features/featured-image-ai/editor/featured-image-ai.css';
    if (file_exists($css_path)) {
      wp_enqueue_style(
        'wpi-featured-image-ai',
        WPI_URL . 'src/features/featured-image-ai/editor/featured-image-ai.css',
        [],
        filemtime($css_path)
      );
    }

    $settings = self::get_settings();

    $overlay = self::get_overlay_settings();

    $has_seo_plugin = defined('RANK_MATH_VERSION') || class_exists('RankMath')
      || defined('WPSEO_VERSION') || class_exists('WPSEO_Meta')
      || defined('AIOSEO_VERSION') || function_exists('aioseo');

    wp_localize_script('wpi-featured-image-ai', 'wpiFeaturedImageAIConfig', [
      'restNamespace' => self::REST_NAMESPACE,
      'nonce'         => wp_create_nonce('wp_rest'),
      'providerReady' => self::$provider->is_available(),
      'readiness'     => self::$provider->get_readiness(),
      'defaults'      => [
        'image_style'         => $settings['image_style'] ?? 'photo-realistic',
        'aspect_ratio'        => $settings['aspect_ratio'] ?? 'landscape',
        'brand_colors'        => $settings['brand_colors'] ?? '',
        'custom_instructions' => $settings['custom_instructions'] ?? '',
      ],
      'overlay'      => $overlay,
      'hasSeoPlugin' => $has_seo_plugin,
    ]);
  }

  /* ──────────────────────────────────────────────
   *  REST routes
   * ────────────────────────────────────────────── */

  public static function register_routes(): void {
    register_rest_route(self::REST_NAMESPACE, '/generate-featured-image', [
      'methods'             => 'POST',
      'callback'            => [self::class, 'handle_generate'],
      'permission_callback' => [self::class, 'check_permission'],
      'args'                => [
        'post_id' => [
          'required'          => true,
          'type'              => 'integer',
          'sanitize_callback' => 'absint',
        ],
        'image_style' => [
          'type'              => 'string',
          'sanitize_callback' => 'sanitize_text_field',
          'default'           => '',
        ],
        'aspect_ratio' => [
          'type'              => 'string',
          'sanitize_callback' => 'sanitize_key',
          'default'           => '',
        ],
        'brand_colors' => [
          'type'              => 'string',
          'sanitize_callback' => 'sanitize_text_field',
          'default'           => '',
        ],
        'custom_instructions' => [
          'type'              => 'string',
          'sanitize_callback' => 'sanitize_textarea_field',
          'default'           => '',
        ],
        'apply_overlay' => [
          'type'    => 'string',
          'default' => 'default',
          'enum'    => ['default', 'yes', 'no'],
        ],
      ],
    ]);

    register_rest_route(self::REST_NAMESPACE, '/detect-fallback-image', [
      'methods'             => 'GET',
      'callback'            => [self::class, 'handle_detect_fallback'],
      'permission_callback' => [self::class, 'check_permission'],
      'args'                => [
        'post_id' => [
          'required'          => true,
          'type'              => 'integer',
          'sanitize_callback' => 'absint',
        ],
      ],
    ]);

    register_rest_route(self::REST_NAMESPACE, '/seo-checklist', [
      'methods'             => 'GET',
      'callback'            => [self::class, 'handle_seo_checklist'],
      'permission_callback' => [self::class, 'check_permission'],
      'args'                => [
        'post_id' => [
          'required'          => true,
          'type'              => 'integer',
          'sanitize_callback' => 'absint',
        ],
      ],
    ]);
  }

  public static function check_permission(): bool {
    $capability = apply_filters('ai_composer_capability', 'edit_posts');
    return current_user_can($capability);
  }

  /**
   * Generate a featured image for the given post.
   */
  public static function handle_generate(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $post_id = (int) $request->get_param('post_id');
    $post    = get_post($post_id);

    if (! $post) {
      return new WP_Error('wpi_invalid_post', __('Post not found.', 'wp-intelligence'), ['status' => 404]);
    }

    if (! current_user_can('edit_post', $post_id)) {
      return new WP_Error('wpi_forbidden', __('You cannot edit this post.', 'wp-intelligence'), ['status' => 403]);
    }

    $settings  = self::get_settings();
    $overrides = [
      'image_style'         => $request->get_param('image_style') ?: ($settings['image_style'] ?? 'photo-realistic'),
      'aspect_ratio'        => $request->get_param('aspect_ratio') ?: ($settings['aspect_ratio'] ?? 'landscape'),
      'brand_colors'        => $request->get_param('brand_colors') ?: ($settings['brand_colors'] ?? ''),
      'custom_instructions' => $request->get_param('custom_instructions') ?: ($settings['custom_instructions'] ?? ''),
    ];

    if (class_exists('AI_Composer_Context_Provider')) {
      $brand_voice = AI_Composer_Context_Provider::get_brand_voice();
      if ($brand_voice !== '') {
        $brand_summary = mb_substr($brand_voice, 0, 1000);
        $existing = trim($overrides['custom_instructions']);
        $overrides['custom_instructions'] = $existing !== ''
          ? $existing . "\n\nBrand context:\n" . $brand_summary
          : "Brand context:\n" . $brand_summary;
      }
    }

    $title   = $post->post_title ?: __('Untitled', 'wp-intelligence');
    $content = $post->post_content ?: '';

    $image_url = self::$provider->generate_image($title, $content, $overrides);
    if (is_wp_error($image_url)) {
      return $image_url;
    }

    $attachment_id = self::sideload_and_attach($image_url, $post_id, $title);
    if (is_wp_error($attachment_id)) {
      return $attachment_id;
    }

    $overlay       = self::get_overlay_settings();
    $apply_overlay = $request->get_param('apply_overlay') ?: 'default';
    $should_overlay = match ($apply_overlay) {
      'yes'   => true,
      'no'    => false,
      default => ($overlay['show_title'] ?? '0') === '1',
    };

    if ($should_overlay) {
      $file_path = get_attached_file($attachment_id);
      if ($file_path && file_exists($file_path)) {
        self::$provider->apply_text_overlay($file_path, $title, $overlay);
        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $file_path));
      }
    }

    set_post_thumbnail($post_id, $attachment_id);

    $attachment_url = wp_get_attachment_url($attachment_id);

    if ($should_overlay) {
      self::set_seo_social_image($post_id, $attachment_id, $attachment_url);
    }

    return new WP_REST_Response([
      'success'        => true,
      'attachment_id'  => $attachment_id,
      'attachment_url' => $attachment_url,
      'thumbnail'      => wp_get_attachment_image_src($attachment_id, 'medium'),
      'og_set'         => $should_overlay,
    ], 200);
  }

  /**
   * Detect fallback images from SEO plugins.
   */
  public static function handle_detect_fallback(WP_REST_Request $request): WP_REST_Response {
    $post_id   = (int) $request->get_param('post_id');
    $fallbacks = [];

    $yoast_default = get_option('wpseo_social', []);
    $og_default    = $yoast_default['og_default_image'] ?? '';
    if ($og_default !== '') {
      $fallbacks[] = [
        'source' => 'Yoast SEO',
        'url'    => $og_default,
        'label'  => __('Yoast default social image', 'wp-intelligence'),
      ];
    }

    $yoast_post_image = get_post_meta($post_id, '_yoast_wpseo_opengraph-image', true);
    if ($yoast_post_image !== '' && $yoast_post_image !== false) {
      $fallbacks[] = [
        'source' => 'Yoast SEO',
        'url'    => $yoast_post_image,
        'label'  => __('Yoast post social image', 'wp-intelligence'),
      ];
    }

    $rankmath_default = get_option('rank-math-options-titles', []);
    $rm_og            = $rankmath_default['open_graph_image'] ?? '';
    if ($rm_og !== '') {
      $fallbacks[] = [
        'source' => 'RankMath',
        'url'    => $rm_og,
        'label'  => __('RankMath default social image', 'wp-intelligence'),
      ];
    }

    $rm_post_image = get_post_meta($post_id, 'rank_math_facebook_image', true);
    if ($rm_post_image !== '' && $rm_post_image !== false) {
      $fallbacks[] = [
        'source' => 'RankMath',
        'url'    => $rm_post_image,
        'label'  => __('RankMath post social image', 'wp-intelligence'),
      ];
    }

    return new WP_REST_Response([
      'fallbacks'          => $fallbacks,
      'has_featured_image' => has_post_thumbnail($post_id),
    ], 200);
  }

  /**
   * Evaluate SEO readiness for the pre-publish checklist.
   *
   * Detects RankMath, Yoast, or AIOSEO and checks relevant meta fields.
   */
  public static function handle_seo_checklist(WP_REST_Request $request): WP_REST_Response {
    $post_id = (int) $request->get_param('post_id');
    $post    = get_post($post_id);

    if (! $post) {
      return new WP_REST_Response(['checks' => [], 'seo_plugin' => 'none'], 200);
    }

    $seo_plugin = 'none';
    $checks     = [];

    if (defined('RANK_MATH_VERSION') || class_exists('RankMath')) {
      $seo_plugin = 'RankMath';
      $checks = self::rankmath_checks($post_id, $post);
    } elseif (defined('WPSEO_VERSION') || class_exists('WPSEO_Meta')) {
      $seo_plugin = 'Yoast SEO';
      $checks = self::yoast_checks($post_id, $post);
    } elseif (defined('AIOSEO_VERSION') || function_exists('aioseo')) {
      $seo_plugin = 'All in One SEO';
      $checks = self::aioseo_checks($post_id, $post);
    }

    $checks[] = [
      'id'     => 'featured_image',
      'label'  => __('Featured image', 'wp-intelligence'),
      'status' => has_post_thumbnail($post_id) ? 'pass' : 'fail',
      'detail' => has_post_thumbnail($post_id)
        ? __('Featured image is set.', 'wp-intelligence')
        : __('No featured image set. Consider generating one with AI above.', 'wp-intelligence'),
    ];

    $content = $post->post_content ?? '';
    $img_count = substr_count($content, '<img');
    if ($img_count > 0) {
      $missing_alt = preg_match_all('/<img(?![^>]*\balt\s*=\s*"[^"]+")[^>]*>/i', $content);
      $checks[] = [
        'id'     => 'image_alt_text',
        'label'  => __('Image alt text', 'wp-intelligence'),
        'status' => $missing_alt > 0 ? 'warn' : 'pass',
        'detail' => $missing_alt > 0
          ? sprintf(__('%d image(s) missing alt text.', 'wp-intelligence'), $missing_alt)
          : __('All images have alt text.', 'wp-intelligence'),
      ];
    }

    $word_count = str_word_count(wp_strip_all_tags($content));
    $checks[] = [
      'id'     => 'content_length',
      'label'  => __('Content length', 'wp-intelligence'),
      'status' => $word_count >= 300 ? 'pass' : ($word_count >= 100 ? 'warn' : 'fail'),
      'detail' => sprintf(__('%d words', 'wp-intelligence'), $word_count),
    ];

    $has_internal_link = false;
    $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
    if (preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\']/i', $content, $link_matches)) {
      foreach ($link_matches[1] as $href) {
        $link_host = wp_parse_url($href, PHP_URL_HOST);
        if ($link_host === $site_host || $link_host === null) {
          $has_internal_link = true;
          break;
        }
      }
    }
    $checks[] = [
      'id'     => 'internal_links',
      'label'  => __('Internal links', 'wp-intelligence'),
      'status' => $has_internal_link ? 'pass' : 'warn',
      'detail' => $has_internal_link
        ? __('Has internal links.', 'wp-intelligence')
        : __('Consider adding internal links to related content.', 'wp-intelligence'),
    ];

    return new WP_REST_Response([
      'seo_plugin' => $seo_plugin,
      'checks'     => $checks,
    ], 200);
  }

  private static function rankmath_checks(int $post_id, WP_Post $post): array {
    $checks = [];

    $title = get_post_meta($post_id, 'rank_math_title', true);
    $checks[] = [
      'id'     => 'meta_title',
      'label'  => __('SEO title', 'wp-intelligence'),
      'status' => (! empty($title)) ? 'pass' : 'warn',
      'detail' => ! empty($title)
        ? __('Custom SEO title set.', 'wp-intelligence')
        : __('Using default title. Consider setting a custom SEO title in RankMath.', 'wp-intelligence'),
    ];

    $desc = get_post_meta($post_id, 'rank_math_description', true);
    $checks[] = [
      'id'     => 'meta_description',
      'label'  => __('Meta description', 'wp-intelligence'),
      'status' => ! empty($desc) ? 'pass' : 'fail',
      'detail' => ! empty($desc)
        ? sprintf(__('Set (%d characters).', 'wp-intelligence'), mb_strlen($desc))
        : __('No meta description. This is important for search results.', 'wp-intelligence'),
    ];

    $keyword = get_post_meta($post_id, 'rank_math_focus_keyword', true);
    $checks[] = [
      'id'     => 'focus_keyword',
      'label'  => __('Focus keyword', 'wp-intelligence'),
      'status' => ! empty($keyword) ? 'pass' : 'warn',
      'detail' => ! empty($keyword)
        ? sprintf(__('"%s"', 'wp-intelligence'), $keyword)
        : __('No focus keyword set.', 'wp-intelligence'),
    ];

    $og_image = get_post_meta($post_id, 'rank_math_facebook_image', true);
    $checks[] = [
      'id'     => 'og_image',
      'label'  => __('Social sharing image', 'wp-intelligence'),
      'status' => ! empty($og_image) ? 'pass' : 'warn',
      'detail' => ! empty($og_image)
        ? __('Custom OG image set.', 'wp-intelligence')
        : __('No custom social image. The featured image will be used.', 'wp-intelligence'),
    ];

    $score = (int) get_post_meta($post_id, 'rank_math_seo_score', true);
    if ($score > 0) {
      $checks[] = [
        'id'     => 'seo_score',
        'label'  => __('RankMath SEO score', 'wp-intelligence'),
        'status' => $score >= 80 ? 'pass' : ($score >= 50 ? 'warn' : 'fail'),
        'detail' => sprintf(__('%d/100', 'wp-intelligence'), $score),
      ];
    }

    return $checks;
  }

  private static function yoast_checks(int $post_id, WP_Post $post): array {
    $checks = [];

    $title = get_post_meta($post_id, '_yoast_wpseo_title', true);
    $checks[] = [
      'id'     => 'meta_title',
      'label'  => __('SEO title', 'wp-intelligence'),
      'status' => ! empty($title) ? 'pass' : 'warn',
      'detail' => ! empty($title)
        ? __('Custom SEO title set.', 'wp-intelligence')
        : __('Using default title. Consider setting a custom SEO title in Yoast.', 'wp-intelligence'),
    ];

    $desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
    $checks[] = [
      'id'     => 'meta_description',
      'label'  => __('Meta description', 'wp-intelligence'),
      'status' => ! empty($desc) ? 'pass' : 'fail',
      'detail' => ! empty($desc)
        ? sprintf(__('Set (%d characters).', 'wp-intelligence'), mb_strlen($desc))
        : __('No meta description. This is important for search results.', 'wp-intelligence'),
    ];

    $keyword = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
    $checks[] = [
      'id'     => 'focus_keyword',
      'label'  => __('Focus keyword', 'wp-intelligence'),
      'status' => ! empty($keyword) ? 'pass' : 'warn',
      'detail' => ! empty($keyword)
        ? sprintf(__('"%s"', 'wp-intelligence'), $keyword)
        : __('No focus keyword set.', 'wp-intelligence'),
    ];

    $og_image = get_post_meta($post_id, '_yoast_wpseo_opengraph-image', true);
    $checks[] = [
      'id'     => 'og_image',
      'label'  => __('Social sharing image', 'wp-intelligence'),
      'status' => ! empty($og_image) ? 'pass' : 'warn',
      'detail' => ! empty($og_image)
        ? __('Custom OG image set.', 'wp-intelligence')
        : __('No custom social image. The featured image will be used.', 'wp-intelligence'),
    ];

    $score = (int) get_post_meta($post_id, '_yoast_wpseo_linkdex', true);
    if ($score > 0) {
      $checks[] = [
        'id'     => 'seo_score',
        'label'  => __('Yoast SEO score', 'wp-intelligence'),
        'status' => $score >= 70 ? 'pass' : ($score >= 40 ? 'warn' : 'fail'),
        'detail' => sprintf(__('%d/100', 'wp-intelligence'), $score),
      ];
    }

    return $checks;
  }

  private static function aioseo_checks(int $post_id, WP_Post $post): array {
    $checks = [];

    $title = get_post_meta($post_id, '_aioseo_title', true);
    $checks[] = [
      'id'     => 'meta_title',
      'label'  => __('SEO title', 'wp-intelligence'),
      'status' => ! empty($title) ? 'pass' : 'warn',
      'detail' => ! empty($title)
        ? __('Custom SEO title set.', 'wp-intelligence')
        : __('Using default title. Consider setting a custom SEO title.', 'wp-intelligence'),
    ];

    $desc = get_post_meta($post_id, '_aioseo_description', true);
    $checks[] = [
      'id'     => 'meta_description',
      'label'  => __('Meta description', 'wp-intelligence'),
      'status' => ! empty($desc) ? 'pass' : 'fail',
      'detail' => ! empty($desc)
        ? sprintf(__('Set (%d characters).', 'wp-intelligence'), mb_strlen($desc))
        : __('No meta description.', 'wp-intelligence'),
    ];

    $og_image = get_post_meta($post_id, '_aioseo_og_image_custom_url', true);
    $checks[] = [
      'id'     => 'og_image',
      'label'  => __('Social sharing image', 'wp-intelligence'),
      'status' => ! empty($og_image) ? 'pass' : 'warn',
      'detail' => ! empty($og_image)
        ? __('Custom OG image set.', 'wp-intelligence')
        : __('No custom social image.', 'wp-intelligence'),
    ];

    return $checks;
  }

  /* ──────────────────────────────────────────────
   *  SEO social image integration
   * ────────────────────────────────────────────── */

  /**
   * Write the generated OG image into the active SEO plugin's meta fields.
   */
  private static function set_seo_social_image(int $post_id, int $attachment_id, string $url): void {
    if (defined('RANK_MATH_VERSION') || class_exists('RankMath')) {
      update_post_meta($post_id, 'rank_math_facebook_image',    $url);
      update_post_meta($post_id, 'rank_math_facebook_image_id', $attachment_id);
      update_post_meta($post_id, 'rank_math_twitter_use_facebook', 'on');
    }

    if (defined('WPSEO_VERSION') || class_exists('WPSEO_Meta')) {
      update_post_meta($post_id, '_yoast_wpseo_opengraph-image',    $url);
      update_post_meta($post_id, '_yoast_wpseo_opengraph-image-id', $attachment_id);
      update_post_meta($post_id, '_yoast_wpseo_twitter-image',      $url);
      update_post_meta($post_id, '_yoast_wpseo_twitter-image-id',   $attachment_id);
    }

    if (defined('AIOSEO_VERSION') || function_exists('aioseo')) {
      update_post_meta($post_id, '_aioseo_og_image_custom_url', $url);
      update_post_meta($post_id, '_aioseo_og_image_type',       'custom');
      update_post_meta($post_id, '_aioseo_twitter_image_custom_url', $url);
      update_post_meta($post_id, '_aioseo_twitter_image_type',       'custom');
    }
  }

  /* ──────────────────────────────────────────────
   *  Media handling
   * ────────────────────────────────────────────── */

  /**
   * Download the generated image and attach it to the post.
   *
   * @return int|WP_Error Attachment ID.
   */
  private static function sideload_and_attach(string $url, int $post_id, string $description): int|WP_Error {
    if (! function_exists('media_sideload_image')) {
      require_once ABSPATH . 'wp-admin/includes/media.php';
      require_once ABSPATH . 'wp-admin/includes/file.php';
      require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $tmp = download_url($url, 120);
    if (is_wp_error($tmp)) {
      return new WP_Error('wpi_download_failed', $tmp->get_error_message(), ['status' => 502]);
    }

    $filename = sanitize_file_name('ai-featured-' . $post_id . '-' . time() . '.png');

    $file_array = [
      'name'     => $filename,
      'tmp_name' => $tmp,
    ];

    $attachment_id = media_handle_sideload($file_array, $post_id, $description);

    if (is_wp_error($attachment_id)) {
      @unlink($tmp);
      return $attachment_id;
    }

    update_post_meta($attachment_id, '_wpi_ai_generated', '1');

    return (int) $attachment_id;
  }

  /* ──────────────────────────────────────────────
   *  Settings helpers
   * ────────────────────────────────────────────── */

  public static function get_settings(): array {
    $all = get_option('ai_composer_settings', []);
    $s   = is_array($all['featured_image_ai'] ?? null) ? $all['featured_image_ai'] : [];

    return wp_parse_args($s, [
      'image_style'         => 'photo-realistic',
      'aspect_ratio'        => 'landscape',
      'brand_colors'        => '',
      'custom_instructions' => '',
    ]);
  }

  public static function get_overlay_settings(): array {
    $all = get_option('ai_composer_settings', []);
    $s   = is_array($all['featured_image_ai'] ?? null) ? $all['featured_image_ai'] : [];

    return wp_parse_args([
      'show_title'  => $s['overlay_show_title'] ?? '0',
      'position'    => $s['overlay_position'] ?? 'bottom',
      'bg_color'    => $s['overlay_bg_color'] ?? '#000000',
      'text_color'  => $s['overlay_text_color'] ?? '#ffffff',
      'opacity'     => $s['overlay_opacity'] ?? 70,
    ], [
      'show_title'  => '0',
      'position'    => 'bottom',
      'bg_color'    => '#000000',
      'text_color'  => '#ffffff',
      'opacity'     => 70,
    ]);
  }

  /**
   * Sanitize featured_image_ai sub-settings.
   */
  public static function sanitize(array $input): array {
    $valid_styles = [
      'photo-realistic', 'illustration', 'flat-design',
      'abstract', '3d-render', 'watercolor', 'minimal',
      'cinematic', 'digital-art',
    ];

    $valid_ratios   = ['landscape', 'portrait', 'square'];
    $valid_positions = ['top', 'center', 'bottom'];

    $style = sanitize_key($input['image_style'] ?? 'photo-realistic');
    $ratio = sanitize_key($input['aspect_ratio'] ?? 'landscape');

    $clean = [
      'image_style'         => in_array($style, $valid_styles, true) ? $style : 'photo-realistic',
      'aspect_ratio'        => in_array($ratio, $valid_ratios, true) ? $ratio : 'landscape',
      'brand_colors'        => sanitize_text_field(mb_substr((string) ($input['brand_colors'] ?? ''), 0, 500)),
      'custom_instructions' => sanitize_textarea_field(mb_substr((string) ($input['custom_instructions'] ?? ''), 0, 2000)),
    ];

    $clean['overlay_show_title'] = ! empty($input['overlay_show_title']) ? '1' : '0';

    $pos = sanitize_key($input['overlay_position'] ?? 'bottom');
    $clean['overlay_position'] = in_array($pos, $valid_positions, true) ? $pos : 'bottom';

    $clean['overlay_bg_color'] = sanitize_hex_color($input['overlay_bg_color'] ?? '#000000') ?: '#000000';
    $clean['overlay_text_color'] = sanitize_hex_color($input['overlay_text_color'] ?? '#ffffff') ?: '#ffffff';

    $opacity = (int) ($input['overlay_opacity'] ?? 70);
    $clean['overlay_opacity'] = max(0, min(100, $opacity));

    return $clean;
  }
}
