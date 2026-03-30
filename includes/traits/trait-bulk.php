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
  /** Cached missing alt count used across the page load. */
  protected $missing_alt_count_cache;

  /** Register WP hooks for bulk workflows. */
  protected function construct_bulk_trait() {
    add_action( 'admin_post_' . PWATG::AJAX_GENERATE_BULK, [ $this, 'handle_bulk_generation' ] );
    add_action( 'wp_ajax_' . PWATG::AJAX_INIT_BULK, [ $this, 'handle_bulk_init_ajax' ] );
    add_action( 'wp_ajax_' . PWATG::AJAX_GENERATE_BULK, [ $this, 'handle_bulk_generate_ajax' ] );
    add_action( 'wp_ajax_' . PWATG::AJAX_SCAN_MISSING, [ $this, 'handle_bulk_scan_missing_ajax' ] );
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
      wp_send_json_error( [ 'message' => __( 'You do not have permission to do that.', 'presswell-alt-text-generator' ) ], 403 );
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

    $run_test            = ! empty( $_POST['run_test'] );
    $regenerate_existing = $run_test ? false : ! empty( $_POST['regenerate_existing'] );
    $limit               = $run_test ? 5 : 0;
    $ids                 = $this->get_bulk_service()->get_attachment_ids( $regenerate_existing, $limit, $run_test );

    $this->debug_log(
      'Bulk init request prepared attachment IDs.',
      [
        'run_test'            => (bool) $run_test,
        'regenerate_existing' => (bool) $regenerate_existing,
        'total'               => count( $ids ),
      ]
    );

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
      wp_send_json_error( [ 'message' => __( 'You do not have permission to do that.', 'presswell-alt-text-generator' ) ], 403 );
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

    $raw_ids             = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : [];
    $offset              = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
    $batch_size          = isset( $_POST['batch_size'] ) ? absint( wp_unslash( $_POST['batch_size'] ) ) : 5;
    $run_test            = ! empty( $_POST['run_test'] );
    $regenerate_existing = $run_test ? false : ! empty( $_POST['regenerate_existing'] );

    if ( $run_test ) {
      $raw_ids = array_slice( $raw_ids, 0, 5 );
    }

    $results = $this->get_bulk_service()->process_batch( $raw_ids, $offset, $batch_size, $regenerate_existing );
    $this->missing_alt_count_cache = $results['missing'];

    $this->debug_log(
      'Bulk generation batch processed.',
      [
        'offset'              => $offset,
        'batch_size'          => $batch_size,
        'run_test'            => (bool) $run_test,
        'regenerate_existing' => (bool) $regenerate_existing,
        'processed'           => isset( $results['processed'] ) ? (int) $results['processed'] : 0,
        'updated'             => isset( $results['updated'] ) ? (int) $results['updated'] : 0,
        'failed'              => isset( $results['failed'] ) ? (int) $results['failed'] : 0,
        'next_offset'         => isset( $results['next_offset'] ) ? (int) $results['next_offset'] : 0,
        'done'                => ! empty( $results['done'] ),
      ]
    );

    wp_send_json_success(
      [
        'processed'   => $results['processed'],
        'updated'     => $results['updated'],
        'failed'      => $results['failed'],
        'items'       => $results['items'],
        'next_offset' => $results['next_offset'],
        'done'        => $results['done'],
        'missing'     => $results['missing'],
      ]
    );
  }

  /** AJAX: scan for attachments that are missing alt text. */
  public function handle_bulk_scan_missing_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
      wp_send_json_error( [ 'message' => __( 'You do not have permission to do that.', 'presswell-alt-text-generator' ) ], 403 );
    }

    check_ajax_referer( PWATG::NONCE_GENERATE_BULK, 'nonce' );

    $count = $this->get_missing_alt_count( true );

    wp_send_json_success(
      [
        'count' => $count,
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
        'missing_alt_count'  => $this->get_missing_alt_count(),
      ]
    );
  }

  /** Handle the non-AJAX bulk form submission fallback. */
  public function handle_bulk_generation() {
    if ( ! current_user_can( 'manage_options' ) ) {
      wp_die( esc_html__( 'You do not have permission to do that.', 'presswell-alt-text-generator' ) );
    }

    check_admin_referer( PWATG::AJAX_GENERATE_BULK );

    $lock = $this->get_rate_limit_lock_state();
    if ( $lock ) {
      wp_die( esc_html( $lock['message'] ) );
    }

    $run_test            = ! empty( $_POST['run_test'] );
    $regenerate_existing = $run_test ? false : ! empty( $_POST['regenerate_existing'] );
    $results             = $this->get_bulk_service()->run_bulk_generation( $regenerate_existing, $run_test ? 5 : 0, $run_test );

    $this->debug_log(
      'Bulk generation completed via admin-post fallback.',
      [
        'run_test'            => (bool) $run_test,
        'regenerate_existing' => (bool) $regenerate_existing,
        'processed'           => isset( $results['processed'] ) ? (int) $results['processed'] : 0,
        'updated'             => isset( $results['updated'] ) ? (int) $results['updated'] : 0,
        'failed'              => isset( $results['failed'] ) ? (int) $results['failed'] : 0,
      ]
    );

    set_transient(
      PWATG::TRANSIENT_NOTICE_BULK,
      [
        'processed' => $results['processed'],
        'updated'   => $results['updated'],
        'failed'    => $results['failed'],
      ],
      PWATG::TRANSIENT_NOTICE_TTL
    );

    wp_safe_redirect( PWATG::BULK_PAGE_URL );
    exit;
  }

  /** Retrieve (and optionally refresh) the missing alt count cache. */
  protected function get_missing_alt_count( $force_refresh = false ) {
    if ( $force_refresh || null === $this->missing_alt_count_cache ) {
      $this->missing_alt_count_cache = $this->get_bulk_service()->count_missing_alt_attachments();
    }

    return max( 0, (int) $this->missing_alt_count_cache );
  }
}
