<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php echo esc_html__( 'Alt Text Generator', $text_domain ); ?></h1>
	<form method="post" action="options.php">
		<?php
		settings_fields( 'pwatg_settings_group' );
		do_settings_sections( $text_domain );
		submit_button();
		?>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="pwatg-test-connection-form" class="pwatg-mt-12">
		<input type="hidden" name="action" value="<?php echo esc_attr( $test_connection_action ); ?>" />
		<input type="hidden" name="service" value="" />
		<input type="hidden" name="model" value="" />
		<input type="hidden" name="api_key" value="" />
		<?php wp_nonce_field( $test_connection_nonce_action, 'pwatg_test_connection_nonce' ); ?>
		<?php submit_button( __( 'Test Connection', $text_domain ), 'secondary', 'pwatg_test_connection_submit', false ); ?>
	</form>
</div>
