<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Validates and transforms AI composition manifests.
 *
 * Primary output for editor insertion is a normalized block tree:
 * - name: string (block name, e.g. core/group)
 * - attributes: array<string,mixed>
 * - innerBlocks: array<block-tree-node>
 *
 * The optional compile() method serializes this tree to block grammar for
 * external consumers (REST, Abilities, logs), but editor insertion should
 * prefer the block tree to avoid save/validation drift.
 */
class AI_Composer_Manifest_Compiler {

  private AI_Composer_Block_Catalog   $blocks;
  private AI_Composer_Pattern_Catalog $patterns;

  /**
   * @var array<string,bool>|null
   */
  private ?array $allowed_lookup = null;

  public function __construct(AI_Composer_Block_Catalog $blocks, AI_Composer_Pattern_Catalog $patterns) {
    $this->blocks   = $blocks;
    $this->patterns = $patterns;
  }

  /**
   * Validate a manifest and return WP_Error on the first pass of violations.
   */
  public function validate(array $manifest): true|WP_Error {
    if (empty($manifest['blocks']) || ! is_array($manifest['blocks'])) {
      return new WP_Error('ai_composer_empty_manifest', __('Manifest contains no blocks.', 'wp-intelligence'));
    }

    $definitions = $this->coalesce_section_pattern_siblings($manifest['blocks']);
    $errors = $this->validate_block_definitions($definitions, 'blocks');

    if (! empty($errors)) {
      return new WP_Error(
        'ai_composer_validation_failed',
        implode(' ', $errors),
        ['details' => $errors]
      );
    }

    return true;
  }

  /**
   * Convert a manifest into a normalized block tree.
   *
   * @return array<int,array{name:string,attributes:array,innerBlocks:array}>|WP_Error
   */
  public function to_block_tree(array $manifest): array|WP_Error {
    $validation = $this->validate($manifest);
    if (is_wp_error($validation)) {
      return $validation;
    }

    $definitions = $this->coalesce_section_pattern_siblings($manifest['blocks']);
    $tree = [];

    foreach ($definitions as $block_def) {
      $nodes = $this->build_nodes_from_definition($block_def);
      if (is_wp_error($nodes)) {
        return $nodes;
      }
      $tree = array_merge($tree, $nodes);
    }

    /**
     * Filter normalized block tree before it reaches the editor.
     */
    return apply_filters('ai_composer_block_tree', $tree, $manifest);
  }

  /**
   * Optional compatibility output: serialized block grammar.
   */
  public function compile(array $manifest): string|WP_Error {
    $tree = $this->to_block_tree($manifest);
    if (is_wp_error($tree)) {
      return $tree;
    }

    $parsed_blocks = $this->tree_to_parsed_blocks($tree);

    if (function_exists('serialize_blocks')) {
      $output = serialize_blocks($parsed_blocks);
    } else {
      $output = '';
    }

    return apply_filters('ai_composer_block_grammar', $output, $manifest);
  }

  /**
   * Recursively validate an array of block definitions.
   *
   * @param array<int,mixed> $definitions
   * @return array<int,string>
   */
  private function validate_block_definitions(array $definitions, string $path): array {
    $errors = [];

    foreach ($definitions as $index => $definition) {
      if (! is_array($definition)) {
        $errors[] = sprintf(__('%s[%d] must be an object.', 'wp-intelligence'), $path, $index);
        continue;
      }

      $current_path = $path . '[' . $index . ']';
      $errors = array_merge($errors, $this->validate_single_definition($definition, $current_path));
    }

    return $errors;
  }

  /**
   * @return array<int,string>
   */
  private function validate_single_definition(array $definition, string $path): array {
    $errors = [];
    $type = $definition['blockType'] ?? '';

    if (! is_string($type) || $type === '') {
      $errors[] = sprintf(__('%s.blockType is required.', 'wp-intelligence'), $path);
      return $errors;
    }

    if ($type === 'pattern') {
      $slug = $definition['patternSlug'] ?? '';
      if (! is_string($slug) || $slug === '') {
        $errors[] = sprintf(__('%s.patternSlug is required when blockType is "pattern".', 'wp-intelligence'), $path);
        return $errors;
      }

      if (! $this->patterns->pattern_exists($slug)) {
        $errors[] = sprintf(__('%s references unknown pattern "%s".', 'wp-intelligence'), $path, $slug);
        return $errors;
      }

      // Validate expanded pattern blocks against registration + allowlist.
      $errors = array_merge($errors, $this->validate_pattern_content($slug, $path));
      return $errors;
    }

    if (! $this->blocks->block_exists($type)) {
      $errors[] = sprintf(__('%s uses unknown block type "%s".', 'wp-intelligence'), $path, $type);
      return $errors;
    }

    if (! $this->is_block_allowed($type)) {
      $errors[] = sprintf(__('%s uses disallowed block type "%s".', 'wp-intelligence'), $path, $type);
      return $errors;
    }

    $inner = $definition['innerBlocks'] ?? [];
    if (! is_array($inner)) {
      $errors[] = sprintf(__('%s.innerBlocks must be an array.', 'wp-intelligence'), $path);
      return $errors;
    }

    $errors = array_merge($errors, $this->validate_block_definitions($inner, $path . '.innerBlocks'));

    return $errors;
  }

