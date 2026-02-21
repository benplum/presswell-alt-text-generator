<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Button section rendered inside the attachment modal sidebar.
 *
 * @var string $url
 * @var int    $attachment_id
 * @var bool   $has_alt
 * @var string $button_label
 * @var string $last_generated
 */
?>
<a href="<?php echo esc_url( $url ); ?>"
  class="button pwatg-generate-alt-action" 
  data-attachment-id="<?php echo esc_attr( (int) $attachment_id ); ?>" 
  data-has-alt="<?php echo ! empty( $has_alt ) ? '1' : '0'; ?>" 
>
  <?php echo esc_html( $button_label ); ?>
</a>
<p class="pwatg-last-generated">
	<strong>
		<?php echo esc_html__( 'Last generated:', PWATG::TEXT_DOMAIN ); ?>
	</strong> 
	<?php echo esc_html( $last_generated ); ?>
</p>
