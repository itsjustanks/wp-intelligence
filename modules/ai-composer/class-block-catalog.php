<?php
if (! defined('ABSPATH')) {
  exit;
}

class AI_Composer_Block_Catalog {

  private ?array $catalog = null;

  /**
   * Block types that are internal / not useful for page composition.
   */
  public const EXCLUDED_BLOCKS = [
    'core/missing',
    'core/block',
    'core/freeform',
    'core/html',
    'core/more',
    'core/nextpage',
    'core/shortcode',
    'core/legacy-widget',
    'core/widget-group',
    'core/archives',
    'core/calendar',
    'core/categories',
    'core/latest-comments',
    'core/latest-posts',
    'core/rss',
    'core/search',
    'core/tag-cloud',
    'core/page-list',
    'core/loginout',
    'core/post-template',
    'core/query-pagination',
    'core/query-pagination-next',
    'core/query-pagination-numbers',
    'core/query-pagination-previous',
    'core/query-no-results',
    'core/query-title',
    'core/template-part',
    'core/site-title',
    'core/site-tagline',
    'core/site-logo',
    'core/navigation',
    'core/navigation-link',
    'core/navigation-submenu',
    'core/post-title',
    'core/post-content',
    'core/post-date',
    'core/post-excerpt',
    'core/post-featured-image',
    'core/post-terms',
    'core/post-author',
    'core/post-author-name',
    'core/post-navigation-link',
    'core/post-comments-form',
    'core/comments',
    'core/comment-author-name',
    'core/comment-content',
    'core/comment-date',
    'core/comment-edit-link',
    'core/comment-reply-link',
    'core/comment-template',
    'core/avatar',
    'core/home-link',
    'core/footnotes',
  ];

  public function discover(): array {
    if ($this->catalog !== null) {
      return $this->catalog;
    }

    $registry = WP_Block_Type_Registry::get_instance();
    $all      = $registry->get_all_registered();
    $catalog  = [];

    foreach ($all as $name => $block_type) {
      if (in_array($name, self::EXCLUDED_BLOCKS, true)) {
        continue;
      }

      $entry = [
        'name'        => $name,
        'title'       => $block_type->title ?: $name,
        'description' => $block_type->description ?: '',
        'category'    => $block_type->category ?: 'common',
        'supports'    => $this->extract_supports($block_type),
        'attributes'  => $this->extract_attributes($block_type),
        'parent'      => $block_type->parent ?: null,
      ];

      $catalog[$name] = $entry;
    }

    $this->catalog = apply_filters('ai_composer_block_catalog', $catalog);

    return $this->catalog;
  }

  /**
   * Blocks with a parent constraint that should still appear in the composable
   * catalog because the AI needs them as children of their parent blocks.
   */
  private const COMPOSABLE_CHILD_BLOCKS = [
    'core/column',
    'core/list-item',
    'core/button',
    'core/navigation-link',
  ];

  public function get_composable(): array {
    $all = $this->discover();

    $enabled = AI_Composer_Settings::get_enabled_blocks();
    $allowed_children = apply_filters('ai_composer_composable_child_blocks', self::COMPOSABLE_CHILD_BLOCKS);

    $filtered = array_filter($all, function ($block) use ($enabled, $allowed_children) {
      if (! empty($block['parent']) && ! in_array($block['name'], $allowed_children, true)) {
        return false;
      }
      if (! empty($enabled) && ! in_array($block['name'], $enabled, true)) {
        return false;
      }
      return true;
    });

    return apply_filters('ai_composer_composable_blocks', $filtered);
  }

  public function block_exists(string $name): bool {
    return WP_Block_Type_Registry::get_instance()->is_registered($name);
  }

  /**
   * Compile the catalog into a compact text format suitable for a system prompt.
   */
  public function to_prompt_text(): string {
    $composable = $this->get_composable();
    if (empty($composable)) {
      return '';
    }

    $lines = ["## Available Blocks\n"];

    foreach ($composable as $block) {
      $line = '- **' . $block['name'] . '**';
      if ($block['title'] && $block['title'] !== $block['name']) {
        $line .= ' (' . $block['title'] . ')';
      }
      if ($block['description']) {
        $line .= ': ' . $block['description'];
      }

      $attrs = $block['attributes'] ?? [];
      if (! empty($attrs)) {
        $attr_names = array_keys($attrs);
        $display    = array_slice($attr_names, 0, 8);
        $line      .= ' — attrs: ' . implode(', ', $display);
        if (count($attr_names) > 8) {
          $line .= ', …';
        }
      }

      if (! empty($block['supports']['innerBlocks'])) {
        $line .= ' [supports innerBlocks]';
      }

      $lines[] = $line;
    }

    return implode("\n", $lines);
  }

  private function extract_supports(WP_Block_Type $block_type): array {
    $supports = $block_type->supports ?? [];
    return [
      'innerBlocks' => ! empty($supports['innerBlocks']) || $block_type->name === 'core/group' || $block_type->name === 'core/columns' || $block_type->name === 'core/column',
      'align'       => ! empty($supports['align']),
      'color'       => ! empty($supports['color']),
      'typography'  => ! empty($supports['typography']),
    ];
  }

  private function extract_attributes(WP_Block_Type $block_type): array {
    $raw   = $block_type->attributes ?? [];
    $attrs = [];

    foreach ($raw as $key => $definition) {
      if (str_starts_with($key, '_')) {
        continue;
      }
      $attrs[$key] = [
        'type' => $definition['type'] ?? 'string',
      ];
      if (isset($definition['default'])) {
        $attrs[$key]['default'] = $definition['default'];
      }
      if (isset($definition['enum'])) {
        $attrs[$key]['enum'] = $definition['enum'];
      }
    }

    return $attrs;
  }
}
