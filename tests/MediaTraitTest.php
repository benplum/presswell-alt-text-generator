<?php
class MediaTraitTest extends WP_UnitTestCase {
  /** @var Presswell_Alt_Text_Generator */
  protected $plugin;

  protected function setUp(): void {
    parent::setUp();
    $this->plugin = presswell_alt_text_generator();
    $this->seed_settings();
    PWATG_Test_Provider::reset();
    PWATG_Test_Provider::$response = 'Generated alt text from stub.';
    add_filter( 'pwatg_provider_registry', [ $this, 'override_provider_map' ] );
  }

  protected function tearDown(): void {
    remove_filter( 'pwatg_provider_registry', [ $this, 'override_provider_map' ] );
    parent::tearDown();
  }

  public function override_provider_map( $map ) {
    $map['openai'] = 'PWATG_Test_Provider';
    return $map;
  }

  public function test_generate_alt_text_updates_metadata() {
    $attachment_id = $this->create_image_attachment();

    $result = $this->plugin->generate_alt_text_for_attachment( $attachment_id, false );

    $this->assertTrue( $result );
    $this->assertSame( 'Generated alt text from stub.', get_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, true ) );
    $this->assertNotEmpty( get_post_meta( $attachment_id, PWATG::META_KEY_LAST_GENERATED, true ) );
    $this->assertNotNull( PWATG_Test_Provider::$last_request );
  }

  public function test_generate_alt_text_skips_when_alt_already_exists() {
    $attachment_id = $this->create_image_attachment();
    update_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, 'Existing alt text' );

    $result = $this->plugin->generate_alt_text_for_attachment( $attachment_id, false );

    $this->assertFalse( $result );
    $this->assertNull( PWATG_Test_Provider::$last_request );
  }

  public function test_generate_alt_text_requires_valid_image_attachment() {
    $post_id = self::factory()->post->create();
    $result  = $this->plugin->generate_alt_text_for_attachment( $post_id, false );

    $this->assertInstanceOf( WP_Error::class, $result );
    $this->assertSame( 'pwatg_invalid_attachment', $result->get_error_code() );
  }

  public function test_generate_alt_text_uses_wp_connector_api_key_fallback() {
    $attachment_id = $this->create_image_attachment();

    $settings = $this->plugin->get_settings();
    $settings['service'] = 'openai';
    $settings['connector_source'] = 'core';
    $settings['core_connector'] = 'openai';
    $settings['api_keys']['openai'] = '';
    $settings['api_key'] = '';
    update_option( PWATG::SETTINGS_KEY, $settings );

    update_option( 'connectors_ai_openai_api_key', 'connector-openai-key' );

    $result = $this->plugin->generate_alt_text_for_attachment( $attachment_id, false );

    $this->assertTrue( $result );
    $this->assertSame( 'connector-openai-key', PWATG_Test_Provider::$last_request['api_key'] );

    delete_option( 'connectors_ai_openai_api_key' );
  }

  public function test_generate_alt_text_prefers_plugin_key_when_source_is_plugin() {
    $attachment_id = $this->create_image_attachment();

    $settings = $this->plugin->get_settings();
    $settings['connector_source'] = 'plugin';
    $settings['core_connector'] = 'openai';
    $settings['service'] = 'openai';
    $settings['api_keys']['openai'] = 'plugin-openai-key';
    $settings['api_key'] = '';
    update_option( PWATG::SETTINGS_KEY, $settings );

    update_option( 'connectors_ai_openai_api_key', 'core-openai-key' );

    $result = $this->plugin->generate_alt_text_for_attachment( $attachment_id, false );

    $this->assertTrue( $result );
    $this->assertSame( 'plugin-openai-key', PWATG_Test_Provider::$last_request['api_key'] );

    delete_option( 'connectors_ai_openai_api_key' );
  }

  public function test_generate_alt_text_prefers_core_key_when_source_is_core() {
    $attachment_id = $this->create_image_attachment();

    $settings = $this->plugin->get_settings();
    $settings['connector_source'] = 'core';
    $settings['core_connector'] = 'openai';
    $settings['service'] = 'openai';
    $settings['api_keys']['openai'] = 'plugin-openai-key';
    $settings['api_key'] = '';
    update_option( PWATG::SETTINGS_KEY, $settings );

    update_option( 'connectors_ai_openai_api_key', 'core-openai-key' );

    $result = $this->plugin->generate_alt_text_for_attachment( $attachment_id, false );

    $this->assertTrue( $result );
    $this->assertSame( 'core-openai-key', PWATG_Test_Provider::$last_request['api_key'] );

    delete_option( 'connectors_ai_openai_api_key' );
  }

  public function test_auto_generate_runs_for_a_new_upload() {
    $this->enable_auto_generate();
    PWATG_Test_Provider::$response = 'Auto alt text';

    // create_image_attachment() inserts the attachment and generates its metadata, like an upload.
    $attachment_id = $this->create_image_attachment();

    $this->assertSame( 'Auto alt text', get_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, true ) );
  }

  public function test_regenerating_metadata_for_an_existing_image_does_not_call_the_provider() {
    $attachment_id = $this->create_image_attachment();
    $this->enable_auto_generate();
    PWATG_Test_Provider::reset();

    // What thumbnail-regeneration tools do.
    wp_generate_attachment_metadata( $attachment_id, get_attached_file( $attachment_id ) );

    $this->assertNull( PWATG_Test_Provider::$last_request );
    $this->assertSame( '', get_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, true ) );
  }

  public function test_upload_generation_can_be_turned_off_with_a_filter() {
    $this->enable_auto_generate();
    add_filter( 'pwatg_generate_on_upload', '__return_false' );

    $attachment_id = $this->create_image_attachment();

    remove_filter( 'pwatg_generate_on_upload', '__return_false' );

    $this->assertNull( PWATG_Test_Provider::$last_request );
    $this->assertSame( '', get_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, true ) );
  }

  protected function enable_auto_generate() {
    $settings = get_option( PWATG::SETTINGS_KEY );
    $settings['auto_generate'] = 'on';
    update_option( PWATG::SETTINGS_KEY, $settings );
  }

  public function test_media_alt_column_is_injected() {
    $columns = [ 'cb' => '<input type="checkbox" />', 'title' => 'Title' ];
    $filtered = $this->plugin->add_media_alt_column( $columns );

    $this->assertArrayHasKey( PWATG::MEDIA_COLUMN_ALT, $filtered );
    $this->assertSame( __( 'Alt Text', 'presswell-alt-text-generator' ), $filtered[ PWATG::MEDIA_COLUMN_ALT ] );
  }

  public function test_media_alt_column_renders_alt_text_preview() {
    $user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
    wp_set_current_user( $user_id );

    $attachment_id = $this->create_image_attachment();
    update_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, 'Preview alt text contents' );

    ob_start();
    $this->plugin->render_media_alt_column( PWATG::MEDIA_COLUMN_ALT, $attachment_id );
    $output = ob_get_clean();

    $this->assertStringContainsString( 'Preview alt text contents', $output );
    $this->assertStringNotContainsString( 'pwatg-generate-alt-action', $output, 'The action is a row action, not repeated in the column.' );
  }

  public function test_media_row_action_offers_generate_or_regenerate() {
    wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    $attachment_id = $this->create_image_attachment();
    delete_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT );

    $actions = $this->plugin->add_media_row_action( [], get_post( $attachment_id ) );
    $this->assertStringContainsString( 'Generate Alt Text', $actions[ PWATG::FIELD_GENERATE_SINGLE ] );

    update_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, 'Existing' );
    $actions = $this->plugin->add_media_row_action( [], get_post( $attachment_id ) );
    $this->assertStringContainsString( 'Regenerate Alt Text', $actions[ PWATG::FIELD_GENERATE_SINGLE ] );
  }

  public function test_media_alt_column_marks_missing_alt_text() {
    $user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
    wp_set_current_user( $user_id );

    $attachment_id = $this->create_image_attachment();
    delete_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT );

    ob_start();
    $this->plugin->render_media_alt_column( PWATG::MEDIA_COLUMN_ALT, $attachment_id );
    $output = ob_get_clean();

    $this->assertStringContainsString( 'pwatg-alt-preview is-empty', $output );
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

    $filename = wp_unique_filename( $uploads['path'], 'pwatg-test.png' );
    $filepath = trailingslashit( $uploads['path'] ) . $filename;
    file_put_contents( $filepath, base64_decode( $this->tiny_png_base64() ) );

    $filetype = wp_check_filetype( $filename, null );
    $attachment = [
      'post_mime_type' => $filetype['type'],
      'post_title'     => 'Test Image',
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
