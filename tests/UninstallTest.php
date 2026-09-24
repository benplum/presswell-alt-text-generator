<?php
/**
 * Tests covering data removal when the plugin is deleted.
 */

class UninstallTest extends WP_UnitTestCase {
  /** @var Presswell_Alt_Text_Generator */
  protected $plugin;

  protected function setUp(): void {
    parent::setUp();

    $this->plugin = presswell_alt_text_generator();
  }

  public function test_uninstall_removes_keys_but_keeps_settings_and_history_by_default() {
    $state = $this->seed_and_uninstall( '' );

    $this->assert_operational_data_removed( $state );

    $settings = get_option( PWATG::SETTINGS_KEY );
    $this->assertIsArray( $settings, 'Settings are kept.' );
    $this->assertSame( 'Keep this prompt.', $settings['prompt_seed'] );
    $this->assertArrayNotHasKey( 'api_keys', $settings, 'API keys never outlive the plugin.' );
    $this->assertArrayNotHasKey( 'api_key', $settings );
    $this->assertSame( '1700000000', get_post_meta( $state['attachment_id'], PWATG::META_KEY_LAST_GENERATED, true ) );
  }

  public function test_uninstall_removes_keys_even_when_the_settings_sanitizer_is_registered() {
    $this->plugin->register_settings();

    $this->seed_and_uninstall( '' );

    $this->assertArrayNotHasKey( 'api_keys', get_option( PWATG::SETTINGS_KEY ) );
  }

  public function test_uninstall_removes_settings_and_history_when_opted_in() {
    $state = $this->seed_and_uninstall( 'on' );

    $this->assert_operational_data_removed( $state );
    $this->assertFalse( get_option( PWATG::SETTINGS_KEY ) );
    $this->assertSame( '', get_post_meta( $state['attachment_id'], PWATG::META_KEY_LAST_GENERATED, true ) );
    $this->assertSame( '', get_post_meta( $state['attachment_id'], PWATG::META_KEY_PREVIOUS_ALT, true ) );
  }

  public function test_the_remove_data_choice_is_saved() {
    $this->assertSame( 'on', $this->plugin->sanitize_settings( [ 'remove_data_on_uninstall' => 'on' ] )['remove_data_on_uninstall'] );
    $this->assertSame( '', $this->plugin->sanitize_settings( [] )['remove_data_on_uninstall'] );
  }

  /**
   * Seed every kind of plugin data, then run uninstall.php.
   *
   * @param string $remove_data The remove_data_on_uninstall setting.
   *
   * @return array{attachment_id: int, log_dir: string, legacy_log: string}
   */
  protected function seed_and_uninstall( $remove_data ) {
    update_option(
      PWATG::SETTINGS_KEY,
      [
        'prompt_seed'              => 'Keep this prompt.',
        'api_keys'                 => [ 'openai' => 'sk-secret' ],
        'api_key'                  => 'sk-legacy',
        'remove_data_on_uninstall' => $remove_data,
      ]
    );

    foreach ( [ PWATG::OPTION_DEBUG_LOG_TOKEN, PWATG::OPTION_DEBUG_LOG_ENABLED_AT, PWATG::OPTION_DEBUG_LOG_EXPIRED ] as $option ) {
      update_option( $option, 'x' );
    }

    pwatg_test_set_lock( [ 'until' => time() + 300 ], 300 );
    set_transient( PWATG::TRANSIENT_NOTICE_TEST_PROVIDER . '_7', [ 'message' => 'ok' ], 60 );
    update_option( 'unrelated_option', 'keep' );

    $log_dir = $this->plugin->get_debug_log_dir();
    wp_mkdir_p( $log_dir );
    foreach ( [ 'debug-test.log', 'debug-test.log.1', 'index.php', '.htaccess' ] as $log_file ) {
      file_put_contents( $log_dir . '/' . $log_file, 'x' );
    }

    $legacy_log = trailingslashit( WP_CONTENT_DIR ) . PWATG::DEBUG_LOG_LEGACY_FILENAME;
    file_put_contents( $legacy_log, "[2026-01-01 00:00:00 UTC] [PWATG] old entry\n" );

    $attachment_id = self::factory()->attachment->create_object( 'pwatg-uninstall.jpg', 0, [ 'post_mime_type' => 'image/jpeg' ] );
    update_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, 'A red kite' );
    update_post_meta( $attachment_id, PWATG::META_KEY_LAST_GENERATED, '1700000000' );
    update_post_meta( $attachment_id, PWATG::META_KEY_PREVIOUS_ALT, 'Written by a person' );

    if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
      define( 'WP_UNINSTALL_PLUGIN', 'presswell-alt-text-generator/presswell-alt-text-generator.php' );
    }

    include dirname( __DIR__ ) . '/uninstall.php';

    // Uninstall deletes the per-user notice rows with SQL; read them fresh, as the next request would.
    wp_cache_flush();

    return [
      'attachment_id' => $attachment_id,
      'log_dir'       => $log_dir,
      'legacy_log'    => $legacy_log,
    ];
  }

  protected function assert_operational_data_removed( array $state ) {
    foreach ( [ PWATG::OPTION_DEBUG_LOG_TOKEN, PWATG::OPTION_DEBUG_LOG_ENABLED_AT, PWATG::OPTION_DEBUG_LOG_EXPIRED ] as $option ) {
      $this->assertFalse( get_option( $option ), $option . ' is removed.' );
    }

    $this->assertFalse( pwatg_test_get_lock() );
    $this->assertFalse( get_transient( PWATG::TRANSIENT_NOTICE_TEST_PROVIDER . '_7' ) );
    $this->assertDirectoryDoesNotExist( $state['log_dir'] );
    $this->assertFileDoesNotExist( $state['legacy_log'] );
    $this->assertSame( 'keep', get_option( 'unrelated_option' ) );
    $this->assertSame( 'A red kite', get_post_meta( $state['attachment_id'], PWATG::META_KEY_ALT_TEXT, true ), 'Alt text is never removed.' );
  }
}
