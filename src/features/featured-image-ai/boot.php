<?php
if (! defined('ABSPATH')) {
  exit;
}

require_once __DIR__ . '/class-image-provider.php';
require_once __DIR__ . '/class-featured-image-ai.php';

WPI_Module_Manager::register('featured_image_ai', [
  'title'       => __('Featured Image AI', 'wp-intelligence'),
  'description' => __('Automatically generate featured images using AI when publishing posts without one.', 'wp-intelligence'),
  'category'    => __('AI & Content', 'wp-intelligence'),
  'icon'        => 'format-image',
  'boot'        => ['WPI_Featured_Image_AI', 'boot'],
  'default'     => true,
]);
