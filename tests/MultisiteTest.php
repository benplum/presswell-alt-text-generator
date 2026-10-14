<?php
/**
 * Tests covering Multisite, network-activated ("network mode", the default in these
 * tests) and activated per site. Run with WP_MULTISITE=1.
 */

class PWATG_Network_Redirect_Exception extends Exception {}

class MultisiteTest extends WP_UnitTestCase {
  /** @var Presswell_Alt_Text_Generator */
  protected $plugin;

  /** @var int */
  protected $subsite_id;

  protected function setUp(): void {
    if ( ! is_multisite() ) {
      $this->markTestSkipped( 'Run the suite with WP_MULTISITE=1.' );
    }

    parent::setUp();

    $this->plugin = presswell_alt_text_generator();
    wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    grant_super_admin( get_current_user_id() );

    update_site_option(
      PWATG::SETTINGS_KEY,
      [
        'connector_source' => 'plugin',
        'service'          => 'openai',
        'model'            => 'gpt-4.1-mini',
        'prompt_seed'      => 'Network prompt.',
        'auto_generate'    => 'on',
        'api_keys'         => [ 'openai' => 'sk-network' ],
      ]
    );

    $this->subsite_id = self::factory()->blog->create();

    PWATG_Test_Provider::reset();
    add_filter( 'pwatg_provider_registry', [ $this, 'override_provider_map' ] );
    $this->unregister_plugin_settings();
  }

  protected function tearDown(): void {
    remove_filter( 'pwatg_provider_registry', [ $this, 'override_provider_map' ] );
    remove_all_filters( 'wp_redirect' );
    pwatg_test_delete_lock();
    delete_transient( $this->plugin->get_test_provider_notice_key() );
    $this->unregister_plugin_settings();
    unset( $_SERVER['REQUEST_METHOD'] );
    $_GET     = [];
    $_POST    = [];
    $_REQUEST = [];
    set_current_screen( 'front' );

    parent::tearDown();
  }

  /**
   * register_settings() fills a global that outlives each test; start clean.
   */
  private function unregister_plugin_settings() {
    unregister_setting( 'pwatg_settings_group', PWATG::SETTINGS_KEY );
    unregister_setting( PWATG::SITE_SETTINGS_GROUP, PWATG::SITE_SETTINGS_KEY );
  }

  /**
   * A site administrator who isn't a Super Admin.
   *
   * @return int User ID.
   */
  private function create_site_admin() {
    $user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
    add_user_to_blog( $this->subsite_id, $user_id, 'administrator' );

    return $user_id;
  }

  /**
   * Render a site's settings page tab.
   *
   * @param int    $site_id Site ID.
   * @param string $tab     'settings' or 'debug'.
   *
   * @return string
   */
  private function render_site_page( $site_id, $tab = 'settings' ) {
    $_GET['tab'] = $tab;
    switch_to_blog( $site_id );
    $this->plugin->register_settings();
    ob_start();
    $this->plugin->render_settings_page();
    $html = (string) ob_get_clean();
    restore_current_blog();
    unset( $_GET['tab'] );

    return $html;
  }

  /**
   * Run Test Connection; returns the redirect or throws WPDieException.
   *
   * @return array{location: string, status: int}|null
   */
  private function run_test_provider() {
    $redirect = null;
    $_POST    = [ 'pwatg_test_provider_nonce' => wp_create_nonce( PWATG::AJAX_TEST_PROVIDER ) ];
    $_REQUEST = $_POST;

    $capture = static function ( $location, $status ) use ( &$redirect ) {
      $redirect = [ 'location' => $location, 'status' => $status ];
      throw new PWATG_Network_Redirect_Exception( 'redirect' );
    };
    add_filter( 'wp_redirect', $capture, 10, 2 );
    $die = $this->throw_on_die();

    try {
      $_SERVER['REQUEST_METHOD'] = 'POST';
      $this->plugin->handle_test_provider();
    } catch ( PWATG_Network_Redirect_Exception $done ) {
      return $redirect;
    } finally {
      remove_filter( 'wp_redirect', $capture, 10 );
      $this->stop_throwing_on_die( $die );
    }

    return $redirect;
  }

