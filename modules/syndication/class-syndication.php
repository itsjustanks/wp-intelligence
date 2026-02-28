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
    $js_url  = defined('WPI_URL') ? WPI_URL . 'modules/syndication/editor/syndication.js' : '';

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

    wp_localize_script('wpi-syndication', 'wpiSyndicationConfig', [
      'restNamespace'    => 'ai-composer/v1',
      'nonce'            => wp_create_nonce('wp_rest'),
      'enabledPostTypes' => $this->get_enabled_post_types(),
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
        'url'     => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw'],
        'prompt'  => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
        'post_id' => ['type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint'],
      ],
    ]);
  }

  /**
   * @param WP_REST_Request $request
   * @return WP_REST_Response|WP_Error
   */
  public function handle_syndicate_request(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $result = $this->syndicate(
      $request->get_param('url'),
      $request->get_param('prompt'),
      $request->get_param('post_id')
    );

    if (is_wp_error($result)) {
      return $result;
    }

    return new WP_REST_Response($result, 200);
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
  public function syndicate(string $url, string $prompt = '', int $post_id = 0): array|WP_Error {
    $url = esc_url_raw($url);
    if (! self::validate_url($url)) {
      return new WP_Error('ai_composer_syndication_invalid_url', __('Please provide a valid article URL.', 'wp-intelligence'), ['status' => 400]);
    }

    do_action('ai_composer_before_syndicate', $url, $prompt, $post_id);

    $html = $this->fetch_remote_html($url);
    if (is_wp_error($html)) {
      return $html;
    }

    $article = $this->extract_article($html, $url);
    if (is_wp_error($article)) {
      return $article;
    }

    $generated = $this->generate_draft($article, $prompt);
    $used_ai   = ! is_wp_error($generated);

    if (is_wp_error($generated)) {
      $generated = $this->generate_fallback_draft($article);
      $used_ai   = false;
    }

    $taxonomy_assigned = false;
    if ($post_id > 0) {
      $this->save_post_meta($post_id, $url, $generated);
      $taxonomy_assigned = $this->assign_source_term($post_id, (string) ($generated['news_source'] ?? ''));
    }

    $result = [
      'title'            => (string) ($generated['title'] ?? ''),
      'excerpt'          => (string) ($generated['excerpt'] ?? ''),
      'content'          => (string) ($generated['content_html'] ?? ''),
      'newsSource'       => (string) ($generated['news_source'] ?? ''),
      'publishedDate'    => (string) ($generated['published_date'] ?? ''),
      'taxonomyAssigned' => $taxonomy_assigned,
      'usedAI'           => $used_ai,
      'sourceUrl'        => $url,
    ];

    $result = apply_filters('ai_composer_syndication_response', $result, $article, $generated);

    do_action('ai_composer_after_syndicate', $result, $post_id);

    return $result;
  }

  // ------------------------------------------------------------------
  // URL validation
  // ------------------------------------------------------------------

  public static function validate_url(string $url): bool {
    $url = trim($url);
    if ($url === '') {
      return false;
    }

    $url = esc_url_raw($url);
    if ($url === '') {
      return false;
    }

    $scheme = wp_parse_url($url, PHP_URL_SCHEME);
    return in_array($scheme, ['http', 'https'], true);
  }

  // ------------------------------------------------------------------
  // Remote fetch
  // ------------------------------------------------------------------

  private function fetch_remote_html(string $url): string|WP_Error {
    $response = wp_remote_get($url, [
      'timeout'            => 25,
      'redirection'        => 5,
      'sslverify'          => true,
      'reject_unsafe_urls' => true,
      'headers'            => ['Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8'],
      'user-agent'         => 'WPIntelligenceSyndicationBot/1.0',
    ]);

    if (is_wp_error($response)) {
      return new WP_Error('ai_composer_syndication_fetch_error', __('The article URL could not be reached.', 'wp-intelligence'));
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 400) {
      return new WP_Error('ai_composer_syndication_http_error', sprintf(__('The source returned HTTP %d.', 'wp-intelligence'), $status));
    }

    $ct = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
    if ($ct !== '' && strpos($ct, 'text/html') === false && strpos($ct, 'application/xhtml+xml') === false) {
      return new WP_Error('ai_composer_syndication_not_html', __('That URL does not appear to be a standard HTML article page.', 'wp-intelligence'));
    }

    $body = (string) wp_remote_retrieve_body($response);
    if (trim($body) === '') {
      return new WP_Error('ai_composer_syndication_empty', __('The source page did not return readable content.', 'wp-intelligence'));
    }

    return $body;
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
      return new WP_Error('ai_composer_syndication_unreadable', __('Could not extract enough readable article text from this URL.', 'wp-intelligence'));
    }

    if ($title === '') {
      $title = $description !== '' ? $description : 'Featured article';
    }
    if ($source_name === '') {
      $source_name = self::source_from_url($url);
    }

    return [
      'url'            => $url,
      'title'          => wp_strip_all_tags($title),
      'description'    => wp_strip_all_tags($description),
      'excerpt'        => wp_strip_all_tags(wp_trim_words(preg_replace('/\s+/', ' ', $body_text), 45, '...')),
      'source_name'    => wp_strip_all_tags($source_name),
      'published_date' => self::normalize_date($published_date),
      'body_text'      => $body_text,
    ];
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

  private function generate_draft(array $article, string $prompt = ''): array|WP_Error {
    if (! $this->provider->is_available()) {
      return new WP_Error('ai_composer_syndication_no_provider', __('No AI provider is configured.', 'wp-intelligence'));
    }

    $settings = AI_Composer_Settings::get_syndication_settings();
    $custom_prompt = trim((string) ($settings['system_prompt'] ?? ''));

    $system_prompt = $custom_prompt !== '' ? $custom_prompt : $this->get_default_system_prompt();
    $system_prompt = apply_filters('ai_composer_syndication_system_prompt', $system_prompt);

    $user_payload = wp_json_encode([
      'source_url'       => (string) ($article['url'] ?? ''),
      'source_title'     => (string) ($article['title'] ?? ''),
      'source_name'      => (string) ($article['source_name'] ?? ''),
      'published_date'   => (string) ($article['published_date'] ?? ''),
      'source_excerpt'   => (string) ($article['excerpt'] ?? ''),
      'source_body_text' => (string) ($article['body_text'] ?? ''),
      'editor_prompt'    => trim($prompt),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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
      return new WP_Error('ai_composer_syndication_bad_json', __('AI response was not valid JSON.', 'wp-intelligence'));
    }

    $title        = sanitize_text_field((string) ($content['title'] ?? $article['title'] ?? ''));
    $excerpt      = sanitize_text_field((string) ($content['excerpt'] ?? $article['excerpt'] ?? ''));
    $news_source  = sanitize_text_field((string) ($content['news_source'] ?? $article['source_name'] ?? ''));
    $published    = self::normalize_date((string) ($content['published_date'] ?? $article['published_date'] ?? ''));
    $content_html = wp_kses_post((string) ($content['content_html'] ?? ''));

    if ($content_html === '') {
      return new WP_Error('ai_composer_syndication_empty_content', __('AI returned empty content.', 'wp-intelligence'));
    }

    $source_url = (string) ($article['url'] ?? '');
    if ($source_url !== '' && ! str_contains($content_html, $source_url)) {
      $content_html .= sprintf(
        '<p><strong>Original source:</strong> <a href="%1$s" rel="noopener noreferrer nofollow">%2$s</a></p>',
        esc_url($source_url),
        esc_html($source_url)
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

    $source_url = (string) ($article['url'] ?? '');
    if ($source_url !== '') {
      $html .= sprintf(
        '<p><strong>Original source:</strong> <a href="%1$s" rel="noopener noreferrer nofollow">%2$s</a></p>',
        esc_url($source_url),
        esc_html($source_url)
      );
    }

    return [
      'title'          => sanitize_text_field((string) ($article['title'] ?? 'Featured article')),
      'excerpt'        => sanitize_text_field((string) ($article['excerpt'] ?? '')),
      'content_html'   => $html,
      'news_source'    => sanitize_text_field((string) ($article['source_name'] ?? '')),
      'published_date' => self::normalize_date((string) ($article['published_date'] ?? '')),
    ];
  }

  private function get_default_system_prompt(): string {
    return <<<'PROMPT'
You are a content editor drafting "as featured in" newsroom entries from third-party media coverage.
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

Rules:
- Keep claims factual and grounded in the provided source text only.
- Write in concise Australian English.
- Keep content_html between 200 and 500 words unless the source text is short.
- Use clean semantic HTML suitable for the WordPress block editor:
  allowed tags include p, h2, h3, ul, ol, li, strong, em, a.
- Include one "Key takeaway" section heading.
- Include a final paragraph linking to the original source URL.
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
