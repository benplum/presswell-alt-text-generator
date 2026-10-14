<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Status rows for the Debug tab: how requests are sent, and what can stop them.
 */
trait PWATG_Status_Trait {

  /**
   * Rows for the status table.
   *
   * @return array<int, array{label: string, value: string, status: string}> Status is ok, warning, or ''.
   */
  public function get_status_rows() {
    $settings = $this->get_settings();
    $service  = $this->get_active_service( $settings );
    $provider = $this->get_provider_label_for_slug( $service );
    $models   = $this->get_available_models( $service );
    $model    = isset( $settings['model'] ) ? (string) $settings['model'] : '';
    $ai_client_provider = $this->get_wp_ai_client_provider( $settings );
    $rows     = [];

    $rows[] = [
      'label'  => __( 'Provider', 'presswell-alt-text-generator' ),
      /* translators: 1: provider name, 2: model name */
      'value'  => sprintf( __( '%1$s, %2$s', 'presswell-alt-text-generator' ), $provider, isset( $models[ $model ] ) ? $models[ $model ] : $model ),
      'status' => '',
    ];

    $rows[] = [
      'label'  => __( 'Connection', 'presswell-alt-text-generator' ),
      'value'  => '' !== $ai_client_provider
        ? __( 'WordPress AI Client, using the site\'s connected provider', 'presswell-alt-text-generator' )
        : __( 'Direct requests to the provider\'s API', 'presswell-alt-text-generator' ),
      'status' => '',
    ];

    $rows[] = [
      'label'  => __( 'WordPress AI Client', 'presswell-alt-text-generator' ),
      'value'  => PWATG_WP_AI_Client_Service::is_available()
        ? __( 'Available', 'presswell-alt-text-generator' )
        : __( 'Not available (needs WordPress 7.0 or later)', 'presswell-alt-text-generator' ),
      'status' => '',
    ];

    if ( '' === $ai_client_provider ) {
      $key_source = $this->get_api_key_source( $service, $settings );
      $rows[]     = [
        'label'  => __( 'API Key', 'presswell-alt-text-generator' ),
        'value'  => '' !== $key_source ? $key_source : __( 'None saved; requests will fail', 'presswell-alt-text-generator' ),
        'status' => '' !== $key_source ? 'ok' : 'warning',
      ];
    }

    $lock   = $this->get_rate_limit_lock_state();
    $rows[] = [
      'label'  => __( 'Rate Limit', 'presswell-alt-text-generator' ),
      'value'  => $lock ? (string) $lock['message'] : __( 'None', 'presswell-alt-text-generator' ),
      'status' => $lock ? 'warning' : 'ok',
    ];

    $run = $this->get_bulk_run();
    if ( ! $run ) {
      $bulk_value  = __( 'Not running', 'presswell-alt-text-generator' );
      $bulk_status = '';
    } elseif ( $this->is_bulk_run_stale( $run ) ) {
      $bulk_value  = __( 'Stopped without finishing; the next run takes over', 'presswell-alt-text-generator' );
      $bulk_status = 'warning';
    } else {
      /* translators: %s: time since the run last reported, e.g. 5 secs */
      $bulk_value  = sprintf( __( 'Running (last active %s ago)', 'presswell-alt-text-generator' ), human_time_diff( (int) $run['heartbeat'] ) );
      $bulk_status = 'ok';
    }

    $rows[] = [
      'label'  => __( 'Bulk Generation', 'presswell-alt-text-generator' ),
      'value'  => $bulk_value,
      'status' => $bulk_status,
    ];

    $missing = $this->get_missing_alt_count();
    $rows[]  = [
      'label'  => __( 'Images Missing Alt Text', 'presswell-alt-text-generator' ),
      'value'  => number_format_i18n( $missing ),
      'status' => $missing > 0 ? 'warning' : 'ok',
    ];

    $rows[] = [
      'label'  => __( 'Generate on Upload', 'presswell-alt-text-generator' ),
      'value'  => isset( $settings['auto_generate'] ) && 'on' === $settings['auto_generate']
        ? __( 'On: each new image is sent to the provider', 'presswell-alt-text-generator' )
        : __( 'Off', 'presswell-alt-text-generator' ),
      'status' => '',
    ];

    $last   = $this->get_last_generated_attachment();
    $rows[] = [
      'label'  => __( 'Last Generated', 'presswell-alt-text-generator' ),
      'value'  => $last
        /* translators: 1: image title, 2: time since, e.g. 3 hours */
        ? sprintf( __( '%1$s, %2$s ago', 'presswell-alt-text-generator' ), $last['title'], human_time_diff( $last['time'] ) )
        : __( 'Nothing yet', 'presswell-alt-text-generator' ),
      'status' => '',
    ];

    /**
     * Filter the Debug tab's status rows.
     *
     * Rows without a string label and value are dropped.
     *
     * @param array $rows Rows, each with label, value, and status (ok, warning, or '').
     */
    $filtered = apply_filters( 'pwatg_status_rows', $rows );
    if ( ! is_array( $filtered ) ) {
      return $rows;
    }

    // Keep only rows the table can show; status falls back to '' when unknown.
    $valid = [];
    foreach ( $filtered as $row ) {
      if ( ! is_array( $row ) || ! isset( $row['label'], $row['value'] ) || ! is_string( $row['label'] ) || ! is_string( $row['value'] ) ) {
        continue;
      }

      $status  = isset( $row['status'] ) && is_string( $row['status'] ) ? $row['status'] : '';
      $valid[] = [
        'label'  => $row['label'],
        'value'  => $row['value'],
        'status' => in_array( $status, [ 'ok', 'warning' ], true ) ? $status : '',
      ];
    }

    return $valid;
  }