  /**
   * Make wp_die() throw, whichever handler WordPress picks.
   *
   * @return callable The filter to pass to stop_throwing_on_die().
   */
  private function throw_on_die() {
    // Another test may have defined DOING_AJAX, which routes wp_die() to the AJAX handler.
    $die = static function () {
      return static function ( $message, $title = '', $args = [] ) {
        $code = is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : 0;
        throw new WPDieException( is_string( $message ) ? $message : 'wp_die', $code );
      };
    };
    add_filter( 'wp_die_handler', $die, 999 );
    add_filter( 'wp_die_ajax_handler', $die, 999 );

    return $die;
  }

  /**
   * @param callable $die Filter from throw_on_die().
   */
  private function stop_throwing_on_die( $die ) {
    remove_filter( 'wp_die_handler', $die, 999 );
    remove_filter( 'wp_die_ajax_handler', $die, 999 );
  }

  public function override_provider_map( $map ) {
    $map['openai'] = 'PWATG_Test_Provider';
    return $map;
  }

  private function settings_on( $site_id ) {
    switch_to_blog( $site_id );
    $settings = $this->plugin->get_settings();
    restore_current_blog();

    return $settings;
  }

  public function test_every_site_uses_the_network_settings() {
    foreach ( [ get_main_site_id(), $this->subsite_id ] as $site_id ) {
      $settings = $this->settings_on( $site_id );

      $this->assertSame( 'Network prompt.', $settings['prompt_seed'] );
      $this->assertSame( 'sk-network', $settings['api_keys']['openai'] );
    }
  }

  public function test_main_site_settings_move_to_the_network_once() {
    delete_site_option( PWATG::SETTINGS_KEY );
    update_blog_option( get_main_site_id(), PWATG::SETTINGS_KEY, [ 'prompt_seed' => 'Old main site prompt.', 'api_keys' => [ 'openai' => 'sk-old' ] ] );

    $this->plugin->maybe_migrate_network_settings();

    $this->assertSame( 'sk-old', get_site_option( PWATG::SETTINGS_KEY )['api_keys']['openai'] );
    $this->assertFalse( get_blog_option( get_main_site_id(), PWATG::SETTINGS_KEY ), 'No copy of the keys is left on the main site.' );

    // Once migrated, later main-site options are ignored.
    update_blog_option( get_main_site_id(), PWATG::SETTINGS_KEY, [ 'prompt_seed' => 'Stray.' ] );
    $this->plugin->maybe_migrate_network_settings();
    $this->assertSame( 'Old main site prompt.', get_site_option( PWATG::SETTINGS_KEY )['prompt_seed'] );
  }

  public function test_site_settings_page_links_to_the_network_and_never_shows_keys() {
    switch_to_blog( $this->subsite_id );
    $this->plugin->register_settings();
    ob_start();
    $this->plugin->render_settings_page();
    $html = (string) ob_get_clean();
    restore_current_blog();

    $this->assertStringContainsString( 'set for the whole network', $html );
    $this->assertStringContainsString( 'settings.php?page=' . PWATG::SETTINGS_PAGE_SLUG, $html );
    $this->assertStringContainsString( PWATG::SITE_SETTINGS_KEY . '[auto_generate]', $html );
    $this->assertStringNotContainsString( PWATG::SETTINGS_KEY . '[api_keys]', $html );
    $this->assertStringNotContainsString( 'sk-network', $html );
  }

  public function test_network_page_renders_the_provider_form() {
    $this->plugin->register_settings();
    ob_start();
    $this->plugin->render_network_settings_page();
    $html = (string) ob_get_clean();

    $this->assertStringContainsString( 'edit.php?action=' . PWATG::ACTION_SAVE_NETWORK_SETTINGS, $html );
    $this->assertStringContainsString( PWATG::SETTINGS_KEY . '[api_keys][openai]', $html );
    $this->assertStringNotContainsString( 'pwatg-test-connection-form', $html, 'Test Connection lives on each site\'s Debug tab.' );
    $this->assertStringNotContainsString( 'sk-network', $html, 'Saved keys never reach the page.' );

    if ( $this->plugin->has_active_core_connectors() ) {
      // The network uses plugin keys here, so the page that chooses the source warns.
      $this->assertMatchesRegularExpression( '/pwatg-plugin-source-deprecated"\s*>/', $html );
    }
  }

