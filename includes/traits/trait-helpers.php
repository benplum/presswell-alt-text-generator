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

  /**
   * Resolve the debug log filename (filterable).
   *
   * @return string
   */
  protected function get_debug_log_filename() {
    $filename = apply_filters( 'pwatg_debug_log_filename', PWATG::DEBUG_LOG_FILENAME );

    if ( ! is_string( $filename ) || '' === trim( $filename ) ) {
      return PWATG::DEBUG_LOG_FILENAME;
    }

    return wp_basename( trim( $filename ) );
  }

  /**
   * Build the absolute path to the debug log file.
   *
   * @return string
   */
  protected function get_debug_log_path() {
    return trailingslashit( WP_CONTENT_DIR ) . $this->get_debug_log_filename();
  }

  /**
   * Build the public URL to the debug log file.
   *
   * @return string
   */
  protected function get_debug_log_url() {
    return content_url( $this->get_debug_log_filename() );
  }

  /**
   * Determine whether debug logging is enabled in plugin settings.
   *
   * @return bool
   */
  protected function is_debug_logging_enabled() {
    if ( ! method_exists( $this, 'get_settings' ) ) {
      return false;
    }

    $settings = $this->get_settings();

    return isset( $settings['debug_logging'] ) && 'on' === $settings['debug_logging'];
  }

  /**
   * Write a debug log line when debug logging is enabled.
   *
   * @param string $message Human-readable log message.
   * @param array  $context Optional structured context.
   */
  protected function debug_log( $message, array $context = [] ) {
    if ( ! $this->is_debug_logging_enabled() || ! function_exists( 'error_log' ) ) {
      return;
    }

    $log_path = $this->get_debug_log_path();
    $timestamp = gmdate( 'Y-m-d H:i:s' );
    $line = sprintf( '[%s UTC] [PWATG] %s', $timestamp, sanitize_text_field( (string) $message ) );
    if ( ! empty( $context ) ) {
      $encoded_context = wp_json_encode( $this->sanitize_debug_log_value( $context ) );
      if ( false !== $encoded_context ) {
        $line .= ' ' . $encoded_context;
      }
    }

    error_log( $line . PHP_EOL, 3, $log_path );
  }

  /**
   * Recursively sanitize and redact debug context values.
   *
   * @param mixed $value Raw context value.
   *
   * @return mixed
   */
  protected function sanitize_debug_log_value( $value ) {
    if ( is_array( $value ) ) {
      $sanitized = [];
      foreach ( $value as $key => $item ) {
        $clean_key = is_string( $key ) ? sanitize_key( $key ) : $key;
        if ( is_string( $clean_key ) && preg_match( '/(api|key|token|secret|auth|password|binary)/i', $clean_key ) ) {
          $sanitized[ $key ] = '[redacted]';
          continue;
        }

        $sanitized[ $key ] = $this->sanitize_debug_log_value( $item );
      }

      return $sanitized;
    }

    if ( is_object( $value ) ) {
      return $this->sanitize_debug_log_value( (array) $value );
    }

    if ( is_string( $value ) ) {
      $value = sanitize_text_field( $value );
      if ( mb_strlen( $value ) > 300 ) {
        return mb_substr( $value, 0, 300 ) . '...';
      }

      return $value;
    }

    return $value;
  }
}
