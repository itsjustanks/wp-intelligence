<?php
/**
 * WP Intelligence uninstall routine.
 *
 * By default, plugin data is preserved to avoid accidental loss.
 * To enable cleanup on uninstall:
 * - define('WPI_REMOVE_DATA_ON_UNINSTALL', true);
 *   or filter `wpi_remove_data_on_uninstall` to true.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
  exit;
}

$remove_data = defined('WPI_REMOVE_DATA_ON_UNINSTALL') ? (bool) WPI_REMOVE_DATA_ON_UNINSTALL : false;
$remove_data = (bool) apply_filters('wpi_remove_data_on_uninstall', $remove_data);

if (! $remove_data) {
  return;
}

$options_to_delete = [
  'ai_composer_settings',
  'wpi_modules',
  'wpi_security',
  'wpi_performance',
  'wpi_woocommerce',
  'wpi_resource_hints',
  'block_visibility_settings',
];

foreach ($options_to_delete as $option_name) {
  delete_option($option_name);
  delete_site_option($option_name);
}

delete_transient('wpi_welcome_redirect');
delete_site_transient('wpi_welcome_redirect');
