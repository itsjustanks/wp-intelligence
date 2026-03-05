<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Dynamic Data REST Controller.
 *
 * Routes:
 *   GET    /wpi-dynamic-data/v1/sources       — list all data sources
 *   GET    /wpi-dynamic-data/v1/tags           — list available merge tags
 *   POST   /wpi-dynamic-data/v1/webhooks       — create a webhook source
 *   PUT    /wpi-dynamic-data/v1/webhooks/NAME  — update a webhook source
 *   DELETE /wpi-dynamic-data/v1/webhooks/NAME  — delete a webhook source
 *   POST   /wpi-dynamic-data/v1/test           — test a webhook source
 *   POST   /wpi-dynamic-data/v1/resolve        — resolve merge tags in content
 */
class WPI_Dynamic_Data_REST_Controller {

  private const NAMESPACE = 'wpi-dynamic-data/v1';

  public static function register_routes(): void {
    register_rest_route(self::NAMESPACE, '/sources', [
      'methods'             => 'GET',
      'callback'            => [self::class, 'handle_list_sources'],
      'permission_callback' => [self::class, 'check_edit_permission'],
    ]);

    register_rest_route(self::NAMESPACE, '/tags', [
      'methods'             => 'GET',
      'callback'            => [self::class, 'handle_list_tags'],
      'permission_callback' => [self::class, 'check_edit_permission'],
    ]);

    register_rest_route(self::NAMESPACE, '/webhooks', [
      'methods'             => 'POST',
      'callback'            => [self::class, 'handle_create_webhook'],
      'permission_callback' => [self::class, 'check_manage_permission'],
      'args'                => self::webhook_args(),
    ]);

    register_rest_route(self::NAMESPACE, '/webhooks/(?P<name>[a-z0-9_]+)', [
      [
        'methods'             => 'PUT',
        'callback'            => [self::class, 'handle_update_webhook'],
        'permission_callback' => [self::class, 'check_manage_permission'],
        'args'                => self::webhook_args(),
      ],
      [
        'methods'             => 'DELETE',
        'callback'            => [self::class, 'handle_delete_webhook'],
        'permission_callback' => [self::class, 'check_manage_permission'],
      ],
    ]);

    register_rest_route(self::NAMESPACE, '/test', [
      'methods'             => 'POST',
      'callback'            => [self::class, 'handle_test_webhook'],
      'permission_callback' => [self::class, 'check_manage_permission'],
      'args'                => self::webhook_args(),
    ]);

    register_rest_route(self::NAMESPACE, '/resolve', [
      'methods'             => 'POST',
      'callback'            => [self::class, 'handle_resolve'],
      'permission_callback' => [self::class, 'check_edit_permission'],
      'args'                => [
        'content' => [
          'required'          => true,
          'type'              => 'string',
          'sanitize_callback' => 'wp_kses_post',
        ],
        'post_id' => [
          'type'              => 'integer',
          'default'           => 0,
          'sanitize_callback' => 'absint',
        ],
      ],
    ]);
  }

  /* ──────────────────────────────────────────────
   *  Permission callbacks
   * ────────────────────────────────────────────── */

  public static function check_edit_permission(): bool {
    $cap = apply_filters('wpi_dynamic_data_read_capability', 'edit_posts');
    return current_user_can($cap);
  }

  public static function check_manage_permission(): bool {
    $cap = apply_filters('wpi_dynamic_data_manage_capability', 'manage_options');
    return current_user_can($cap);
  }

  /* ──────────────────────────────────────────────
   *  Handlers
   * ────────────────────────────────────────────── */

  public static function handle_list_sources(\WP_REST_Request $request): \WP_REST_Response {
    $registry = WPI_Data_Source_Registry::instance();
    return new \WP_REST_Response([
      'sources' => $registry->get_source_descriptions(),
    ]);
  }

  public static function handle_list_tags(\WP_REST_Request $request): \WP_REST_Response {
    $tags = WPI_Merge_Tag_Engine::get_available_tags();
    return new \WP_REST_Response(['tags' => $tags]);
  }

