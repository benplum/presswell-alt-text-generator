<?php
/**
 * Tests covering network behavior. Run with WP_MULTISITE=1.
 */

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

    update_option(
      PWATG::SETTINGS_KEY,
      [
        'connector_source' => 'plugin',
        'service'          => 'openai',
        'model'            => 'gpt-4.1-mini',
        'prompt_seed'      => 'Main site prompt.',
        'api_keys'         => [ 'openai' => 'sk-main' ],
      ]
    );

    $this->subsite_id = self::factory()->blog->create();

    PWATG_Test_Provider::reset();
    add_filter( 'pwatg_provider_registry', [ $this, 'override_provider_map' ] );
  }

  protected function tearDown(): void {
    remove_filter( 'pwatg_provider_registry', [ $this, 'override_provider_map' ] );
    pwatg_test_delete_lock();
    $_GET = [];

    parent::tearDown();
  }

  public function override_provider_map( $map ) {
    $map['openai'] = 'PWATG_Test_Provider';
    return $map;
  }

  public function test_subsites_use_the_main_site_settings() {
    switch_to_blog( $this->subsite_id );
    $settings = $this->plugin->get_settings();
    restore_current_blog();

    $this->assertSame( 'Main site prompt.', $settings['prompt_seed'] );
    $this->assertSame( 'sk-main', $settings['api_keys']['openai'] );
  }

  public function test_subsite_settings_page_has_no_form_to_save() {
    switch_to_blog( $this->subsite_id );
    ob_start();
    $this->plugin->render_settings_page();
    $html = (string) ob_get_clean();
    restore_current_blog();

    $this->assertStringContainsString( 'uses the settings from the main site', $html );
    $this->assertStringNotContainsString( 'action="options.php"', $html );
  }

  public function test_saving_on_a_subsite_never_copies_the_main_site_keys() {
    switch_to_blog( $this->subsite_id );
    $sanitized = $this->plugin->sanitize_settings( [ 'service' => 'openai' ] );
    restore_current_blog();

    $this->assertSame( '', $sanitized['api_keys']['openai'] );
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

    $this->assertNotNull( $blocked, 'Every site shares the main site key.' );
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
