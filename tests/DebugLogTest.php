<?php
/**
 * Tests covering where the debug log is written, rotation, auto-off, and the admin viewer.
 */

class PWATG_Debug_Log_Die_Exception extends Exception {}

class DebugLogTest extends WP_UnitTestCase {
  /** @var Presswell_Alt_Text_Generator */
  protected $plugin;

  protected function setUp(): void {
    parent::setUp();

    if ( ! defined( 'DOING_AJAX' ) ) {
      define( 'DOING_AJAX', true );
    }

    $this->plugin = presswell_alt_text_generator();
    $this->save_debug_logging( 'on' );

    wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    $this->remove_log_dir();
    $_SERVER['REQUEST_METHOD'] = 'POST';
  }

  protected function tearDown(): void {
    $this->remove_log_dir();
    pwatg_test_delete_option( PWATG::SETTINGS_KEY );
    pwatg_test_delete_option( PWATG::OPTION_DEBUG_LOG_ENABLED_AT );
    pwatg_test_delete_option( PWATG::OPTION_DEBUG_LOG_EXPIRED );
    remove_all_filters( 'pwatg_debug_log_max_bytes' );
    unset( $_SERVER['REQUEST_METHOD'] );
    $_GET = [];
    $_POST = [];
    $_REQUEST = [];
    wp_set_current_user( 0 );

    parent::tearDown();
  }

  public function test_log_is_written_inside_uploads_with_an_unguessable_name() {
    $this->plugin->debug_log( 'hello' );

    $path = $this->plugin->get_debug_log_path();

    $this->assertStringStartsWith( trailingslashit( wp_upload_dir()['basedir'] ) . PWATG::DEBUG_LOG_DIR . '/', $path );
    $this->assertMatchesRegularExpression( '/\/debug-[A-Za-z0-9]{20}\.log$/', $path );
    $this->assertSame( $path, $this->plugin->get_debug_log_path(), 'The token is stable across calls.' );
    $this->assertStringContainsString( '[PWATG] hello', file_get_contents( $path ) );
  }

  public function test_log_directory_blocks_web_access() {
    $this->plugin->debug_log( 'hello' );

    $dir = $this->plugin->get_debug_log_dir();

    $this->assertFileExists( $dir . '/index.php' );
    $this->assertStringContainsString( 'Require all denied', file_get_contents( $dir . '/.htaccess' ) );
  }

  public function test_nothing_is_written_while_logging_is_off() {
    $this->save_debug_logging( 'off' );

    $this->plugin->debug_log( 'hello' );

    $this->assertFileDoesNotExist( $this->plugin->get_debug_log_path() );
  }

  public function test_legacy_public_log_is_removed_only_when_this_plugin_wrote_it() {
    $legacy = trailingslashit( WP_CONTENT_DIR ) . PWATG::DEBUG_LOG_LEGACY_FILENAME;

    file_put_contents( $legacy, "[2026-01-01 00:00:00 UTC] [PWAD] Art Direction entry\n" );
    $this->plugin->debug_log( 'hello' );
    $this->assertFileExists( $legacy, 'Art Direction used the same filename; its log is left alone.' );

    file_put_contents( $legacy, "[2026-01-01 00:00:00 UTC] [PWATG] old entry\n" );
    $this->plugin->debug_log( 'hello' );
    $this->assertFileDoesNotExist( $legacy );
  }

  public function test_log_rotates_when_it_reaches_the_size_cap() {
    add_filter( 'pwatg_debug_log_max_bytes', static function () {
      return 200;
    } );

    for ( $i = 0; $i < 10; $i++ ) {
      $this->plugin->debug_log( str_repeat( 'x', 40 ) . " entry {$i}" );
    }

    $path = $this->plugin->get_debug_log_path();

    $this->assertFileExists( $path . '.1', 'One previous log is kept.' );
    $this->assertLessThan( 400, filesize( $path ) );
    $this->assertStringContainsString( 'entry 9', file_get_contents( $path ) );
  }

  public function test_logging_turns_off_after_seven_days() {
    pwatg_test_update_option( PWATG::OPTION_DEBUG_LOG_ENABLED_AT, time() - PWATG::DEBUG_LOG_TTL - 60 );

    $this->plugin->maybe_expire_debug_logging();

    $this->assertSame( 'off', pwatg_test_get_option( PWATG::SETTINGS_KEY )['debug_logging'] );
    $this->assertSame( 'off', $this->plugin->get_settings()['debug_logging'] );
    $this->assertNotFalse( pwatg_test_get_option( PWATG::OPTION_DEBUG_LOG_EXPIRED ) );
    $this->assertFalse( pwatg_test_get_option( PWATG::OPTION_DEBUG_LOG_ENABLED_AT ) );
  }

  public function test_logging_stays_on_within_seven_days() {
    pwatg_test_update_option( PWATG::OPTION_DEBUG_LOG_ENABLED_AT, time() - DAY_IN_SECONDS );

    $this->plugin->maybe_expire_debug_logging();

    $this->assertSame( 'on', $this->plugin->get_settings()['debug_logging'] );
  }

