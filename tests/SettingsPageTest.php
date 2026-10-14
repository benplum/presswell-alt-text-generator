<?php
/**
 * Tests for settings page workflows.
 */

class SettingsPageTest extends WP_UnitTestCase {
  /** @var Presswell_Alt_Text_Generator */
  protected $plugin;

  /** @var array{location: string, status: int}|null */
  protected $redirect = null;

  protected function setUp(): void {
    parent::setUp();
    $this->plugin = presswell_alt_text_generator();
    wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    if ( is_multisite() ) {
      // In network mode Test Connection spends the network's credits, so it's for Super Admins.
      grant_super_admin( get_current_user_id() );
    }
    PWATG_Test_Provider::reset();
    add_filter( 'pwatg_provider_registry', [ $this, 'override_provider_map' ] );
    add_filter( 'wp_redirect', [ $this, 'intercept_redirect' ], 10, 2 );
  }

  protected function tearDown(): void {
    remove_filter( 'pwatg_provider_registry', [ $this, 'override_provider_map' ] );
    remove_filter( 'wp_redirect', [ $this, 'intercept_redirect' ], 10 );
    delete_transient( $this->plugin->get_test_provider_notice_key() );
    pwatg_test_delete_option( PWATG::SETTINGS_KEY );
    $_POST    = [];
    $_REQUEST = [];
    unset( $_SERVER['REQUEST_METHOD'] );
    parent::tearDown();
  }

  public function override_provider_map( $map ) {
    $map['openai'] = 'PWATG_Test_Provider';
    return $map;
  }

  public function intercept_redirect( $location, $status ) {
    $this->redirect = [ 'location' => $location, 'status' => $status ];
    throw new Exception( 'redirect' );
  }

  /**
   * Run the Test Connection handler with the Debug tab's form fields.
   *
   * @param array $fields Extra posted fields.
   */
  private function run_test_provider( array $fields = [] ) {
    $_POST    = array_merge( [ 'pwatg_test_provider_nonce' => wp_create_nonce( PWATG::AJAX_TEST_PROVIDER ) ], $fields );
    $_REQUEST = $_POST;

    try {
      $_SERVER['REQUEST_METHOD'] = 'POST';
      $this->plugin->handle_test_provider();
    } catch ( WPDieException $denied ) {
      throw $denied;
    } catch ( Exception $e ) {
      $this->assertSame( 'redirect', $e->getMessage() );
    }
  }

  public function test_test_connection_returns_to_the_debug_tab_with_a_303() {
    pwatg_test_update_option( PWATG::SETTINGS_KEY, [ 'connector_source' => 'plugin', 'service' => 'openai', 'api_keys' => [ 'openai' => 'sk-saved' ] ] );

    $this->run_test_provider();

    $this->assertSame( $this->plugin->get_debug_log_viewer_url(), $this->redirect['location'] );
    $this->assertSame( 303, $this->redirect['status'] );
  }

  public function test_the_debug_tab_form_tests_the_saved_provider_and_model() {
    pwatg_test_update_option( PWATG::SETTINGS_KEY, [ 'connector_source' => 'plugin', 'service' => 'openai', 'model' => 'gpt-4o', 'api_keys' => [ 'openai' => 'sk-saved' ] ] );

    $this->run_test_provider();

    $this->assertSame( 'sk-saved', PWATG_Test_Provider::$last_request['api_key'] );
    $this->assertSame( 'gpt-4o', PWATG_Test_Provider::$last_request['model'] );
    $this->assertSame( 'success', get_transient( $this->plugin->get_test_provider_notice_key() )['type'] );
  }

  public function test_the_connection_result_lands_where_notices_are_shown() {
    pwatg_test_update_option( PWATG::SETTINGS_KEY, [ 'connector_source' => 'plugin', 'service' => 'openai', 'api_keys' => [ 'openai' => 'sk-saved' ] ] );
    $this->run_test_provider();

    // render_admin_notices() shows the stored result on any screen with this page slug.
    parse_str( (string) wp_parse_url( $this->redirect['location'], PHP_URL_QUERY ), $query );
    $this->assertSame( PWATG::SETTINGS_PAGE_SLUG, $query['page'] );
    $this->assertSame( 'debug', $query['tab'] );
    $this->assertNotEmpty( get_transient( $this->plugin->get_test_provider_notice_key() ) );
  }

  public function test_editors_cannot_test_the_connection() {
    wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

    // Another test may have defined DOING_AJAX, which routes wp_die() to the AJAX handler.
    $die = static function () {
      return static function ( $message ) {
        throw new WPDieException( is_string( $message ) ? $message : 'wp_die' );
      };
    };
    add_filter( 'wp_die_handler', $die, 999 );
    add_filter( 'wp_die_ajax_handler', $die, 999 );

    try {
      $this->run_test_provider();
      $this->fail( 'An editor ran Test Connection.' );
    } catch ( WPDieException $denied ) {
      $this->assertStringContainsString( 'permission', $denied->getMessage() );
    } finally {
      remove_filter( 'wp_die_handler', $die, 999 );
      remove_filter( 'wp_die_ajax_handler', $die, 999 );
    }

    $this->assertNull( PWATG_Test_Provider::$last_request, 'No request reaches the provider.' );
    $this->assertNull( $this->redirect );
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
      $_SERVER['REQUEST_METHOD'] = 'POST';
      $this->plugin->handle_test_provider();
    } catch ( Exception $e ) {
      $this->assertSame( 'redirect', $e->getMessage() );
    }

