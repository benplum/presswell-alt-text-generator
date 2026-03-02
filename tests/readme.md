# Presswell Alt Text Generator Tests

## One-time setup

1. `cd /Users/bp/Sites/blockparty/wp-content/plugins/presswell-alt-text-generator`
2. `composer install`
3. `bin/install-wp-tests.sh wordpress_test root '' localhost latest`

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

- `CorePluginIntegrationTest.php`: plugin bootstrap/singleton wiring, key hook registration, settings action link, and admin asset localization for settings + bulk screens.
- `SettingsTest.php`: settings defaults, merging, and sanitization for service/model/prompt/API keys.
- `SettingsPageTest.php`: settings page connection test handler notices and redirect flow.
- `MediaTraitTest.php`: attachment generation workflow, upload auto-generation behavior, and media-library column rendering.
- `SingleMediaAjaxTest.php`: single-image AJAX endpoint success/error/permission behavior.
- `BulkTraitTest.php`: bulk init/generate AJAX behavior, missing-only test runs, and rate-limit lock handling.
- `ProvidersTest.php`: provider request/response adapters (OpenAI/Anthropic/Gemini), payload shape validation, and error mapping.

## Useful notes

- `tests/helpers/class-pwatg-test-provider.php` is a shared provider stub used by media/bulk/settings page tests to avoid live API calls.
- Full suite should pass with `Exit code 0`; if failures mention missing WordPress test libs, rerun `bin/install-wp-tests.sh`.
- If your shell autocorrects `test`, prefer the `composer phpunit` aliases above.