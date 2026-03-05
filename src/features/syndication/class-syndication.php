<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Content syndication: fetch an external article URL, extract readable text,
 * run an AI rewrite via the shared Provider, and return structured data.
 *
 * Theme-agnostic. No ACF dependency. Uses standard post meta for storage
 * and fires filters so themes can bridge data into their own field systems.
 */
class AI_Composer_Syndication {

  private AI_Composer_Provider $provider;

  private static ?self $instance = null;

  private const META_SOURCE_URL  = '_syndication_source_url';
  private const META_SOURCE_NAME = '_syndication_source_name';
  private const META_PUB_DATE    = '_syndication_published_date';

  public function __construct(AI_Composer_Provider $provider) {
    $this->provider = $provider;
  }

  public static function boot(): void {
    if (! class_exists('AI_Composer')) {
      return;
    }
    $composer = AI_Composer::get_instance();
    self::$instance = new self($composer->provider());
    self::$instance->register_hooks();
  }

  public static function get_instance(): ?self {
    return self::$instance;
  }

  private function register_hooks(): void {
    $types = $this->get_enabled_post_types();
    if (empty($types)) {
      return;
    }

    add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
    add_action('rest_api_init', [$this, 'register_rest_routes']);
  }

  public function enqueue_editor_assets(): void {
    if (! current_user_can('edit_posts')) {
      return;
    }

    $js_path = __DIR__ . '/editor/syndication.js';
    $js_url  = defined('WPI_URL') ? WPI_URL . 'src/features/syndication/editor/syndication.js' : '';

    if ($js_url === '' || ! file_exists($js_path)) {
      return;
    }

    wp_enqueue_script(
      'wpi-syndication',
      $js_url,
      ['wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n'],
      (string) filemtime($js_path),
      true
    );

    $css_path = __DIR__ . '/editor/syndication.css';
    $css_url  = defined('WPI_URL') ? WPI_URL . 'src/features/syndication/editor/syndication.css' : '';
    if ($css_url !== '' && file_exists($css_path)) {
      wp_enqueue_style(
        'wpi-syndication',
        $css_url,
        ['wp-components'],
        (string) filemtime($css_path)
      );
    }

    wp_localize_script('wpi-syndication', 'wpiSyndicationConfig', [
      'restNamespace'    => 'ai-composer/v1',
      'nonce'            => wp_create_nonce('wp_rest'),
      'enabledPostTypes' => $this->get_enabled_post_types(),
      'hasFirecrawl'     => $this->get_firecrawl_key() !== '',
    ]);
  }

  public function register_rest_routes(): void {
    register_rest_route('ai-composer/v1', '/syndicate', [
      'methods'             => 'POST',
      'callback'            => [$this, 'handle_syndicate_request'],
      'permission_callback' => function () {
        return current_user_can(apply_filters('ai_composer_capability', 'edit_posts'));
      },
      'args' => [
        'url'        => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw'],
        'prompt'     => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
        'post_id'    => ['type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint'],
        'postId'     => ['type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint'],
        'word_count' => ['type' => 'integer', 'default' => 600, 'sanitize_callback' => 'absint'],
      ],
    ]);

    register_rest_route('ai-composer/v1', '/syndicate/test', [
      'methods'             => 'GET',
      'callback'            => [$this, 'handle_test_request'],
      'permission_callback' => function () {
        return current_user_can('manage_options');
      },
      'args' => [
        'url'  => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw'],
        'step' => ['type' => 'string', 'default' => 'all', 'sanitize_callback' => 'sanitize_key'],
      ],
    ]);
  }

  /**
   * @param WP_REST_Request $request
   * @return WP_REST_Response|WP_Error
   */
  public function handle_syndicate_request(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $post_id = absint($request->get_param('post_id'));
    if ($post_id <= 0) {
      $post_id = absint($request->get_param('postId'));
    }

    $word_count = absint($request->get_param('word_count'));
    $word_count = max(200, min(1500, $word_count ?: 600));

    $result = $this->syndicate(
      $request->get_param('url'),
      $request->get_param('prompt'),
      $post_id,
      $word_count
    );

    if (is_wp_error($result)) {
      return $result;
    }

    return new WP_REST_Response($result, 200);
  }

  /**
   * Diagnostic endpoint: step through the syndication pipeline and report
   * what happens at each stage without writing anything.
   *
   * GET /ai-composer/v1/syndicate/test?url=...&step=all|builtin|firecrawl|extract|draft
   */
  public function handle_test_request(WP_REST_Request $request): WP_REST_Response {
    $url  = $request->get_param('url');
    $step = $request->get_param('step') ?: 'all';
    $start = microtime(true);

    $report = [
      'url'            => $url,
      'step'           => $step,
      'php_max_exec'   => (int) ini_get('max_execution_time'),
      'firecrawl_key'  => $this->get_firecrawl_key() !== '' ? 'configured (' . substr($this->get_firecrawl_key(), 0, 8) . '...)' : 'not set',
      'stages'         => [],
    ];

    $url_check = self::validate_source_url($url);
    $report['stages']['url_validation'] = is_wp_error($url_check)
      ? ['ok' => false, 'error' => $url_check->get_error_message()]
      : ['ok' => true];

    if (is_wp_error($url_check)) {
      $report['elapsed_ms'] = round((microtime(true) - $start) * 1000);
      return new WP_REST_Response($report, 200);
    }

    if ($step === 'all' || $step === 'builtin') {
      $t = microtime(true);
      $builtin = $this->fetch_via_builtin($url);
      $report['stages']['builtin_fetch'] = [
        'ok'         => ! is_wp_error($builtin),
        'elapsed_ms' => round((microtime(true) - $t) * 1000),
      ];
      if (is_wp_error($builtin)) {
        $report['stages']['builtin_fetch']['error'] = $builtin->get_error_message();
      } else {
        $report['stages']['builtin_fetch']['html_length'] = strlen($builtin);
      }

      if (! is_wp_error($builtin) && ($step === 'all' || $step === 'extract')) {
        $t = microtime(true);
        $article = $this->extract_article($builtin, $url);
        $report['stages']['builtin_extract'] = [
          'ok'         => ! is_wp_error($article),
          'elapsed_ms' => round((microtime(true) - $t) * 1000),
        ];
        if (is_wp_error($article)) {
          $report['stages']['builtin_extract']['error'] = $article->get_error_message();
        } else {
          $report['stages']['builtin_extract']['title']      = $article['title'] ?? '';
          $report['stages']['builtin_extract']['source']     = $article['source_name'] ?? '';
          $report['stages']['builtin_extract']['pub_date']   = $article['published_date'] ?? '';
          $report['stages']['builtin_extract']['body_chars'] = strlen($article['body_text'] ?? '');
          $report['stages']['builtin_extract']['images']     = count($article['images'] ?? []);
          $report['stages']['builtin_extract']['excerpt']    = substr($article['excerpt'] ?? '', 0, 200);
        }
      }
    }

    if ($step === 'all' || $step === 'firecrawl') {
      $key = $this->get_firecrawl_key();
      if ($key === '') {
        $report['stages']['firecrawl'] = ['ok' => false, 'error' => 'No API key configured'];
      } else {
        $t = microtime(true);
        $fc = $this->fetch_via_firecrawl($url, $key);
        $report['stages']['firecrawl'] = [
          'ok'         => ! is_wp_error($fc),
          'elapsed_ms' => round((microtime(true) - $t) * 1000),
        ];
        if (is_wp_error($fc)) {
          $report['stages']['firecrawl']['error'] = $fc->get_error_message();
        } else {
          $report['stages']['firecrawl']['title']      = $fc['title'] ?? '';
          $report['stages']['firecrawl']['source']     = $fc['source_name'] ?? '';
          $report['stages']['firecrawl']['pub_date']   = $fc['published_date'] ?? '';
          $report['stages']['firecrawl']['body_chars'] = strlen($fc['body_text'] ?? '');
          $report['stages']['firecrawl']['images']     = count($fc['images'] ?? []);
          $report['stages']['firecrawl']['excerpt']    = substr($fc['excerpt'] ?? '', 0, 200);
        }
      }
    }

    $report['elapsed_ms'] = round((microtime(true) - $start) * 1000);
    return new WP_REST_Response($report, 200);
  }

