<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<a class="button" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html__( 'Generate Alt Text', $text_domain ); ?></a>
<p class="pwatg-last-generated"><strong><?php echo esc_html__( 'Last generated:', $text_domain ); ?></strong> <?php echo esc_html( $last_generated ); ?></p>
