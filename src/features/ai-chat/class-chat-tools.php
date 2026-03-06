<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Defines and executes WordPress tools that the AI chat can call.
 *
 * Reuses the same tool implementations as the MCP server but exposes
 * them as OpenAI Responses API function tools for agentic chat.
 */
class WPI_Chat_Tools {

  private const MAX_TOOL_ITERATIONS = 5;

  /**
   * Get tool definitions formatted for the OpenAI Responses API.
   */
  public static function get_tool_definitions(): array {
    $tools = [
      [
        'type'     => 'function',
        'name'     => 'search_posts',
        'description' => 'Search published posts and pages on this WordPress site by keyword.',
        'parameters' => [
          'type'       => 'object',
          'properties' => [
            'query' => [
              'type'        => 'string',
              'description' => 'Search query string.',
            ],
            'post_type' => [
              'type'        => 'string',
              'description' => 'Post type to search (post, page, or any). Default: any.',
            ],
            'limit' => [
              'type'        => 'integer',
              'description' => 'Max results to return (1-20). Default: 10.',
            ],
          ],
          'required'             => ['query', 'post_type', 'limit'],
          'additionalProperties' => false,
        ],
        'strict' => true,
      ],
      [
        'type'     => 'function',
        'name'     => 'get_post',
        'description' => 'Get the full content and metadata of a specific post or page by its WordPress ID.',
        'parameters' => [
          'type'       => 'object',
          'properties' => [
            'post_id' => [
              'type'        => 'integer',
              'description' => 'WordPress post ID.',
            ],
          ],
          'required'             => ['post_id'],
          'additionalProperties' => false,
        ],
        'strict' => true,
      ],
      [
        'type'     => 'function',
        'name'     => 'get_site_info',
        'description' => 'Get basic site information: name, URL, tagline, active theme, post types.',
        'parameters' => [
          'type'                 => 'object',
          'properties'           => new \stdClass(),
          'additionalProperties' => false,
        ],
        'strict' => true,
      ],
      [
        'type'     => 'function',
        'name'     => 'get_brand_context',
        'description' => 'Get the brand voice, tone guidelines, and context documents for this site.',
        'parameters' => [
          'type'                 => 'object',
          'properties'           => new \stdClass(),
          'additionalProperties' => false,
        ],
        'strict' => true,
      ],
      [
        'type'     => 'function',
        'name'     => 'read_current_page',
        'description' => 'Read the full content of the page/post the user is currently editing. Only works when the user is in the block editor and has provided page context.',
        'parameters' => [
          'type'                 => 'object',
          'properties'           => new \stdClass(),
          'additionalProperties' => false,
        ],
        'strict' => true,
      ],
      [
        'type'     => 'function',
        'name'     => 'get_available_blocks',
        'description' => 'Get the full list of available WordPress blocks and patterns that can be used on this site, with their attributes and usage information. Use this when the user asks about what blocks exist, how to use a specific block, or needs help building layouts.',
        'parameters' => [
          'type'       => 'object',
          'properties' => [
            'include_patterns' => [
              'type'        => 'boolean',
              'description' => 'Whether to include registered patterns in the response. Default: true.',
            ],
          ],
          'required'             => ['include_patterns'],
          'additionalProperties' => false,
        ],
        'strict' => true,
      ],
    ];

    return apply_filters('ai_composer_chat_tools', $tools);
  }

  /**
   * Execute a tool call and return the result text.
   */
  public static function execute(string $name, array $arguments, array $context = []): string {
    $result = match ($name) {
      'search_posts'       => self::tool_search_posts($arguments),
      'get_post'           => self::tool_get_post($arguments),
      'get_site_info'      => self::tool_get_site_info(),
      'get_brand_context'  => self::tool_get_brand_context(),
      'read_current_page'  => self::tool_read_current_page($context),
      'get_available_blocks' => self::tool_get_available_blocks($arguments),
      default              => self::tool_custom($name, $arguments, $context),
    };

    return $result;
  }

  public static function max_iterations(): int {
    return self::MAX_TOOL_ITERATIONS;
  }

  /* ──────────────────────────────────────────────
   *  Built-in tool implementations
   * ────────────────────────────────────────────── */

  private static function tool_search_posts(array $args): string {
    $query_str = sanitize_text_field($args['query'] ?? '');
    if ($query_str === '') {
      return wp_json_encode(['error' => 'No search query provided.']);
    }

    $post_type = sanitize_key($args['post_type'] ?? 'any');
    $limit     = max(1, min(20, (int) ($args['limit'] ?? 10)));

    $query = new WP_Query([
      's'              => $query_str,
      'post_status'    => 'publish',
      'posts_per_page' => $limit,
      'post_type'      => $post_type === 'any' ? get_post_types(['public' => true]) : $post_type,
      'orderby'        => 'relevance',
    ]);

    $results = [];
    foreach ($query->posts as $post) {
      $results[] = [
        'id'      => $post->ID,
        'title'   => $post->post_title,
        'url'     => get_permalink($post),
        'type'    => $post->post_type,
        'date'    => $post->post_date,
        'excerpt' => wp_trim_words(wp_strip_all_tags($post->post_content), 40),
      ];
    }

    if (empty($results)) {
      return "No results found for \"{$query_str}\".";
    }

    return wp_json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  }

