<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * REST API controller for WP Intelligence settings.
 *
 * Provides GET/POST endpoints at wp-intelligence/v1/settings
 * for the React-based admin UI.
 */
class WPI_REST_Settings {

  private const NAMESPACE = 'wp-intelligence/v1';

  public static function init(): void {
    add_action('rest_api_init', [self::class, 'register_routes']);
  }

  public static function register_routes(): void {
    register_rest_route(self::NAMESPACE, '/settings', [
      [
        'methods'             => 'GET',
        'callback'            => [self::class, 'get_settings'],
        'permission_callback' => [self::class, 'check_permission'],
      ],
      [
        'methods'             => 'POST',
        'callback'            => [self::class, 'save_settings'],
        'permission_callback' => [self::class, 'check_permission'],
      ],
    ]);
  }

  public static function check_permission(): bool {
    return current_user_can('manage_options');
  }

  public static function get_settings(): WP_REST_Response {
    $settings        = AI_Composer_Settings::get_settings();
    $modules         = WPI_Module_Manager::get_flags();
    $module_registry = self::build_module_registry();

    return new WP_REST_Response([
      'settings'        => $settings,
      'modules'         => $modules,
      'module_registry' => $module_registry,
    ], 200);
  }

  public static function save_settings(WP_REST_Request $request): WP_REST_Response {
    $params = $request->get_json_params();

    if (isset($params['settings']) && is_array($params['settings'])) {
      $sanitized = AI_Composer_Settings::sanitize($params['settings']);
      update_option('ai_composer_settings', $sanitized);

      foreach (['wpi_security', 'wpi_performance', 'wpi_woocommerce', 'wpi_resource_hints'] as $sub_option) {
        if (isset($params['settings'][$sub_option]) && is_array($params['settings'][$sub_option])) {
          $existing = get_option($sub_option, []);
          if (! is_array($existing)) {
            $existing = [];
          }
          $merged = array_merge($existing, $params['settings'][$sub_option]);
          update_option($sub_option, $merged);
        }
      }
    }

    if (isset($params['modules']) && is_array($params['modules'])) {
      $sanitized_modules = AI_Composer_Settings::sanitize_modules($params['modules']);
      update_option('wpi_modules', $sanitized_modules);
    }

    $settings = AI_Composer_Settings::get_settings();
    $modules  = WPI_Module_Manager::get_flags();

    foreach (['wpi_security', 'wpi_performance', 'wpi_woocommerce', 'wpi_resource_hints'] as $sub_option) {
      $val = get_option($sub_option, []);
      if (is_array($val)) {
        $settings[$sub_option] = $val;
      }
    }

    return new WP_REST_Response([
      'settings' => $settings,
      'modules'  => $modules,
    ], 200);
  }

  private static function build_module_registry(): array {
    $registry = [];
    $forced   = apply_filters('wpi_force_active_modules', []);
    if (! is_array($forced)) {
      $forced = [];
    }

    foreach (WPI_Module_Manager::all() as $id => $config) {
      $registry[$id] = [
        'title'          => $config['title'],
        'description'    => $config['description'],
        'icon'           => $config['icon'] ?? 'admin-generic',
        'category'       => $config['category'] ?? 'General',
        'default'        => (bool) $config['default'],
        'dependency_met' => WPI_Module_Manager::dependency_met($id),
        'is_forced'      => in_array($id, $forced, true),
      ];
    }

    return $registry;
  }
}
