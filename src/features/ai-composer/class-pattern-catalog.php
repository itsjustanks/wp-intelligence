<?php
if (! defined('ABSPATH')) {
  exit;
}

class AI_Composer_Pattern_Catalog {

  private ?array $catalog = null;

  public function discover(): array {
    if ($this->catalog !== null) {
      return $this->catalog;
    }

    $catalog = [];

    // 1) Existing registered WP patterns.
    if (class_exists('WP_Block_Patterns_Registry')) {
      $registry = WP_Block_Patterns_Registry::get_instance();
      $all      = $registry->get_all_registered();

      foreach ($all as $pattern) {
        $name = $pattern['name'] ?? '';
        if ($name === '') {
          continue;
        }

        $catalog[$name] = [
          'name'        => $name,
          'title'       => $pattern['title'] ?? $name,
          'description' => $pattern['description'] ?? '',
          'categories'  => $pattern['categories'] ?? [],
          'keywords'    => $pattern['keywords'] ?? [],
          'content'     => $pattern['content'] ?? '',
          'source'      => 'wp-registry',
        ];
      }
    }

    // 2) Built-in AI Composer patterns from src/ai-composer/patterns/*.php
    //    These can be used even if not registered in block inserter.
    foreach ($this->load_builtin_patterns() as $name => $pattern) {
      // Do not overwrite explicit registered patterns.
      if (isset($catalog[$name])) {
        continue;
      }
      $catalog[$name] = $pattern;
    }

    $this->catalog = apply_filters('ai_composer_pattern_catalog', $catalog);

    return $this->catalog;
  }

  public function get_pattern(string $name): ?array {
    $catalog = $this->discover();
    return $catalog[$name] ?? null;
  }

  public function pattern_exists(string $name): bool {
    return $this->get_pattern($name) !== null;
  }

  /**
   * Compile the catalog into a compact text format for the system prompt.
   * Pattern content is NOT included (too large); only metadata.
   */
  public function to_prompt_text(array $options = []): string {
    $defaults = [
      'max_patterns'          => 40,
      'include_descriptions'  => true,
      'description_max_chars' => 120,
      'include_categories'    => true,
    ];

    $options = wp_parse_args(
      $options,
      apply_filters('ai_composer_prompt_pattern_catalog_config', $defaults, $options)
    );

    $catalog = $this->discover();
    if (empty($catalog)) {
      return '';
    }

    $patterns = array_values($catalog);
    usort($patterns, static function (array $a, array $b): int {
      $a_name = (string) ($a['name'] ?? '');
      $b_name = (string) ($b['name'] ?? '');
      return strcmp($a_name, $b_name);
    });

    $max_patterns = max(1, absint((string) $options['max_patterns']));
    $patterns = array_slice($patterns, 0, $max_patterns);

    $lines = ["## Available Patterns
"];
    $lines[] = 'Use a pattern by setting `"blockType": "pattern"` and `"patternSlug": "<slug>"` in your manifest.';
    $lines[] = "Patterns are pre-built layouts that will be resolved to block grammar automatically.
";

    foreach ($patterns as $pattern) {
      $line = '- **' . $pattern['name'] . '**';
      if ($pattern['title'] && $pattern['title'] !== $pattern['name']) {
        $line .= ' — ' . $pattern['title'];
      }
      if (! empty($options['include_descriptions']) && ! empty($pattern['description'])) {
        $line .= ': ' . $this->truncate_text((string) $pattern['description'], (int) $options['description_max_chars']);
      }
      if (! empty($options['include_categories']) && ! empty($pattern['categories'])) {
        $line .= ' [' . implode(', ', $pattern['categories']) . ']';
      }
      $lines[] = $line;
    }

    if (count($catalog) > $max_patterns) {
      $lines[] = '';
      $lines[] = '- … plus ' . (count($catalog) - $max_patterns) . ' additional patterns omitted for brevity.';
    }

    return implode("\n", $lines);
  }

  private function truncate_text(string $text, int $max_chars): string {
    $text = trim($text);
    if ($max_chars <= 0 || $text === '') {
      return $text;
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
      if (mb_strlen($text) <= $max_chars) {
        return $text;
      }
      return rtrim(mb_substr($text, 0, $max_chars - 1)) . '…';
    }

    if (strlen($text) <= $max_chars) {
      return $text;
    }

    return rtrim(substr($text, 0, $max_chars - 1)) . '…';
  }

  /**
   * @return array<string,array<string,mixed>>
   */
  private function load_builtin_patterns(): array {
    $patterns = [];

    $dir = rtrim(AI_COMPOSER_DIR, '/\\\\') . '/patterns';
    if (! is_dir($dir)) {
      return $patterns;
    }

    $files = glob($dir . '/*.php') ?: [];
    sort($files, SORT_STRING);

    foreach ($files as $file) {
      if (! is_file($file)) {
        continue;
      }

      $slug = sanitize_title(pathinfo($file, PATHINFO_FILENAME));
      if ($slug === '') {
        continue;
      }

      $raw = include $file;
      $normalized = $this->normalize_builtin_definition($raw, $slug);
      if ($normalized === null) {
        continue;
      }

      $patterns[$normalized['name']] = $normalized;
    }

    return $patterns;
  }

  /**
   * @param mixed $raw
   * @return array<string,mixed>|null
   */
  private function normalize_builtin_definition(mixed $raw, string $slug): ?array {
    $defaults = [
      'name'        => 'ai-composer/' . $slug,
      'title'       => ucwords(str_replace(['-', '_'], ' ', $slug)),
      'description' => '',
      'categories'  => ['ai-composer-layouts'],
      'keywords'    => [],
      'inserter'    => false,
      'content'     => '',
      'source'      => 'wp-intelligence',
    ];

    if (is_string($raw)) {
      $defaults['content'] = $raw;
      return $defaults;
    }

    if (! is_array($raw)) {
      return null;
    }

    $pattern = array_merge($defaults, $raw);

    if (! is_string($pattern['name']) || trim($pattern['name']) === '') {
      $pattern['name'] = $defaults['name'];
    }

    if (! is_string($pattern['content']) || trim($pattern['content']) === '') {
      return null;
    }

    if (! is_array($pattern['categories'])) {
      $pattern['categories'] = $defaults['categories'];
    }

    if (! is_array($pattern['keywords'])) {
      $pattern['keywords'] = [];
    }

    return $pattern;
  }
}
