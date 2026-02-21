<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PWATG_Plugin_Helper' ) ) {
	class PWATG_Plugin_Helper {
		protected $plugin_file;
		protected $plugin_key;
		protected $option_key;
		protected $text_domain;
		protected $settings_page_slug;
		protected $bulk_page_slug;

		public function __construct( $plugin_file, $plugin_key, $option_key, $text_domain, $settings_page_slug, $bulk_page_slug ) {
			$this->plugin_file = $plugin_file;
			$this->plugin_key = $plugin_key;
			$this->option_key = $option_key;
			$this->text_domain = $text_domain;
			$this->settings_page_slug = $settings_page_slug;
			$this->bulk_page_slug = $bulk_page_slug;
		}

		public function get_plugin_path( $path = '' ) {
			$base = plugin_dir_path( $this->plugin_file );

			if ( '' === $path ) {
				return $base;
			}

			return $base . ltrim( $path, '/' );
		}

		public function get_plugin_url( $path = '' ) {
			$base = plugin_dir_url( $this->plugin_file );

			if ( '' === $path ) {
				return $base;
			}

			return $base . ltrim( $path, '/' );
		}

		public function get_settings_page_slug() {
			return $this->settings_page_slug;
		}

		public function get_bulk_page_slug() {
			return $this->bulk_page_slug;
		}

		public function get_settings_screen_id() {
			return 'settings_page_' . $this->get_settings_page_slug();
		}

		public function get_bulk_screen_id() {
			return 'media_page_' . $this->get_bulk_page_slug();
		}

		public function get_settings_page_url() {
			return admin_url( 'options-general.php?page=' . $this->get_settings_page_slug() );
		}

		public function get_bulk_page_url() {
			return admin_url( 'upload.php?page=' . $this->get_bulk_page_slug() );
		}

		public function get_asset_url( $relative_path ) {
			return $this->get_plugin_url( 'assets/' . ltrim( $relative_path, '/' ) );
		}

		public function get_text_domain() {
			return $this->text_domain;
		}
	}
}