  public function test_a_site_can_override_generate_on_upload() {
    switch_to_blog( $this->subsite_id );
    update_option( PWATG::SITE_SETTINGS_KEY, [ 'auto_generate' => 'off' ] );
    $overridden = $this->plugin->get_settings()['auto_generate'];
    update_option( PWATG::SITE_SETTINGS_KEY, [ 'auto_generate' => 'default' ] );
    $inherited = $this->plugin->get_settings()['auto_generate'];
    restore_current_blog();

    $this->assertSame( '', $overridden );
    $this->assertSame( 'on', $inherited );
    $this->assertSame( 'on', $this->settings_on( get_main_site_id() )['auto_generate'], 'Other sites keep the network setting.' );
  }

  public function test_sites_cannot_save_a_copy_of_the_network_settings() {
    global $wp_registered_settings;

    $this->plugin->register_settings();

    $this->assertArrayNotHasKey( PWATG::SETTINGS_KEY, (array) $wp_registered_settings );
    $this->assertArrayHasKey( PWATG::SITE_SETTINGS_KEY, (array) $wp_registered_settings );
  }

  /** @var array{location: string, status: int}|null */
  private $network_redirect = null;

  private function save_network_settings( array $input, $method = 'POST' ) {
    $_SERVER['REQUEST_METHOD'] = $method;
    $_POST = [
      '_wpnonce'          => wp_create_nonce( PWATG::ACTION_SAVE_NETWORK_SETTINGS ),
      PWATG::SETTINGS_KEY => $input,
    ];
    $_REQUEST = $_POST;

    $this->network_redirect = null;
    add_filter(
      'wp_redirect',
      function ( $location, $status ) {
        $this->network_redirect = [ 'location' => $location, 'status' => $status ];
        throw new PWATG_Network_Redirect_Exception( 'redirect' );
      },
      10,
      2
    );

    try {
      $this->plugin->handle_save_network_settings();
    } catch ( PWATG_Network_Redirect_Exception $redirect ) {
      return true;
    }

    return false;
  }

  public function test_network_save_keeps_the_saved_key_and_remove_clears_it() {
    $this->assertTrue( $this->save_network_settings( [ 'service' => 'openai', 'model' => 'gpt-4.1-mini', 'prompt_seed' => 'Updated.', 'api_keys' => [ 'openai' => '' ] ] ) );
    $this->assertSame( 'sk-network', get_site_option( PWATG::SETTINGS_KEY )['api_keys']['openai'] );
    $this->assertSame( 'Updated.', get_site_option( PWATG::SETTINGS_KEY )['prompt_seed'] );

    $this->save_network_settings( [ 'service' => 'openai', 'model' => 'gpt-4.1-mini', 'api_keys' => [ 'openai' => '' ], 'api_keys_remove' => [ 'openai' => '1' ] ] );
    $this->assertSame( '', get_site_option( PWATG::SETTINGS_KEY )['api_keys']['openai'], 'Removing works; the value is sanitized once.' );
  }

  public function test_only_network_admins_can_save_network_settings() {
    wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    $die = $this->throw_on_die();

    try {
      $this->save_network_settings( [ 'prompt_seed' => 'Nope.' ] );
      $this->fail( 'A site admin saved network settings.' );
    } catch ( WPDieException $denied ) {
      $this->assertSame( 'Network prompt.', get_site_option( PWATG::SETTINGS_KEY )['prompt_seed'] );
    } finally {
      $this->stop_throwing_on_die( $die );
    }
  }

