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
    $model      = 'gemini-2.5-flash';
    $api_key    = 'gm-key';
    $expected_url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent';

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
    $this->assertSame( $api_key, $this->captured_request['headers']['x-goog-api-key'], 'The key is sent as a header, not in the URL.' );
  }

  /**
   * @dataProvider provider_gemini_models
   */
  public function test_gemini_leaves_room_for_the_answer_after_thinking( $model, $expects_thinking_off ) {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent';
    $this->mock_http_response( $url, $this->build_http_response( 200, [ 'candidates' => [ [ 'content' => [ 'parts' => [ [ 'text' => 'OK' ] ] ] ] ] ] ) );

    PWATG_Gemini_Service::request_text( 'gm-key', $model, 'Reply with: OK' );

    $config = json_decode( $this->captured_request['body'], true )['generationConfig'];
    $this->assertGreaterThanOrEqual( 512, $config['maxOutputTokens'], 'Thinking tokens count toward the cap.' );
    $this->assertSame( $expects_thinking_off, isset( $config['thinkingConfig']['thinkingBudget'] ) && 0 === $config['thinkingConfig']['thinkingBudget'] );
  }

  public function provider_gemini_models() {
    return [
      'flash'      => [ 'gemini-2.5-flash', true ],
      'flash-lite' => [ 'gemini-2.5-flash-lite', true ],
      'pro'        => [ 'gemini-2.5-pro', false ],
    ];
  }

  /**
   * @dataProvider provider_error_responses
   */
  public function test_provider_errors_map_to_the_right_pause( $service, $status, $body, $expected_code, $expected_retry ) {
    $urls = [
      'openai'    => 'https://api.openai.com/v1/chat/completions',
      'anthropic' => 'https://api.anthropic.com/v1/messages',
      'gemini'    => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent',
    ];
    $classes = [
      'openai'    => 'PWATG_OpenAI_Service',
      'anthropic' => 'PWATG_Anthropic_Service',
      'gemini'    => 'PWATG_Gemini_Service',
    ];

    $this->mock_http_response( $urls[ $service ], $this->build_http_response( $status, $body ) );

    $result = call_user_func( [ $classes[ $service ], 'request_text' ], 'key', 'gemini' === $service ? 'gemini-2.5-flash' : 'model', 'Prompt' );

    $this->assertInstanceOf( WP_Error::class, $result );
    $this->assertSame( $expected_code, $result->get_error_code() );
    $data = $result->get_error_data();
    $this->assertSame( $service, $data['provider'] );
    $this->assertSame( $expected_retry, isset( $data['retry_after'] ) ? $data['retry_after'] : null );
  }

  public function provider_error_responses() {
    return [
      'openai rate limit'             => [ 'openai', 429, [ 'error' => [ 'message' => 'Rate limit reached', 'code' => 'rate_limit_exceeded' ] ], 'pwatg_rate_limited', null ],
      'openai out of credit'          => [ 'openai', 429, [ 'error' => [ 'message' => 'You exceeded your current quota', 'code' => 'insufficient_quota' ] ], 'pwatg_quota_exceeded', null ],
      'openai bad key'                => [ 'openai', 401, [ 'error' => [ 'message' => 'Incorrect API key' ] ], 'pwatg_api_error', null ],
      'openai forbidden region'       => [ 'openai', 403, [ 'error' => [ 'message' => 'Country not supported' ] ], 'pwatg_api_error', null ],
      'anthropic out of credit'       => [ 'anthropic', 400, [ 'error' => [ 'type' => 'invalid_request_error', 'message' => 'Your credit balance is too low to access the Anthropic API.' ] ], 'pwatg_quota_exceeded', null ],
      'anthropic overloaded'          => [ 'anthropic', 529, [ 'error' => [ 'type' => 'overloaded_error', 'message' => 'Overloaded' ] ], 'pwatg_rate_limited', 60 ],
      'anthropic permission'          => [ 'anthropic', 403, [ 'error' => [ 'type' => 'permission_error', 'message' => 'Not allowed' ] ], 'pwatg_api_error', null ],
      'anthropic unknown model'       => [ 'anthropic', 404, [ 'error' => [ 'type' => 'not_found_error', 'message' => 'model: claude-3-opus' ] ], 'pwatg_api_error', null ],
      'gemini rate limit'             => [ 'gemini', 429, [ 'error' => [ 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Resource has been exhausted (e.g. check rate limits).' ] ], 'pwatg_rate_limited', null ],
      'gemini quota'                  => [ 'gemini', 429, [ 'error' => [ 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'You exceeded your current quota, please check your plan.' ] ], 'pwatg_quota_exceeded', null ],
      'gemini overloaded'             => [ 'gemini', 503, [ 'error' => [ 'status' => 'UNAVAILABLE', 'message' => 'The model is overloaded.' ] ], 'pwatg_rate_limited', 60 ],
      'gemini permission'             => [ 'gemini', 403, [ 'error' => [ 'status' => 'PERMISSION_DENIED', 'message' => 'API key not valid.' ] ], 'pwatg_api_error', null ],
      'payment required'              => [ 'openai', 402, [ 'error' => [ 'message' => 'Payment required' ] ], 'pwatg_quota_exceeded', null ],
    ];
  }

  public function test_a_permission_error_does_not_pause_generation() {
    $plugin = presswell_alt_text_generator();
    $lock   = new ReflectionMethod( $plugin, 'maybe_start_rate_limit_lock' );
    $lock->setAccessible( true );

    $lock->invoke( $plugin, new WP_Error( 'pwatg_api_error', 'Not allowed', [ 'http_code' => 403, 'provider' => 'anthropic' ] ) );

    $this->assertFalse( pwatg_test_get_lock() );
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
