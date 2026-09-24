<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Handles registering and localizing all admin-side assets.
 */
trait PWATG_Assets_Trait {
  /**
   * Retrieve a query parameter in a way that works in both web and CLI contexts.
   *
   * @param string $key Query string key.
   *
   * @return string
   */
  private function get_query_param( $key ) {
    $value = filter_input( INPUT_GET, $key, FILTER_UNSAFE_RAW );

    if ( null === $value || false === $value ) {
      $value = isset( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : '';
    }

    return is_scalar( $value ) ? (string) $value : '';
  }

  /** Hook asset loaders into WordPress. */
  protected function construct_assets_trait() {
    add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    // Wherever the media modal loads (block editor, Customizer, page builders), the
    // Generate button shows in the attachment details.
    add_action( 'wp_enqueue_media', [ $this, 'enqueue_media_assets' ] );
  }

  /**
   * Load CSS/JS needed across the plugin admin experiences.
   *
   * @param string $hook_suffix Current admin page identifier.
   */
  public function enqueue_admin_assets( $hook_suffix ) {
    if ( ! is_admin() ) {
      return;
    }

    if ( $this->is_settings_page( $hook_suffix ) ) {
      $this->enqueue_admin_style();
    }

    if ( 'upload.php' === $hook_suffix || $this->is_attachment_edit_screen( $hook_suffix ) ) {
      $this->enqueue_media_assets();
    }

    if ( $this->is_settings_page( $hook_suffix ) && 'debug' === sanitize_key( $this->get_query_param( 'tab' ) ) ) {
      wp_enqueue_script(
        PWATG::ASSET_HANDLE_DEBUG_JS,
        $this->get_asset_url( 'js/debug.js' ),
        [],
        PWATG::VERSION,
        true
      );

      wp_localize_script(
        PWATG::ASSET_HANDLE_DEBUG_JS,
        PWATG::JS_OBJECT_DEBUG,
        [
          'ajaxUrl' => admin_url( 'admin-ajax.php' ),
          'nonce'   => wp_create_nonce( PWATG::NONCE_DEBUG ),
          'actions' => [
            'readLog'  => PWATG::AJAX_DEBUG_READ_LOG,
            'clearLog' => PWATG::AJAX_DEBUG_CLEAR_LOG,
          ],
          'i18n'    => [
            'loading'         => __( 'Loading...', 'presswell-alt-text-generator' ),
            'requestFailed'   => __( 'Request failed.', 'presswell-alt-text-generator' ),
            /* translators: %s: date and time logging turns off */
            'logEnabledUntil' => __( 'Logging is on until %s.', 'presswell-alt-text-generator' ),
            'logDisabled'     => __( 'Logging is off. Turn it on in Settings.', 'presswell-alt-text-generator' ),
            'logTruncated'    => __( 'Showing the most recent lines; download for the full log.', 'presswell-alt-text-generator' ),
            'logEmpty'        => __( 'The log is empty.', 'presswell-alt-text-generator' ),
            'confirmClear'    => __( 'Delete the debug log?', 'presswell-alt-text-generator' ),
          ],
        ]
      );
    } elseif ( $this->is_settings_page( $hook_suffix ) ) {
      $settings  = $this->get_settings();
      $model_map = [];
      $services  = array_keys( $this->get_available_services() );
      $core_connectors = method_exists( $this, 'get_active_core_connector_choices' )
        ? $this->get_active_core_connector_choices()
        : [];
      $core_connector_service_map = [];
      foreach ( $core_connectors as $connector_id => $connector ) {
        if ( isset( $connector['service'] ) ) {
          $core_connector_service_map[ $connector_id ] = sanitize_key( (string) $connector['service'] );
        }
      }
      foreach ( $services as $service ) {
        $model_map[ $service ] = $this->get_available_models( $service );
      }

      wp_enqueue_script(
        PWATG::ASSET_HANDLE_SETTINGS_JS,
        $this->get_asset_url( 'js/settings.js' ),
        [],
        PWATG::VERSION,
        true
      );

      wp_localize_script(
        PWATG::ASSET_HANDLE_SETTINGS_JS,
        PWATG::JS_OBJECT_SETTINGS,
        [
          'optionKey'    => PWATG::SETTINGS_KEY,
          'modelMap'     => $model_map,
          'currentModel' => (string) $settings['model'],
          'connectorSource' => isset( $settings['connector_source'] ) ? (string) $settings['connector_source'] : 'plugin',
          'coreConnector' => isset( $settings['core_connector'] ) ? (string) $settings['core_connector'] : '',
          'hasCoreConnectors' => ! empty( $core_connectors ),
          'coreConnectorServiceMap' => $core_connector_service_map,
        ]
      );
    }

    if ( $this->is_bulk_page( $hook_suffix ) ) {
      $missing_count = $this->get_missing_alt_count();
      wp_enqueue_style(
        PWATG::ASSET_HANDLE_BULK_CSS,
        $this->get_asset_url( 'css/bulk.css' ),
        [ $this->register_common_style() ],
        PWATG::VERSION
      );

      wp_enqueue_script(
        PWATG::ASSET_HANDLE_BULK_JS,
        $this->get_asset_url( 'js/bulk.js' ),
        [ 'jquery' ],
        PWATG::VERSION,
        true
      );

      wp_localize_script(
        PWATG::ASSET_HANDLE_BULK_JS,
        PWATG::JS_OBJECT_BULK,
        [
          'nonce' => wp_create_nonce( PWATG::NONCE_GENERATE_BULK ),
          'ajaxAction' => PWATG::AJAX_GENERATE_BULK,
          'ajaxInitAction' => PWATG::AJAX_INIT_BULK,
          'ajaxScanAction' => PWATG::AJAX_SCAN_MISSING,
          'missingCount' => $missing_count,
          'i18n'  => [
            'runBulk'      => __( 'Run Bulk Generation', 'presswell-alt-text-generator' ),
            'bulkComplete' => __( 'Bulk generation complete.', 'presswell-alt-text-generator' ),
            'batchFailed'  => __( 'Batch request failed.', 'presswell-alt-text-generator' ),
            'bulkFailed'   => __( 'Could not complete bulk generation.', 'presswell-alt-text-generator' ),
            'preparing'    => __( 'Preparing...', 'presswell-alt-text-generator' ),
            'preparingList'=> __( 'Preparing image list...', 'presswell-alt-text-generator' ),
            'initFailed'   => __( 'Could not initialize bulk generation.', 'presswell-alt-text-generator' ),
            'noImages'     => __( 'No matching images found for this run.', 'presswell-alt-text-generator' ),
            'running'      => __( 'Running...', 'presswell-alt-text-generator' ),
            'failedAlt'    => __( '[Failed to generate]', 'presswell-alt-text-generator' ),
            'rateLimited'  => __( 'Bulk paused due to provider limits. Try again shortly.', 'presswell-alt-text-generator' ),
            'quotaExceeded'=> __( 'Bulk paused because the provider quota was exceeded.', 'presswell-alt-text-generator' ),
            'seeDetails'   => __( 'See failed rows for details.', 'presswell-alt-text-generator' ),
            'checkAgain'   => __( 'Check again', 'presswell-alt-text-generator' ),
            'checking'     => __( 'Checking...', 'presswell-alt-text-generator' ),
            'checkFailed'  => __( 'Could not refresh the count.', 'presswell-alt-text-generator' ),
            'countZero'    => __( 'No images without alt text were found.', 'presswell-alt-text-generator' ),
            'countUpdated' => __( 'Count updated.', 'presswell-alt-text-generator' ),
            'pause'        => __( 'Pause', 'presswell-alt-text-generator' ),
            'continue'     => __( 'Continue', 'presswell-alt-text-generator' ),
            'leaveWarning' => __( 'Bulk generation is still running. Leaving this page will stop the process.', 'presswell-alt-text-generator' ),
            'statusUpdated' => __( 'Updated', 'presswell-alt-text-generator' ),
            'statusFailed'  => __( 'Failed', 'presswell-alt-text-generator' ),
            'statusSkipped' => __( 'Skipped', 'presswell-alt-text-generator' ),
            /* translators: 1: images processed, 2: images in the run, 3: updated, 4: failed */
            'progress'      => __( 'Processed %1$s of %2$s · Updated: %3$s · Failed: %4$s', 'presswell-alt-text-generator' ),
            /* translators: 1: images processed, 2: updated, 3: failed */
            'summary'       => __( 'Processed: %1$s · Updated: %2$s · Failed: %3$s', 'presswell-alt-text-generator' ),
            /* translators: %d: minutes */
            'retryMinutes'  => __( 'retry in %d min', 'presswell-alt-text-generator' ),
          ],
        ]
      );
    }

  }

  /**
   * Register the badge styles shared by every plugin screen.
   *
   * @return string Style handle.
   */
  protected function register_common_style() {
    wp_register_style(
      PWATG::ASSET_HANDLE_COMMON_CSS,
      $this->get_asset_url( 'css/common.css' ),
      [],
      PWATG::VERSION
    );

    return PWATG::ASSET_HANDLE_COMMON_CSS;
  }

  /** Load the shared admin stylesheet once. */
  protected function enqueue_admin_style() {
    wp_enqueue_style(
      PWATG::ASSET_HANDLE_ADMIN_CSS,
      $this->get_asset_url( 'css/admin.css' ),
      [ $this->register_common_style() ],
      PWATG::VERSION
    );
  }

  /**
   * Load the Media Library and media modal UI for users who can manage media.
   */
  public function enqueue_media_assets() {
    if ( ! current_user_can( 'upload_files' ) || wp_script_is( PWATG::ASSET_HANDLE_MEDIA_JS, 'enqueued' ) ) {
      return;
    }

    $this->enqueue_admin_style();

    $current_post_param = filter_input( INPUT_GET, 'post', FILTER_UNSAFE_RAW );
    $current_post_id    = is_scalar( $current_post_param ) ? absint( (string) $current_post_param ) : 0;
    $inline_url         = '';
    $inline_last        = '';
    $inline_has_alt     = false;
    $inline_previous    = false;
    $inline_restore     = '';

    if ( $current_post_id > 0 && 'attachment' === get_post_type( $current_post_id ) ) {
      $inline_url     = $this->get_single_action_url( $current_post_id );
      $inline_last    = $this->get_last_generated_label( $current_post_id );
      $inline_current = (string) get_post_meta( $current_post_id, PWATG::META_KEY_ALT_TEXT, true );
      $inline_has_alt = '' !== trim( $inline_current );
      $inline_previous = $this->has_previous_alt( $current_post_id );
      $inline_restore  = wp_create_nonce( PWATG::NONCE_RESTORE_ALT . $current_post_id );
    }

    wp_enqueue_script(
      PWATG::ASSET_HANDLE_MEDIA_JS,
      $this->get_asset_url( 'js/media.js' ),
      [ 'jquery' ],
      PWATG::VERSION,
      true
    );

    wp_localize_script(
      PWATG::ASSET_HANDLE_MEDIA_JS,
      PWATG::JS_OBJECT_MEDIA,
      [
        // ajaxurl isn't defined when the media modal loads outside wp-admin.
        'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
        'inlineUrl'    => $inline_url,
        'inlineLast'   => $inline_last,
        'inlineHasAlt' => $inline_has_alt,
        'inlineHasPrevious' => $inline_previous,
        'inlineRestoreNonce' => $inline_restore,
        'ajaxAction'   => PWATG::AJAX_GENERATE_SINGLE,
        'restoreAction' => PWATG::AJAX_RESTORE_ALT,
        'fieldName'    => PWATG::FIELD_GENERATE_SINGLE,
        'strings'      => [
          'generateButton'     => __( 'Generate Alt Text', 'presswell-alt-text-generator' ),
          'generatingButton'   => __( 'Generating...', 'presswell-alt-text-generator' ),
          'regenerateButton'   => __( 'Regenerate Alt Text', 'presswell-alt-text-generator' ),
          'lastGeneratedLabel' => __( 'Last generated:', 'presswell-alt-text-generator' ),
          'never'              => __( 'Never', 'presswell-alt-text-generator' ),
          'updated'            => __( 'Alt text generated successfully.', 'presswell-alt-text-generator' ),
          'skipped'            => __( 'No changes were needed for this image.', 'presswell-alt-text-generator' ),
          'missing_key'        => __( 'Missing API key. Add it in Alt Text Generator settings or WordPress AI Connectors.', 'presswell-alt-text-generator' ),
          'error'              => __( 'Could not generate alt text for this image.', 'presswell-alt-text-generator' ),
          'restoreButton'      => __( 'Restore previous alt text', 'presswell-alt-text-generator' ),
          'restoreFailed'      => __( 'Could not restore the previous alt text.', 'presswell-alt-text-generator' ),
        ],
      ]
    );
  }

  /**
   * Whether this is the edit screen for an attachment.
   *
   * @param string $hook_suffix Admin hook.
   *
   * @return bool
   */
  private function is_attachment_edit_screen( $hook_suffix ) {
    if ( 'post.php' !== $hook_suffix ) {
      return false;
    }

    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

    return $screen && 'attachment' === $screen->post_type;
  }

  /**
   * Determine whether the current hook/page is the settings screen.
   *
   * @param string $hook_suffix Admin hook.
   *
   * @return bool
   */
  private function is_settings_page( $hook_suffix ) {
    $page = sanitize_key( $this->get_query_param( 'page' ) );

    return PWATG::SETTINGS_PAGE_SCREEN_ID === $hook_suffix && PWATG::SETTINGS_PAGE_SLUG === $page;
  }

  /**
   * Determine whether the current hook/page is the bulk processing screen.
   *
   * @param string $hook_suffix Admin hook.
   *
   * @return bool
   */
  private function is_bulk_page( $hook_suffix ) {
    $page = sanitize_key( $this->get_query_param( 'page' ) );

    return PWATG::BULK_PAGE_SCREEN_ID === $hook_suffix && PWATG::BULK_PAGE_SLUG === $page;
  }
}
