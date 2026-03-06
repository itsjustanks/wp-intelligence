<?php
if (! defined('ABSPATH')) {
  exit;
}

class WPI_Chat_Handler {

  private AI_Composer_Provider $provider;
  private WPI_Chat_Storage $storage;

  public function __construct(AI_Composer_Provider $provider, WPI_Chat_Storage $storage) {
    $this->provider = $provider;
    $this->storage  = $storage;
  }

  /**
   * Process a user message with optional tool execution loop.
   *
   * @param string $message         The user's new message.
   * @param string $conversation_id Existing conversation UUID or empty for new.
   * @param int    $user_id         WordPress user ID.
   * @param array  $context         Current page context from the client.
   * @return array|WP_Error
   */
  public function handle_message(string $message, string $conversation_id, int $user_id, array $context = []): array|WP_Error {
    if (! $this->provider->is_available()) {
      return new WP_Error(
        'ai_composer_chat_no_provider',
        __('No AI provider is configured. Add an API key under Intelligence > AI.', 'wp-intelligence'),
        ['status' => 503]
      );
    }

    $message = trim($message);
    if ($message === '') {
      return new WP_Error(
        'ai_composer_chat_empty',
        __('Please enter a message.', 'wp-intelligence'),
        ['status' => 400]
      );
    }

    if ($conversation_id === '') {
      $conversation_id = $this->storage->generate_conversation_id();
    }

    $this->storage->save_message($conversation_id, $user_id, 'user', $message, $context);

    $system_prompt = $this->build_system_prompt($context);
    $user_prompt   = $this->build_user_prompt($conversation_id, $user_id, $message);

    $reply = $this->run_with_tools($system_prompt, $user_prompt, $context);

    if (is_wp_error($reply)) {
      return $reply;
    }

    $msg_id = $this->storage->save_message($conversation_id, $user_id, 'assistant', $reply);

    return [
      'message'         => $reply,
      'conversation_id' => $conversation_id,
      'message_id'      => $msg_id,
    ];
  }

  /**
   * Run the AI with tools, executing an agentic loop if the model requests tool calls.
   */
  private function run_with_tools(string $system_prompt, string $user_prompt, array $context): string|WP_Error {
    $tools     = WPI_Chat_Tools::get_tool_definitions();
    $max_iters = WPI_Chat_Tools::max_iterations();
    $api_key   = $this->provider->get_api_key();

    if ($api_key === '') {
      return $this->run_simple($system_prompt, $user_prompt);
    }

    $body = [
      'model'        => $this->provider->get_model(),
      'instructions' => $system_prompt,
      'input'        => $user_prompt,
      'temperature'  => apply_filters('ai_composer_temperature', 0.4),
      'store'        => false,
      'tools'        => $tools,
    ];

    for ($iter = 0; $iter < $max_iters; $iter++) {
      $response = wp_remote_post('https://api.openai.com/v1/responses', [
        'headers' => [
          'Content-Type'  => 'application/json',
          'Authorization' => 'Bearer ' . $api_key,
        ],
        'body'    => wp_json_encode($body),
        'timeout' => 120,
      ]);

      if (is_wp_error($response)) {
        return new WP_Error('ai_composer_request_failed', $response->get_error_message(), ['status' => 502]);
      }

      $status = wp_remote_retrieve_response_code($response);
      $data   = json_decode(wp_remote_retrieve_body($response), true);

      if ($status !== 200) {
        $msg = $data['error']['message'] ?? __('Unknown API error.', 'wp-intelligence');
        return new WP_Error('ai_composer_api_error', $msg, ['status' => $status]);
      }

      $output        = $data['output'] ?? [];
      $function_calls = [];
      $text_reply     = '';

      foreach ($output as $item) {
        if (($item['type'] ?? '') === 'function_call') {
          $function_calls[] = $item;
        }
        if (($item['type'] ?? '') === 'message') {
          foreach ($item['content'] ?? [] as $content) {
            if (($content['type'] ?? '') === 'output_text') {
              $text_reply = $content['text'] ?? '';
            }
          }
        }
      }

      if (empty($function_calls)) {
        return $text_reply !== '' ? $text_reply : __('Sorry, I could not generate a response.', 'wp-intelligence');
      }

      $tool_results = [];
      foreach ($function_calls as $fc) {
        $tool_name = $fc['name'] ?? '';
        $call_id   = $fc['call_id'] ?? '';
        $args      = json_decode($fc['arguments'] ?? '{}', true);
        if (! is_array($args)) {
          $args = [];
        }

        $result = WPI_Chat_Tools::execute($tool_name, $args, $context);

        $tool_results[] = [
          'type'    => 'function_call_output',
          'call_id' => $call_id,
          'output'  => mb_substr($result, 0, 16000),
        ];
      }

      $body['input'] = array_merge($output, $tool_results);
    }

    return __('I ran out of steps while working on your request. Please try a more specific question.', 'wp-intelligence');
  }

