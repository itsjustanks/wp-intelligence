# WP Intelligence Verification Matrix

This document tracks runtime verification targets for major plugin contracts.

## Public Contract Inventory

### REST Namespaces and Routes

- `ai-composer/v1`
  - `POST /compose`
  - `GET /catalog`
  - `GET /status`
  - `POST /syndicate`
- `block-visibility/v1`
  - `GET/POST /settings`
  - `GET /variables`

### Core Option Keys

- `ai_composer_settings`
- `wpi_modules`
- `wpi_security`
- `wpi_performance`
- `wpi_woocommerce`
- `wpi_resource_hints`
- `block_visibility_settings`

### Key Filter/Action Surfaces

- AI Composer capability and output filters:
  - `ai_composer_capability`
  - `ai_composer_output_schema`
  - `ai_composer_manifest`
  - `ai_composer_result`
- Provider/configuration filters:
  - `ai_composer_model`
  - `ai_composer_openai_api_key`
- Runtime hardening filters:
  - `wpi_visibility_rest_public_settings_read`
  - `wpi_visibility_rest_public_variables_read`
  - `ai_composer_syndication_allow_private_hosts`
  - `wpi_remove_data_on_uninstall`

## Regression Checklist

### Syntax and Static Checks

- `php -l` on all modified PHP files.
- `node --check` on all modified JavaScript files.

### Automated Smoke Checks

- `php tests/php/run.php`
  - provider readiness behavior
  - syndication URL safety behavior

### Runtime Manual Checks (WordPress environment)

#### AI Composer

- Sidebar loads and opens in block editor.
- `new_content` mode composes blocks.
- `selected_block` mode replaces selected block only.
- `page` mode rewrites whole page.
- Insert modes:
  - append
  - replace all
  - insert after selected
- No "Block validation failed" warnings on generated output.

#### Provider Matrix

- Native AI runtime available:
  - status/readiness payload indicates native path.
  - compose can run.
- Fallback OpenAI key path:
  - status/readiness indicates openai-direct.
  - compose can run.
- No provider configured:
  - sidebar disable state and guidance shown.
  - compose returns actionable error.

#### Syndication

- Saved post flow writes source metadata.
- Unsaved post flow returns generated content without meta writes.
- Invalid/private/local URL is blocked with actionable error.

#### Block Visibility API policy

- Authenticated user can read `block-visibility/v1/settings` and `/variables`.
- Anonymous read is blocked by default.
- Anonymous read can be re-enabled with:
  - `wpi_visibility_rest_public_settings_read`
  - `wpi_visibility_rest_public_variables_read`

## Release Candidate Sign-off

- [ ] Contract inventory reviewed for backward compatibility impact.
- [ ] Syntax checks pass.
- [ ] Automated smoke checks pass.
- [ ] Manual editor/runtime checks pass in target WP environment.
- [ ] Documentation reflects any changed behavior.
