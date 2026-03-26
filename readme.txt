=== Presswell Alt Text Generator ===
Contributors: presswell, benplum
Tags: accessibility, alt text, media library, ai, openai, anthropic, gemini, image seo, automation, wp-cli
Requires at least: 6.1
Tested up to: 6.5
Stable tag: trunk
License: GNU General Public License v2.0 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Generate accessible image alt text in WordPress with OpenAI, Anthropic, and Google Gemini.

== Description ==

Presswell Alt Text Generator helps teams reduce accessibility backlog by generating descriptive alt text for new and existing images. Connect a provider, choose a model, and run generation workflows directly inside wp-admin.

**Features**

* Supports OpenAI, Anthropic Claude, and Google Gemini multimodal models
* Generates alt text for individual images in the Media Library and media modal
* Processes missing-alt backlogs in batches with a dedicated bulk queue
* Handles provider rate limits with automatic cooldown locks
* Adds a Media Library column with quick generate/regenerate actions
* Provides a customizable prompt seed for output consistency

***Media Library Tools***

Inline actions let you generate or regenerate alt text directly from each image row. When an image has no alt text, the column displays a "Generate Alt Text" link that triggers the AJAX workflow and replaces itself with the result.

***Bulk Generator***

The Bulk page counts images missing alt text, allows regeneration preferences, and runs batches while surfacing progress, failures, and rate-limit pauses.

***Prompt and Provider Controls***

A dedicated settings screen stores provider API keys, default model selection, and the prompt seed prepended to each request. You can test provider connectivity without leaving wp-admin.

= Documentation =

**Filters**

* `pwatg_available_services` — Modify the available AI providers
* `pwatg_available_models` — Modify the available models for each provider
* `pwatg_provider_registry` — Map provider slugs to custom service classes

**WP-CLI**

If WP-CLI is available, you can run single-image generation, bulk generation, and missing-alt counts from the command line:

	wp pwatg generate <attachment-id>
	wp pwatg bulk-generate
	wp pwatg count-missing
	wp pwatg network-bulk-generate

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
2. Visit *Settings -> Alt Text Generator* to enter your API key and choose a model.
3. (Optional) Enable auto-generate-on-upload if you want every new image to receive alt text automatically.
4. Use the Bulk tool under *Media -> Alt Text Bulk Generator* or inline Media Library actions.

== Frequently Asked Questions ==

= Which providers are supported? =

OpenAI (GPT-4.1, GPT-4o), Anthropic Claude (3.5 Haiku/Sonnet, 3 Opus), and Google Gemini (2.0 Flash, 1.5 Flash/Pro). You can extend the provider registry to add more.

= Where are API keys stored? =

Keys are stored in the site options table. They are used only when calling the selected provider from your server.

= Can I regenerate specific images without affecting others? =

Yes. Use the inline "Regenerate Alt Text" link in the Media Library or media modal to update a single attachment without affecting the rest of the queue, or run `wp pwatg generate <attachment-id> --force`.

= Can I run generation from WP-CLI? =

Yes. Use `wp pwatg generate <attachment-id>` for one image, `wp pwatg bulk-generate` for a batch run, and `wp pwatg count-missing` to check remaining backlog.

= Can I run bulk generation network-wide on Multisite? =

Yes. Use `wp pwatg network-bulk-generate` to process all sites in the network, or pass `--sites=<id,id,...>` to target specific site IDs.

= How does rate limiting work? =

If a provider responds with a retry-after header, the plugin sets a transient lock and pauses both single and bulk runs until the cooldown expires.

== Privacy ==

This plugin sends image-derived payloads to the AI provider you choose when generating alt text. API keys are stored in WordPress options on your site and used only for outbound provider requests initiated by your server.

== Screenshots ==

1. Settings screen with provider, model, prompt, and API key controls.
2. Media Library column with inline Generate/Regenerate actions.
3. Bulk generator queue with progress and retry status.

== Changelog ==

= 1.0.0 =
* First public release with provider integrations, Media Library tools, and bulk generator.
