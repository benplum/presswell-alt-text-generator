<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<a class="button pwatg-generate-alt-action" data-attachment-id="<?php echo esc_attr( (int) $attachment_id ); ?>" data-has-alt="<?php echo ! empty( $has_alt ) ? '1' : '0'; ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $button_label ); ?></a>
<p class="pwatg-last-generated"><strong><?php echo esc_html__( 'Last generated:', $text_domain ); ?></strong> <?php echo esc_html( $last_generated ); ?></p>