  /**
   * Fallback: simple single-turn generation without tools (used when native WP AI client is active).
   */
  private function run_simple(string $system_prompt, string $user_prompt): string|WP_Error {
    $schema = [
      'type' => 'object',
      'properties' => [
        'response' => ['type' => 'string', 'description' => 'Your reply to the user.'],
      ],
      'required'             => ['response'],
      'additionalProperties' => false,
    ];

    $raw = $this->provider->generate($system_prompt, $user_prompt, $schema);
    if (is_wp_error($raw)) {
      return $raw;
    }

    $parsed = json_decode($raw, true);
    if (! is_array($parsed)) {
      $parsed = json_decode($this->extract_json($raw), true);
    }

    $reply = is_array($parsed) ? trim((string) ($parsed['response'] ?? $raw)) : trim($raw);
    if ($reply === '') {
      $reply = __('Sorry, I could not generate a response. Please try again.', 'wp-intelligence');
    }

    return $reply;
  }

  private function build_system_prompt(array $context): string {
    $site_name = get_bloginfo('name');
    $site_url  = home_url();

    $prompt = <<<PROMPT
You are a helpful AI assistant for the WordPress site "{$site_name}" ({$site_url}).
You help site administrators and editors with content, strategy, data, and WordPress tasks.
Respond in a conversational but professional tone.
Use the same language as the user's message.
If you don't know something, say so rather than guessing.
Keep responses concise unless the user asks for detail.

You have tools available to search and read site content, get site information, and access brand context. Use them proactively when the user's question relates to existing content or site data. Don't ask the user for information you can look up with your tools.

When the user is editing a page in the block editor, you can use the read_current_page tool to see what they're working on. Use it when they ask about "this page", "the current content", or want improvements to what they're editing.

You can use the get_available_blocks tool to see every registered block and pattern on this site, including their attributes and usage. Use it when the user asks about block capabilities, how to build a layout, or what blocks are available. This includes blocks from the theme, plugins, and core WordPress.
PROMPT;

    if (! empty($context['post_title'])) {
      $prompt .= "\n\nThe user is currently editing: \"{$context['post_title']}\"";
      if (! empty($context['post_type'])) {
        $prompt .= " (post type: {$context['post_type']})";
      }
      $prompt .= '.';
    } elseif (! empty($context['admin_page'])) {
      $prompt .= "\n\nThe user is currently on admin page: {$context['admin_page']}";
    }

    if (class_exists('AI_Composer_Context_Provider')) {
      $brand_voice = AI_Composer_Context_Provider::get_brand_voice();
      if ($brand_voice !== '') {
        $prompt .= "\n\n" . $brand_voice;
      }

      $site_context = AI_Composer_Context_Provider::get_context();
      if ($site_context !== '' && $site_context !== $brand_voice) {
        $prompt .= "\n\n" . $site_context;
      }
    } else {
      $settings = class_exists('AI_Composer_Settings') ? AI_Composer_Settings::get_syndication_settings() : [];
      $brand = trim((string) ($settings['brand_context'] ?? ''));
      if ($brand !== '') {
        $prompt .= "\n\nBrand context:\n" . $brand;
      }
    }

    return apply_filters('ai_composer_chat_system_prompt', $prompt, $context);
  }

  private function build_user_prompt(string $conversation_id, int $user_id, string $new_message): string {
    $history = $this->storage->get_messages($conversation_id, $user_id, 20);

    if (count($history) <= 1) {
      return $new_message;
    }

    $parts = [];
    foreach (array_slice($history, 0, -1) as $msg) {
      $parts[] = '[' . $msg->role . ']: ' . $msg->content;
    }
    $parts[] = '[user]: ' . $new_message;

    return implode("\n\n", $parts);
  }

  private function extract_json(string $text): string {
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
