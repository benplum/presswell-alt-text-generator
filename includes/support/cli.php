<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Register WP-CLI commands. The closures only format output; the logic lives in
 * PWATG_CLI_Trait so it can be tested.
 */
function pwatg_register_cli_commands() {
  if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) {
    return;
  }

  if ( ! function_exists( 'presswell_alt_text_generator' ) ) {
    return;
  }

  $bulk_synopsis = [
    [
      'type'        => 'flag',
      'name'        => 'force',
      'description' => 'Overwrite existing alt text. Runs as a dry run unless --dry-run=false is also given.',
      'optional'    => true,
    ],
    [
      'type'        => 'assoc',
      'name'        => 'dry-run',
      'description' => 'Report how many images would be sent without calling the provider. Defaults to true with --force.',
      'optional'    => true,
      // Lets a bare --dry-run mean true; without it WP-CLI ignores the flag and runs for real.
      'value'       => [
        'optional' => true,
        'name'     => 'dry-run',
      ],
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
  ];

  $item_logger = static function ( $attachment_id, $status, $message ) {
    $line = sprintf( '[%d] %s', $attachment_id, $status );
    if ( '' !== $message ) {
      $line .= ': ' . $message;
    }

    WP_CLI::log( $line );
  };

  $report_bulk = static function ( array $result, $prefix = '' ) {
    if ( $result['dry_run'] ) {
      WP_CLI::log(
        sprintf(
          '%sDry run: %d image(s) would be sent to the provider%s. Nothing was changed.',
          $prefix,
          $result['selected'],
          $result['overwrite'] ? ', overwriting existing alt text' : ''
        )
      );

      if ( $result['overwrite'] ) {
        WP_CLI::log( $prefix . 'Run again with --dry-run=false to generate.' );
      }

      return;
    }

    WP_CLI::log(
      sprintf(
        '%sProcessed: %d, updated: %d, skipped: %d, failed: %d, missing alt remaining: %d',
        $prefix,
        $result['processed'],
        $result['updated'],
        $result['skipped'],
        $result['failed'],
        $result['missing_remaining']
      )
    );

    if ( $result['halted'] ) {
      WP_CLI::warning( $prefix . 'Stopped by a provider limit. ' . $result['halt_message'] );
    } elseif ( $result['failed'] > 0 ) {
      WP_CLI::warning( $prefix . 'Some attachments failed. Turn on debug logging for details.' );
    }
  };

  WP_CLI::add_command(
    'pwatg generate',
    static function ( $args, $assoc_args ) {
      $attachment_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
      if ( ! $attachment_id ) {
        WP_CLI::error( 'Attachment ID is required.' );
      }

      $result = presswell_alt_text_generator()->run_generate_cli( $attachment_id, $assoc_args );

      if ( ! $result['ok'] ) {
        WP_CLI::error( sprintf( 'Attachment %d failed: %s (%s)', $attachment_id, $result['error'], $result['code'] ) );
      }

      if ( 'skipped' === $result['status'] ) {
        WP_CLI::success( sprintf( 'Attachment %d skipped (already has alt text). Use --force to overwrite.', $attachment_id ) );
        return;
      }

      WP_CLI::success( sprintf( 'Attachment %d updated.', $attachment_id ) );
      WP_CLI::log( 'Alt text: ' . $result['alt'] );
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
    static function ( $args, $assoc_args ) use ( $item_logger, $report_bulk ) {
      $assoc_args['__status_callback'] = $item_logger;

      $result = presswell_alt_text_generator()->run_bulk_generate_cli( $assoc_args );

      if ( ! $result['ok'] && $result['halted'] ) {
        WP_CLI::error( 'Generation is paused by a provider limit. ' . $result['halt_message'] );
      }

      if ( ! $result['ok'] ) {
        WP_CLI::error( $result['error'] );
      }

      if ( 0 === $result['selected'] ) {
        WP_CLI::success( 'No matching attachments found.' );
        return;
      }

      $report_bulk( $result );
      WP_CLI::success( $result['dry_run'] ? 'Dry run complete.' : 'Bulk generation run complete.' );
    },
    [
      'shortdesc' => 'Run bulk alt-text generation across Media Library images.',
      'synopsis'  => $bulk_synopsis,
    ]
  );

  WP_CLI::add_command(
    'pwatg count-missing',
    static function () {
      $bulk_service = new PWATG_Bulk_Service( presswell_alt_text_generator() );
      WP_CLI::log( (string) $bulk_service->count_missing_alt_attachments() );
    },
    [
      'shortdesc' => 'Count image attachments that are currently missing alt text.',
    ]
  );

  WP_CLI::add_command(
    'pwatg network-bulk-generate',
    static function ( $args, $assoc_args ) use ( $item_logger, $report_bulk ) {
      $assoc_args['__status_callback'] = $item_logger;

      $result = presswell_alt_text_generator()->run_network_bulk_generate_cli( $assoc_args );

      if ( ! $result['ok'] ) {
        WP_CLI::error( $result['error'] );
      }

      foreach ( $result['sites'] as $site_id => $site ) {
        if ( '' !== $site['error'] ) {
          WP_CLI::warning( sprintf( '[site %d] %s - %s', $site_id, $site['url'], $site['error'] ) );
          continue;
        }

        if ( 0 === $site['selected'] ) {
          WP_CLI::log( sprintf( '[site %d] %s - no matching attachments.', $site_id, $site['url'] ) );
          continue;
        }

        $report_bulk( $site, sprintf( '[site %d] %s - ', $site_id, $site['url'] ) );
      }

      $totals = $result['totals'];
      WP_CLI::log( sprintf( 'Network totals: sites with work: %d, processed: %d, updated: %d, skipped: %d, failed: %d', $totals['sites'], $totals['processed'], $totals['updated'], $totals['skipped'], $totals['failed'] ) );

      if ( $result['halted'] ) {
        WP_CLI::warning( 'Stopped early: the provider limit applies to every site, so the remaining sites were not processed.' );
      }

      WP_CLI::success( 'Network bulk generation run complete.' );
    },
    [
      'shortdesc' => 'Run bulk alt-text generation across sites in a Multisite network.',
      'synopsis'  => array_merge(
        $bulk_synopsis,
        [
          [
            'type'        => 'assoc',
            'name'        => 'sites',
            'description' => 'Comma-separated site IDs to process (default: all network sites).',
            'optional'    => true,
          ],
        ]
      ),
    ]
  );
}

add_action( 'plugins_loaded', 'pwatg_register_cli_commands', 20 );
