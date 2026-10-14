<?php
/**
 * Debug tab log viewer. The same in every Presswell plugin; debug-log.js fills it in.
 */
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}
?>
<div id="pwatg-debug-log" class="pwatg-debug-log" aria-busy="true">
  <h2><?php echo esc_html__( 'Debug Log', 'presswell-alt-text-generator' ); ?></h2>

  <p class="pwatg-debug-log-loading">
    <span class="spinner is-active"></span>
    <span class="screen-reader-text"><?php echo esc_html__( 'Loading the debug log…', 'presswell-alt-text-generator' ); ?></span>
  </p>

  <div class="pwatg-debug-log-body" hidden>
    <p id="pwatg-debug-log-status" aria-live="polite"></p>
    <div class="pwatg-debug-log-actions">
      <button type="button" class="button" id="pwatg-debug-log-refresh"><?php echo esc_html__( 'Refresh', 'presswell-alt-text-generator' ); ?></button>
      <button type="button" class="button" id="pwatg-debug-log-clear"><?php echo esc_html__( 'Clear Log', 'presswell-alt-text-generator' ); ?></button>
      <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="POST" class="pwatg-debug-log-download">
        <input type="hidden" name="action" value="<?php echo esc_attr( PWATG::ACTION_DOWNLOAD_LOG ); ?>" />
        <?php wp_nonce_field( PWATG::NONCE_DOWNLOAD_LOG ); ?>
        <?php submit_button( __( 'Download', 'presswell-alt-text-generator' ), 'secondary', 'submit', false, [ 'id' => 'pwatg-debug-log-download' ] ); ?>
      </form>
    </div>
    <pre id="pwatg-debug-log-output" class="pwatg-debug-log-output" tabindex="0"></pre>
  </div>
</div>
