# WP Intelligence

WP Intelligence is a standalone WordPress plugin that adds AI-assisted page composition to the block editor.

It is designed to be:

- Theme-agnostic by default.
- Compatible with both modern and older WordPress AI environments.
- Safe with Gutenberg validation by inserting normalized block objects, not fragile handcrafted markup.

## Features

- Prompt-to-page composition in the block editor sidebar.
- Uses your live block registry (core + custom + third-party blocks).
- Uses registered patterns plus built-in plugin patterns.
- Supports slot-aware pattern injection (`ai-composer-slot`) for section wrappers.
- Returns both:
  - `blockTree` (primary output for editor insertion), and
  - serialized block grammar (`blocks`) for compatibility.
- Includes a settings page for:
  - block allow-list mode,
  - model/API key fallback configuration,
  - theme strategy toggle,
  - prepend/append system prompt instructions.
- Includes REST API and WordPress Abilities API integration.

## Compatibility Matrix

| Environment | Provider path used | What you must configure | Expected behavior |
|---|---|---|---|
| WordPress 7.0+ with native AI client available (`wp_ai_client_prompt`) | Native WordPress AI client | Configure credentials in WordPress AI Credentials | Plugin prefers native path automatically |
| WordPress 6.5-6.x (no native AI client function) | Direct OpenAI API fallback | Add OpenAI API key in `Settings > WP Intelligence` (or constant/filter) | Compose works through OpenAI chat completions API |
| WordPress 7.0+ native client present but AI credentials missing | Native path still selected | Add credentials in AI settings | Compose request returns provider error until credentials are set |
| Any supported WordPress with no configured provider path | None | Configure one of the above | REST status shows unavailable, compose returns error |

### Important Compatibility Notes

- WP Intelligence does **not** require WordPress 7.0 to run.
- WP Intelligence does **not** break on latest WordPress releases; when native APIs exist, they are used first.
- Legacy internal namespaces are preserved for backward compatibility:
  - REST namespace: `ai-composer/v1`
  - PHP classes: `AI_Composer_*`
  - filters/actions: `ai_composer_*`

## Requirements

- WordPress `6.5+`
- PHP `8.0+`
- Block editor environment (Gutenberg in WordPress core)
- AI provider configuration:
  - Native WordPress AI credentials (preferred when available), or
  - OpenAI API key fallback

## Installation

1. Place plugin folder at:
   - `wp-content/plugins/wp-intelligence`
2. Activate **WP Intelligence** in WP Admin.
3. Configure provider/settings:
   - `Settings > WP Intelligence`
4. Open the block editor and launch the **WP Intelligence** sidebar.

## Quick Start

1. Open a page/post in the block editor.
2. Open **WP Intelligence** sidebar from the editor menu.
3. Enter a detailed prompt (sections, tone, content goals).
4. Choose insert mode:
   - Append
   - Replace all
   - Insert after selected block
5. Click **Compose**.

## How It Works

1. Sidebar sends prompt to `POST /wp-json/ai-composer/v1/compose`.
2. Prompt engine builds system prompt from:
   - available blocks,
   - available patterns,
   - strategy rules,
   - optional settings prepend/append.
3. Provider layer chooses:
   - native WP AI client first, else
   - direct OpenAI fallback.
4. AI returns strict JSON manifest.
5. Manifest compiler validates:
   - known blocks only,
   - allowed blocks only,
   - known patterns only,
   - nested pattern content also allowed/known.
6. Compiler outputs normalized `blockTree`.
7. Editor creates real Gutenberg blocks via `wp.blocks.createBlock(...)`.

This reduces block validation drift compared to custom HTML serialization.

## REST API

Base namespace: `ai-composer/v1`

- `POST /compose`
  - Required: `prompt`
  - Optional: `template`, `compose_mode`, `insert_mode`
  - Returns: `success`, `manifest`, `blockTree`, `blocks`, `summary`
- `GET /catalog`
  - Returns discovered composable blocks and patterns
- `GET /status`
  - Returns provider availability, provider info, abilities API availability, plugin version

