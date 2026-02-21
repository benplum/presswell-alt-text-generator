<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Provides bulk generation routes and orchestration helpers.
 */
trait PWATG_Bulk_Trait {
  
  /**
   * Lazily-instantiated worker that performs the heavy lifting.
   *
   * @var PWATG_Bulk_Service|null
   */
  protected $bulk_service;

  /** Register WP hooks for bulk workflows. */
  protected function construct_bulk_trait() {
    add_action( 'admin_post_' . PWATG::AJAX_GENERATE_BULK, [ $this, 'handle_bulk_generation' ] );
    add_action( 'wp_ajax_' . PWATG::AJAX_INIT_BULK, [ $this, 'handle_bulk_init_ajax' ] );
    add_action( 'wp_ajax_' . PWATG::AJAX_GENERATE_BULK, [ $this, 'handle_bulk_generate_ajax' ] );
  }
  
  /**
   * Get (and create if needed) the reusable bulk service instance.
   *
   * @return PWATG_Bulk_Service
   */
  protected function get_bulk_service() {
    if ( null === $this->bulk_service ) {
      $this->bulk_service = new PWATG_Bulk_Service( $this );
    }

    return $this->bulk_service;
  }

  /** AJAX: build the attachment list for a bulk run. */
  public function handle_bulk_init_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
      wp_send_json_error( [ 'message' => __( 'You do not have permission to do that.', PWATG::TEXT_DOMAIN ) ], 403 );
    }

    check_ajax_referer( PWATG::NONCE_GENERATE_BULK, 'nonce' );

    $lock = $this->get_rate_limit_lock_state();
    if ( $lock ) {
      wp_send_json_error(
        [
          'message' => $lock['message'],
          'code'    => $lock['code'],
        ],
        429
      );
    }

    $regenerate_existing = ! empty( $_POST['regenerate_existing'] );
    $ids                 = $this->get_bulk_service()->get_attachment_ids( $regenerate_existing );

    wp_send_json_success(
      [
        'ids'   => $ids,
        'total' => count( $ids ),
      ]
    );
  }

  /** AJAX: process a queued batch of attachment IDs. */
  public function handle_bulk_generate_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
      wp_send_json_error( [ 'message' => __( 'You do not have permission to do that.', PWATG::TEXT_DOMAIN ) ], 403 );
    }

    check_ajax_referer( PWATG::NONCE_GENERATE_BULK, 'nonce' );

    $lock = $this->get_rate_limit_lock_state();
    if ( $lock ) {
      wp_send_json_error(
        [
          'message' => $lock['message'],
          'code'    => $lock['code'],
        ],
        429
      );
    }

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

  /** Render the Media → Alt Text Generator admin page. */
  public function render_bulk_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
      return;
    }
    $this->render_view(
      'bulk-page.php',
      [
        'rate_limit_message' => $this->get_rate_limit_notice_text(),
      ]
    );
  }

  /** Handle the non-AJAX bulk form submission fallback. */
  public function handle_bulk_generation() {
    if ( ! current_user_can( 'manage_options' ) ) {
      wp_die( esc_html__( 'You do not have permission to do that.', PWATG::TEXT_DOMAIN ) );
    }

    check_admin_referer( PWATG::AJAX_GENERATE_BULK );

    $lock = $this->get_rate_limit_lock_state();
    if ( $lock ) {
      wp_die( esc_html( $lock['message'] ) );
    }

    $regenerate_existing = ! empty( $_POST['regenerate_existing'] );
    $results             = $this->get_bulk_service()->run_bulk_generation( $regenerate_existing );

    set_transient(
      PWATG::NOTICE_KEY_BULK,
      [
        'processed' => $results['processed'],
        'updated'   => $results['updated'],
        'failed'    => $results['failed'],
      ],
      60
    );

    wp_safe_redirect( PWATG::BULK_PAGE_URL );
    exit;
  }
}
