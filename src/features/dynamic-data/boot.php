<?php
if (! defined('ABSPATH')) {
  exit;
}

require_once __DIR__ . '/class-merge-tag-engine.php';
require_once __DIR__ . '/class-data-source-registry.php';
require_once __DIR__ . '/sources/class-wordpress-source.php';
require_once __DIR__ . '/sources/class-url-params-source.php';
require_once __DIR__ . '/sources/class-cookie-source.php';
require_once __DIR__ . '/sources/class-webhook-source.php';
require_once __DIR__ . '/sources/class-storage-source.php';
require_once __DIR__ . '/class-rest-controller.php';
require_once __DIR__ . '/class-visibility-integration.php';
require_once __DIR__ . '/class-dynamic-data.php';

WPI_Module_Manager::register('dynamic_data', [
  'title'       => __('Dynamic Data', 'wp-intelligence'),
  'description' => __('Pre-fetch data from webhooks and APIs, use merge tags in block content, and control visibility with external data.', 'wp-intelligence'),
  'category'    => __('AI & Content', 'wp-intelligence'),
  'icon'        => 'database',
  'boot'        => ['WPI_Dynamic_Data', 'boot'],
  'default'     => true,
]);
