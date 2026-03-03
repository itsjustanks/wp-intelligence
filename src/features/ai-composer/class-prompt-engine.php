<?php
if (! defined('ABSPATH')) {
  exit;
}

class AI_Composer_Prompt_Engine {

  private AI_Composer_Block_Catalog   $blocks;
  private AI_Composer_Pattern_Catalog $patterns;

  public function __construct(AI_Composer_Block_Catalog $blocks, AI_Composer_Pattern_Catalog $patterns) {
    $this->blocks   = $blocks;
    $this->patterns = $patterns;
  }

  public function build_system_prompt(array $options = []): string {
    $sections = [];

    $prepend = trim(AI_Composer_Settings::get_system_prompt_prepend());
    if ($prepend !== '') {
      $sections[] = "## Custom System Instructions (Prepend)
" . $prepend;
    }

    $sections[] = $this->get_role_section();
    $sections[] = $this->blocks->to_prompt_text();
    $sections[] = $this->patterns->to_prompt_text();

    $theme_strategy = $this->get_theme_strategy_section();
    if ($theme_strategy !== '') {
      $sections[] = $theme_strategy;
    }

    $sections[] = $this->get_rules_section();
    $sections[] = $this->get_output_format_section();

    if (! empty($options['template'])) {
      $sections[] = "## Page Template Constraint\nThis page uses the `{$options['template']}` template. Compose blocks appropriate for that template.";
    }

    $compose_mode = $options['compose_mode'] ?? 'new_content';

    if ($compose_mode === 'selected_block' && ! empty($options['selected_block_context'])) {
      $sections[] = $this->get_optimize_block_section($options['selected_block_context']);
    }

    if ($compose_mode === 'page' && ! empty($options['page_context'])) {
      $sections[] = $this->get_optimize_page_section($options['page_context']);
    }

    $append = trim(AI_Composer_Settings::get_system_prompt_append());
    if ($append !== '') {
      $sections[] = "## Custom System Instructions (Append)
" . $append;
    }

    $prompt = implode("

", array_filter($sections));

    return apply_filters('ai_composer_system_prompt', $prompt, $options);
  }

  /**
   * JSON Schema for OpenAI structured output (strict mode compatible).
   *
   * Strict mode requires: additionalProperties: false on every object,
   * all defined properties in required, and no open-ended objects.
   * Attributes are represented as arrays of {name, value} pairs.
   */
  public function get_output_schema(): array {
    $schema = [
      'type'       => 'object',
      'properties' => [
        'blocks' => [
          'type'        => 'array',
          'items'       => $this->get_block_item_schema(),
          'description' => 'Ordered array of top-level blocks that compose the page.',
        ],
        'summary' => [
          'type'        => 'string',
          'description' => 'One-sentence summary of the composition.',
        ],
      ],
      'required'             => ['blocks', 'summary'],
      'additionalProperties' => false,
    ];

    return apply_filters('ai_composer_output_schema', $schema);
  }

  private function get_attr_pair_schema(): array {
    return [
      'type'       => 'object',
      'properties' => [
        'name'  => ['type' => 'string', 'description' => 'Attribute name (e.g. level, content, url, alt).'],
        'value' => ['type' => 'string', 'description' => 'Attribute value as a string. Stringify numbers and booleans (e.g. "2", "true").'],
      ],
      'required'             => ['name', 'value'],
      'additionalProperties' => false,
    ];
  }

  private function get_block_item_schema(): array {
    $attr_pair = $this->get_attr_pair_schema();

    $level3 = [
      'type'       => 'object',
      'properties' => [
        'blockType'   => ['type' => 'string', 'description' => 'Block type identifier.'],
        'attributes'  => ['type' => 'array', 'items' => $attr_pair, 'description' => 'Block attributes as name-value pairs.'],
        'patternSlug' => ['type' => ['string', 'null'], 'description' => 'Pattern slug when blockType is "pattern", otherwise null.'],
      ],
      'required'             => ['blockType', 'attributes', 'patternSlug'],
      'additionalProperties' => false,
    ];

    $level2 = [
      'type'       => 'object',
      'properties' => [
        'blockType'   => ['type' => 'string', 'description' => 'Block type identifier.'],
        'attributes'  => ['type' => 'array', 'items' => $attr_pair, 'description' => 'Block attributes as name-value pairs.'],
        'patternSlug' => ['type' => ['string', 'null'], 'description' => 'Pattern slug when blockType is "pattern", otherwise null.'],
        'innerBlocks' => ['type' => 'array', 'items' => $level3, 'description' => 'Third-level nested blocks.'],
      ],
      'required'             => ['blockType', 'attributes', 'patternSlug', 'innerBlocks'],
      'additionalProperties' => false,
    ];

    return [
      'type'       => 'object',
      'properties' => [
        'blockType' => [
          'type'        => 'string',
          'description' => 'Block type identifier (e.g. core/heading, core/group, acf/my-block) or "pattern" for pattern references.',
        ],
        'attributes' => [
          'type'        => 'array',
          'items'       => $attr_pair,
          'description' => 'Block attributes as name-value pairs. For headings: [{name:"level",value:"2"},{name:"content",value:"Title"}].',
        ],
        'patternSlug' => [
          'type'        => ['string', 'null'],
          'description' => 'When blockType is "pattern", the registered pattern slug. Otherwise null.',
        ],
        'innerBlocks' => [
          'type'        => 'array',
          'items'       => $level2,
          'description' => 'Nested blocks for containers like core/group, core/columns, core/column.',
        ],
      ],
      'required'             => ['blockType', 'attributes', 'patternSlug', 'innerBlocks'],
      'additionalProperties' => false,
    ];
  }

  private function get_role_section(): string {
    $site_name = get_bloginfo('name') ?: 'this WordPress site';

    return <<<PROMPT
# Role

You are an AI page composer for {$site_name}. Your job is to take a user's natural-language page description and produce a structured composition manifest using the site's available blocks and patterns.

Create clean, production-ready layouts. Prefer reusable patterns when they match intent. Avoid unnecessary wrappers and over-nesting.
PROMPT;
  }

  private function get_rules_section(): string {
    $composable = $this->blocks->get_composable();
    $lookup = [];
    foreach ($composable as $block) {
      $lookup[$block['name']] = true;
    }

    $has_group = isset($lookup['core/group']);
    $has_columns = isset($lookup['core/columns']) && isset($lookup['core/column']);
    $preferred_section_pattern = $this->get_preferred_section_pattern_slug();

    $lines = [];
    $lines[] = '## Composition Rules';
    $lines[] = '';
    $lines[] = '1. Only use block types listed in "Available Blocks" or pattern slugs from "Available Patterns".';

    if ($preferred_section_pattern !== '') {
      $lines[] = '2. For page sections, prefer the `' . $preferred_section_pattern . '` pattern before manually composing wrappers.';
    } else {
      $lines[] = '2. Prefer existing patterns before manual composition.';
    }

    if ($has_group) {
      $lines[] = '3. Use `core/group` sparingly: only for real section boundaries. Do NOT wrap single standalone blocks in an unnecessary group.';
    } else {
      $lines[] = '3. Do not use `core/group` unless it appears in Available Blocks.';
    }

    if ($has_columns) {
      $lines[] = '4. Use `core/columns` + `core/column` for side-by-side layouts. Always place content blocks inside `core/column`, never directly inside `core/columns`.';
    } else {
      $lines[] = '4. If column blocks are unavailable, use vertical stacking instead of simulating columns.';
    }

    $lines[] = '5. For headings, use descending levels: h1 → h2 → h3. Only one h1 per page.';
    $lines[] = '6. To reference a pattern, set `blockType` to `"pattern"` and `patternSlug` to the pattern\'s registered slug.';
    $lines[] = '7. When using a section-style pattern, place section content INSIDE that pattern by populating the pattern block\'s `innerBlocks`.';
    $lines[] = '8. Do not output a section pattern followed by unrelated sibling content unless you are intentionally starting a new section.';
    $lines[] = '9. For ACF blocks (prefixed `acf/`), place known field values in attributes with name `data.field_name`.';
    $lines[] = '10. Use filler/placeholder text when the user does not provide specific content.';
    $lines[] = '11. Keep the manifest compact. Do not over-nest blocks.';
    $lines[] = '12. Never invent block types. If unsure whether a block exists, use core blocks that are listed as available.';
    $lines[] = '13. All attribute values MUST be strings. Stringify numbers ("2"), booleans ("true"/"false"), and JSON objects.';
    $lines[] = '14. Always set `patternSlug` to null when `blockType` is not "pattern".';
    $lines[] = '15. Always set `innerBlocks` to an empty array `[]` when the block has no children.';

    return implode("\n", $lines);
  }

  private function get_output_format_section(): string {
    return <<<'PROMPT'
## Output Format

Return a JSON object with:
- `blocks`: an ordered array of block definitions.
- `summary`: a one-sentence summary of what you composed.

Every block MUST have all four fields: `blockType`, `attributes`, `patternSlug`, `innerBlocks`.

Block definition examples:
```
{
  "blockType": "core/heading",
  "attributes": [
    {"name": "level", "value": "2"},
    {"name": "content", "value": "Section Title"}
  ],
  "patternSlug": null,
  "innerBlocks": []
}
```

```
{
  "blockType": "core/group",
  "attributes": [],
  "patternSlug": null,
  "innerBlocks": [
    {
      "blockType": "core/paragraph",
      "attributes": [{"name": "content", "value": "Hello world."}],
      "patternSlug": null,
      "innerBlocks": []
    }
  ]
}
```

For pattern references:
```
{
  "blockType": "pattern",
  "attributes": [],
  "patternSlug": "theme-namespace/pattern-name",
  "innerBlocks": []
}
```

For section pattern with nested content:
```
{
  "blockType": "pattern",
  "attributes": [],
  "patternSlug": "theme-namespace/section",
  "innerBlocks": [
    {
      "blockType": "core/heading",
      "attributes": [{"name": "level", "value": "2"}, {"name": "content", "value": "Inside the section"}],
      "patternSlug": null,
      "innerBlocks": []
    }
  ]
}
```
PROMPT;
  }

  private function get_theme_strategy_section(): string {
    if (! AI_Composer_Settings::is_theme_strategy_enabled()) {
      return '';
    }

    $is_nectar_ecosystem = $this->is_nectar_ecosystem();
    if (! $is_nectar_ecosystem) {
      return '';
    }

    $preferred_section_pattern = $this->get_preferred_section_pattern_slug();

    $lines = [];
    $lines[] = '## Theme Strategy: Nectar Ecosystem Detected';
    $lines[] = 'Nectar Blocks are available. Favor Nectar-style section composition over generic wrappers.';

    if ($preferred_section_pattern !== '') {
      $lines[] = 'Preferred section scaffold pattern: `' . $preferred_section_pattern . '`.';
      $lines[] = 'Use this as the default top-level section wrapper whenever the user asks for sections/rows.';
    } else {
      $lines[] = 'No Nectar section pattern slug is available; compose using available Nectar row/column blocks directly.';
    }

    $lines[] = 'When using Nectar blocks, keep nesting shallow and content focused inside column containers.';

    return implode("\n", $lines);
  }

  private function is_nectar_ecosystem(): bool {
    $registry = WP_Block_Type_Registry::get_instance();

    if ($registry->is_registered('nectar-blocks/row') || $registry->is_registered('nectar-blocks/column')) {
      return true;
    }

    $template = (string) get_template();
    $stylesheet = (string) get_stylesheet();

    return str_contains($template, 'nectar') || str_contains($stylesheet, 'nectar');
  }

  private function get_preferred_section_pattern_slug(): string {
    $preferred = apply_filters('ai_composer_preferred_section_patterns', [
      'ai-composer/nectar-section',
      'ai-composer/section',
    ]);

    if (! is_array($preferred)) {
      $preferred = ['ai-composer/section'];
    }

    foreach ($preferred as $slug) {
      if ($this->patterns->pattern_exists($slug)) {
        return $slug;
      }
    }

    return '';
  }

  private function get_optimize_block_section(mixed $context): string {
    $json = wp_json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (! is_string($json) || $json === 'null') {
      return '';
    }

    $truncated = mb_substr($json, 0, 8000);

    return <<<PROMPT
## Optimize Mode: Selected Block

The user wants to optimize/improve an existing block. Below is the current block structure.
Rewrite and improve it based on the user's prompt while preserving block type compatibility.
Return the improved block(s) in the same manifest format.

Current block:
```json
{$truncated}
```
PROMPT;
  }

  private function get_optimize_page_section(mixed $context): string {
    $json = wp_json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (! is_string($json) || $json === 'null') {
      return '';
    }

    $truncated = mb_substr($json, 0, 16000);

    return <<<PROMPT
## Optimize Mode: Entire Page

The user wants to optimize/improve the entire page. Below is the current page block structure.
Rewrite and improve it based on the user's prompt while preserving block type compatibility.
Return the full improved page in the same manifest format.

Current page blocks:
```json
{$truncated}
```
PROMPT;
  }
}
