<?php
/**
 * Tests covering the Image block Generate button and its REST route.
 */

class BlockEditorTest extends WP_UnitTestCase {
  /** @var Presswell_Alt_Text_Generator */
  protected $plugin;

  /** @var WP_REST_Server */
  protected $server;

  protected function setUp(): void {
    parent::setUp();

    $this->plugin = presswell_alt_text_generator();

    $settings = $this->plugin->get_default_settings();
    $settings['api_keys']['openai'] = 'sk-test';
    $settings['connector_source']   = 'plugin';
    $settings['auto_generate']      = '';
    update_option( PWATG::SETTINGS_KEY, $settings );

    PWATG_Test_Provider::reset();
    PWATG_Test_Provider::$response = 'Block alt text';
    add_filter( 'pwatg_provider_registry', [ $this, 'override_provider_map' ] );

    global $wp_rest_server;
    $wp_rest_server = new WP_REST_Server();
    $this->server   = $wp_rest_server;
    do_action( 'rest_api_init' );
  }

  protected function tearDown(): void {
    remove_filter( 'pwatg_provider_registry', [ $this, 'override_provider_map' ] );
    pwatg_test_delete_lock();
    global $wp_rest_server;
    $wp_rest_server = null;
    wp_dequeue_script( PWATG::ASSET_HANDLE_BLOCK_EDITOR_JS );

    parent::tearDown();
  }

  public function override_provider_map( $map ) {
    $map['openai'] = 'PWATG_Test_Provider';
    return $map;
  }

  public function test_generating_from_the_image_block_returns_and_saves_alt_text() {
    wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
    $attachment_id = $this->create_image();
    update_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, 'Written by a person' );

    $response = $this->request( $attachment_id );

    $this->assertSame( 200, $response->get_status() );
    $this->assertSame( 'Block alt text', $response->get_data()['alt_text'] );
    $this->assertTrue( $response->get_data()['has_previous'], 'The overwritten text can be restored from the Media Library.' );
    $this->assertSame( 'Block alt text', get_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, true ) );
  }

  public function test_authors_can_only_generate_for_their_own_images() {
    $attachment_id = $this->create_image();
    wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

    $this->assertSame( 403, $this->request( $attachment_id )->get_status() );

    wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );
    $this->assertSame( 403, $this->request( $attachment_id )->get_status() );
    $this->assertNull( PWATG_Test_Provider::$last_request );
  }

  public function test_provider_limits_come_back_as_429() {
    wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    PWATG_Test_Provider::$response = new WP_Error( 'pwatg_rate_limited', 'Slow down', [ 'provider' => 'openai' ] );

    $response = $this->request( $this->create_image() );

    $this->assertSame( 429, $response->get_status() );
    $this->assertSame( 'Slow down', $response->get_data()['message'] );
  }

  public function test_the_route_only_accepts_post() {
    wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    $attachment_id = $this->create_image();

    $response = $this->server->dispatch( new WP_REST_Request( 'GET', '/pwatg/v1/attachments/' . $attachment_id . '/alt-text' ) );

    $this->assertSame( 404, $response->get_status() );
    $this->assertNull( PWATG_Test_Provider::$last_request );
  }

  public function test_the_editor_script_loads_for_users_who_can_upload() {
    wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
    $this->plugin->enqueue_block_editor_assets();
    $this->assertTrue( wp_script_is( PWATG::ASSET_HANDLE_BLOCK_EDITOR_JS, 'enqueued' ) );

    wp_dequeue_script( PWATG::ASSET_HANDLE_BLOCK_EDITOR_JS );
    wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );
    $this->plugin->enqueue_block_editor_assets();
    $this->assertFalse( wp_script_is( PWATG::ASSET_HANDLE_BLOCK_EDITOR_JS, 'enqueued' ) );
  }

  protected function request( $attachment_id ) {
    $request = new WP_REST_Request( 'POST', '/pwatg/v1/attachments/' . $attachment_id . '/alt-text' );

    return $this->server->dispatch( $request );
  }

  protected function create_image() {
    $attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
    delete_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT );

    return $attachment_id;
  }
}
