<?php
/**
 * Tests covering the WP-CLI command logic.
 */

class CliTest extends WP_UnitTestCase {
  /** @var Presswell_Alt_Text_Generator */
  protected $plugin;

  protected function setUp(): void {
    parent::setUp();

    $this->plugin = presswell_alt_text_generator();

    $settings = $this->plugin->get_default_settings();
    $settings['api_keys']['openai'] = 'sk-test';
    $settings['connector_source']   = 'plugin';
    $settings['auto_generate']      = '';
    update_option( PWATG::SETTINGS_KEY, $settings );

    PWATG_Test_Provider::reset();
    PWATG_Test_Provider::$response = 'CLI alt text';
    add_filter( 'pwatg_provider_registry', [ $this, 'override_provider_map' ] );
  }

  protected function tearDown(): void {
    remove_filter( 'pwatg_provider_registry', [ $this, 'override_provider_map' ] );
    pwatg_test_delete_lock();
    delete_option( PWATG::OPTION_BULK_RUN );
    parent::tearDown();
  }

  public function override_provider_map( $map ) {
    $map['openai'] = 'PWATG_Test_Provider';
    return $map;
  }

  public function test_force_is_a_dry_run_by_default() {
    $with_alt = $this->create_image();
    update_post_meta( $with_alt, PWATG::META_KEY_ALT_TEXT, 'Written by a person' );
    $this->create_image();

    $result = $this->plugin->run_bulk_generate_cli( [ 'force' => true ] );

    $this->assertTrue( $result['dry_run'] );
    $this->assertTrue( $result['overwrite'] );
    $this->assertSame( 2, $result['selected'] );
    $this->assertNull( PWATG_Test_Provider::$last_request, 'Nothing is sent to the provider.' );
    $this->assertSame( 'Written by a person', get_post_meta( $with_alt, PWATG::META_KEY_ALT_TEXT, true ) );
  }

  public function test_force_overwrites_when_dry_run_is_turned_off() {
    $with_alt = $this->create_image();
    update_post_meta( $with_alt, PWATG::META_KEY_ALT_TEXT, 'Written by a person' );

    $result = $this->plugin->run_bulk_generate_cli( [ 'force' => true, 'dry-run' => 'false' ] );

    $this->assertFalse( $result['dry_run'] );
    $this->assertSame( 1, $result['updated'] );
    $this->assertSame( 'CLI alt text', get_post_meta( $with_alt, PWATG::META_KEY_ALT_TEXT, true ) );
  }

  public function test_filling_missing_alt_text_runs_straight_away() {
    $missing = $this->create_image();
    $seen    = [];

    $result = $this->plugin->run_bulk_generate_cli(
      [
        '__status_callback' => static function ( $attachment_id, $status ) use ( &$seen ) {
          $seen[ $attachment_id ] = $status;
        },
      ]
    );

    $this->assertFalse( $result['dry_run'] );
    $this->assertSame( 1, $result['updated'] );
    $this->assertSame( 0, $result['missing_remaining'] );
    $this->assertSame( [ $missing => 'updated' ], $seen, 'Each image is reported as it is processed.' );
  }

  public function test_a_dry_run_can_be_requested_without_force() {
    $this->create_image();

    $result = $this->plugin->run_bulk_generate_cli( [ 'dry-run' => true ] );

    $this->assertTrue( $result['dry_run'] );
    $this->assertSame( 1, $result['selected'] );
    $this->assertNull( PWATG_Test_Provider::$last_request );
  }

  public function test_a_provider_limit_stops_the_run_and_is_reported() {
    $this->create_image();
    $this->create_image();
    PWATG_Test_Provider::$response = new WP_Error( 'pwatg_rate_limited', 'Slow down', [ 'provider' => 'openai', 'retry_after' => 120 ] );

    $result = $this->plugin->run_bulk_generate_cli();

    $this->assertTrue( $result['halted'] );
    $this->assertSame( 1, $result['processed'], 'The run stops at the first limit.' );
    $this->assertStringContainsString( 'Slow down', $result['halt_message'] );
  }

  public function test_an_active_limit_blocks_a_new_run() {
    $this->create_image();
    pwatg_test_set_lock( [ 'code' => 'pwatg_rate_limited', 'provider' => 'openai', 'message' => 'Paused', 'until' => time() + 300 ], 300 );

    $result = $this->plugin->run_bulk_generate_cli();

    $this->assertFalse( $result['ok'] );
    $this->assertTrue( $result['halted'] );
    $this->assertNull( PWATG_Test_Provider::$last_request );
  }

  public function test_cli_waits_for_a_browser_run_and_releases_its_own() {
    $this->create_image();
    $browser_run = $this->plugin->acquire_bulk_run( 'Jamie' );

    $blocked = $this->plugin->run_bulk_generate_cli();
    $this->assertFalse( $blocked['ok'] );
    $this->assertStringContainsString( 'started by Jamie', $blocked['error'] );
    $this->assertNull( PWATG_Test_Provider::$last_request );

    $this->plugin->release_bulk_run( $browser_run );

    $this->assertTrue( $this->plugin->run_bulk_generate_cli()['ok'] );
    $this->assertFalse( get_option( PWATG::OPTION_BULK_RUN ), 'The CLI run releases the lock when it ends.' );
  }

  public function test_generate_reports_skips_and_failures() {
    $attachment_id = $this->create_image();
    update_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, 'Existing' );

    $this->assertSame( 'skipped', $this->plugin->run_generate_cli( $attachment_id )['status'] );
    $this->assertSame( 'updated', $this->plugin->run_generate_cli( $attachment_id, [ 'force' => true ] )['status'] );

    $failed = $this->plugin->run_generate_cli( self::factory()->post->create() );
    $this->assertFalse( $failed['ok'] );
    $this->assertSame( 'pwatg_invalid_attachment', $failed['code'] );
  }

  public function test_network_command_requires_multisite() {
    if ( is_multisite() ) {
      $this->markTestSkipped( 'Single-site check.' );
    }

    $this->assertFalse( $this->plugin->run_network_bulk_generate_cli()['ok'] );
  }

  protected function create_image() {
    $attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
    delete_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT );

    return $attachment_id;
  }
}
