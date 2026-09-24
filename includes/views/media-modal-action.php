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
 * @var bool   $has_previous
 * @var string $restore_nonce
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
		<?php echo esc_html__( 'Last generated:', 'presswell-alt-text-generator' ); ?>
	</strong> 
	<?php echo esc_html( $last_generated ); ?>
</p>
<button type="button" class="button-link pwatg-restore-alt-action" data-attachment-id="<?php echo esc_attr( (int) $attachment_id ); ?>" data-nonce="<?php echo esc_attr( $restore_nonce ); ?>" <?php echo empty( $has_previous ) ? 'hidden' : ''; ?>>
  <?php echo esc_html__( 'Restore previous alt text', 'presswell-alt-text-generator' ); ?>
</button>