  public function test_network_save_refuses_get_requests() {
    $die = $this->throw_on_die();

    try {
      $this->save_network_settings( [ 'prompt_seed' => 'Via GET.' ], 'GET' );
      $this->fail( 'Network settings were saved from a GET request.' );
    } catch ( WPDieException $refused ) {
      $this->assertSame( 405, $refused->getCode() );
      $this->assertSame( 'Network prompt.', get_site_option( PWATG::SETTINGS_KEY )['prompt_seed'] );
    } finally {
      $this->stop_throwing_on_die( $die );
    }
  }

  public function test_network_save_redirects_with_303_and_a_one_time_notice() {
    $this->save_network_settings( [ 'service' => 'openai', 'model' => 'gpt-4.1-mini', 'prompt_seed' => 'Updated.' ] );

    $this->assertSame( 303, $this->network_redirect['status'] );
    $this->assertSame( $this->plugin->get_network_settings_url(), $this->network_redirect['location'], 'No ?updated=true in the URL.' );

    $notice = get_transient( $this->plugin->get_test_provider_notice_key() );
    $this->assertSame( 'success', $notice['type'] );
    $this->assertSame( 'Settings saved.', $notice['message'] );
  }

  public function test_the_network_page_shows_the_network_value_not_the_main_sites_override() {
    // The network turns Generate on Upload on; the main site has opted out.
    update_option( PWATG::SITE_SETTINGS_KEY, [ 'auto_generate' => 'off' ] );
    set_current_screen( PWATG::NETWORK_SETTINGS_SCREEN_ID );

    ob_start();
    $this->plugin->render_auto_generate_field();
    $html = (string) ob_get_clean();

    $this->assertTrue( is_network_admin() );
    $this->assertMatchesRegularExpression( '/\[auto_generate\]" value="on"\s+checked/', $html, 'Saving the network page keeps the network value.' );
    $this->assertSame( 'on', $this->plugin->get_settings()['auto_generate'] );

    set_current_screen( 'front' );
    $this->assertSame( '', $this->plugin->get_settings()['auto_generate'], 'The main site itself still uses its override.' );
  }

  public function test_settings_migrate_early_and_whenever_they_are_read() {
    $this->assertNotFalse( has_action( 'init', [ $this->plugin, 'maybe_migrate_network_settings' ] ), 'Runs for cron, REST, and WP-CLI too.' );

    delete_site_option( PWATG::SETTINGS_KEY );
    update_blog_option( get_main_site_id(), PWATG::SETTINGS_KEY, [ 'connector_source' => 'plugin', 'api_keys' => [ 'openai' => 'sk-legacy' ] ] );

    // A request that never reached init, like an early cron or CLI call.
    $this->assertSame( 'sk-legacy', $this->settings_on( $this->subsite_id )['api_keys']['openai'] );
    $this->assertSame( 'sk-legacy', get_site_option( PWATG::SETTINGS_KEY )['api_keys']['openai'] );
  }

  public function test_only_super_admins_can_test_the_connection_in_network_mode() {
    wp_set_current_user( $this->create_site_admin() );
    switch_to_blog( $this->subsite_id );

    try {
      $this->run_test_provider();
      $this->fail( 'A site admin spent the network\'s credits.' );
    } catch ( WPDieException $denied ) {
      $this->assertNull( PWATG_Test_Provider::$last_request );
    } finally {
      restore_current_blog();
    }

    $this->assertStringNotContainsString( 'pwatg-test-connection-form', $this->render_site_page( $this->subsite_id, 'debug' ), 'Site admins don\'t see the button.' );
  }

  public function test_super_admins_test_the_connection_from_a_sites_debug_tab() {
    $html = $this->render_site_page( $this->subsite_id, 'debug' );
    $this->assertStringContainsString( 'pwatg-test-connection-form', $html );

    switch_to_blog( $this->subsite_id );
    $redirect = $this->run_test_provider();
    $debug    = $this->plugin->get_debug_log_viewer_url();
    restore_current_blog();

    $this->assertSame( 'sk-network', PWATG_Test_Provider::$last_request['api_key'] );
    $this->assertSame( $debug, $redirect['location'], 'Back to the Debug tab of the site it ran from.' );
    $this->assertSame( 303, $redirect['status'] );
  }

