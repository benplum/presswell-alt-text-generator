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

  public function test_default_settings_structure() {
    $defaults = $this->plugin->get_default_settings();

    $this->assertArrayHasKey( 'service', $defaults );
    $this->assertArrayHasKey( 'model', $defaults );
    $this->assertArrayHasKey( 'prompt_seed', $defaults );
    $this->assertArrayHasKey( 'api_keys', $defaults );
    $this->assertArrayHasKey( 'auto_generate', $defaults );
    $this->assertArrayHasKey( 'debug_logging', $defaults );

    $this->assertSame( 'openai', $defaults['service'] );
    $this->assertSame( 'gpt-4.1-mini', $defaults['model'] );
    $this->assertIsArray( $defaults['api_keys'] );
    $this->assertArrayHasKey( 'openai', $defaults['api_keys'] );
    $this->assertSame( 'on', $defaults['auto_generate'] );
    $this->assertSame( 'off', $defaults['debug_logging'] );
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
    $this->assertSame( 'gpt-4o', $sanitized['model'], 'Invalid models should reset to the first allowed model for the resolved provider.' );
    $this->assertNotEmpty( $sanitized['prompt_seed'], 'Blank prompts should reset to the default seed.' );
    $this->assertSame( '', $sanitized['auto_generate'] );
    $this->assertSame( 'on', $sanitized['debug_logging'] );
    $this->assertSame( 'ok-123', $sanitized['api_keys']['openai'] );
    $this->assertSame( 'anth-456', $sanitized['api_keys']['anthropic'] );
  }
}
