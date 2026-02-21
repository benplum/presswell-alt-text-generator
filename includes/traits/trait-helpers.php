<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

trait PWATG_Helpers_Trait {
  
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

  protected function get_plugin_path( $path = '' ) {
    $base = plugin_dir_path( Presswell_Alt_Text_Generator::PLUGIN_FILE );

    if ( '' === $path ) {
      return $base;
    }

    return $base . ltrim( $path, '/' );
  }

  protected function get_plugin_url( $path = '' ) {
    $base = plugin_dir_url( Presswell_Alt_Text_Generator::PLUGIN_FILE );

    if ( '' === $path ) {
      return $base;
    }

    return $base . ltrim( $path, '/' );
  }

  protected function get_asset_url( $relative_path ) {
    return $this->get_plugin_url( 'assets/' . ltrim( $relative_path, '/' ) );
  }
}
