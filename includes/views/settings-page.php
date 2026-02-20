<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Alt Text Generator', 'presswell-alt-text' ); ?></h1>
	<form method="post" action="options.php">
		<?php
		settings_fields( 'pwatg_settings_group' );
		do_settings_sections( 'presswell-alt-text' );
		submit_button();
		?>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="pwatg-test-connection-form" class="pwatg-mt-12">
		<input type="hidden" name="action" value="pwatg_test_connection" />
		<input type="hidden" name="service" value="" />
		<input type="hidden" name="model" value="" />
		<input type="hidden" name="api_key" value="" />
		<?php wp_nonce_field( 'pwatg_test_connection', 'pwatg_test_connection_nonce' ); ?>
		<?php submit_button( __( 'Test Connection', 'presswell-alt-text' ), 'secondary', 'pwatg_test_connection_submit', false ); ?>
	</form>
</div>