  /**
   * Where the provider's API key comes from, without revealing it.
   *
   * @param string $service  Provider slug.
   * @param array  $settings Settings.
   *
   * @return string '' when there is no key.
   */
  protected function get_api_key_source( $service, array $settings ) {
    if ( isset( $settings['connector_source'] ) && 'core' === $settings['connector_source'] ) {
      $core_connector = isset( $settings['core_connector'] ) ? sanitize_key( (string) $settings['core_connector'] ) : '';
      if ( '' !== trim( (string) $this->get_api_key_for_core_connector( $core_connector ) ) ) {
        return __( 'WordPress Connectors', 'presswell-alt-text-generator' );
      }
    }

    if ( ! empty( $settings['api_keys'][ $service ] ) || ! empty( $settings['api_key'] ) ) {
      return __( 'Plugin settings', 'presswell-alt-text-generator' );
    }

    if ( '' !== $this->get_wp_connector_api_key_for_service( $service ) ) {
      return __( 'WordPress Connectors', 'presswell-alt-text-generator' );
    }

    return '';
  }

  /**
   * The image whose alt text was generated most recently.
   *
   * @return array{title: string, time: int}|null
   */
  protected function get_last_generated_attachment() {
    $ids = get_posts(
      [
        'post_type'              => 'attachment',
        'post_status'            => 'inherit',
        'posts_per_page'         => 1,
        'fields'                 => 'ids',
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One row, on the Debug tab only.
        'meta_key'               => PWATG::META_KEY_LAST_GENERATED,
        'orderby'                => 'meta_value_num',
        'order'                  => 'DESC',
        'no_found_rows'          => true,
        'update_post_term_cache' => false,
      ]
    );

    if ( empty( $ids ) ) {
      return null;
    }

    $title = get_the_title( $ids[0] );

    return [
      'title' => '' !== $title ? $title : __( '(no title)', 'presswell-alt-text-generator' ),
      'time'  => (int) get_post_meta( $ids[0], PWATG::META_KEY_LAST_GENERATED, true ),
    ];
  }
}
