<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Admin Media → Alt Text Bulk Generator markup.
 *
 * @var string $rate_limit_message Optional warning string.
 */
?>
<div class="wrap">
  <?php wp_nonce_field( PWATG::NONCE_GENERATE_BULK, 'pwatg_bulk_nonce', false ); ?>
  <?php
  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local template variable from extracted view context.
  $rate_limit_message = isset( $rate_limit_message ) ? (string) $rate_limit_message : '';
  if ( '' !== $rate_limit_message ) :
    ?>
    <div class="notice notice-warning">
      <p><?php echo esc_html( $rate_limit_message ); ?></p>
    </div>
  <?php endif; ?>
  <h1>
    <?php echo esc_html__( 'Alt Text Bulk Generator', 'presswell-alt-text-generator' ); ?>
  </h1>
  <p>
    <?php echo esc_html__( 'Generate alt text for existing images. By default only images with missing alt text are processed.', 'presswell-alt-text-generator' ); ?>
  </p>
  
  <?php
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local template variable from extracted view context.
    $missing_alt_count = isset( $missing_alt_count ) ? absint( $missing_alt_count ) : 0;
  ?>
  <div class="pwatg-count">
    <h2 class="pwatg-count-title">
      Images missing alt text: 
      <span id="pwatg_missing_count" data-initial="<?php echo esc_attr( $missing_alt_count ); ?>">
        <?php echo esc_html( number_format_i18n( $missing_alt_count ) ); ?>
      </span>
    </h2>
    <p class="pwatg-count-actions">
      <a href="#" id="pwatg_refresh_missing" class="button-link">
        <?php echo esc_html__( 'Check again', 'presswell-alt-text-generator' ); ?>
      </a>
      <span id="pwatg_refresh_status" class="pwatg-inline-status" aria-live="polite"></span>
    </p>
  </div>
  
  <p>
    <label>
      <input type="checkbox" id="pwatg_regenerate_existing" value="1" />
      <?php echo esc_html__( 'Regenerate existing alt text', 'presswell-alt-text-generator' ); ?>
    </label>
  </p>

  <p>
    <label>
      <input type="checkbox" id="pwatg_run_test" value="1" />
      <?php echo esc_html__( 'Test run (generates first 5 results)', 'presswell-alt-text-generator' ); ?>
    </label>
  </p>

  <p>
    <button type="button" class="button button-primary" id="pwatg_start_bulk"><?php echo esc_html__( 'Run Bulk Generation', 'presswell-alt-text-generator' ); ?></button>
    <button type="button" class="button pwatg-pause-bulk" id="pwatg_pause_bulk">
      <?php echo esc_html__( 'Pause', 'presswell-alt-text-generator' ); ?>
    </button>
  </p>

  <div id="pwatg_progress_wrap" class="pwatg-progress-wrap">
    <div class="pwatg-progress-track">
      <div id="pwatg_progress_bar" class="pwatg-progress-bar"></div>
    </div>
    <p id="pwatg_progress_text" class="pwatg-progress-text"></p>
  </div>

  <table id="pwatg_results_table" class="wp-list-table widefat fixed striped pwatg-results-table">
    <thead>
      <tr>
        <th class="pwatg-col-thumb"><?php echo esc_html__( 'Thumbnail', 'presswell-alt-text-generator' ); ?></th>
        <th class="pwatg-col-id"><?php echo esc_html__( 'Media ID', 'presswell-alt-text-generator' ); ?></th>
        <th class="pwatg-col-status"><?php echo esc_html__( 'Status', 'presswell-alt-text-generator' ); ?></th>
        <th><?php echo esc_html__( 'Alt Text Generated', 'presswell-alt-text-generator' ); ?></th>
      </tr>
    </thead>
    <tbody id="pwatg_results_body"></tbody>
  </table>
</div>
