<?php
if (! defined('ABSPATH')) {
  exit;
}

// Only available when Nectar row/column blocks are registered.
if (
  ! class_exists('WP_Block_Type_Registry') ||
  ! WP_Block_Type_Registry::get_instance()->is_registered('nectar-blocks/row') ||
  ! WP_Block_Type_Registry::get_instance()->is_registered('nectar-blocks/column')
) {
  return [];
}

return [
  'name'        => 'ai-composer/nectar-section',
  'title'       => __('WP Intelligence: Nectar Section', 'wp-intelligence'),
  'description' => __('Nectar row/column section scaffold with a dedicated AI content slot.', 'wp-intelligence'),
  'categories'  => ['ai-composer-layouts'],
  'keywords'    => ['nectar', 'section', 'row', 'column'],
  'inserter'    => false,
  'content'     => <<<'HTML'
<!-- wp:nectar-blocks/row {"align":"full","containedContentWidth":true,"className":"ai-composer-section"} -->
<!-- wp:nectar-blocks/column {"columnSettings":{"desktop":{"width":"100.00%"}},"className":"ai-composer-slot"} -->
<!-- wp:nectar-blocks/text -->
<p class="wp-block-nectar-blocks-text nectar-blocks-text nectar-block"></p>
<!-- /wp:nectar-blocks/text -->
<!-- /wp:nectar-blocks/column -->
<!-- /wp:nectar-blocks/row -->
HTML,
];