  public function test_logging_already_on_before_upgrade_starts_the_clock() {
    pwatg_test_delete_option( PWATG::OPTION_DEBUG_LOG_ENABLED_AT );

    $this->plugin->maybe_expire_debug_logging();

    $this->assertSame( 'on', $this->plugin->get_settings()['debug_logging'] );
    $this->assertEqualsWithDelta( time(), (int) pwatg_test_get_option( PWATG::OPTION_DEBUG_LOG_ENABLED_AT ), 5 );
  }

  public function test_saving_settings_starts_and_clears_the_clock() {
    $this->save_debug_logging( 'off' );
    pwatg_test_update_option( PWATG::OPTION_DEBUG_LOG_EXPIRED, time() );

    $this->plugin->sanitize_settings( [ 'debug_logging' => 'on' ] );
    $this->assertEqualsWithDelta( time(), (int) pwatg_test_get_option( PWATG::OPTION_DEBUG_LOG_ENABLED_AT ), 5 );
    $this->assertFalse( pwatg_test_get_option( PWATG::OPTION_DEBUG_LOG_EXPIRED ), 'Turning it back on clears the expired notice.' );

    $this->plugin->sanitize_settings( [] );
    $this->assertFalse( pwatg_test_get_option( PWATG::OPTION_DEBUG_LOG_ENABLED_AT ) );
  }

  public function test_expired_notice_shows_once() {
    if ( is_multisite() ) {
      // In network mode only Super Admins can turn logging back on, so only they see it.
      grant_super_admin( get_current_user_id() );
    }

    pwatg_test_update_option( PWATG::OPTION_DEBUG_LOG_EXPIRED, time() );

    ob_start();
    $this->plugin->render_debug_log_expired_notice();
    $first = (string) ob_get_clean();

    ob_start();
    $this->plugin->render_debug_log_expired_notice();
    $second = (string) ob_get_clean();

    $this->assertStringContainsString( 'turned off automatically after 7 days', $first );
    $this->assertSame( '', trim( $second ) );
  }

  public function test_expired_notice_is_also_shown_in_network_admin() {
    $this->assertNotFalse( has_action( 'admin_notices', [ $this->plugin, 'render_debug_log_expired_notice' ] ) );
    $this->assertNotFalse( has_action( 'network_admin_notices', [ $this->plugin, 'render_debug_log_expired_notice' ] ) );
  }

  public function test_debug_tab_points_to_the_settings_tab_when_logging_is_off() {
    if ( is_multisite() ) {
      $this->markTestSkipped( 'Network mode wording is covered in MultisiteTest.' );
    }

    $this->plugin->enqueue_debug_log_assets();
    $data = wp_scripts()->get_data( PWATG::ASSET_HANDLE_DEBUG_LOG_JS, 'data' );
    wp_dequeue_script( PWATG::ASSET_HANDLE_DEBUG_LOG_JS );

    $this->assertStringContainsString( 'Turn it on in Settings.', $data );
    $this->assertStringContainsString( '"settingsUrl":"' . admin_url( PWATG::SETTINGS_PAGE_URL ) . '"', $data );
  }

  public function test_viewer_returns_the_tail_of_the_log() {
    for ( $i = 1; $i <= 5; $i++ ) {
      $this->plugin->debug_log( "line {$i}" );
    }

    $response = $this->ajax( 'ajax_debug_read_log' );

    $this->assertTrue( $response['success'] );
    $this->assertTrue( $response['data']['enabled'] );
    $this->assertStringContainsString( 'line 5', $response['data']['contents'] );

    $tail = $this->plugin->read_debug_log_tail( 2 );
    $this->assertSame( 2, count( explode( "\n", $tail['contents'] ) ) );
    $this->assertTrue( $tail['truncated'] );
  }

  public function test_viewer_can_clear_the_log() {
    $this->plugin->debug_log( 'hello' );

    $response = $this->ajax( 'ajax_debug_clear_log' );

    $this->assertTrue( $response['data']['cleared'] );
    $this->assertFileDoesNotExist( $this->plugin->get_debug_log_path() );
  }

  public function test_clearing_the_log_rejects_get_requests() {
    $this->plugin->debug_log( 'hello' );
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $response = $this->ajax( 'ajax_debug_clear_log' );

    $this->assertFalse( $response['success'] );
    $this->assertFileExists( $this->plugin->get_debug_log_path() );
  }

  /**
   * @dataProvider provider_viewer_handlers
   */
  public function test_viewer_is_limited_to_administrators( $handler ) {
    $this->plugin->debug_log( 'secret' );
    wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

    $response = $this->ajax( $handler );

    $this->assertFalse( $response['success'] );
    $this->assertFileExists( $this->plugin->get_debug_log_path() );
  }

  public function provider_viewer_handlers() {
    return [
      'read'  => [ 'ajax_debug_read_log' ],
      'clear' => [ 'ajax_debug_clear_log' ],
    ];
  }

