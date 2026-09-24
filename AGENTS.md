# Presswell Plugin Conventions

Guidance for anyone (human or agent) changing a Presswell WordPress plugin. Every `presswell-*` plugin follows the same structure and style, so this file applies unchanged to each of them. Match the surrounding code first; use this file to settle anything the code doesn't.

## Plugin identifiers

Each plugin has a short uppercase prefix. It names the constants class, prefixes every trait and service class, and (lowercased) prefixes options, meta keys, hooks, nonces, AJAX actions, CSS classes, and database tables.

| Plugin | Prefix | Main class | Global accessor |
|---|---|---|---|
| presswell-alt-text-generator | `PWATG` | `Presswell_Alt_Text_Generator` | `presswell_alt_text_generator()` |
| presswell-art-direction | `PWAD` | `Presswell_Art_Direction` | `presswell_art_direction()` |
| presswell-content-extractor | `PWCE` | `Presswell_Content_Extractor` | `presswell_content_extractor()` |
| presswell-publication-schedule | `PWPS` | `Presswell_Publication_Schedule` | `presswell_publication_schedule()` |
| presswell-schema-manager | `PWSM` | `Presswell_Schema_Manager` | `presswell_schema_manager()` |
| presswell-signal-relay | `PWTSR` | `Presswell_Tracking_Signal_Relay` | `presswell_tracking_signal_relay()` |

Examples below use `PWXX` / `pwxx` as a stand-in for the prefix.

## Directory layout

```
presswell-{slug}/
├── presswell-{slug}.php        Plugin header, require_once list, main class, global accessor
├── readme.txt                  WordPress.org readme (Stable tag, changelog, public API docs)
├── uninstall.php               Removes the plugin's tables, options, and metadata when it is deleted
├── includes/
│   ├── helpers/class-constants.php   The PWXX constants class
│   ├── traits/trait-{area}.php       Feature areas mixed into the main class
│   ├── services/class-{name}-service.php   Logic classes with no direct hook wiring
│   ├── support/                      Procedural glue: cli.php, lifecycle-hooks.php, public-api.php
│   └── views/                        PHP templates (views/fields/ for field partials)
├── assets/css, assets/js       Plain, unbuilt admin/front-end assets
├── tests/                      PHPUnit (WP_UnitTestCase) suite + bootstrap.php + readme.md
├── bin/                        install-wp-tests.sh, wp-sync.sh
├── wp-assets/                  WordPress.org banners, icons, screenshots
├── git/, svn/                  Release mirrors managed by bin/wp-sync.sh — never edit
└── vendor/                     Composer dev dependencies — never edit
```

The plugin root is the source of truth. `git/` and `svn/` are generated copies used for publishing; changes made there are overwritten.

## Architecture

### Bootstrap

The main plugin file:

1. Guards with `if ( ! defined( 'ABSPATH' ) ) { exit; }`.
2. `require_once`s the constants class, then support files, services, and traits using `__DIR__ . '/includes/...'`. Add every new file to this list; there is no autoloader.
3. Declares the main class inside `if ( ! class_exists( ... ) )`. It is a singleton: `private static $instance`, `protected function __construct()`, empty `__clone()` / `__wakeup()`, and `public static function instance()`.
4. Defines `const PLUGIN_FILE = __FILE__;` on the main class. Resolve paths and URLs from it (`plugin_dir_path( Presswell_X::PLUGIN_FILE )`), never from `__FILE__` inside includes.
5. Defines the global accessor inside `if ( ! function_exists( ... ) )`, with a `phpcs:ignore` explaining the unprefixed name, then calls it once to boot.

Every PHP file under `includes/` starts with the same `ABSPATH` guard.

### Traits: feature areas and hook wiring

The main class is a thin container that `use`s one trait per feature area (`PWXX_Settings_Trait`, `PWXX_Assets_Trait`, `PWXX_Pages_Trait`, ...).

