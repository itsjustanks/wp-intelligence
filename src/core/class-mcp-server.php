<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * MCP Server for WP Intelligence.
 *
 * Exposes trained context, brand knowledge, and site content as MCP tools
 * over Streamable HTTP transport (JSON-RPC 2.0).
 *
 * External clients (ChatGPT, Claude, Cursor, etc.) can connect to:
 *   {site_url}/wp-json/wp-intelligence/v1/mcp
 *
 * Authentication: Bearer token set in WP Intelligence settings.
 */
class WPI_MCP_Server {

  private const NAMESPACE       = 'wp-intelligence/v1';
  private const PROTOCOL_VERSION = '2024-11-05';

  private static function get_tool_definitions(): array {
    $empty_props = new \stdClass();

    return [
      'get_brand_voice' => [
        'description' => 'Get the brand voice, tone guidelines, and brand foundation for this site.',
        'inputSchema' => ['type' => 'object', 'properties' => $empty_props],
      ],
      'get_context' => [
        'description' => 'Get site context documents by slug names. Returns all context if no slugs specified.',
        'inputSchema' => [
          'type'       => 'object',
          'properties' => [
            'names' => [
              'type'        => 'array',
              'items'       => ['type' => 'string'],
              'description' => 'Context document slugs to retrieve. Empty for all.',
            ],
          ],
        ],
      ],
      'get_context_for_task' => [
        'description' => 'Get a context bundle optimized for a specific task type.',
        'inputSchema' => [
          'type'       => 'object',
          'properties' => [
            'task' => [
              'type'        => 'string',
              'description' => 'Task type: seo-article, social-post, email-nurture, website-creation, landing-page.',
            ],
          ],
          'required' => ['task'],
        ],
      ],
      'get_proof_points' => [
        'description' => 'Get verified proof points and evidence, optionally filtered by category.',
        'inputSchema' => [
          'type'       => 'object',
          'properties' => [
            'category' => [
              'type'        => 'string',
              'description' => 'Category filter: outcome, access, process, trust, research, or all.',
              'default'     => 'all',
            ],
          ],
        ],
      ],
      'get_safe_claims' => [
        'description' => 'Get verified safe claims (Tier A) that can be stated without qualification.',
        'inputSchema' => ['type' => 'object', 'properties' => $empty_props],
      ],
      'get_site_info' => [
        'description' => 'Get basic site information: name, URL, tagline, active theme, and WordPress version.',
        'inputSchema' => ['type' => 'object', 'properties' => $empty_props],
      ],
      'search_posts' => [
        'description' => 'Search published posts and pages by keyword.',
        'inputSchema' => [
          'type'       => 'object',
          'properties' => [
            'query' => [
              'type'        => 'string',
              'description' => 'Search query string.',
            ],
            'post_type' => [
              'type'        => 'string',
              'description' => 'Post type to search. Default: any public post type.',
              'default'     => 'any',
            ],
            'limit' => [
              'type'        => 'number',
              'description' => 'Maximum results to return (1-20).',
              'default'     => 10,
            ],
          ],
          'required' => ['query'],
        ],
      ],
      'get_post' => [
        'description' => 'Get the full content and metadata of a specific post or page by ID.',
        'inputSchema' => [
          'type'       => 'object',
          'properties' => [
            'post_id' => [
              'type'        => 'number',
              'description' => 'WordPress post ID.',
            ],
          ],
          'required' => ['post_id'],
        ],
      ],
    ];
  }

  private ?string $session_id = null;

  public static function init(): void {
    add_action('rest_api_init', [self::class, 'register_routes']);
  }

  public static function register_routes(): void {
    register_rest_route(self::NAMESPACE, '/mcp', [
      [
        'methods'             => 'POST',
        'callback'            => [self::class, 'handle_request'],
        'permission_callback' => [self::class, 'check_auth'],
      ],
    ]);
  }

  /**
   * Authenticate MCP requests via Bearer token.
   */
  public static function check_auth(WP_REST_Request $request): bool|WP_Error {
    if (! self::is_server_enabled()) {
      return new WP_Error('wpi_mcp_disabled', __('MCP server is not enabled.', 'wp-intelligence'), ['status' => 404]);
    }

    $token = self::get_server_token();
    if ($token === '') {
      return new WP_Error('wpi_mcp_no_token', __('MCP server token not configured.', 'wp-intelligence'), ['status' => 500]);
    }

    $auth = $request->get_header('authorization');
    if (! is_string($auth) || ! str_starts_with($auth, 'Bearer ')) {
      return new WP_Error('wpi_mcp_unauthorized', __('Missing or invalid Authorization header.', 'wp-intelligence'), ['status' => 401]);
    }

    $bearer = substr($auth, 7);
    if (! hash_equals($token, $bearer)) {
      return new WP_Error('wpi_mcp_forbidden', __('Invalid MCP server token.', 'wp-intelligence'), ['status' => 403]);
    }

    return true;
  }

