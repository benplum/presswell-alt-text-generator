=== Presswell Alt Text Generator ===
Contributors: presswell, benplum
Tags: accessibility, alt text, accessibility text, images, media library, ai, openai, anthropic, gemini, automation, bulk
Requires at least: 6.1
Tested up to: 6.5
Stable tag: trunk
License: GNU General Public License v2.0 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Generate complete, high-quality image alt text across the Media Library with AI providers like OpenAI, Anthropic, and Google Gemini.

== Description ==

Presswell Alt Text Generator helps WordPress editors remove accessibility backlogs by generating accurate alt text for new and existing images. Connect your preferred AI provider, choose a model, and run single-image or bulk generation from inside wp-admin.

**Features**

* Supports OpenAI, Anthropic Claude, and Google Gemini multimodal models
* Generates alt text for individual images directly inside the Media Library and media modal
* Adds a bulk queue that scans the site for missing alt text and processes images in batches
* Preserves rate-limit safety with automatic cool-down locks
* Provides a Media Library column showing current alt text (with quick-generate links)
* Offers a customizable prompt seed so the AI output matches your brand voice

***Media Library Tools***

Inline actions let you generate or regenerate alt text right from each image row. When an image has no description, the column shows a "Generate Alt Text" link that triggers the AJAX workflow and instantly replaces itself with the resulting text.

***Bulk Generator***

The Bulk page counts media items missing alt text, lets you filter regeneration preferences, and runs batches while surfacing progress, failures, and any rate-limit pauses.

***Prompt & Provider Controls***

A dedicated settings screen stores individual API keys per provider, default model selection, and the prompt seed that is prepended to every AI request. You can test connectivity without leaving wp-admin.

= Documentation =

**Filters**

* `pwatg_available_services` — Modify the available AI providers
* `pwatg_available_models` — Modify the available models for each provider
* `pwatg_provider_registry` — Map provider slugs to custom service classes

**WP-CLI**

If WP-CLI is available, you can run single-image generation, bulk generation, and missing-alt counts from the command line:

`
wp pwatg generate <attachment-id>
wp pwatg bulk-generate
wp pwatg count-missing
wp pwatg network-bulk-generate
`

Optional CLI flags:

* `wp pwatg generate <attachment-id> --force`
* `wp pwatg bulk-generate --force`
* `wp pwatg bulk-generate --limit=<int>`
* `wp pwatg bulk-generate --missing-only`
* `wp pwatg network-bulk-generate --force`
* `wp pwatg network-bulk-generate --limit=<int>`
* `wp pwatg network-bulk-generate --missing-only`
* `wp pwatg network-bulk-generate --sites=<id,id,...>`

== Installation ==

Install via the WordPress plugin installer or manually upload the folder to `wp-content/plugins/`.

1. Activate the plugin.
2. Visit *Settings → Alt Text Generator* to enter your API key and choose a model.
3. (Optional) Enable auto-generate-on-upload if you want every new image to receive alt text automatically.
4. Use the Bulk tool under *Media → Alt Text Bulk Generator* or inline Media Library actions.

== Frequently Asked Questions ==

= Which AI providers are supported? =

OpenAI (GPT-4.1, GPT-4o), Anthropic Claude (3.5 Haiku/Sonnet, 3 Opus), and Google Gemini (2.0 Flash, 1.5 Flash/Pro). You can extend the provider registry to add more.

= Does the plugin store my API keys? =

Keys are stored in the site options table. They are used only when calling the selected provider from your server.

= Can I regenerate specific images without touching others? =

Yes. Use the inline "Regenerate Alt Text" link in the Media Library or media modal to update a single attachment without affecting the rest of the queue, or run `wp pwatg generate <attachment-id> --force`.

= Can I run alt generation from WP-CLI? =

Yes. Use `wp pwatg generate <attachment-id>` for one image, `wp pwatg bulk-generate` for a batch run, and `wp pwatg count-missing` to check remaining backlog.

= Can I run bulk generation network-wide on Multisite? =

Yes. Use `wp pwatg network-bulk-generate` to process all sites in the network, or pass `--sites=<id,id,...>` to target specific site IDs.

= How does rate limiting work? =

If a provider responds with a retry-after header, the plugin sets a transient lock and pauses both single and bulk runs until the cooldown expires.

== Screenshots ==

1. Settings screen with provider, model, and prompt controls
2. Media Library showing inline Generate/Regenerate links and alt text previews
3. Bulk generator queue with progress indicators and retry messaging

== Changelog ==

= 0.1.0 =
* Initial release with provider integrations, Media Library tools, and bulk generator.
