<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Centralized context provider for all AI features.
 *
 * Primary path:  MCP server (when configured and reachable).
 * Fallback path: theme context directory files (existing behavior).
 * Caching:       WordPress transients with configurable TTL.
 */
class AI_Composer_Context_Provider {

  private const TRANSIENT_PREFIX = 'wpi_ctx_';
  private const FAILURE_TTL     = 300;

  private static ?AI_Composer_MCP_Client $client = null;
  private static ?bool $mcp_healthy = null;

  /* ──────────────────────────────────────────────
   *  Public API
   * ────────────────────────────────────────────── */

  /**
   * Load context by slug names.
   *
   * @param string[] $slugs Context document slugs (e.g. ['01-brand-foundation', '03-proof-points']).
   *                        Empty array = all context.
   */
  public static function get_context(array $slugs = []): string {
    $cache_key = 'context_' . md5(wp_json_encode($slugs));

    $cached = self::get_cached($cache_key);
    if ($cached !== null) {
      return $cached;
    }

    $args = empty($slugs) ? [] : ['names' => $slugs];
    $result = self::call_mcp('get_context', $args);

    if (! is_wp_error($result) && $result !== '') {
      self::set_cached($cache_key, $result);
      return $result;
    }

    $fallback = self::load_from_files('Site context');
    self::set_cached($cache_key, $fallback);
    return $fallback;
  }

  /**
   * Load task-specific context bundle from MCP.
   *
   * @param string   $task_type MCP task type (e.g. 'seo-article', 'social-post', 'website-creation').
   * @param string[] $extra     Additional context slugs to include.
   */
  public static function get_context_for_task(string $task_type, array $extra = []): string {
    $cache_key = 'task_' . $task_type . '_' . md5(wp_json_encode($extra));

    $cached = self::get_cached($cache_key);
    if ($cached !== null) {
      return $cached;
    }

    $args = ['task' => $task_type];
    if (! empty($extra)) {
      $args['additional_context'] = $extra;
    }

    $result = self::call_mcp('get_context_for_task', $args);

    if (! is_wp_error($result) && $result !== '') {
      self::set_cached($cache_key, $result);
      return $result;
    }

    $fallback = self::load_from_files('Site context');
    self::set_cached($cache_key, $fallback);
    return $fallback;
  }

  /**
   * Load brand voice and foundation context.
   */
  public static function get_brand_voice(): string {
    $cached = self::get_cached('brand_voice');
    if ($cached !== null) {
      return $cached;
    }

    $result = self::call_mcp('get_brand_voice');

    if (! is_wp_error($result) && $result !== '') {
      self::set_cached('brand_voice', $result);
      return $result;
    }

    $fallback = self::load_from_files('Brand context');
    self::set_cached('brand_voice', $fallback);
    return $fallback;
  }

  /**
   * Load proof points, optionally filtered by category.
   *
   * @param string $category One of: outcome, access, process, trust, research, all.
   */
  public static function get_proof_points(string $category = 'all'): string {
    $cache_key = 'proof_' . sanitize_key($category);

    $cached = self::get_cached($cache_key);
    if ($cached !== null) {
      return $cached;
    }

    $args = $category !== 'all' ? ['category' => $category] : [];
    $result = self::call_mcp('get_proof_points', $args);

    if (! is_wp_error($result) && $result !== '') {
      self::set_cached($cache_key, $result);
      return $result;
    }

    return '';
  }

  /**
   * Load verified safe claims (Tier A).
   */
  public static function get_safe_claims(): string {
    $cached = self::get_cached('safe_claims');
    if ($cached !== null) {
      return $cached;
    }

    $result = self::call_mcp('get_safe_claims');

    if (! is_wp_error($result) && $result !== '') {
      self::set_cached('safe_claims', $result);
      return $result;
    }

    return '';
  }

