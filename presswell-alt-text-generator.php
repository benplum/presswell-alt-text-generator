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

require_once __DIR__ . '/includes/helpers/class-constants.php';

require_once __DIR__ . '/includes/traits/trait-settings.php';
require_once __DIR__ . '/includes/traits/trait-bulk.php';
require_once __DIR__ . '/includes/traits/trait-media.php';
require_once __DIR__ . '/includes/traits/trait-providers.php';
require_once __DIR__ . '/includes/traits/trait-assets.php';
require_once __DIR__ . '/includes/traits/trait-plugin-helper.php';
require_once __DIR__ . '/includes/traits/trait-view-helper.php';
require_once __DIR__ . '/includes/services/class-openai-service.php';
require_once __DIR__ . '/includes/services/class-anthropic-service.php';
require_once __DIR__ . '/includes/services/class-gemini-service.php';
require_once __DIR__ . '/includes/services/class-provider-registry.php';
require_once __DIR__ . '/includes/services/class-bulk-service.php';

if ( ! class_exists( 'Presswell_Alt_Text_Generator' ) ) {
	class Presswell_Alt_Text_Generator {
		use PWATG_Settings_Trait;
		use PWATG_Bulk_Trait;
		use PWATG_Media_Trait;
		use PWATG_Providers_Trait;
		use PWATG_Assets_Trait;
		use PWATG_Plugin_Helper_Trait;
		use PWATG_View_Helper_Trait;
		
		
		const PLUGIN_FILE = __FILE__;

		protected $bulk_service;
		protected $plugin_helper;

		public function __construct() {
			add_action( 'admin_menu', [ $this, 'register_admin_pages' ] );
			add_action( 'admin_init', [ $this, 'register_settings' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
			add_filter( 'wp_generate_attachment_metadata', [ $this, 'maybe_generate_on_upload_from_metadata' ], 20, 2 );
			add_action( 'admin_post_pwatg_run_bulk', [ $this, 'handle_bulk_generation' ] );
			add_action( 'wp_ajax_pwatg_bulk_init', [ $this, 'handle_bulk_init_ajax' ] );
			add_action( 'wp_ajax_pwatg_bulk_generate', [ $this, 'handle_bulk_generate_ajax' ] );
			add_action( 'admin_post_pwatg_generate_single', [ $this, 'handle_single_generation' ] );
			add_action( 'wp_ajax_pwatg_generate_single', [ $this, 'handle_single_generation_ajax' ] );
			add_action( 'admin_post_pwatg_test_connection', [ $this, 'handle_test_connection' ] );
			add_filter( 'media_row_actions', [ $this, 'add_media_row_action' ], 10, 2 );
			add_filter( 'attachment_fields_to_edit', [ $this, 'add_media_modal_action_field' ], 10, 2 );
			add_action( 'admin_notices', [ $this, 'render_admin_notices' ] );
		}

		public function get_bulk_service() {
			if ( null === $this->bulk_service ) {
				$this->bulk_service = new PWATG_Bulk_Service( $this );
			}

			return $this->bulk_service;
		}

		public function register_admin_pages() {
			add_options_page(
				__( PWATG::SETTINGS_PAGE_TITLE, PWATG::TEXT_DOMAIN ),
				__( PWATG::SETTINGS_PAGE_TITLE, PWATG::TEXT_DOMAIN ),
				'manage_options',
				PWATG::SETTINGS_PAGE_SLUG,
				[ $this, 'render_settings_page' ]
			);

			add_media_page(
				__( PWATG::BULK_PAGE_TITLE, PWATG::TEXT_DOMAIN ),
				__( PWATG::BULK_PAGE_TITLE, PWATG::TEXT_DOMAIN ),
				'manage_options',
				PWATG::BULK_PAGE_SLUG,
				[ $this, 'render_bulk_page' ]
			);
		}
	}
}

if ( ! function_exists( 'presswell_alt_text_generator_instance' ) ) {
	function presswell_alt_text_generator_instance() {
		return isset( $GLOBALS[ PWATG::INSTANCE_KEY ] ) ? $GLOBALS[ PWATG::INSTANCE_KEY ] : null;
	}
}

if ( ! function_exists( 'presswell_alt_text_generator' ) ) {
	function presswell_alt_text_generator() {
		$instance = presswell_alt_text_generator_instance();

		if ( ! ( $instance instanceof Presswell_Alt_Text_Generator ) ) {
			$instance = new Presswell_Alt_Text_Generator();
			$GLOBALS[ PWATG::INSTANCE_KEY ] = $instance;
		}

		return $instance;
	}
}

presswell_alt_text_generator();
