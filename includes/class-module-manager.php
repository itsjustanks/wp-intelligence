<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Manages feature modules and their on/off state.
 *
 * Each module registers itself via `WPI_Module_Manager::register()`.
 * Feature flags are stored in a single `wpi_modules` option as an
 * associative array of module_id => bool.
 */
class WPI_Module_Manager {

  private const OPTION = 'wpi_modules';

  /** @var array<string,array{title:string,description:string,boot:callable,default:bool,requires?:string}> */
  private static array $modules = [];

  /** @var bool */
  private static bool $booted = false;

  /**
   * Register a module.
   *
   * @param string $id     Unique slug (e.g. 'security', 'ai_composer').
   * @param array  $config {
   *   @type string   $title       Human label.
   *   @type string   $description One-liner shown in settings.
   *   @type callable $boot        Called when the module is active.
   *   @type bool     $default     Whether the module is on by default.
   *   @type string   $requires    Optional. Plugin class/function gate (e.g. 'WooCommerce').
   *   @type string   $icon        Optional. Dashicon slug.
   * }
   */
  public static function register(string $id, array $config): void {
    $config = wp_parse_args($config, [
      'title'       => $id,
      'description' => '',
      'boot'        => '__return_null',
      'default'     => true,
      'requires'    => '',
      'icon'        => 'admin-generic',
    ]);
    self::$modules[$id] = $config;
  }

  /**
   * @return array<string,array>
   */
  public static function all(): array {
    return self::$modules;
  }

  /**
   * Whether a module's external dependency is satisfied.
   */
  public static function dependency_met(string $id): bool {
    $req = self::$modules[$id]['requires'] ?? '';
    if ($req === '') {
      return true;
    }
    return class_exists($req) || function_exists($req);
  }

  /**
   * Whether a module is enabled by the admin.
   */
  public static function is_active(string $id): bool {
    if (! isset(self::$modules[$id])) {
      return false;
    }
    if (! self::dependency_met($id)) {
      return false;
    }

    $forced = apply_filters('wpi_force_active_modules', []);
    if (is_array($forced) && in_array($id, $forced, true)) {
      return true;
    }

    $flags = get_option(self::OPTION, []);
    if (! is_array($flags)) {
      $flags = [];
    }
    return (bool) ($flags[$id] ?? self::$modules[$id]['default']);
  }

  /**
   * Boot every active module. Safe to call multiple times.
   *
   * On first activation (no saved flags yet), runs the
   * `wpi_prefill_module_defaults` action so themes can seed settings.
   */
  public static function boot(): void {
    if (self::$booted) {
      return;
    }
    self::$booted = true;

    $existing = get_option(self::OPTION, null);
    if ($existing === null) {
      /**
       * Fired once when WP Intelligence has no saved module flags.
       * Themes should hook here to call update_option() for
       * wpi_modules, wpi_security, wpi_performance, wpi_resource_hints, etc.
       */
      do_action('wpi_prefill_module_defaults');
    }

    foreach (self::$modules as $id => $config) {
      if (self::is_active($id)) {
        call_user_func($config['boot']);
      }
    }
  }

  /**
   * Save feature flags from settings form.
   *
   * @param array<string,mixed> $posted Raw $_POST['wpi_modules'] values.
   * @return array<string,bool>
   */
  public static function save_flags(array $posted): array {
    $clean = [];
    foreach (self::$modules as $id => $config) {
      $clean[$id] = ! empty($posted[$id]);
    }
    update_option(self::OPTION, $clean, true);
    return $clean;
  }

  /**
   * @return array<string,bool>
   */
  public static function get_flags(): array {
    $flags = get_option(self::OPTION, []);
    if (! is_array($flags)) {
      $flags = [];
    }
    $merged = [];
    foreach (self::$modules as $id => $config) {
      $merged[$id] = (bool) ($flags[$id] ?? $config['default']);
    }
    return $merged;
  }
}
