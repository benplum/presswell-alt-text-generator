<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait PWATG_View_Helper_Trait {
	protected function render_view( $relative_path, array $context = [] ) {
		$view_path = $this->get_plugin_path( 'includes/views/' . ltrim( $relative_path, '/' ) );
		if ( ! file_exists( $view_path ) ) {
			return;
		}

		extract( $context, EXTR_SKIP );
		require $view_path;
	}

	protected function render_view_to_string( $relative_path, array $context = [] ) {
		ob_start();
		$this->render_view( $relative_path, $context );
		return trim( (string) ob_get_clean() );
	}
}
