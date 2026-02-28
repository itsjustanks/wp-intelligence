<?php
if (! defined('ABSPATH')) {
  exit;
}

require_once __DIR__ . '/class-code-injection.php';

WPI_Module_Manager::register('code_injection', [
  'title'       => __('Sitewide Code Injection', 'wp-intelligence'),
  'description' => __('Customizer controls for adding HTML/JS to the site header and footer.', 'wp-intelligence'),
  'category'    => __('Developer Tools', 'wp-intelligence'),
  'icon'        => 'editor-code',
  'boot'        => ['WPI_Code_Injection', 'boot'],
  'default'     => true,
]);
