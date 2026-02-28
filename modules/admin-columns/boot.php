<?php
if (! defined('ABSPATH')) {
  exit;
}

require_once __DIR__ . '/class-admin-columns.php';

WPI_Module_Manager::register('admin_columns', [
  'title'       => __('Admin Columns & Post Type Switcher', 'wp-intelligence'),
  'description' => __('Adds permalink and template columns to post list tables, and a post type switcher metabox.', 'wp-intelligence'),
  'icon'        => 'editor-table',
  'boot'        => ['WP_Intelligence_Admin_Columns', 'init'],
  'default'     => true,
]);