  public function test_the_expired_logging_notice_is_for_super_admins_in_network_mode() {
    update_site_option( PWATG::OPTION_DEBUG_LOG_EXPIRED, time() );

    wp_set_current_user( $this->create_site_admin() );
    ob_start();
    $this->plugin->render_debug_log_expired_notice();
    $this->assertSame( '', trim( (string) ob_get_clean() ), 'A site admin can\'t turn it back on, so doesn\'t dismiss it.' );

    grant_super_admin( get_current_user_id() );
    set_current_screen( 'dashboard-network' );
    ob_start();
    $this->plugin->render_debug_log_expired_notice();
    $html = (string) ob_get_clean();

    $this->assertStringContainsString( 'Network Admin', $html );
    $this->assertFalse( get_site_option( PWATG::OPTION_DEBUG_LOG_EXPIRED ) );
  }

  public function test_debug_tab_wording_points_to_network_admin() {
    $this->plugin->enqueue_debug_log_assets();
    $super = (string) wp_scripts()->get_data( PWATG::ASSET_HANDLE_DEBUG_LOG_JS, 'data' );
    wp_dequeue_script( PWATG::ASSET_HANDLE_DEBUG_LOG_JS );
    wp_scripts()->registered[ PWATG::ASSET_HANDLE_DEBUG_LOG_JS ]->extra = [];

    wp_set_current_user( $this->create_site_admin() );
    $this->plugin->enqueue_debug_log_assets();
    $site = (string) wp_scripts()->get_data( PWATG::ASSET_HANDLE_DEBUG_LOG_JS, 'data' );
    wp_dequeue_script( PWATG::ASSET_HANDLE_DEBUG_LOG_JS );

    $this->assertStringContainsString( 'Turn it on in Network Admin', $super );
    $this->assertStringContainsString( '"settingsUrl":"' . $this->plugin->get_network_settings_url() . '"', $super );
    $this->assertStringContainsString( 'A network administrator can turn it on', $site );
    $this->assertStringContainsString( '"settingsUrl":""', $site );
    $this->assertStringNotContainsString( 'Turn it on in Settings.', $super . $site );
  }

  public function test_uninstall_removes_the_network_keys() {
    pwatg_test_run_uninstall();

    $network = get_site_option( PWATG::SETTINGS_KEY );
    $this->assertArrayNotHasKey( 'api_keys', $network, 'Keys always go.' );
    $this->assertSame( 'Network prompt.', $network['prompt_seed'], 'Other settings stay unless Remove Data is on.' );
  }

  public function test_uninstall_restores_the_settings_sanitizer_and_current_site() {
    $this->plugin->register_settings();
    switch_to_blog( $this->subsite_id );
    $this->plugin->register_settings();
    $before = has_filter( 'sanitize_option_' . PWATG::SITE_SETTINGS_KEY );

    pwatg_test_run_uninstall();

    $this->assertSame( $this->subsite_id, get_current_blog_id() );
    $this->assertSame( $before, has_filter( 'sanitize_option_' . PWATG::SITE_SETTINGS_KEY ) );
    restore_current_blog();
  }

