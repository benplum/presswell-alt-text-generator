<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait PWATG_Plugin_Accessors_Trait {
	public function get_plugin_path( $path = '' ) {
		return $this->get_plugin_helper()->get_plugin_path( $path );
	}

	public function get_plugin_url( $path = '' ) {
		return $this->get_plugin_helper()->get_plugin_url( $path );
	}

	public function get_settings_page_slug() {
		return $this->get_plugin_helper()->get_settings_page_slug();
	}

	public function get_bulk_page_slug() {
		return $this->get_plugin_helper()->get_bulk_page_slug();
	}

	public function get_settings_screen_id() {
		return $this->get_plugin_helper()->get_settings_screen_id();
	}

	public function get_bulk_screen_id() {
		return $this->get_plugin_helper()->get_bulk_screen_id();
	}

	public function get_settings_page_url() {
		return $this->get_plugin_helper()->get_settings_page_url();
	}

	public function get_bulk_page_url() {
		return $this->get_plugin_helper()->get_bulk_page_url();
	}

	public function get_asset_url( $relative_path ) {
		return $this->get_plugin_helper()->get_asset_url( $relative_path );
	}

	public function get_text_domain() {
		return $this->get_plugin_helper()->get_text_domain();
	}

	protected function get_plugin_helper() {
		if ( null === $this->plugin_helper ) {
			$this->plugin_helper = new PWATG_Plugin_Helper(
				self::PLUGIN_FILE,
				self::KEY,
				self::OPTION_KEY,
				self::TEXT_DOMAIN,
				self::SETTINGS_PAGE_SLUG,
				self::BULK_PAGE_SLUG
			);
		}

		return $this->plugin_helper;
	}
}
