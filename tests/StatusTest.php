<?php
/**
 * Tests for the Debug tab status table.
 */
class StatusTest extends WP_UnitTestCase {
  /** @var Presswell_Alt_Text_Generator */
  protected $plugin;

  protected function setUp(): void {
    parent::setUp();
    $this->plugin = presswell_alt_text_generator();
    wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    if ( is_multisite() ) {
      grant_super_admin( get_current_user_id() );
    }

    // The singleton caches the count for the request; each test starts uncounted.
    $this->reset_missing_alt_count_cache();

    // The rate-limit lock is per provider, so pin the provider the lock tests use.
    pwatg_test_update_option( PWATG::SETTINGS_KEY, array_merge( $this->plugin->get_default_settings(), [ 'connector_source' => 'plugin' ] ) );
  }

  protected function tearDown(): void {
    pwatg_test_delete_option( PWATG::SETTINGS_KEY );
    delete_option( PWATG::OPTION_BULK_RUN );
    pwatg_test_delete_lock();
    $this->reset_missing_alt_count_cache();
    unset( $_GET['tab'] );
    parent::tearDown();
  }

  private function reset_missing_alt_count_cache() {
    $property = new ReflectionProperty( $this->plugin, 'missing_alt_count_cache' );
    $property->setAccessible( true );
    $property->setValue( $this->plugin, null );
  }

  private function rows() {
    $rows = [];
    foreach ( $this->plugin->get_status_rows() as $row ) {
      $rows[ $row['label'] ] = $row;
    }

    return $rows;
  }

  public function test_rows_describe_provider_key_and_counts_without_revealing_the_key() {
    pwatg_test_update_option( PWATG::SETTINGS_KEY, array_merge( $this->plugin->get_default_settings(), [ 'connector_source' => 'plugin', 'api_keys' => [ 'openai' => 'sk-secret-value' ] ] ) );
    self::factory()->attachment->create( [ 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ] );

    $rows = $this->rows();

    $this->assertStringContainsString( 'OpenAI', $rows['Provider']['value'] );
    $this->assertSame( 'Plugin settings', $rows['API Key']['value'] );
    $this->assertSame( 'ok', $rows['API Key']['status'] );
    $this->assertSame( 'None', $rows['Rate Limit']['value'] );
    $this->assertSame( 'Not running', $rows['Bulk Generation']['value'] );
    $this->assertSame( 'warning', $rows['Images Missing Alt Text']['status'] );
    $this->assertStringNotContainsString( 'sk-secret-value', wp_json_encode( $rows ) );
  }

  public function test_missing_key_is_a_warning() {
    pwatg_test_update_option( PWATG::SETTINGS_KEY, array_merge( $this->plugin->get_default_settings(), [ 'connector_source' => 'plugin', 'api_keys' => [ 'openai' => '' ] ] ) );

    $this->assertSame( 'warning', $this->rows()['API Key']['status'] );
  }

  public function test_rate_limit_and_bulk_runs_are_reported() {
    $method = new ReflectionMethod( $this->plugin, 'maybe_start_rate_limit_lock' );
    $method->setAccessible( true );
    $method->invoke( $this->plugin, new WP_Error( 'pwatg_rate_limited', 'Slow down', [ 'provider' => 'openai' ] ) );
    update_option( PWATG::OPTION_BULK_RUN, [ 'id' => 'run-1', 'owner' => 1, 'heartbeat' => time() ] );

    $rows = $this->rows();

    $this->assertSame( 'warning', $rows['Rate Limit']['status'] );
    $this->assertStringStartsWith( 'Running', $rows['Bulk Generation']['value'] );

    update_option( PWATG::OPTION_BULK_RUN, [ 'id' => 'run-1', 'owner' => 1, 'heartbeat' => time() - HOUR_IN_SECONDS ] );
    $this->assertSame( 'warning', $this->rows()['Bulk Generation']['status'] );
  }

  public function test_last_generated_shows_the_newest() {
    $older = self::factory()->attachment->create( [ 'post_title' => 'Older', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ] );
    $newer = self::factory()->attachment->create( [ 'post_title' => 'Newer', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ] );
    update_post_meta( $older, PWATG::META_KEY_LAST_GENERATED, (string) ( time() - DAY_IN_SECONDS ) );
    update_post_meta( $newer, PWATG::META_KEY_LAST_GENERATED, (string) time() );

    $this->assertStringStartsWith( 'Newer', $this->rows()['Last Generated']['value'] );
  }

  private function render_tab( $tab ) {
    $_GET['tab'] = $tab;
    $this->plugin->register_settings();
    ob_start();
    $this->plugin->render_settings_page();

    return (string) ob_get_clean();
  }

  public function test_debug_tab_renders_the_table() {
    $html = $this->render_tab( 'debug' );

    $this->assertStringContainsString( 'pwatg-status-table', $html );
    $this->assertStringContainsString( 'Images Missing Alt Text', $html );
  }

  public function test_test_connection_sits_between_the_status_heading_and_the_table() {
    $html = $this->render_tab( 'debug' );

    $heading = strpos( $html, '>Status</h2>' );
    $form    = strpos( $html, 'id="pwatg-test-connection-form"' );
    $table   = strpos( $html, 'pwatg-status-table' );

    $this->assertNotFalse( $heading );
    $this->assertNotFalse( $form );
    $this->assertTrue( $heading < $form && $form < $table, 'Status heading, then Test Connection, then the table.' );
    $this->assertStringContainsString( 'name="pwatg_test_provider_nonce"', $html, 'The form keeps its own nonce.' );
    $this->assertMatchesRegularExpression( '/<form method="post"[^>]*id="pwatg-test-connection-form"/', $html );
  }

  public function test_the_settings_tab_no_longer_has_test_connection() {
    $this->assertStringNotContainsString( 'pwatg-test-connection-form', $this->render_tab( 'settings' ) );
  }

  public function test_filtered_rows_without_a_string_label_and_value_are_dropped() {
    $filter = static function ( $rows ) {
      $rows[] = 'not a row';
      $rows[] = [ 'label' => 'No value' ];
      $rows[] = [ 'label' => [ 'array' ], 'value' => 'x' ];
      $rows[] = [ 'label' => 'Custom', 'value' => 'Fine', 'status' => 'bogus' ];

      return $rows;
    };
    add_filter( 'pwatg_status_rows', $filter );

    $rows = $this->rows();

    $this->assertArrayHasKey( 'Custom', $rows );
    $this->assertSame( '', $rows['Custom']['status'], 'Unknown statuses are cleared.' );
    $this->assertArrayNotHasKey( 'No value', $rows );
    foreach ( $this->plugin->get_status_rows() as $row ) {
      $this->assertIsString( $row['label'] );
      $this->assertIsString( $row['value'] );
    }
  }

  public function test_a_filter_that_returns_a_non_array_keeps_the_default_rows() {
    add_filter( 'pwatg_status_rows', '__return_null' );

    $this->assertArrayHasKey( 'Provider', $this->rows() );
  }
}
