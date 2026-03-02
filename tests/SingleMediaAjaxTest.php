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
  }

  protected function tearDown(): void {
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
    $this->assertSame( 'Missing API key. Add it in Alt Text Generator settings.', $response['data']['message'] );
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
