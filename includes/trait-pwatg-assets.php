<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait PWATG_Assets_Trait {
	public function enqueue_admin_assets( $hook_suffix ) {
		if ( ! is_admin() ) {
			return;
		}

		$should_enqueue_admin_css = $this->is_settings_page( $hook_suffix ) || current_user_can( 'upload_files' );
		if ( $should_enqueue_admin_css ) {
			wp_enqueue_style(
				'pwatg-admin',
				$this->get_plugin_url( 'assets/css/pwatg-admin.css' ),
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
				'pwatg-settings',
				$this->get_plugin_url( 'assets/js/pwatg-settings.js' ),
				[],
				$this->get_asset_version(),
				true
			);

			wp_localize_script(
				'pwatg-settings',
				'pwatgSettingsData',
				[
					'optionKey'    => 'pwatg_settings',
					'modelMap'     => $model_map,
					'currentModel' => (string) $settings['model'],
				]
			);
		}

		if ( $this->is_bulk_page( $hook_suffix ) ) {
			wp_enqueue_style(
				'pwatg-bulk',
				$this->get_plugin_url( 'assets/css/pwatg-bulk.css' ),
				[],
				$this->get_asset_version()
			);

			wp_enqueue_script(
				'pwatg-bulk',
				$this->get_plugin_url( 'assets/js/pwatg-bulk.js' ),
				[ 'jquery' ],
				$this->get_asset_version(),
				true
			);

			wp_localize_script(
				'pwatg-bulk',
				'pwatgBulkData',
				[
					'nonce' => wp_create_nonce( 'pwatg_bulk_ajax' ),
					'i18n'  => [
						'runBulk'      => __( 'Run Bulk Generation', 'presswell-alt-text' ),
						'bulkComplete' => __( 'Bulk generation complete.', 'presswell-alt-text' ),
						'batchFailed'  => __( 'Batch request failed.', 'presswell-alt-text' ),
						'bulkFailed'   => __( 'Could not complete bulk generation.', 'presswell-alt-text' ),
						'preparing'    => __( 'Preparing…', 'presswell-alt-text' ),
						'preparingList'=> __( 'Preparing image list…', 'presswell-alt-text' ),
						'initFailed'   => __( 'Could not initialize bulk generation.', 'presswell-alt-text' ),
						'noImages'     => __( 'No matching images found for this run.', 'presswell-alt-text' ),
						'running'      => __( 'Running…', 'presswell-alt-text' ),
						'failedAlt'    => __( '[Failed to generate]', 'presswell-alt-text' ),
					],
				]
			);
		}

		if ( current_user_can( 'upload_files' ) ) {
			$current_post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
			$inline_url      = '';
			$inline_last     = '';

			if ( $current_post_id > 0 && 'attachment' === get_post_type( $current_post_id ) ) {
				$inline_url  = $this->get_single_action_url( $current_post_id );
				$inline_last = $this->get_last_generated_label( $current_post_id );
			}

			wp_enqueue_script(
				'pwatg-media',
				$this->get_plugin_url( 'assets/js/pwatg-media.js' ),
				[ 'jquery' ],
				$this->get_asset_version(),
				true
			);

			wp_localize_script(
				'pwatg-media',
				'pwatgMediaData',
				[
					'inlineUrl'  => $inline_url,
					'inlineLast' => $inline_last,
					'strings'    => [
						'generateButton'    => __( 'Generate Alt Text', 'presswell-alt-text' ),
						'lastGeneratedLabel'=> __( 'Last generated:', 'presswell-alt-text' ),
						'never'             => __( 'Never', 'presswell-alt-text' ),
					],
				]
			);
		}
	}

	private function is_settings_page( $hook_suffix ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return 'settings_page_presswell-alt-text-generator' === $hook_suffix && 'presswell-alt-text-generator' === $page;
	}

	private function get_asset_version() {
		return (string) Presswell_Alt_Text_Generator::VERSION;
	}

	private function is_bulk_page( $hook_suffix ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return 'media_page_presswell-alt-text-bulk-generator' === $hook_suffix && 'presswell-alt-text-bulk-generator' === $page;
	}
}
