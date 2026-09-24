<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Admin Settings → Alt Text Generator markup.
 *
 * @var string $active_tab       'settings' or 'debug'.
 * @var string $settings_tab_url Settings tab URL.
 * @var string $debug_tab_url    Debug tab URL.
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local template variable from extracted view context.
$active_tab = isset( $active_tab ) && 'debug' === $active_tab ? 'debug' : 'settings';
?>
<div class="wrap pwatg-settings">
  <h1><?php echo esc_html__( 'Alt Text Generator', 'presswell-alt-text-generator' ); ?></h1>

  <nav class="nav-tab-wrapper">
    <a href="<?php echo esc_url( $settings_tab_url ); ?>" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__( 'Settings', 'presswell-alt-text-generator' ); ?></a>
    <a href="<?php echo esc_url( $debug_tab_url ); ?>" class="nav-tab <?php echo 'debug' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__( 'Debug', 'presswell-alt-text-generator' ); ?></a>
  </nav>

  <?php if ( 'settings' === $active_tab ) : ?>
    <form method="post" action="options.php">
      <?php
        settings_fields( 'pwatg_settings_group' );
        do_settings_sections( PWATG::SETTINGS_PAGE_SLUG );
        submit_button();
      ?>
    </form>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="pwatg-test-connection-form" class="pwatg-mt-12">
      <input type="hidden" name="action" value="<?php echo esc_attr( PWATG::AJAX_TEST_PROVIDER ); ?>" />
      <input type="hidden" name="service" value="" />
      <input type="hidden" name="model" value="" />
      <input type="hidden" name="api_key" value="" />
      <?php wp_nonce_field( PWATG::AJAX_TEST_PROVIDER, 'pwatg_test_provider_nonce' ); ?>
      <?php submit_button( __( 'Test Connection', 'presswell-alt-text-generator' ), 'secondary', 'pwatg_test_connection_submit', false ); ?>
    </form>
  <?php else : ?>
    <div id="pwatg-debug-log" class="pwatg-debug-log">
      <h2><?php echo esc_html__( 'Debug Log', 'presswell-alt-text-generator' ); ?></h2>
      <p id="pwatg-debug-log-status" aria-live="polite"></p>
      <div class="pwatg-debug-log-actions">
        <button type="button" class="button" id="pwatg-debug-log-refresh"><?php echo esc_html__( 'Refresh', 'presswell-alt-text-generator' ); ?></button>
        <button type="button" class="button" id="pwatg-debug-log-clear"><?php echo esc_html__( 'Clear Log', 'presswell-alt-text-generator' ); ?></button>
        <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="POST" class="pwatg-debug-log-download">
          <input type="hidden" name="action" value="<?php echo esc_attr( PWATG::ACTION_DOWNLOAD_LOG ); ?>">
          <?php wp_nonce_field( PWATG::NONCE_DOWNLOAD_LOG ); ?>
          <?php submit_button( __( 'Download', 'presswell-alt-text-generator' ), 'secondary', 'submit', false, [ 'id' => 'pwatg-debug-log-download' ] ); ?>
        </form>
      </div>
      <pre id="pwatg-debug-log-output" class="pwatg-debug-log-output" tabindex="0"></pre>
    </div>
  <?php endif; ?>
</div>
