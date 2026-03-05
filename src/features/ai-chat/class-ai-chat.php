<?php
if (! defined('ABSPATH')) {
  exit;
}

class WPI_AI_Chat {

  private AI_Composer_Provider $provider;
  private WPI_Chat_Storage $storage;
  private WPI_Chat_Handler $handler;

  private static ?self $instance = null;

  private const PAGE_SLUG = 'wpi-ask-ai';

  public function __construct(AI_Composer_Provider $provider) {
    $this->provider = $provider;
    $this->storage  = new WPI_Chat_Storage();
    $this->handler  = new WPI_Chat_Handler($provider, $this->storage);
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

  public static function activate(): void {
    WPI_Chat_Storage::ensure_table();
  }

  private function register_hooks(): void {
    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
    add_action('admin_bar_menu', [$this, 'add_admin_bar_button'], 5);
    add_action('rest_api_init', [$this, 'register_rest_routes']);
    add_action('admin_menu', [$this, 'add_admin_page']);
  }

  /**
   * Enqueue chat assets on all admin pages.
   */
  public function enqueue_admin_assets(): void {
    if (! current_user_can('edit_posts') || ! $this->provider->is_available()) {
      return;
    }

    $this->enqueue_chat_assets();
  }

  /**
   * Enqueue chat assets on the frontend when the admin bar is visible.
   */
  public function enqueue_frontend_assets(): void {
    if (! is_admin_bar_showing()) {
      return;
    }
    if (! current_user_can('edit_posts') || ! $this->provider->is_available()) {
      return;
    }

    $this->enqueue_chat_assets();
  }

  private function enqueue_chat_assets(): void {
    $js_path = __DIR__ . '/editor/ai-chat.js';
    $js_url  = defined('WPI_URL') ? WPI_URL . 'src/features/ai-chat/editor/ai-chat.js' : '';

    if ($js_url === '' || ! file_exists($js_path)) {
      return;
    }

    $deps = ['wp-api-fetch', 'wp-i18n'];
    if (function_exists('get_current_screen')) {
      $screen = get_current_screen();
      if ($screen && $screen->is_block_editor()) {
        $deps[] = 'wp-data';
      }
    }

    wp_enqueue_script(
      'wpi-ai-chat',
      $js_url,
      $deps,
      (string) filemtime($js_path),
      true
    );

    $css_path = __DIR__ . '/editor/ai-chat.css';
    $css_url  = defined('WPI_URL') ? WPI_URL . 'src/features/ai-chat/editor/ai-chat.css' : '';
    if ($css_url !== '' && file_exists($css_path)) {
      wp_enqueue_style(
        'wpi-ai-chat',
        $css_url,
        [],
        (string) filemtime($css_path)
      );
    }

    $is_fullscreen = is_admin()
      && isset($_GET['page'])
      && sanitize_key((string) $_GET['page']) === self::PAGE_SLUG;

    wp_localize_script('wpi-ai-chat', 'wpiAiChatConfig', [
      'restNamespace' => 'ai-composer/v1',
      'nonce'         => wp_create_nonce('wp_rest'),
      'siteUrl'       => home_url(),
      'siteName'      => get_bloginfo('name'),
      'hasProvider'   => $this->provider->is_available(),
      'isFullscreen'  => $is_fullscreen,
      'pageUrl'       => admin_url('admin.php?page=' . self::PAGE_SLUG),
    ]);
  }

  /**
   * Add "Ask AI" to the RIGHT side of the admin bar.
   */
  public function add_admin_bar_button(\WP_Admin_Bar $admin_bar): void {
    if (! current_user_can('edit_posts') || ! $this->provider->is_available()) {
      return;
    }

    $admin_bar->add_node([
      'id'     => 'wpi-ai-chat-toggle',
      'parent' => 'top-secondary',
      'title'  => '<span class="ab-icon dashicons dashicons-format-chat"></span><span class="ab-label">' . esc_html__('Ask AI', 'wp-intelligence') . '</span>',
      'href'   => admin_url('admin.php?page=' . self::PAGE_SLUG),
      'meta'   => [
        'class' => 'wpi-ai-chat-trigger',
        'title' => __('Open AI Chat', 'wp-intelligence'),
      ],
    ]);
  }

  /**
   * Register the standalone Ask AI admin page.
   */
  public function add_admin_page(): void {
    add_submenu_page(
      'wp-intelligence',
      __('Ask AI', 'wp-intelligence'),
      __('Ask AI', 'wp-intelligence'),
      apply_filters('ai_composer_capability', 'edit_posts'),
      self::PAGE_SLUG,
      [$this, 'render_admin_page']
    );
  }

  /**
   * Render the standalone fullscreen Ask AI page.
   */
  public function render_admin_page(): void {
    echo '<div id="wpi-ask-ai-page"></div>';
  }

  public function register_rest_routes(): void {
    register_rest_route('ai-composer/v1', '/chat', [
      'methods'             => 'POST',
      'callback'            => [$this, 'handle_chat'],
      'permission_callback' => function () {
        return current_user_can(apply_filters('ai_composer_capability', 'edit_posts'));
      },
      'args' => [
        'message'         => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
        'conversation_id' => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
        'context'         => ['type' => 'string', 'default' => '{}', 'sanitize_callback' => 'sanitize_text_field'],
      ],
    ]);

    register_rest_route('ai-composer/v1', '/chat/history', [
      'methods'             => 'GET',
      'callback'            => [$this, 'handle_history'],
      'permission_callback' => function () {
        return current_user_can(apply_filters('ai_composer_capability', 'edit_posts'));
      },
    ]);

    register_rest_route('ai-composer/v1', '/chat/(?P<conversation_id>[a-f0-9-]{36})', [
      [
        'methods'             => 'GET',
        'callback'            => [$this, 'handle_get_conversation'],
        'permission_callback' => function () {
          return current_user_can(apply_filters('ai_composer_capability', 'edit_posts'));
        },
      ],
      [
        'methods'             => 'DELETE',
        'callback'            => [$this, 'handle_delete_conversation'],
        'permission_callback' => function () {
          return current_user_can(apply_filters('ai_composer_capability', 'edit_posts'));
        },
      ],
    ]);
  }

  public function handle_chat(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
    $context = json_decode($request->get_param('context') ?: '{}', true);
    if (! is_array($context)) {
      $context = [];
    }

    $context = apply_filters('ai_composer_chat_context', $context);

    $result = $this->handler->handle_message(
      $request->get_param('message'),
      $request->get_param('conversation_id') ?: '',
      get_current_user_id(),
      $context
    );

    if (is_wp_error($result)) {
      return $result;
    }

    return new \WP_REST_Response($result, 200);
  }

  public function handle_history(): \WP_REST_Response {
    $conversations = $this->storage->get_conversations(get_current_user_id());

    $list = array_map(function ($c) {
      return [
        'conversation_id' => $c->conversation_id,
        'title'           => mb_substr(wp_strip_all_tags((string) ($c->title ?? '')), 0, 60),
        'last_message_at' => $c->last_message_at,
        'message_count'   => (int) $c->message_count,
      ];
    }, $conversations);

    return new \WP_REST_Response($list, 200);
  }

  public function handle_get_conversation(\WP_REST_Request $request): \WP_REST_Response {
    $messages = $this->storage->get_messages(
      $request->get_param('conversation_id'),
      get_current_user_id(),
      100
    );

    $list = array_map(function ($m) {
      return [
        'id'         => (int) $m->id,
        'role'       => $m->role,
        'content'    => $m->content,
        'created_at' => $m->created_at,
      ];
    }, $messages);

    return new \WP_REST_Response(['messages' => $list], 200);
  }

  public function handle_delete_conversation(\WP_REST_Request $request): \WP_REST_Response {
    $deleted = $this->storage->delete_conversation(
      $request->get_param('conversation_id'),
      get_current_user_id()
    );

    return new \WP_REST_Response(['deleted' => $deleted], 200);
  }
}
