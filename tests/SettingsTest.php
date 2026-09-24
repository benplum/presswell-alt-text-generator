<?php
/**
 * Tests covering the settings helpers.
 */

class SettingsTest extends WP_UnitTestCase {
  /** @var Presswell_Alt_Text_Generator */
  protected $plugin;

  public function setUp(): void {
    parent::setUp();
    $this->plugin = presswell_alt_text_generator();
    delete_option( PWATG::SETTINGS_KEY );
  }

  public function tearDown(): void {
    foreach ( [ 'anthropic', 'openai', 'google' ] as $connector ) {
      delete_option( 'connectors_ai_' . $connector . '_api_key' );
    }
    remove_all_filters( 'pwatg_available_models' );

    parent::tearDown();
  }

  public function test_default_settings_structure() {
    $defaults = $this->plugin->get_default_settings();

    $this->assertArrayHasKey( 'service', $defaults );
    $this->assertArrayHasKey( 'model', $defaults );
    $this->assertArrayHasKey( 'prompt_seed', $defaults );
    $this->assertArrayHasKey( 'api_keys', $defaults );
    $this->assertArrayHasKey( 'connector_source', $defaults );
    $this->assertArrayHasKey( 'core_connector', $defaults );
    $this->assertArrayHasKey( 'auto_generate', $defaults );
    $this->assertArrayHasKey( 'debug_logging', $defaults );

    $this->assertSame( 'openai', $defaults['service'] );
    $this->assertSame( 'gpt-4.1-mini', $defaults['model'] );
    $this->assertIsArray( $defaults['api_keys'] );
    $this->assertArrayHasKey( 'openai', $defaults['api_keys'] );
    $this->assertContains( $defaults['connector_source'], [ 'plugin', 'core' ] );
    $this->assertIsString( $defaults['core_connector'] );
    $this->assertSame( 'on', $defaults['auto_generate'] );
    $this->assertSame( 'off', $defaults['debug_logging'] );
  }

  public function test_core_connector_service_mapping_supports_google_to_gemini() {
    $this->assertSame( 'gemini', $this->plugin->get_core_service_for_connector( 'google' ) );
    $this->assertSame( 'openai', $this->plugin->get_core_service_for_connector( 'openai' ) );
    $this->assertSame( '', $this->plugin->get_core_service_for_connector( 'unknown-provider' ) );
  }

  public function test_get_settings_merges_saved_values_with_defaults() {
    update_option(
      PWATG::SETTINGS_KEY,
      [
        'service'  => 'anthropic',
        'api_keys' => [
          'anthropic' => 'abc123',
        ],
      ]
    );

    $settings = $this->plugin->get_settings();

    $this->assertSame( 'anthropic', $settings['service'] );
    $this->assertArrayHasKey( 'openai', $settings['api_keys'] );
    $this->assertSame( 'abc123', $settings['api_keys']['anthropic'] );
    $this->assertSame( '', $settings['api_keys']['openai'] );
  }

  public function test_sanitize_settings_enforces_allowed_values() {
    $input = [
      'service'       => 'invalid-service',
      'model'         => 'not-a-real-model',
      'prompt_seed'   => '   ',
      'auto_generate' => '',
      'debug_logging' => 'on',
      'api_keys'      => [
        'openai'    => 'ok-123',
        'anthropic' => 'anth-456',
      ],
    ];

    $sanitized = $this->plugin->sanitize_settings( $input );

    $this->assertSame( 'openai', $sanitized['service'], 'Invalid services should fall back to the default.' );
    $this->assertSame( 'gpt-4.1-mini', $sanitized['model'], "Invalid models reset to the provider's default." );
    $this->assertNotEmpty( $sanitized['prompt_seed'], 'Blank prompts should reset to the default seed.' );
    $this->assertSame( '', $sanitized['auto_generate'] );
    $this->assertSame( 'on', $sanitized['debug_logging'] );
    $this->assertSame( 'ok-123', $sanitized['api_keys']['openai'] );
    $this->assertSame( 'anth-456', $sanitized['api_keys']['anthropic'] );
  }

  public function test_defaults_use_plugin_keys_when_no_core_connector_has_a_key() {
    if ( ! function_exists( 'wp_get_connectors' ) ) {
      $this->markTestSkipped( 'Requires WordPress AI Connectors.' );
    }

    $this->assertTrue( $this->plugin->has_active_core_connectors(), 'WordPress registers its connectors even without keys.' );

    $settings = $this->plugin->get_settings();

    $this->assertSame( 'plugin', $settings['connector_source'] );
    $this->assertSame( 'openai', $settings['service'] );
    $this->assertSame( 'gpt-4.1-mini', $settings['model'] );
  }

