# AGENTS.md

Guidance for AI coding agents (Cursor, Claude Code, and similar) working in the WP Intelligence repository.

## Project Intent

WP Intelligence is a standalone, theme-agnostic plugin that adds AI-assisted Gutenberg composition.

When making changes, optimize for:

- portability across themes/sites,
- safety in block editor serialization/validation,
- compatibility across WordPress versions (with and without native AI APIs),
- backward compatibility of existing extension hooks.

## Non-Negotiable Guardrails

1. Keep the plugin standalone
- Do not add hard dependencies on a specific theme, pattern pack, or hosting stack.
- Theme-specific behavior must be implemented via filters/actions, not hardcoded.

2. Preserve compatibility contracts
- Existing internal identifiers are legacy and must remain stable unless a deliberate breaking-release plan exists:
  - REST namespace: `ai-composer/v1`
  - class prefix: `AI_Composer_*`
  - hooks/options: `ai_composer_*`
- Public branding can be "WP Intelligence", but runtime identifiers are compatibility surface.

3. Do not reintroduce fragile manual HTML block rendering
- The canonical insertion output is normalized `blockTree`.
- Editor should create blocks with Gutenberg APIs (`createBlock`) to avoid save/validation drift.

4. Keep provider fallback behavior intact
- If `wp_ai_client_prompt` exists, native path is preferred.
- Otherwise fallback to direct OpenAI path.
- Do not make WP 7.0 APIs mandatory.

5. Keep strict output-schema discipline
- If changing AI output schema, preserve strict compatibility rules:
  - object schemas define `additionalProperties: false`
  - required arrays/properties are complete
  - attribute-pair conventions remain consistent with compiler normalization.

6. Never weaken permission checks silently
- Compose endpoints must remain capability-gated.
- Capability can be filterable, but default should remain conservative (`edit_posts`).

## Architecture Map

- `wp-intelligence.php`
  - bootstrap, constants, includes, plugin metadata.
- `includes/class-ai-composer.php`
  - orchestrator; initializes routes/settings/abilities and composition flow.
- `includes/class-provider.php`
  - provider abstraction:
    - native WP AI client path,
    - OpenAI fallback path.
- `includes/class-prompt-engine.php`
  - system prompt assembly + strict output JSON schema.
- `includes/class-manifest-compiler.php`
  - manifest validation, pattern expansion checks, block tree generation, grammar serialization.
- `includes/class-block-catalog.php`
  - block discovery and composable filtering.
- `includes/class-pattern-catalog.php`
  - registered pattern discovery + built-in pattern loading.
- `includes/class-rest-controller.php`
  - REST endpoints (`compose`, `catalog`, `status`) and permission callback.
- `includes/class-abilities-bridge.php`
  - WordPress Abilities API registration when available.
- `includes/class-settings.php`
  - admin settings UI and sanitized persistence.
- `editor/sidebar.js`, `editor/sidebar.css`
  - Gutenberg sidebar UI and insertion behavior.
- `patterns/*.php`
  - built-in patterns, including slot-enabled section scaffolds.

## Provider and Version Compatibility Rules

When editing provider behavior:

- Treat native AI client support as optional runtime capability.
- Ensure older WordPress versions still function through fallback.
- Avoid fatal paths if native APIs are absent.
- Keep fallback key resolution order deterministic:
  - constant,
  - environment variable,
  - stored settings,
  - filter.

When editing docs, always explain:

- how WP 7.0+ native AI path is used,
- how pre-7.0 fallback works,
- what error users should expect when neither path is configured.

## Prompting and Manifest Rules

When modifying prompt/schema/compiler behavior:

- Update prompt examples and schema together.
- Ensure compiler normalization still matches emitted schema format.
- Preserve or improve validation messages; do not hide validation failures.
- Keep pattern validation recursive (including nested parsed pattern blocks).
- Keep section-slot and section-coalescing behavior predictable.

## Block Safety Rules

- Only allow registered block types.
- Respect allow-list settings and forced core composable blocks.
- Do not permit invented block names.
- For ACF/custom data attributes, maintain current nested-key handling (`data.field_name` style support).