  /**
   * @return array<int,string>
   */
  private function validate_pattern_content(string $slug, string $path): array {
    $pattern = $this->patterns->get_pattern($slug);
    if ($pattern === null) {
      return [sprintf(__('%s references unknown pattern "%s".', 'wp-intelligence'), $path, $slug)];
    }

    if (! function_exists('parse_blocks')) {
      return [];
    }

    $parsed = parse_blocks($pattern['content'] ?? '');
    return $this->validate_parsed_blocks($parsed, $path . '.pattern(' . $slug . ')');
  }

  /**
   * @param array<int,mixed> $parsed_blocks
   * @return array<int,string>
   */
  private function validate_parsed_blocks(array $parsed_blocks, string $path): array {
    $errors = [];

    foreach ($parsed_blocks as $index => $parsed) {
      if (! is_array($parsed)) {
        continue;
      }

      $name = $parsed['blockName'] ?? '';
      if (! is_string($name) || $name === '') {
        // Null block names are freeform HTML fragments. Ignore.
        continue;
      }

      $current_path = $path . '[' . $index . ']';

      if (! $this->blocks->block_exists($name)) {
        $errors[] = sprintf(__('%s contains unknown block "%s".', 'wp-intelligence'), $current_path, $name);
      } elseif (! $this->is_block_allowed($name)) {
        $errors[] = sprintf(__('%s contains disallowed block "%s".', 'wp-intelligence'), $current_path, $name);
      }

      $inner = $parsed['innerBlocks'] ?? [];
      if (is_array($inner) && ! empty($inner)) {
        $errors = array_merge($errors, $this->validate_parsed_blocks($inner, $current_path . '.innerBlocks'));
      }
    }

    return $errors;
  }

  /**
   * Build editor-ready block tree nodes from one manifest definition.
   *
   * @return array<int,array{name:string,attributes:array,innerBlocks:array}>|WP_Error
   */
  private function build_nodes_from_definition(array $definition): array|WP_Error {
    $type = $definition['blockType'] ?? '';

    if (! is_string($type) || $type === '') {
      return new WP_Error('ai_composer_invalid_block_type', __('Manifest blockType is missing.', 'wp-intelligence'));
    }

    if ($type === 'pattern') {
      $slug = (string) ($definition['patternSlug'] ?? '');
      $pattern_nodes = $this->pattern_to_nodes($slug);
      if (is_wp_error($pattern_nodes)) {
        return $pattern_nodes;
      }

      $content_defs = $definition['innerBlocks'] ?? [];
      if (! is_array($content_defs) || empty($content_defs)) {
        return $pattern_nodes;
      }

      $content_nodes = [];
      foreach ($content_defs as $child_def) {
        if (! is_array($child_def)) {
          continue;
        }
        $child_nodes = $this->build_nodes_from_definition($child_def);
        if (is_wp_error($child_nodes)) {
          return $child_nodes;
        }
        $content_nodes = array_merge($content_nodes, $child_nodes);
      }

      return $this->inject_nodes_into_pattern($pattern_nodes, $content_nodes);
    }

    $attributes = $this->normalize_attributes($definition['attributes'] ?? []);
    $inner_defs = $definition['innerBlocks'] ?? [];

    $inner_nodes = [];
    if (is_array($inner_defs)) {
      foreach ($inner_defs as $child_def) {
        if (! is_array($child_def)) {
          continue;
        }
        $child_nodes = $this->build_nodes_from_definition($child_def);
        if (is_wp_error($child_nodes)) {
          return $child_nodes;
        }
        $inner_nodes = array_merge($inner_nodes, $child_nodes);
      }
    }

    return [[
      'name'       => $type,
      'attributes' => $attributes,
      'innerBlocks'=> $inner_nodes,
    ]];
  }

