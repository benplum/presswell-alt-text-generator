<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Multisite. When the plugin is network-activated ("network mode"), the provider,
 * API keys, model, and prompt belong to the network and are managed in Network
 * Admin; each site keeps its own Bulk screen and Debug tab, and may override
 * Generate on Upload, since that spends credits per site. When it is activated on
 * individual sites instead, each site has its own settings, as on a single site.
 */
trait PWATG_Network_Trait {

  /**
   * Attach Network Admin hooks.
   *
   * Callbacks check is_network_mode() themselves: activation state can change
   * during a request, and plugin.php isn't loaded this early on the front end.
   */
  protected function construct_network_trait() {
    if ( ! is_multisite() ) {
      return;
    }

    // Early, so cron, REST, and WP-CLI requests after an upgrade see the migrated keys.
    add_action( 'init', [ $this, 'maybe_migrate_network_settings' ], 1 );
    add_action( 'network_admin_menu', [ $this, 'register_network_admin_page' ] );
    add_action( 'network_admin_edit_' . PWATG::ACTION_SAVE_NETWORK_SETTINGS, [ $this, 'handle_save_network_settings' ] );
    add_action( 'network_admin_notices', [ $this, 'render_network_admin_notices' ] );
    add_filter( 'network_admin_plugin_action_links_' . plugin_basename( self::PLUGIN_FILE ), [ $this, 'add_network_settings_action_link' ] );
  }

  /**
   * Whether settings belong to the network: Multisite with the plugin network-activated.
   *
   * Activated on individual sites instead, each site keeps its own settings.
   *
   * @return bool
   */
  public function is_network_mode() {
    if ( ! is_multisite() ) {
      return false;
    }

    if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
      require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    return is_plugin_active_for_network( plugin_basename( self::PLUGIN_FILE ) );
  }

  /**
   * Capability needed to change the provider settings or test the connection.
   *
   * @return string
   */
  public function get_settings_capability() {
    return $this->is_network_mode() ? 'manage_network_options' : 'manage_options';
  }

  /**
   * Add Network Admin → Settings → Alt Text Generator.
   */
  public function register_network_admin_page() {
    if ( ! $this->is_network_mode() ) {
      return;
    }

    add_submenu_page(
      'settings.php',
      __( 'Alt Text Generator', 'presswell-alt-text-generator' ),
      __( 'Alt Text Generator', 'presswell-alt-text-generator' ),
      'manage_network_options',
      PWATG::SETTINGS_PAGE_SLUG,
      [ $this, 'render_network_settings_page' ]
    );
  }

  /**
   * Add a Settings link to the plugin's row on Network Admin → Plugins.
   *
   * @param array $links Action links.
   *
   * @return array
   */
  public function add_network_settings_action_link( $links ) {
    if ( ! $this->is_network_mode() ) {
      return $links;
    }

    array_unshift(
      $links,
      sprintf(
        '<a href="%s">%s</a>',
        esc_url( $this->get_network_settings_url() ),
        esc_html__( 'Settings', 'presswell-alt-text-generator' )
      )
    );

    return $links;
  }

  /**
   * Network Admin settings page URL.
   *
   * @return string
   */
  public function get_network_settings_url() {
    return network_admin_url( 'settings.php?page=' . PWATG::SETTINGS_PAGE_SLUG );
  }

  /**
   * Whether the current screen is the Network Admin settings page.
   *
   * @return bool
   */
  public function is_network_settings_screen() {
    return is_network_admin() && PWATG::SETTINGS_PAGE_SLUG === sanitize_key( $this->get_query_param( 'page' ) );
  }

  /**
   * Render the Network Admin settings page.
   */
  public function render_network_settings_page() {
    if ( ! $this->is_network_mode() || ! current_user_can( 'manage_network_options' ) ) {
      return;
    }

    $this->render_view(
      'settings-page.php',
      [
        'is_network'       => true,
        'is_subsite'       => false,
        'active_tab'       => 'settings',
        'settings_tab_url' => $this->get_network_settings_url(),
        'debug_tab_url'    => '',
      ]
    );
  }