  /**
   * Post types where the syndication UI is enabled.
   *
   * @return array<int, string>
   */
  public function get_enabled_post_types(): array {
    $settings = AI_Composer_Settings::get_syndication_settings();
    $types = $settings['enabled_post_types'] ?? [];

    if (! is_array($types) || empty($types)) {
      $types = [];
    }

    return apply_filters('ai_composer_syndication_enabled_post_types', $types);
  }

  /**
   * Main syndication entry point.
   *
   * @param string $url    Remote article URL.
   * @param string $prompt Optional editor prompt to guide the rewrite.
   * @param int    $post_id Post ID (0 if unsaved).
   * @return array<string, mixed>|WP_Error
   */
  public function syndicate(string $url, string $prompt = '', int $post_id = 0, int $word_count = 600): array|WP_Error {
    $pipeline_start = microtime(true);
    $log = [];

    $url = esc_url_raw($url);
    $url_validation = self::validate_source_url($url);
    if (is_wp_error($url_validation)) {
      return $url_validation;
    }

    do_action('ai_composer_before_syndicate', $url, $prompt, $post_id);

    // --- Step 1: Fetch ---
    $t = microtime(true);
    $fetched = $this->fetch_remote_content($url);
    $fetch_ms = round((microtime(true) - $t) * 1000);

    if (is_wp_error($fetched)) {
      $log[] = ['step' => 'Fetch', 'status' => 'error', 'detail' => $fetched->get_error_message(), 'ms' => $fetch_ms];
      return $this->error_with_log($fetched, $log, $pipeline_start);
    }

    // --- Step 2: Extract ---
    $t = microtime(true);
    if (is_array($fetched)) {
      $article = $fetched;
      $via = $article['_via'] ?? 'firecrawl';
      $extract_ms = round((microtime(true) - $t) * 1000);
      $log[] = ['step' => 'Fetch', 'status' => 'ok', 'detail' => 'via Firecrawl JSON extraction', 'ms' => $fetch_ms];
      $log[] = ['step' => 'Extract', 'status' => 'ok', 'detail' => sprintf('%s | %s chars | %d images', $article['title'] ?? '', strlen($article['body_text'] ?? ''), count($article['images'] ?? [])), 'ms' => $extract_ms];
    } else {
      $log[] = ['step' => 'Fetch', 'status' => 'ok', 'detail' => sprintf('Built-in HTTP 200 | %s chars HTML', strlen($fetched)), 'ms' => $fetch_ms];

      $article = $this->extract_article($fetched, $url);
      $extract_ms = round((microtime(true) - $t) * 1000);

      if (is_wp_error($article)) {
        $log[] = ['step' => 'Extract', 'status' => 'warn', 'detail' => 'Built-in extraction failed: ' . $article->get_error_message(), 'ms' => $extract_ms];

        $firecrawl_key = $this->get_firecrawl_key();
        if ($firecrawl_key !== '') {
          $t2 = microtime(true);
          $firecrawl_result = $this->fetch_via_firecrawl($url, $firecrawl_key);
          $fc_ms = round((microtime(true) - $t2) * 1000);
          if (! is_wp_error($firecrawl_result)) {
            $article = $firecrawl_result;
            $log[] = ['step' => 'Firecrawl fallback', 'status' => 'ok', 'detail' => sprintf('%s | %s chars | %d images', $article['title'] ?? '', strlen($article['body_text'] ?? ''), count($article['images'] ?? [])), 'ms' => $fc_ms];
          } else {
            $log[] = ['step' => 'Firecrawl fallback', 'status' => 'error', 'detail' => $firecrawl_result->get_error_message(), 'ms' => $fc_ms];
            return $this->error_with_log($article, $log, $pipeline_start);
          }
        } else {
          return $this->error_with_log($article, $log, $pipeline_start);
        }
      } else {
        $log[] = ['step' => 'Extract', 'status' => 'ok', 'detail' => sprintf('%s | %s chars | %d images', $article['title'] ?? '', strlen($article['body_text'] ?? ''), count($article['images'] ?? [])), 'ms' => $extract_ms];
      }
    }

    // --- Step 3: AI Draft ---
    $t = microtime(true);
    $generated = $this->generate_draft($article, $prompt, $word_count);
    $draft_ms = round((microtime(true) - $t) * 1000);
    $used_ai = ! is_wp_error($generated);

    if (is_wp_error($generated)) {
      $log[] = ['step' => 'AI draft', 'status' => 'warn', 'detail' => 'AI failed: ' . $generated->get_error_message() . ' — using fallback', 'ms' => $draft_ms];
      $generated = $this->generate_fallback_draft($article);
      $used_ai = false;
    } else {
      $wc = str_word_count(wp_strip_all_tags($generated['content_html'] ?? ''));
      $log[] = ['step' => 'AI draft', 'status' => 'ok', 'detail' => sprintf('%d words generated', $wc), 'ms' => $draft_ms];
    }

    // --- Step 4: Image sideload ---
    $images_imported = 0;
    $content_html = (string) ($generated['content_html'] ?? '');
    if ($post_id > 0 && $content_html !== '') {
      $t = microtime(true);
      $sideload_result = $this->sideload_content_images($content_html, $post_id);
      $sideload_ms = round((microtime(true) - $t) * 1000);
      $content_html    = $sideload_result['html'];
      $images_imported = $sideload_result['count'];
      $generated['content_html'] = $content_html;

      if ($images_imported > 0) {
        $log[] = ['step' => 'Images', 'status' => 'ok', 'detail' => sprintf('%d sideloaded to media library', $images_imported), 'ms' => $sideload_ms];
      } else {
        $log[] = ['step' => 'Images', 'status' => 'skip', 'detail' => 'No external images to import', 'ms' => $sideload_ms];
      }
    }

    // --- Step 5: Featured image from og:image ---
    $featured_set = false;
    if ($post_id > 0 && ! has_post_thumbnail($post_id)) {
      $og_image = (string) ($article['og_image'] ?? '');
      if ($og_image !== '' && str_starts_with($og_image, 'http')) {
        $t = microtime(true);
        $feat_id = $this->sideload_featured_image($og_image, $post_id, $article['title'] ?? '');
        $feat_ms = round((microtime(true) - $t) * 1000);
        if ($feat_id > 0) {
          $featured_set = true;
          $log[] = ['step' => 'Featured image', 'status' => 'ok', 'detail' => 'og:image sideloaded and set (' . substr($og_image, 0, 80) . ')', 'ms' => $feat_ms];
        } else {
          $log[] = ['step' => 'Featured image', 'status' => 'warn', 'detail' => 'Sideload failed for: ' . substr($og_image, 0, 80), 'ms' => $feat_ms];
        }
      } else {
        $log[] = ['step' => 'Featured image', 'status' => 'skip', 'detail' => 'No og:image found in article metadata', 'ms' => 0];
      }
    }

    // --- Step 6: Post date from article published date ---
    $date_set = false;
    if ($post_id > 0) {
      $post_obj = get_post($post_id);
      $pub_date = (string) ($article['published_date'] ?? '');
      if ($pub_date !== '' && $post_obj && in_array($post_obj->post_status, ['draft', 'auto-draft'], true)) {
        $normalized = self::normalize_date($pub_date);
        if ($normalized !== '') {
          wp_update_post([
            'ID'            => $post_id,
            'post_date'     => $normalized . ' 12:00:00',
            'post_date_gmt' => get_gmt_from_date($normalized . ' 12:00:00'),
          ]);
          $date_set = true;
          $log[] = ['step' => 'Post date', 'status' => 'ok', 'detail' => 'Set to article publish date: ' . $normalized, 'ms' => 0];
        }
      }
    }

    // --- Step 7: Meta + taxonomy ---
    $taxonomy_assigned = false;
    if ($post_id > 0) {
      $this->save_post_meta($post_id, $url, $generated);
      $taxonomy_assigned = $this->assign_source_term($post_id, (string) ($generated['news_source'] ?? ''));
      $log[] = ['step' => 'Save', 'status' => 'ok', 'detail' => 'Post meta saved' . ($taxonomy_assigned ? ' + taxonomy assigned' : ''), 'ms' => 0];
    }

    $total_ms = round((microtime(true) - $pipeline_start) * 1000);
    $log[] = ['step' => 'Done', 'status' => 'ok', 'detail' => sprintf('Total: %ss', number_format($total_ms / 1000, 1)), 'ms' => $total_ms];

    $result = [
      'title'            => (string) ($generated['title'] ?? ''),
      'excerpt'          => (string) ($generated['excerpt'] ?? ''),
      'content'          => $content_html,
      'newsSource'       => (string) ($generated['news_source'] ?? ''),
      'publishedDate'    => (string) ($generated['published_date'] ?? ''),
      'taxonomyAssigned' => $taxonomy_assigned,
      'usedAI'           => $used_ai,
      'sourceUrl'        => $url,
      'imagesImported'   => $images_imported,
      'featuredImageSet' => $featured_set,
      'dateSet'          => $date_set,
      'fetchedVia'       => (string) ($article['_via'] ?? 'builtin'),
      'log'              => $log,
    ];

    $result = apply_filters('ai_composer_syndication_response', $result, $article, $generated);

    do_action('ai_composer_after_syndicate', $result, $post_id);

    return $result;
  }

