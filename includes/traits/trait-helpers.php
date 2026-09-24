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
   * Read an option from the site whose settings apply (the main site on Multisite).
   *
   * @param string $name    Option name.
   * @param mixed  $default Default value.
   *
   * @return mixed
   */
  protected function get_shared_option( $name, $default = false ) {
    if ( is_multisite() && function_exists( 'get_blog_option' ) ) {
      return get_blog_option( get_main_site_id(), $name, $default );
    }

    return get_option( $name, $default );
  }

  /**
   * @param string $name  Option name.
   * @param mixed  $value Value.
   */
  protected function update_shared_option( $name, $value ) {
    if ( is_multisite() && function_exists( 'update_blog_option' ) ) {
      return update_blog_option( get_main_site_id(), $name, $value );
    }

    return update_option( $name, $value, false );
  }

  /**
   * @param string $name Option name.
   */
  protected function delete_shared_option( $name ) {
    if ( is_multisite() && function_exists( 'delete_blog_option' ) ) {
      return delete_blog_option( get_main_site_id(), $name );
    }

    return delete_option( $name );
  }

  /**
   * Absolute path of the directory that holds the debug log.
   *
   * @return string
   */
  public function get_debug_log_dir() {
    $upload_dir = wp_upload_dir( null, false );

    return trailingslashit( $upload_dir['basedir'] ) . PWATG::DEBUG_LOG_DIR;
  }

  /**
   * Resolve the debug log filename (filterable).
   *
   * The default includes a random token stored once per site, so the file can't
   * be found by guessing where web servers don't honor the directory's deny rules.
   *
   * @return string
   */
  protected function get_debug_log_filename() {
    $token = get_option( PWATG::OPTION_DEBUG_LOG_TOKEN );

    if ( ! is_string( $token ) || '' === $token ) {
      $token = wp_generate_password( 20, false, false );
      add_option( PWATG::OPTION_DEBUG_LOG_TOKEN, $token, '', false );
      $token = get_option( PWATG::OPTION_DEBUG_LOG_TOKEN, $token );
    }

    $default  = 'debug-' . $token . '.log';
    $filename = apply_filters( 'pwatg_debug_log_filename', $default );

    if ( ! is_string( $filename ) || '' === trim( $filename ) ) {
      return $default;
    }

    return wp_basename( trim( $filename ) );
  }

  /**
   * Build the absolute path to the debug log file.
   *
   * @return string
   */
  public function get_debug_log_path() {
    return trailingslashit( $this->get_debug_log_dir() ) . $this->get_debug_log_filename();
  }

  /**
   * Admin URL of the log viewer; the log file itself is never linked.
   *
   * @return string
   */
  public function get_debug_log_viewer_url() {
    return admin_url( PWATG::SETTINGS_PAGE_URL . '&tab=debug' );
  }

  /**
   * Create the log directory with rules that keep it from being served publicly.
   *
   * Apache honors the .htaccess deny; on nginx and others the random filename and
   * the index.php listing guard keep the log from being found. Also removes the
   * pre-1.2 log that was served publicly from wp-content.
   *
   * @return bool Whether the directory is ready for writing.
   */
  protected function ensure_debug_log_dir() {
    $dir = $this->get_debug_log_dir();

    if ( ! wp_mkdir_p( $dir ) ) {
      return false;
    }

    $guards = [
      'index.php' => "<?php\n// Silence is golden.\n",
      '.htaccess' => "# Keep Presswell Alt Text Generator logs private.\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n",
    ];

    foreach ( $guards as $file => $contents ) {
      $path = trailingslashit( $dir ) . $file;

      if ( ! file_exists( $path ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Small guard files in the plugin's own log directory.
        file_put_contents( $path, $contents );
      }
    }

    $this->delete_legacy_debug_log();

    return true;
  }

  /**
   * Remove the old public log, but only if this plugin wrote it.
   *
   * Presswell Art Direction once used the same filename, so check the line tag first.
   */
  protected function delete_legacy_debug_log() {
    $legacy_log = trailingslashit( WP_CONTENT_DIR ) . PWATG::DEBUG_LOG_LEGACY_FILENAME;

    if ( ! is_readable( $legacy_log ) ) {
      return;
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Checking the first line of a local log file.
    $head = (string) file_get_contents( $legacy_log, false, null, 0, 200 );

    if ( false !== strpos( $head, '[PWATG]' ) ) {
      wp_delete_file( $legacy_log );
    }
  }

  /**
   * Maximum debug log size before it rotates (filterable).
   *
   * @return int Bytes.
   */
  protected function get_debug_log_max_bytes() {
    $max_bytes = (int) apply_filters( 'pwatg_debug_log_max_bytes', PWATG::DEBUG_LOG_MAX_BYTES );

    return $max_bytes > 0 ? $max_bytes : PWATG::DEBUG_LOG_MAX_BYTES;
  }

  /**
   * Move an oversized log to `.1`, keeping one previous file.
   *
   * @param string $log_path Absolute log path.
   */
  protected function maybe_rotate_debug_log( $log_path ) {
    clearstatcache( true, $log_path );

    if ( file_exists( $log_path ) && filesize( $log_path ) >= $this->get_debug_log_max_bytes() ) {
      // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Rotating the plugin's own log file.
      rename( $log_path, $log_path . '.1' );
    }
  }

  /**
   * Read the end of the debug log for display.
   *
   * @param int $max_lines Maximum number of lines to return.
   * @param int $max_bytes Maximum bytes to read from the end of the file.
   *
   * @return array{exists: bool, size: int, contents: string, truncated: bool}
   */
  public function read_debug_log_tail( $max_lines = 300, $max_bytes = 262144 ) {
    $log_path = $this->get_debug_log_path();
    clearstatcache( true, $log_path );

    if ( ! is_readable( $log_path ) ) {
      return [ 'exists' => false, 'size' => 0, 'contents' => '', 'truncated' => false ];
    }

    $size   = (int) filesize( $log_path );
    $offset = max( 0, $size - (int) $max_bytes );

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the plugin's own local log file.
    $contents = (string) file_get_contents( $log_path, false, null, $offset );
    $lines    = preg_split( '/\r\n|\n/', rtrim( $contents, "\r\n" ) );

    // A partial first line from the byte offset isn't useful.
    if ( $offset > 0 ) {
      array_shift( $lines );
    }

    $truncated = $offset > 0 || count( $lines ) > $max_lines;
    $lines     = array_slice( $lines, -1 * (int) $max_lines );

    return [
      'exists'    => true,
      'size'      => $size,
      'contents'  => implode( "\n", $lines ),
      'truncated' => $truncated,
    ];
  }

  /**
   * Delete the debug log and its rotated copy.
   *
   * @return bool Whether any file was deleted.
   */
  public function clear_debug_log() {
    $deleted = false;

    foreach ( [ $this->get_debug_log_path(), $this->get_debug_log_path() . '.1' ] as $path ) {
      if ( file_exists( $path ) ) {
        wp_delete_file( $path );
        $deleted = true;
      }
    }

    return $deleted;
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
   * When debug logging will switch itself off, as a Unix timestamp (0 when off).
   *
   * @return int
   */
  public function get_debug_log_expires_at() {
    $enabled_at = (int) $this->get_shared_option( PWATG::OPTION_DEBUG_LOG_ENABLED_AT, 0 );

    return ( $enabled_at > 0 && $this->is_debug_logging_enabled() ) ? $enabled_at + PWATG::DEBUG_LOG_TTL : 0;
  }

  /**
   * Turn debug logging off once it has been on for PWATG::DEBUG_LOG_TTL.
   *
   * Logging is easy to switch on while troubleshooting and forget. Sites that
   * already had it on before this check existed start the clock now.
   */
  public function maybe_expire_debug_logging() {
    $stored = $this->get_shared_option( PWATG::SETTINGS_KEY, [] );

    if ( ! is_array( $stored ) || empty( $stored['debug_logging'] ) || 'on' !== $stored['debug_logging'] ) {
      return;
    }

    $enabled_at = (int) $this->get_shared_option( PWATG::OPTION_DEBUG_LOG_ENABLED_AT, 0 );

    if ( $enabled_at < 1 ) {
      $this->update_shared_option( PWATG::OPTION_DEBUG_LOG_ENABLED_AT, time() );

      return;
    }

    if ( $enabled_at + PWATG::DEBUG_LOG_TTL > time() ) {
      return;
    }

    $stored['debug_logging'] = 'off';
    $this->update_shared_option( PWATG::SETTINGS_KEY, $stored );
    $this->delete_shared_option( PWATG::OPTION_DEBUG_LOG_ENABLED_AT );
    $this->update_shared_option( PWATG::OPTION_DEBUG_LOG_EXPIRED, time() );
  }

  /**
   * Write a debug log line when debug logging is enabled.
   *
   * @param string $message Human-readable log message.
   * @param array  $context Optional structured context.
   */
  public function debug_log( $message, array $context = [] ) {
    if ( ! $this->is_debug_logging_enabled() || ! function_exists( 'error_log' ) ) {
      return;
    }

    // A few stat calls per line, and only while debug logging is on.
    if ( ! $this->ensure_debug_log_dir() ) {
      return;
    }

    $log_path = $this->get_debug_log_path();
    $this->maybe_rotate_debug_log( $log_path );

    $timestamp = gmdate( 'Y-m-d H:i:s' );
    $line      = sprintf( '[%s UTC] [PWATG] %s', $timestamp, sanitize_text_field( (string) $message ) );
    if ( ! empty( $context ) ) {
      $encoded_context = wp_json_encode( $this->sanitize_debug_log_value( $context ) );
      if ( false !== $encoded_context ) {
        $line .= ' ' . $encoded_context;
      }
    }

    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Writing to plugin-managed log file path when debug logging is explicitly enabled.
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
