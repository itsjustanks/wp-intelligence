<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Sitewide code injection module.
 *
 * Registers Customizer controls for injecting arbitrary HTML/JS
 * into the site <head> and footer. Also renames the built-in
 * "Additional CSS" section to "Sitewide CSS" for consistency.
 *
 * Settings are stored in the standard Customizer option (theme_mods)
 * and rendered via wp_head / wp_footer hooks.
 *
 * @since 0.3.0
 */
class WPI_Code_Injection {

  public static function boot(): void {
    add_action('customize_register', [self::class, 'register_customizer']);
    add_action('wp_head',   [self::class, 'render_header_code'], 999);
    add_action('wp_footer', [self::class, 'render_footer_code'], 999);
  }

  public static function register_customizer(\WP_Customize_Manager $wp_customize): void {
    $wp_customize->add_section('wpi_custom_code', [
      'title'       => __('Sitewide Code', 'wp-intelligence'),
      'priority'    => 160,
      'description' => __('Add custom HTML/JS code that will be output in the header or footer site-wide.', 'wp-intelligence'),
    ]);

    $wp_customize->add_setting('wpi_header_code', [
      'default'           => '',
      'sanitize_callback' => [self::class, 'sanitize_code'],
    ]);
    $wp_customize->add_control(
      new \WP_Customize_Code_Editor_Control($wp_customize, 'wpi_header_code_control', [
        'label'     => __('Header Custom Code', 'wp-intelligence'),
        'code_type' => 'text/html',
        'settings'  => 'wpi_header_code',
        'section'   => 'wpi_custom_code',
      ])
    );

    $wp_customize->add_setting('wpi_footer_code', [
      'default'           => '',
      'sanitize_callback' => [self::class, 'sanitize_code'],
    ]);
    $wp_customize->add_control(
      new \WP_Customize_Code_Editor_Control($wp_customize, 'wpi_footer_code_control', [
        'label'     => __('Footer Custom Code', 'wp-intelligence'),
        'code_type' => 'text/html',
        'settings'  => 'wpi_footer_code',
        'section'   => 'wpi_custom_code',
      ])
    );

    $css_section = $wp_customize->get_section('custom_css');
    if ($css_section !== null) {
      $css_section->title = __('Sitewide CSS', 'wp-intelligence');
    }
  }

  /**
   * Only administrators should save raw HTML/JS.
   * The Customizer itself enforces manage_options, so this is a
   * defense-in-depth pass-through.
   */
  public static function sanitize_code(string $content): string {
    if (! current_user_can('unfiltered_html')) {
      return wp_kses_post($content);
    }
    return $content;
  }

  public static function render_header_code(): void {
    $code = get_theme_mod('wpi_header_code', '');
    if ($code !== '') {
      echo $code . "\n";
    }
  }

  public static function render_footer_code(): void {
    $code = get_theme_mod('wpi_footer_code', '');
    if ($code !== '') {
      echo $code . "\n";
    }
  }
}