  public static function handle_create_webhook(\WP_REST_Request $request): \WP_REST_Response {
    $name = sanitize_key($request->get_param('name') ?? '');
    if ($name === '' || $name === 'wp' || $name === 'url' || $name === 'cookie') {
      return new \WP_REST_Response([
        'success' => false,
        'message' => __('Invalid webhook name. Names "wp", "url", and "cookie" are reserved.', 'wp-intelligence'),
      ], 400);
    }

    $existing = WPI_Webhook_Source::get_all_configs();
    if (isset($existing[$name])) {
      return new \WP_REST_Response([
        'success' => false,
        'message' => sprintf(__('Webhook "%s" already exists. Use PUT to update.', 'wp-intelligence'), $name),
      ], 409);
    }

    $config = $request->get_json_params();
    WPI_Webhook_Source::save_config($name, $config);

    $registry = WPI_Data_Source_Registry::instance();
    $registry->register($name, new WPI_Webhook_Source($name, WPI_Webhook_Source::sanitize_config($config)));

    return new \WP_REST_Response([
      'success' => true,
      'name'    => $name,
      'message' => sprintf(__('Webhook "%s" created.', 'wp-intelligence'), $name),
    ], 201);
  }

  public static function handle_update_webhook(\WP_REST_Request $request): \WP_REST_Response {
    $name = sanitize_key($request->get_param('name'));

    $existing = WPI_Webhook_Source::get_all_configs();
    if (! isset($existing[$name])) {
      return new \WP_REST_Response([
        'success' => false,
        'message' => sprintf(__('Webhook "%s" not found.', 'wp-intelligence'), $name),
      ], 404);
    }

    $config = $request->get_json_params();
    WPI_Webhook_Source::save_config($name, $config);

    $registry = WPI_Data_Source_Registry::instance();
    $registry->register($name, new WPI_Webhook_Source($name, WPI_Webhook_Source::sanitize_config($config)));

    return new \WP_REST_Response([
      'success' => true,
      'name'    => $name,
      'message' => sprintf(__('Webhook "%s" updated.', 'wp-intelligence'), $name),
    ]);
  }

  public static function handle_delete_webhook(\WP_REST_Request $request): \WP_REST_Response {
    $name = sanitize_key($request->get_param('name'));

    WPI_Webhook_Source::delete_config($name);

    $registry = WPI_Data_Source_Registry::instance();
    $registry->unregister($name);

    return new \WP_REST_Response([
      'success' => true,
      'message' => sprintf(__('Webhook "%s" deleted.', 'wp-intelligence'), $name),
    ]);
  }

  public static function handle_test_webhook(\WP_REST_Request $request): \WP_REST_Response {
    $config = $request->get_json_params();
    $name   = sanitize_key($config['name'] ?? 'test');

    $source   = new WPI_Webhook_Source($name, WPI_Webhook_Source::sanitize_config($config));
    $response = $source->fetch([]);

    if (is_wp_error($response)) {
      return new \WP_REST_Response([
        'success' => false,
        'error'   => $response->get_error_message(),
      ], 422);
    }

    $sample_paths = self::extract_field_paths($response);

    return new \WP_REST_Response([
      'success' => true,
      'data'    => $response,
      'fields'  => $sample_paths,
    ]);
  }

  public static function handle_resolve(\WP_REST_Request $request): \WP_REST_Response {
    $content = $request->get_param('content');
    $post_id = $request->get_param('post_id');

    $context = ['post_id' => $post_id];
    $resolved = WPI_Merge_Tag_Engine::resolve($content, $context, false);
    $tags = WPI_Merge_Tag_Engine::find_tags($content);

    return new \WP_REST_Response([
      'resolved' => $resolved,
      'tags'     => $tags,
    ]);
  }

  /* ──────────────────────────────────────────────
   *  Helpers
   * ────────────────────────────────────────────── */

  /**
   * Recursively extract dot-separated field paths from a JSON response.
   */
  private static function extract_field_paths(array $data, string $prefix = '', int $depth = 0): array {
    $paths = [];
    if ($depth > 5) {
      return $paths;
    }

    foreach ($data as $key => $value) {
      $path = $prefix !== '' ? $prefix . '.' . $key : (string) $key;

      if (is_array($value) && ! array_is_list($value)) {
        $paths = array_merge($paths, self::extract_field_paths($value, $path, $depth + 1));
      } else {
        $label = ucwords(str_replace(['.', '_', '-'], ' ', $path));
        $paths[] = ['path' => $path, 'label' => $label];
      }
    }

    return array_slice($paths, 0, 50);
  }

  private static function webhook_args(): array {
    return [
      'name' => [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_key',
      ],
      'label' => [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
      ],
      'url' => [
        'type'              => 'string',
        'sanitize_callback' => 'esc_url_raw',
      ],
      'method' => [
        'type'    => 'string',
        'default' => 'GET',
      ],
    ];
  }
}
