# Presswell Alt Text Generator Tests

## One-time setup

1. `cd wp-content/plugins/presswell-alt-text-generator`
2. `composer install`
3. `bin/install-wp-tests.sh pwatg_tests root '' localhost latest`

Use a dedicated database, never the site's own. The WordPress test suite drops and recreates its tables on every run.

## Run tests (Composer shortcuts)

- Full suite: `composer phpunit`
- Single file: `composer phpunit:file -- tests/MediaTraitTest.php`
- Single test method: `composer phpunit:filter -- --filter test_generate_alt_text_updates_metadata tests/MediaTraitTest.php`

## Run tests (direct PHPUnit)

- Full suite: `vendor/bin/phpunit`
- Single file: `vendor/bin/phpunit tests/MediaTraitTest.php`
- Single test method: `vendor/bin/phpunit --filter test_generate_alt_text_updates_metadata tests/MediaTraitTest.php`

## Optional WordPress test groups

By default, WordPress prints notices that some groups are skipped. You can run them explicitly:

- AJAX group: `vendor/bin/phpunit --group ajax`
- Multisite files group: `vendor/bin/phpunit --group ms-files`
- External HTTP group: `vendor/bin/phpunit --group external-http`

## Test coverage map

- `CorePluginIntegrationTest.php`: plugin bootstrap/singleton wiring, key hook registration, settings action link, and which screens load the settings, bulk, and media assets.
- `SettingsTest.php`: settings defaults and sanitization, core connector defaults (the first connector with a key), current model lists, retired-model fallback, and the models filter.
- `SettingsPageTest.php`: Test Connection notices (per user, using the saved key), and API keys kept out of the page and kept, replaced, or removed on save.
- `MediaTraitTest.php`: attachment generation, generation on upload (new uploads only, filterable), and the Media Library column and row action.
- `ImagePreparationTest.php`: which file is sent (web-sized, on disk), JPEG conversion for AVIF/GIF, the site-language instruction, and word-boundary trimming.
- `SingleMediaAjaxTest.php`: single-image AJAX success, errors, permissions, POST-only, and restoring overwritten alt text.
- `BulkTraitTest.php`: bulk init and cursor-based batches, halting on provider limits, missing-only test runs, POST-only, and the rate-limit lock.
- `ProvidersTest.php`: provider adapters (OpenAI/Anthropic/Gemini), payloads, the Gemini key header, and how each provider's errors map to pauses.
- `CliTest.php`: WP-CLI logic: dry runs for overwrites, progress callbacks, provider-limit halts, and single-image results.
- `DebugLogTest.php`: private log location, rotation, 7-day auto-off, the Debug tab viewer and download, and their access checks.
- `HelpersTraitTest.php`: debug log context redaction and truncation.
- `UninstallTest.php`: what deleting the plugin removes (API keys, logs, locks, notices always; settings and history when opted in) and that alt text is kept.

## Useful notes

- `tests/helpers/class-pwatg-test-provider.php` is a shared provider stub used by media/bulk/settings page tests to avoid live API calls.
- Full suite should pass with `Exit code 0`; if failures mention missing WordPress test libs, rerun `bin/install-wp-tests.sh`.
- PHPUnit 9 accepts one path argument; pass a single file or use `--filter` across the suite.
- If your shell autocorrects `test`, prefer the `composer phpunit` aliases above.