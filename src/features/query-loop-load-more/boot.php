<?php
if (! defined('ABSPATH')) {
  exit;
}

require_once __DIR__ . '/class-query-loop-load-more.php';

WPI_Module_Manager::register('query_loop_load_more', [
  'title'       => __('Query Loop Load More', 'wp-intelligence'),
  'description' => __('Adds a load more button and infinite scroll option to the Query Loop pagination block.', 'wp-intelligence'),
  'category'    => __('Editor', 'wp-intelligence'),
  'icon'        => 'controls-repeat',
  'boot'        => ['WPI_Query_Loop_Load_More', 'boot'],
  'default'     => true,
]);
