<?php
if (! defined('ABSPATH')) {
  exit;
}

require_once __DIR__ . '/class-html-canvas.php';

WPI_Module_Manager::register('html_canvas', [
  'title'       => __('HTML Canvas', 'wp-intelligence'),
  'description' => __('Full HTML/CSS/JS block with a live sandboxed preview — ideal for AI-composed pages.', 'wp-intelligence'),
  'category'    => __('Editor', 'wp-intelligence'),
  'icon'        => 'editor-code',
  'boot'        => ['WPI_HTML_Canvas', 'boot'],
  'default'     => true,
]);
