<?php
if (! defined('ABSPATH')) {
  exit;
}

class AI_Composer_REST_Controller {

  private const NAMESPACE = 'ai-composer/v1';

  public static function register_routes(): void {
    add_action('rest_api_init', [self::class, 'register']);
  }

  public static function register(): void {
    register_rest_route(self::NAMESPACE, '/compose', [
      'methods'             => 'POST',
      'callback'            => [self::class, 'handle_compose'],
      'permission_callback' => [self::class, 'can_compose'],
      'args'                => [
        'prompt' => [
          'required'          => true,
          'type'              => 'string',
          'sanitize_callback' => 'sanitize_textarea_field',
        ],
        'template' => [
          'required' => false,
          'type'     => 'string',
          'default'  => '',
        ],
        'compose_mode' => [
          'required' => false,
          'type'     => 'string',
          'default'  => 'new_content',
          'enum'     => ['new_content', 'selected_block', 'page'],
        ],
        'insert_mode' => [
          'required' => false,
          'type'     => 'string',
          'default'  => 'append',
          'enum'     => ['append', 'replace_all', 'insert_after'],
        ],
        'selected_block_context' => [
          'required'          => false,
          'default'           => null,
          'validate_callback' => function ($value) {
            return $value === null || is_array($value) || is_object($value);
          },
        ],
        'page_context' => [
          'required'          => false,
          'default'           => null,
          'validate_callback' => function ($value) {
            return $value === null || is_array($value) || is_object($value);
          },
        ],
      ],
    ]);

    register_rest_route(self::NAMESPACE, '/catalog', [
      'methods'             => 'GET',
      'callback'            => [self::class, 'handle_catalog'],
      'permission_callback' => [self::class, 'can_compose'],
    ]);

    register_rest_route(self::NAMESPACE, '/status', [
      'methods'             => 'GET',
      'callback'            => [self::class, 'handle_status'],
      'permission_callback' => [self::class, 'can_compose'],
    ]);
  }

  public static function handle_compose(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $prompt  = $request->get_param('prompt');
    $options = [
      'template'    => $request->get_param('template'),
      'compose_mode'=> $request->get_param('compose_mode'),
      'insert_mode' => $request->get_param('insert_mode'),
      'selected_block_context' => self::sanitize_context($request->get_param('selected_block_context')),
      'page_context'           => self::sanitize_context($request->get_param('page_context')),
    ];

    $composer = AI_Composer::get_instance();
    $result   = $composer->compose($prompt, $options);

    if (is_wp_error($result)) {
      return $result;
    }

    return new WP_REST_Response([
      'success'  => true,
      'blocks'   => $result['blocks'],
      'blockTree'=> $result['blockTree'],
      'manifest' => $result['manifest'],
      'summary'  => $result['summary'],
    ], 200);
  }

  public static function handle_catalog(WP_REST_Request $request): WP_REST_Response {
    $composer = AI_Composer::get_instance();

    return new WP_REST_Response([
      'blocks'   => array_values($composer->blocks()->get_composable()),
      'patterns' => array_values($composer->patterns()->discover()),
    ], 200);
  }

  public static function handle_status(WP_REST_Request $request): WP_REST_Response {
    $composer = AI_Composer::get_instance();
    $provider = $composer->provider();

    return new WP_REST_Response([
      'available'     => $provider->is_available(),
      'provider'      => $provider->get_provider_info(),
      'abilities_api' => function_exists('wp_register_ability'),
      'version'       => AI_COMPOSER_VERSION,
    ], 200);
  }

  public static function can_compose(WP_REST_Request $request): bool {
    return current_user_can(
      apply_filters('ai_composer_capability', 'edit_posts')
    );
  }

  /**
   * Sanitize arbitrary request context payloads before they are embedded
   * in prompt text. This preserves shape while trimming extreme depth/size.
   */
  private static function sanitize_context(mixed $value, int $depth = 0): mixed {
    if ($depth > 8) {
      return null;
    }

    if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) {
      return $value;
    }

    if (is_string($value)) {
      if (function_exists('mb_substr')) {
        return mb_substr($value, 0, 4000);
      }
      return substr($value, 0, 4000);
    }

    if (is_object($value)) {
      $value = get_object_vars($value);
    }

    if (is_array($value)) {
      $out = [];
      $count = 0;

      foreach ($value as $key => $item) {
        if ($count > 500) {
          break;
        }

        $safe_key = is_string($key) ? substr($key, 0, 120) : $key;
        $out[$safe_key] = self::sanitize_context($item, $depth + 1);
        $count++;
      }

      return $out;
    }

    return null;
  }
}