  /**
   * Handle an incoming MCP JSON-RPC 2.0 request.
   */
  public static function handle_request(WP_REST_Request $request): WP_REST_Response {
    $body = $request->get_json_params();

    if (! is_array($body) || ! isset($body['method'])) {
      return self::jsonrpc_error(null, -32600, 'Invalid Request');
    }

    $method    = (string) ($body['method'] ?? '');
    $params    = (array) ($body['params'] ?? []);
    $id        = $body['id'] ?? null;
    $is_notify = ! array_key_exists('id', $body);

    $session_header = $request->get_header('mcp-session-id');
    $instance = new self();
    $instance->session_id = is_string($session_header) ? $session_header : null;

    $result = match ($method) {
      'initialize'                => $instance->handle_initialize($params),
      'notifications/initialized' => null,
      'tools/list'                => $instance->handle_tools_list(),
      'tools/call'                => $instance->handle_tools_call($params),
      'resources/list'            => $instance->handle_resources_list(),
      default                     => 'METHOD_NOT_FOUND',
    };

    if ($is_notify || $result === null) {
      return new WP_REST_Response(null, 202);
    }

    if ($result === 'METHOD_NOT_FOUND') {
      return self::jsonrpc_error($id, -32601, "Method not found: {$method}");
    }

    $response = new WP_REST_Response([
      'jsonrpc' => '2.0',
      'result'  => $result,
      'id'      => $id,
    ], 200);

    $response->header('Content-Type', 'application/json');

    if ($instance->session_id !== null) {
      $response->header('Mcp-Session-Id', $instance->session_id);
    }

    return $response;
  }

  /* ──────────────────────────────────────────────
   *  Protocol handlers
   * ────────────────────────────────────────────── */

  private function handle_initialize(array $params): array {
    $this->session_id = wp_generate_uuid4();

    return [
      'protocolVersion' => self::PROTOCOL_VERSION,
      'capabilities'    => [
        'tools'     => new \stdClass(),
        'resources' => new \stdClass(),
      ],
      'serverInfo' => [
        'name'    => get_bloginfo('name') . ' (WP Intelligence)',
        'version' => WPI_VERSION,
      ],
    ];
  }

  private function handle_tools_list(): array {
    $tools = [];

    foreach (self::get_tool_definitions() as $name => $meta) {
      $tools[] = [
        'name'        => $name,
        'description' => $meta['description'],
        'inputSchema' => $meta['inputSchema'],
      ];
    }

    return ['tools' => apply_filters('wpi_mcp_server_tools', $tools)];
  }

  private function handle_tools_call(array $params): array {
    $tool_name = (string) ($params['name'] ?? '');
    $arguments = (array) ($params['arguments'] ?? []);

    if (! array_key_exists($tool_name, self::get_tool_definitions())) {
      return self::tool_error("Unknown tool: {$tool_name}");
    }

    $text = match ($tool_name) {
      'get_brand_voice'      => $this->tool_get_brand_voice(),
      'get_context'          => $this->tool_get_context($arguments),
      'get_context_for_task' => $this->tool_get_context_for_task($arguments),
      'get_proof_points'     => $this->tool_get_proof_points($arguments),
      'get_safe_claims'      => $this->tool_get_safe_claims(),
      'get_site_info'        => $this->tool_get_site_info(),
      'search_posts'         => $this->tool_search_posts($arguments),
      'get_post'             => $this->tool_get_post($arguments),
      default                => '',
    };

    if ($text === '') {
      return self::tool_error("No content available for {$tool_name}.");
    }

    return [
      'content' => [
        ['type' => 'text', 'text' => $text],
      ],
    ];
  }

  private function handle_resources_list(): array {
    $site_url = home_url();

    return [
      'resources' => [
        [
          'uri'         => "wpi://context/brand-voice",
          'name'        => 'Brand Voice',
          'description' => 'Brand voice, tone, and foundation guidelines.',
          'mimeType'    => 'text/plain',
        ],
        [
          'uri'         => "wpi://context/all",
          'name'        => 'Full Site Context',
          'description' => 'All available site context documents.',
          'mimeType'    => 'text/plain',
        ],
        [
          'uri'         => "wpi://site/info",
          'name'        => 'Site Information',
          'description' => 'Basic site metadata: name, URL, tagline.',
          'mimeType'    => 'application/json',
        ],
      ],
    ];
  }

  /* ──────────────────────────────────────────────
   *  Tool implementations
   * ────────────────────────────────────────────── */

  private function tool_get_brand_voice(): string {
    if (! class_exists('AI_Composer_Context_Provider')) {
      return $this->fallback_brand_context();
    }
    $result = AI_Composer_Context_Provider::get_brand_voice();
    return $result !== '' ? $result : $this->fallback_brand_context();
  }

  private function tool_get_context(array $args): string {
    if (! class_exists('AI_Composer_Context_Provider')) {
      return '';
    }
    $slugs = isset($args['names']) && is_array($args['names']) ? $args['names'] : [];
    return AI_Composer_Context_Provider::get_context($slugs);
  }

