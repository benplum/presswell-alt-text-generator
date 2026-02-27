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
  $rate_limit_message = isset( $rate_limit_message ) ? (string) $rate_limit_message : '';
  if ( '' !== $rate_limit_message ) :
    ?>
    <div class="notice notice-warning">
      <p><?php echo esc_html( $rate_limit_message ); ?></p>
    </div>
  <?php endif; ?>
  <h1>
    <?php echo esc_html__( 'Alt Text Bulk Generator', PWATG::TEXT_DOMAIN ); ?>
  </h1>
  <p>
    <?php echo esc_html__( 'Generate alt text for existing images. By default only images with missing alt text are processed.', PWATG::TEXT_DOMAIN ); ?>
  </p>
  
  <?php
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
        <?php echo esc_html__( 'Check again', PWATG::TEXT_DOMAIN ); ?>
      </a>
      <span id="pwatg_refresh_status" class="pwatg-inline-status" aria-live="polite"></span>
    </p>
  </div>
  
  <p>
    <label>
      <input type="checkbox" id="pwatg_regenerate_existing" value="1" />
      <?php echo esc_html__( 'Regenerate existing alt text', PWATG::TEXT_DOMAIN ); ?>
    </label>
  </p>

  <p>
    <label>
      <input type="checkbox" id="pwatg_run_test" value="1" />
      <?php echo esc_html__( 'Test run (generates first 5 results)', PWATG::TEXT_DOMAIN ); ?>
    </label>
  </p>

  <p>
    <button type="button" class="button button-primary" id="pwatg_start_bulk"><?php echo esc_html__( 'Run Bulk Generation', PWATG::TEXT_DOMAIN ); ?></button>
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
        <th class="pwatg-col-thumb"><?php echo esc_html__( 'Thumbnail', PWATG::TEXT_DOMAIN ); ?></th>
        <th class="pwatg-col-id"><?php echo esc_html__( 'Media ID', PWATG::TEXT_DOMAIN ); ?></th>
        <th class="pwatg-col-status"><?php echo esc_html__( 'Status', PWATG::TEXT_DOMAIN ); ?></th>
        <th><?php echo esc_html__( 'Alt Text Generated', PWATG::TEXT_DOMAIN ); ?></th>
      </tr>
    </thead>
    <tbody id="pwatg_results_body"></tbody>
  </table>
</div>
