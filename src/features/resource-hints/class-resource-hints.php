<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Resource hints module.
 *
 * Outputs <link rel="preconnect"> and <link rel="dns-prefetch"> tags
 * for origins defined in the wpi_resource_hints option or injected
 * via the `wpi_resource_hint_origins` filter.
 *
 * Themes should use the filter to declare their CDN, font, and
 * analytics domains so the plugin renders them consistently.
 *
 * @since 0.3.0
 */
class WPI_Resource_Hints {

  private const OPTION = 'wpi_resource_hints';

  private const DEFAULTS = [
    'origins' => [],
  ];

  public static function boot(): void {
    add_action('wp_head', [self::class, 'render'], 1);
  }

  /**
   * Merge saved origins with filter-provided ones and render tags.
   */
  public static function render(): void {
    $opts    = self::get_options();
    $origins = is_array($opts['origins']) ? $opts['origins'] : [];

    /**
     * Let themes and plugins inject additional origins.
     *
     * Each entry: ['url' => 'https://...', 'crossorigin' => true/false]
     *
     * @param array $origins Current list.
     */
    $origins = apply_filters('wpi_resource_hint_origins', $origins);

    if (empty($origins)) {
      return;
    }

    $seen = [];
    foreach ($origins as $entry) {
      if (! is_array($entry) || empty($entry['url'])) {
        continue;
      }
      $url = esc_url($entry['url']);
      if ($url === '' || isset($seen[$url])) {
        continue;
      }
      $seen[$url] = true;

      $cross = ! empty($entry['crossorigin']) ? ' crossorigin' : '';
      $host  = wp_parse_url($url, PHP_URL_HOST);

      echo '<link rel="preconnect" href="' . $url . '"' . $cross . ">\n";
      if ($host) {
        echo '<link rel="dns-prefetch" href="//' . esc_attr($host) . "\">\n";
      }
    }
  }

  /** @return array{origins: array} */
  public static function get_options(): array {
    $saved = get_option(self::OPTION, []);
    if (! is_array($saved)) {
      $saved = [];
    }
    return wp_parse_args($saved, self::DEFAULTS);
  }

  /** @param array<string,mixed> $input */
  public static function sanitize(array $input): array {
    $clean = ['origins' => []];

    if (! empty($input['origins']) && is_array($input['origins'])) {
      foreach ($input['origins'] as $entry) {
        $url = esc_url_raw(trim($entry['url'] ?? ''));
        if ($url === '') {
          continue;
        }
        $clean['origins'][] = [
          'url'         => $url,
          'crossorigin' => ! empty($entry['crossorigin']),
        ];
      }
    }

    return $clean;
  }

  public static function render_fields(): void {
    $opts    = self::get_options();
    $origins = $opts['origins'];
    $option  = esc_attr(self::OPTION);
    $next_i  = empty($origins) ? 1 : count($origins);

    ?>
    <p class="description">
      <?php esc_html_e('Define origins for preconnect and dns-prefetch. Themes can also inject origins via the wpi_resource_hint_origins filter.', 'wp-intelligence'); ?>
    </p>
    <table class="form-table" role="presentation"><tbody>
      <tr>
        <th scope="row"><?php esc_html_e('Origins', 'wp-intelligence'); ?></th>
        <td>
          <div id="wpi-origins-list" data-option="<?php echo esc_attr($option); ?>" data-next-index="<?php echo esc_attr((string) $next_i); ?>">
            <?php if (empty($origins)) : ?>
              <?php self::render_origin_row($option, 0, '', false); ?>
            <?php else : ?>
              <?php foreach ($origins as $i => $entry) : ?>
                <?php self::render_origin_row($option, $i, $entry['url'] ?? '', ! empty($entry['crossorigin'])); ?>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <button type="button" class="button button-small" id="wpi-add-origin" data-option="<?php echo esc_attr($option); ?>">
            + <?php esc_html_e('Add origin', 'wp-intelligence'); ?>
          </button>
        </td>
      </tr>
    </tbody></table>
    <?php
  }

  private static function render_origin_row(string $option, int $i, string $url, bool $cross): void {
    ?>
    <div class="wpi-origin-row">
      <input type="url" name="<?php echo $option; ?>[origins][<?php echo $i; ?>][url]" value="<?php echo esc_attr($url); ?>" class="regular-text" placeholder="https://cdn.example.com">
      <label>
        <input type="checkbox" name="<?php echo $option; ?>[origins][<?php echo $i; ?>][crossorigin]" value="1" <?php checked($cross); ?>>
        crossorigin
      </label>
      <button type="button" class="button button-link-delete wpi-rm-origin">&times;</button>
    </div>
    <?php
  }
}
