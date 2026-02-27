<?php
/**
 * Tests for bulk workflows and rate limit handling.
 */

class BulkTraitTest extends WP_Ajax_UnitTestCase {
  /** @var Presswell_Alt_Text_Generator */
  protected $plugin;

  protected function setUp(): void {
    parent::setUp();
    $this->plugin = presswell_alt_text_generator();
    $this->seed_settings();
    PWATG_Test_Provider::reset();
    add_filter( 'pwatg_provider_registry', [ $this, 'override_provider_map' ] );
    $this->_setRole( 'administrator' );
  }

  protected function tearDown(): void {
    remove_filter( 'pwatg_provider_registry', [ $this, 'override_provider_map' ] );
    delete_transient( PWATG::RATE_LIMIT_TRANSIENT );
    parent::tearDown();
  }

  public function override_provider_map( $map ) {
    $map['openai'] = 'PWATG_Test_Provider';
    return $map;
  }

  public function test_maybe_start_rate_limit_lock_sets_transient() {
    $error = new WP_Error(
      'pwatg_rate_limited',
      'Slow down',
      [
        'retry_after' => 120,
        'provider'    => 'openai',
      ]
    );

    $this->invoke_protected_method( 'maybe_start_rate_limit_lock', [ $error ] );
    $state = $this->invoke_protected_method( 'get_rate_limit_lock_state' );

    $this->assertNotNull( $state );
    $this->assertSame( 'openai', $state['provider'] );
    $this->assertGreaterThan( 0, $state['remaining'] );
  }

  public function test_get_rate_limit_lock_state_clears_expired() {
    set_transient(
      PWATG::RATE_LIMIT_TRANSIENT,
      [
        'code'     => 'pwatg_rate_limited',
        'provider' => 'openai',
        'message'  => 'Old lock',
        'until'    => time() - 5,
      ],
      MINUTE_IN_SECONDS
    );

    $state = $this->invoke_protected_method( 'get_rate_limit_lock_state' );
    $this->assertNull( $state );
    $this->assertFalse( get_transient( PWATG::RATE_LIMIT_TRANSIENT ) );
  }

  public function test_bulk_init_ajax_returns_attachment_ids() {
    $attachment_id = $this->create_image_attachment();
    $_POST = [
      'nonce' => wp_create_nonce( PWATG::NONCE_GENERATE_BULK ),
    ];

    try {
      $this->_handleAjax( PWATG::AJAX_INIT_BULK );
    } catch ( WPAjaxDieContinueException $e ) {
      // Expected.
    }

    $response = json_decode( $this->_last_response, true );
    $this->assertTrue( $response['success'] );
    $this->assertSame( [ $attachment_id ], $response['data']['ids'] );
    $this->assertSame( 1, $response['data']['total'] );
  }

  public function test_bulk_init_ajax_run_test_limits_to_missing_subset() {
    $attachments = [];
    for ( $i = 0; $i < 6; $i++ ) {
      $attachments[] = $this->create_image_attachment();
    }

    update_post_meta( $attachments[0], PWATG::META_KEY_ALT_TEXT, 'existing alt' );

    $_POST = [
      'nonce'                => wp_create_nonce( PWATG::NONCE_GENERATE_BULK ),
      'run_test'             => 1,
      'regenerate_existing'  => 1,
    ];

    try {
      $this->_handleAjax( PWATG::AJAX_INIT_BULK );
    } catch ( WPAjaxDieContinueException $e ) {
      // Expected.
    }

    $response = json_decode( $this->_last_response, true );
    $this->assertTrue( $response['success'] );
    $this->assertSame( 5, $response['data']['total'] );
    $this->assertCount( 5, $response['data']['ids'] );
    $this->assertNotContains( $attachments[0], $response['data']['ids'] );
  }

  public function test_bulk_init_ajax_respects_rate_limit_lock() {
    $this->simulate_rate_limit_lock();
    $_POST = [
      'nonce' => wp_create_nonce( PWATG::NONCE_GENERATE_BULK ),
    ];

    try {
      $this->_handleAjax( PWATG::AJAX_INIT_BULK );
    } catch ( WPAjaxDieContinueException $e ) {
      // Expected.
    }

    $response = json_decode( $this->_last_response, true );
    $this->assertFalse( $response['success'] );
    $this->assertSame( 'pwatg_rate_limited', $response['data']['code'] );
  }

  public function test_bulk_generate_ajax_processes_batch() {
    $attachment_id = $this->create_image_attachment();
    $_POST = [
      'nonce'              => wp_create_nonce( PWATG::NONCE_GENERATE_BULK ),
      'ids'                => [ $attachment_id ],
      'offset'             => 0,
      'batch_size'         => 1,
      'regenerate_existing'=> 0,
    ];

    PWATG_Test_Provider::$response = 'Batch alt text';

    try {
      $this->_handleAjax( PWATG::AJAX_GENERATE_BULK );
    } catch ( WPAjaxDieContinueException $e ) {
      // Expected.
    }

    $response = json_decode( $this->_last_response, true );
    $this->assertTrue( $response['success'] );
    $this->assertSame( 1, $response['data']['processed'] );
    $this->assertSame( 1, $response['data']['updated'] );
    $this->assertSame( 0, $response['data']['failed'] );
    $this->assertSame( 'Batch alt text', get_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, true ) );
  }

  protected function simulate_rate_limit_lock() {
    $error = new WP_Error( 'pwatg_rate_limited', 'Wait', [ 'provider' => 'openai' ] );
    $this->invoke_protected_method( 'maybe_start_rate_limit_lock', [ $error ] );
  }

  protected function invoke_protected_method( $method, array $args = [] ) {
    $ref = new ReflectionClass( $this->plugin );
    $m   = $ref->getMethod( $method );
    $m->setAccessible( true );
    return $m->invokeArgs( $this->plugin, $args );
  }

  protected function seed_settings() {
    $defaults = $this->plugin->get_default_settings();
    $defaults['api_keys']['openai'] = 'sk-test';
    $defaults['auto_generate'] = 0;
    update_option( PWATG::SETTINGS_KEY, $defaults );
  }

  protected function create_image_attachment() {
    $uploads = wp_upload_dir();
    wp_mkdir_p( $uploads['path'] );

    $filename = wp_unique_filename( $uploads['path'], 'bulk-test.png' );
    $filepath = trailingslashit( $uploads['path'] ) . $filename;
    file_put_contents( $filepath, base64_decode( $this->tiny_png_base64() ) );

    $filetype = wp_check_filetype( $filename, null );
    $attachment = [
      'post_mime_type' => $filetype['type'],
      'post_title'     => 'Bulk Image',
      'post_content'   => '',
      'post_status'    => 'inherit',
    ];

    $attachment_id = wp_insert_attachment( $attachment, $filepath );
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $metadata = wp_generate_attachment_metadata( $attachment_id, $filepath );
    wp_update_attachment_metadata( $attachment_id, $metadata );

    delete_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT );

    return $attachment_id;
  }

  protected function tiny_png_base64() {
    return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=';
  }
}
