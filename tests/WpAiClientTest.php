<?php
/**
 * Tests covering requests sent through the WordPress AI Client (Core mode, WordPress 7.0+).
 */

class WpAiClientTest extends WP_UnitTestCase {
  /** @var Presswell_Alt_Text_Generator */
  protected $plugin;

  /** @var string[] Providers the fake registry reports as ready. */
  protected $ready = [ 'openai' ];

  protected function setUp(): void {
    parent::setUp();

    if ( ! function_exists( 'wp_get_connectors' ) ) {
      $this->markTestSkipped( 'Requires WordPress AI Connectors.' );
    }

    $this->plugin = presswell_alt_text_generator();

    PWATG_Fake_Prompt_Builder::reset();
    PWATG_Test_Provider::reset();
    PWATG_WP_AI_Client_Service::$prompt_factory = static function ( $prompt ) {
      return new PWATG_Fake_Prompt_Builder( $prompt );
    };
    PWATG_WP_AI_Client_Service::$provider_checker = function ( $provider ) {
      return in_array( $provider, $this->ready, true );
    };

    // Direct calls would go to the stub, so a request reaching it means the AI Client was skipped.
    add_filter( 'pwatg_provider_registry', [ $this, 'override_provider_map' ] );

    update_option(
      PWATG::SETTINGS_KEY,
      [
        'connector_source' => 'core',
        'core_connector'   => 'openai',
        'service'          => 'openai',
        'model'            => 'gpt-4.1-mini',
        'auto_generate'    => '',
      ]
    );
  }

  protected function tearDown(): void {
    PWATG_WP_AI_Client_Service::$prompt_factory   = null;
    PWATG_WP_AI_Client_Service::$provider_checker = null;
    remove_filter( 'pwatg_provider_registry', [ $this, 'override_provider_map' ] );
    remove_all_filters( 'pwatg_use_wp_ai_client' );
    pwatg_test_delete_lock();

    parent::tearDown();
  }

  public function override_provider_map( $map ) {
    $map['openai']    = 'PWATG_Test_Provider';
    $map['anthropic'] = 'PWATG_Test_Provider';
    return $map;
  }

  public function test_core_mode_sends_the_image_through_the_ai_client_without_a_plugin_key() {
    $attachment_id = $this->create_image();

    $this->assertTrue( $this->plugin->generate_alt_text_for_attachment( $attachment_id, true ) );
    $this->assertSame( 'AI Client alt text', get_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, true ) );

