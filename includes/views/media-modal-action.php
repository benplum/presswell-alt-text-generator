<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<a class="button" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Generate Alt Text', 'presswell-alt-text' ); ?></a>
<p class="pwatg-last-generated"><strong><?php esc_html_e( 'Last generated:', 'presswell-alt-text' ); ?></strong> <?php echo esc_html( $last_generated ); ?></p>
