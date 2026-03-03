# WP Intelligence Baseline Snapshot

Baseline date: 2026-03-03

This snapshot documents expected behavior for key contracts after the current implementation pass.
Use this file as a regression reference for future changes.

## 1) AI Composer REST status baseline

Route: `GET /wp-json/ai-composer/v1/status`

Expected top-level keys:

- `available` (bool)
- `provider` (object)
- `readiness` (object)
- `abilities_api` (bool)
- `version` (string)

Expected readiness keys:

- `can_compose` (bool)
- `runtime` (`wp-ai-client` | `openai-direct` | `none`)
- `native` (bool)
- `configured` (bool|null)
- `requires_configuration` (bool)
- `message` (string)
- `model` (string)

## 2) Syndication request contract baseline

Route: `POST /wp-json/ai-composer/v1/syndicate`

Canonical input:

- `url` (required)
- `prompt` (optional)
- `post_id` (optional, integer)

Compatibility alias accepted:

- `postId` (legacy alias; normalized to `post_id`)

Removed from canonical client payload:

- `postType` (no longer sent by editor client)

## 3) Block Visibility read policy baseline

Routes:

- `GET /wp-json/block-visibility/v1/settings`
- `GET /wp-json/block-visibility/v1/variables`

Default policy:

- Authenticated users with `edit_posts` capability can read.
- Anonymous/public read is denied by default.

Public-read opt-in filters:

- `wpi_visibility_rest_public_settings_read`
- `wpi_visibility_rest_public_variables_read`

Capability override filters:

- `wpi_visibility_rest_settings_read_capability`
- `wpi_visibility_rest_variables_read_capability`

## 4) Syndication URL safety baseline

Default URL policy:

- Allows public `http(s)` URLs.
- Rejects local/private hosts (e.g. localhost, loopback, private ranges, reserved/local TLD patterns).

Override filter:

- `ai_composer_syndication_allow_private_hosts`

## 5) Error response baseline

Recoverable errors from AI Composer and Syndication now include status metadata in `WP_Error` data.

Representative statuses used:

- `400` invalid request/input/validation
- `403` blocked upstream
- `422` unreadable/extractable content failures
- `502` upstream/provider response failures
- `503` provider unavailable

## 6) Automated baseline checks executed

### PHP syntax checks

Executed `php -l` for all changed PHP files. Result: all pass.

### JavaScript syntax checks

Executed `node --check` for changed JS files. Result: all pass.

### PHP smoke checks

Executed:

```bash
php tests/php/run.php
```

Expected output:

- `PASS: test-provider-readiness.php`
- `PASS: test-syndication-url-validation.php`
- `All PHP smoke tests passed.`
