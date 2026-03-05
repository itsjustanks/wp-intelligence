<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Merge Tag Engine — parses and resolves tags and conditionals in content.
 *
 * Value tags:
 *   {{source.field.path}}           — resolve from a named data source
 *   {{source.field.path|fallback}}  — use fallback if value is empty
 *
 * Conditional tags:
 *   {{#if source.field}}...{{/if}}
 *   {{#if source.field}}...{{#else}}...{{/if}}
 *   {{#if source.field == "value"}}...{{/if}}
 *   {{#if source.field != "value"}}...{{/if}}
 *   {{#if source.field > "42"}}...{{/if}}
 *   {{#if source.field < "42"}}...{{/if}}
 *
 * Client-side sources (e.g. storage):
 *   Value tags are wrapped in <span data-wpi-dd="..."> for JS resolution.
 *   Conditional blocks with client-side sources are rendered as hidden
 *   if/else branches for JS to evaluate and reveal.
 *
 * Built-in source prefixes:
 *   wp.*       — WordPress data (post, user, site, meta)
 *   url.*      — URL query parameters
 *   cookie.*   — Browser cookie values
 *   storage.*  — localStorage / sessionStorage (client-side)
 *   *          — Any registered webhook/API data source name
 */
class WPI_Merge_Tag_Engine {

  private const TAG_PATTERN = '/\{\{([a-zA-Z0-9_]+(?:\.[a-zA-Z0-9_\-]+)*)(?:\|([^}]*))?\}\}/';

  /**
   * Pattern for conditional blocks:
   *   {{#if source.field}}...{{/if}}
   *   {{#if source.field}}...{{#else}}...{{/if}}
   *   {{#if source.field OP "value"}}...{{/if}}
   * where OP is ==, !=, >, <, contains, !contains
   */
  private const CONDITIONAL_PATTERN = '/\{\{#if\s+([a-zA-Z0-9_]+(?:\.[a-zA-Z0-9_\-]+)*)(?:\s*(==|!=|>|<|contains|!contains)\s*"([^"]*)")?\s*\}\}(.*?)(?:\{\{#else\}\}(.*?))?\{\{\/if\}\}/s';

  private const MAX_REPLACEMENTS = 500;
  private const MAX_DEPTH = 10;
  private const MAX_CONDITIONAL_DEPTH = 5;

  /**
   * Resolve all merge tags and conditionals in a string.
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

    $content = self::resolve_conditionals($content, $context, $escape_html);
    $content = self::resolve_value_tags($content, $context, $escape_html);

    return $content;
  }

  /**
   * Process {{#if}}...{{#else}}...{{/if}} blocks.
   *
   * Processes innermost conditionals first to support nesting.
   */
  public static function resolve_conditionals(string $content, array $context = [], bool $escape_html = true, int $depth = 0): string {
    if ($depth > self::MAX_CONDITIONAL_DEPTH || strpos($content, '{{#if') === false) {
      return $content;
    }

    $resolved = preg_replace_callback(
      self::CONDITIONAL_PATTERN,
      function ($matches) use ($context, $escape_html, $depth) {
        $tag_path      = $matches[1];
        $operator      = $matches[2] ?? '';
        $compare_value = $matches[3] ?? '';
        $if_content    = $matches[4];
        $else_content  = $matches[5] ?? '';

        $source_name = explode('.', $tag_path, 2)[0];
        $registry    = WPI_Data_Source_Registry::instance();
        $source      = $registry->get($source_name);

        if ($source !== null && $source->is_client_side()) {
          return self::wrap_client_side_conditional(
            $tag_path, $operator, $compare_value,
            $if_content, $else_content
          );
        }

        $value  = self::resolve_tag($tag_path, $context);
        $result = self::evaluate_condition($value, $operator, $compare_value);

        $chosen = $result ? $if_content : $else_content;

        return self::resolve_conditionals($chosen, $context, $escape_html, $depth + 1);
      },
      $content
    );

    return $resolved ?? $content;
  }

  /**
   * Evaluate a conditional expression.
   *
   * @param mixed  $value         The resolved value.
   * @param string $operator      Comparison operator (empty = truthy check).
   * @param string $compare_value Value to compare against.
   * @return bool
   */
  private static function evaluate_condition($value, string $operator, string $compare_value): bool {
    if (is_array($value) || is_object($value)) {
      $value = wp_json_encode($value);
    }
    $str_value = (string) ($value ?? '');

    if ($operator === '' || $operator === null) {
      return $str_value !== '' && $str_value !== '0' && $str_value !== 'false' && $str_value !== 'null';
    }

    switch ($operator) {
      case '==':
        return $str_value === $compare_value;
      case '!=':
        return $str_value !== $compare_value;
      case '>':
        return is_numeric($str_value) && is_numeric($compare_value) && (float) $str_value > (float) $compare_value;
      case '<':
        return is_numeric($str_value) && is_numeric($compare_value) && (float) $str_value < (float) $compare_value;
      case 'contains':
        return $compare_value !== '' && strpos($str_value, $compare_value) !== false;
      case '!contains':
        return $compare_value === '' || strpos($str_value, $compare_value) === false;
      default:
        return $str_value !== '';
    }
  }

  /**
   * Wrap a conditional block for client-side evaluation.
   *
   * Both branches are rendered but hidden; frontend JS evaluates and reveals the correct one.
   */
  private static function wrap_client_side_conditional(
    string $tag_path, string $operator, string $compare_value,
    string $if_content, string $else_content
  ): string {
    $config = wp_json_encode([
      'tag'      => $tag_path,
      'operator' => $operator ?: 'truthy',
      'value'    => $compare_value,
    ]);

    $id = 'wpi-cond-' . wp_unique_id();

    $html = '<span class="wpi-dd-conditional" data-wpi-condition="' . esc_attr($config) . '" data-wpi-cond-id="' . esc_attr($id) . '">';
    $html .= '<span class="wpi-dd-if" style="display:none">' . $if_content . '</span>';
    if ($else_content !== '') {
      $html .= '<span class="wpi-dd-else" style="display:none">' . $else_content . '</span>';
    }
    $html .= '</span>';

    return $html;
  }

  /**
   * Resolve all value tags (non-conditional) in a string.
   */
  public static function resolve_value_tags(string $content, array $context = [], bool $escape_html = true): string {
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

        $source_name = explode('.', $tag_path, 2)[0];
        $registry    = WPI_Data_Source_Registry::instance();
        $source      = $registry->get($source_name);

        if ($source !== null && $source->is_client_side()) {
          return self::wrap_client_side_tag($tag_path, $fallback);
        }

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
   * Wrap a client-side tag in a placeholder element for JS resolution.
   */
  private static function wrap_client_side_tag(string $tag_path, string $fallback): string {
    $parts       = explode('.', $tag_path, 2);
    $source_name = $parts[0];
    $field_path  = $parts[1] ?? '';

    $config = wp_json_encode([
      'source'   => $source_name,
      'field'    => $field_path,
      'fallback' => $fallback,
    ]);

    return '<span class="wpi-dd-pending" data-wpi-dd="' . esc_attr($config) . '">'
      . esc_html($fallback)
      . '</span>';
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

    if ($source->is_client_side()) {
      return null;
    }

    $data = $registry->fetch_cached($source_name, $context);

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
   * @return array Array of tag descriptors.
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
   * Check if content contains any client-side tags or conditionals.
   */
  public static function has_client_side_content(string $content): bool {
    return strpos($content, 'wpi-dd-pending') !== false
      || strpos($content, 'wpi-dd-conditional') !== false;
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
        $tag_info['source']     = $name;
        $tag_info['clientSide'] = $source->is_client_side();
        $tags[] = $tag_info;
      }
    }

    return apply_filters('wpi_dynamic_data_available_tags', $tags);
  }
}