  public function test_viewer_rejects_other_nonces() {
    $this->plugin->debug_log( 'hello' );

    $response = $this->ajax( 'ajax_debug_clear_log', PWATG::NONCE_GENERATE_BULK );

    $this->assertFalse( $response['success'] );
    $this->assertFileExists( $this->plugin->get_debug_log_path() );
  }

  public function test_download_is_limited_to_administrators() {
    $this->plugin->debug_log( 'secret' );
    wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
    $_POST    = [ '_wpnonce' => wp_create_nonce( PWATG::NONCE_DOWNLOAD_LOG ) ];
    $_REQUEST = $_POST;

    $message = $this->capture_die( function () {
      $this->plugin->handle_debug_log_download();
    } );

    $this->assertSame( 'You do not have permission to do that.', $message );
  }

  public function test_settings_field_links_to_the_viewer_not_the_file() {
    ob_start();
    $this->plugin->render_debug_logging_field();
    $html = (string) ob_get_clean();

    $this->assertStringContainsString( 'tab=debug', $html );
    $this->assertStringNotContainsString( '.log', $html );
  }

  protected function save_debug_logging( $value ) {
    $settings                  = pwatg_test_get_option( PWATG::SETTINGS_KEY );
    $settings                  = is_array( $settings ) ? $settings : [];
    $settings['debug_logging'] = $value;
    pwatg_test_update_option( PWATG::SETTINGS_KEY, $settings );
  }

  protected function ajax( $handler, $nonce_action = PWATG::NONCE_DEBUG ) {
    $_GET     = [ 'nonce' => wp_create_nonce( $nonce_action ) ];
    $_REQUEST = $_GET;

    $output = '';
    $this->capture_die( function () use ( $handler, &$output ) {
      ob_start();
      try {
        $this->plugin->{$handler}();
      } finally {
        $output = (string) ob_get_clean();
      }
    } );

    $decoded = json_decode( $output, true );
    $this->assertIsArray( $decoded );

    return $decoded;
  }

  protected function capture_die( callable $callback ) {
    $die_handler = static function () {
      return static function ( $message ) {
        throw new PWATG_Debug_Log_Die_Exception( wp_strip_all_tags( (string) $message ) );
      };
    };

    add_filter( 'wp_die_handler', $die_handler, 999 );
    add_filter( 'wp_die_ajax_handler', $die_handler, 999 );

    try {
      $callback();
      $this->fail( 'Expected the handler to end the request.' );
    } catch ( PWATG_Debug_Log_Die_Exception $e ) {
      return $e->getMessage();
    } finally {
      remove_filter( 'wp_die_handler', $die_handler, 999 );
      remove_filter( 'wp_die_ajax_handler', $die_handler, 999 );
    }
  }

  protected function remove_log_dir() {
    $dir = $this->plugin->get_debug_log_dir();

    if ( is_dir( $dir ) ) {
      foreach ( scandir( $dir ) as $file ) {
        if ( is_file( $dir . '/' . $file ) ) {
          unlink( $dir . '/' . $file );
        }
      }

      rmdir( $dir );
    }

    $legacy = trailingslashit( WP_CONTENT_DIR ) . PWATG::DEBUG_LOG_LEGACY_FILENAME;
    if ( file_exists( $legacy ) ) {
      unlink( $legacy );
    }
  }

  public function test_log_context_redacts_secrets_but_keeps_ids_and_counts() {
    $method = new ReflectionMethod( $this->plugin, 'sanitize_debug_log_value' );
    $method->setAccessible( true );

    $clean = $method->invoke( $this->plugin, [ 'api_key' => 'k', 'authToken' => 't', 'nested' => [ 'secret' => 's', 'api_token' => 'a' ], 'size_key' => 'hero', 'max_tokens' => 1024, 'tokens' => 12, 'author' => 'Sam' ] );

    $this->assertSame( '[redacted]', $clean['api_key'] );
    $this->assertSame( '[redacted]', $clean['authToken'] );
    $this->assertSame( '[redacted]', $clean['nested']['secret'] );
    $this->assertSame( '[redacted]', $clean['nested']['api_token'] );
    $this->assertSame( 'hero', $clean['size_key'] );
    $this->assertSame( 1024, $clean['max_tokens'] );
    $this->assertSame( 12, $clean['tokens'] );
    $this->assertSame( 'Sam', $clean['author'] );
  }

  public function test_log_context_redacts_hyphenated_header_names() {
    $method = new ReflectionMethod( $this->plugin, 'sanitize_debug_log_value' );
    $method->setAccessible( true );

    $clean = $method->invoke( $this->plugin, [ 'headers' => [ 'x-api-key' => 'k1', 'x-goog-api-key' => 'k2', 'X-Auth-Token' => 't', 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json' ] ] );

    $this->assertSame( '[redacted]', $clean['headers']['x-api-key'] );
    $this->assertSame( '[redacted]', $clean['headers']['x-goog-api-key'] );
    $this->assertSame( '[redacted]', $clean['headers']['X-Auth-Token'] );
    $this->assertSame( '2023-06-01', $clean['headers']['anthropic-version'] );
    $this->assertSame( 'application/json', $clean['headers']['content-type'] );
  }
}
