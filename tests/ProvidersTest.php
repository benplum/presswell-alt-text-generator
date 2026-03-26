<?php
/**
 * Tests covering the provider service integrations.
 */

class ProvidersTest extends WP_UnitTestCase {
  /** @var callable|null */
  protected $http_mock_callback = null;

  /** @var array|null */
  protected $captured_request = null;

  protected function tearDown(): void {
    $this->remove_http_mock();
    parent::tearDown();
  }

  public function test_openai_request_alt_text_parses_response() {
    $this->mock_http_response(
      'https://api.openai.com/v1/chat/completions',
      $this->build_http_response(
        200,
        [
          'choices' => [
            [
              'message' => [
                'content' => 'A cat sitting on a sofa.',
              ],
            ],
          ],
        ]
      )
    );

    $binary  = 'fake-image-binary';
    $result  = PWATG_OpenAI_Service::request_alt_text( 'sk-test', 'gpt-4.1-mini', 'Describe this image', 'image/png', $binary );

    $this->assertIsString( $result );
    $this->assertSame( 'A cat sitting on a sofa.', $result );
    $this->assertNotNull( $this->captured_request );
    $this->assertSame( 'Bearer sk-test', $this->captured_request['headers']['Authorization'] );

    $payload = json_decode( $this->captured_request['body'], true );
    $image_url = $payload['messages'][1]['content'][1]['image_url']['url'] ?? '';
    $this->assertStringStartsWith( 'data:image/png;base64,', $image_url );
    $encoded = substr( $image_url, strpos( $image_url, ',' ) + 1 );
    $this->assertSame( base64_encode( $binary ), $encoded );
  }

  public function test_openai_request_alt_text_handles_rate_limit_error() {
    $this->mock_http_response(
      'https://api.openai.com/v1/chat/completions',
      $this->build_http_response(
        429,
        [ 'error' => [ 'message' => 'Too many requests' ] ],
        [ 'retry-after' => '30' ]
      )
    );

    $result = PWATG_OpenAI_Service::request_alt_text( 'sk-test', 'gpt-4.1-mini', 'Prompt', 'image/png', 'bin' );
    $this->assertInstanceOf( WP_Error::class, $result );
    $this->assertSame( 'pwatg_rate_limited', $result->get_error_code() );
    $data = $result->get_error_data();
    $this->assertSame( 'openai', $data['provider'] );
    $this->assertSame( 30, $data['retry_after'] );
  }

  public function test_openai_request_text_returns_connection_error_when_content_is_missing() {
    $this->mock_http_response(
      'https://api.openai.com/v1/chat/completions',
      $this->build_http_response(
        200,
        [
          'choices' => [
            [
              'message' => [],
            ],
          ],
        ]
      )
    );

    $result = PWATG_OpenAI_Service::request_text( 'sk-test', 'gpt-4.1-mini', 'Prompt' );
    $this->assertInstanceOf( WP_Error::class, $result );
    $this->assertSame( 'pwatg_connection_error', $result->get_error_code() );
  }

  public function test_anthropic_request_text_requires_api_key() {
    $result = PWATG_Anthropic_Service::request_text( '', 'claude-3-5-haiku-latest', 'Prompt' );
    $this->assertInstanceOf( WP_Error::class, $result );
    $this->assertSame( 'pwatg_missing_api_key', $result->get_error_code() );
  }

  public function test_anthropic_request_alt_text_maps_retry_after_on_rate_limit() {
    $this->mock_http_response(
      'https://api.anthropic.com/v1/messages',
      $this->build_http_response(
        429,
        [ 'error' => [ 'message' => 'Rate limit reached' ] ],
        [ 'retry-after' => '12' ]
      )
    );

    $result = PWATG_Anthropic_Service::request_alt_text( 'ak-test', 'claude-3-5-haiku-latest', 'Describe this image', 'image/png', 'binary' );

    $this->assertInstanceOf( WP_Error::class, $result );
    $this->assertSame( 'pwatg_rate_limited', $result->get_error_code() );

    $data = $result->get_error_data();
    $this->assertSame( 'anthropic', $data['provider'] );
    $this->assertSame( 12, $data['retry_after'] );
  }

  public function test_gemini_request_alt_text_combines_text_parts() {
    $model      = 'gemini-1.5-flash';
    $api_key    = 'gm-key';
    $expected_url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $api_key );

    $this->mock_http_response(
      $expected_url,
      $this->build_http_response(
        200,
        [
          'candidates' => [
            [
              'content' => [
                'parts' => [
                  [ 'text' => 'Energetic dog' ],
                  [ 'text' => 'running through a field.' ],
                ],
              ],
            ],
          ],
        ]
      )
    );

    $result = PWATG_Gemini_Service::request_alt_text( $api_key, $model, 'Describe the photo', 'image/jpeg', 'binary-data' );
    $this->assertSame( 'Energetic dog running through a field.', $result );

    $payload = json_decode( $this->captured_request['body'], true );
    $inline_data = $payload['contents'][0]['parts'][1]['inlineData'] ?? [];
    $this->assertSame( 'image/jpeg', $inline_data['mimeType'] );
    $this->assertSame( base64_encode( 'binary-data' ), $inline_data['data'] );
  }

  public function test_gemini_request_text_maps_quota_errors() {
    $model   = 'gemini-2.0-flash';
    $api_key = 'gm-key';
    $url     = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $api_key );

    $this->mock_http_response(
      $url,
      $this->build_http_response(
        403,
        [ 'error' => [ 'message' => 'Quota exceeded' ] ]
      )
    );

    $result = PWATG_Gemini_Service::request_text( $api_key, $model, 'Say hello' );
    $this->assertInstanceOf( WP_Error::class, $result );
    $this->assertSame( 'pwatg_quota_exceeded', $result->get_error_code() );
    $data = $result->get_error_data();
    $this->assertSame( 'gemini', $data['provider'] );
  }

  /**
   * Register a mock HTTP response for wp_remote_post calls.
   */
  protected function mock_http_response( $expected_url, $response ) {
    $this->remove_http_mock();

    $this->http_mock_callback = function( $preempt, $args, $url ) use ( $expected_url, $response ) {
      if ( $url === $expected_url ) {
        $this->captured_request = $args;
        return $response;
      }

      return $preempt;
    };

    add_filter( 'pre_http_request', $this->http_mock_callback, 10, 3 );
  }

  /** Remove the registered HTTP mock filter between tests. */
  protected function remove_http_mock() {
    if ( $this->http_mock_callback ) {
      remove_filter( 'pre_http_request', $this->http_mock_callback, 10 );
      $this->http_mock_callback = null;
    }

    $this->captured_request = null;
  }

  /** Build a wp_remote_post-compatible response array. */
  protected function build_http_response( $code, array $body = [], array $headers = [] ) {
    return [
      'response' => [
        'code'    => $code,
        'message' => '',
      ],
      'body'     => wp_json_encode( $body ),
      'headers'  => $headers,
    ];
  }
}