  public function test_uninstall_with_network_remove_data_clears_every_site() {
    update_site_option( PWATG::SETTINGS_KEY, [ 'prompt_seed' => 'Network.', 'remove_data_on_uninstall' => 'on' ] );
    update_site_option( PWATG::OPTION_DEBUG_LOG_ENABLED_AT, time() );

    foreach ( [ get_main_site_id(), $this->subsite_id ] as $site_id ) {
      // Leftovers: a copy from per-site activation, an override, and a bulk run.
      update_blog_option( $site_id, PWATG::SETTINGS_KEY, [ 'prompt_seed' => 'Left over.', 'api_keys' => [ 'openai' => 'sk-site' ] ] );
      update_blog_option( $site_id, PWATG::SITE_SETTINGS_KEY, [ 'auto_generate' => 'off' ] );
      update_blog_option( $site_id, PWATG::OPTION_BULK_RUN, [ 'id' => 'run', 'owner' => 1, 'heartbeat' => time() ] );
    }

    pwatg_test_run_uninstall();

    $this->assertFalse( get_site_option( PWATG::SETTINGS_KEY ) );
    $this->assertFalse( get_site_option( PWATG::OPTION_DEBUG_LOG_ENABLED_AT ) );
    foreach ( [ get_main_site_id(), $this->subsite_id ] as $site_id ) {
      $this->assertFalse( get_blog_option( $site_id, PWATG::SETTINGS_KEY ), "Site {$site_id}'s copy is gone." );
      $this->assertFalse( get_blog_option( $site_id, PWATG::SITE_SETTINGS_KEY ) );
      $this->assertFalse( get_blog_option( $site_id, PWATG::OPTION_BULK_RUN ) );
    }
  }

  public function test_uninstall_strips_keys_from_per_site_copies_when_remove_data_is_off() {
    update_blog_option( $this->subsite_id, PWATG::SETTINGS_KEY, [ 'prompt_seed' => 'Site.', 'api_keys' => [ 'openai' => 'sk-site' ] ] );

    pwatg_test_run_uninstall();

    $site = get_blog_option( $this->subsite_id, PWATG::SETTINGS_KEY );
    $this->assertSame( 'Site.', $site['prompt_seed'] );
    $this->assertArrayNotHasKey( 'api_keys', $site );
  }

  // Activated on individual sites: each site behaves like a single-site install.

  public function test_per_site_activation_gives_each_site_its_own_settings() {
    pwatg_test_set_network_mode( false );
    update_blog_option( get_main_site_id(), PWATG::SETTINGS_KEY, [ 'connector_source' => 'plugin', 'prompt_seed' => 'Main prompt.', 'api_keys' => [ 'openai' => 'sk-main' ] ] );
    update_blog_option( $this->subsite_id, PWATG::SETTINGS_KEY, [ 'connector_source' => 'plugin', 'prompt_seed' => 'Sub prompt.', 'api_keys' => [ 'openai' => 'sk-sub' ] ] );

    $this->assertFalse( $this->plugin->is_network_mode() );
    $this->assertSame( 'sk-main', $this->settings_on( get_main_site_id() )['api_keys']['openai'] );
    $this->assertSame( 'Sub prompt.', $this->settings_on( $this->subsite_id )['prompt_seed'] );
    $this->assertSame( 'sk-sub', $this->settings_on( $this->subsite_id )['api_keys']['openai'], 'The network option is ignored.' );
  }

  public function test_per_site_activation_shows_the_full_settings_form_on_each_site() {
    global $wp_registered_settings;

    pwatg_test_set_network_mode( false );
    wp_set_current_user( $this->create_site_admin() );

    $html = $this->render_site_page( $this->subsite_id );

    $this->assertArrayHasKey( PWATG::SETTINGS_KEY, (array) $wp_registered_settings );
    $this->assertStringContainsString( PWATG::SETTINGS_KEY . '[api_keys][openai]', $html );
    $this->assertMatchesRegularExpression( '/name=[\'"]option_page[\'"] value=[\'"]pwatg_settings_group[\'"]/', $html );
    $this->assertStringNotContainsString( 'set for the whole network', $html );
  }

  public function test_per_site_activation_saves_to_the_sites_own_option() {
    pwatg_test_set_network_mode( false );
    switch_to_blog( $this->subsite_id );
    $this->plugin->register_settings();
    update_option( PWATG::SETTINGS_KEY, [ 'connector_source' => 'plugin', 'prompt_seed' => 'Saved on the site.', 'api_keys' => [ 'openai' => 'sk-sub' ] ] );
    $saved = get_option( PWATG::SETTINGS_KEY );
    restore_current_blog();

    $this->assertSame( 'Saved on the site.', $saved['prompt_seed'], 'Sanitized by the registered setting.' );
    $this->assertSame( 'Network prompt.', get_site_option( PWATG::SETTINGS_KEY )['prompt_seed'] );
  }

