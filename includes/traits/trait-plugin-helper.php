<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait PWATG_Plugin_Helper_Trait {

	public function get_plugin_path( $path = '' ) {
		$base = plugin_dir_path( Presswell_Alt_Text_Generator::PLUGIN_FILE );

		if ( '' === $path ) {
			return $base;
		}

		return $base . ltrim( $path, '/' );
	}

	public function get_plugin_url( $path = '' ) {
		$base = plugin_dir_url( Presswell_Alt_Text_Generator::PLUGIN_FILE );

		if ( '' === $path ) {
			return $base;
		}

		return $base . ltrim( $path, '/' );
	}

	public function get_asset_url( $relative_path ) {
		return $this->get_plugin_url( 'assets/' . ltrim( $relative_path, '/' ) );
	}
}
