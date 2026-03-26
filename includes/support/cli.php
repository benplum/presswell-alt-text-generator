<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * CLI command registration helpers for alt text workflows.
 */

/**
 * Register WP-CLI commands when available.
 */
function pwatg_register_cli_commands() {
  if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
  }

  if ( ! function_exists( 'presswell_alt_text_generator' ) ) {
    return;
  }

  if ( class_exists( 'WP_CLI' ) ) {
    WP_CLI::add_command(
      'pwatg generate',
      function( $args, $assoc_args ) {
        $attachment_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
        if ( ! $attachment_id ) {
          WP_CLI::error( 'Attachment ID is required.' );
        }

        $force  = ! empty( $assoc_args['force'] );
        $plugin = presswell_alt_text_generator();
        $result = $plugin->generate_alt_text_for_attachment( $attachment_id, $force );

        if ( is_wp_error( $result ) ) {
          WP_CLI::error( sprintf( 'Attachment %d failed: %s (%s)', $attachment_id, $result->get_error_message(), $result->get_error_code() ) );
        }

        if ( false === $result ) {
          WP_CLI::success( sprintf( 'Attachment %d skipped (already has alt text).', $attachment_id ) );
          return;
        }

        $alt = (string) get_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, true );
        WP_CLI::success( sprintf( 'Attachment %d updated.', $attachment_id ) );
        WP_CLI::log( 'Alt text: ' . $alt );
      },
      [
        'shortdesc' => 'Generate alt text for a single image attachment.',
        'synopsis'  => [
          [
            'type'        => 'positional',
            'name'        => 'attachment-id',
            'description' => 'Image attachment ID.',
            'optional'    => false,
          ],
          [
            'type'        => 'flag',
            'name'        => 'force',
            'description' => 'Overwrite existing alt text.',
            'optional'    => true,
          ],
        ],
      ]
    );

    WP_CLI::add_command(
      'pwatg bulk-generate',
      function( $args, $assoc_args ) {
        $force        = ! empty( $assoc_args['force'] );
        $missing_only = ! empty( $assoc_args['missing-only'] );
        $limit        = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 0;

        $plugin       = presswell_alt_text_generator();
        $bulk_service = new PWATG_Bulk_Service( $plugin );

        $ids      = $bulk_service->get_attachment_ids( $force, $limit, $missing_only );
        $selected = count( $ids );

        if ( 0 === $selected ) {
          WP_CLI::success( 'No matching attachments found.' );
          return;
        }

        WP_CLI::log( sprintf( 'Processing %d attachment(s)...', $selected ) );

        $results   = $bulk_service->run_bulk_generation( $force, $limit, $missing_only );
        $processed = isset( $results['processed'] ) ? (int) $results['processed'] : 0;
        $updated   = isset( $results['updated'] ) ? (int) $results['updated'] : 0;
        $failed    = isset( $results['failed'] ) ? (int) $results['failed'] : 0;
        $skipped   = max( 0, $processed - $updated - $failed );

        WP_CLI::log( 'Processed: ' . $processed );
        WP_CLI::log( 'Updated: ' . $updated );
        WP_CLI::log( 'Skipped: ' . $skipped );
        WP_CLI::log( 'Failed: ' . $failed );
        WP_CLI::log( 'Missing alt remaining: ' . $bulk_service->count_missing_alt_attachments() );

        if ( $processed < $selected ) {
          WP_CLI::warning( 'Run halted early (likely due to provider rate limit or quota lock).' );
        }

        if ( $failed > 0 ) {
          WP_CLI::warning( 'Some attachments failed. Review debug logs for details.' );
        }

        WP_CLI::success( 'Bulk generation run complete.' );
      },
      [
        'shortdesc' => 'Run bulk alt-text generation across Media Library images.',
        'synopsis'  => [
          [
            'type'        => 'flag',
            'name'        => 'force',
            'description' => 'Overwrite existing alt text.',
            'optional'    => true,
          ],
          [
            'type'        => 'assoc',
            'name'        => 'limit',
            'description' => 'Limit the number of attachments to process.',
            'optional'    => true,
          ],
          [
            'type'        => 'flag',
            'name'        => 'missing-only',
            'description' => 'Only process attachments currently missing alt text.',
            'optional'    => true,
          ],
        ],
      ]
    );

    WP_CLI::add_command(
      'pwatg count-missing',
      function() {
        $bulk_service = new PWATG_Bulk_Service( presswell_alt_text_generator() );
        WP_CLI::log( (string) $bulk_service->count_missing_alt_attachments() );
      },
      [
        'shortdesc' => 'Count image attachments that are currently missing alt text.',
      ]
    );

    WP_CLI::add_command(
      'pwatg network-bulk-generate',
      function( $args, $assoc_args ) {
        if ( ! is_multisite() ) {
          WP_CLI::error( 'This command is only available on Multisite.' );
        }

        $force        = ! empty( $assoc_args['force'] );
        $missing_only = ! empty( $assoc_args['missing-only'] );
        $limit        = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 0;
        $sites_arg    = isset( $assoc_args['sites'] ) ? (string) $assoc_args['sites'] : '';

        $site_ids = [];
        if ( '' !== $sites_arg ) {
          $site_ids = array_values( array_filter( array_map( 'absint', array_map( 'trim', explode( ',', $sites_arg ) ) ) ) );
        }

        if ( empty( $site_ids ) ) {
          $site_ids = get_sites(
            [
              'fields' => 'ids',
              'number' => 0,
            ]
          );
        }

        $site_ids = array_values( array_filter( array_map( 'absint', (array) $site_ids ) ) );
        if ( empty( $site_ids ) ) {
          WP_CLI::success( 'No sites found to process.' );
          return;
        }

        $totals = [
          'sites'     => 0,
          'processed' => 0,
          'updated'   => 0,
          'failed'    => 0,
          'skipped'   => 0,
        ];

        WP_CLI::log( sprintf( 'Starting network bulk generation on %d site(s)...', count( $site_ids ) ) );

        foreach ( $site_ids as $site_id ) {
          switch_to_blog( $site_id );

          $plugin       = presswell_alt_text_generator();
          $bulk_service = new PWATG_Bulk_Service( $plugin );
          $site_url     = (string) home_url( '/' );

          $ids      = $bulk_service->get_attachment_ids( $force, $limit, $missing_only );
          $selected = count( $ids );

          if ( 0 === $selected ) {
            WP_CLI::log( sprintf( '[site %d] %s - no matching attachments.', $site_id, $site_url ) );
            restore_current_blog();
            continue;
          }

          $results   = $bulk_service->run_bulk_generation( $force, $limit, $missing_only );
          $processed = isset( $results['processed'] ) ? (int) $results['processed'] : 0;
          $updated   = isset( $results['updated'] ) ? (int) $results['updated'] : 0;
          $failed    = isset( $results['failed'] ) ? (int) $results['failed'] : 0;
          $skipped   = max( 0, $processed - $updated - $failed );

          $totals['sites']++;
          $totals['processed'] += $processed;
          $totals['updated'] += $updated;
          $totals['failed'] += $failed;
          $totals['skipped'] += $skipped;

          WP_CLI::log(
            sprintf(
              '[site %d] %s - processed: %d, updated: %d, skipped: %d, failed: %d, missing remaining: %d',
              $site_id,
              $site_url,
              $processed,
              $updated,
              $skipped,
              $failed,
              $bulk_service->count_missing_alt_attachments()
            )
          );

          if ( $processed < $selected ) {
            WP_CLI::warning( sprintf( '[site %d] Run halted early (likely due to rate limit or quota lock).', $site_id ) );
          }

          restore_current_blog();
        }

        WP_CLI::log( 'Network totals:' );
        WP_CLI::log( 'Sites with work: ' . $totals['sites'] );
        WP_CLI::log( 'Processed: ' . $totals['processed'] );
        WP_CLI::log( 'Updated: ' . $totals['updated'] );
        WP_CLI::log( 'Skipped: ' . $totals['skipped'] );
        WP_CLI::log( 'Failed: ' . $totals['failed'] );

        if ( $totals['failed'] > 0 ) {
          WP_CLI::warning( 'Some attachments failed across the network. Review debug logs for details.' );
        }

        WP_CLI::success( 'Network bulk generation run complete.' );
      },
      [
        'shortdesc' => 'Run bulk alt-text generation across sites in a Multisite network.',
        'synopsis'  => [
          [
            'type'        => 'flag',
            'name'        => 'force',
            'description' => 'Overwrite existing alt text.',
            'optional'    => true,
          ],
          [
            'type'        => 'assoc',
            'name'        => 'limit',
            'description' => 'Limit the number of attachments to process per site.',
            'optional'    => true,
          ],
          [
            'type'        => 'flag',
            'name'        => 'missing-only',
            'description' => 'Only process attachments currently missing alt text.',
            'optional'    => true,
          ],
          [
            'type'        => 'assoc',
            'name'        => 'sites',
            'description' => 'Comma-separated site IDs to process (default: all network sites).',
            'optional'    => true,
          ],
        ],
      ]
    );
  }
}

add_action( 'plugins_loaded', 'pwatg_register_cli_commands', 20 );
