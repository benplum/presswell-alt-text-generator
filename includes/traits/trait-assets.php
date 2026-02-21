<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait PWATG_Assets_Trait {
	public function enqueue_admin_assets( $hook_suffix ) {
		$text_domain = $this->get_text_domain();

		if ( ! is_admin() ) {
			return;
		}

		$should_enqueue_admin_css = $this->is_settings_page( $hook_suffix ) || current_user_can( 'upload_files' );
		if ( $should_enqueue_admin_css ) {
			wp_enqueue_style(
				'pwatg-css-admin',
				$this->get_asset_url( 'css/admin.css' ),
				[],
				$this->get_asset_version()
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
				$this->get_asset_version(),
				true
			);

			wp_localize_script(
				'pwatg-js-settings',
				'pwatgSettingsData',
				[
					'optionKey'    => '',
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
				$this->get_asset_version()
			);

			wp_enqueue_script(
				'pwatg-js-bulk',
				$this->get_asset_url( 'js/bulk.js' ),
				[ 'jquery' ],
				$this->get_asset_version(),
				true
			);

			wp_localize_script(
				'pwatg-js-bulk',
				'pwatgBulkData',
				[
					'nonce' => wp_create_nonce( 'pwatg_bulk_ajax' ),
					'i18n'  => [
						'runBulk'      => __( 'Run Bulk Generation', $text_domain ),
						'bulkComplete' => __( 'Bulk generation complete.', $text_domain ),
						'batchFailed'  => __( 'Batch request failed.', $text_domain ),
						'bulkFailed'   => __( 'Could not complete bulk generation.', $text_domain ),
						'preparing'    => __( 'Preparing…', $text_domain ),
						'preparingList'=> __( 'Preparing image list…', $text_domain ),
						'initFailed'   => __( 'Could not initialize bulk generation.', $text_domain ),
						'noImages'     => __( 'No matching images found for this run.', $text_domain ),
						'running'      => __( 'Running…', $text_domain ),
						'failedAlt'    => __( '[Failed to generate]', $text_domain ),
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
				$inline_current = (string) get_post_meta( $current_post_id, Presswell_Alt_Text_Generator::ALT_TEXT_META_KEY, true );
				$inline_has_alt = '' !== trim( $inline_current );
			}

			wp_enqueue_script(
				'pwatg-jsmedia',
				$this->get_asset_url( 'js/media.js' ),
				[ 'jquery' ],
				$this->get_asset_version(),
				true
			);

			wp_localize_script(
				'pwatg-js-media',
				'pwatgMediaData',
				[
					'inlineUrl'  => $inline_url,
					'inlineLast' => $inline_last,
					'inlineHasAlt' => $inline_has_alt,
					'ajaxAction' => 'pwatg_generate_single',
					'strings'    => [
						'generateButton'    => __( 'Generate Alt Text', $text_domain ),
						'generatingButton'  => __( 'Generating...', $text_domain ),
						'regenerateButton'  => __( 'Regenerate Alt Text', $text_domain ),
						'lastGeneratedLabel'=> __( 'Last generated:', $text_domain ),
						'never'             => __( 'Never', $text_domain ),
						'updated'           => __( 'Alt text generated successfully.', $text_domain ),
						'skipped'           => __( 'No changes were needed for this image.', $text_domain ),
						'missing_key'       => __( 'Missing API key. Add it in Alt Text Generator settings.', $text_domain ),
						'error'             => __( 'Could not generate alt text for this image.', $text_domain ),
					],
				]
			);
		}
	}

	private function is_settings_page( $hook_suffix ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return $this->get_settings_screen_id() === $hook_suffix && $this->get_settings_page_slug() === $page;
	}

	private function get_asset_version() {
		return (string) Presswell_Alt_Text_Generator::VERSION;
	}

	private function is_bulk_page( $hook_suffix ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return $this->get_bulk_screen_id() === $hook_suffix && $this->get_bulk_page_slug() === $page;
	}
}
