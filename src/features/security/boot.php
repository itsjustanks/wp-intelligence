<?php
if (! defined('ABSPATH')) {
  exit;
}

require_once __DIR__ . '/class-security.php';

WPI_Module_Manager::register('security', [
  'title'       => __('Security Hardening', 'wp-intelligence'),
  'description' => __('Disable feeds, strip version info, remove header metadata, disable file editor.', 'wp-intelligence'),
  'category'    => __('Site Health', 'wp-intelligence'),
  'icon'        => 'shield',
  'boot'        => ['WPI_Security', 'boot'],
  'default'     => false,
]);
