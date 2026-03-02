<?php
/**
 * Integration tests for plugin bootstrap wiring and admin integration.
 */

class CorePluginIntegrationTest extends WP_UnitTestCase {
  /** @var Presswell_Alt_Text_Generator */
  protected $plugin;

  protected function setUp(): void {
    parent::setUp();

    $this->plugin = presswell_alt_text_generator();
    wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    set_current_screen( 'dashboard' );

    $defaults = $this->plugin->get_default_settings();
    $defaults['api_keys']['openai'] = 'sk-test';
    update_option( PWATG::SETTINGS_KEY, $defaults );

    unset( $_GET['page'] );
    unset( $_GET['post'] );
  }

  protected function tearDown(): void {
    wp_dequeue_style( PWATG::ASSET_HANDLE_ADMIN_CSS );
    wp_dequeue_style( PWATG::ASSET_HANDLE_BULK_CSS );
    wp_dequeue_script( PWATG::ASSET_HANDLE_SETTINGS_JS );
    wp_dequeue_script( PWATG::ASSET_HANDLE_BULK_JS );
    wp_dequeue_script( PWATG::ASSET_HANDLE_MEDIA_JS );

    unset( $_GET['page'] );
    unset( $_GET['post'] );

    set_current_screen( 'front' );

    parent::tearDown();
  }

  public function test_plugin_singleton_helper_returns_shared_instance() {
    $this->assertSame( Presswell_Alt_Text_Generator::instance(), presswell_alt_text_generator() );
  }

  public function test_core_hooks_are_registered() {
    $this->assertNotFalse( has_action( 'admin_enqueue_scripts', [ $this->plugin, 'enqueue_admin_assets' ] ) );

    $this->assertNotFalse( has_action( 'admin_post_' . PWATG::AJAX_GENERATE_BULK, [ $this->plugin, 'handle_bulk_generation' ] ) );
    $this->assertNotFalse( has_action( 'wp_ajax_' . PWATG::AJAX_INIT_BULK, [ $this->plugin, 'handle_bulk_init_ajax' ] ) );
    $this->assertNotFalse( has_action( 'wp_ajax_' . PWATG::AJAX_GENERATE_BULK, [ $this->plugin, 'handle_bulk_generate_ajax' ] ) );
    $this->assertNotFalse( has_action( 'wp_ajax_' . PWATG::AJAX_SCAN_MISSING, [ $this->plugin, 'handle_bulk_scan_missing_ajax' ] ) );

    $this->assertNotFalse( has_filter( 'wp_generate_attachment_metadata', [ $this->plugin, 'maybe_generate_on_upload_from_metadata' ] ) );
    $this->assertNotFalse( has_action( 'admin_post_' . PWATG::AJAX_GENERATE_SINGLE, [ $this->plugin, 'handle_single_generation' ] ) );
    $this->assertNotFalse( has_action( 'wp_ajax_' . PWATG::AJAX_GENERATE_SINGLE, [ $this->plugin, 'handle_single_generation_ajax' ] ) );
    $this->assertNotFalse( has_filter( 'media_row_actions', [ $this->plugin, 'add_media_row_action' ] ) );
    $this->assertNotFalse( has_filter( 'attachment_fields_to_edit', [ $this->plugin, 'add_media_modal_action_field' ] ) );
    $this->assertNotFalse( has_filter( 'manage_upload_columns', [ $this->plugin, 'add_media_alt_column' ] ) );
    $this->assertNotFalse( has_action( 'manage_media_custom_column', [ $this->plugin, 'render_media_alt_column' ] ) );
    $this->assertNotFalse( has_action( 'admin_notices', [ $this->plugin, 'render_admin_notices' ] ) );

    $this->assertNotFalse( has_action( 'admin_menu', [ $this->plugin, 'register_admin_pages' ] ) );
    $this->assertNotFalse( has_filter( 'plugin_action_links_' . plugin_basename( Presswell_Alt_Text_Generator::PLUGIN_FILE ), [ $this->plugin, 'add_settings_action_link' ] ) );

    $this->assertNotFalse( has_action( 'admin_init', [ $this->plugin, 'register_settings' ] ) );
    $this->assertNotFalse( has_action( 'admin_post_' . PWATG::AJAX_TEST_PROVIDER, [ $this->plugin, 'handle_test_provider' ] ) );
  }

  public function test_add_settings_action_link_prepends_settings_url() {
    $links  = [ '<a href="plugins.php">Deactivate</a>' ];
    $result = $this->plugin->add_settings_action_link( $links );

    $this->assertNotEmpty( $result );
    $this->assertStringContainsString( 'Settings', $result[0] );
    $this->assertStringContainsString( admin_url( PWATG::SETTINGS_PAGE_URL ), $result[0] );
  }

  public function test_enqueue_assets_on_settings_page_registers_expected_handles_and_data() {
    $_GET['page'] = PWATG::SETTINGS_PAGE_SLUG;

    $this->plugin->enqueue_admin_assets( PWATG::SETTINGS_PAGE_SCREEN_ID );

    $this->assertTrue( wp_style_is( PWATG::ASSET_HANDLE_ADMIN_CSS, 'enqueued' ) );
    $this->assertTrue( wp_script_is( PWATG::ASSET_HANDLE_SETTINGS_JS, 'enqueued' ) );
    $this->assertTrue( wp_script_is( PWATG::ASSET_HANDLE_MEDIA_JS, 'enqueued' ) );

    $data = wp_scripts()->get_data( PWATG::ASSET_HANDLE_SETTINGS_JS, 'data' );
    $this->assertIsString( $data );
    $this->assertStringContainsString( PWATG::JS_OBJECT_SETTINGS, $data );
    $this->assertStringContainsString( 'gpt-4.1-mini', $data );
  }

  public function test_enqueue_assets_on_bulk_page_registers_expected_handles_and_data() {
    $_GET['page'] = PWATG::BULK_PAGE_SLUG;

    $this->plugin->enqueue_admin_assets( PWATG::BULK_PAGE_SCREEN_ID );

    $this->assertTrue( wp_style_is( PWATG::ASSET_HANDLE_ADMIN_CSS, 'enqueued' ) );
    $this->assertTrue( wp_style_is( PWATG::ASSET_HANDLE_BULK_CSS, 'enqueued' ) );
    $this->assertTrue( wp_script_is( PWATG::ASSET_HANDLE_BULK_JS, 'enqueued' ) );
    $this->assertTrue( wp_script_is( PWATG::ASSET_HANDLE_MEDIA_JS, 'enqueued' ) );

    $data = wp_scripts()->get_data( PWATG::ASSET_HANDLE_BULK_JS, 'data' );
    $this->assertIsString( $data );
    $this->assertStringContainsString( PWATG::JS_OBJECT_BULK, $data );
    $this->assertStringContainsString( PWATG::AJAX_INIT_BULK, $data );
  }
}