  public function test_per_site_activation_has_no_network_page_or_migration() {
    global $submenu;

    pwatg_test_set_network_mode( false );
    delete_site_option( PWATG::SETTINGS_KEY );
    update_blog_option( get_main_site_id(), PWATG::SETTINGS_KEY, [ 'prompt_seed' => 'Main site keeps this.' ] );

    $this->plugin->register_network_admin_page();
    $this->plugin->get_settings();
    $this->plugin->maybe_migrate_network_settings();

    ob_start();
    $this->plugin->render_network_settings_page();
    $this->assertSame( '', (string) ob_get_clean() );

    $this->assertEmpty( wp_list_filter( (array) ( $submenu['settings.php'] ?? [] ), [ 2 => PWATG::SETTINGS_PAGE_SLUG ] ) );
    $this->assertFalse( get_site_option( PWATG::SETTINGS_KEY ), 'Nothing is moved to the network.' );
    $this->assertSame( 'Main site keeps this.', get_blog_option( get_main_site_id(), PWATG::SETTINGS_KEY )['prompt_seed'] );
    $this->assertSame( [ 'Deactivate' ], $this->plugin->add_network_settings_action_link( [ 'Deactivate' ] ) );
  }

  public function test_per_site_activation_lets_site_admins_test_their_connection() {
    pwatg_test_set_network_mode( false );
    wp_set_current_user( $this->create_site_admin() );
    update_blog_option( $this->subsite_id, PWATG::SETTINGS_KEY, [ 'connector_source' => 'plugin', 'service' => 'openai', 'api_keys' => [ 'openai' => 'sk-sub' ] ] );

    $this->assertStringContainsString( 'pwatg-test-connection-form', $this->render_site_page( $this->subsite_id, 'debug' ) );

    switch_to_blog( $this->subsite_id );
    $this->run_test_provider();
    restore_current_blog();

    $this->assertSame( 'sk-sub', PWATG_Test_Provider::$last_request['api_key'] );
  }

  public function test_per_site_activation_ignores_the_generate_on_upload_override() {
    pwatg_test_set_network_mode( false );
    switch_to_blog( $this->subsite_id );
    update_option( PWATG::SETTINGS_KEY, [ 'auto_generate' => 'on' ] );
    update_option( PWATG::SITE_SETTINGS_KEY, [ 'auto_generate' => 'off' ] );
    $auto = $this->plugin->get_settings()['auto_generate'];
    restore_current_blog();

    $this->assertSame( 'on', $auto, 'The site\'s own setting decides.' );
  }

  public function test_a_provider_limit_applies_to_every_site() {
    $lock = new ReflectionMethod( $this->plugin, 'maybe_start_rate_limit_lock' );
    $lock->setAccessible( true );
    $lock->invoke( $this->plugin, new WP_Error( 'pwatg_rate_limited', 'Wait', [ 'provider' => 'openai' ] ) );

    switch_to_blog( $this->subsite_id );
    $state = new ReflectionMethod( $this->plugin, 'get_rate_limit_lock_state' );
    $state->setAccessible( true );
    $blocked = $state->invoke( $this->plugin );
    restore_current_blog();

    $this->assertNotNull( $blocked, 'Every site shares the network key.' );
  }

  public function test_network_run_stops_at_the_first_provider_limit() {
    PWATG_Test_Provider::$response = new WP_Error( 'pwatg_rate_limited', 'Slow down', [ 'provider' => 'openai' ] );

    $second_site = self::factory()->blog->create();
    foreach ( [ get_current_blog_id(), $this->subsite_id, $second_site ] as $site_id ) {
      switch_to_blog( $site_id );
      self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
      restore_current_blog();
    }

    $result = $this->plugin->run_network_bulk_generate_cli( [ 'sites' => implode( ',', [ get_current_blog_id(), $this->subsite_id, $second_site ] ) ] );

    $this->assertTrue( $result['halted'] );
    $this->assertCount( 1, $result['sites'], 'Later sites share the limited key, so they are not attempted.' );
  }
}
