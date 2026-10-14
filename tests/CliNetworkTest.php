<?php
/**
 * Tests covering which sites the network WP-CLI command processes. Run with WP_MULTISITE=1.
 */

class CliNetworkTest extends WP_UnitTestCase {
  /** @var Presswell_Alt_Text_Generator */
  protected $plugin;

  /** @var string */
  protected $basename = 'presswell-alt-text-generator/presswell-alt-text-generator.php';

  protected function setUp(): void {
    if ( ! is_multisite() ) {
      $this->markTestSkipped( 'Run the suite with WP_MULTISITE=1.' );
    }

    parent::setUp();

    $this->plugin = presswell_alt_text_generator();
  }

  protected function tearDown(): void {
    if ( is_multisite() ) {
      delete_site_option( 'active_sitewide_plugins' );
    }

    parent::tearDown();
  }

  public function test_only_sites_that_activated_the_plugin_count_as_active() {
    $active   = self::factory()->blog->create();
    $inactive = self::factory()->blog->create();
    update_blog_option( $active, 'active_plugins', [ $this->basename ] );
    update_blog_option( $inactive, 'active_plugins', [ 'another-plugin/another-plugin.php' ] );

    $this->assertTrue( $this->is_active_on( $active ) );
    $this->assertFalse( $this->is_active_on( $inactive ), 'Activating on one site does not opt the others in.' );
  }

  public function test_network_activation_covers_every_site() {
    $site = self::factory()->blog->create();
    update_site_option( 'active_sitewide_plugins', [ $this->basename => time() ] );

    $this->assertTrue( $this->is_active_on( $site ) );
  }

  public function test_a_plugin_loaded_outside_the_plugins_directory_runs_everywhere() {
    $method = new ReflectionMethod( $this->plugin, 'is_loaded_as_regular_plugin' );
    $method->setAccessible( true );

    $in_plugins_dir = 0 === strpos( wp_normalize_path( Presswell_Alt_Text_Generator::PLUGIN_FILE ), trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) ) );

    $this->assertSame( $in_plugins_dir, $method->invoke( $this->plugin ), 'Must-use installs have no activation to check.' );
  }

  /**
   * @param int $site_id Site ID.
   *
   * @return bool
   */
  protected function is_active_on( $site_id ) {
    $method = new ReflectionMethod( $this->plugin, 'is_plugin_active_on_site' );
    $method->setAccessible( true );

    return $method->invoke( $this->plugin, $site_id, $this->basename );
  }
}
