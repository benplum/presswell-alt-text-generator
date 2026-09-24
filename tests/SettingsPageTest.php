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
    delete_transient( PWATG::TRANSIENT_NOTICE_TEST_PROVIDER );
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

    $notice = get_transient( PWATG::TRANSIENT_NOTICE_TEST_PROVIDER );
    $this->assertNotEmpty( $notice );
    $this->assertSame( 'success', $notice['type'] );
    $this->assertStringContainsString( 'Connection successful', $notice['message'] );
  }

  public function test_test_connection_uses_the_saved_key_when_the_field_is_blank() {
    update_option( PWATG::SETTINGS_KEY, [ 'connector_source' => 'plugin', 'service' => 'openai', 'api_keys' => [ 'openai' => 'sk-saved' ] ] );
    $_POST = [
      'pwatg_test_provider_nonce' => wp_create_nonce( PWATG::AJAX_TEST_PROVIDER ),
      'service'                   => 'openai',
      'model'                     => 'gpt-4.1-mini',
      'api_key'                   => '',
    ];
    $_REQUEST = $_POST;

    try {
      $this->plugin->handle_test_provider();
    } catch ( Exception $e ) {
      $this->assertSame( 'redirect', $e->getMessage() );
    }

    $this->assertSame( 'sk-saved', PWATG_Test_Provider::$last_request['api_key'] );
    $this->assertSame( 'success', get_transient( PWATG::TRANSIENT_NOTICE_TEST_PROVIDER )['type'] );
  }

  public function test_saved_api_keys_are_not_printed_in_the_page() {
    update_option( PWATG::SETTINGS_KEY, [ 'connector_source' => 'plugin', 'service' => 'openai', 'api_keys' => [ 'openai' => 'sk-secret-value-1234' ] ] );

    ob_start();
    $this->plugin->render_api_key_field();
    $html = (string) ob_get_clean();

    $this->assertStringNotContainsString( 'sk-secret-value', $html );
    $this->assertStringContainsString( 'Saved key ending in 1234', $html );
    $this->assertStringContainsString( '[api_keys_remove][openai]', $html );
  }

  public function test_a_blank_key_field_keeps_the_saved_key() {
    update_option( PWATG::SETTINGS_KEY, [ 'api_keys' => [ 'openai' => 'sk-saved', 'anthropic' => 'ak-saved' ] ] );

    $sanitized = $this->plugin->sanitize_settings( [ 'api_keys' => [ 'openai' => '', 'anthropic' => 'ak-new' ] ] );

    $this->assertSame( 'sk-saved', $sanitized['api_keys']['openai'] );
    $this->assertSame( 'ak-new', $sanitized['api_keys']['anthropic'] );
  }

  public function test_the_remove_checkbox_clears_a_saved_key() {
    update_option( PWATG::SETTINGS_KEY, [ 'api_keys' => [ 'openai' => 'sk-saved' ] ] );

    $sanitized = $this->plugin->sanitize_settings( [ 'api_keys' => [ 'openai' => '' ], 'api_keys_remove' => [ 'openai' => '1' ] ] );

    $this->assertSame( '', $sanitized['api_keys']['openai'] );
    $this->assertArrayNotHasKey( 'api_keys_remove', $sanitized, 'The checkbox is not stored.' );
  }

  public function test_saving_settings_without_key_fields_keeps_saved_keys() {
    update_option( PWATG::SETTINGS_KEY, [ 'api_keys' => [ 'gemini' => 'gm-saved' ] ] );

    $sanitized = $this->plugin->sanitize_settings( [ 'debug_logging' => 'off' ] );

    $this->assertSame( 'gm-saved', $sanitized['api_keys']['gemini'] );
  }
}