  /**
   * Expand a pattern into normalized tree nodes.
   *
   * @return array<int,array{name:string,attributes:array,innerBlocks:array}>|WP_Error
   */
  private function pattern_to_nodes(string $slug): array|WP_Error {
    if ($slug === '') {
      return new WP_Error('ai_composer_pattern_missing', __('Pattern slug is missing.', 'wp-intelligence'));
    }

    $pattern = $this->patterns->get_pattern($slug);
    if ($pattern === null) {
      return new WP_Error(
        'ai_composer_pattern_not_found',
        sprintf(__('Pattern "%s" not found.', 'wp-intelligence'), $slug)
      );
    }

    if (! function_exists('parse_blocks')) {
      return new WP_Error('ai_composer_parse_blocks_unavailable', __('parse_blocks() is unavailable.', 'wp-intelligence'));
    }

    $parsed = parse_blocks($pattern['content'] ?? '');
    return $this->parsed_blocks_to_tree($parsed);
  }

  /**
   * Heuristic normalization:
   * If a section-style pattern appears as a top-level block and is followed by
   * non-pattern siblings, treat those siblings as inner content for that section.
   *
   * This prevents "pattern then orphaned siblings" layouts and aligns with
   * expected section composition behavior.
   *
   * @param array<int,mixed> $definitions
   * @return array<int,mixed>
   */
  private function coalesce_section_pattern_siblings(array $definitions): array {
    $normalized = [];
    $count = count($definitions);
    $i = 0;

    while ($i < $count) {
      $definition = $definitions[$i];
      if (! is_array($definition)) {
        $i++;
        continue;
      }

      // Normalize already-nested children first.
      $existing_inner = $definition['innerBlocks'] ?? [];
      if (is_array($existing_inner) && ! empty($existing_inner)) {
        $definition['innerBlocks'] = $this->coalesce_section_pattern_siblings($existing_inner);
      }

      if ($this->is_section_pattern_definition($definition)) {
        if (! isset($definition['innerBlocks']) || ! is_array($definition['innerBlocks'])) {
          $definition['innerBlocks'] = [];
        }

        $j = $i + 1;
        while ($j < $count) {
          $next = $definitions[$j];
          if (! is_array($next)) {
            $j++;
            continue;
          }

          $next_type = $next['blockType'] ?? '';
          if ($next_type === 'pattern') {
            break;
          }

          $definition['innerBlocks'][] = $next;
          $j++;
        }

        if (! empty($definition['innerBlocks'])) {
          $definition['innerBlocks'] = $this->coalesce_section_pattern_siblings($definition['innerBlocks']);
        }

        $normalized[] = $definition;
        $i = $j;
        continue;
      }

      $normalized[] = $definition;
      $i++;
    }

    return $normalized;
  }

  private function is_section_pattern_definition(array $definition): bool {
    if (($definition['blockType'] ?? '') !== 'pattern') {
      return false;
    }

    $slug = (string) ($definition['patternSlug'] ?? '');
    if ($slug === '') {
      return false;
    }

    $known = $this->get_section_pattern_slugs();
    if (in_array($slug, $known, true)) {
      return true;
    }

    // Sensible fallback for custom section pattern naming.
    return str_contains($slug, 'section');
  }

  /**
   * @return array<int,string>
   */
  private function get_section_pattern_slugs(): array {
    $defaults = [
      'ai-composer/section',
      'ai-composer/section',
      'ai-composer/nectar-section',
    ];

    $slugs = apply_filters('ai_composer_section_pattern_slugs', $defaults);
    if (! is_array($slugs)) {
      return $defaults;
    }

    return array_values(array_filter(array_map('strval', $slugs), static function (string $slug): bool {
      return $slug !== '';
    }));
  }

  /**
   * Inject content nodes into expanded pattern nodes.
   *
   * Strategy:
   * 1) explicit slot (className contains "ai-composer-slot")
   * 2) deepest container that can accept inner blocks
   * 3) fallback append as siblings (preserve content)
   *
   * @param array<int,array{name:string,attributes:array,innerBlocks:array}> $pattern_nodes
   * @param array<int,array{name:string,attributes:array,innerBlocks:array}> $content_nodes
   * @return array<int,array{name:string,attributes:array,innerBlocks:array}>
   */
  private function inject_nodes_into_pattern(array $pattern_nodes, array $content_nodes): array {
    if (empty($content_nodes)) {
      return $pattern_nodes;
    }

    $candidate = $pattern_nodes;

    if ($this->append_to_slot_node($candidate, $content_nodes)) {
      return $candidate;
    }

    if ($this->append_to_deepest_container($candidate, $content_nodes)) {
      return $candidate;
    }

    // Last-resort fallback: preserve both.
    return array_merge($pattern_nodes, $content_nodes);
  }

