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

    $run_id = $this->acquire_bulk_run( wp_get_current_user()->display_name );
    if ( is_wp_error( $run_id ) ) {
      wp_send_json_error( [ 'message' => $run_id->get_error_message(), 'code' => $run_id->get_error_code() ], 409 );
    }

    $total = $this->get_bulk_service()->count_attachments( $regenerate_existing );

    if ( $run_test ) {
      $total = min( 5, $total );
    }

    $this->debug_log(
      'Bulk run started.',
      [
        'run_test'            => (bool) $run_test,
        'regenerate_existing' => (bool) $regenerate_existing,
        'total'               => $total,
      ]
    );

    if ( 0 === $total ) {
      $this->release_bulk_run( $run_id );
    }

    wp_send_json_success(
      [
        'total'  => $total,
        'run_id' => $run_id,
      ]
    );
  }

  /** AJAX: process a queued batch of attachment IDs. */
  public function handle_bulk_generate_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
      wp_send_json_error( [ 'message' => __( 'You do not have permission to do that.', 'presswell-alt-text-generator' ) ], 403 );
    }

    check_ajax_referer( PWATG::NONCE_GENERATE_BULK, 'nonce' );
    $this->require_post_request();

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

    $run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
    $alive  = $this->touch_bulk_run( $run_id );
    if ( is_wp_error( $alive ) ) {
      wp_send_json_error( [ 'message' => $alive->get_error_message(), 'code' => $alive->get_error_code() ], 409 );
    }

    $cursor              = isset( $_POST['cursor'] ) ? absint( wp_unslash( $_POST['cursor'] ) ) : 0;
    $batch_size          = isset( $_POST['batch_size'] ) ? absint( wp_unslash( $_POST['batch_size'] ) ) : 5;
    $run_test            = ! empty( $_POST['run_test'] );
    $regenerate_existing = $run_test ? false : ! empty( $_POST['regenerate_existing'] );

    if ( $run_test ) {
      $batch_size = min( 5, $batch_size );
    }

    $results = $this->get_bulk_service()->process_next_batch( $cursor, $batch_size, $regenerate_existing );
    $this->missing_alt_count_cache = $results['missing'];

    $this->debug_log(
      empty( $results['halted'] ) ? 'Bulk generation batch processed.' : 'Bulk run halted by a provider limit.',
      [
        'cursor'              => $cursor,
        'batch_size'          => $batch_size,
        'run_test'            => (bool) $run_test,
        'regenerate_existing' => (bool) $regenerate_existing,
        'processed'           => isset( $results['processed'] ) ? (int) $results['processed'] : 0,
        'updated'             => isset( $results['updated'] ) ? (int) $results['updated'] : 0,
        'failed'              => isset( $results['failed'] ) ? (int) $results['failed'] : 0,
        'next_cursor'         => (int) $results['next_cursor'],
        'done'                => ! empty( $results['done'] ),
      ]
    );

    // The browser stops once it has processed the total it was given, which can be
    // before the server sees a short page.
    $remaining = isset( $_POST['remaining'] ) ? absint( wp_unslash( $_POST['remaining'] ) ) : 0;
    if ( ! empty( $results['done'] ) || ( $remaining > 0 && (int) $results['processed'] >= $remaining ) ) {
      $this->release_bulk_run( $run_id );
    }

    $payload = [
      'processed'   => $results['processed'],
      'updated'     => $results['updated'],
      'failed'      => $results['failed'],
      'items'       => $results['items'],
      'next_cursor' => $results['next_cursor'],
      'done'        => $results['done'],
      'halted'      => ! empty( $results['halted'] ),
      'missing'     => $results['missing'],
    ];

    foreach ( [ 'halt_code', 'halt_reason', 'halt_retry_after' ] as $key ) {
      if ( isset( $results[ $key ] ) ) {
        $payload[ $key ] = $results[ $key ];
      }
    }

    wp_send_json_success( $payload );
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

  /**
   * Claim the site's bulk run, so two admins (or a browser and WP-CLI) don't
   * process and pay for the same images at once.
   *
   * A run that hasn't checked in for PWATG::BULK_RUN_STALE_SECONDS (a closed tab,
   * a crashed process) can be taken over.
   *
   * @param string $owner Who is running it, for the message another admin sees.
   *
   * @return string|WP_Error Run ID, or an error naming the run in progress.
   */
  public function acquire_bulk_run( $owner ) {
    $run = [
      'id'        => wp_generate_password( 12, false, false ),
      'owner'     => (string) $owner,
      'heartbeat' => time(),
    ];

    // add_option() only succeeds when no run is stored, so two starts can't both win.
    if ( add_option( PWATG::OPTION_BULK_RUN, $run, '', false ) ) {
      return $run['id'];
    }

    $current = $this->get_bulk_run();
    if ( $current && ! $this->is_bulk_run_stale( $current ) ) {
      return new WP_Error(
        'pwatg_bulk_run_active',
        sprintf(
          /* translators: %s: user name or "WP-CLI" */
          __( 'Another bulk run is in progress (started by %s). Try again when it finishes.', 'presswell-alt-text-generator' ),
          '' !== $current['owner'] ? $current['owner'] : __( 'another administrator', 'presswell-alt-text-generator' )
        )
      );
    }

    update_option( PWATG::OPTION_BULK_RUN, $run, false );

    return $run['id'];
  }

  /**
   * Confirm a run still holds the lock and record that it is alive.
   *
   * @param string $run_id Run ID from acquire_bulk_run().
   *
   * @return true|WP_Error
   */
  public function touch_bulk_run( $run_id ) {
    $current = $this->get_bulk_run();

    if ( ! $current || '' === (string) $run_id || ! hash_equals( $current['id'], (string) $run_id ) ) {
      return new WP_Error( 'pwatg_bulk_run_replaced', __( 'This bulk run was stopped because another run started. Start again when it finishes.', 'presswell-alt-text-generator' ) );
    }

    $current['heartbeat'] = time();
    update_option( PWATG::OPTION_BULK_RUN, $current, false );

    return true;
  }

  /**
   * Release the lock, if this run still holds it.
   *
   * @param string $run_id Run ID.
   */
  public function release_bulk_run( $run_id ) {
    $current = $this->get_bulk_run();

    if ( $current && hash_equals( $current['id'], (string) $run_id ) ) {
      delete_option( PWATG::OPTION_BULK_RUN );
    }
  }

  /** @return array|null Stored run. */
  protected function get_bulk_run() {
    // Read past the options cache: another request may have just changed it.
    wp_cache_delete( PWATG::OPTION_BULK_RUN, 'options' );
    $run = get_option( PWATG::OPTION_BULK_RUN );

    return is_array( $run ) && ! empty( $run['id'] ) ? wp_parse_args( $run, [ 'owner' => '', 'heartbeat' => 0 ] ) : null;
  }

  /**
   * @param array $run Stored run.
   *
   * @return bool
   */
  protected function is_bulk_run_stale( array $run ) {
    return (int) $run['heartbeat'] < time() - PWATG::BULK_RUN_STALE_SECONDS;
  }

  /**
   * Stop an AJAX request that changes data unless it was sent as POST.
   */
  protected function require_post_request() {
    $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

    if ( 'POST' !== $method ) {
      wp_send_json_error( [ 'message' => __( 'This action must be sent as a POST request.', 'presswell-alt-text-generator' ) ], 405 );
    }
  }

  /** Retrieve (and optionally refresh) the missing alt count cache. */
  protected function get_missing_alt_count( $force_refresh = false ) {
    if ( $force_refresh || null === $this->missing_alt_count_cache ) {
      $this->missing_alt_count_cache = $this->get_bulk_service()->count_missing_alt_attachments();
    }

    return max( 0, (int) $this->missing_alt_count_cache );
  }
}
