<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PWATG_Bulk_Service' ) ) {
	class PWATG_Bulk_Service {
		protected $plugin;

		public function __construct( $plugin ) {
			$this->plugin = $plugin;
		}

		public function get_attachment_ids( $regenerate_existing ) {
			$args = [
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
			];

			if ( ! $regenerate_existing ) {
				$args['meta_query'] = [
					'relation' => 'OR',
					[
						'key'     => Presswell_Alt_Text_Generator::ALT_TEXT_META_KEY,
						'compare' => 'NOT EXISTS',
					],
					[
						'key'     => Presswell_Alt_Text_Generator::ALT_TEXT_META_KEY,
						'value'   => '',
						'compare' => '=',
					],
				];
			}

			return array_map( 'absint', get_posts( $args ) );
		}

		public function process_batch( $ids, $offset, $batch_size, $regenerate_existing ) {
			$ids        = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
			$offset     = absint( $offset );
			$batch_size = max( 1, min( 50, absint( $batch_size ) ) );

			$total = count( $ids );
			if ( $offset >= $total ) {
				return [
					'processed'   => 0,
					'updated'     => 0,
					'failed'      => 0,
					'items'       => [],
					'next_offset' => $offset,
					'done'        => true,
				];
			}

			$batch_ids = array_slice( $ids, $offset, $batch_size );

			$processed = 0;
			$updated   = 0;
			$failed    = 0;
			$items     = [];

			foreach ( $batch_ids as $attachment_id ) {
				$processed++;
				$result = $this->plugin->generate_alt_text_for_attachment( $attachment_id, $regenerate_existing );
				$thumb  = $this->get_attachment_thumb_url( $attachment_id );

				if ( is_wp_error( $result ) ) {
					$failed++;
					$items[] = [
						'id'     => $attachment_id,
						'thumb'  => $thumb,
						'alt'    => '',
						'status' => 'failed',
					];
					continue;
				}

				if ( $result ) {
					$updated++;
					$items[] = [
						'id'     => $attachment_id,
						'thumb'  => $thumb,
						'alt'    => (string) get_post_meta( $attachment_id, Presswell_Alt_Text_Generator::ALT_TEXT_META_KEY, true ),
						'status' => 'updated',
					];
				}
			}

			$next_offset = $offset + count( $batch_ids );

			return [
				'processed'   => $processed,
				'updated'     => $updated,
				'failed'      => $failed,
				'items'       => $items,
				'next_offset' => $next_offset,
				'done'        => $next_offset >= $total,
			];
		}

		public function run_bulk_generation( $regenerate_existing ) {
			$attachment_ids = $this->get_attachment_ids( $regenerate_existing );

			$processed = 0;
			$updated   = 0;
			$failed    = 0;

			foreach ( $attachment_ids as $attachment_id ) {
				$processed++;
				$result = $this->plugin->generate_alt_text_for_attachment( $attachment_id, $regenerate_existing );
				if ( is_wp_error( $result ) ) {
					$failed++;
					continue;
				}
				if ( $result ) {
					$updated++;
				}
			}

			return [
				'processed' => $processed,
				'updated'   => $updated,
				'failed'    => $failed,
			];
		}

		protected function get_attachment_thumb_url( $attachment_id ) {
			$thumb = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
			if ( ! $thumb ) {
				$thumb = wp_get_attachment_url( $attachment_id );
			}

			return $thumb ? esc_url_raw( $thumb ) : '';
		}
	}
}
