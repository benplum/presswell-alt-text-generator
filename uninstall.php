<?php
/**
 * Removes Presswell Alt Text Generator data when the plugin is deleted (never on deactivation).
 *
 * Always removed: API keys, debug logs, rate-limit locks, and notices. Keys are
 * secrets, so they don't outlive the plugin. Settings and generation history
 * (last-generated times and the alt text kept for Restore) are only removed when
 * "Remove Data" is on, so deleting and reinstalling to troubleshoot keeps them.
 *
 * Alt text itself is never removed: it is site content that belongs to the images.
 *
 * @package Presswell Alt Text Generator
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
  exit;
}

require_once __DIR__ . '/includes/helpers/class-constants.php';

if ( ! function_exists( 'pwatg_uninstall_site' ) ) {
  /**
   * Remove the plugin's data for the current site.
   */
  function pwatg_uninstall_site() {
    global $wpdb;

    // Read before anything is deleted: the flag lives in the settings option.
    $settings    = get_option( PWATG::SETTINGS_KEY );
    $remove_data = is_array( $settings ) && isset( $settings['remove_data_on_uninstall'] ) && 'on' === $settings['remove_data_on_uninstall'];

    if ( $remove_data ) {
      delete_option( PWATG::SETTINGS_KEY );
      delete_post_meta_by_key( PWATG::META_KEY_LAST_GENERATED );
      delete_post_meta_by_key( PWATG::META_KEY_PREVIOUS_ALT );
    } elseif ( is_array( $settings ) ) {
      unset( $settings['api_keys'], $settings['api_key'] );

      // The settings sanitizer keeps saved keys when none are submitted, so bypass it.
      remove_all_filters( 'sanitize_option_' . PWATG::SETTINGS_KEY );
      update_option( PWATG::SETTINGS_KEY, $settings );
    }

    foreach ( [ PWATG::OPTION_DEBUG_LOG_TOKEN, PWATG::OPTION_DEBUG_LOG_ENABLED_AT, PWATG::OPTION_DEBUG_LOG_EXPIRED ] as $option ) {
      delete_option( $option );
    }

    delete_transient( PWATG::RATE_LIMIT_TRANSIENT );

    // Test Connection notices are stored per user.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time cleanup of prefixed rows.
    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_' . PWATG::TRANSIENT_NOTICE_TEST_PROVIDER ) . '%',
        $wpdb->esc_like( '_transient_timeout_' . PWATG::TRANSIENT_NOTICE_TEST_PROVIDER ) . '%'
      )
    );

    // Debug logs, their rotated copies, and the guard files (each site has its own uploads dir).
    $upload_dir = wp_upload_dir( null, false );
    $log_dir    = trailingslashit( $upload_dir['basedir'] ) . PWATG::DEBUG_LOG_DIR;
    if ( is_dir( $log_dir ) ) {
      // scandir() includes dotfiles like .htaccess on every platform (GLOB_BRACE isn't portable).
      foreach ( (array) scandir( $log_dir ) as $log_file ) {
        $log_path = trailingslashit( $log_dir ) . $log_file;

        if ( is_file( $log_path ) ) {
          wp_delete_file( $log_path );
        }
      }

      // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing the plugin's own empty log directory.
      rmdir( $log_dir );
    }
  }
}

if ( is_multisite() ) {
  foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $pwatg_site_id ) {
    switch_to_blog( $pwatg_site_id );
    pwatg_uninstall_site();
    restore_current_blog();
  }

  delete_site_transient( PWATG::RATE_LIMIT_TRANSIENT );
} else {
  pwatg_uninstall_site();
}

// Pre-1.2 public log, only if this plugin wrote it (Presswell Art Direction used the same name).
$pwatg_legacy_log = trailingslashit( WP_CONTENT_DIR ) . PWATG::DEBUG_LOG_LEGACY_FILENAME;
if ( is_readable( $pwatg_legacy_log ) ) {
  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Checking the first line of a local log file.
  $pwatg_legacy_head = (string) file_get_contents( $pwatg_legacy_log, false, null, 0, 200 );

  if ( false !== strpos( $pwatg_legacy_head, '[PWATG]' ) ) {
    wp_delete_file( $pwatg_legacy_log );
  }
}
