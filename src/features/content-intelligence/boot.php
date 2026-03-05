<?php
if (! defined('ABSPATH')) {
  exit;
}

require_once __DIR__ . '/class-syndication.php';

WPI_Module_Manager::register('syndication', [
  'title'       => __('Content Intelligence', 'wp-intelligence'),
  'description' => __('Generate content from URLs, videos, text, and files with AI-powered rewriting, source citations, and ACF field mapping.', 'wp-intelligence'),
  'category'    => __('AI & Content', 'wp-intelligence'),
  'icon'        => 'welcome-write-blog',
  'boot'        => ['AI_Composer_Syndication', 'boot'],
  'default'     => true,
]);
