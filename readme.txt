=== Presswell Alt Text Generator ===
Contributors: presswell, benplum
Tags: accessibility, alt text, media library, ai, image seo
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate accessible image alt text in WordPress with OpenAI, Anthropic, and Google Gemini.

== Description ==

Presswell Alt Text Generator helps teams reduce accessibility backlog by generating descriptive alt text for new and existing images. Connect a provider, choose a model, and run generation workflows directly inside wp-admin.

**Features**

* Supports OpenAI, Anthropic Claude, and Google Gemini through WordPress AI Connectors (recommended, WordPress 7.0+) or, deprecated, with API keys stored by the plugin
* On WordPress 7.0+, can send requests through the WordPress AI Client, so the plugin stores no key
* Generates alt text for individual images in the Media Library, the media modal, and the Image block
* Generates alt text for new uploads automatically (optional)
* Processes missing-alt backlogs in batches with a dedicated bulk queue
* Writes alt text in your site's language
* Restores the previous alt text if a regeneration isn't an improvement
* Handles provider rate limits and quota errors with automatic pauses
* Provides a customizable prompt seed for output consistency

***Media Library Tools***

An Alt Text column shows each image's alt text, and a "Generate Alt Text" or "Regenerate Alt Text" row action updates it in place. The same button appears in the media modal and on the attachment edit screen, along with "Restore previous alt text" after a regeneration.

***Block Editor***

Select an Image block to find an Alt Text Generator panel in its sidebar. It fills in the block's alt text and updates the image in the Media Library.

***Bulk Generator***

The Bulk page counts images missing alt text, allows regeneration preferences, and runs batches while surfacing progress, failures, and rate-limit pauses.

***Debug Tab***

*Settings -> Alt Text Generator -> Debug* shows how requests are sent and what can stop them (API key, rate limits, bulk runs, images missing alt text), a *Test Connection* button, and the private debug log.

***Connector Source***

WordPress AI Connectors (*Settings -> Connectors*, WordPress 7.0+) are the recommended setup and the default for new installs. The *Plugin* connector source, which stores API keys in this plugin's settings, is deprecated and will be removed in a future release; the settings screen shows a warning while it is selected. On WordPress versions without AI Connectors, the plugin keeps using its own keys.

= Documentation =

**Filters**

* `pwatg_available_services` — Modify the available AI providers
* `pwatg_available_models` — Modify the available models for a provider (receives the models and the provider slug; the first model is the default)
* `pwatg_provider_registry` — Map provider slugs to custom service classes
* `pwatg_generate_on_upload` — Return false to skip generating alt text for a new upload (receives the attachment ID)
* `pwatg_debug_log_max_bytes` — Size at which the debug log rotates (default 5 MB)
* `pwatg_image_max_dimension` — Long edge, in pixels, of the image copy sent to the provider (default 1024)
* `pwatg_alt_text_language` — Language the alt text is written in (defaults to the site language; return an empty string to leave it to the model)
* `pwatg_include_filename_in_prompt` — Return false to leave the image's filename out of the prompt (receives the attachment ID)
* `pwatg_use_wp_ai_client` — Return false to call the provider directly instead of through the WordPress AI Client in Core mode (receives the provider ID)
* `pwatg_core_connector_service_map` — Map WordPress AI Connector IDs to this plugin's provider slugs
* `pwatg_service_connector_candidates` — Connector IDs checked for each provider's API key
* `pwatg_debug_log_filename` — Filename of the private debug log
* `pwatg_status_rows` — Rows of the Debug tab's status table (each an array with string `label` and `value`, and `status` of `ok`, `warning`, or an empty string; rows without a string label and value are dropped)

**WP-CLI**

If WP-CLI is available, you can run single-image generation, bulk generation, and missing-alt counts from the command line:

* `wp pwatg generate <attachment-id>`
* `wp pwatg bulk-generate`
* `wp pwatg count-missing`
* `wp pwatg network-bulk-generate`

Optional CLI flags:

* `wp pwatg generate <attachment-id> --force`
* `wp pwatg bulk-generate --force` — reports how many images would be overwritten; add `--dry-run=false` to generate
* `wp pwatg bulk-generate --dry-run`
* `wp pwatg bulk-generate --limit=<int>`
* `wp pwatg bulk-generate --missing-only`
* `wp pwatg network-bulk-generate --force` — a dry run unless `--dry-run=false` is given
* `wp pwatg network-bulk-generate --limit=<int>`
* `wp pwatg network-bulk-generate --missing-only`
* `wp pwatg network-bulk-generate --sites=<id,id,...>`

== Installation ==

Install via the WordPress plugin installer, or manually upload the plugin directory to `wp-content/plugins/`.

**Configuration**

1. Activate the plugin.
2. Visit *Settings -> Alt Text Generator*. Choose *Core* to use a provider set up in *Settings -> Connectors* (WordPress 7.0+), or *Plugin* to enter an API key and choose a model.
3. Use *Test Connection* on the *Debug* tab to confirm the provider responds.
4. (Optional) Turn *Generate on Upload* on or off.
5. Use the bulk tool under *Media -> Alt Text Generator*, or the Media Library and Image block actions.

