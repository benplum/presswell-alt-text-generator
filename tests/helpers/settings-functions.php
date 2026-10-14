<?php
/**
 * Settings that apply everywhere are network options in network mode, like the
 * plugin's get_shared_option(). Tests use these so they run the same in every mode.
 *
 * The test library loads the plugin without activating it, so Multisite runs treat
 * it as network-activated by default (the setup the network tests cover). Tests of
 * per-site activation call pwatg_test_set_network_mode( false ); WP_UnitTestCase
 * restores hooks after each test.
 */

/**
 * Report the plugin as network-activated.
 *
 * @param mixed $plugins Network-activated plugins, basename => time.
 *
 * @return array
 */
function pwatg_test_network_activated( $plugins ) {
  $plugins = is_array( $plugins ) ? $plugins : [];

  if ( class_exists( 'Presswell_Alt_Text_Generator' ) ) {
    $plugins[ plugin_basename( Presswell_Alt_Text_Generator::PLUGIN_FILE ) ] = time();
  }

  return $plugins;
}

// This file loads before the test library, so register the filter the way
// tests_add_filter() does before add_filter() exists.
$GLOBALS['wp_filter']['site_option_active_sitewide_plugins'][10]['pwatg_test_network_activated'] = [
  'function'      => 'pwatg_test_network_activated',
  'accepted_args' => 1,
];

/**
 * Switch between network activation and per-site activation for the current test.
 *
 * @param bool $network Whether the plugin is network-activated.
 */
function pwatg_test_set_network_mode( $network ) {
  if ( $network ) {
    add_filter( 'site_option_active_sitewide_plugins', 'pwatg_test_network_activated' );
  } else {
    remove_filter( 'site_option_active_sitewide_plugins', 'pwatg_test_network_activated' );
  }
}

function pwatg_test_is_network_mode() {
  return presswell_alt_text_generator()->is_network_mode();
}

function pwatg_test_get_option( $name, $default = false ) {
  return pwatg_test_is_network_mode() ? get_site_option( $name, $default ) : get_option( $name, $default );
}

function pwatg_test_update_option( $name, $value ) {
  return pwatg_test_is_network_mode() ? update_site_option( $name, $value ) : update_option( $name, $value );
}

function pwatg_test_add_option( $name, $value ) {
  return pwatg_test_is_network_mode() ? add_site_option( $name, $value ) : add_option( $name, $value );
}

function pwatg_test_delete_option( $name ) {
  return pwatg_test_is_network_mode() ? delete_site_option( $name ) : delete_option( $name );
}

/**
 * Run uninstall.php without leaking its side effects into later tests.
 *
 * uninstall.php removes the settings sanitizer and switches between sites, so both
 * are put back here. WP_UNINSTALL_PLUGIN can't be undefined; only uninstall.php
 * reads it, so later tests are unaffected.
 */
function pwatg_test_run_uninstall() {
  global $wp_filter;

  if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    define( 'WP_UNINSTALL_PLUGIN', 'presswell-alt-text-generator/presswell-alt-text-generator.php' );
  }

  $hook    = 'sanitize_option_' . PWATG::SETTINGS_KEY;
  $saved   = isset( $wp_filter[ $hook ] ) ? clone $wp_filter[ $hook ] : null;
  $blog_id = get_current_blog_id();

  try {
    include dirname( __DIR__, 2 ) . '/uninstall.php';
  } finally {
    if ( null !== $saved ) {
      $wp_filter[ $hook ] = $saved;
    }

    if ( is_multisite() && get_current_blog_id() !== $blog_id ) {
      switch_to_blog( $blog_id );
    }

    // Uninstall deletes some rows with SQL; read them fresh, as the next request would.
    wp_cache_flush();
  }
}
