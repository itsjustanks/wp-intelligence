<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * REST API controller for OpenAI file and vector store management.
 *
 * Provides endpoints for uploading context files, managing vector stores,
 * and listing stored files — used by the AI Provider settings UI.
 */
class WPI_REST_Files {

  private const NAMESPACE    = 'wp-intelligence/v1';
  private const MAX_FILE_SIZE = 20 * 1024 * 1024; // 20 MB

  private const ALLOWED_MIMES = [
    'txt'  => 'text/plain',
    'md'   => 'text/markdown',
    'csv'  => 'text/csv',
    'json' => 'application/json',
    'pdf'  => 'application/pdf',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'html' => 'text/html',
  ];

  public static function init(): void {
    add_action('rest_api_init', [self::class, 'register_routes']);
  }

  public static function register_routes(): void {
    register_rest_route(self::NAMESPACE, '/files', [
      [
        'methods'             => 'POST',
        'callback'            => [self::class, 'upload_file'],
        'permission_callback' => [self::class, 'check_permission'],
      ],
    ]);

    register_rest_route(self::NAMESPACE, '/files/(?P<file_id>[a-zA-Z0-9_-]+)', [
      [
        'methods'             => 'DELETE',
        'callback'            => [self::class, 'delete_file'],
        'permission_callback' => [self::class, 'check_permission'],
        'args'                => [
          'file_id' => [
            'required' => true,
            'type'     => 'string',
            'sanitize_callback' => 'sanitize_text_field',
          ],
        ],
      ],
    ]);

    register_rest_route(self::NAMESPACE, '/vector-stores', [
      [
        'methods'             => 'POST',
        'callback'            => [self::class, 'create_vector_store'],
        'permission_callback' => [self::class, 'check_permission'],
      ],
    ]);

    register_rest_route(self::NAMESPACE, '/vector-stores/(?P<store_id>[a-zA-Z0-9_-]+)', [
      [
        'methods'             => 'DELETE',
        'callback'            => [self::class, 'delete_vector_store'],
        'permission_callback' => [self::class, 'check_permission'],
        'args'                => [
          'store_id' => [
            'required' => true,
            'type'     => 'string',
            'sanitize_callback' => 'sanitize_text_field',
          ],
        ],
      ],
    ]);

    register_rest_route(self::NAMESPACE, '/vector-stores/(?P<store_id>[a-zA-Z0-9_-]+)/files', [
      [
        'methods'             => 'GET',
        'callback'            => [self::class, 'list_files'],
        'permission_callback' => [self::class, 'check_permission'],
        'args'                => [
          'store_id' => [
            'required' => true,
            'type'     => 'string',
            'sanitize_callback' => 'sanitize_text_field',
          ],
        ],
      ],
      [
        'methods'             => 'POST',
        'callback'            => [self::class, 'add_file_to_store'],
        'permission_callback' => [self::class, 'check_permission'],
        'args'                => [
          'store_id' => [
            'required' => true,
            'type'     => 'string',
            'sanitize_callback' => 'sanitize_text_field',
          ],
        ],
      ],
    ]);
  }

  public static function check_permission(): bool {
    return current_user_can('manage_options');
  }

  /**
   * Upload a file to OpenAI and optionally add it to the configured vector store.
   */
  public static function upload_file(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $files = $request->get_file_params();

    if (empty($files['file'])) {
      return new WP_Error('wpi_no_file', __('No file provided.', 'wp-intelligence'), ['status' => 400]);
    }

    $file = $files['file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
      return new WP_Error('wpi_upload_error', __('File upload failed.', 'wp-intelligence'), ['status' => 400]);
    }

    if ($file['size'] > self::MAX_FILE_SIZE) {
      return new WP_Error('wpi_file_too_large', __('File exceeds the 20 MB size limit.', 'wp-intelligence'), ['status' => 400]);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (! array_key_exists($ext, self::ALLOWED_MIMES)) {
      return new WP_Error(
        'wpi_invalid_type',
        sprintf(__('File type .%s is not supported. Allowed: %s', 'wp-intelligence'), $ext, implode(', ', array_keys(self::ALLOWED_MIMES))),
        ['status' => 400]
      );
    }

    $provider = new AI_Composer_Provider();
    $result = $provider->upload_file($file['tmp_name'], 'assistants');

    if (is_wp_error($result)) {
      return $result;
    }

    $add_to_store = $request->get_param('vector_store_id');
    if (! empty($add_to_store) && ! empty($result['id'])) {
      $store_result = $provider->add_file_to_vector_store($add_to_store, $result['id']);
      if (is_wp_error($store_result)) {
        $result['vector_store_warning'] = $store_result->get_error_message();
      } else {
        $result['vector_store_file'] = $store_result;
      }
    }

    return new WP_REST_Response($result, 200);
  }

  public static function delete_file(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $file_id  = $request->get_param('file_id');
    $provider = new AI_Composer_Provider();

    $result = $provider->delete_file($file_id);
    if (is_wp_error($result)) {
      return $result;
    }

    return new WP_REST_Response($result, 200);
  }

  public static function create_vector_store(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $name = sanitize_text_field($request->get_param('name') ?? 'WP Intelligence Context');
    $provider = new AI_Composer_Provider();

    $result = $provider->create_vector_store($name);
    if (is_wp_error($result)) {
      return $result;
    }

    if (! empty($result['id'])) {
      $settings = AI_Composer_Settings::get_settings();
      $settings['vector_store_id'] = $result['id'];
      update_option('ai_composer_settings', AI_Composer_Settings::sanitize($settings));
    }

    return new WP_REST_Response($result, 200);
  }

  public static function delete_vector_store(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $store_id = $request->get_param('store_id');
    $provider = new AI_Composer_Provider();

    $result = $provider->delete_vector_store($store_id);
    if (is_wp_error($result)) {
      return $result;
    }

    $settings = AI_Composer_Settings::get_settings();
    if (($settings['vector_store_id'] ?? '') === $store_id) {
      $settings['vector_store_id'] = '';
      update_option('ai_composer_settings', AI_Composer_Settings::sanitize($settings));
    }

    return new WP_REST_Response($result, 200);
  }

  public static function list_files(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $store_id = $request->get_param('store_id');
    $provider = new AI_Composer_Provider();

    $result = $provider->list_vector_store_files($store_id);
    if (is_wp_error($result)) {
      return $result;
    }

    return new WP_REST_Response($result, 200);
  }

  public static function add_file_to_store(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $store_id = $request->get_param('store_id');
    $file_id  = sanitize_text_field($request->get_param('file_id') ?? '');

    if ($file_id === '') {
      return new WP_Error('wpi_no_file_id', __('No file_id provided.', 'wp-intelligence'), ['status' => 400]);
    }

    $provider = new AI_Composer_Provider();
    $result = $provider->add_file_to_vector_store($store_id, $file_id);

    if (is_wp_error($result)) {
      return $result;
    }

    return new WP_REST_Response($result, 200);
  }
}
