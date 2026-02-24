<?php
/**
 * PHPUnit bootstrap file.
 */

$composer_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( file_exists( $composer_autoload ) ) {
  require_once $composer_autoload;
}

require_once __DIR__ . '/helpers/class-pwatg-test-provider.php';

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
  $polyfills_path = dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills';
  if ( file_exists( $polyfills_path . '/phpunitpolyfills-autoload.php' ) ) {
    define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $polyfills_path );
  }
}

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
  $tmp_dir    = sys_get_temp_dir();
  $_tests_dir = rtrim( $tmp_dir, '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir ) ) {
  $_tests_dir = '/tmp/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
  fwrite( STDERR, "Could not find WordPress test suite in ".$_tests_dir.".\n" );
  exit( 1 );
}

require $_tests_dir . '/includes/functions.php';

function _pwatg_manually_load_plugin() {
  require dirname( __DIR__ ) . '/presswell-alt-text-generator.php';
}
tests_add_filter( 'muplugins_loaded', '_pwatg_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';