    $notice = get_transient( $this->plugin->get_test_provider_notice_key() );
    $this->assertNotEmpty( $notice );
    $this->assertSame( 'success', $notice['type'] );
    $this->assertStringContainsString( 'Connection successful', $notice['message'] );
  }

  public function test_test_connection_uses_the_saved_key_when_the_field_is_blank() {
    pwatg_test_update_option( PWATG::SETTINGS_KEY, [ 'connector_source' => 'plugin', 'service' => 'openai', 'api_keys' => [ 'openai' => 'sk-saved' ] ] );
    $_POST = [
      'pwatg_test_provider_nonce' => wp_create_nonce( PWATG::AJAX_TEST_PROVIDER ),
      'service'                   => 'openai',
      'model'                     => 'gpt-4.1-mini',
      'api_key'                   => '',
    ];
    $_REQUEST = $_POST;

    try {
      $_SERVER['REQUEST_METHOD'] = 'POST';
      $this->plugin->handle_test_provider();
    } catch ( Exception $e ) {
      $this->assertSame( 'redirect', $e->getMessage() );
    }

    $this->assertSame( 'sk-saved', PWATG_Test_Provider::$last_request['api_key'] );
    $this->assertSame( 'success', get_transient( $this->plugin->get_test_provider_notice_key() )['type'] );
  }

  public function test_saved_api_keys_are_not_printed_in_the_page() {
    pwatg_test_update_option( PWATG::SETTINGS_KEY, [ 'connector_source' => 'plugin', 'service' => 'openai', 'api_keys' => [ 'openai' => 'sk-secret-value-1234' ] ] );

    ob_start();
    $this->plugin->render_api_key_field();
    $html = (string) ob_get_clean();

    $this->assertStringNotContainsString( 'sk-secret-value', $html );
    $this->assertStringContainsString( 'Saved key ending in 1234', $html );
    $this->assertStringContainsString( '[api_keys_remove][openai]', $html );
  }

  public function test_a_blank_key_field_keeps_the_saved_key() {
    pwatg_test_update_option( PWATG::SETTINGS_KEY, [ 'api_keys' => [ 'openai' => 'sk-saved', 'anthropic' => 'ak-saved' ] ] );

    $sanitized = $this->plugin->sanitize_settings( [ 'api_keys' => [ 'openai' => '', 'anthropic' => 'ak-new' ] ] );

    $this->assertSame( 'sk-saved', $sanitized['api_keys']['openai'] );
    $this->assertSame( 'ak-new', $sanitized['api_keys']['anthropic'] );
  }

  public function test_the_remove_checkbox_clears_a_saved_key() {
    pwatg_test_update_option( PWATG::SETTINGS_KEY, [ 'api_keys' => [ 'openai' => 'sk-saved' ] ] );

    $sanitized = $this->plugin->sanitize_settings( [ 'api_keys' => [ 'openai' => '' ], 'api_keys_remove' => [ 'openai' => '1' ] ] );

    $this->assertSame( '', $sanitized['api_keys']['openai'] );
    $this->assertArrayNotHasKey( 'api_keys_remove', $sanitized, 'The checkbox is not stored.' );
  }

  public function test_saving_settings_without_key_fields_keeps_saved_keys() {
    pwatg_test_update_option( PWATG::SETTINGS_KEY, [ 'api_keys' => [ 'gemini' => 'gm-saved' ] ] );

    $sanitized = $this->plugin->sanitize_settings( [ 'debug_logging' => 'off' ] );

    $this->assertSame( 'gm-saved', $sanitized['api_keys']['gemini'] );
  }

  public function test_connection_results_are_shown_only_to_the_admin_who_ran_them() {
    $_POST = [
      'pwatg_test_provider_nonce' => wp_create_nonce( PWATG::AJAX_TEST_PROVIDER ),
      'service'                   => 'openai',
      'model'                     => 'gpt-4.1-mini',
      'api_key'                   => 'sk-test',
    ];
    $_REQUEST = $_POST;

    try {
      $_SERVER['REQUEST_METHOD'] = 'POST';
      $this->plugin->handle_test_provider();
    } catch ( Exception $e ) {
      $this->assertSame( 'redirect', $e->getMessage() );
    }

    $this->assertNotEmpty( get_transient( $this->plugin->get_test_provider_notice_key() ) );

    wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    $this->assertFalse( get_transient( $this->plugin->get_test_provider_notice_key() ) );
  }

  public function test_test_connection_refuses_get_requests() {
    wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    if ( is_multisite() ) {
      grant_super_admin( get_current_user_id() );
    }

    $_REQUEST['pwatg_test_provider_nonce'] = wp_create_nonce( PWATG::AJAX_TEST_PROVIDER );
    $_SERVER['REQUEST_METHOD']             = 'GET';

    // Earlier suites can leave DOING_AJAX defined, which routes wp_die() to a handler that exits.
    $die = static function () {
      return static function ( $message ) {
        throw new WPDieException( is_string( $message ) ? $message : '' );
      };
    };
    add_filter( 'wp_die_handler', $die, 999 );
    add_filter( 'wp_die_ajax_handler', $die, 999 );

    try {
      $this->plugin->handle_test_provider();
      $this->fail( 'A GET request should be refused.' );
    } catch ( WPDieException $e ) {
      $this->assertStringContainsString( 'POST', $e->getMessage() );
    } finally {
      remove_filter( 'wp_die_handler', $die, 999 );
      remove_filter( 'wp_die_ajax_handler', $die, 999 );
    }
  }
}
