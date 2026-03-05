<?php
if (! defined('ABSPATH')) {
  exit;
}

class AI_Composer_Provider {

  private const RESPONSES_ENDPOINT  = 'https://api.openai.com/v1/responses';
  private const FILES_ENDPOINT      = 'https://api.openai.com/v1/files';
  private const VECTOR_STORES_ENDPOINT = 'https://api.openai.com/v1/vector_stores';
  private const DEFAULT_MODEL       = 'gpt-5.2';
  private const TIMEOUT             = 120;

  /**
   * Generate a completion from the AI provider.
   *
   * Tries the native WP AI Client (7.0+) first, then falls back to OpenAI Responses API.
   *
   * @param array $options Optional. Additional options for the request.
   *                       'tools'            => array of tool configs (file_search, code_interpreter).
   *                       'vector_store_ids' => array of vector store IDs for file_search.
   *                       'include'          => array of includable fields in response.
   * @return string|WP_Error Raw JSON string from the AI.
   */
  public function generate(string $system_prompt, string $user_prompt, array $json_schema, array $options = []): string|WP_Error {
    if ($this->has_native_client()) {
      return $this->generate_via_wp_ai_client($system_prompt, $user_prompt, $json_schema);
    }

    return $this->generate_via_responses_api($system_prompt, $user_prompt, $json_schema, $options);
  }

  public function is_available(): bool {
    if ($this->has_native_client()) {
      return true;
    }

    return $this->get_api_key() !== '';
  }

  public function get_provider_info(): array {
    if ($this->has_native_client()) {
      return [
        'provider' => 'wp-ai-client',
        'native'   => true,
      ];
    }

    $key = $this->get_api_key();
    return [
      'provider'  => $key !== '' ? 'openai-responses' : 'none',
      'native'    => false,
      'model'     => $this->get_model(),
    ];
  }

  /**
   * @return array{
   *   can_compose:bool,
   *   runtime:string,
   *   native:bool,
   *   configured:bool|null,
   *   requires_configuration:bool,
   *   message:string,
   *   model:string
   * }
   */
  public function get_readiness_status(): array {
    if ($this->has_native_client()) {
      return [
        'can_compose'             => true,
        'runtime'                 => 'wp-ai-client',
        'native'                  => true,
        'configured'              => null,
        'requires_configuration'  => false,
        'message'                 => __('WordPress native AI runtime detected. Credentials are managed in Settings -> AI Credentials.', 'wp-intelligence'),
        'model'                   => '',
      ];
    }

    $has_api_key = ($this->get_api_key() !== '');

    return [
      'can_compose'            => $has_api_key,
      'runtime'                => $has_api_key ? 'openai-responses' : 'none',
      'native'                 => false,
      'configured'             => $has_api_key,
      'requires_configuration' => ! $has_api_key,
      'message'                => $has_api_key
        ? __('Using OpenAI Responses API from WP Intelligence settings.', 'wp-intelligence')
        : __('No AI provider is configured. Add an OpenAI API key in WP Intelligence settings or configure the WordPress native AI runtime.', 'wp-intelligence'),
      'model'                  => $has_api_key ? (string) $this->get_model() : '',
    ];
  }

  /* ──────────────────────────────────────────────
   *  File and Vector Store Management
   * ────────────────────────────────────────────── */

  /**
   * Upload a file to OpenAI for use with file_search or code_interpreter.
   *
   * @param string $file_path  Absolute path to the file.
   * @param string $purpose    'assistants' for file_search/code_interpreter.
   * @return array|WP_Error    File object with 'id', 'filename', 'bytes', etc.
   */
  public function upload_file(string $file_path, string $purpose = 'assistants'): array|WP_Error {
    $api_key = $this->get_api_key();
    if ($api_key === '') {
      return new WP_Error('ai_composer_no_api_key', __('No OpenAI API key configured.', 'wp-intelligence'), ['status' => 400]);
    }

    if (! file_exists($file_path) || ! is_readable($file_path)) {
      return new WP_Error('ai_composer_file_not_found', __('File not found or not readable.', 'wp-intelligence'), ['status' => 400]);
    }

    $boundary = wp_generate_password(24, false);
    $filename = basename($file_path);
    $file_contents = file_get_contents($file_path);

    $body = '';
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"purpose\"\r\n\r\n";
    $body .= "{$purpose}\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
    $body .= "Content-Type: application/octet-stream\r\n\r\n";
    $body .= $file_contents . "\r\n";
    $body .= "--{$boundary}--\r\n";

    $response = wp_remote_post(self::FILES_ENDPOINT, [
      'headers' => [
        'Authorization' => 'Bearer ' . $api_key,
        'Content-Type'  => "multipart/form-data; boundary={$boundary}",
      ],
      'body'    => $body,
      'timeout' => 60,
    ]);

    return $this->parse_api_response($response);
  }

