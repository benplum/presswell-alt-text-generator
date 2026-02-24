<?php
/**
 * Tests for settings page workflows.
 */

class SettingsPageTest extends WP_UnitTestCase {
  /** @var Presswell_Alt_Text_Generator */
  protected $plugin;

  protected function setUp(): void {
    parent::setUp();
    $this->plugin = presswell_alt_text_generator();
    wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    PWATG_Test_Provider::reset();
    add_filter( 'pwatg_provider_registry', [ $this, 'override_provider_map' ] );
    add_filter( 'wp_redirect', [ $this, 'intercept_redirect' ], 10, 2 );
  }

  protected function tearDown(): void {
    remove_filter( 'pwatg_provider_registry', [ $this, 'override_provider_map' ] );
    remove_filter( 'wp_redirect', [ $this, 'intercept_redirect' ], 10 );
    delete_transient( PWATG::NOTICE_KEY_TEST_PROVIDER );
    parent::tearDown();
  }

  public function override_provider_map( $map ) {
    $map['openai'] = 'PWATG_Test_Provider';
    return $map;
  }

  public function intercept_redirect( $location, $status ) {
    throw new Exception( 'redirect' );
  }

  public function test_handle_test_provider_sets_success_notice() {
    $nonce = wp_create_nonce( PWATG::AJAX_TEST_PROVIDER );
    $_POST = [
      'pwatg_test_provider_nonce' => $nonce,
      'service' => 'openai',
      'model'   => 'gpt-4.1-mini',
      'api_key' => 'sk-test',
      '_wp_http_referer' => admin_url( PWATG::SETTINGS_PAGE_URL ),
    ];
    $_REQUEST = $_POST;

    try {
      $this->plugin->handle_test_provider();
    } catch ( Exception $e ) {
      $this->assertSame( 'redirect', $e->getMessage() );
    }

    $notice = get_transient( PWATG::NOTICE_KEY_TEST_PROVIDER );
    $this->assertNotEmpty( $notice );
    $this->assertSame( 'success', $notice['type'] );
    $this->assertStringContainsString( 'Connection successful', $notice['message'] );
  }
}
