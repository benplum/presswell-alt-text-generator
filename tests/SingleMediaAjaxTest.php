<?php
/**
 * Tests for the single-image AJAX generation handler.
 */
class SingleMediaAjaxTest extends WP_Ajax_UnitTestCase {
  /** @var Presswell_Alt_Text_Generator */
  protected $plugin;

  protected function setUp(): void {
    parent::setUp();
    $this->plugin = presswell_alt_text_generator();
    $this->seed_settings();
    PWATG_Test_Provider::reset();
    PWATG_Test_Provider::$response = 'Inline alt text stub.';
    add_filter( 'pwatg_provider_registry', [ $this, 'override_provider_map' ] );
    $this->_setRole( 'administrator' );
    $_SERVER['REQUEST_METHOD'] = 'POST';
  }

  protected function tearDown(): void {
    unset( $_SERVER['REQUEST_METHOD'] );
    remove_filter( 'pwatg_provider_registry', [ $this, 'override_provider_map' ] );
    parent::tearDown();
  }

  public function override_provider_map( $map ) {
    $map['openai'] = 'PWATG_Test_Provider';
    return $map;
  }

  public function test_ajax_handler_generates_alt_text_and_returns_payload() {
    $attachment_id = $this->create_image_attachment();
    delete_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT );
    $nonce = wp_create_nonce( PWATG::NONCE_GENERATE_SINGLE . $attachment_id );

    $_POST = [
      'attachment_id' => $attachment_id,
      'nonce'         => $nonce,
    ];

    try {
      $this->_handleAjax( PWATG::AJAX_GENERATE_SINGLE );
    } catch ( WPAjaxDieContinueException $e ) {
      // Expected WordPress ajax termination.
    } catch ( WPAjaxDieStopException $e ) {
      // Expected WordPress ajax termination.
    }

    $response = json_decode( $this->_last_response, true );