  /**
   * Create a vector store for file_search.
   *
   * @param string   $name     Display name for the vector store.
   * @param string[] $file_ids OpenAI file IDs to include.
   * @return array|WP_Error    Vector store object with 'id', 'name', etc.
   */
  public function create_vector_store(string $name, array $file_ids = []): array|WP_Error {
    $api_key = $this->get_api_key();
    if ($api_key === '') {
      return new WP_Error('ai_composer_no_api_key', __('No OpenAI API key configured.', 'wp-intelligence'), ['status' => 400]);
    }

    $body = ['name' => $name];
    if (! empty($file_ids)) {
      $body['file_ids'] = $file_ids;
    }

    $response = wp_remote_post(self::VECTOR_STORES_ENDPOINT, [
      'headers' => [
        'Content-Type'  => 'application/json',
        'Authorization' => 'Bearer ' . $api_key,
      ],
      'body'    => wp_json_encode($body),
      'timeout' => 60,
    ]);

    return $this->parse_api_response($response);
  }

  /**
   * Add a file to an existing vector store.
   */
  public function add_file_to_vector_store(string $vector_store_id, string $file_id): array|WP_Error {
    $api_key = $this->get_api_key();
    if ($api_key === '') {
      return new WP_Error('ai_composer_no_api_key', __('No OpenAI API key configured.', 'wp-intelligence'), ['status' => 400]);
    }

    $url = self::VECTOR_STORES_ENDPOINT . '/' . $vector_store_id . '/files';

    $response = wp_remote_post($url, [
      'headers' => [
        'Content-Type'  => 'application/json',
        'Authorization' => 'Bearer ' . $api_key,
      ],
      'body'    => wp_json_encode(['file_id' => $file_id]),
      'timeout' => 30,
    ]);

    return $this->parse_api_response($response);
  }

  /**
   * Delete a file from OpenAI.
   */
  public function delete_file(string $file_id): array|WP_Error {
    $api_key = $this->get_api_key();
    if ($api_key === '') {
      return new WP_Error('ai_composer_no_api_key', __('No OpenAI API key configured.', 'wp-intelligence'), ['status' => 400]);
    }

    $response = wp_remote_request(self::FILES_ENDPOINT . '/' . $file_id, [
      'method'  => 'DELETE',
      'headers' => [
        'Authorization' => 'Bearer ' . $api_key,
      ],
      'timeout' => 30,
    ]);

    return $this->parse_api_response($response);
  }

  /**
   * Delete a vector store.
   */
  public function delete_vector_store(string $vector_store_id): array|WP_Error {
    $api_key = $this->get_api_key();
    if ($api_key === '') {
      return new WP_Error('ai_composer_no_api_key', __('No OpenAI API key configured.', 'wp-intelligence'), ['status' => 400]);
    }

    $response = wp_remote_request(self::VECTOR_STORES_ENDPOINT . '/' . $vector_store_id, [
      'method'  => 'DELETE',
      'headers' => [
        'Authorization' => 'Bearer ' . $api_key,
      ],
      'timeout' => 30,
    ]);

    return $this->parse_api_response($response);
  }

  /**
   * List files in a vector store.
   */
  public function list_vector_store_files(string $vector_store_id): array|WP_Error {
    $api_key = $this->get_api_key();
    if ($api_key === '') {
      return new WP_Error('ai_composer_no_api_key', __('No OpenAI API key configured.', 'wp-intelligence'), ['status' => 400]);
    }

    $url = self::VECTOR_STORES_ENDPOINT . '/' . $vector_store_id . '/files';

    $response = wp_remote_get($url, [
      'headers' => [
        'Authorization' => 'Bearer ' . $api_key,
      ],
      'timeout' => 30,
    ]);

    return $this->parse_api_response($response);
  }

  /* ──────────────────────────────────────────────
   *  Generation internals
   * ────────────────────────────────────────────── */

  private function has_native_client(): bool {
    return function_exists('wp_ai_client_prompt');
  }

  private function generate_via_wp_ai_client(string $system_prompt, string $user_prompt, array $json_schema): string|WP_Error {
    $full_prompt = $system_prompt . "\n\n---\n\nUser request:\n" . $user_prompt;

    $result = wp_ai_client_prompt($full_prompt)
      ->using_model_preference(
        ...apply_filters('ai_composer_model_preferences', ['gpt-5.2', 'claude-sonnet-4-5', 'gpt-4.1', 'gemini-3-pro-preview'])
      )
      ->using_temperature(apply_filters('ai_composer_temperature', 0.2))
      ->as_json_response($json_schema)
      ->generate_text();

    if (is_wp_error($result)) {
      return $result;
    }

    return (string) $result;
  }