  /**
   * Attach log to an error response so the editor can still display it.
   */
  private function error_with_log(WP_Error $error, array $log, float $pipeline_start): WP_Error {
    $total_ms = round((microtime(true) - $pipeline_start) * 1000);
    $log[] = ['step' => 'Failed', 'status' => 'error', 'detail' => sprintf('Total: %ss', number_format($total_ms / 1000, 1)), 'ms' => $total_ms];
    $data = $error->get_error_data() ?: [];
    $data['log'] = $log;
    $error->add_data($data);
    return $error;
  }

  // ------------------------------------------------------------------
  // URL validation
  // ------------------------------------------------------------------

  public static function validate_url(string $url): bool {
    return ! is_wp_error(self::validate_source_url($url));
  }

  /**
   * Validate and safety-check a remote article URL.
   *
   * @return true|WP_Error
   */
  private static function validate_source_url(string $url): true|WP_Error {
    $url = trim($url);
    if ($url === '') {
      return new WP_Error(
        'ai_composer_syndication_invalid_url',
        __('Please provide a valid article URL.', 'wp-intelligence'),
        ['status' => 400]
      );
    }

    $url = esc_url_raw($url);
    if ($url === '') {
      return new WP_Error(
        'ai_composer_syndication_invalid_url',
        __('Please provide a valid article URL.', 'wp-intelligence'),
        ['status' => 400]
      );
    }

    $scheme = wp_parse_url($url, PHP_URL_SCHEME);
    if (! in_array($scheme, ['http', 'https'], true)) {
      return new WP_Error(
        'ai_composer_syndication_invalid_url',
        __('Please provide a valid article URL.', 'wp-intelligence'),
        ['status' => 400]
      );
    }

    $host = (string) wp_parse_url($url, PHP_URL_HOST);
    if ($host === '') {
      return new WP_Error(
        'ai_composer_syndication_invalid_url',
        __('Please provide a valid article URL.', 'wp-intelligence'),
        ['status' => 400]
      );
    }

    $allow_private_hosts = (bool) apply_filters('ai_composer_syndication_allow_private_hosts', false, $url, $host);
    if (! $allow_private_hosts && self::is_private_or_local_host($host)) {
      return new WP_Error(
        'ai_composer_syndication_private_url',
        __('For security reasons, local and private network URLs are not allowed for syndication.', 'wp-intelligence'),
        ['status' => 400]
      );
    }

    if (function_exists('wp_http_validate_url')) {
      $validated = wp_http_validate_url($url);
      if ($validated === false && ! $allow_private_hosts) {
        return new WP_Error(
          'ai_composer_syndication_private_url',
          __('That URL is blocked by WordPress HTTP safety checks. Use a public article URL.', 'wp-intelligence'),
          ['status' => 400]
        );
      }
    }

    return true;
  }

