<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Admin Settings → Alt Text Generator markup.
 */
?>
<div class="wrap">
  <h1><?php echo esc_html__( 'Alt Text Generator', PWATG::TEXT_DOMAIN ); ?></h1>
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
    <?php submit_button( __( 'Test Connection', PWATG::TEXT_DOMAIN ), 'secondary', 'pwatg_test_connection_submit', false ); ?>
  </form>
</div>
