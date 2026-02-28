<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Security hardening module.
 *
 * Reduces information leakage and attack surface by disabling
 * feeds, version exposure, unnecessary header links, the in-admin
 * file editor, and the comment URL field.
 *
 * Individual features are controlled by sub-flags stored in the
 * `wpi_security` option. Everything defaults to ON.
 *
 * @since 0.3.0
 */
class WPI_Security {

  private const OPTION = 'wpi_security';

  private const DEFAULTS = [
    'disable_feeds'       => true,
    'remove_header_meta'  => true,
    'disable_file_editor' => true,
    'remove_comment_url'  => true,
  ];

  public static function boot(): void {
    $opts = self::get_options();

    if ($opts['disable_feeds']) {
      add_action('do_feed',               [self::class, 'kill_feed'], -1);
      add_action('do_feed_rdf',           [self::class, 'kill_feed'], -1);
      add_action('do_feed_rss',           [self::class, 'kill_feed'], -1);
      add_action('do_feed_rss2',          [self::class, 'kill_feed'], -1);
      add_action('do_feed_atom',          [self::class, 'kill_feed'], -1);
      add_action('do_feed_rss2_comments', [self::class, 'kill_feed'], -1);
      add_action('do_feed_atom_comments', [self::class, 'kill_feed'], -1);

      add_action('feed_links_show_posts_feed',    '__return_false', -1);
      add_action('feed_links_show_comments_feed', '__return_false', -1);
      remove_action('wp_head', 'feed_links', 2);
      remove_action('wp_head', 'feed_links_extra', 3);
    }

    if ($opts['remove_header_meta']) {
      remove_action('template_redirect', 'rest_output_link_header', 11, 0);
      remove_action('wp_head', 'rest_output_link_wp_head', 10);
      remove_action('wp_head', 'wp_oembed_add_discovery_links', 10);
      remove_action('wp_head', 'wlwmanifest_link');
      remove_action('wp_head', 'rsd_link');
      remove_action('wp_head', 'wp_resource_hints', 2, 99);
      remove_action('wp_head', 'wp_generator');
      add_filter('the_generator', '__return_empty_string');
    }

    if ($opts['disable_file_editor']) {
      add_action('init', [self::class, 'disable_editor']);
    }

    if ($opts['remove_comment_url']) {
      add_filter('comment_form_default_fields', [self::class, 'strip_url_field']);
    }
  }

  public static function kill_feed(): void {
    wp_redirect(home_url());
    exit;
  }

  public static function disable_editor(): void {
    if (! defined('DISALLOW_FILE_EDIT')) {
      define('DISALLOW_FILE_EDIT', true);
    }
  }

  /**
   * @param array<string,string> $fields
   * @return array<string,string>
   */
  public static function strip_url_field(array $fields): array {
    unset($fields['url']);
    return $fields;
  }

  /**
   * @return array<string,bool>
   */
  public static function get_options(): array {
    $saved = get_option(self::OPTION, []);
    if (! is_array($saved)) {
      $saved = [];
    }
    return wp_parse_args($saved, self::DEFAULTS);
  }

  /**
   * @param array<string,mixed> $input
   * @return array<string,bool>
   */
  public static function sanitize(array $input): array {
    $clean = [];
    foreach (self::DEFAULTS as $key => $default) {
      $clean[$key] = ! empty($input[$key]);
    }
    return $clean;
  }

  /**
   * Render sub-settings for the settings page.
   */
  public static function render_fields(): void {
    $opts   = self::get_options();
    $option = esc_attr(self::OPTION);

    $features = [
      'disable_feeds'       => [
        'label' => __('Disable RSS / Atom feeds', 'wp-intelligence'),
        'desc'  => __('Redirects all feed URLs to homepage and removes feed discovery links from &lt;head&gt;.', 'wp-intelligence'),
      ],
      'remove_header_meta'  => [
        'label' => __('Remove header metadata', 'wp-intelligence'),
        'desc'  => __('Strips REST API links, oEmbed discovery, WLW manifest, RSD link, resource hints, and generator tag.', 'wp-intelligence'),
      ],
      'disable_file_editor' => [
        'label' => __('Disable theme/plugin file editor', 'wp-intelligence'),
        'desc'  => __('Defines DISALLOW_FILE_EDIT to prevent editing files from the admin dashboard.', 'wp-intelligence'),
      ],
      'remove_comment_url'  => [
        'label' => __('Remove comment URL field', 'wp-intelligence'),
        'desc'  => __('Removes the website URL input from the comment form to reduce spam.', 'wp-intelligence'),
      ],
    ];

    echo '<table class="form-table" role="presentation"><tbody>';
    foreach ($features as $key => $meta) {
      $checked = checked($opts[$key], true, false);
      echo '<tr>';
      echo '<th scope="row">' . esc_html($meta['label']) . '</th>';
      echo '<td>';
      printf(
        '<label><input type="checkbox" name="%s[%s]" value="1" %s> %s</label>',
        $option,
        esc_attr($key),
        $checked,
        esc_html__('Enable', 'wp-intelligence')
      );
      echo '<p class="description">' . wp_kses_post($meta['desc']) . '</p>';
      echo '</td></tr>';
    }
    echo '</tbody></table>';
  }
}