- A trait that registers hooks exposes `protected function construct_{area}_trait()`, and the main constructor calls it. Keep `add_action` / `add_filter` calls in these methods rather than scattered through the trait.
- Return early from `construct_*_trait()` when the area only matters in one context, e.g. `if ( ! is_admin() ) { return; }`.
- Hook callbacks are `public` methods registered as `[ $this, 'method_name' ]`. Internal helpers are `protected`.
- Traits share state and call each other freely through `$this` because they compose into one class. Static analysers flag these calls as undefined methods; that is expected.
- Split a large area into several focused traits named `trait-{area}-{subarea}.php` (e.g. `trait-cache-cli-prune.php`) instead of growing one file.
- Common trait names across plugins: `helpers` (paths, URLs, views, debug log), `settings` (option load/save/sanitize, admin-post handlers, flash notices), `pages` (menu registration, action links, admin footer), `assets` (enqueue + localize).

### Services: logic without hooks

Put non-trivial logic (queues, generators, API clients, parsers) in a service class, `PWXX_{Name}_Service` in `includes/services/class-{name}-service.php`.

- Wrap the declaration in `if ( ! class_exists( ... ) )`.
- Take dependencies through the constructor. Services that need plugin state receive the main instance (`__construct( $plugin )`) and call its public methods.
- Services don't register hooks. A trait owns the hook and delegates to the service.
- Construct services lazily behind a trait getter and cache them on a property:

  ```php
  protected function get_image_generator_service() {
    if ( null === $this->image_generator_service ) {
      $this->image_generator_service = new PWXX_Image_Generator_Service( $this );
    }

    return $this->image_generator_service;
  }
  ```

  Thin one-line trait wrappers that forward to a service method are normal and keep call sites unchanged.

### Constants

All identifiers live on the `PWXX` class in `includes/helpers/class-constants.php`: `KEY`, `VERSION`, `TEXT_DOMAIN`, `SETTINGS_KEY`, page slugs, URLs, and screen IDs, option names, meta keys, nonce actions, `admin-post` actions, AJAX actions, asset handles, localized JS object names, table slugs, and schema versions.

- Never hard-code one of these strings elsewhere; reference `PWXX::CONSTANT_NAME`.
- Build related values from each other (`const SETTINGS_PAGE_URL = 'options-general.php?page=' . self::SETTINGS_PAGE_SLUG;`).
- Group constants by purpose with a blank line (or short comment) between groups, and follow the naming already used in that plugin's constants class (`NONCE_*`, `AJAX_*`, `ACTION_*`, `OPTION_*`, `META_KEY_*`, and `HANDLE_*` or `ASSET_HANDLE_*`).

### Public API and extension points

- Template-level functions go in `includes/support/public-api.php`, prefixed `pwxx_`, each wrapped in `function_exists` and forwarding to the singleton. Treat their names and signatures as a stable public API, and document them in `readme.txt`.
- Filters are named `pwxx_{thing}` (e.g. `pwxx_debug_log_filename`). Validate a filter's return value and fall back to the default when it is the wrong type.
- Hook names, option keys, meta keys, table names, and public function names are persisted or relied on by other code. Don't rename them; add aliases if a rename is unavoidable.

## PHP style

The code follows the WordPress Coding Standards, with these house rules.

- **Indentation**: 2 spaces, LF line endings, final newline (see `.editorconfig`). No tabs.
- **Arrays**: short syntax `[]` everywhere.
- **Spacing**: spaces inside parentheses and brackets with variables, as WPCS does: `foo( $a, $b )`, `$arr[ $key ]`, `[ 'a' => 1 ]`, `! empty( $x )`.
- **Comparisons**: Yoda conditions for literals (`'on' === $value`), strict equality, and `in_array( $needle, $haystack, true )`.
- **Control flow**: guard clauses and early returns instead of deep nesting. Leave a blank line after a guard block.
- **Defensive reads**: treat option and request data as untrusted shapes. `isset( $a['k'] ) ? (string) $a['k'] : ''`, `is_array()` / `is_scalar()` checks before use, and casts at the boundary (`absint()`, `(int)`, `(float)`, `(bool)`).
- **Naming**: `snake_case` for functions, methods, and variables; descriptive names over abbreviations. Some older code uses mixed-case IDs like `$image_ID`; use `$image_id` in new code but don't rename existing parameters just for style.
- **Docblocks**: every function and method gets a one-line summary, with `@param` and `@return` when there are parameters or a return value. Say *why* in comments; don't restate what the code does.
- **`phpcs:ignore`**: allowed only with the specific sniff and a reason, e.g. `// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Queue writes intentionally run directly.`
- **Commented-out code**: delete it rather than leaving it in place.
- **Strings**: user-facing text goes through `__()` / `esc_html__()` with the plugin's text-domain string literal (`'presswell-{slug}'`). Messages meant only for developers or CLI output can be plain strings.

