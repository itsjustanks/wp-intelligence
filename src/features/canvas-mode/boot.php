<?php
if (! defined('ABSPATH')) {
  exit;
}

require_once __DIR__ . '/class-canvas-mode.php';

WPI_Module_Manager::register('canvas_mode', [
  'title'       => __('Canvas Mode', 'wp-intelligence'),
  'description' => __('Figma-style multi-viewport canvas for the WordPress block editor.', 'wp-intelligence'),
  'category'    => __('Editor', 'wp-intelligence'),
  'icon'        => 'grid-view',
  'boot'        => ['WPI_Canvas_Mode', 'boot'],
  'default'     => true,
]);
