<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

trait PWATG_Pages_Trait {
  protected function construct_pages_trait() {
    add_action( 'admin_menu', [ $this, 'register_admin_pages' ] );
  }

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
}
