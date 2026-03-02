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

  public function test_auto_generate_runs_on_upload_filter() {
    $attachment_id = $this->create_image_attachment();
    PWATG_Test_Provider::$response = 'Auto alt text';

    $settings = $this->plugin->get_settings();
    $settings['auto_generate'] = 'on';
    update_option( PWATG::SETTINGS_KEY, $settings );

    $this->plugin->maybe_generate_on_upload_from_metadata( [], $attachment_id );

    $this->assertSame( 'Auto alt text', get_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, true ) );
  }

  public function test_media_alt_column_is_injected() {
    $columns = [ 'cb' => '<input type="checkbox" />', 'title' => 'Title' ];
    $filtered = $this->plugin->add_media_alt_column( $columns );

    $this->assertArrayHasKey( PWATG::MEDIA_COLUMN_ALT, $filtered );
    $this->assertSame( __( 'Alt Text', PWATG::TEXT_DOMAIN ), $filtered[ PWATG::MEDIA_COLUMN_ALT ] );
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
    $this->assertStringContainsString( 'Regenerate', $output );
  }

  public function test_media_alt_column_renders_generate_link_when_empty() {
    $user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
    wp_set_current_user( $user_id );

    $attachment_id = $this->create_image_attachment();
    delete_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT );

    ob_start();
    $this->plugin->render_media_alt_column( PWATG::MEDIA_COLUMN_ALT, $attachment_id );
    $output = ob_get_clean();

    $this->assertStringContainsString( 'pwatg-generate-alt-action', $output );
    $this->assertStringContainsString( 'Generate Alt Text', $output );
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
