<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

trait PWATG_Assets_Trait {
  protected function construct_assets_trait() {
    add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
  }

  public function enqueue_admin_assets( $hook_suffix ) {
    if ( ! is_admin() ) {
      return;
    }

    $should_enqueue_admin_css = $this->is_settings_page( $hook_suffix ) || current_user_can( 'upload_files' );
    if ( $should_enqueue_admin_css ) {
      wp_enqueue_style(
        'pwatg-css-admin',
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
        'pwatg-js-settings',
        $this->get_asset_url( 'js/settings.js' ),
        [],
        PWATG::VERSION,
        true
      );

      wp_localize_script(
        'pwatg-js-settings',
        'pwatgSettingsData',
        [
          'optionKey'    => PWATG::SETTINGS_KEY,
          'modelMap'     => $model_map,
          'currentModel' => (string) $settings['model'],
        ]
      );
    }

    if ( $this->is_bulk_page( $hook_suffix ) ) {
      wp_enqueue_style(
        'pwatg-css-bulk',
        $this->get_asset_url( 'css/bulk.css' ),
        [],
        PWATG::VERSION
      );

      wp_enqueue_script(
        'pwatg-js-bulk',
        $this->get_asset_url( 'js/bulk.js' ),
        [ 'jquery' ],
        PWATG::VERSION,
        true
      );

      wp_localize_script(
        'pwatg-js-bulk',
        'pwatgBulkData',
        [
          'nonce' => wp_create_nonce( PWATG::NONCE_GENERATE_BULK ),
          'ajaxAction' => PWATG::AJAX_GENERATE_BULK,
          'ajaxInitAction' => PWATG::AJAX_INIT_BULK,
          'i18n'  => [
            'runBulk'      => __( 'Run Bulk Generation', PWATG::TEXT_DOMAIN ),
            'bulkComplete' => __( 'Bulk generation complete.', PWATG::TEXT_DOMAIN ),
            'batchFailed'  => __( 'Batch request failed.', PWATG::TEXT_DOMAIN ),
            'bulkFailed'   => __( 'Could not complete bulk generation.', PWATG::TEXT_DOMAIN ),
            'preparing'    => __( 'Preparing…', PWATG::TEXT_DOMAIN ),
            'preparingList'=> __( 'Preparing image list…', PWATG::TEXT_DOMAIN ),
            'initFailed'   => __( 'Could not initialize bulk generation.', PWATG::TEXT_DOMAIN ),
            'noImages'     => __( 'No matching images found for this run.', PWATG::TEXT_DOMAIN ),
            'running'      => __( 'Running…', PWATG::TEXT_DOMAIN ),
            'failedAlt'    => __( '[Failed to generate]', PWATG::TEXT_DOMAIN ),
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
        'pwatg-js-media',
        $this->get_asset_url( 'js/media.js' ),
        [ 'jquery' ],
        PWATG::VERSION,
        true
      );

      wp_localize_script(
        'pwatg-js-media',
        'pwatgMediaData',
        [
          'inlineUrl'  => $inline_url,
          'inlineLast' => $inline_last,
          'inlineHasAlt' => $inline_has_alt,
          'ajaxAction' => PWATG::AJAX_GENERATE_SINGLE,
            'fieldName' => PWATG::FIELD_GENERATE_SINGLE,
          'strings'    => [
            'generateButton'    => __( 'Generate Alt Text', PWATG::TEXT_DOMAIN ),
            'generatingButton'  => __( 'Generating...', PWATG::TEXT_DOMAIN ),
            'regenerateButton'  => __( 'Regenerate Alt Text', PWATG::TEXT_DOMAIN ),
            'lastGeneratedLabel'=> __( 'Last generated:', PWATG::TEXT_DOMAIN ),
            'never'             => __( 'Never', PWATG::TEXT_DOMAIN ),
            'updated'           => __( 'Alt text generated successfully.', PWATG::TEXT_DOMAIN ),
            'skipped'           => __( 'No changes were needed for this image.', PWATG::TEXT_DOMAIN ),
            'missing_key'       => __( 'Missing API key. Add it in Alt Text Generator settings.', PWATG::TEXT_DOMAIN ),
            'error'             => __( 'Could not generate alt text for this image.', PWATG::TEXT_DOMAIN ),
          ],
        ]
      );
    }
  }

  private function is_settings_page( $hook_suffix ) {
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

    return PWATG::SETTINGS_PAGE_SCREEN_ID === $hook_suffix && PWATG::SETTINGS_PAGE_SLUG === $page;
  }

  private function is_bulk_page( $hook_suffix ) {
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

    return PWATG::BULK_PAGE_SCREEN_ID === $hook_suffix && PWATG::BULK_PAGE_SLUG === $page;
  }
}