  /**
   * @param array<int,array{name:string,attributes:array,innerBlocks:array}> $nodes
   * @param array<int,array{name:string,attributes:array,innerBlocks:array}> $content_nodes
   */
  private function append_to_slot_node(array &$nodes, array $content_nodes): bool {
    foreach ($nodes as &$node) {
      if (! is_array($node)) {
        continue;
      }

      if ($this->node_is_slot($node)) {
        if (! isset($node['innerBlocks']) || ! is_array($node['innerBlocks'])) {
          $node['innerBlocks'] = [];
        }
        $node['innerBlocks'] = array_merge($node['innerBlocks'], $content_nodes);
        return true;
      }

      if (! isset($node['innerBlocks']) || ! is_array($node['innerBlocks'])) {
        continue;
      }

      if ($this->append_to_slot_node($node['innerBlocks'], $content_nodes)) {
        return true;
      }
    }

    return false;
  }

  /**
   * @param array<int,array{name:string,attributes:array,innerBlocks:array}> $nodes
   * @param array<int,array{name:string,attributes:array,innerBlocks:array}> $content_nodes
   */
  private function append_to_deepest_container(array &$nodes, array $content_nodes): bool {
    for ($i = count($nodes) - 1; $i >= 0; $i--) {
      if (! isset($nodes[$i]) || ! is_array($nodes[$i])) {
        continue;
      }

      if (isset($nodes[$i]['innerBlocks']) && is_array($nodes[$i]['innerBlocks']) && ! empty($nodes[$i]['innerBlocks'])) {
        if ($this->append_to_deepest_container($nodes[$i]['innerBlocks'], $content_nodes)) {
          return true;
        }
      }

      $name = (string) ($nodes[$i]['name'] ?? '');
      if (! $this->can_accept_inner_blocks($name)) {
        continue;
      }

      if (! isset($nodes[$i]['innerBlocks']) || ! is_array($nodes[$i]['innerBlocks'])) {
        $nodes[$i]['innerBlocks'] = [];
      }

      $nodes[$i]['innerBlocks'] = array_merge($nodes[$i]['innerBlocks'], $content_nodes);
      return true;
    }

    return false;
  }

  private function node_is_slot(array $node): bool {
    $attrs = $node['attributes'] ?? [];
    if (! is_array($attrs)) {
      return false;
    }

    $class_name = (string) ($attrs['className'] ?? '');
    if ($class_name === '') {
      return false;
    }

    return str_contains(' ' . $class_name . ' ', ' ai-composer-slot ');
  }

  private function can_accept_inner_blocks(string $block_name): bool {
    if ($block_name === '') {
      return false;
    }

    $registry = WP_Block_Type_Registry::get_instance();
    if ($registry->is_registered($block_name)) {
      $all = $registry->get_all_registered();
      $type = $all[$block_name] ?? null;
      if ($type instanceof WP_Block_Type) {
        $supports = $type->supports ?? [];
        if (! empty($supports['innerBlocks'])) {
          return true;
        }
      }
    }

    $fallback_containers = [
      'core/group',
      'core/columns',
      'core/column',
      'core/cover',
      'core/buttons',
      'core/list',
      'core/quote',
      'nectar-blocks/row',
      'nectar-blocks/column',
    ];

    return in_array($block_name, $fallback_containers, true);
  }

  /**
   * Convert parsed WP blocks to editor-ready tree nodes.
   *
   * @param array<int,mixed> $parsed_blocks
   * @return array<int,array{name:string,attributes:array,innerBlocks:array}>
   */
  private function parsed_blocks_to_tree(array $parsed_blocks): array {
    $nodes = [];

    foreach ($parsed_blocks as $parsed) {
      if (! is_array($parsed)) {
        continue;
      }

      $name = $parsed['blockName'] ?? '';
      if (! is_string($name) || $name === '') {
        continue;
      }

      $attrs = $parsed['attrs'] ?? [];
      if (! is_array($attrs)) {
        $attrs = [];
      }

      $inner_parsed = $parsed['innerBlocks'] ?? [];
      if (! is_array($inner_parsed)) {
        $inner_parsed = [];
      }

      $nodes[] = [
        'name'        => $name,
        'attributes'  => $attrs,
        'innerBlocks' => $this->parsed_blocks_to_tree($inner_parsed),
      ];
    }

    return $nodes;
  }

