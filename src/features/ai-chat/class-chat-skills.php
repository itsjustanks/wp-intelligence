<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Manages configurable chat skills (preset prompts / quick actions).
 *
 * Skills are stored in ai_composer_settings['chat_skills'] and can be
 * customized via the settings UI or the ai_composer_chat_skills filter.
 *
 * Themes and plugins can add skills:
 *
 *   add_filter('ai_composer_chat_skills', function (array $skills): array {
 *     $skills[] = [
 *       'id'     => 'theme-layout-help',
 *       'icon'   => 'layout',
 *       'label'  => 'Help me build a layout',
 *       'prompt' => 'Help me build a page layout using the available blocks...',
 *     ];
 *     return $skills;
 *   });
 *
 * Themes and plugins can also add tools:
 *
 *   add_filter('ai_composer_chat_tools', function (array $tools): array {
 *     $tools[] = [
 *       'type' => 'function',
 *       'name' => 'get_theme_options',
 *       'description' => 'Get the current theme options and customizer settings.',
 *       'parameters' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
 *       'strict' => true,
 *     ];
 *     return $tools;
 *   });
 *
 *   add_filter('ai_composer_chat_tool_execute', function (string $result, string $name, array $args): string {
 *     if ($name === 'get_theme_options') {
 *       return wp_json_encode(get_theme_mods());
 *     }
 *     return $result;
 *   }, 10, 3);
 */
class WPI_Chat_Skills {

  private const SETTINGS_KEY = 'ai_composer_settings';

  public static function get_defaults(): array {
    return [
      [
        'id'    => 'blog-post',
        'icon'  => 'edit',
        'label' => __('Help me write a blog post', 'wp-intelligence'),
        'prompt' => 'Help me write a blog post. Ask me what topic I want to write about, who the audience is, and the desired tone. Then draft an outline first before writing.',
      ],
      [
        'id'    => 'seo-improvements',
        'icon'  => 'search',
        'label' => __('Suggest SEO improvements', 'wp-intelligence'),
        'prompt' => 'Analyse the current page content I\'m editing and suggest specific SEO improvements: title tag, meta description, heading structure, keyword usage, internal linking opportunities, and readability. Be specific and actionable.',
      ],
      [
        'id'    => 'content-ideas',
        'icon'  => 'lightbulb',
        'label' => __('Give me content ideas', 'wp-intelligence'),
        'prompt' => 'Based on this site\'s existing content and brand, suggest 5-10 content ideas. For each idea include a working title, target audience, and a brief description of what the piece should cover. Search existing posts first to avoid duplicates.',
      ],
      [
        'id'    => 'wordpress-tasks',
        'icon'  => 'admin-tools',
        'label' => __('Help with WordPress tasks', 'wp-intelligence'),
        'prompt' => 'I need help with a WordPress task. Ask me what I\'m trying to do and guide me step by step. Use your knowledge of this site\'s setup when relevant.',
      ],
      [
        'id'    => 'rewrite-content',
        'icon'  => 'editor-paste-text',
        'label' => __('Rewrite or improve content', 'wp-intelligence'),
        'prompt' => 'Help me rewrite or improve the content I\'m currently editing. Read the current page content, then ask what aspect I want to improve: clarity, tone, conciseness, engagement, or something else. Suggest specific rewrites.',
      ],
      [
        'id'    => 'summarize-page',
        'icon'  => 'text-page',
        'label' => __('Summarise this page', 'wp-intelligence'),
        'prompt' => 'Read the current page content and provide a concise summary: what the page covers, its key messages, target audience, and any calls to action. Also note anything that could be improved.',
      ],
    ];
  }

  /**
   * Get active skills, merging saved customisations with defaults.
   */
  public static function get_skills(): array {
    $settings = get_option(self::SETTINGS_KEY, []);
    $saved    = is_array($settings['chat_skills'] ?? null) ? $settings['chat_skills'] : null;

    $skills = $saved !== null ? $saved : self::get_defaults();

    return apply_filters('ai_composer_chat_skills', $skills);
  }

  /**
   * Get skills formatted for the frontend (only id, icon, label, prompt).
   */
  public static function get_skills_for_frontend(): array {
    $skills = self::get_skills();

    return array_map(function ($skill) {
      return [
        'id'     => sanitize_key($skill['id'] ?? ''),
        'icon'   => sanitize_key($skill['icon'] ?? 'lightbulb'),
        'label'  => wp_strip_all_tags($skill['label'] ?? ''),
        'prompt' => wp_strip_all_tags($skill['prompt'] ?? ''),
      ];
    }, $skills);
  }

  /**
   * Sanitize skills array from settings input.
   */
  public static function sanitize(array $input): array {
    $clean = [];
    foreach ($input as $skill) {
      if (! is_array($skill) || empty($skill['id']) || empty($skill['label'])) {
        continue;
      }
      $clean[] = [
        'id'     => sanitize_key($skill['id']),
        'icon'   => sanitize_key($skill['icon'] ?? 'lightbulb'),
        'label'  => sanitize_text_field($skill['label']),
        'prompt' => sanitize_textarea_field($skill['prompt'] ?? ''),
      ];
    }
    return $clean;
  }
}
