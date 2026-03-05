<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Cookie Data Source — provides merge tags for cookie values.
 *
 * Tags:
 *   {{cookie.COOKIE_NAME}}  — value of the cookie
 *
 * Example:
 *   {{cookie.user_pref}}          → "dark_mode"
 *   {{cookie.viewed_pricing}}     → "1"
 */
class WPI_Cookie_Source implements WPI_Data_Source_Interface {

  private const MAX_COOKIES = 30;
  private const MAX_VALUE_LENGTH = 1000;

  public function get_label(): string {
    return __('Cookies', 'wp-intelligence');
  }

  public function get_type(): string {
    return 'cookie';
  }

  public function is_client_side(): bool {
    return false;
  }

  public function fetch(array $context = []): array {
    $cookies = [];
    $count   = 0;

    foreach ($_COOKIE as $key => $value) {
      if (++$count > self::MAX_COOKIES) {
        break;
      }

      $key = sanitize_key($key);
      if ($key === '' || str_starts_with($key, 'wordpress_')) {
        continue;
      }

      if (is_string($value)) {
        $cookies[$key] = sanitize_text_field(mb_substr($value, 0, self::MAX_VALUE_LENGTH));
      }
    }

    return $cookies;
  }

  public function get_available_tags(): array {
    return [
      [
        'tag'   => 'cookie.COOKIE_NAME',
        'label' => __('Cookie Value (specify name)', 'wp-intelligence'),
        'group' => __('Cookies', 'wp-intelligence'),
      ],
    ];
  }
}
