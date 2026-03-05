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
   * Process a user message, call the AI, save both messages, return the response.
   *
   * @param string      $message         The user's new message.
   * @param string      $conversation_id Existing conversation UUID or empty for new.
   * @param int         $user_id         WordPress user ID.
   * @param array       $context         Current page context (url, post_id, post_type, post_title).
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

    $msg_id = $this->storage->save_message($conversation_id, $user_id, 'assistant', $reply);

    return [
      'message'         => $reply,
      'conversation_id' => $conversation_id,
      'message_id'      => $msg_id,
    ];
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
        $after_label = static fn( string $s ): string =>
          ( $p = strpos( $s, "\n" ) ) !== false ? substr( $s, $p + 1 ) : $s;
        if ( $brand_voice === '' || $after_label( $site_context ) !== $after_label( $brand_voice ) ) {
          $prompt .= "\n\n" . $site_context;
        }
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
