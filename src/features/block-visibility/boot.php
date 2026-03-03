<?php
/**
 * Block Visibility module bootstrap.
 *
 * Provides visibility controls and scheduling functionality to all WordPress blocks.
 * Adapted from the standalone Block Visibility plugin (v3.7.1) by Nick Diego.
 *
 * @package wp-intelligence
 * @since   0.3.0
 */

if (! defined('ABSPATH')) {
  exit;
}

require_once __DIR__ . '/class-block-visibility.php';

// Module-level path constants.
if (! defined('WPI_BV_DIR')) {
  define('WPI_BV_DIR', __DIR__);
}

if (! defined('BLOCK_VISIBILITY_VERSION')) {
  define('BLOCK_VISIBILITY_VERSION', '3.7.1');
}
if (! defined('BLOCK_VISIBILITY_SETTINGS_URL')) {
  define('BLOCK_VISIBILITY_SETTINGS_URL', admin_url('admin.php?page=wp-intelligence&tab=visibility'));
}

WPI_Module_Manager::register('block_visibility', [
  'title'       => __('Block Visibility', 'wp-intelligence'),
  'description' => __('Conditional visibility controls and scheduling for all WordPress blocks.', 'wp-intelligence'),
  'category'    => 'Editor',
  'icon'        => 'visibility',
  'default'     => true,
  'boot'        => function () {
    // URL must be resolved after WPI_URL is available.
    if (! defined('WPI_BV_URL')) {
      define('WPI_BV_URL', WPI_URL . 'src/features/block-visibility/');
    }

    $module = new WPI_Block_Visibility();
    $module->init();
  },
]);
