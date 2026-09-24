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
    add_filter( 'admin_footer_text', [ $this, 'filter_presswell_admin_footer_text' ], 20 );
    add_filter( 'update_footer', [ $this, 'filter_presswell_admin_version_text' ], 20 );
  }

  /** Add the settings and bulk pages. */
  public function register_admin_pages() {
    add_options_page(
      __( 'Alt Text Generator', 'presswell-alt-text-generator' ),
      __( 'Alt Text Generator', 'presswell-alt-text-generator' ),
      'manage_options',
      PWATG::SETTINGS_PAGE_SLUG,
      [ $this, 'render_settings_page' ]
    );

    add_media_page(
      __( 'Alt Text Generator', 'presswell-alt-text-generator' ),
      __( 'Alt Text Generator', 'presswell-alt-text-generator' ),
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
      esc_html__( 'Settings', 'presswell-alt-text-generator' )
    );

    array_unshift( $links, $settings_link );

    return $links;
  }

  /**
   * Whether the current admin screen belongs to this plugin.
   *
   * @return bool
   */
  protected function is_presswell_admin_screen() {
    if ( ! function_exists( 'get_current_screen' ) ) {
      return false;
    }

    $screen = get_current_screen();
    if ( ! $screen || empty( $screen->id ) ) {
      return false;
    }

    return in_array( $screen->id, [ PWATG::SETTINGS_PAGE_SCREEN_ID, PWATG::BULK_PAGE_SCREEN_ID ], true );
  }

  /**
   * Replace default admin footer text on plugin admin screens.
   *
   * @param string $footer_text Existing footer text.
   *
   * @return string
   */
  public function filter_presswell_admin_footer_text( $footer_text ) {
    if ( ! $this->is_presswell_admin_screen() ) {
      return $footer_text;
    }

    return sprintf(
      '<a href="%1$s" target="_blank" rel="noopener">%2$s</a> &bull; %3$s',
      esc_url( 'https://presswell.co' ),
      esc_html( 'Presswell Supply Co.' ),
      esc_html__( 'Quality Digital Goods', 'presswell-alt-text-generator' )
    );
  }

  /**
   * Replace default version footer text on plugin admin screens.
   *
   * @param string $version_text Existing version text.
   *
   * @return string
   */
  public function filter_presswell_admin_version_text( $version_text ) {
    if ( ! $this->is_presswell_admin_screen() ) {
      return $version_text;
    }

    /* translators: %s: plugin version number */
    return esc_html( sprintf( __( 'Alt Text Generator v%s', 'presswell-alt-text-generator' ), PWATG::VERSION ) );
  }
}