## Security rules

Every request handler checks **both** a capability and a nonce, and every value is sanitized on input and escaped on output.

### Request handlers

- Centralize checks in a per-area helper (`verify_settings_request()`, `verify_regenerator_request()`, `verify_attachment_request()`) instead of repeating them inline.
- Settings and tools pages (`admin_post_*` handlers, settings AJAX): require `manage_options`.
- Per-object actions (a post, an attachment): require the object's meta capability, e.g. `current_user_can( 'edit_post', $id )`, plus any feature capability such as `upload_files`, and confirm the object is the expected post type.
- Give each area its own nonce action (settings form, per-object media actions, debug tools, and so on), so a nonce issued on one screen can't authorize another's handlers. When one handler is reached from two screens, have each screen send the handler's own nonce.
- Handlers that change data require POST and refuse other methods, so nonces don't end up in URLs, server logs, or referrers. Read-only lookups can stay GET.
- A nonce proves intent, not permission. Nonces localized into scripts loaded on every admin page are available to every logged-in user, Subscribers included, so they must never be the only check.
- Read request data with `wp_unslash()`, or with the plugin's request helper when it has one (e.g. `get_request_value()` / `get_request_array()`), then sanitize for the target type (`absint`, `sanitize_key`, `sanitize_text_field`, `esc_url_raw`).
- Settings form handlers use Post/Redirect/Get: verify the request, save, queue any notice, then `wp_safe_redirect( ..., 303 )` and `exit`.

### Persisted data

- Sanitize to a whitelist of known fields before saving; don't store raw request arrays.
- Don't call `unserialize()` on stored data; use WordPress's option and meta APIs, which handle serialization.

### Output

