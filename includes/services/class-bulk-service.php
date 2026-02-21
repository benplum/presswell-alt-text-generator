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
            'key'     => PWATG::META_KEY_ALT_TEXT,
            'compare' => 'NOT EXISTS',
          ],
          [
            'key'     => PWATG::META_KEY_ALT_TEXT,
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

      $processed  = 0;
      $updated    = 0;
      $failed     = 0;
      $items      = [];
      $halted     = false;
      $halt_error = null;

      foreach ( $batch_ids as $attachment_id ) {
        $processed++;
        $result = $this->plugin->generate_alt_text_for_attachment( $attachment_id, $regenerate_existing );
        $thumb  = $this->get_attachment_thumb_url( $attachment_id );

        if ( is_wp_error( $result ) ) {
          $failed++;
          $items[] = [
            'id'                => $attachment_id,
            'thumb'             => $thumb,
            'alt'               => '',
            'status'            => 'failed',
            'error_code'        => sanitize_key( $result->get_error_code() ),
            'error_message'     => sanitize_text_field( $result->get_error_message() ),
            'error_retry_after' => $this->extract_retry_after_from_error( $result ),
          ];

          if ( $this->is_rate_limit_wp_error( $result ) ) {
            $halted     = true;
            $halt_error = $result;
            break;
          }

          continue;
        }

        if ( $result ) {
          $updated++;
          $items[] = [
            'id'     => $attachment_id,
            'thumb'  => $thumb,
            'alt'    => (string) get_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, true ),
            'status' => 'updated',
          ];
        }
      }

      $next_offset = $offset + $processed;

      $response = [
        'processed'   => $processed,
        'updated'     => $updated,
        'failed'      => $failed,
        'items'       => $items,
        'next_offset' => $next_offset,
        'done'        => $halted ? true : ( $next_offset >= $total ),
        'halted'      => $halted,
      ];

      if ( $halted ) {
        $response['halt_code']        = sanitize_key( $halt_error ? $halt_error->get_error_code() : '' );
        $response['halt_reason']      = $halt_error ? sanitize_text_field( $halt_error->get_error_message() ) : '';
        $response['halt_retry_after'] = $halt_error ? $this->extract_retry_after_from_error( $halt_error ) : 0;
      }

      return $response;
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
          if ( $this->is_rate_limit_wp_error( $result ) ) {
            break;
          }
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

    protected function extract_retry_after_from_error( $error ) {
      if ( ! ( $error instanceof WP_Error ) ) {
        return 0;
      }

      $data = $error->get_error_data();
      if ( is_array( $data ) ) {
        if ( isset( $data['remaining'] ) ) {
          return max( 0, (int) $data['remaining'] );
        }

        if ( isset( $data['retry_after'] ) ) {
          return max( 0, (int) $data['retry_after'] );
        }
      }

      return 0;
    }

    protected function is_rate_limit_wp_error( $error ) {
      if ( ! ( $error instanceof WP_Error ) ) {
        return false;
      }

      return in_array( $error->get_error_code(), [ 'pwatg_rate_limited', 'pwatg_quota_exceeded' ], true );
    }
  }
}
