<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * WooCommerce optimization module.
 *
 * 1. Dequeues WC front-end scripts/styles on non-WooCommerce pages.
 * 2. Persists checkout form data in the WC session so returning
 *    visitors see their previous inputs.
 *
 * Only boots when the WooCommerce plugin is active.
 *
 * @since 0.3.0
 */
class WPI_WooCommerce {

  private const OPTION = 'wpi_woocommerce';

  private const DEFAULTS = [
    'asset_optimization'    => true,
    'checkout_persistence'  => true,
  ];

  public static function boot(): void {
    if (! class_exists('WooCommerce')) {
      return;
    }

    $opts = self::get_options();

    if ($opts['asset_optimization']) {
      add_action('get_header', [self::class, 'maybe_dequeue_assets']);
    }

    if ($opts['checkout_persistence']) {
      add_action('woocommerce_checkout_update_order_review', [self::class, 'save_checkout_fields'], 9999);
      add_filter('woocommerce_checkout_get_value', [self::class, 'restore_checkout_field'], 9999, 2);
    }
  }

  public static function maybe_dequeue_assets(): void {
    if (is_woocommerce() || is_cart() || is_checkout() || is_page('my-account')) {
      return;
    }

    remove_action('wp_enqueue_scripts', [WC_Frontend_Scripts::class, 'load_scripts']);
    remove_action('wp_print_scripts', [WC_Frontend_Scripts::class, 'localize_printed_scripts'], 5);
    remove_action('wp_print_footer_scripts', [WC_Frontend_Scripts::class, 'localize_printed_scripts'], 5);
  }

  /**
   * @param string $posted_data URL-encoded form string from checkout AJAX.
   * @return string Unmodified data.
   */
  public static function save_checkout_fields(string $posted_data): string {
    parse_str($posted_data, $output);
    WC()->session->set('checkout_data', $output);
    return $posted_data;
  }

  /**
   * @param mixed  $value Current field value.
   * @param string $index Field key (e.g. billing_email).
   * @return mixed
   */
  public static function restore_checkout_field(mixed $value, string $index): mixed {
    $data = WC()->session->get('checkout_data');
    if (! is_array($data) || empty($data[$index])) {
      return $value;
    }
    return is_bool($data[$index]) ? (int) $data[$index] : $data[$index];
  }

  /** @return array<string,bool> */
  public static function get_options(): array {
    $saved = get_option(self::OPTION, []);
    if (! is_array($saved)) {
      $saved = [];
    }
    return wp_parse_args($saved, self::DEFAULTS);
  }

  /** @param array<string,mixed> $input */
  public static function sanitize(array $input): array {
    return [
      'asset_optimization'   => ! empty($input['asset_optimization']),
      'checkout_persistence' => ! empty($input['checkout_persistence']),
    ];
  }

  public static function render_fields(): void {
    $opts   = self::get_options();
    $option = esc_attr(self::OPTION);

    $features = [
      'asset_optimization' => [
        'label' => __('Conditional asset loading', 'wp-intelligence'),
        'desc'  => __('Dequeues WooCommerce scripts and styles on non-WooCommerce pages (shop, product, cart, checkout, account).', 'wp-intelligence'),
      ],
      'checkout_persistence' => [
        'label' => __('Checkout field persistence', 'wp-intelligence'),
        'desc'  => __('Saves checkout form inputs in the WooCommerce session so returning visitors see their previous data.', 'wp-intelligence'),
      ],
    ];

    echo '<table class="form-table" role="presentation"><tbody>';
    foreach ($features as $key => $meta) {
      echo '<tr><th scope="row">' . esc_html($meta['label']) . '</th><td>';
      printf(
        '<label><input type="checkbox" name="%s[%s]" value="1" %s> %s</label>',
        $option,
        esc_attr($key),
        checked($opts[$key], true, false),
        esc_html__('Enable', 'wp-intelligence')
      );
      echo '<p class="description">' . wp_kses_post($meta['desc']) . '</p>';
      echo '</td></tr>';
    }
    echo '</tbody></table>';
  }
}