## Extension Surface (Do Not Break Lightly)

Maintain behavior for these major hooks:

- Filters:
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
- Actions:
  - `ai_composer_init`
  - `ai_composer_before_compose`
  - `ai_composer_after_compose`
  - `ai_composer_composition_error`

If any hook behavior changes, document it in `README.md` and changelog/release notes.

## Coding Standards

General:

- Keep code ASCII unless file already uses non-ASCII.
- Add concise comments only where logic is non-obvious.
- Keep functions small and explicit.

PHP:

- Prefer strict, typed signatures where already used.
- Always sanitize incoming request/settings data.
- Return `WP_Error` with actionable messages for recoverable failures.

JavaScript (editor UI):

- Prefer native Gutenberg stores and APIs over custom state hacks.
- Keep insertion logic based on normalized block tree.
- Preserve backward fallback parsing path unless intentionally removed.

Security:

- No plaintext secret logging.
- No exposing API keys in REST output.
- Keep permission callbacks mandatory for mutating operations.

## Testing Checklist for Changes

Run these before claiming completion:

1. Syntax checks
- `php -l` on every modified PHP file.

2. Runtime checks (manual)
- plugin activates without fatal errors,
- sidebar loads in editor,
- compose success path inserts blocks,
- compose failure path surfaces errors cleanly.

3. Compatibility checks
- native provider path (if available),
- fallback provider path (when native absent),
- no-provider path (clear error).

4. Validation checks
- no Gutenberg "Block validation failed" warnings for typical outputs,
- unknown/disallowed block attempts are rejected with clear messages.

## Documentation Rules

For user-facing docs:

- explain compatibility matrix clearly,
- explain provider resolution clearly,
- include extension hook examples,
- avoid assumptions about a specific theme or block library.

For contributor-facing docs:

- keep architecture map current,
- list compatibility constraints and non-negotiables,
- include verification checklist.

## Release and Versioning Guidance

When publishing releases:

- bump version in plugin header and version constant together,
- update `README.md` for new features/breaking changes,
- if targeting WordPress.org, maintain official `readme.txt` format separately,
- keep migration notes explicit when internal names or APIs change.

## Change Review Heuristics

High risk changes (require extra review):

- provider routing logic,
- schema/compiler changes,
- permission callbacks,
- hook removals/renames,
- editor insertion logic.

Lower risk changes:

- copy updates,
- docs-only edits,
- additive filters/actions with no default behavior change.

## Agent Workflow Expectations

When asked to implement:

1. Read relevant files first.
2. Identify extension points instead of hardcoding site-specific logic.
3. Make minimal, targeted edits.
4. Run syntax/lint checks.
5. Summarize:
   - what changed,
   - why,
   - what to verify next.

When asked to review:

- prioritize bugs, regressions, security, and compatibility gaps first.
- keep summaries brief; lead with findings.

## Public API Contracts

These contracts are treated as public behavior and should not be changed without migration notes.

### REST contracts

Namespace:

- `ai-composer/v1`

Routes:

- `POST /compose`
  - accepts: `prompt`, optional `template`, `compose_mode`, `insert_mode`, optional context payloads
  - returns: `success`, `blocks`, `blockTree`, `manifest`, `summary`
- `GET /catalog`
  - returns: composable block catalog and pattern catalog
- `GET /status`
  - returns: `available`, `provider`, `abilities_api`, `version`

Rules:

- keep route names stable
- keep response keys backward compatible
- additive fields are acceptable; removals/renames are breaking

### Settings contracts

Storage option:

- `ai_composer_settings`

Current keys include:

- provider keys (`api_key`, `model`) for fallback mode
- block selection keys (`block_selection_mode`, `enabled_blocks`)
- prompting keys (`theme_strategy_enabled`, `system_prompt_prepend`, `system_prompt_append`)

Rules:

- sanitize all settings writes
- preserve unknown keys where reasonable during migrations
- avoid silent shape changes

### Frontend/editor contracts

- localized JS config key remains `aiComposerConfig`
- script/style handles remain stable unless migration is provided
- `blockTree` insertion path is primary; `blocks` parse path remains compatibility fallback

