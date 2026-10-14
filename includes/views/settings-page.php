<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Admin Settings → Alt Text Generator markup.
 *
 * @var bool   $is_network       Whether this is the Network Admin page.
 * @var bool   $is_subsite       Whether this is a site's page on a network.
 * @var string $active_tab       'settings' or 'debug'.
 * @var string $settings_tab_url Settings tab URL.
 * @var string $debug_tab_url    Debug tab URL.
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local template variable from extracted view context.
$active_tab = isset( $active_tab ) && 'debug' === $active_tab ? 'debug' : 'settings';
?>
<div class="wrap pwatg-settings">
  <h1><?php echo esc_html__( 'Alt Text Generator', 'presswell-alt-text-generator' ); ?></h1>

  <?php if ( ! empty( $is_network ) ) : ?>
    <p><?php echo esc_html__( 'These settings apply to every site in the network. Each site can choose whether new uploads get alt text automatically, and has its own Debug tab, where Super Admins can test the connection.', 'presswell-alt-text-generator' ); ?></p>

    <form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=' . PWATG::ACTION_SAVE_NETWORK_SETTINGS ) ); ?>">
      <?php
        wp_nonce_field( PWATG::ACTION_SAVE_NETWORK_SETTINGS );
        do_settings_sections( PWATG::SETTINGS_PAGE_SLUG );
        submit_button();
      ?>
    </form>
  </div>
    <?php return; ?>
  <?php endif; ?>

  <nav class="nav-tab-wrapper">
    <a href="<?php echo esc_url( $settings_tab_url ); ?>" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__( 'Settings', 'presswell-alt-text-generator' ); ?></a>
    <a href="<?php echo esc_url( $debug_tab_url ); ?>" class="nav-tab <?php echo 'debug' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__( 'Debug', 'presswell-alt-text-generator' ); ?></a>
  </nav>

  <?php if ( 'settings' === $active_tab && ! empty( $is_subsite ) ) : ?>
    <div class="notice notice-info inline">
      <p>
        <?php echo esc_html__( 'The AI provider, API keys, model, and prompt are set for the whole network.', 'presswell-alt-text-generator' ); ?>
        <?php if ( current_user_can( 'manage_network_options' ) ) : ?>
          <a href="<?php echo esc_url( $this->get_network_settings_url() ); ?>"><?php echo esc_html__( 'Change network settings', 'presswell-alt-text-generator' ); ?></a>
        <?php endif; ?>
      </p>
    </div>

    <form method="post" action="options.php">
      <?php
        settings_fields( PWATG::SITE_SETTINGS_GROUP );
        do_settings_sections( PWATG::SITE_SETTINGS_KEY );
        submit_button();
      ?>
    </form>
  <?php elseif ( 'settings' === $active_tab ) : ?>
    <form method="post" action="options.php">
      <?php
        settings_fields( 'pwatg_settings_group' );
        do_settings_sections( PWATG::SETTINGS_PAGE_SLUG );
        submit_button();
      ?>
    </form>
  <?php else : ?>
    <?php
    $this->render_view(
      'status-table.php',
      [
        'rows'                => $this->get_status_rows(),
        'can_test_connection' => $this->can_test_provider(),
      ]
    );
    ?>

    <?php $this->render_view( 'debug-log.php' ); ?>
  <?php endif; ?>
</div>