  private static function tool_get_post(array $args): string {
    $post_id = (int) ($args['post_id'] ?? 0);
    if ($post_id <= 0) {
      return wp_json_encode(['error' => 'Invalid post ID.']);
    }

    $post = get_post($post_id);
    if (! $post) {
      return "Post {$post_id} not found.";
    }

    $content = wp_strip_all_tags($post->post_content);
    $content = preg_replace('/\s+/', ' ', $content);

    return wp_json_encode([
      'id'         => $post->ID,
      'title'      => $post->post_title,
      'url'        => get_permalink($post),
      'type'       => $post->post_type,
      'status'     => $post->post_status,
      'date'       => $post->post_date,
      'author'     => get_the_author_meta('display_name', $post->post_author),
      'excerpt'    => $post->post_excerpt ?: wp_trim_words($content, 55),
      'content'    => mb_substr($content, 0, 8000),
      'categories' => wp_get_post_categories($post_id, ['fields' => 'names']),
      'tags'       => wp_get_post_tags($post_id, ['fields' => 'names']),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  }

  private static function tool_get_site_info(): string {
    return wp_json_encode([
      'name'              => get_bloginfo('name'),
      'url'               => home_url(),
      'tagline'           => get_bloginfo('description'),
      'language'          => get_bloginfo('language'),
      'timezone'          => wp_timezone_string(),
      'theme'             => wp_get_theme()->get('Name'),
      'wordpress_version' => get_bloginfo('version'),
      'post_types'        => array_values(get_post_types(['public' => true], 'names')),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  }

  private static function tool_get_brand_context(): string {
    $parts = [];

    if (class_exists('AI_Composer_Context_Provider')) {
      $brand = AI_Composer_Context_Provider::get_brand_voice();
      if ($brand !== '') {
        $parts[] = $brand;
      }

      $ctx = AI_Composer_Context_Provider::get_context();
      if ($ctx !== '' && $ctx !== $brand) {
        $parts[] = $ctx;
      }
    } else {
      $settings = class_exists('AI_Composer_Settings') ? AI_Composer_Settings::get_syndication_settings() : [];
      $brand    = trim((string) ($settings['brand_context'] ?? ''));
      if ($brand !== '') {
        $parts[] = $brand;
      }
    }

    return ! empty($parts) ? implode("\n\n---\n\n", $parts) : 'No brand context is configured for this site.';
  }

  /**
   * Read the page content from the editor context sent by the client.
   */
  private static function tool_read_current_page(array $context): string {
    if (! empty($context['page_content'])) {
      $info = [];
      if (! empty($context['post_title'])) {
        $info[] = "Title: {$context['post_title']}";
      }
      if (! empty($context['post_type'])) {
        $info[] = "Post type: {$context['post_type']}";
      }
      if (! empty($context['block_count'])) {
        $info[] = "Block count: {$context['block_count']}";
      }
      if (! empty($context['selected_block'])) {
        $info[] = "Selected block: " . wp_json_encode($context['selected_block'], JSON_UNESCAPED_SLASHES);
      }
      $info[] = "\n--- Page Content ---\n" . mb_substr($context['page_content'], 0, 12000);

      return implode("\n", $info);
    }

    if (! empty($context['post_id'])) {
      $post_id = (int) $context['post_id'];
      return self::tool_get_post(['post_id' => $post_id]);
    }

    return 'No page content is available. The user may not be in the editor.';
  }

  /**
   * Return available blocks and patterns from the composer catalogs.
   */
  private static function tool_get_available_blocks(array $args): string {
    $parts = [];

    if (class_exists('AI_Composer_Block_Catalog')) {
      $catalog = new AI_Composer_Block_Catalog();
      $parts[] = $catalog->to_prompt_text([
        'max_blocks'              => 150,
        'max_attributes_per_block' => 6,
        'include_descriptions'    => true,
      ]);
    } else {
      $registry = WP_Block_Type_Registry::get_instance();
      $blocks   = [];
      foreach ($registry->get_all_registered() as $type) {
        $blocks[] = $type->name . ' (' . ($type->title ?: $type->name) . ')';
      }
      $parts[] = "## Available Blocks\n\n" . implode("\n", array_map(fn($b) => "- {$b}", $blocks));
    }

    $include_patterns = ($args['include_patterns'] ?? true) !== false;
    if ($include_patterns && class_exists('AI_Composer_Pattern_Catalog')) {
      $catalog = new AI_Composer_Pattern_Catalog();
      $parts[] = $catalog->to_prompt_text(['max_patterns' => 50]);
    }

    return implode("\n\n", $parts);
  }

  /**
   * Fallback for custom tools added via the ai_composer_chat_tools filter.
   */
  private static function tool_custom(string $name, array $arguments, array $context): string {
    $result = apply_filters('ai_composer_chat_tool_execute', '', $name, $arguments, $context);
    return is_string($result) && $result !== '' ? $result : "Tool '{$name}' is not implemented.";
  }
}
