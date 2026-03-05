<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Webhook/API Data Source — pre-fetches data from external REST endpoints.
 *
 * Each webhook source is configured with:
 *   - url:      The endpoint URL (supports {{url.param}} interpolation for dynamic URLs)
 *   - method:   GET or POST
 *   - headers:  Custom request headers
 *   - body:     Request body (for POST)
 *   - auth:     Authentication (none, bearer, basic, api_key)
 *   - cache_ttl: Transient cache duration in seconds (0 = no cache)
 *
 * Tags:
 *   {{source_name.field.path}} — resolved from the JSON response
 *
 * Example:
 *   Source "crm" configured to GET https://api.example.com/user?email={{url.email}}
 *   {{crm.plan}}           → "pro"
 *   {{crm.company.name}}   → "Acme Inc"
 */
class WPI_Webhook_Source implements WPI_Data_Source_Interface {

  private const OPTION_KEY = 'wpi_dynamic_data_webhooks';
  private const MAX_RESPONSE_SIZE = 524288; // 512 KB
  private const DEFAULT_TIMEOUT = 5;
  private const MAX_TIMEOUT = 15;

  private string $name;
  private array $config;

  public function __construct(string $name, array $config) {
    $this->name   = $name;
    $this->config = $config;
  }

  public function get_label(): string {
    return $this->config['label'] ?? $this->name;
  }

  public function get_type(): string {
    return 'webhook';
  }

  public function is_client_side(): bool {
    return false;
  }

  public function fetch(array $context = []): array|\WP_Error {
    $url = $this->interpolate_url($this->config['url'] ?? '', $context);

    if (empty($url) || ! wp_http_validate_url($url)) {
      return new \WP_Error('invalid_webhook_url', __('Invalid webhook URL.', 'wp-intelligence'));
    }

    $cache_ttl = (int) ($this->config['cache_ttl'] ?? 300);
    if ($cache_ttl > 0) {
      $cache_key = 'wpi_dd_' . md5($this->name . '|' . $url);
      $cached    = get_transient($cache_key);
      if (is_array($cached)) {
        return $cached;
      }
    }

    $method  = strtoupper($this->config['method'] ?? 'GET');
    $headers = $this->build_headers();
    $timeout = min(
      max(1, (int) ($this->config['timeout'] ?? self::DEFAULT_TIMEOUT)),
      self::MAX_TIMEOUT
    );

    $args = [
      'method'    => $method === 'POST' ? 'POST' : 'GET',
      'headers'   => $headers,
      'timeout'   => $timeout,
      'sslverify' => true,
    ];

    if ($method === 'POST' && ! empty($this->config['body'])) {
      $body = $this->config['body'];
      if (is_array($body)) {
        $args['body']                    = wp_json_encode($body);
        $args['headers']['Content-Type'] = 'application/json';
      } else {
        $args['body'] = (string) $body;
      }
    }

    $args = apply_filters('wpi_dynamic_data_webhook_request_args', $args, $this->name, $this->config);

    $response = wp_remote_request($url, $args);

    if (is_wp_error($response)) {
      return $response;
    }

    $status = wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) {
      return new \WP_Error(
        'webhook_http_error',
        sprintf(__('Webhook "%s" returned HTTP %d.', 'wp-intelligence'), $this->name, $status)
      );
    }

    $raw_body = wp_remote_retrieve_body($response);
    if (strlen($raw_body) > self::MAX_RESPONSE_SIZE) {
      return new \WP_Error('webhook_response_too_large', __('Webhook response exceeds size limit.', 'wp-intelligence'));
    }

    $data = json_decode($raw_body, true);
    if (! is_array($data)) {
      return new \WP_Error('webhook_invalid_json', __('Webhook response is not valid JSON.', 'wp-intelligence'));
    }

    $data = apply_filters('wpi_dynamic_data_webhook_response', $data, $this->name, $this->config);

    if ($cache_ttl > 0 && isset($cache_key)) {
      set_transient($cache_key, $data, $cache_ttl);
    }

