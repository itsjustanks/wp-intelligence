<?php
declare(strict_types=1);

if (! defined('ABSPATH')) {
  define('ABSPATH', __DIR__);
}

if (! function_exists('__')) {
  function __(string $text, string $domain = ''): string {
    return $text;
  }
}

if (! function_exists('apply_filters')) {
  /**
   * Minimal filter shim for isolated tests.
   */
  function apply_filters(string $hook, mixed $value, ...$args): mixed {
    return $value;
  }
}

if (! function_exists('get_option')) {
  function get_option(string $key, mixed $default = false): mixed {
    $options = $GLOBALS['wpi_test_options'] ?? [];
    return $options[$key] ?? $default;
  }
}

if (! function_exists('esc_url_raw')) {
  function esc_url_raw(string $url): string {
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
  }
}

if (! function_exists('wp_parse_url')) {
  function wp_parse_url(string $url, int $component = -1): mixed {
    return parse_url($url, $component);
  }
}

if (! function_exists('wp_http_validate_url')) {
  function wp_http_validate_url(string $url): string|false {
    $host = (string) parse_url($url, PHP_URL_HOST);
    if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
      return false;
    }
    return $url;
  }
}

if (! class_exists('WP_Error')) {
  class WP_Error {
    public function __construct(
      public string $code = '',
      public string $message = '',
      public mixed $data = null
    ) {
    }
  }
}

if (! function_exists('is_wp_error')) {
  function is_wp_error(mixed $value): bool {
    return $value instanceof WP_Error;
  }
}

function wpi_test_assert_true(bool $condition, string $message): void {
  if (! $condition) {
    throw new RuntimeException($message);
  }
}

function wpi_test_assert_same(mixed $expected, mixed $actual, string $message): void {
  if ($expected !== $actual) {
    throw new RuntimeException(
      $message . ' Expected: ' . var_export($expected, true) . ' Actual: ' . var_export($actual, true)
    );
  }
}
