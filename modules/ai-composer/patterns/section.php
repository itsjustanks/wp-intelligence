<?php
if (! defined('ABSPATH')) {
  exit;
}

return [
  'name'        => 'ai-composer/section',
  'title'       => __('WP Intelligence: Section', 'wp-intelligence'),
  'description' => __('Generic section scaffold with a dedicated AI content slot.', 'wp-intelligence'),
  'categories'  => ['ai-composer-layouts'],
  'keywords'    => ['section', 'layout', 'wrapper', 'generic'],
  'inserter'    => false,
  'content'     => <<<'HTML'
<!-- wp:group {"align":"wide","layout":{"type":"constrained"},"className":"ai-composer-section"} -->
<div class="wp-block-group alignwide ai-composer-section"><!-- wp:group {"className":"ai-composer-slot"} -->
<div class="wp-block-group ai-composer-slot"></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
HTML,
];
