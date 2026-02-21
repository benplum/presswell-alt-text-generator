<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait PWATG_Bulk_Trait {
	public function handle_bulk_init_ajax() {
		$text_domain = $this->get_text_domain();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do that.', $text_domain ) ], 403 );
		}

		check_ajax_referer( 'pwatg_bulk_ajax', 'nonce' );

		$regenerate_existing = ! empty( $_POST['regenerate_existing'] );
		$ids                 = $this->get_bulk_service()->get_attachment_ids( $regenerate_existing );

		wp_send_json_success(
			[
				'ids'   => $ids,
				'total' => count( $ids ),
			]
		);
	}

	public function handle_bulk_generate_ajax() {
		$text_domain = $this->get_text_domain();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do that.', $text_domain ) ], 403 );
		}

		check_ajax_referer( 'pwatg_bulk_ajax', 'nonce' );

		$raw_ids            = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : [];
		$offset             = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$batch_size         = isset( $_POST['batch_size'] ) ? absint( wp_unslash( $_POST['batch_size'] ) ) : 5;
		$regenerate_existing = ! empty( $_POST['regenerate_existing'] );

		$results = $this->get_bulk_service()->process_batch( $raw_ids, $offset, $batch_size, $regenerate_existing );

		wp_send_json_success(
			[
				'processed'   => $results['processed'],
				'updated'     => $results['updated'],
				'failed'      => $results['failed'],
				'items'       => $results['items'],
				'next_offset' => $results['next_offset'],
				'done'        => $results['done'],
			]
		);
	}

	public function render_bulk_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$this->render_view(
			'bulk-page.php',
			[
				'text_domain' => $this->get_text_domain(),
			]
		);
	}

	public function handle_bulk_generation() {
		$text_domain = $this->get_text_domain();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', $text_domain ) );
		}

		check_admin_referer( 'pwatg_run_bulk' );

		$regenerate_existing = ! empty( $_POST['regenerate_existing'] );
		$results             = $this->get_bulk_service()->run_bulk_generation( $regenerate_existing );

		set_transient(
			Presswell_Alt_Text_Generator::NOTICE_KEY,
			[
				'processed' => $results['processed'],
				'updated'   => $results['updated'],
				'failed'    => $results['failed'],
			],
			60
		);

		wp_safe_redirect( $this->get_bulk_page_url() );
		exit;
	}
}