## Data Model and Normalization Rules

Manifest authoring model:

- each block item must include:
  - `blockType`
  - `attributes`
  - `patternSlug`
  - `innerBlocks`

Attribute representation contract:

- model emits attribute pairs (`[{name, value}]`)
- compiler normalizes into associative/nested attributes
- values may be stringified scalars or JSON payload strings

Normalization requirements:

- preserve boolean/number/object semantics where possible
- preserve dotted-key mapping for nested data (`data.field_name`)
- avoid lossy transformations unless explicitly documented

## Error Taxonomy and UX Expectations

When returning `WP_Error`, use stable codes and actionable messages.

Common categories:

- provider unavailable / configuration errors
- upstream AI request errors
- invalid manifest/schema mismatch
- unknown/disallowed block usage
- unknown pattern usage

Guidelines:

- prefer specific error codes over generic failures
- include contextual details for debugging when safe
- do not leak secrets/tokens in error payloads
- keep editor-facing messages human-readable

## Performance and Reliability Guidelines

General:

- avoid excessive repeated registry lookups in hot paths
- use lightweight caching for discovered catalogs where possible
- avoid expanding prompt payloads with full pattern HTML unless required

Prompting:

- keep system prompts deterministic and compact
- avoid unnecessary randomness in composition defaults
- preserve low-temperature deterministic behavior unless explicitly changed

REST latency:

- avoid expensive synchronous work inside request handlers beyond validation/compile flow
- no blocking network calls except provider request itself

## Security and Privacy Requirements

Input handling:

- sanitize all REST arguments and context payloads
- bound nested context depth/size to avoid prompt bloat and abuse

Output handling:

- validate all AI output before editor insertion
- reject unknown block/pattern references

Credentials:

- never expose API keys in REST responses, logs, or notices
- prefer environment/constant storage for production where possible

Privacy:

- assume prompts may contain business-sensitive content
- do not add telemetry/export behavior without explicit opt-in and documentation

## Internationalization and Accessibility

Internationalization:

- wrap user-facing strings in translation functions
- keep text domain compatibility for existing strings
- avoid concatenation patterns that break localization context

Accessibility:

- ensure editor sidebar controls remain keyboard accessible
- preserve semantic labels/help text for controls
- maintain readable notice/error feedback in editor UI

## WordPress.org Distribution Rules

If preparing for wordpress.org release:

- maintain plugin header metadata consistency
- include and maintain official `readme.txt` format
- keep tested-up-to and minimum versions current
- avoid bundling proprietary SDKs or license-restricted assets
- keep licensing notices clear and GPL-compatible

## Deprecation and Migration Policy

When deprecating identifiers (hooks/classes/routes/options):

1. add compatibility shim first
2. mark deprecated in docs/changelog
3. emit soft warnings where appropriate
4. remove only in a planned major release with migration notes

Do not rename legacy `ai_composer_*` or `AI_Composer_*` symbols casually.

## PR and Change Checklist

Before merge:

- [ ] backward compatibility impact reviewed
- [ ] syntax checks run on touched PHP files
- [ ] manual editor smoke test performed
- [ ] provider compatibility verified (native/fallback/no-provider)
- [ ] docs updated for behavior changes
- [ ] new hooks/routes/settings keys documented

For high-risk changes, also:

- [ ] review by another maintainer/agent
- [ ] explicit rollback plan documented

## Minimal Verification Matrix

Run this matrix for release candidates:

Environment A:

- WordPress with native AI client available
- compose success path and failure path

Environment B:

- WordPress without native AI client, OpenAI fallback configured
- compose success path and failure path

Environment C:

- provider absent
- confirm clean "unavailable" status and actionable admin guidance

Content checks:

- simple core-only page
- mixed core + custom block page
- pattern-based section composition with nested content injection
- invalid/disallowed block rejection path

## Definition of Done for Agents

A task is done only when:

- code changes satisfy requested behavior,
- compatibility contracts are preserved or explicitly documented,
- verification steps are run and reported with evidence,
- documentation is updated if public behavior changed.
