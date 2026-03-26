<?php
/**
 * Tests helper trait context sanitization behavior.
 */

class HelpersTraitTest extends WP_UnitTestCase {
  /** @var Presswell_Alt_Text_Generator */
  protected $plugin;

  protected function setUp(): void {
    parent::setUp();
    $this->plugin = presswell_alt_text_generator();
  }

  public function test_sanitize_debug_log_value_redacts_sensitive_keys_recursively() {
    $input = [
      'api_key' => 'sk-test-123',
      'nested' => [
        'authToken' => 'Bearer abc',
        'safe' => 'visible',
      ],
      'object_payload' => (object) [
        'secret' => 'hidden',
        'name' => 'visible',
      ],
      'binary_blob' => 'AAAA',
      'count' => 42,
    ];

    $result = $this->invoke_protected_method( 'sanitize_debug_log_value', [ $input ] );

    $this->assertSame( '[redacted]', $result['api_key'] );
    $this->assertSame( '[redacted]', $result['nested']['authToken'] );
    $this->assertSame( 'visible', $result['nested']['safe'] );
    $this->assertSame( '[redacted]', $result['object_payload']['secret'] );
    $this->assertSame( 'visible', $result['object_payload']['name'] );
    $this->assertSame( '[redacted]', $result['binary_blob'] );
    $this->assertSame( 42, $result['count'] );
  }

  public function test_sanitize_debug_log_value_truncates_and_sanitizes_strings() {
    $dirty = '<script>alert(1)</script>' . str_repeat( 'a', 400 );

    $result = $this->invoke_protected_method( 'sanitize_debug_log_value', [ $dirty ] );

    $this->assertIsString( $result );
    $this->assertStringNotContainsString( '<script>', $result );
    $this->assertStringEndsWith( '...', $result );
    $this->assertSame( 303, strlen( $result ) );
  }

  protected function invoke_protected_method( $method, array $args = [] ) {
    $ref = new ReflectionClass( $this->plugin );
    $m   = $ref->getMethod( $method );
    $m->setAccessible( true );

    return $m->invokeArgs( $this->plugin, $args );
  }
}
