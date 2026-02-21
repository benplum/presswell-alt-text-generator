<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Shared utility helpers for locating plugin assets and rendering templates.
 */
trait PWATG_Helpers_Trait {
  
  /**
   * Safely load a PHP view partial while exposing the provided context.
   *
   * @param string $relative_path Path under includes/views/.
   * @param array  $context       Variables to extract for the template.
   */
  protected function render_view( $relative_path, array $context = [] ) {
    $view_path = $this->get_plugin_path( 'includes/views/' . ltrim( $relative_path, '/' ) );
    if ( ! file_exists( $view_path ) ) {
      return;
    }

    extract( $context, EXTR_SKIP );
    require $view_path;
  }

  /**
   * Buffer-render a view so the markup can be returned as a string.
   *
   * @param string $relative_path Template path relative to includes/views/.
   * @param array  $context       Variables for the template.
   *
   * @return string
   */
  protected function render_view_to_string( $relative_path, array $context = [] ) {
    ob_start();
    $this->render_view( $relative_path, $context );
    return trim( (string) ob_get_clean() );
  }

  /**
   * Build an absolute path inside the plugin directory.
   *
   * @param string $path Optional relative path.
   *
   * @return string
   */
  protected function get_plugin_path( $path = '' ) {
    $base = plugin_dir_path( Presswell_Alt_Text_Generator::PLUGIN_FILE );

    if ( '' === $path ) {
      return $base;
    }

    return $base . ltrim( $path, '/' );
  }

  /**
   * Build a public URL inside the plugin directory.
   *
   * @param string $path Optional relative path.
   *
   * @return string
   */
  protected function get_plugin_url( $path = '' ) {
    $base = plugin_dir_url( Presswell_Alt_Text_Generator::PLUGIN_FILE );

    if ( '' === $path ) {
      return $base;
    }

    return $base . ltrim( $path, '/' );
  }

  /**
   * Build a versioned asset URL under the assets/ directory.
   *
   * @param string $relative_path Relative asset path.
   *
   * @return string
   */
  protected function get_asset_url( $relative_path ) {
    return $this->get_plugin_url( 'assets/' . ltrim( $relative_path, '/' ) );
  }
}
