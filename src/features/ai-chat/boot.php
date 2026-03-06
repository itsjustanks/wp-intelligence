<?php
if (! defined('ABSPATH')) {
  exit;
}

require_once __DIR__ . '/class-chat-storage.php';
require_once __DIR__ . '/class-chat-skills.php';
require_once __DIR__ . '/class-chat-tools.php';
require_once __DIR__ . '/class-chat-handler.php';
require_once __DIR__ . '/class-ai-chat.php';

WPI_Module_Manager::register('ai_chat', [
  'title'       => __('AI Chat', 'wp-intelligence'),
  'description' => __('Admin-wide AI chat assistant with multi-turn conversations, page context awareness, and saved history.', 'wp-intelligence'),
  'category'    => __('AI & Content', 'wp-intelligence'),
  'icon'        => 'format-chat',
  'boot'        => ['WPI_AI_Chat', 'boot'],
  'activate'    => ['WPI_AI_Chat', 'activate'],
  'default'     => true,
]);
