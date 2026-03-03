<?php
if (! defined('ABSPATH')) {
  exit;
}

require_once __DIR__ . '/class-admin-customizer.php';

WPI_Module_Manager::register('admin_experience', [
  'title'       => __('Admin Experience', 'wp-intelligence'),
  'description' => __('White-label admin bar, login page, footer, menu reorganization, and editor enhancements.', 'wp-intelligence'),
  'category'    => __('Admin', 'wp-intelligence'),
  'icon'        => 'admin-appearance',
  'boot'        => ['AI_Composer_Admin_Customizer', 'boot'],
  'default'     => true,
]);
