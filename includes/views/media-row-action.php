<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Inline action shown within the Media Library list table.
 *
 * Generation changes data, so it runs only as a POST from assets/js/media.js.
 *
 * @var int    $attachment_id
 * @var string $nonce
 * @var bool   $has_alt
 * @var string $button_label
 */
?>
<span class="pwatg-row-action">
  <button type="button"
    class="button-link pwatg-generate-alt-action"
    data-attachment-id="<?php echo esc_attr( (int) $attachment_id ); ?>"
    data-nonce="<?php echo esc_attr( $nonce ); ?>"
    data-has-alt="<?php echo ! empty( $has_alt ) ? '1' : '0'; ?>"
  ><?php echo esc_html( $button_label ); ?></button>
  <span class="pwatg-inline-action-status" aria-live="polite"></span>
</span>
