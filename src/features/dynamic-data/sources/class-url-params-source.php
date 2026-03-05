<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * URL Parameters Data Source — provides merge tags for query string values.
 *
 * Tags:
 *   {{url.PARAM_NAME}}  — value of the URL parameter
 *
 * Example:
 *   URL: example.com/page?ref=newsletter&campaign=spring2026
 *   {{url.ref}}       → "newsletter"
 *   {{url.campaign}}  → "spring2026"
 */
class WPI_URL_Params_Source implements WPI_Data_Source_Interface {

  private const MAX_PARAMS = 50;
  private const MAX_VALUE_LENGTH = 1000;

  public function get_label(): string {
    return __('URL Parameters', 'wp-intelligence');
  }

  public function get_type(): string {
    return 'url_params';
  }

  public function fetch(array $context = []): array {
    $params = [];
    $count  = 0;

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    foreach ($_GET as $key => $value) {
      if (++$count > self::MAX_PARAMS) {
        break;
      }

      $key = sanitize_key($key);
      if ($key === '') {
        continue;
      }

      if (is_array($value)) {
        $params[$key] = array_map('sanitize_text_field', array_slice($value, 0, 20));
      } else {
        $params[$key] = sanitize_text_field(mb_substr((string) $value, 0, self::MAX_VALUE_LENGTH));
      }
    }

    return $params;
  }

  public function get_available_tags(): array {
    return [
      [
        'tag'   => 'url.PARAM_NAME',
        'label' => __('URL Parameter (specify name)', 'wp-intelligence'),
        'group' => __('URL Parameters', 'wp-intelligence'),
      ],
    ];
  }
}
