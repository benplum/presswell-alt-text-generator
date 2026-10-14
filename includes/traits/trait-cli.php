<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * WP-CLI command logic, kept out of the command closures so it can be tested.
 *
 * Each method returns a result array and never calls WP_CLI itself; progress is
 * reported through the optional `__status_callback` argument.
 */
trait PWATG_CLI_Trait {
  /**
   * Generate alt text for one image.
   *
   * @param int   $attachment_id Attachment ID.
   * @param array $assoc_args    Supports `force`.
   *
   * @return array{ok: bool, status: string, attachment_id: int, alt: string, error: string, code: string}
   */
  public function run_generate_cli( $attachment_id, array $assoc_args = [] ) {
    $attachment_id = absint( $attachment_id );
    $result        = $this->generate_alt_text_for_attachment( $attachment_id, ! empty( $assoc_args['force'] ) );

    if ( is_wp_error( $result ) ) {
      return [
        'ok'            => false,
        'status'        => 'failed',
        'attachment_id' => $attachment_id,
        'alt'           => '',
        'error'         => $result->get_error_message(),
        'code'          => $result->get_error_code(),
      ];
    }

    return [
      'ok'            => true,
      'status'        => $result ? 'updated' : 'skipped',
      'attachment_id' => $attachment_id,
      'alt'           => (string) get_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, true ),
      'error'         => '',
      'code'          => '',
    ];
  }

  /**
   * Generate alt text across the Media Library.
   *
   * With `force`, existing alt text is overwritten, so the run is a dry run unless
   * `dry-run` is explicitly false. Filling in missing alt text runs straight away.
   *
   * @param array $assoc_args Supports `force`, `missing-only`, `limit`, `dry-run`, `__status_callback`.
   *
   * @return array
   */
  public function run_bulk_generate_cli( array $assoc_args = [] ) {
    $force        = ! empty( $assoc_args['force'] );
    $missing_only = ! empty( $assoc_args['missing-only'] );
    $limit        = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 0;
    $overwrite    = $force && ! $missing_only;
    $dry_run      = $this->parse_cli_bool( $assoc_args, 'dry-run', $overwrite );
    $callback     = isset( $assoc_args['__status_callback'] ) && is_callable( $assoc_args['__status_callback'] ) ? $assoc_args['__status_callback'] : null;
    $service      = $this->get_bulk_service();

    $result = [
      'ok'                => true,
      'dry_run'           => $dry_run,
      'overwrite'         => $overwrite,
      'selected'          => 0,
      'processed'         => 0,
      'updated'           => 0,
      'skipped'           => 0,
      'failed'            => 0,
      'halted'            => false,
      'halt_message'      => '',
      'missing_remaining' => 0,
      'error'             => '',
    ];

    $lock = $this->get_rate_limit_lock_state();
    if ( $lock && ! $dry_run ) {
      $result['ok']           = false;
      $result['halted']       = true;
      $result['halt_message'] = $lock['message'];

      return $result;
    }

    $selected = $service->count_attachments( $overwrite );
    if ( $limit > 0 ) {
      $selected = min( $limit, $selected );
    }
    $result['selected'] = $selected;

    if ( $dry_run || 0 === $selected ) {
      $result['missing_remaining'] = $service->count_missing_alt_attachments();

      return $result;
    }

    $run_id = $this->acquire_bulk_run( 'WP-CLI' );
    if ( is_wp_error( $run_id ) ) {
      $result['ok']    = false;
      $result['error'] = $run_id->get_error_message();

      return $result;
    }

    $this->debug_log( 'CLI bulk run started.', [ 'selected' => $selected, 'overwrite' => $overwrite ] );

    // Check in after each image so a browser run can't take over mid-run.
    $on_item = function ( $attachment_id, $status, $message ) use ( $callback, $run_id ) {
      $this->touch_bulk_run( $run_id );

      if ( $callback ) {
        call_user_func( $callback, $attachment_id, $status, $message );
      }
    };

    try {
      $run = $service->run_bulk_generation( $force, $limit, $missing_only, $on_item );
    } finally {
      $this->release_bulk_run( $run_id );
    }

    $result['processed']         = (int) $run['processed'];
    $result['updated']           = (int) $run['updated'];
    $result['failed']            = (int) $run['failed'];
    $result['skipped']           = max( 0, $result['processed'] - $result['updated'] - $result['failed'] );
    $result['halted']            = ! empty( $run['halted'] );
    $result['halt_message']      = $run['halt_error'] instanceof WP_Error ? $this->get_rate_limit_notice_text() : '';
    $result['missing_remaining'] = $service->count_missing_alt_attachments();

    if ( $result['halted'] && '' === $result['halt_message'] && $run['halt_error'] instanceof WP_Error ) {
      $result['halt_message'] = $run['halt_error']->get_error_message();
    }

    $this->debug_log( 'CLI bulk run finished.', array_diff_key( $result, [ 'halt_message' => true ] ) );

    return $result;
  }

  /**
   * Run bulk generation on each site in a network.
   *
   * @param array $assoc_args Bulk arguments plus `sites` (comma-separated IDs).
   *
   * @return array{ok: bool, error: string, sites: array, totals: array, halted: bool}
   */
  public function run_network_bulk_generate_cli( array $assoc_args = [] ) {
    if ( ! is_multisite() ) {
      return [ 'ok' => false, 'error' => 'This command is only available on Multisite.', 'sites' => [], 'totals' => [], 'halted' => false ];
    }

    $site_ids = [];
    if ( ! empty( $assoc_args['sites'] ) ) {
      $site_ids = array_filter( array_map( 'absint', explode( ',', (string) $assoc_args['sites'] ) ) );
    }
    if ( empty( $site_ids ) ) {
      $site_ids = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
    }

    $basename         = plugin_basename( Presswell_Alt_Text_Generator::PLUGIN_FILE );
    $check_activation = $this->is_loaded_as_regular_plugin();

    $totals = [ 'sites' => 0, 'processed' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'selected' => 0 ];
    $sites  = [];
    $halted = false;

    foreach ( array_values( array_unique( array_map( 'absint', (array) $site_ids ) ) ) as $site_id ) {
      // Sites that haven't activated the plugin haven't opted in to paying for alt text.
      if ( $check_activation && ! $this->is_plugin_active_on_site( $site_id, $basename ) ) {
        $sites[ $site_id ] = [
          'ok'       => true,
          'error'    => 'Skipped: the plugin is not active on this site.',
          'selected' => 0,
          'url'      => (string) get_home_url( $site_id, '/' ),
        ];
        continue;
      }

      switch_to_blog( $site_id );
      $site_result          = $this->run_bulk_generate_cli( $assoc_args );
      $site_result['url']   = (string) home_url( '/' );
      restore_current_blog();

      $sites[ $site_id ] = $site_result;

      foreach ( [ 'processed', 'updated', 'skipped', 'failed', 'selected' ] as $key ) {
        $totals[ $key ] += (int) $site_result[ $key ];
      }
      if ( $site_result['selected'] > 0 ) {
        $totals['sites']++;
      }

      // The provider limit applies to the shared key, so later sites would fail too.
      if ( ! empty( $site_result['halted'] ) ) {
        $halted = true;
        break;
      }
    }

    return [ 'ok' => true, 'error' => '', 'sites' => $sites, 'totals' => $totals, 'halted' => $halted ];
  }

  /**
   * Count images missing alt text on the current site.
   *
   * @return int
   */
  public function run_count_missing_cli() {
    return (int) $this->get_bulk_service()->count_missing_alt_attachments();
  }

  /**
   * Whether this plugin was loaded from the plugins directory, so activation applies.
   *
   * Loaded as a must-use plugin (or by custom code), it runs on every site.
   *
   * @return bool
   */
  protected function is_loaded_as_regular_plugin() {
    $file = wp_normalize_path( Presswell_Alt_Text_Generator::PLUGIN_FILE );

    return 0 === strpos( $file, trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) ) );
  }

  /**
   * Whether a plugin is active on a site, either network-wide or on the site itself.
   *
   * @param int    $site_id  Site ID.
   * @param string $basename Plugin basename, e.g. presswell-alt-text-generator/presswell-alt-text-generator.php.
   *
   * @return bool
   */
  protected function is_plugin_active_on_site( $site_id, $basename ) {
    if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
      require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    if ( is_plugin_active_for_network( $basename ) ) {
      return true;
    }

    $active = get_blog_option( absint( $site_id ), 'active_plugins', [] );

    return is_array( $active ) && in_array( $basename, $active, true );
  }

  /**
   * Read a WP-CLI boolean flag such as `--dry-run` or `--dry-run=false`.
   *
   * @param array  $assoc_args Arguments.
   * @param string $key        Flag name.
   * @param bool   $default    Value when the flag is absent.
   *
   * @return bool
   */
  protected function parse_cli_bool( array $assoc_args, $key, $default ) {
    if ( ! array_key_exists( $key, $assoc_args ) ) {
      return (bool) $default;
    }

    $value = filter_var( $assoc_args[ $key ], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

    return null === $value ? (bool) $default : $value;
  }
}
