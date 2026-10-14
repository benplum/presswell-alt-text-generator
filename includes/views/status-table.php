<?php
/**
 * Debug tab status table. Included from render_view(), so its variables are local.
 *
 * @var array $rows                Rows, each with label, value, and status (ok, warning, or '').
 * @var bool  $can_test_connection Whether to show Test Connection (it follows the settings capability).
 */
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$rows                = isset( $rows ) && is_array( $rows ) ? $rows : [];
$can_test_connection = ! empty( $can_test_connection );
?>
<h2><?php echo esc_html__( 'Status', 'presswell-alt-text-generator' ); ?></h2>
<?php if ( $can_test_connection ) : ?>
  <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="pwatg-test-connection-form" class="pwatg-test-connection">
    <input type="hidden" name="action" value="<?php echo esc_attr( PWATG::AJAX_TEST_PROVIDER ); ?>" />
    <?php wp_nonce_field( PWATG::AJAX_TEST_PROVIDER, 'pwatg_test_provider_nonce' ); ?>
    <?php submit_button( __( 'Test Connection', 'presswell-alt-text-generator' ), 'secondary', 'pwatg_test_connection_submit', false ); ?>
    <span class="description"><?php echo esc_html__( 'Sends a short text prompt to the saved provider and model.', 'presswell-alt-text-generator' ); ?></span>
  </form>
<?php endif; ?>
<table class="widefat striped pwatg-status-table">
  <tbody>
    <?php foreach ( $rows as $row ) : ?>
      <?php $status = isset( $row['status'] ) ? (string) $row['status'] : ''; ?>
      <tr class="<?php echo 'warning' === $status ? 'is-warning' : ( 'ok' === $status ? 'is-ok' : '' ); ?>">
        <th scope="row"><?php echo esc_html( (string) $row['label'] ); ?></th>
        <td>
          <?php if ( 'warning' === $status ) : ?>
            <span class="dashicons dashicons-warning" aria-hidden="true"></span>
            <span class="screen-reader-text"><?php echo esc_html__( 'Needs attention:', 'presswell-alt-text-generator' ); ?></span>
          <?php endif; ?>
          <?php echo esc_html( (string) $row['value'] ); ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
