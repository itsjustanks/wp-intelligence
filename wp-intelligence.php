<?php
/**
 * Plugin Name: WP Intelligence
 * Plugin URI:  https://github.com/whole-code/wp-intelligence
 * Author URI:  https://whole.tech
 * Description: Modular WordPress toolkit — AI page composition, security hardening, performance tuning, admin enhancements, and more.
 * Version:     0.3.0
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author:      whole.tech
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-intelligence
 */

if (! defined('ABSPATH')) {
  exit;
}

if (defined('WPI_VERSION')) {
  return;
}

define('WPI_VERSION', '0.3.0');
define('WPI_DIR',  __DIR__);
define('WPI_FILE', __FILE__);

// Backward-compat aliases used inside the AI Composer module.
if (! defined('AI_COMPOSER_VERSION')) {
  define('AI_COMPOSER_VERSION', WPI_VERSION);
  define('AI_COMPOSER_DIR', WPI_DIR . '/modules/ai-composer');
  define('AI_COMPOSER_FILE', WPI_FILE);
}

add_action('after_setup_theme', function () {
  if (defined('WPI_URL')) {
    return;
  }

  $self       = wp_normalize_path(__DIR__);
  $plugin_dir = wp_normalize_path(WP_PLUGIN_DIR);
  $mu_dir     = wp_normalize_path(defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : '');

  if (str_starts_with($self, $plugin_dir) || ($mu_dir && str_starts_with($self, $mu_dir))) {
    define('WPI_URL', plugin_dir_url(__FILE__));
  } else {
    $theme_dir = wp_normalize_path(get_stylesheet_directory());
    $relative  = str_replace($theme_dir, '', $self);
    define('WPI_URL', trailingslashit(get_stylesheet_directory_uri() . $relative));
  }

  if (! defined('AI_COMPOSER_URL')) {
    define('AI_COMPOSER_URL', WPI_URL . 'modules/ai-composer/');
  }
}, 5);

// Shared infrastructure.
require_once __DIR__ . '/includes/class-module-manager.php';
require_once __DIR__ . '/includes/class-settings.php';

// Auto-discover and register every module.
foreach (glob(__DIR__ . '/modules/*/boot.php') as $boot_file) {
  require_once $boot_file;
}

// Boot active modules after all themes/plugins have loaded.
add_action('init', ['WPI_Module_Manager', 'boot'], 20);

// Settings page is always available regardless of module state.
add_action('init', ['AI_Composer_Settings', 'init'], 20);