  private static function is_private_or_local_host(string $host): bool {
    $normalized = strtolower(trim($host));
    if ($normalized === '') {
      return true;
    }

    if (in_array($normalized, ['localhost', '127.0.0.1', '::1'], true)) {
      return true;
    }

    $local_tlds = ['.local', '.test', '.invalid', '.example', '.internal', '.localhost'];
    foreach ($local_tlds as $suffix) {
      if (str_ends_with($normalized, $suffix)) {
        return true;
      }
    }

    if (filter_var($normalized, FILTER_VALIDATE_IP) !== false) {
      $public_ip = filter_var(
        $normalized,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
      );

      return ($public_ip === false);
    }

    $resolved = gethostbyname($normalized);
    if ($resolved !== $normalized && filter_var($resolved, FILTER_VALIDATE_IP) !== false) {
      $public_ip = filter_var(
        $resolved,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
      );

      if ($public_ip === false) {
        return true;
      }
    }

    return false;
  }

  // ------------------------------------------------------------------
  // Remote fetch — strategy router
  // ------------------------------------------------------------------

  private function get_firecrawl_key(): string {
    $syn = AI_Composer_Settings::get_syndication_settings();
    return trim((string) ($syn['firecrawl_api_key'] ?? ''));
  }

  private function get_fetch_strategy(): string {
    return $this->get_firecrawl_key() !== '' ? 'firecrawl' : 'builtin';
  }

  /**
   * Fetch article content from a remote URL.
   *
   * Always tries the free builtin fetch first. If that fails (403, blocked,
   * empty) and a Firecrawl API key is configured, retries via Firecrawl
   * which can bypass anti-scraping protections.
   *
   * @return string|array|WP_Error  HTML string, Firecrawl article array, or error.
   */
  private function fetch_remote_content(string $url): string|array|WP_Error {
    $firecrawl_key = $this->get_firecrawl_key();
    $has_firecrawl = $firecrawl_key !== '';

    $result = $this->fetch_via_builtin($url);

    if (! is_wp_error($result)) {
      return $result;
    }

    if ($has_firecrawl) {
      return $this->fetch_via_firecrawl($url, $firecrawl_key);
    }

    return $result;
  }

  // ------------------------------------------------------------------
  // Strategy: built-in wp_remote_get
  // ------------------------------------------------------------------

