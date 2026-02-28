<?php
if (! defined('ABSPATH')) {
  exit;
}

require_once __DIR__ . '/class-syndication.php';

WPI_Module_Manager::register('syndication', [
  'title'       => __('Content Syndication', 'wp-intelligence'),
  'description' => __('Fetch external articles, AI-rewrite them, and publish as native posts.', 'wp-intelligence'),
  'category'    => __('AI & Content', 'wp-intelligence'),
  'icon'        => 'rss',
  'boot'        => ['AI_Composer_Syndication', 'boot'],
  'default'     => true,
]);
