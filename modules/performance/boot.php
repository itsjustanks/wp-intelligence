<?php
if (! defined('ABSPATH')) {
  exit;
}

require_once __DIR__ . '/class-performance.php';

WPI_Module_Manager::register('performance', [
  'title'       => __('Performance', 'wp-intelligence'),
  'description' => __('WordPress performance constants and HTML output compression.', 'wp-intelligence'),
  'category'    => __('Site Health', 'wp-intelligence'),
  'icon'        => 'performance',
  'boot'        => ['WPI_Performance', 'boot'],
  'default'     => false,
]);