  private function fetch_via_builtin(string $url): string|WP_Error {
    $user_agent = apply_filters(
      'ai_composer_syndication_user_agent',
      'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
    );

    $response = wp_remote_get($url, [
      'timeout'            => 10,
      'redirection'        => 3,
      'sslverify'          => true,
      'reject_unsafe_urls' => true,
      'user-agent'         => $user_agent,
      'headers'            => [
        'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language'  => 'en-AU,en;q=0.9',
        'Accept-Encoding'  => 'gzip, deflate, br',
        'Cache-Control'    => 'no-cache',
        'Sec-Fetch-Dest'   => 'document',
        'Sec-Fetch-Mode'   => 'navigate',
        'Sec-Fetch-Site'   => 'none',
        'Sec-Fetch-User'   => '?1',
        'Upgrade-Insecure-Requests' => '1',
      ],
    ]);

    if (is_wp_error($response)) {
      return new WP_Error(
        'ai_composer_syndication_fetch_error',
        __('The article URL could not be reached.', 'wp-intelligence'),
        ['status' => 502]
      );
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    if ($status === 403) {
      return new WP_Error(
        'ai_composer_syndication_http_error',
        __('The source blocked the request (HTTP 403). This site has anti-scraping protections. Try switching to Firecrawl in settings, or paste the article text manually.', 'wp-intelligence'),
        ['status' => 403]
      );
    }
    if ($status < 200 || $status >= 400) {
      return new WP_Error(
        'ai_composer_syndication_http_error',
        sprintf(__('The source returned HTTP %d.', 'wp-intelligence'), $status),
        ['status' => $status >= 400 ? $status : 502]
      );
    }

    $ct = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
    if ($ct !== '' && strpos($ct, 'text/html') === false && strpos($ct, 'application/xhtml+xml') === false) {
      return new WP_Error(
        'ai_composer_syndication_not_html',
        __('That URL does not appear to be a standard HTML article page.', 'wp-intelligence'),
        ['status' => 400]
      );
    }

    $body = (string) wp_remote_retrieve_body($response);
    if (trim($body) === '') {
      return new WP_Error(
        'ai_composer_syndication_empty',
        __('The source page did not return readable content.', 'wp-intelligence'),
        ['status' => 422]
      );
    }

    return $body;
  }

  // ------------------------------------------------------------------
  // Strategy: Firecrawl API (https://firecrawl.dev)
  // ------------------------------------------------------------------

  /**
   * Firecrawl extraction schema for article content.
   *
   * Uses Firecrawl's JSON mode so their LLM strips navigation, ads, and
   * boilerplate — we get clean article data without regex hacks.
   */
  private function get_firecrawl_article_schema(): array {
    return [
      'type'       => 'object',
      'properties' => [
        'title' => [
          'type'        => 'string',
          'description' => 'The article headline/title.',
        ],
        'author' => [
          'type'        => ['string', 'null'],
          'description' => 'Article author name. Return null if not found.',
        ],
        'published_date' => [
          'type'        => ['string', 'null'],
          'description' => 'Publication date in YYYY-MM-DD format. Return null if not found.',
        ],
        'source_name' => [
          'type'        => ['string', 'null'],
          'description' => 'The publication or website name (e.g. "The Guardian", "realestate.com.au"). Return null if not found.',
        ],
        'description' => [
          'type'        => ['string', 'null'],
          'description' => 'A brief 1-2 sentence summary of the article. Return null if not found.',
        ],
        'article_body' => [
          'type'        => 'string',
          'description' => 'The complete article body text with all paragraphs preserved. Separate paragraphs with double newlines. Exclude navigation, advertisements, related article links, sidebars, footer content, cookie notices, newsletter signups, and survey prompts. Include only the actual article content.',
        ],
        'images' => [
          'type'  => 'array',
          'items' => [
            'type'       => 'object',
            'properties' => [
              'url'     => ['type' => 'string', 'description' => 'Full absolute image URL.'],
              'alt'     => ['type' => ['string', 'null'], 'description' => 'Image alt text or caption.'],
              'credit'  => ['type' => ['string', 'null'], 'description' => 'Photo credit if available.'],
            ],
            'required' => ['url'],
          ],
          'description' => 'Up to 5 article images (photos only — exclude logos, icons, ads, avatars, and UI elements). Return empty array if none found.',
        ],
      ],
      'required' => ['title', 'article_body'],
    ];
  }

  /**
   * Fetch via Firecrawl using fast markdown mode + metadata.
   *
   * Markdown mode completes in ~1-2s vs ~30s+ for JSON extraction.
   * Metadata (og:image, og:title, publishedTime) comes free with every response.
   * Our AI provider handles the content rewriting anyway.
   */
  private function fetch_via_firecrawl(string $url, string $api_key): array|WP_Error {
    $endpoint = apply_filters('ai_composer_firecrawl_endpoint', 'https://api.firecrawl.dev/v2/scrape');
    $is_local = function_exists('wp_get_environment_type') && wp_get_environment_type() !== 'production';

    $response = wp_remote_post($endpoint, [
      'timeout'   => apply_filters('ai_composer_firecrawl_timeout', 30),
      'sslverify' => ! $is_local,
      'headers'   => [
        'Authorization' => 'Bearer ' . $api_key,
        'Content-Type'  => 'application/json',
      ],
      'body' => wp_json_encode([
        'url'             => $url,
        'formats'         => ['markdown'],
        'onlyMainContent' => true,
        'excludeTags'     => ['nav', 'footer', 'aside', 'header', '.sidebar', '.menu', '.breadcrumb', '.related', '.ad', '.newsletter', '.subscribe', '.comments', 'iframe', 'form'],
      ]),
    ]);

    if (is_wp_error($response)) {
      return new WP_Error(
        'ai_composer_syndication_firecrawl_error',
        sprintf(
          __('Could not reach the Firecrawl API: %s', 'wp-intelligence'),
          $response->get_error_message()
        ),
        ['status' => 502]
      );
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    if ($status === 401 || $status === 403) {
      return new WP_Error(
        'ai_composer_syndication_firecrawl_auth',
        __('Firecrawl API key is invalid or expired. Check your key in settings.', 'wp-intelligence'),
        ['status' => $status]
      );
    }
    if ($status === 429) {
      return new WP_Error(
        'ai_composer_syndication_firecrawl_rate',
        __('Firecrawl rate limit reached. Wait a moment and try again.', 'wp-intelligence'),
        ['status' => 429]
      );
    }
    if ($status < 200 || $status >= 400) {
      return new WP_Error(
        'ai_composer_syndication_firecrawl_http',
        sprintf(__('Firecrawl returned HTTP %d.', 'wp-intelligence'), $status),
        ['status' => $status >= 400 ? $status : 502]
      );
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (! is_array($body) || empty($body['success'])) {
      $error_msg = $body['error'] ?? __('Firecrawl returned an unsuccessful response.', 'wp-intelligence');
      return new WP_Error('ai_composer_syndication_firecrawl_fail', $error_msg, ['status' => 502]);
    }

    $data     = $body['data'] ?? [];
    $metadata = $data['metadata'] ?? [];
    $markdown = self::normalize_ws(trim((string) ($data['markdown'] ?? '')));

    if (self::mb_len($markdown) < 280) {
      return new WP_Error(
        'ai_composer_syndication_firecrawl_short',
        __('Firecrawl could not extract enough readable content from this URL.', 'wp-intelligence'),
        ['status' => 422]
      );
    }

    if (self::mb_len($markdown) > 12000) {
      $markdown = self::mb_cut($markdown, 0, 12000);
    }

    $title       = wp_strip_all_tags((string) ($metadata['title'] ?? $metadata['ogTitle'] ?? ''));
    $description = wp_strip_all_tags((string) ($metadata['description'] ?? $metadata['ogDescription'] ?? ''));
    $source_name = wp_strip_all_tags((string) ($metadata['ogSiteName'] ?? ''));
    $pub_date    = (string) ($metadata['publishedTime'] ?? $metadata['article:published_time'] ?? '');
    $og_image    = trim((string) ($metadata['ogImage'] ?? ''));

    if ($source_name === '') {
      $source_name = self::source_from_url($url);
    }

    return [
      'url'            => $url,
      'title'          => $title !== '' ? $title : ($description !== '' ? $description : 'Featured article'),
      'description'    => $description,
      'excerpt'        => wp_strip_all_tags(wp_trim_words(preg_replace('/\s+/', ' ', $markdown), 45, '...')),
      'source_name'    => $source_name,
      'published_date' => self::normalize_date($pub_date),
      'body_text'      => $markdown,
      'og_image'       => $og_image,
      '_via'           => 'firecrawl',
    ];
  }

  // ------------------------------------------------------------------
  // Article extraction (DOM + regex fallback)
  // ------------------------------------------------------------------

  private function extract_article(string $html, string $url): array|WP_Error {
    $title = $description = $published_date = $source_name = $body_text = '';

    if (class_exists('DOMDocument') && class_exists('DOMXPath')) {
      $doc = new \DOMDocument();
      $prev = libxml_use_internal_errors(true);
      $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
      libxml_clear_errors();
      libxml_use_internal_errors($prev);

      $xpath = new \DOMXPath($doc);

      $title = self::xpath_first($xpath, [
        'string(//meta[@property="og:title"]/@content)',
        'string(//title)',
      ]);

      $description = self::xpath_first($xpath, [
        'string(//meta[@name="description"]/@content)',
        'string(//meta[@property="og:description"]/@content)',
      ]);

      $source_name = trim((string) $xpath->evaluate('string(//meta[@property="og:site_name"]/@content)'));

      $published_date = self::xpath_first($xpath, [
        'string(//meta[@property="article:published_time"]/@content)',
        'string(//meta[@name="article:published_time"]/@content)',
        'string(//meta[@name="pubdate"]/@content)',
        'string(//time/@datetime)',
      ]);

      $ld = $this->extract_jsonld_article($xpath);
      if ($ld !== null) {
        if ($title === '' && ! empty($ld['headline'])) {
          $title = (string) $ld['headline'];
        }
        if ($description === '' && ! empty($ld['description'])) {
          $description = (string) $ld['description'];
        }
        if ($published_date === '' && ! empty($ld['datePublished'])) {
          $published_date = (string) $ld['datePublished'];
        }
        if ($source_name === '') {
          $pub = $ld['publisher'] ?? null;
          if (is_array($pub) && ! empty($pub['name'])) {
            $source_name = (string) $pub['name'];
          }
        }
      }

      $content_queries = [
        '//article',
        '//*[contains(concat(" ", normalize-space(@class), " "), " article-content ")]',
        '//*[contains(concat(" ", normalize-space(@class), " "), " post-content ")]',
        '//*[@itemprop="articleBody"]',
        '//main',
      ];

      foreach ($content_queries as $query) {
        $nodes = $xpath->query($query);
        if (! $nodes || $nodes->length < 1) {
          continue;
        }
        foreach ($nodes as $node) {
          $candidate = $this->readable_text_from_node($xpath, $node);
          if (self::mb_len($candidate) >= 600) {
            $body_text = $candidate;
            break 2;
          }
        }
      }

      if ($body_text === '' && $doc->documentElement) {
        $body_text = $this->readable_text_from_node($xpath, $doc->documentElement);
      }
    }

    if ($body_text === '') {
      $body_text = $this->extract_paragraphs_regex($html);
    }

    $body_text = self::normalize_ws($body_text);
    if (self::mb_len($body_text) > 12000) {
      $body_text = self::mb_cut($body_text, 0, 12000);
    }

    if (self::mb_len($body_text) < 280) {
      return new WP_Error(
        'ai_composer_syndication_unreadable',
        __('Could not extract enough readable article text from this URL.', 'wp-intelligence'),
        ['status' => 422]
      );
    }

    if ($title === '') {
      $title = $description !== '' ? $description : 'Featured article';
    }
    if ($source_name === '') {
      $source_name = self::source_from_url($url);
    }

    $images = [];
    $og_image = '';
    if (isset($xpath)) {
      $images = $this->extract_images_from_dom($xpath, $ld ?? null);
      $og_image = trim((string) $xpath->evaluate('string(//meta[@property="og:image"]/@content)'));
    }
    if ($og_image === '' && ! empty($images)) {
      $og_image = $images[0]['url'];
    }

    return [
      'url'            => $url,
      'title'          => wp_strip_all_tags($title),
      'description'    => wp_strip_all_tags($description),
      'excerpt'        => wp_strip_all_tags(wp_trim_words(preg_replace('/\s+/', ' ', $body_text), 45, '...')),
      'source_name'    => wp_strip_all_tags($source_name),
      'published_date' => self::normalize_date($published_date),
      'body_text'      => $body_text,
      'images'         => $images,
      'og_image'       => $og_image,
    ];
  }

  /**
   * Extract the first Article/NewsArticle JSON-LD block from the page.
   *
   * @return array<string, mixed>|null
   */
  private function extract_jsonld_article(\DOMXPath $xpath): ?array {
    $scripts = $xpath->query('//script[@type="application/ld+json"]');
    if (! $scripts || $scripts->length < 1) {
      return null;
    }

    foreach ($scripts as $script) {
      $raw = trim((string) $script->textContent);
      if ($raw === '') {
        continue;
      }
      $data = json_decode($raw, true);
      if (! is_array($data)) {
        continue;
      }

      if (isset($data['@graph']) && is_array($data['@graph'])) {
        foreach ($data['@graph'] as $node) {
          if (is_array($node) && $this->is_article_type($node)) {
            return $node;
          }
        }
      }

      if ($this->is_article_type($data)) {
        return $data;
      }
    }

    return null;
  }

  private function is_article_type(array $data): bool {
    $type = $data['@type'] ?? '';
    if (is_array($type)) {
      $type = implode(' ', $type);
    }
    return (bool) preg_match('/Article|NewsArticle|BlogPosting|ReportageNewsArticle/i', (string) $type);
  }

  /**
   * Collect article images from OG tags, JSON-LD, and content <img> tags.
   *
   * @return list<array{url: string, alt: string, credit: string}>
   */
  private function extract_images_from_dom(\DOMXPath $xpath, ?array $jsonld): array {
    $seen   = [];
    $images = [];

    $og_image = trim((string) $xpath->evaluate('string(//meta[@property="og:image"]/@content)'));
    if ($og_image !== '' && str_starts_with($og_image, 'http')) {
      $seen[$og_image] = true;
      $images[] = [
        'url'    => $og_image,
        'alt'    => trim((string) $xpath->evaluate('string(//meta[@property="og:image:alt"]/@content)')),
        'credit' => '',
      ];
    }

    if ($jsonld !== null) {
      $ld_images = $jsonld['image'] ?? [];
      if (is_string($ld_images)) {
        $ld_images = [['url' => $ld_images]];
      } elseif (is_array($ld_images) && isset($ld_images['url'])) {
        $ld_images = [$ld_images];
      } elseif (is_array($ld_images) && isset($ld_images[0]) && is_string($ld_images[0])) {
        $ld_images = array_map(fn($u) => ['url' => $u], $ld_images);
      }
      foreach (array_slice($ld_images, 0, 3) as $li) {
        if (! is_array($li)) {
          continue;
        }
        $u = (string) ($li['url'] ?? '');
        if ($u !== '' && str_starts_with($u, 'http') && ! isset($seen[$u])) {
          $seen[$u] = true;
          $images[] = [
            'url'    => $u,
            'alt'    => (string) ($li['caption'] ?? $li['name'] ?? ''),
            'credit' => '',
          ];
        }
      }
    }

    $article_imgs = $xpath->query('//article//img | //main//img | //*[@itemprop="articleBody"]//img');
    if ($article_imgs && $article_imgs->length > 0) {
      foreach ($article_imgs as $img) {
        if (count($images) >= 5) {
          break;
        }
        $src = trim((string) $img->getAttribute('src'));
        if ($src === '' || ! str_starts_with($src, 'http') || isset($seen[$src])) {
          continue;
        }
        $width = (int) $img->getAttribute('width');
        if ($width > 0 && $width < 200) {
          continue;
        }
        $seen[$src] = true;
        $images[] = [
          'url'    => $src,
          'alt'    => trim((string) $img->getAttribute('alt')),
          'credit' => '',
        ];
      }
    }

    return array_slice($images, 0, 5);
  }

  private function readable_text_from_node(\DOMXPath $xpath, \DOMNode $node): string {
    $nodes = $xpath->query('.//p | .//li', $node);
    if (! $nodes) {
      return '';
    }

    $skip = ['cookie', 'subscribe', 'newsletter', 'sign up', 'all rights reserved', 'advertisement'];
    $pieces = [];

    foreach ($nodes as $n) {
      $text = self::normalize_ws(wp_strip_all_tags((string) $n->textContent));
      if (self::mb_len($text) < 48) {
        continue;
      }

      $lower = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
      $is_boilerplate = false;
      foreach ($skip as $marker) {
        if (str_contains($lower, $marker)) {
          $is_boilerplate = true;
          break;
        }
      }
      if ($is_boilerplate) {
        continue;
      }

      $pieces[] = $text;
      if (count($pieces) >= 45) {
        break;
      }
    }

    return implode("\n\n", $pieces);
  }

  private function extract_paragraphs_regex(string $html): string {
    preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $html, $matches);
    if (empty($matches[1])) {
      return '';
    }

    $pieces = [];
    foreach ($matches[1] as $raw) {
      $p = self::normalize_ws(html_entity_decode(wp_strip_all_tags((string) $raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
      if (self::mb_len($p) < 48) {
        continue;
      }
      $pieces[] = $p;
      if (count($pieces) >= 35) {
        break;
      }
    }

    return implode("\n\n", $pieces);
  }

  // ------------------------------------------------------------------
  // AI draft generation
  // ------------------------------------------------------------------

  private function generate_draft(array $article, string $prompt = '', int $word_count = 600): array|WP_Error {
    if (! $this->provider->is_available()) {
      return new WP_Error(
        'ai_composer_syndication_no_provider',
        __('No AI provider is configured.', 'wp-intelligence'),
        ['status' => 503]
      );
    }

    $settings = AI_Composer_Settings::get_syndication_settings();
    $custom_prompt = trim((string) ($settings['system_prompt'] ?? ''));

    $system_prompt = $custom_prompt !== '' ? $custom_prompt : $this->get_default_system_prompt($word_count);
    $system_prompt = apply_filters('ai_composer_syndication_system_prompt', $system_prompt, $word_count);

    $site_name = apply_filters(
      'ai_composer_syndication_site_name',
      get_bloginfo('name')
    );

    $payload_data = [
      'site_name'        => sanitize_text_field($site_name),
      'source_url'       => (string) ($article['url'] ?? ''),
      'source_title'     => (string) ($article['title'] ?? ''),
      'source_name'      => (string) ($article['source_name'] ?? ''),
      'published_date'   => (string) ($article['published_date'] ?? ''),
      'source_excerpt'   => (string) ($article['excerpt'] ?? ''),
      'source_body_text' => (string) ($article['body_text'] ?? ''),
      'editor_prompt'    => trim($prompt),
    ];

    if (! empty($article['images'])) {
      $payload_data['source_images'] = $article['images'];
    }

    $user_payload = wp_json_encode($payload_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $schema = $this->get_output_schema();

    $raw = $this->provider->generate($system_prompt, (string) $user_payload, $schema);
    if (is_wp_error($raw)) {
      return $raw;
    }

    $content = json_decode($raw, true);
    if (! is_array($content)) {
      $content = json_decode(self::extract_json_object($raw), true);
    }
    if (! is_array($content)) {
      return new WP_Error(
        'ai_composer_syndication_bad_json',
        __('AI response was not valid JSON.', 'wp-intelligence'),
        ['status' => 502]
      );
    }

    $title        = sanitize_text_field((string) ($content['title'] ?? $article['title'] ?? ''));
    $excerpt      = sanitize_text_field((string) ($content['excerpt'] ?? $article['excerpt'] ?? ''));
    $news_source  = sanitize_text_field((string) ($content['news_source'] ?? $article['source_name'] ?? ''));
    $published    = self::normalize_date((string) ($content['published_date'] ?? $article['published_date'] ?? ''));
    $raw_html = (string) ($content['content_html'] ?? '');
    $allowed  = wp_kses_allowed_html('post');
    if (isset($allowed['a'])) {
      $allowed['a']['target'] = true;
    }
    $content_html = wp_kses($raw_html, $allowed);

    if ($content_html === '') {
      return new WP_Error(
        'ai_composer_syndication_empty_content',
        __('AI returned empty content.', 'wp-intelligence'),
        ['status' => 502]
      );
    }

    $source_url  = (string) ($article['url'] ?? '');
    $source_name = sanitize_text_field((string) ($article['source_name'] ?? ''));
    $link_label  = $source_name !== '' ? $source_name : $source_url;

    if ($source_url !== '' && ! str_contains($content_html, $source_url)) {
      $content_html .= sprintf(
        '<p><a href="%1$s" target="_blank" rel="noopener noreferrer nofollow"><strong>Read the full article on %2$s &rarr;</strong></a></p>',
        esc_url($source_url),
        esc_html($link_label)
      );
    }

    return [
      'title'          => $title,
      'excerpt'        => $excerpt,
      'content_html'   => $content_html,
      'news_source'    => $news_source,
      'published_date' => $published,
    ];
  }

  private function generate_fallback_draft(array $article): array {
    $body = (string) ($article['body_text'] ?? '');
    $paragraphs = array_slice(array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', $body) ?: []))), 0, 5);

    $html = '';
    foreach ($paragraphs as $p) {
      $html .= '<p>' . esc_html($p) . '</p>';
    }
    if ($html === '') {
      $html = '<p>' . esc_html((string) ($article['excerpt'] ?? '')) . '</p>';
    }

    $source_url  = (string) ($article['url'] ?? '');
    $source_name = sanitize_text_field((string) ($article['source_name'] ?? ''));
    $link_label  = $source_name !== '' ? $source_name : $source_url;

    if ($source_url !== '') {
      $html .= sprintf(
        '<p><a href="%1$s" target="_blank" rel="noopener noreferrer nofollow"><strong>Read the full article on %2$s &rarr;</strong></a></p>',
        esc_url($source_url),
        esc_html($link_label)
      );
    }

    return [
      'title'          => sanitize_text_field((string) ($article['title'] ?? 'Featured article')),
      'excerpt'        => sanitize_text_field((string) ($article['excerpt'] ?? '')),
      'content_html'   => $html,
      'news_source'    => $source_name,
      'published_date' => self::normalize_date((string) ($article['published_date'] ?? '')),
    ];
  }

  private function get_default_system_prompt(int $word_count = 600): string {
    $lower = max(200, (int) round($word_count * 0.8));
    $upper = (int) round($word_count * 1.2);

    return <<<PROMPT
You are a content editor writing "as featured in" newsroom posts that syndicate third-party media coverage.
The payload includes a site_name field — this is the brand/company that was featured in the media article.
Return valid JSON only.

Required JSON shape:
{
  "title": "string",
  "excerpt": "string",
  "content_html": "string",
  "news_source": "string",
  "published_date": "YYYY-MM-DD or empty string",
  "suggested_tags": ["string"]
}

CRITICAL — Anti-hallucination rules:
- ONLY use facts, statistics, quotes, and claims that appear in the provided source text.
- NEVER invent, fabricate, or extrapolate data points, percentages, dollar amounts, or quotes.
- NEVER attribute statements to people unless the source text explicitly quotes them.
- If the source lacks specific data for a claim, omit the claim entirely rather than guessing.
- When paraphrasing, stay faithful to the original meaning — do not editorialize or add analysis beyond what the source states.

Syndication framing:
- The opening paragraph MUST frame this as media coverage of the site_name brand. Examples:
  "[site_name] was recently featured in [source_name], discussing [topic]."
  "[site_name]'s research was highlighted by [source_name] in a piece covering [topic]."
  "In a recent article published by [source_name], [site_name] founder [person] shared insights on [topic]."
- Use the actual site_name and source_name from the payload — never use placeholders.
- The tone should position this as earned media coverage — authoritative, credible, and third-party validated.
- If the source article quotes someone from the site_name organisation, lead with that angle.

Content rules:
- Write in Australian English with a professional, authoritative tone.
- Aim for {$lower}–{$upper} words of content_html.
- After the intro, structure the content with clear sections using h2/h3 headings (e.g. key findings, market data, expert commentary, outlook).
- Use clean semantic HTML for the WordPress block editor:
  allowed tags: p, h2, h3, ul, ol, li, strong, em, a, blockquote, figure, img, figcaption.
- Preserve specific numbers, statistics, and direct quotes with attribution.
- Use <blockquote> for direct quotes, followed by attribution text.
- If source_images are provided, include up to 2 relevant article photos using <figure><img src="URL" alt="description"><figcaption>Credit/caption</figcaption></figure>. Omit logos, icons, ads, and UI screenshots.
- End with a paragraph linking to the source_url: <a href="URL" target="_blank" rel="noopener noreferrer nofollow"><strong>Read the full article on SOURCE_NAME →</strong></a>
- If an editor_prompt is provided, follow its guidance for tone, length, or focus.
- Never include markdown fences or extra commentary outside JSON.
PROMPT;
  }

  private function get_output_schema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'title'          => ['type' => 'string'],
        'excerpt'        => ['type' => 'string'],
        'content_html'   => ['type' => 'string'],
        'news_source'    => ['type' => 'string'],
        'published_date' => ['type' => 'string'],
        'suggested_tags' => ['type' => 'array', 'items' => ['type' => 'string']],
      ],
      'required'             => ['title', 'excerpt', 'content_html', 'news_source', 'published_date', 'suggested_tags'],
      'additionalProperties' => false,
    ];
  }

  // ------------------------------------------------------------------
  // Post meta + taxonomy
  // ------------------------------------------------------------------

  private function save_post_meta(int $post_id, string $url, array $generated): void {
    update_post_meta($post_id, self::META_SOURCE_URL, esc_url_raw($url));
    update_post_meta($post_id, self::META_SOURCE_NAME, sanitize_text_field((string) ($generated['news_source'] ?? '')));
    update_post_meta($post_id, self::META_PUB_DATE, sanitize_text_field((string) ($generated['published_date'] ?? '')));

    do_action('ai_composer_syndication_save_meta', $post_id, $url, $generated);
  }

  private function assign_source_term(int $post_id, string $source_name): bool {
    $source_name = sanitize_text_field($source_name);
    if ($source_name === '' || $post_id <= 0) {
      return false;
    }

    $settings = AI_Composer_Settings::get_syndication_settings();
    $taxonomy = sanitize_key((string) ($settings['source_taxonomy'] ?? ''));
    $taxonomy = apply_filters('ai_composer_syndication_source_taxonomy', $taxonomy, $post_id);

    if ($taxonomy === '' || ! taxonomy_exists($taxonomy)) {
      return false;
    }

    $post_type = get_post_type($post_id);
    if (! $post_type || ! is_object_in_taxonomy($post_type, $taxonomy)) {
      return false;
    }

    $term = term_exists($source_name, $taxonomy);
    if (! $term) {
      $term = wp_insert_term($source_name, $taxonomy);
    }
    if (is_wp_error($term)) {
      return false;
    }

    $term_id = is_array($term) ? absint($term['term_id']) : absint($term);
    if ($term_id <= 0) {
      return false;
    }

    return ! is_wp_error(wp_set_object_terms($post_id, [$term_id], $taxonomy, true));
  }

  // ------------------------------------------------------------------
  // Image sideloading
  // ------------------------------------------------------------------

  /**
   * Find external <img> tags in content HTML, sideload them into the WP
   * media library, and replace the src URLs with local attachment URLs.
   *
   * @return array{html: string, count: int}
   */
  private function sideload_content_images(string $html, int $post_id): array {
    if ($post_id <= 0 || trim($html) === '') {
      return ['html' => $html, 'count' => 0];
    }

    if (! function_exists('media_sideload_image')) {
      require_once ABSPATH . 'wp-admin/includes/media.php';
      require_once ABSPATH . 'wp-admin/includes/file.php';
      require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $site_host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    $count = 0;

    if (! preg_match_all('/<img\s[^>]*src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches, PREG_SET_ORDER)) {
      return ['html' => $html, 'count' => 0];
    }

    foreach ($matches as $match) {
      $original_tag = $match[0];
      $img_url      = $match[1];

      if (! str_starts_with($img_url, 'http')) {
        continue;
      }

      $img_host = (string) wp_parse_url($img_url, PHP_URL_HOST);
      if ($img_host === $site_host) {
        continue;
      }

      $attachment_id = media_sideload_image($img_url, $post_id, '', 'id');
      if (is_wp_error($attachment_id)) {
        continue;
      }

      $local_url = wp_get_attachment_url($attachment_id);
      if ($local_url === false) {
        continue;
      }

      $html = str_replace($img_url, $local_url, $html);
      $count++;
    }

    return ['html' => $html, 'count' => $count];
  }

  /**
   * Download an image URL and set it as the post's featured image.
   *
   * @return int Attachment ID on success, 0 on failure.
   */
  private function sideload_featured_image(string $url, int $post_id, string $description = ''): int {
    if (! function_exists('media_sideload_image')) {
      require_once ABSPATH . 'wp-admin/includes/media.php';
      require_once ABSPATH . 'wp-admin/includes/file.php';
      require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    add_filter('http_request_args', [$this, 'relax_sideload_args'], 10, 2);
    $attachment_id = media_sideload_image($url, $post_id, $description, 'id');
    remove_filter('http_request_args', [$this, 'relax_sideload_args'], 10);

    if (is_wp_error($attachment_id)) {
      return 0;
    }

    set_post_thumbnail($post_id, $attachment_id);
    return (int) $attachment_id;
  }

  /**
   * @internal Temporarily relax SSL/redirect settings for image sideloading
   * since many news sites serve images via CDN with redirects.
   */
  public function relax_sideload_args(array $args, string $url): array {
    $args['timeout']     = 30;
    $args['redirection'] = 10;
    return $args;
  }

  // ------------------------------------------------------------------
  // Utility helpers
  // ------------------------------------------------------------------

  private static function xpath_first(\DOMXPath $xpath, array $queries): string {
    foreach ($queries as $q) {
      $v = trim((string) $xpath->evaluate($q));
      if ($v !== '') {
        return $v;
      }
    }
    return '';
  }

  private static function normalize_date(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') {
      return '';
    }
    $ts = strtotime($raw);
    return $ts !== false ? gmdate('Y-m-d', $ts) : '';
  }

  private static function source_from_url(string $url): string {
    $host = (string) wp_parse_url($url, PHP_URL_HOST);
    $host = (string) preg_replace('/^www\./i', '', $host);
    return $host !== '' ? $host : 'External Source';
  }

  private static function normalize_ws(string $text): string {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
    $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
  }

  private static function mb_len(string $s): int {
    return function_exists('mb_strlen') ? (int) mb_strlen($s) : strlen($s);
  }

  private static function mb_cut(string $s, int $start, ?int $len = null): string {
    if (function_exists('mb_substr')) {
      return $len === null ? (string) mb_substr($s, $start) : (string) mb_substr($s, $start, $len);
    }
    return $len === null ? (string) substr($s, $start) : (string) substr($s, $start, $len);
  }

  private static function extract_json_object(string $text): string {
    $text = trim($text);
    $d = json_decode($text, true);
    if (is_array($d)) {
      return $text;
    }

    if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/is', $text, $m)) {
      $d = json_decode(trim($m[1]), true);
      if (is_array($d)) {
        return trim($m[1]);
      }
    }

    $s = strpos($text, '{');
    $e = strrpos($text, '}');
    if ($s !== false && $e !== false && $e > $s) {
      $c = substr($text, $s, $e - $s + 1);
      if (is_array(json_decode($c, true))) {
        return $c;
      }
    }

    return '';
  }
}
