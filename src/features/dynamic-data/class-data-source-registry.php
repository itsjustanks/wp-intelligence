<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Data Source Registry — manages named data sources for merge tag resolution.
 *
 * Each source implements WPI_Data_Source_Interface and provides:
 *   - fetch($context): retrieves data
 *   - get_available_tags(): lists available merge tags
 */

interface WPI_Data_Source_Interface {

  /**
   * Fetch data from this source.
   *
   * @param array $context Request context (post_id, user_id, etc.).
   * @return array|WP_Error The fetched data or error.
   */
  public function fetch(array $context = []): array|\WP_Error;

  /**
   * List available merge tags this source provides.
   *
   * @return array Array of ['tag' => string, 'label' => string, 'group' => string].
   */
  public function get_available_tags(): array;

  /**
   * Human-readable label for this source.
   *
   * @return string
   */
  public function get_label(): string;

  /**
   * Source type identifier.
   *
   * @return string One of: wordpress, url_params, cookie, webhook.
   */
  public function get_type(): string;
}

class WPI_Data_Source_Registry {

  private static ?self $instance = null;

  /** @var array<string, WPI_Data_Source_Interface> */
  private array $sources = [];

  /** @var array<string, array|WP_Error> Memoized fetch results for current request. */
  private array $cache = [];

  private function __construct() {}

  public static function instance(): self {
    if (self::$instance === null) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  /**
   * Register a named data source.
   *
   * @param string                    $name   Unique source name (used in merge tags).
   * @param WPI_Data_Source_Interface $source The source instance.
   */
  public function register(string $name, WPI_Data_Source_Interface $source): void {
    $name = sanitize_key($name);
    if ($name === '') {
      return;
    }
    $this->sources[$name] = $source;
  }

  /**
   * Unregister a data source.
   *
   * @param string $name Source name.
   */
  public function unregister(string $name): void {
    unset($this->sources[$name], $this->cache[$name]);
  }

  /**
   * Get a registered source by name.
   *
   * @param string $name Source name.
   * @return WPI_Data_Source_Interface|null
   */
  public function get(string $name): ?WPI_Data_Source_Interface {
    return $this->sources[$name] ?? null;
  }

  /**
   * Get all registered sources.
   *
   * @return array<string, WPI_Data_Source_Interface>
   */
  public function all(): array {
    return $this->sources;
  }

  /**
   * Check if a source is registered.
   *
   * @param string $name Source name.
   * @return bool
   */
  public function has(string $name): bool {
    return isset($this->sources[$name]);
  }

  /**
   * Fetch data from a source with per-request memoization.
   *
   * @param string $name    Source name.
   * @param array  $context Request context.
   * @return array|WP_Error
   */
  public function fetch_cached(string $name, array $context = []): array|\WP_Error {
    if (isset($this->cache[$name])) {
      return $this->cache[$name];
    }

    $source = $this->get($name);
    if ($source === null) {
      return new \WP_Error('source_not_found', sprintf('Data source "%s" not found.', $name));
    }

    $data = $source->fetch($context);
    $this->cache[$name] = $data;

    return $data;
  }

  /**
   * Clear the per-request cache.
   */
  public function clear_cache(): void {
    $this->cache = [];
  }

  /**
   * Get a descriptive list of all sources for the editor.
   *
   * @return array
   */
  public function get_source_descriptions(): array {
    $descriptions = [];
    foreach ($this->sources as $name => $source) {
      $descriptions[] = [
        'name'  => $name,
        'label' => $source->get_label(),
        'type'  => $source->get_type(),
        'tags'  => $source->get_available_tags(),
      ];
    }
    return $descriptions;
  }
}
