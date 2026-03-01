<?php
/**
 * Plugin Name: WP Intelligence
 * Plugin URI:  https://github.com/whole-code/wp-intelligence
 * Author URI:  https://whole.tech
 * Description: AI-powered page composition for Gutenberg using your site's registered blocks and patterns.
 * Version:     0.2.0
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

define('WPI_VERSION', '0.2.0');
define('WPI_DIR', __DIR__);
define('WPI_FILE', __FILE__);

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
}, 5);

// Legacy compat: some module code still references AI_COMPOSER_VERSION.
if (! defined('AI_COMPOSER_VERSION')) {
  define('AI_COMPOSER_VERSION', WPI_VERSION);
}

// Core includes (always loaded).
require_once WPI_DIR . '/includes/class-module-manager.php';
require_once WPI_DIR . '/includes/class-settings.php';

// Discover and register all modules.
foreach (glob(WPI_DIR . '/modules/*/boot.php') as $boot_file) {
  require_once $boot_file;
}

// Boot active modules after all post types / taxonomies are registered.
add_action('init', ['WPI_Module_Manager', 'boot'], 20);
