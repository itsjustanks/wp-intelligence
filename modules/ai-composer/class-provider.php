<?php
if (! defined('ABSPATH')) {
  exit;
}

class AI_Composer_Provider {

  private const OPENAI_ENDPOINT = 'https://api.openai.com/v1/chat/completions';
  private const DEFAULT_MODEL   = 'gpt-4.1';
  private const TIMEOUT         = 120;

  /**
   * Generate a completion from the AI provider.
   *
   * Tries the native WP AI Client (7.0+) first, then falls back to direct OpenAI.
   *
   * @return string|WP_Error Raw JSON string from the AI.
   */
  public function generate(string $system_prompt, string $user_prompt, array $json_schema): string|WP_Error {
    if (function_exists('wp_ai_client_prompt')) {
      return $this->generate_via_wp_ai_client($system_prompt, $user_prompt, $json_schema);
    }

    return $this->generate_via_openai($system_prompt, $user_prompt, $json_schema);
  }

  public function is_available(): bool {
    if (function_exists('wp_ai_client_prompt')) {
      return true;
    }

    return $this->get_api_key() !== '';
  }

  public function get_provider_info(): array {
    if (function_exists('wp_ai_client_prompt')) {
      return [
        'provider' => 'wp-ai-client',
        'native'   => true,
      ];
    }

    $key = $this->get_api_key();
    return [
      'provider'  => $key !== '' ? 'openai-direct' : 'none',
      'native'    => false,
      'model'     => $this->get_model(),
    ];
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

  private function generate_via_openai(string $system_prompt, string $user_prompt, array $json_schema): string|WP_Error {
    $api_key = $this->get_api_key();
    if ($api_key === '') {
      return new WP_Error(
        'ai_composer_no_api_key',
        __('No OpenAI API key configured. Go to Settings → WP Intelligence to add one.', 'wp-intelligence')
      );
    }

    $messages = [
      ['role' => 'system',  'content' => $system_prompt],
      ['role' => 'user',    'content' => $user_prompt],
    ];

    $body = [
      'model'       => $this->get_model(),
      'messages'    => $messages,
      'temperature' => apply_filters('ai_composer_temperature', 0.2),
      'response_format' => [
        'type' => 'json_schema',
        'json_schema' => [
          'name'   => 'page_composition',
          'strict' => true,
          'schema' => $json_schema,
        ],
      ],
    ];

    $response = wp_remote_post(self::OPENAI_ENDPOINT, [
      'headers' => [
        'Content-Type'  => 'application/json',
        'Authorization' => 'Bearer ' . $api_key,
      ],
      'body'    => wp_json_encode($body),
      'timeout' => self::TIMEOUT,
    ]);

    if (is_wp_error($response)) {
      return new WP_Error('ai_composer_request_failed', $response->get_error_message());
    }

    $status = wp_remote_retrieve_response_code($response);
    $raw    = wp_remote_retrieve_body($response);
    $data   = json_decode($raw, true);

    if ($status !== 200) {
      $msg = $data['error']['message'] ?? __('Unknown API error.', 'wp-intelligence');
      return new WP_Error('ai_composer_api_error', $msg, ['status' => $status]);
    }

    $content = $data['choices'][0]['message']['content'] ?? null;
    if (! is_string($content)) {
      return new WP_Error('ai_composer_empty_response', __('The AI returned an empty response.', 'wp-intelligence'));
    }

    return $content;
  }

  private function get_api_key(): string {
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

  private function get_model(): string {
    $settings = get_option('ai_composer_settings', []);
    $model    = $settings['model'] ?? self::DEFAULT_MODEL;

    return apply_filters('ai_composer_model', $model);
  }
}