    return $data;
  }

  /**
   * Interpolate URL parameters (e.g. replace {{url.email}} in the endpoint URL).
   */
  private function interpolate_url(string $url, array $context): string {
    if (strpos($url, '{{') === false) {
      return $url;
    }

    return preg_replace_callback(
      '/\{\{url\.([a-zA-Z0-9_\-]+)\}\}/',
      function ($matches) {
        $param = sanitize_key($matches[1]);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $value = isset($_GET[$param]) ? sanitize_text_field(wp_unslash($_GET[$param])) : '';
        return urlencode($value);
      },
      $url
    ) ?? $url;
  }

  private function build_headers(): array {
    $headers = [];

    if (! empty($this->config['headers']) && is_array($this->config['headers'])) {
      foreach ($this->config['headers'] as $header) {
        $name  = sanitize_text_field($header['name'] ?? '');
        $value = $header['value'] ?? '';
        if ($name !== '') {
          $headers[$name] = $value;
        }
      }
    }

    $auth_type  = $this->config['auth_type'] ?? 'none';
    $auth_value = $this->config['auth_value'] ?? '';

    switch ($auth_type) {
      case 'bearer':
        if ($auth_value !== '') {
          $headers['Authorization'] = 'Bearer ' . $auth_value;
        }
        break;

      case 'basic':
        if ($auth_value !== '') {
          // auth_value should be "username:password"
          $headers['Authorization'] = 'Basic ' . base64_encode($auth_value);
        }
        break;

      case 'api_key':
        $header_name = $this->config['auth_header'] ?? 'X-API-Key';
        if ($auth_value !== '') {
          $headers[$header_name] = $auth_value;
        }
        break;
    }

    if (! isset($headers['Accept'])) {
      $headers['Accept'] = 'application/json';
    }

    return $headers;
  }

  public function get_available_tags(): array {
    $sample_fields = $this->config['fields'] ?? [];
    $tags = [];

    foreach ($sample_fields as $field) {
      $path  = $field['path'] ?? '';
      $label = $field['label'] ?? $path;
      if ($path !== '') {
        $tags[] = [
          'tag'   => $this->name . '.' . $path,
          'label' => $label,
          'group' => $this->get_label(),
        ];
      }
    }

    if (empty($tags)) {
      $tags[] = [
        'tag'   => $this->name . '.FIELD_PATH',
        'label' => sprintf(__('%s response field (specify path)', 'wp-intelligence'), $this->get_label()),
        'group' => $this->get_label(),
      ];
    }

    return $tags;
  }

  /* ──────────────────────────────────────────────
   *  Static helpers for managing webhook configs
   * ────────────────────────────────────────────── */

  /**
   * Get all saved webhook configurations.
   *
   * @return array<string, array>
   */
  public static function get_all_configs(): array {
    $webhooks = get_option(self::OPTION_KEY, []);
    return is_array($webhooks) ? $webhooks : [];
  }

  /**
   * Save a webhook configuration.
   *
   * @param string $name   Webhook name.
   * @param array  $config Webhook config.
   * @return bool
   */
  public static function save_config(string $name, array $config): bool {
    $webhooks = self::get_all_configs();
    $webhooks[$name] = self::sanitize_config($config);
    return update_option(self::OPTION_KEY, $webhooks, false);
  }

  /**
   * Delete a webhook configuration.
   *
   * @param string $name Webhook name.
   * @return bool
   */
  public static function delete_config(string $name): bool {
    $webhooks = self::get_all_configs();
    if (! isset($webhooks[$name])) {
      return false;
    }
    $cache_key = 'wpi_dd_' . md5($name . '|' . ($webhooks[$name]['url'] ?? ''));
    unset($webhooks[$name]);
    delete_transient($cache_key);
    return update_option(self::OPTION_KEY, $webhooks, false);
  }

  /**
   * Sanitize a webhook configuration.
   *
   * @param array $config Raw config.
   * @return array Sanitized config.
   */
  public static function sanitize_config(array $config): array {
    $clean = [
      'label'       => sanitize_text_field($config['label'] ?? ''),
      'url'         => esc_url_raw($config['url'] ?? ''),
      'method'      => in_array(strtoupper($config['method'] ?? 'GET'), ['GET', 'POST'], true) ? strtoupper($config['method']) : 'GET',
      'auth_type'   => in_array($config['auth_type'] ?? 'none', ['none', 'bearer', 'basic', 'api_key'], true) ? $config['auth_type'] : 'none',
      'auth_value'  => sanitize_text_field($config['auth_value'] ?? ''),
      'auth_header' => sanitize_text_field($config['auth_header'] ?? 'X-API-Key'),
      'cache_ttl'   => max(0, (int) ($config['cache_ttl'] ?? 300)),
      'timeout'     => min(self::MAX_TIMEOUT, max(1, (int) ($config['timeout'] ?? self::DEFAULT_TIMEOUT))),
      'headers'     => [],
      'body'        => null,
      'fields'      => [],
    ];

    if (! empty($config['headers']) && is_array($config['headers'])) {
      foreach ($config['headers'] as $header) {
        if (! is_array($header)) continue;
        $name  = sanitize_text_field($header['name'] ?? '');
        $value = sanitize_text_field($header['value'] ?? '');
        if ($name !== '') {
          $clean['headers'][] = ['name' => $name, 'value' => $value];
        }
      }
    }

    if (isset($config['body'])) {
      if (is_array($config['body'])) {
        $clean['body'] = $config['body'];
      } elseif (is_string($config['body'])) {
        $decoded = json_decode($config['body'], true);
        $clean['body'] = is_array($decoded) ? $decoded : $config['body'];
      }
    }

    if (! empty($config['fields']) && is_array($config['fields'])) {
      foreach (array_slice($config['fields'], 0, 50) as $field) {
        if (! is_array($field)) continue;
        $path  = sanitize_text_field($field['path'] ?? '');
        $label = sanitize_text_field($field['label'] ?? $path);
        if ($path !== '') {
          $clean['fields'][] = ['path' => $path, 'label' => $label];
        }
      }
    }

    return $clean;
  }

  /**
   * Register all saved webhook configs as data sources.
   */
  public static function register_all(): void {
    $webhooks = self::get_all_configs();
    $registry = WPI_Data_Source_Registry::instance();

    foreach ($webhooks as $name => $config) {
      $name = sanitize_key($name);
      if ($name === '' || in_array($name, ['wp', 'url', 'cookie', 'storage'], true)) {
        continue;
      }
      $registry->register($name, new self($name, $config));
    }
  }
}