  public function test_defaults_pick_the_core_connector_that_has_a_key() {
    if ( ! function_exists( 'wp_get_connectors' ) ) {
      $this->markTestSkipped( 'Requires WordPress AI Connectors.' );
    }

    update_option( 'connectors_ai_google_api_key', 'g-test' );

    $settings = $this->plugin->get_settings();

    $this->assertSame( 'core', $settings['connector_source'] );
    $this->assertSame( 'google', $settings['core_connector'], 'Anthropic is registered first but has no key.' );
    $this->assertSame( 'gemini', $settings['service'] );
    $this->assertSame( 'gemini-2.5-flash', $settings['model'] );

    $choices = $this->plugin->get_active_core_connector_choices();
    $this->assertTrue( $choices['google']['has_key'] );
    $this->assertFalse( $choices['anthropic']['has_key'] );
  }

  public function test_a_saved_connector_choice_is_kept() {
    if ( ! function_exists( 'wp_get_connectors' ) ) {
      $this->markTestSkipped( 'Requires WordPress AI Connectors.' );
    }

    update_option( 'connectors_ai_google_api_key', 'g-test' );
    update_option( PWATG::SETTINGS_KEY, [ 'connector_source' => 'core', 'core_connector' => 'openai', 'service' => 'openai', 'model' => 'gpt-4o' ] );

    $settings = $this->plugin->get_settings();

    $this->assertSame( 'openai', $settings['core_connector'] );
    $this->assertSame( 'gpt-4o', $settings['model'] );
  }

  /**
   * @dataProvider provider_retired_models
   */
  public function test_retired_saved_models_fall_back_to_the_provider_default( $service, $retired, $expected ) {
    update_option( PWATG::SETTINGS_KEY, [ 'connector_source' => 'plugin', 'service' => $service, 'model' => $retired ] );

    $this->assertSame( $expected, $this->plugin->get_settings()['model'] );
  }

  public function provider_retired_models() {
    return [
      'claude 3 opus'    => [ 'anthropic', 'claude-3-opus', 'claude-haiku-4-5-20251001' ],
      'claude 3 haiku'   => [ 'anthropic', 'claude-3-haiku', 'claude-haiku-4-5-20251001' ],
      'gemini 1.5 flash' => [ 'gemini', 'gemini-1.5-flash', 'gemini-2.5-flash' ],
      'gpt-4 turbo'      => [ 'openai', 'gpt-4-turbo', 'gpt-4.1-mini' ],
    ];
  }

  public function test_model_lists_contain_no_retired_models() {
    $all = array_merge(
      array_keys( $this->plugin->get_available_models( 'openai' ) ),
      array_keys( $this->plugin->get_available_models( 'anthropic' ) ),
      array_keys( $this->plugin->get_available_models( 'gemini' ) )
    );

    $this->assertSame( [], preg_grep( '/^(claude-3|gemini-1\.|gpt-4-turbo)/', $all ) );
  }

  public function test_models_filter_receives_the_provider() {
    $seen = [];
    add_filter(
      'pwatg_available_models',
      static function ( $models, $service ) use ( &$seen ) {
        $seen[] = $service;

        return 'anthropic' === $service ? [ 'claude-custom' => 'Custom' ] : $models;
      },
      10,
      2
    );

    $this->assertSame( [ 'claude-custom' => 'Custom' ], $this->plugin->get_available_models( 'anthropic' ) );
    $this->assertArrayHasKey( 'gpt-4.1-mini', $this->plugin->get_available_models( 'openai' ) );
    $this->assertSame( [ 'anthropic', 'openai' ], $seen );
  }

  public function test_debug_logging_saved_as_off_stays_off() {
    update_option( PWATG::SETTINGS_KEY, [ 'debug_logging' => 'off' ] );

    $this->assertSame( 'off', $this->plugin->get_settings()['debug_logging'] );
    $this->assertSame( 'off', $this->plugin->sanitize_settings( [ 'debug_logging' => 'off' ] )['debug_logging'] );
    $this->assertSame( 'on', $this->plugin->sanitize_settings( [ 'debug_logging' => 'on' ] )['debug_logging'] );
  }
}
