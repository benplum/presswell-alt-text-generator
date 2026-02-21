<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Inline action link shown within the Media Library list table.
 *
 * @var string $url
 * @var int    $attachment_id
 * @var bool   $has_alt
 * @var string $button_label
 */
?>
<span class="pwatg-row-action">
  <a href="<?php echo esc_url( $url ); ?>"
    class="pwatg-generate-alt-action" 
    data-attachment-id="<?php echo esc_attr( (int) $attachment_id ); ?>" 
    data-has-alt="<?php echo ! empty( $has_alt ) ? '1' : '0'; ?>" 
  >
    <?php echo esc_html( $button_label ); ?>
  </a>
  <span class="pwatg-inline-action-status" aria-live="polite"></span>
</span>