  /**
   * Flush all cached context.
   */
  public static function flush_cache(): void {
    global $wpdb;

    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        '_transient_' . self::TRANSIENT_PREFIX . '%'
      )
    );
    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        '_transient_timeout_' . self::TRANSIENT_PREFIX . '%'
      )
    );

    self::$mcp_healthy = null;
    self::$client      = null;
  }

  /**
   * Map a content intelligence mode to an MCP task type.
   *
   * @param string $mode Syndication mode (e.g. 'featured_in', 'original_post').
   * @return string MCP task type.
   */
  public static function syndication_mode_to_task(string $mode): string {
    $map = apply_filters('ai_composer_syndication_task_map', [
      'featured_in'     => 'seo-article',
      'original_post'   => 'seo-article',
      'summary'         => 'seo-article',
      'commentary'      => 'seo-article',
      'video_recap'     => 'seo-article',
      'video_takeaways' => 'seo-article',
    ]);

    return $map[$mode] ?? 'seo-article';
  }

  /* ──────────────────────────────────────────────
   *  MCP integration
   * ────────────────────────────────────────────── */

  private static function call_mcp(string $tool, array $args = []): string|WP_Error {
    if (! self::is_mcp_available()) {
      return new WP_Error('wpi_mcp_disabled', 'MCP context is not configured.');
    }

    if (self::$mcp_healthy === false) {
      return new WP_Error('wpi_mcp_unhealthy', 'MCP server recently failed; using fallback.');
    }

    $client = self::get_client();
    if ($client === null) {
      return new WP_Error('wpi_mcp_no_client', 'Could not create MCP client.');
    }

    $result = $client->call_tool($tool, $args);

    if (is_wp_error($result)) {
      self::$mcp_healthy = false;
      set_transient(self::TRANSIENT_PREFIX . 'failure', '1', self::FAILURE_TTL);
      error_log('[WP Intelligence] MCP call failed (' . $tool . '): ' . $result->get_error_message());
      do_action('ai_composer_context_fallback', $tool, $result);
      return $result;
    }

    self::$mcp_healthy = true;
    return $result;
  }

  private static function is_mcp_available(): bool {
    if (! class_exists('AI_Composer_Settings')) {
      return false;
    }

    if (! AI_Composer_Settings::is_mcp_context_enabled()) {
      return false;
    }

    $url = AI_Composer_Settings::get_mcp_server_url();
    if ($url === '') {
      return false;
    }

    if (get_transient(self::TRANSIENT_PREFIX . 'failure') !== false) {
      return false;
    }

    return true;
  }

  private static function get_client(): ?AI_Composer_MCP_Client {
    if (self::$client !== null) {
      return self::$client;
    }

    $url = AI_Composer_Settings::get_mcp_server_url();
    if ($url === '') {
      return null;
    }

    self::$client = new AI_Composer_MCP_Client($url);
    return self::$client;
  }

  /* ──────────────────────────────────────────────
   *  File-based fallback (existing behavior)
   * ────────────────────────────────────────────── */

  /**
   * Load context from the theme context directory.
   * Preserves the existing ai_composer_context_directory filter.
   */
  private static function load_from_files(string $section_label = 'Site context'): string {
    $dir = apply_filters(
      'ai_composer_context_directory',
      get_stylesheet_directory() . '/content-intelligence/context'
    );

    if (! is_string($dir) || ! is_dir($dir)) {
      return '';
    }

    $files = glob($dir . '/*.{txt,md}', GLOB_BRACE);
    if (! is_array($files) || empty($files)) {
      return '';
    }

    sort($files);
    $chunks = [];

    foreach (array_slice($files, 0, 10) as $file) {
      $text = trim((string) file_get_contents($file));
      if ($text !== '' && strlen($text) < 10000) {
        $chunks[] = '--- ' . basename($file) . " ---\n" . $text;
      }
    }

    if (empty($chunks)) {
      return '';
    }

    return $section_label . ":\n" . implode("\n\n", $chunks);
  }

  /* ──────────────────────────────────────────────
   *  Transient cache
   * ────────────────────────────────────────────── */

  private static function get_cached(string $key): ?string {
    $value = get_transient(self::TRANSIENT_PREFIX . $key);
    return $value !== false ? (string) $value : null;
  }

  private static function set_cached(string $key, string $value): void {
    $ttl = class_exists('AI_Composer_Settings')
      ? AI_Composer_Settings::get_mcp_cache_ttl()
      : 3600;

    set_transient(self::TRANSIENT_PREFIX . $key, $value, $ttl);
  }
}
