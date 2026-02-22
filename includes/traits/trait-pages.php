<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Registers the plugin's admin menus/pages.
 */
trait PWATG_Pages_Trait {
  /** Hook menu registration. */
  protected function construct_pages_trait() {
    add_action( 'admin_menu', [ $this, 'register_admin_pages' ] );
    add_filter( 'plugin_action_links_' . plugin_basename( self::PLUGIN_FILE ), [ $this, 'add_settings_action_link' ] );
  }

  /** Add the settings and bulk pages. */
  public function register_admin_pages() {
    add_options_page(
      __( 'Alt Text Generator', PWATG::TEXT_DOMAIN ),
      __( 'Alt Text Generator', PWATG::TEXT_DOMAIN ),
      'manage_options',
      PWATG::SETTINGS_PAGE_SLUG,
      [ $this, 'render_settings_page' ]
    );

    add_media_page(
      __( 'Alt Text Generator', PWATG::TEXT_DOMAIN ),
      __( 'Alt Text Generator', PWATG::TEXT_DOMAIN ),
      'manage_options',
      PWATG::BULK_PAGE_SLUG,
      [ $this, 'render_bulk_page' ]
    );
  }
  
  /**
   * Append a Settings shortcut on the Plugins list table.
   */
  public function add_settings_action_link( $links ) {
    $settings_link = sprintf(
      '<a href="%s">%s</a>',
      esc_url( admin_url( PWATG::SETTINGS_PAGE_URL ) ),
      esc_html__( 'Settings', PWATG::TEXT_DOMAIN )
    );

    array_unshift( $links, $settings_link );

    return $links;
  }
}