    $calls = PWATG_Fake_Prompt_Builder::$last->calls;
    $this->assertSame( [ 'openai' ], $calls['using_provider'] );
    $this->assertSame( [ 'gpt-4.1-mini' ], $calls['using_model_preference'] );
    $this->assertStringStartsWith( 'data:image/jpeg;base64,', $calls['with_file'][0] );
    $this->assertSame( 'image/jpeg', $calls['with_file'][1] );
    $this->assertStringContainsString( 'Return only the alt text', $calls['prompt'][0] );
    $this->assertNull( PWATG_Test_Provider::$last_request, 'The plugin client is not used.' );
  }

  public function test_connectors_without_an_ai_client_provider_fall_back_to_the_plugin_client() {
    $this->ready = [];
    update_option( 'connectors_ai_openai_api_key', 'sk-connector' );
    $attachment_id = $this->create_image();

    $this->assertTrue( $this->plugin->generate_alt_text_for_attachment( $attachment_id, true ) );

    $this->assertNull( PWATG_Fake_Prompt_Builder::$last );
    $this->assertSame( 'sk-connector', PWATG_Test_Provider::$last_request['api_key'] );
  }

  public function test_plugin_mode_never_uses_the_ai_client() {
    update_option( PWATG::SETTINGS_KEY, [ 'connector_source' => 'plugin', 'service' => 'openai', 'api_keys' => [ 'openai' => 'sk-plugin' ] ] );

    $this->plugin->generate_alt_text_for_attachment( $this->create_image(), true );

    $this->assertNull( PWATG_Fake_Prompt_Builder::$last );
    $this->assertSame( 'sk-plugin', PWATG_Test_Provider::$last_request['api_key'] );
  }

  public function test_the_ai_client_can_be_turned_off_with_a_filter() {
    update_option( 'connectors_ai_openai_api_key', 'sk-connector' );
    add_filter( 'pwatg_use_wp_ai_client', '__return_false' );

    $this->plugin->generate_alt_text_for_attachment( $this->create_image(), true );

    $this->assertNull( PWATG_Fake_Prompt_Builder::$last );
  }

  /**
   * @dataProvider provider_ai_client_errors
   */
  public function test_ai_client_errors_map_to_the_plugin_pauses( $error, $expected_code, $locks ) {
    PWATG_Fake_Prompt_Builder::$result = $error;

    $result = $this->plugin->generate_alt_text_for_attachment( $this->create_image(), true );

    $this->assertInstanceOf( WP_Error::class, $result );
    $this->assertSame( $expected_code, $result->get_error_code() );
    $this->assertSame( 'openai', $result->get_error_data()['provider'] );
    $this->assertSame( $locks, false !== pwatg_test_get_lock() );
  }

  public function provider_ai_client_errors() {
    return [
      'rate limit'       => [ new WP_Error( 'prompt_client_error', 'Too many requests', [ 'status' => 429 ] ), 'pwatg_rate_limited', true ],
      'quota'            => [ new WP_Error( 'prompt_client_error', 'You exceeded your current quota', [ 'status' => 429 ] ), 'pwatg_quota_exceeded', true ],
      'payment'          => [ new WP_Error( 'prompt_client_error', 'Payment required', [ 'status' => 402 ] ), 'pwatg_quota_exceeded', true ],
      'overloaded'       => [ new WP_Error( 'prompt_upstream_server_error', 'Overloaded', [ 'status' => 503 ] ), 'pwatg_rate_limited', true ],
      'bad key'          => [ new WP_Error( 'prompt_client_error', 'Invalid API key', [ 'status' => 401 ] ), 'pwatg_api_error', false ],
      'blocked by site'  => [ new WP_Error( 'prompt_prevented', 'Prompt execution was prevented by a filter.', [ 'status' => 503 ] ), 'pwatg_api_error', false ],
    ];
  }

  public function test_the_google_connector_is_tracked_as_gemini_for_limits() {
    $this->ready = [ 'google' ];
    update_option( PWATG::SETTINGS_KEY, [ 'connector_source' => 'core', 'core_connector' => 'google', 'service' => 'gemini' ] );
    PWATG_Fake_Prompt_Builder::$result = new WP_Error( 'prompt_client_error', 'Too many requests', [ 'status' => 429 ] );

    $result = $this->plugin->generate_alt_text_for_attachment( $this->create_image(), true );

    $this->assertSame( [ 'google' ], PWATG_Fake_Prompt_Builder::$last->calls['using_provider'] );
    $this->assertSame( 'gemini', $result->get_error_data()['provider'], 'Lock checks compare against the gemini service.' );
  }

  public function test_a_configured_ai_client_provider_counts_as_a_usable_connector() {
    delete_option( PWATG::SETTINGS_KEY );

    $choices = $this->plugin->get_active_core_connector_choices();

    $this->assertTrue( $choices['openai']['has_key'], 'Set up through the AI Client, even with no key option.' );
    $this->assertSame( 'core', $this->plugin->get_settings()['connector_source'] );
  }

  public function test_test_connection_uses_the_ai_client() {
    wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    add_filter( 'wp_redirect', [ $this, 'stop_redirect' ] );
    $_POST = [
      'pwatg_test_provider_nonce' => wp_create_nonce( PWATG::AJAX_TEST_PROVIDER ),
      'service'                   => 'openai',
      'model'                     => 'gpt-4.1-mini',
      'api_key'                   => '',
    ];
    $_REQUEST = $_POST;
    PWATG_Fake_Prompt_Builder::$result = 'OK';

    try {
      $this->plugin->handle_test_provider();
    } catch ( Exception $e ) {
      $this->assertSame( 'redirect', $e->getMessage() );
    }

    remove_filter( 'wp_redirect', [ $this, 'stop_redirect' ] );

    $this->assertSame( 'success', get_transient( $this->plugin->get_test_provider_notice_key() )['type'] );
    $this->assertSame( [ 'openai' ], PWATG_Fake_Prompt_Builder::$last->calls['using_provider'] );
  }

  public function stop_redirect() {
    throw new Exception( 'redirect' );
  }

  protected function create_image() {
    $attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
    delete_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT );

    return $attachment_id;
  }
}
