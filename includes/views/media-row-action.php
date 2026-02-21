<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<span class="pwatg-row-action">
	<a class="pwatg-generate-alt-action" data-attachment-id="<?php echo esc_attr( (int) $attachment_id ); ?>" data-has-alt="<?php echo ! empty( $has_alt ) ? '1' : '0'; ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $button_label ); ?></a>
	<span class="pwatg-inline-action-status" aria-live="polite"></span>
</span>
