<?php
/**
 * Tests covering which image file is sent to the provider, and in what form.
 */

class ImagePreparationTest extends WP_UnitTestCase {
  /** @var Presswell_Alt_Text_Generator */
  protected $plugin;

  protected function setUp(): void {
    parent::setUp();

    $this->plugin = presswell_alt_text_generator();

    $settings = $this->plugin->get_default_settings();
    $settings['api_keys']['openai'] = 'sk-test';
    $settings['connector_source']   = 'plugin';
    $settings['auto_generate']      = '';
    update_option( PWATG::SETTINGS_KEY, $settings );

    PWATG_Test_Provider::reset();
    add_filter( 'pwatg_provider_registry', [ $this, 'override_provider_map' ] );
  }

  protected function tearDown(): void {
    remove_filter( 'pwatg_provider_registry', [ $this, 'override_provider_map' ] );
    remove_all_filters( 'locale' );
    remove_all_filters( 'pwatg_alt_text_language' );
    parent::tearDown();
  }

  public function override_provider_map( $map ) {
    $map['openai'] = 'PWATG_Test_Provider';
    return $map;
  }

  public function test_a_large_photo_is_sent_at_web_size_not_as_the_original() {
    $attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image-large.jpg' );

    $image = $this->prepare( $attachment_id );

    $this->assertSame( 'image/jpeg', $image['mime_type'] );
    $this->assertMatchesRegularExpression( '/-1024x768\.jpg$/', $image['file'], 'The smallest file at least 1024px on its long edge.' );
    $this->assertLessThan( filesize( get_attached_file( $attachment_id ) ), strlen( $image['binary'] ) );
  }

  public function test_the_target_size_is_filterable() {
    $attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image-large.jpg' );
    add_filter( 'pwatg_image_max_dimension', static function () {
      return 300;
    } );

    $image = $this->prepare( $attachment_id );

    remove_all_filters( 'pwatg_image_max_dimension' );

    $this->assertMatchesRegularExpression( '/-300x225\.jpg$/', $image['file'] );
  }

  public function test_a_small_image_is_sent_as_the_original() {
    $attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

    $image = $this->prepare( $attachment_id );

    $this->assertSame( get_attached_file( $attachment_id ), $image['file'], '640px is below the target, so the largest file is used.' );
  }

  public function test_sizes_that_are_not_on_disk_are_ignored() {
    $attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image-large.jpg' );
    $meta          = wp_get_attachment_metadata( $attachment_id );
    $large         = trailingslashit( dirname( get_attached_file( $attachment_id ) ) ) . $meta['sizes']['large']['file'];
    unlink( $large );

    $image = $this->prepare( $attachment_id );

    $this->assertFileExists( $image['file'] );
    $this->assertNotSame( $large, $image['file'] );
  }

  /**
   * @dataProvider provider_convertible_formats
   */
  public function test_formats_some_providers_reject_are_sent_as_jpeg( $fixture ) {
    if ( ! wp_image_editor_supports( [ 'mime_type' => wp_check_filetype( $fixture )['type'] ] ) ) {
      $this->markTestSkipped( 'This server cannot read ' . $fixture . '.' );
    }

    $attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/' . $fixture );

    $image = $this->prepare( $attachment_id );

    $this->assertSame( 'image/jpeg', $image['mime_type'] );
    $this->assertSame( 'image/jpeg', getimagesizefromstring( $image['binary'] )['mime'] );
  }

  public function provider_convertible_formats() {
    return [
      'avif' => [ 'avif-lossy.avif' ],
      'gif'  => [ 'test-image-2.gif' ],
    ];
  }

  public function test_the_prompt_asks_for_the_site_language() {
    add_filter( 'locale', static function () {
      return 'de_DE';
    } );
    $attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

    $this->assertTrue( $this->plugin->generate_alt_text_for_attachment( $attachment_id, true ) );

    $this->assertStringContainsString( 'Write the alt text in German (Germany).', PWATG_Test_Provider::$last_request['prompt'] );
  }

  public function test_the_language_instruction_can_be_removed() {
    add_filter( 'pwatg_alt_text_language', '__return_empty_string' );
    $attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

    $this->plugin->generate_alt_text_for_attachment( $attachment_id, true );

    $this->assertStringNotContainsString( 'Write the alt text in', PWATG_Test_Provider::$last_request['prompt'] );
  }

  public function test_long_alt_text_is_cut_at_a_word_boundary() {
    PWATG_Test_Provider::$response = str_repeat( 'bright red kite ', 20 );
    $attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

    $this->plugin->generate_alt_text_for_attachment( $attachment_id, true );

    $alt = get_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, true );
    $this->assertLessThanOrEqual( 220, mb_strlen( $alt ) );
    $this->assertMatchesRegularExpression( '/(bright|red|kite)$/', $alt, 'No half words.' );
  }

  protected function prepare( $attachment_id ) {
    $method = new ReflectionMethod( $this->plugin, 'prepare_image_for_request' );
    $method->setAccessible( true );

    $image = $method->invoke( $this->plugin, $attachment_id );
    $this->assertIsArray( $image, is_wp_error( $image ) ? $image->get_error_message() : '' );

    return $image;
  }
}