Permission for REST compose/catalog/status uses:
- `current_user_can( apply_filters('ai_composer_capability', 'edit_posts') )`

## Abilities API

When `wp_register_ability` exists, plugin registers:

- `ai-composer/compose-page`
- `ai-composer/list-blocks`

This enables capability discovery/use by tools that consume WordPress abilities.

## Configuration

Settings are stored in option:
- `ai_composer_settings`

Admin page includes:
- Provider fields (shown only when native WP AI client is unavailable)
- Block Library selector (all vs selected mode)
- Theme-aware strategy toggle
- System prompt prepend/append

Composable core blocks are always forced enabled in selected mode to prevent dead-end compositions.

## Extending WP Intelligence

Use filters/actions to adapt behavior without forking plugin code.

### Core filters

- `ai_composer_block_catalog`
- `ai_composer_composable_blocks`
- `ai_composer_pattern_catalog`
- `ai_composer_always_allowed_blocks`
- `ai_composer_available_models`
- `ai_composer_model_preferences`
- `ai_composer_model`
- `ai_composer_openai_api_key`
- `ai_composer_temperature`
- `ai_composer_system_prompt`
- `ai_composer_output_schema`
- `ai_composer_manifest`
- `ai_composer_result`
- `ai_composer_block_tree`
- `ai_composer_block_grammar`
- `ai_composer_section_pattern_slugs`
- `ai_composer_capability`

### Core actions

- `ai_composer_init`
- `ai_composer_before_compose`
- `ai_composer_after_compose`
- `ai_composer_composition_error`

### Example: enrich custom block descriptions

```php
add_filter('ai_composer_block_catalog', function (array $catalog): array {
  if (isset($catalog['acf/pricing-table'])) {
    $catalog['acf/pricing-table']['description'] = 'Displays pricing tiers with CTA buttons.';
  }
  return $catalog;
});
```

### Example: tighten permission capability

```php
add_filter('ai_composer_capability', function (): string {
  return 'publish_pages';
});
```

### Example: set fallback OpenAI key from environment

```php
add_filter('ai_composer_openai_api_key', function (): string {
  return getenv('WP_INTELLIGENCE_OPENAI_KEY') ?: '';
});
```

## Pattern and Slot Behavior

- Built-in patterns are loaded from plugin `patterns/*.php`.
- Section-like patterns can expose an `ai-composer-slot` class for target injection.
- Compiler includes heuristics to coalesce sibling content into section patterns when appropriate.

## Security

- Compose/candidate endpoints require authenticated user capabilities.
- Prompt and context payloads are sanitized.
- Manifest is validated before insertion.
- Unknown/disallowed blocks are rejected.

## Troubleshooting

### "No AI provider configured"

Check:
- WordPress native AI credentials (if native client exists), or
- plugin OpenAI API key fallback in settings.

### "Invalid schema ... additionalProperties must be false"

If you customize schema via `ai_composer_output_schema`, keep strict-schema rules:
- every object should define `additionalProperties: false`
- required arrays/properties must stay consistent with strict mode.

### "Block validation failed"

WP Intelligence inserts via normalized `blockTree` and `createBlock` to reduce this. If still seen:
- verify custom blocks support their provided attributes,
- verify filters are not injecting malformed attributes,
- verify pattern content parses into valid blocks.

### "Disallowed block type"

Check block settings mode and allowed list in plugin settings, plus any custom allow-list filters.

## Development

```bash
cd wp-content/plugins/wp-intelligence
php -l wp-intelligence.php
php -l includes/class-ai-composer.php
php -l includes/class-provider.php
php -l includes/class-manifest-compiler.php
```

Then run editor smoke tests:

- compose with append/replace/insert-after
- compose using a known custom pattern
- compose with fallback provider path (if no native AI client)
- validate no Gutenberg block recovery warnings

## WordPress.org Readiness

This repository uses a GitHub-style `README.md`. For WordPress.org distribution, also add:

- plugin `readme.txt` in official format
- stable tag/release discipline
- tested-up-to metadata updates per core release

## License

GPL-2.0-or-later
