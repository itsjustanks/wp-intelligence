<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Merge Tag Engine — parses and resolves {{source.field.path}} tags in content.
 *
 * Syntax:
 *   {{source_name.field.path}}           — resolve from a named data source
 *   {{source_name.field.path|fallback}}  — use fallback if value is empty
 *
 * Built-in source prefixes:
 *   wp.*      — WordPress data (post, user, site, meta)
 *   url.*     — URL query parameters
 *   cookie.*  — Browser cookie values
 *   *         — Any registered webhook/API data source name
 */
class WPI_Merge_Tag_Engine {

  private const TAG_PATTERN = '/\{\{([a-zA-Z0-9_]+(?:\.[a-zA-Z0-9_\-]+)*)(?:\|([^}]*))?\}\}/';

  private const MAX_REPLACEMENTS = 500;
  private const MAX_DEPTH = 10;

  /**
   * Resolve all merge tags in a string using the registered data sources.
   *
   * @param string $content      The content with merge tags.
   * @param array  $context      Optional context (e.g. post_id, user_id).
   * @param bool   $escape_html  Whether to escape resolved values for HTML output.
   * @return string              Content with merge tags resolved.
   */
  public static function resolve(string $content, array $context = [], bool $escape_html = true): string {
    if (strpos($content, '{{') === false) {
      return $content;
    }

    $count = 0;

    $resolved = preg_replace_callback(
      self::TAG_PATTERN,
      function ($matches) use ($context, $escape_html, &$count) {
        if (++$count > self::MAX_REPLACEMENTS) {
          return $matches[0];
        }

        $tag_path = $matches[1];
        $fallback = $matches[2] ?? '';

        $value = self::resolve_tag($tag_path, $context);

        if ($value === null || $value === '') {
          $value = $fallback;
        }

        if (is_array($value) || is_object($value)) {
          $value = wp_json_encode($value);
        }

        $value = (string) $value;

        return $escape_html ? esc_html($value) : $value;
      },
      $content
    );

    return $resolved ?? $content;
  }

  /**
   * Resolve a single tag path like "webhook_name.data.user.name".
   *
   * @param string $tag_path Dot-separated path.
   * @param array  $context  Request context.
   * @return mixed|null
   */
  public static function resolve_tag(string $tag_path, array $context = []) {
    $parts = explode('.', $tag_path, 2);
    $source_name = $parts[0];
    $field_path  = $parts[1] ?? '';

    $registry = WPI_Data_Source_Registry::instance();
    $source   = $registry->get($source_name);

    if ($source === null) {
      return apply_filters('wpi_dynamic_data_resolve_tag', null, $tag_path, $context);
    }

    $data = $source->fetch($context);

    if (is_wp_error($data)) {
      return null;
    }

    if ($field_path === '') {
      return $data;
    }

    return self::get_nested_value($data, $field_path);
  }

  /**
   * Extract a nested value using a dot-separated path.
   *
   * @param mixed  $data  The data structure (array or object).
   * @param string $path  Dot-separated field path.
   * @return mixed|null
   */
  public static function get_nested_value($data, string $path) {
    $keys  = explode('.', $path);
    $value = $data;
    $depth = 0;

    foreach ($keys as $key) {
      if (++$depth > self::MAX_DEPTH) {
        return null;
      }

      if (is_array($value) && array_key_exists($key, $value)) {
        $value = $value[$key];
      } elseif (is_object($value) && property_exists($value, $key)) {
        $value = $value->$key;
      } else {
        return null;
      }
    }

    return $value;
  }

  /**
   * Find all merge tags in a string.
   *
   * @param string $content The content to scan.
   * @return array Array of ['tag' => full tag, 'path' => tag path, 'fallback' => fallback value].
   */
  public static function find_tags(string $content): array {
    if (strpos($content, '{{') === false) {
      return [];
    }

    $tags = [];
    if (preg_match_all(self::TAG_PATTERN, $content, $matches, PREG_SET_ORDER)) {
      foreach ($matches as $match) {
        $tags[] = [
          'tag'      => $match[0],
          'path'     => $match[1],
          'fallback' => $match[2] ?? '',
        ];
      }
    }

    return $tags;
  }

  /**
   * List all available merge tags from all registered sources.
   *
   * @return array Array of tag descriptors with 'tag', 'label', 'source', 'group'.
   */
  public static function get_available_tags(): array {
    $tags = [];
    $registry = WPI_Data_Source_Registry::instance();

    foreach ($registry->all() as $name => $source) {
      $source_tags = $source->get_available_tags();
      foreach ($source_tags as $tag_info) {
        $tag_info['source'] = $name;
        $tags[] = $tag_info;
      }
    }

    return apply_filters('wpi_dynamic_data_available_tags', $tags);
  }
}
