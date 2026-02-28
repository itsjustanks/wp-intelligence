<?php
if (! defined('ABSPATH')) {
  exit;
}

require_once __DIR__ . '/class-resource-hints.php';

WPI_Module_Manager::register('resource_hints', [
  'title'       => __('Resource Hints', 'wp-intelligence'),
  'description' => __('Adds preconnect and dns-prefetch hints for CDN and external domains.', 'wp-intelligence'),
  'category'    => __('Site Health', 'wp-intelligence'),
  'icon'        => 'performance',
  'boot'        => ['WPI_Resource_Hints', 'boot'],
  'default'     => false,
]);
