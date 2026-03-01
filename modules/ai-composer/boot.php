<?php
if (! defined('ABSPATH')) {
  exit;
}

if (! defined('AI_COMPOSER_DIR')) {
  define('AI_COMPOSER_DIR', __DIR__);
}
if (! defined('AI_COMPOSER_URL')) {
  $ai_composer_url = defined('WPI_URL') ? WPI_URL . 'modules/ai-composer/' : '';
  define('AI_COMPOSER_URL', $ai_composer_url);
}

$dir = __DIR__;

require_once $dir . '/class-block-catalog.php';
require_once $dir . '/class-pattern-catalog.php';
require_once $dir . '/class-prompt-engine.php';
require_once $dir . '/class-provider.php';
require_once $dir . '/class-manifest-compiler.php';
require_once $dir . '/class-rest-controller.php';
require_once $dir . '/class-abilities-bridge.php';
require_once $dir . '/class-ai-composer.php';

WPI_Module_Manager::register('ai_composer', [
  'title'       => __('AI Composer', 'wp-intelligence'),
  'description' => __('AI-powered page composition using your site\'s registered blocks and patterns.', 'wp-intelligence'),
  'category'    => __('AI & Content', 'wp-intelligence'),
  'icon'        => 'admin-customizer',
  'boot'        => ['AI_Composer', 'init'],
  'default'     => true,
]);