    $this->assertTrue( $response['success'] );
    $this->assertSame( 'updated', $response['data']['status'] );
    $this->assertSame( 'Inline alt text stub.', $response['data']['alt_text'] );
    $this->assertSame( 'Inline alt text stub.', get_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, true ) );
    $this->assertNotEmpty( $response['data']['last_generated'] );
  }

  public function test_regenerating_keeps_the_overwritten_alt_text_for_restore() {
    $attachment_id = $this->create_image_attachment();
    update_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, 'Written by a person' );

    $response = $this->generate( $attachment_id );

    $this->assertTrue( $response['data']['has_previous'] );
    $this->assertSame( 'Written by a person', get_post_meta( $attachment_id, PWATG::META_KEY_PREVIOUS_ALT, true ) );

    $response = $this->restore( $attachment_id );

    $this->assertTrue( $response['success'] );
    $this->assertSame( 'Written by a person', $response['data']['alt_text'] );
    $this->assertSame( 'Written by a person', get_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, true ) );
    $this->assertSame( '', get_post_meta( $attachment_id, PWATG::META_KEY_PREVIOUS_ALT, true ), 'Restoring is one step.' );
  }

  public function test_generating_for_an_image_without_alt_text_has_nothing_to_restore() {
    $attachment_id = $this->create_image_attachment();
    delete_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT );

    $response = $this->generate( $attachment_id );

    $this->assertFalse( $response['data']['has_previous'] );
    $this->assertFalse( $this->restore( $attachment_id )['success'] );
  }

  public function test_restore_requires_permission_a_post_and_its_own_nonce() {
    $attachment_id = $this->create_image_attachment();
    update_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, 'AI text' );
    update_post_meta( $attachment_id, PWATG::META_KEY_PREVIOUS_ALT, 'Written by a person' );

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $this->assertFalse( $this->restore( $attachment_id )['success'], 'GET is rejected.' );
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $this->assertNull( $this->restore( $attachment_id, PWATG::NONCE_GENERATE_SINGLE . $attachment_id ), 'The generate nonce is not accepted.' );

    $this->_setRole( 'contributor' );
    $this->assertFalse( $this->restore( $attachment_id )['success'] );

    $this->assertSame( 'AI text', get_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, true ) );
  }

  protected function generate( $attachment_id ) {
    $_POST = [
      'attachment_id' => $attachment_id,
      'nonce'         => wp_create_nonce( PWATG::NONCE_GENERATE_SINGLE . $attachment_id ),
    ];

    return $this->call( PWATG::AJAX_GENERATE_SINGLE );
  }

  protected function restore( $attachment_id, $nonce_action = null ) {
    $_POST = [
      'attachment_id' => $attachment_id,
      'nonce'         => wp_create_nonce( null === $nonce_action ? PWATG::NONCE_RESTORE_ALT . $attachment_id : $nonce_action ),
    ];

    return $this->call( PWATG::AJAX_RESTORE_ALT );
  }

  protected function call( $action ) {
    $this->_last_response = '';

    try {
      $this->_handleAjax( $action );
    } catch ( WPAjaxDieContinueException $e ) {
      // Expected WordPress ajax termination.
    } catch ( WPAjaxDieStopException $e ) {
      // Expected WordPress ajax termination; check_ajax_referer() failures end up here.
    }

    return json_decode( $this->_last_response, true );
  }

  public function test_ajax_handler_rejects_get_requests() {
    $attachment_id = $this->create_image_attachment();
    delete_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT );
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = [
      'attachment_id' => $attachment_id,
      'nonce'         => wp_create_nonce( PWATG::NONCE_GENERATE_SINGLE . $attachment_id ),
    ];

    try {
      $this->_handleAjax( PWATG::AJAX_GENERATE_SINGLE );
    } catch ( WPAjaxDieContinueException $e ) {
      // Expected WordPress ajax termination.
    } catch ( WPAjaxDieStopException $e ) {
      // Expected WordPress ajax termination.
    }

    $response = json_decode( $this->_last_response, true );

    $this->assertFalse( $response['success'] );
    $this->assertNull( PWATG_Test_Provider::$last_request );
  }

  public function test_ajax_handler_reports_missing_api_key_error() {
    $attachment_id = $this->create_image_attachment();
    $settings = $this->plugin->get_settings();
    $settings['api_keys']['openai'] = '';
    update_option( PWATG::SETTINGS_KEY, $settings );
    $nonce = wp_create_nonce( PWATG::NONCE_GENERATE_SINGLE . $attachment_id );

    $_POST = [
      'attachment_id' => $attachment_id,
      'nonce'         => $nonce,
    ];

    try {
      $this->_handleAjax( PWATG::AJAX_GENERATE_SINGLE );
    } catch ( WPAjaxDieContinueException $e ) {
      // Expected WordPress ajax termination.
    } catch ( WPAjaxDieStopException $e ) {
      // Expected WordPress ajax termination.
    }

    $response = json_decode( $this->_last_response, true );

    $this->assertFalse( $response['success'] );
    $this->assertSame( 'missing_key', $response['data']['status'] );
    $this->assertSame( 'Missing API key. Add it in Alt Text Generator settings or WordPress AI Connectors.', $response['data']['message'] );
  }

  public function test_ajax_handler_requires_permissions() {
    $attachment_id = $this->create_image_attachment();
    $nonce         = wp_create_nonce( PWATG::NONCE_GENERATE_SINGLE . $attachment_id );

    $this->_setRole( 'subscriber' );

    $_POST = [
      'attachment_id' => $attachment_id,
      'nonce'         => $nonce,
    ];

    try {
      $this->_handleAjax( PWATG::AJAX_GENERATE_SINGLE );
    } catch ( WPAjaxDieContinueException $e ) {
      // Expected WordPress ajax termination.
    } catch ( WPAjaxDieStopException $e ) {
      // Expected WordPress ajax termination.
    }

    $response = json_decode( $this->_last_response, true );

    $this->assertFalse( $response['success'] );
    $this->assertSame( 'You do not have permission to do that.', $response['data']['message'] );
  }

  protected function seed_settings() {
    $defaults = $this->plugin->get_default_settings();
    $defaults['api_keys']['openai'] = 'sk-test';
    $defaults['api_keys']['anthropic'] = '';
    $defaults['api_keys']['gemini'] = '';
    $defaults['auto_generate'] = '';
    update_option( PWATG::SETTINGS_KEY, $defaults );
  }

  protected function create_image_attachment() {
    $uploads = wp_upload_dir();
    wp_mkdir_p( $uploads['path'] );

    $filename = wp_unique_filename( $uploads['path'], 'single-ajax-test.png' );
    $filepath = trailingslashit( $uploads['path'] ) . $filename;
    file_put_contents( $filepath, base64_decode( $this->tiny_png_base64() ) );

    $filetype   = wp_check_filetype( $filename, null );
    $attachment = [
      'post_mime_type' => $filetype['type'],
      'post_title'     => 'Single Ajax Image',
      'post_content'   => '',
      'post_status'    => 'inherit',
    ];

    $attachment_id = wp_insert_attachment( $attachment, $filepath );
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $metadata = wp_generate_attachment_metadata( $attachment_id, $filepath );
    wp_update_attachment_metadata( $attachment_id, $metadata );

    return $attachment_id;
  }

  protected function tiny_png_base64() {
    return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=';
  }
}
