<?php
/**
 * Plugin Name: Presswell Alt Text Generator
 * Description: Generates AI-powered alt text for WordPress media uploads and in bulk.
 * Version: 0.1.0
 * Author: Ben Plum
 * License: GPLv2 or later
 * Text Domain: presswell-alt-text
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/trait-pwatg-settings.php';
require_once __DIR__ . '/includes/trait-pwatg-bulk.php';
require_once __DIR__ . '/includes/trait-pwatg-media.php';
require_once __DIR__ . '/includes/trait-pwatg-providers.php';
require_once __DIR__ . '/includes/trait-pwatg-assets.php';
require_once __DIR__ . '/includes/trait-pwatg-view-helpers.php';
require_once __DIR__ . '/includes/services/class-pwatg-openai-service.php';
require_once __DIR__ . '/includes/services/class-pwatg-anthropic-service.php';
require_once __DIR__ . '/includes/services/class-pwatg-gemini-service.php';
require_once __DIR__ . '/includes/services/class-pwatg-provider-registry.php';

if ( ! class_exists( 'Presswell_Alt_Text_Generator' ) ) {
	class Presswell_Alt_Text_Generator {
		use PWATG_Settings_Trait;
		use PWATG_Bulk_Trait;
		use PWATG_Media_Trait;
		use PWATG_Providers_Trait;
		use PWATG_Assets_Trait;
		use PWATG_View_Helpers_Trait;

		const OPTION_KEY = 'pwatg_settings';
		const VERSION = '0.1.0';
		const NOTICE_KEY = 'pwatg_bulk_notice';
		const TEST_NOTICE_KEY = 'pwatg_test_notice';
		const LAST_GENERATED_META_KEY = '_pwatg_last_generated';
		const PROVIDER_MAP = [
			'openai'    => 'PWATG_OpenAI_Service',
			'anthropic' => 'PWATG_Anthropic_Service',
			'gemini'    => 'PWATG_Gemini_Service',
		];

		public function __construct() {
			add_action( 'admin_menu', [ $this, 'register_admin_pages' ] );
			add_action( 'admin_init', [ $this, 'register_settings' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
			add_action( 'add_attachment', [ $this, 'maybe_generate_on_upload' ], 20 );
			add_action( 'admin_post_pwatg_run_bulk', [ $this, 'handle_bulk_generation' ] );
			add_action( 'wp_ajax_pwatg_bulk_init', [ $this, 'handle_bulk_init_ajax' ] );
			add_action( 'wp_ajax_pwatg_bulk_generate', [ $this, 'handle_bulk_generate_ajax' ] );
			add_action( 'admin_post_pwatg_generate_single', [ $this, 'handle_single_generation' ] );
			add_action( 'admin_post_pwatg_test_connection', [ $this, 'handle_test_connection' ] );
			add_filter( 'media_row_actions', [ $this, 'add_media_row_action' ], 10, 2 );
			add_filter( 'attachment_fields_to_edit', [ $this, 'add_media_modal_action_field' ], 10, 2 );
			add_action( 'admin_notices', [ $this, 'render_admin_notices' ] );
		}

		public function get_plugin_path( $path = '' ) {
			$base = plugin_dir_path( __FILE__ );

			if ( '' === $path ) {
				return $base;
			}

			return $base . ltrim( $path, '/' );
		}

		public function get_plugin_url( $path = '' ) {
			$base = plugin_dir_url( __FILE__ );

			if ( '' === $path ) {
				return $base;
			}

			return $base . ltrim( $path, '/' );
		}

		public function register_admin_pages() {
			add_options_page(
				__( 'Alt Text Generator', 'presswell-alt-text' ),
				__( 'Alt Text Generator', 'presswell-alt-text' ),
				'manage_options',
				'presswell-alt-text-generator',
				[ $this, 'render_settings_page' ]
			);

			add_media_page(
				__( 'Alt Text Generator', 'presswell-alt-text' ),
				__( 'Alt Text Generator', 'presswell-alt-text' ),
				'manage_options',
				'presswell-alt-text-bulk-generator',
				[ $this, 'render_bulk_page' ]
			);
		}
	}
}

new Presswell_Alt_Text_Generator();