  /**
   * Convert block tree to parsed-block format for serialize_blocks().
   *
   * @param array<int,array{name:string,attributes:array,innerBlocks:array}> $tree
   * @return array<int,array<string,mixed>>
   */
  private function tree_to_parsed_blocks(array $tree): array {
    $parsed = [];

    foreach ($tree as $node) {
      $name = $node['name'] ?? '';
      if (! is_string($name) || $name === '') {
        continue;
      }

      $attrs = $node['attributes'] ?? [];
      if (! is_array($attrs)) {
        $attrs = [];
      }

      $inner = $node['innerBlocks'] ?? [];
      if (! is_array($inner)) {
        $inner = [];
      }

      $parsed[] = [
        'blockName'   => $name,
        'attrs'       => $attrs,
        'innerBlocks' => $this->tree_to_parsed_blocks($inner),
        'innerHTML'   => '',
        'innerContent'=> [],
      ];
    }

    return $parsed;
  }

  /**
   * Normalize the AI's attribute format into a standard key-value map.
   *
   * Supports:
   * - Array of {name,value} pairs (structured-output safe format)
   * - Legacy associative array (for backward compatibility)
   */
  private function normalize_attributes(array $raw): array {
    if (empty($raw)) {
      return [];
    }

    // Structured format: [{"name":"level","value":"2"}, ...]
    if (isset($raw[0]) && is_array($raw[0]) && isset($raw[0]['name'])) {
      $normalized = [];

      foreach ($raw as $pair) {
        if (! is_array($pair) || ! isset($pair['name'], $pair['value'])) {
          continue;
        }

        $name = trim((string) $pair['name']);
        if ($name === '') {
          continue;
        }

        $value = $this->cast_value($pair['value']);
        $this->set_nested_attribute($normalized, $name, $value);
      }

      return $normalized;
    }

    // Legacy format: {"level":2,"content":"Title"}
    return $raw;
  }

  /**
   * Cast string values back to scalar/JSON values where appropriate.
   */
  private function cast_value(mixed $value): mixed {
    if (! is_string($value)) {
      return $value;
    }

    $trim = trim($value);

    if ($trim === 'true') {
      return true;
    }
    if ($trim === 'false') {
      return false;
    }
    if ($trim === 'null') {
      return null;
    }

    // Int/float without leading-zero ambiguity.
    if (preg_match('/^-?(0|[1-9]\d*)(\.\d+)?$/', $trim)) {
      return str_contains($trim, '.') ? (float) $trim : (int) $trim;
    }

    // JSON payloads represented as strings.
    if ((str_starts_with($trim, '{') && str_ends_with($trim, '}')) || (str_starts_with($trim, '[') && str_ends_with($trim, ']'))) {
      $decoded = json_decode($trim, true);
      if (json_last_error() === JSON_ERROR_NONE) {
        return $decoded;
      }
    }

    return $value;
  }

  /**
   * Support dotted keys (e.g. data.field_name) for nested attrs.
   */
  private function set_nested_attribute(array &$target, string $path, mixed $value): void {
    if (! str_contains($path, '.')) {
      $target[$path] = $value;
      return;
    }

    $segments = array_values(array_filter(explode('.', $path), static function ($segment) {
      return $segment !== '';
    }));

    if (empty($segments)) {
      return;
    }

    $cursor =& $target;

    foreach ($segments as $index => $segment) {
      $last = $index === (count($segments) - 1);

      if ($last) {
        $cursor[$segment] = $value;
        continue;
      }

      if (! isset($cursor[$segment]) || ! is_array($cursor[$segment])) {
        $cursor[$segment] = [];
      }

      $cursor =& $cursor[$segment];
    }
  }

  private function is_block_allowed(string $name): bool {
    $lookup = $this->get_allowed_lookup();

    // Empty lookup means "all blocks allowed" mode.
    if (empty($lookup)) {
      return true;
    }

    return isset($lookup[$name]);
  }

  /**
   * @return array<string,bool>
   */
  private function get_allowed_lookup(): array {
    if ($this->allowed_lookup !== null) {
      return $this->allowed_lookup;
    }

    $enabled = AI_Composer_Settings::get_enabled_blocks();
    if (empty($enabled)) {
      $this->allowed_lookup = [];
      return $this->allowed_lookup;
    }

    $this->allowed_lookup = array_fill_keys($enabled, true);
    return $this->allowed_lookup;
  }
}
