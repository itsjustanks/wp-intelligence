<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Registers AI Composer capabilities with the WordPress Abilities API (6.9+ / 7.0+).
 * Degrades gracefully when the Abilities API is not available.
 */
class AI_Composer_Abilities_Bridge {

  public static function maybe_register(): void {
    if (! function_exists('wp_register_ability')) {
      return;
    }

    add_action('wp_abilities_api_init', [self::class, 'register']);
  }

  public static function register(): void {
    wp_register_ability('ai-composer/compose-page', [
      'label'       => __('Compose Page from Prompt', 'wp-intelligence'),
      'description' => __('Takes a natural-language description and composes a page using the site\'s registered blocks and patterns. Returns serialized block grammar ready for insertion into the editor.', 'wp-intelligence'),
      'category'    => 'content',
      'input_schema' => [
        'type'       => 'object',
        'properties' => [
          'prompt' => [
            'type'        => 'string',
            'description' => 'Natural-language description of the desired page or section.',
          ],
          'template' => [
            'type'        => 'string',
            'description' => 'Optional page template slug to constrain the composition.',
          ],
        ],
        'required' => ['prompt'],
      ],
      'output_schema' => [
        'type'       => 'object',
        'properties' => [
          'blocks' => [
            'type'        => 'string',
            'description' => 'Serialized WordPress block grammar.',
          ],
          'summary' => [
            'type'        => 'string',
            'description' => 'Human-readable summary of the composition.',
          ],
        ],
      ],
      'execute_callback'    => [self::class, 'execute_compose'],
      'permission_callback' => function () {
        return current_user_can(apply_filters('ai_composer_capability', 'edit_posts'));
      },
      'meta' => [
        'show_in_rest' => true,
        'annotations'  => [
          'readonly'    => false,
          'destructive' => false,
          'idempotent'  => false,
        ],
      ],
    ]);

    wp_register_ability('ai-composer/list-blocks', [
      'label'       => __('List Composable Blocks', 'wp-intelligence'),
      'description' => __('Returns the list of blocks available for AI composition on this site.', 'wp-intelligence'),
      'category'    => 'content',
      'input_schema'  => [],
      'output_schema' => [
        'type'  => 'array',
        'items' => [
          'type'       => 'object',
          'properties' => [
            'name'        => ['type' => 'string'],
            'title'       => ['type' => 'string'],
            'description' => ['type' => 'string'],
          ],
        ],
      ],
      'execute_callback'    => [self::class, 'execute_list_blocks'],
      'permission_callback' => function () {
        return current_user_can(apply_filters('ai_composer_capability', 'edit_posts'));
      },
      'meta' => [
        'show_in_rest' => true,
        'annotations'  => [
          'readonly'   => true,
          'destructive' => false,
          'idempotent'  => true,
        ],
      ],
    ]);
  }

  public static function execute_compose(array $input): array|WP_Error {
    $composer = AI_Composer::get_instance();
    $result   = $composer->compose($input['prompt'], $input);

    if (is_wp_error($result)) {
      return $result;
    }

    return [
      'blocks'  => $result['blocks'],
      'summary' => $result['summary'],
    ];
  }

  public static function execute_list_blocks(): array {
    $composer = AI_Composer::get_instance();
    $blocks   = $composer->blocks()->get_composable();

    return array_values(array_map(function ($block) {
      return [
        'name'        => $block['name'],
        'title'       => $block['title'],
        'description' => $block['description'],
      ];
    }, $blocks));
  }
}
