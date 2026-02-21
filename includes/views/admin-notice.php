<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Reusable admin notice partial.
 *
 * @var string $class CSS classes for the notice container.
 * @var string $text  Message body.
 */
?>
<div class="<?php echo esc_attr( $class ); ?>">
  <p><?php echo esc_html( $text ); ?></p>
</div>