  private function tool_get_context_for_task(array $args): string {
    if (! class_exists('AI_Composer_Context_Provider')) {
      return '';
    }
    $task = sanitize_text_field($args['task'] ?? 'seo-article');
    return AI_Composer_Context_Provider::get_context_for_task($task);
  }

  private function tool_get_proof_points(array $args): string {
    if (! class_exists('AI_Composer_Context_Provider')) {
      return '';
    }
    $category = sanitize_key($args['category'] ?? 'all');
    return AI_Composer_Context_Provider::get_proof_points($category);
  }

  private function tool_get_safe_claims(): string {
    if (! class_exists('AI_Composer_Context_Provider')) {
      return '';
    }
    return AI_Composer_Context_Provider::get_safe_claims();
  }

  private function tool_get_site_info(): string {
    $info = [
      'name'              => get_bloginfo('name'),
      'url'               => home_url(),
      'tagline'           => get_bloginfo('description'),
      'language'          => get_bloginfo('language'),
      'timezone'          => wp_timezone_string(),
      'theme'             => wp_get_theme()->get('Name'),
      'wordpress_version' => get_bloginfo('version'),
      'post_types'        => array_values(get_post_types(['public' => true, '_builtin' => false], 'names')),
    ];

    return wp_json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  }

  private function tool_search_posts(array $args): string {
    $query_str = sanitize_text_field($args['query'] ?? '');
    if ($query_str === '') {
      return '';
    }

    $post_type = sanitize_key($args['post_type'] ?? 'any');
    $limit     = max(1, min(20, (int) ($args['limit'] ?? 10)));

    $query_args = [
      's'              => $query_str,
      'post_status'    => 'publish',
      'posts_per_page' => $limit,
      'post_type'      => $post_type === 'any' ? get_post_types(['public' => true]) : $post_type,
      'orderby'        => 'relevance',
    ];

    $query   = new WP_Query($query_args);
    $results = [];

    foreach ($query->posts as $post) {
      $results[] = [
        'id'        => $post->ID,
        'title'     => $post->post_title,
        'url'       => get_permalink($post),
        'type'      => $post->post_type,
        'date'      => $post->post_date,
        'excerpt'   => wp_trim_words(wp_strip_all_tags($post->post_content), 40),
      ];
    }

    if (empty($results)) {
      return "No results found for \"{$query_str}\".";
    }

    return wp_json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  }

  private function tool_get_post(array $args): string {
    $post_id = (int) ($args['post_id'] ?? 0);
    if ($post_id <= 0) {
      return '';
    }

    $post = get_post($post_id);
    if (! $post || $post->post_status !== 'publish') {
      return "Post {$post_id} not found or not published.";
    }

    $content = wp_strip_all_tags($post->post_content);
    $content = preg_replace('/\s+/', ' ', $content);

    $data = [
      'id'         => $post->ID,
      'title'      => $post->post_title,
      'url'        => get_permalink($post),
      'type'       => $post->post_type,
      'date'       => $post->post_date,
      'modified'   => $post->post_modified,
      'author'     => get_the_author_meta('display_name', $post->post_author),
      'excerpt'    => $post->post_excerpt ?: wp_trim_words($content, 55),
      'content'    => mb_substr($content, 0, 8000),
      'categories' => wp_get_post_categories($post_id, ['fields' => 'names']),
      'tags'       => wp_get_post_tags($post_id, ['fields' => 'names']),
    ];

    return wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  }

  /**
   * Fallback brand context from syndication settings when MCP provider is unavailable.
   */
  private function fallback_brand_context(): string {
    if (! class_exists('AI_Composer_Settings')) {
      return '';
    }
    $syn = AI_Composer_Settings::get_syndication_settings();
    return (string) ($syn['brand_context'] ?? '');
  }

  /* ──────────────────────────────────────────────
   *  Helpers
   * ────────────────────────────────────────────── */

  private static function tool_error(string $message): array {
    return [
      'content' => [
        ['type' => 'text', 'text' => $message],
      ],
      'isError' => true,
    ];
  }

  private static function jsonrpc_error(?int $id, int $code, string $message): WP_REST_Response {
    return new WP_REST_Response([
      'jsonrpc' => '2.0',
      'error'   => ['code' => $code, 'message' => $message],
      'id'      => $id,
    ], 200);
  }

  public static function is_server_enabled(): bool {
    $settings = get_option('ai_composer_settings', []);
    return is_array($settings) && (($settings['mcp_server_enabled'] ?? '0') === '1');
  }

  public static function get_server_token(): string {
    $settings = get_option('ai_composer_settings', []);
    return is_array($settings) ? (string) ($settings['mcp_server_token'] ?? '') : '';
  }

  public static function get_server_url(): string {
    return rest_url(self::NAMESPACE . '/mcp');
  }
}
