=== Presswell Alt Text Generator ===
Contributors: presswell, benplum
Tags: alt text, accessibility, ai
Requires at least: 4.0
Tested up to: 6.4.2
Stable tag: trunk
License: GNU General Public License v2.0 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Generate AI-powered alt text for WordPress media uploads and bulk runs.

== Description ==

Presswell Alt Text Generator adds AI-powered alt text generation to the media library, including single-image actions and bulk workflows.

== Development Standards ==

Use helper methods/constants for naming consistency; avoid hardcoded plugin endpoint strings in runtime PHP code.

**Text domain**

* Use `get_text_domain()` for translations.
* Pass `$text_domain` into views and use that in `esc_html__()` / `__()` calls.

**Plugin identity and routing helpers**

* Use class helpers for all runtime identifiers:
	* `get_plugin_key()`
	* `get_option_key()`
	* `get_settings_page_slug()` / `get_bulk_page_slug()`
	* `get_settings_screen_id()` / `get_bulk_screen_id()`
	* `get_settings_page_url()` / `get_bulk_page_url()`
	* `get_asset_handle( $suffix )` / `get_asset_url( $relative_path )`

**Hooks, action tags, and nonces**

* Build plugin action tags through helper methods:
	* `get_action_name( $suffix )`
	* `get_admin_post_hook( $suffix )`
	* `get_ajax_hook( $suffix )`
	* `get_nonce_action( $suffix )`
* Register `admin_post_*` / `wp_ajax_*` handlers with these helpers instead of hardcoded tags.

**Shared constants**

* Use constants for shared keys where available (`OPTION_KEY`, `NOTICE_KEY`, `TEST_NOTICE_KEY`, `TEXT_DOMAIN`, etc.).

**Code style**

* Prefer short array syntax (`[]`) in active plugin code.

**Structure conventions**

* Organize runtime code under `includes/` by role:
	* `includes/traits` for composition traits
	* `includes/services` for business/data workflow classes
	* `includes/helpers` for reusable utility/helper classes (non-service)
	* `includes/views` for templates only
* Keep plugin bootstrap files focused on wiring and delegation.