- PHP views escape at the point of output: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()` for trusted markup.
- JavaScript must never concatenate data into HTML strings. Build elements with `$('<option>', { value: key, text: label })`, `textContent`, or `document.createElement`. Escape only static, trusted strings with a helper when a template string is unavoidable.

### Database

- Always use `$wpdb->prepare()` for values. For table names, the `%i` identifier placeholder needs WordPress 6.2+, so only use it when `Requires at least` in `readme.txt` is 6.2 or later; otherwise interpolate the internal, `$wpdb->prefix`-built name.
- Custom tables are created by a schema trait (`ensure_{name}_schema()`) that compares a stored schema-version option with a `PWXX::*_SCHEMA_VERSION` constant and runs `dbDelta()` only when they differ. Bump the constant when the schema changes.

## Admin UI

- Register pages in the `pages` trait with `add_submenu_page()` / `add_media_page()`, gated by capability.
- Render markup from templates in `includes/views/` via `$this->render_view( 'file.php', [ ...context ] )`. The context array is `extract()`ed, so pass everything the view needs explicitly and treat those names as the view's inputs. Views contain presentation logic only.
- Enqueue assets in the `assets` trait. Branch on `get_current_screen()->id` compared against `PWXX::*_SCREEN_ID` so page-specific assets only load on their page. Version assets with `PWXX::VERSION`.
- Pass data to JavaScript with `wp_localize_script()` using a `PWXX::JS_*` object name: nonces, AJAX action names, config, and translated strings.
- Notices that must survive a redirect are stored per user in a short-lived transient (e.g. `{key}_notices_{user_id}`) and shown once on the target screen, not passed through query arguments.

## JavaScript and CSS

- No build step: files in `assets/` ship as written. Don't introduce bundlers or transpiled syntax.
- Wrap each script in an IIFE (`(function($) { ... })(jQuery);` for jQuery or media-modal code, `(function() { ... })();` for plain DOM code). Bail early when the localized data object is missing.
- Use `const` / `let` in new code; some older files use `var`, so match the file you're editing.
- Prefix CSS classes and IDs with the lowercase plugin key (`pwxx-...`). Don't restyle core WordPress selectors except to position plugin UI inside them.

### Admin UI

Plugin screens should look like part of WordPress. Reach for core markup and classes before writing custom styles.

- **Tables**: use real `<table class="widefat">` markup, with `check-column` cells for selectable rows so core's select-all and shift-click work. Group related rows (a size and its thumbnails) in their own `<tbody>`.
- **Buttons**: one `button-primary` per section, for its main action; everything else is a plain `button`. Icon-only buttons carry a `screen-reader-text` label. Space buttons 8px apart (4px between icon buttons).
- **Colors**: use `var(--wp-admin-theme-color, #2271b1)` (and its `--rgb` / `-darker-*` variants) for anything that should follow the user's admin color scheme, such as progress bars and highlights. Other colors come from the core admin palette (`#f0f0f1`, `#c3c4c7`, `#646970`, `#50575e`, and the success, error, warning, and info tints).
- **Badges**: small status labels use one shared class with tone modifiers (Art Direction: `.pwad-badge` plus `is-success`, `is-error`, `is-warning`, `is-info`). Labels are sentence case, not CSS-uppercased.
- **Shape**: border radius is 2px, like core buttons, for badges, progress bars, and similar custom elements.
- Styles shared by more than one screen live in a common stylesheet registered as a dependency of each screen's stylesheet.

## WP-CLI

For plugins that provide WP-CLI commands:

- Register commands in `includes/support/cli.php`, only when `WP_CLI` is defined, under the plugin's own namespace (`wp {pwxx} {command}`).
- Keep the command closure a thin adapter that forwards `$assoc_args` to a `run_{command}_cli( $assoc_args )` method on the plugin. That method returns a result array (`[ 'ok' => bool, 'error' => string, ...counts ]`) and doesn't call `WP_CLI` itself, so it can be unit tested. Pass progress output in as a callback (Art Direction uses `$assoc_args['__status_callback']`).
- Commands that delete or overwrite data default to a dry run (`--dry-run=true`) and report what they would change.

## Testing

The suite uses PHPUnit 9 with `WP_UnitTestCase` from the WordPress core test library.

```sh
composer install
bin/install-wp-tests.sh {db_name} root '' localhost latest   # one time; use a dedicated database
vendor/bin/phpunit                                          # full suite
vendor/bin/phpunit tests/SettingsTest.php                   # one file
vendor/bin/phpunit --filter test_method_name                # one test
```

The test library drops every table in its database, so `{db_name}` must never be a site's real database.

- **Organization**: one test file per trait or service, named `{Area}Test.php`. Test methods are named `test_{behavior_in_words}()`.
- **Instance**: get the plugin with `presswell_{slug}()` in `setUp()`, and reset any state a test changes (singleton properties, filters, `$_POST` / `$_GET` / `$_REQUEST` / `$_FILES`, current user) in `tearDown()`.
- **Protected methods**: call them through a local `invoke_protected( $method, $args )` reflection helper instead of widening visibility.
- **Handlers that end the request**: capture `wp_die()`, `wp_send_json_*()`, and `wp_safe_redirect()` by adding `wp_die_handler` / `wp_die_ajax_handler` / `wp_die_json_handler` / `wp_redirect` filters that throw a test-local exception.
- **Permissions**: run handlers as a real role (`self::factory()->user->create( [ 'role' => 'editor' ] )` + `wp_set_current_user()`). Cover at least one role that must be allowed and one that must be refused, using a valid nonce so the capability check is what's tested.
- **HTTP**: mock requests with a `pre_http_request` filter. Tests must not make network calls.
- **Files**: create fixtures under the uploads directory and delete them in `tearDown()`.
- **Bug fixes**: add a regression test that fails against the pre-fix code, not just one that passes afterwards.
- **After any change**: run the full suite.

## Releases

- Bump the version in three places together: the plugin header `Version:`, `PWXX::VERSION`, and `Stable tag:` in `readme.txt`. Add a `== Changelog ==` entry describing user-visible changes.
- Keep `Requires at least` / `Tested up to` in `readme.txt` accurate for the APIs the code uses.
- Publishing is done with `bin/wp-sync.sh`, which syncs the root into `git/` and `svn/`. `.distignore` controls what ships; development-only files (tests, bin, vendor, composer files, agent docs) must stay listed there.

## Working in these repositories

- Don't edit `git/`, `svn/`, or `vendor/`.
- Don't commit or push unless asked. Each plugin is its own git repository at the plugin root.
- Keep changes scoped to the task; note unrelated problems instead of fixing them in passing.
- Don't change persisted identifiers (option names, meta keys, hook names, table names, public functions) as a side effect of other work.