  /**
   * Save the network settings (Network Admin forms post to edit.php).
   */
  public function handle_save_network_settings() {
    if ( ! $this->is_network_mode() || ! current_user_can( 'manage_network_options' ) ) {
      wp_die( esc_html__( 'You do not have permission to do that.', 'presswell-alt-text-generator' ), '', [ 'response' => 403 ] );
    }

    if ( ! $this->is_post_request() ) {
      wp_die( esc_html__( 'This action must be sent as a POST request.', 'presswell-alt-text-generator' ), '', [ 'response' => 405 ] );
    }

    check_admin_referer( PWATG::ACTION_SAVE_NETWORK_SETTINGS );

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_settings() cleans every field.
    $input = isset( $_POST[ PWATG::SETTINGS_KEY ] ) && is_array( $_POST[ PWATG::SETTINGS_KEY ] ) ? wp_unslash( $_POST[ PWATG::SETTINGS_KEY ] ) : [];

    $this->update_shared_option( PWATG::SETTINGS_KEY, $this->sanitize_settings( $input ) );

    $this->queue_admin_notice( 'success', __( 'Settings saved.', 'presswell-alt-text-generator' ) );

    wp_safe_redirect( $this->get_network_settings_url(), 303 );
    exit;
  }

  /**
   * Notices on the Network Admin settings page.
   */
  public function render_network_admin_notices() {
    if ( ! $this->is_network_mode() || ! $this->is_network_settings_screen() || ! current_user_can( 'manage_network_options' ) ) {
      return;
    }

    $this->render_admin_notices();
  }

  /**
   * Move settings saved on the main site before network settings existed.
   *
   * Runs on init and again whenever settings are read, so no request after an
   * upgrade runs without the keys.
   */
  public function maybe_migrate_network_settings() {
    if ( ! $this->is_network_mode() || false !== get_site_option( PWATG::SETTINGS_KEY, false ) ) {
      return;
    }

    $main_site = get_main_site_id();
    $legacy    = get_blog_option( $main_site, PWATG::SETTINGS_KEY, false );

    // Mark as migrated even with nothing to move, so this runs once.
    update_site_option( PWATG::SETTINGS_KEY, is_array( $legacy ) ? $legacy : [] );

    foreach ( [ PWATG::OPTION_DEBUG_LOG_ENABLED_AT, PWATG::OPTION_DEBUG_LOG_EXPIRED ] as $option ) {
      $value = get_blog_option( $main_site, $option, false );
      if ( false !== $value ) {
        update_site_option( $option, $value );
        delete_blog_option( $main_site, $option );
      }
    }

    // The keys now live with the network; leave no copy on the main site.
    if ( false !== $legacy ) {
      delete_blog_option( $main_site, PWATG::SETTINGS_KEY );
    }
  }

  /**
   * This site's Generate on Upload choice: 'default', 'on', or 'off'.
   *
   * @return string
   */
  public function get_site_auto_generate_choice() {
    $site = get_option( PWATG::SITE_SETTINGS_KEY, [] );
    $choice = is_array( $site ) && isset( $site['auto_generate'] ) ? sanitize_key( (string) $site['auto_generate'] ) : 'default';

    return in_array( $choice, [ 'on', 'off' ], true ) ? $choice : 'default';
  }

  /**
   * Sanitize a site's own settings.
   *
   * @param mixed $input Submitted values.
   *
   * @return array{auto_generate: string}
   */
  public function sanitize_site_settings( $input ) {
    $choice = is_array( $input ) && isset( $input['auto_generate'] ) ? sanitize_key( (string) $input['auto_generate'] ) : 'default';

    return [ 'auto_generate' => in_array( $choice, [ 'on', 'off' ], true ) ? $choice : 'default' ];
  }

  /**
   * Output the per-site Generate on Upload choice.
   */
  public function render_site_auto_generate_field() {
    $choice  = $this->get_site_auto_generate_choice();
    $network = $this->get_shared_option( PWATG::SETTINGS_KEY, [] );
    $default = is_array( $network ) && ! empty( $network['auto_generate'] );
    $options = [
      /* translators: %s: On or Off, the network's setting */
      'default' => sprintf( __( 'Network default (%s)', 'presswell-alt-text-generator' ), $default ? __( 'On', 'presswell-alt-text-generator' ) : __( 'Off', 'presswell-alt-text-generator' ) ),
      'on'      => __( 'On', 'presswell-alt-text-generator' ),
      'off'     => __( 'Off', 'presswell-alt-text-generator' ),
    ];
    ?>
    <select name="<?php echo esc_attr( PWATG::SITE_SETTINGS_KEY ); ?>[auto_generate]" id="pwatg-site-auto-generate">
      <?php foreach ( $options as $value => $label ) : ?>
        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $choice, $value ); ?>><?php echo esc_html( $label ); ?></option>
      <?php endforeach; ?>
    </select>
    <p class="description"><?php echo esc_html__( 'Whether images uploaded to this site get alt text automatically. Each image uses the network\'s AI credits.', 'presswell-alt-text-generator' ); ?></p>
    <?php
  }
}
