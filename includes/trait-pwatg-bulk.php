<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait PWATG_Bulk_Trait {
	public function handle_bulk_init_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do that.', 'presswell-alt-text' ) ], 403 );
		}

		check_ajax_referer( 'pwatg_bulk_ajax', 'nonce' );

		$regenerate_existing = ! empty( $_POST['regenerate_existing'] );
		$limit               = isset( $_POST['limit'] ) ? absint( wp_unslash( $_POST['limit'] ) ) : 50;
		$limit               = max( 1, min( 500, $limit ) );

		$args = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'DESC',
		];

		if ( ! $regenerate_existing ) {
			$args['meta_query'] = [
				'relation' => 'OR',
				[
					'key'     => '_wp_attachment_image_alt',
					'compare' => 'NOT EXISTS',
				],
				[
					'key'     => '_wp_attachment_image_alt',
					'value'   => '',
					'compare' => '=',
				],
			];
		}

		$ids = array_map( 'absint', get_posts( $args ) );

		wp_send_json_success(
			[
				'ids'   => $ids,
				'total' => count( $ids ),
			]
		);
	}

	public function handle_bulk_generate_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do that.', 'presswell-alt-text' ) ], 403 );
		}

		check_ajax_referer( 'pwatg_bulk_ajax', 'nonce' );

		$raw_ids = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : [];
		$ids     = array_values( array_filter( array_map( 'absint', $raw_ids ) ) );

		$offset              = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$batch_size          = isset( $_POST['batch_size'] ) ? absint( wp_unslash( $_POST['batch_size'] ) ) : 10;
		$batch_size          = max( 1, min( 50, $batch_size ) );
		$regenerate_existing = ! empty( $_POST['regenerate_existing'] );

		$total = count( $ids );
		if ( $offset >= $total ) {
			wp_send_json_success(
				[
					'processed'   => 0,
					'updated'     => 0,
					'failed'      => 0,
					'next_offset' => $offset,
					'done'        => true,
				]
			);
		}

		$batch_ids = array_slice( $ids, $offset, $batch_size );

		$processed = 0;
		$updated   = 0;
		$failed    = 0;
		$items     = [];

		foreach ( $batch_ids as $attachment_id ) {
			$processed++;
			$result = $this->generate_alt_text_for_attachment( $attachment_id, $regenerate_existing );

			$thumb = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
			if ( ! $thumb ) {
				$thumb = wp_get_attachment_url( $attachment_id );
			}
			if ( is_wp_error( $result ) ) {
				$failed++;
				$items[] = [
					'id'     => $attachment_id,
					'thumb'  => $thumb ? esc_url_raw( $thumb ) : '',
					'alt'    => '',
					'status' => 'failed',
				];
				continue;
			}
			if ( $result ) {
				$updated++;
				$items[] = [
					'id'     => $attachment_id,
					'thumb'  => $thumb ? esc_url_raw( $thumb ) : '',
					'alt'    => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
					'status' => 'updated',
				];
			}
		}

		$next_offset = $offset + count( $batch_ids );

		wp_send_json_success(
			[
				'processed'   => $processed,
				'updated'     => $updated,
				'failed'      => $failed,
				'items'       => $items,
				'next_offset' => $next_offset,
				'done'        => $next_offset >= $total,
			]
		);
	}

	public function render_bulk_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		require $this->get_plugin_path( 'includes/views/bulk-page.php' );
	}

	public function handle_bulk_generation() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'presswell-alt-text' ) );
		}

		check_admin_referer( 'pwatg_run_bulk' );

		$regenerate_existing = ! empty( $_POST['regenerate_existing'] );
		$limit               = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 50;
		$limit               = max( 1, min( 500, $limit ) );

		$args = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'DESC',
		];

		if ( ! $regenerate_existing ) {
			$args['meta_query'] = [
				'relation' => 'OR',
				[
					'key'     => '_wp_attachment_image_alt',
					'compare' => 'NOT EXISTS',
				],
				[
					'key'     => '_wp_attachment_image_alt',
					'value'   => '',
					'compare' => '=',
				],
			];
		}

		$attachment_ids = get_posts( $args );

		$processed = 0;
		$updated   = 0;
		$failed    = 0;

		foreach ( $attachment_ids as $attachment_id ) {
			$processed++;
			$result = $this->generate_alt_text_for_attachment( $attachment_id, $regenerate_existing );
			if ( is_wp_error( $result ) ) {
				$failed++;
				continue;
			}
			if ( $result ) {
				$updated++;
			}
		}

		set_transient(
			'pwatg_bulk_notice',
			[
				'processed' => $processed,
				'updated'   => $updated,
				'failed'    => $failed,
			],
			60
		);

		wp_safe_redirect( admin_url( 'upload.php?page=presswell-alt-text-bulk-generator' ) );
		exit;
	}
}