  /**
   * Generate via the OpenAI Responses API (POST /v1/responses).
   *
   * Supports built-in tools: file_search (with vector stores) and code_interpreter.
   */
  private function generate_via_responses_api(string $system_prompt, string $user_prompt, array $json_schema, array $options = []): string|WP_Error {
    $api_key = $this->get_api_key();
    if ($api_key === '') {
      return new WP_Error(
        'ai_composer_no_api_key',
        __('No OpenAI API key configured. Go to Settings → WP Intelligence to add one.', 'wp-intelligence'),
        ['status' => 400]
      );
    }

    $body = [
      'model'        => $this->get_model(),
      'instructions' => $system_prompt,
      'input'        => $user_prompt,
      'temperature'  => apply_filters('ai_composer_temperature', 0.2),
      'store'        => false,
      'text'         => [
        'format' => [
          'type'   => 'json_schema',
          'name'   => 'page_composition',
          'strict' => true,
          'schema' => $json_schema,
        ],
      ],
    ];

    $tools   = $options['tools'] ?? [];
    $include = $options['include'] ?? [];

    $vector_store_ids = $options['vector_store_ids'] ?? $this->get_vector_store_ids();
    if (! empty($vector_store_ids)) {
      $tools[] = [
        'type'             => 'file_search',
        'vector_store_ids' => array_values($vector_store_ids),
      ];
      $include[] = 'file_search_call.results';
    }

    if (! empty($options['code_interpreter'])) {
      $tools[]   = ['type' => 'code_interpreter'];
      $include[] = 'code_interpreter_call.outputs';
    }

    $tools = apply_filters('ai_composer_response_tools', $tools, $options);

    if (! empty($tools)) {
      $body['tools'] = $tools;
    }
    if (! empty($include)) {
      $body['include'] = array_values(array_unique($include));
    }

    $response = wp_remote_post(self::RESPONSES_ENDPOINT, [
      'headers' => [
        'Content-Type'  => 'application/json',
        'Authorization' => 'Bearer ' . $api_key,
      ],
      'body'    => wp_json_encode($body),
      'timeout' => self::TIMEOUT,
    ]);

    if (is_wp_error($response)) {
      return new WP_Error('ai_composer_request_failed', $response->get_error_message(), ['status' => 502]);
    }

    $status = wp_remote_retrieve_response_code($response);
    $raw    = wp_remote_retrieve_body($response);
    $data   = json_decode($raw, true);

    if ($status !== 200) {
      $msg = $data['error']['message'] ?? __('Unknown API error.', 'wp-intelligence');
      return new WP_Error('ai_composer_api_error', $msg, ['status' => $status]);
    }

    return $this->extract_response_text($data);
  }

  /**
   * Extract the output text from a Responses API result.
   *
   * The response has `output` containing items. Message items have `content`
   * arrays with `output_text` entries.
   */
  private function extract_response_text(array $data): string|WP_Error {
    $output = $data['output'] ?? [];

    foreach ($output as $item) {
      if (($item['type'] ?? '') !== 'message') {
        continue;
      }
      foreach ($item['content'] ?? [] as $content) {
        if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
          return $content['text'];
        }
        if (($content['type'] ?? '') === 'refusal' && isset($content['refusal'])) {
          return new WP_Error(
            'ai_composer_refusal',
            sprintf(__('The AI declined the request: %s', 'wp-intelligence'), $content['refusal']),
            ['status' => 422]
          );
        }
      }
    }

    return new WP_Error(
      'ai_composer_empty_response',
      __('The AI returned an empty response.', 'wp-intelligence'),
      ['status' => 502]
    );
  }

  /**
   * Parse a standard OpenAI API JSON response.
   */
  private function parse_api_response($response): array|WP_Error {
    if (is_wp_error($response)) {
      return new WP_Error('ai_composer_request_failed', $response->get_error_message(), ['status' => 502]);
    }

    $status = wp_remote_retrieve_response_code($response);
    $raw    = wp_remote_retrieve_body($response);
    $data   = json_decode($raw, true);

    if ($status < 200 || $status >= 300) {
      $msg = $data['error']['message'] ?? __('Unknown API error.', 'wp-intelligence');
      return new WP_Error('ai_composer_api_error', $msg, ['status' => $status]);
    }

    return is_array($data) ? $data : [];
  }

  /* ──────────────────────────────────────────────
   *  Configuration helpers
   * ────────────────────────────────────────────── */

  public function get_api_key(): string {
    if (defined('AI_COMPOSER_OPENAI_API_KEY') && AI_COMPOSER_OPENAI_API_KEY !== '') {
      return AI_COMPOSER_OPENAI_API_KEY;
    }

    $env = getenv('AI_COMPOSER_OPENAI_API_KEY');
    if (is_string($env) && $env !== '') {
      return $env;
    }

    $settings = get_option('ai_composer_settings', []);
    if (! empty($settings['api_key'])) {
      return (string) $settings['api_key'];
    }

    return apply_filters('ai_composer_openai_api_key', '');
  }

  public function get_model(): string {
    $settings = get_option('ai_composer_settings', []);
    $model    = $settings['model'] ?? self::DEFAULT_MODEL;

    return apply_filters('ai_composer_model', $model);
  }

  /**
   * Get configured vector store IDs for file_search.
   *
   * @return string[] Array of vector store IDs.
   */
  public function get_vector_store_ids(): array {
    $settings = get_option('ai_composer_settings', []);
    $id = $settings['vector_store_id'] ?? '';

    $ids = apply_filters('ai_composer_vector_store_ids', $id !== '' ? [$id] : []);

    return is_array($ids) ? array_filter($ids) : [];
  }
}
