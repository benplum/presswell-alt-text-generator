<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Handles registering and localizing all admin-side assets.
 */
trait PWATG_Assets_Trait {
  /** Hook asset loaders into WordPress. */
  protected function construct_assets_trait() {
    add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
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

    $should_enqueue_admin_css = $this->is_settings_page( $hook_suffix ) || current_user_can( 'upload_files' );
    if ( $should_enqueue_admin_css ) {
      wp_enqueue_style(
        PWATG::ASSET_HANDLE_ADMIN_CSS,
        $this->get_asset_url( 'css/admin.css' ),
        [],
        PWATG::VERSION
      );
    }

    if ( $this->is_settings_page( $hook_suffix ) ) {
      $settings  = $this->get_settings();
      $model_map = [];
      $services  = array_keys( $this->get_available_services() );
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
        ]
      );
    }

    if ( $this->is_bulk_page( $hook_suffix ) ) {
      $missing_count = $this->get_missing_alt_count();
      wp_enqueue_style(
        PWATG::ASSET_HANDLE_BULK_CSS,
        $this->get_asset_url( 'css/bulk.css' ),
        [],
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
          ],
        ]
      );
    }

    if ( current_user_can( 'upload_files' ) ) {
      $current_post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
      $inline_url      = '';
      $inline_last     = '';
      $inline_has_alt  = false;

      if ( $current_post_id > 0 && 'attachment' === get_post_type( $current_post_id ) ) {
        $inline_url     = $this->get_single_action_url( $current_post_id );
        $inline_last    = $this->get_last_generated_label( $current_post_id );
        $inline_current = (string) get_post_meta( $current_post_id, PWATG::META_KEY_ALT_TEXT, true );
        $inline_has_alt = '' !== trim( $inline_current );
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
          'inlineUrl'  => $inline_url,
          'inlineLast' => $inline_last,
          'inlineHasAlt' => $inline_has_alt,
          'ajaxAction' => PWATG::AJAX_GENERATE_SINGLE,
            'fieldName' => PWATG::FIELD_GENERATE_SINGLE,
          'strings'    => [
            'generateButton'    => __( 'Generate Alt Text', 'presswell-alt-text-generator' ),
            'generatingButton'  => __( 'Generating...', 'presswell-alt-text-generator' ),
            'regenerateButton'  => __( 'Regenerate Alt Text', 'presswell-alt-text-generator' ),
            'lastGeneratedLabel'=> __( 'Last generated:', 'presswell-alt-text-generator' ),
            'never'             => __( 'Never', 'presswell-alt-text-generator' ),
            'updated'           => __( 'Alt text generated successfully.', 'presswell-alt-text-generator' ),
            'skipped'           => __( 'No changes were needed for this image.', 'presswell-alt-text-generator' ),
            'missing_key'       => __( 'Missing API key. Add it in Alt Text Generator settings.', 'presswell-alt-text-generator' ),
            'error'             => __( 'Could not generate alt text for this image.', 'presswell-alt-text-generator' ),
          ],
        ]
      );
    }
  }

  /**
   * Determine whether the current hook/page is the settings screen.
   *
   * @param string $hook_suffix Admin hook.
   *
   * @return bool
   */
  private function is_settings_page( $hook_suffix ) {
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

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
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

    return PWATG::BULK_PAGE_SCREEN_ID === $hook_suffix && PWATG::BULK_PAGE_SLUG === $page;
  }
}