== Frequently Asked Questions ==

= Which providers are supported? =

OpenAI (GPT-4.1 and GPT-4o, including the mini models), Anthropic Claude (Haiku 4.5, Sonnet 5, Opus 5.5), and Google Gemini (2.5 Flash, Flash-Lite, and Pro). On WordPress 7.0+, any provider connected to the WordPress AI Client can be used in Core mode. The `pwatg_available_models` filter changes the model list, and the `pwatg_provider_registry` filter adds provider classes.

= Where are API keys stored? =

In Core mode (recommended), keys are managed by WordPress in *Settings -> Connectors*. In the deprecated Plugin mode, keys are stored in the site's options (the network's, when the plugin is network-activated on Multisite) and sent only to the selected provider, from your server. Saved keys are never printed in the settings page, and they are removed when the plugin is deleted. Plugin mode will be removed in a future release, so switch to WordPress AI Connectors when you can.

= Can I regenerate specific images without affecting others? =

Yes. Use the inline "Regenerate Alt Text" link in the Media Library or media modal to update a single attachment without affecting the rest of the queue, or run `wp pwatg generate <attachment-id> --force`.

= Can I run generation from WP-CLI? =

Yes. Use `wp pwatg generate <attachment-id>` for one image, `wp pwatg bulk-generate` for a batch run, and `wp pwatg count-missing` to check remaining backlog.

= How does it work on Multisite? =

It depends on how the plugin is activated.

* **Network-activated** (recommended for managing settings once): the AI provider, API keys, model, and prompt are set once for the whole network, in *Network Admin -> Settings -> Alt Text Generator*, so only Super Admins manage keys and costs. Each site can choose whether new uploads get alt text automatically (or follow the network's choice), and has its own Bulk screen and Debug tab. *Test Connection* on a site's Debug tab is available to Super Admins. Settings saved on the main site by earlier versions move to the network automatically.
* **Activated on individual sites**: each site has its own settings page, API keys, and *Test Connection*, just like a single-site install.

= Can I run bulk generation network-wide on Multisite? =

Yes. Use `wp pwatg network-bulk-generate` to process all sites in the network, or pass `--sites=<id,id,...>` to target specific site IDs.

= How does rate limiting work? =

When a provider reports a rate limit, the plugin pauses requests to that provider for the time it asks for (or a few minutes). An out-of-credit or quota error pauses it for an hour. Bulk runs stop and say why, and the image that hit the limit is retried when you continue.

== Privacy ==

This plugin sends images to the AI provider you choose when generating alt text (OpenAI, Anthropic, or Google Gemini). Generation happens when you ask for it, and for new uploads when *Generate on Upload* is on. In the deprecated Plugin mode, API keys are stored in WordPress options on your site (or your network, when network-activated on Multisite) and used only for requests your server sends to that provider.

== External Services ==

This plugin connects to an external AI service only when alt text is generated (on request, or for new uploads when *Generate on Upload* is on) or when you use Test Connection. Only the provider selected in the plugin settings is contacted. In Core mode on WordPress 7.0+, the request is sent through the WordPress AI Client using the connection set up in *Settings -> Connectors*. This plugin sends the following data:

* The API key, in the provider's authentication header
* The selected model name
* The prompt: your prompt seed, the site language, and a short plain-text version of the image's filename (leave it out with the `pwatg_include_filename_in_prompt` filter)
* For alt text generation: a copy of the image, resized to about 1024 pixels and converted to JPEG when the provider doesn't accept its format, encoded as base64
* For Test Connection: a short text prompt ("Reply with: OK")

= OpenAI API =

* Terms: https://openai.com/policies/terms-of-use
* Privacy: https://openai.com/policies/privacy-policy

= Anthropic API =

* Terms: https://www.anthropic.com/legal/commercial-terms
* Privacy: https://www.anthropic.com/privacy

= Google Gemini API =

* Terms: https://ai.google.dev/terms
* Privacy: https://policies.google.com/privacy

== Screenshots ==

1. Alt text generation controls on the media edit screen.
2. Alt text generation controls in the media modal.
3. Bulk generator with progress and per-image results.
4. Settings screen with connector, model, prompt, and upload controls.
5. Debug screen with connection status, Test Connection, and the debug log.

== Changelog ==

= 1.3.0 =
* Added Multisite network settings managed by Super Admins, with a per-site Generate on Upload choice.
* Added a status table and Test Connection to the Debug tab.
* Changed the default connection to WordPress AI Connectors. API keys stored by this plugin are deprecated and will be removed in a future release.
* Fixed Gemini rate limits pausing generation for an hour.
* Fixed image conversion, bulk generation, and various security and reliability issues.

= 1.2.0 =
* Fixed various performance and security issues.
* Added site language support and filter.
* Added debug functionality and improved logging.

= 1.1.0 =
* Added WordPress 7.0 Core AI Connector support with connector mode setting.

= 1.0.0 =
* First public release with provider integrations, Media Library tools, and bulk generator.
