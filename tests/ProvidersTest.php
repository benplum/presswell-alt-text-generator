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
      'gemini per-minute quota'       => [ 'gemini', 429, self::gemini_quota_body( 'GenerateRequestsPerMinutePerProjectPerModel-FreeTier', '34s' ), 'pwatg_rate_limited', 34 ],
      'gemini quota without details'  => [ 'gemini', 429, [ 'error' => [ 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'You exceeded your current quota, please check your plan and billing details.' ] ], 'pwatg_rate_limited', null ],
      'gemini fractional retry delay' => [ 'gemini', 429, self::gemini_quota_body( 'GenerateContentInputTokensPerModelPerMinute-FreeTier', '1.2s' ), 'pwatg_rate_limited', 2 ],
      'gemini daily quota'            => [ 'gemini', 429, self::gemini_quota_body( 'GenerateRequestsPerDayPerProjectPerModel-FreeTier', '16s' ), 'pwatg_quota_exceeded', 16 ],
      'gemini daily quota metric'     => [ 'gemini', 429, self::gemini_quota_body( '', '', 'generativelanguage.googleapis.com/generate_requests_per_day' ), 'pwatg_quota_exceeded', null ],
      'gemini overloaded'             => [ 'gemini', 503, [ 'error' => [ 'status' => 'UNAVAILABLE', 'message' => 'The model is overloaded.' ] ], 'pwatg_rate_limited', 60 ],
      'gemini permission'             => [ 'gemini', 403, [ 'error' => [ 'status' => 'PERMISSION_DENIED', 'message' => 'API key not valid.' ] ], 'pwatg_api_error', null ],
      'payment required'              => [ 'openai', 402, [ 'error' => [ 'message' => 'Payment required' ] ], 'pwatg_quota_exceeded', null ],
    ];
  }

  /**
   * A Gemini 429 body as the API sends it: the same "quota" wording for every limit,
   * with the kind of limit only in error.details.
   */
  protected static function gemini_quota_body( $quota_id, $retry_delay, $quota_metric = 'generativelanguage.googleapis.com/generate_content_free_tier_requests' ) {
    $details = [
      [
        '@type'      => 'type.googleapis.com/google.rpc.QuotaFailure',
        'violations' => [ array_filter( [ 'quotaMetric' => $quota_metric, 'quotaId' => $quota_id ] ) ],
      ],
      [
        '@type' => 'type.googleapis.com/google.rpc.Help',
        'links' => [ [ 'description' => 'Learn more', 'url' => 'https://ai.google.dev/gemini-api/docs/rate-limits' ] ],
      ],
    ];

    if ( '' !== $retry_delay ) {
      $details[] = [ '@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => $retry_delay ];
    }

    return [
      'error' => [
        'code'    => 429,
        'status'  => 'RESOURCE_EXHAUSTED',
        'message' => 'You exceeded your current quota, please check your plan and billing details.',
        'details' => $details,
      ],
    ];
  }

  public function test_a_gemini_per_minute_limit_pauses_briefly_not_for_an_hour() {
    $plugin = presswell_alt_text_generator();
    $url    = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';
    $this->mock_http_response( $url, $this->build_http_response( 429, self::gemini_quota_body( 'GenerateRequestsPerMinutePerProjectPerModel-FreeTier', '34s' ) ) );

    $error    = PWATG_Gemini_Service::request_alt_text( 'gm-key', 'gemini-2.5-flash', 'Prompt', 'image/jpeg', 'bin' );
    $duration = new ReflectionMethod( $plugin, 'determine_rate_limit_duration' );
    $duration->setAccessible( true );

    $this->assertLessThan( PWATG::QUOTA_LOCK_SECONDS, $duration->invoke( $plugin, $error ) );
  }

  public function test_gemini_pro_gets_a_small_thinking_budget() {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent';
    $this->mock_http_response( $url, $this->build_http_response( 200, [ 'candidates' => [ [ 'content' => [ 'parts' => [ [ 'text' => 'OK' ] ] ] ] ] ] ) );

    PWATG_Gemini_Service::request_alt_text( 'gm-key', 'gemini-2.5-pro', 'Prompt', 'image/jpeg', 'bin' );

    $config = json_decode( $this->captured_request['body'], true )['generationConfig'];
    $this->assertSame( 128, $config['thinkingConfig']['thinkingBudget'], 'Pro cannot turn thinking off, so it is capped instead.' );
    $this->assertGreaterThan( 128, $config['maxOutputTokens'] );
  }

  /**
   * @dataProvider provider_openai_token_params
   */
  public function test_openai_reasoning_models_get_max_completion_tokens( $model, $expected_param ) {
    $this->mock_http_response( 'https://api.openai.com/v1/chat/completions', $this->build_http_response( 200, [ 'choices' => [ [ 'message' => [ 'content' => 'A cat.' ] ] ] ] ) );

    PWATG_OpenAI_Service::request_alt_text( 'sk-test', $model, 'Prompt', 'image/png', 'bin' );

    $payload = json_decode( $this->captured_request['body'], true );
    $other   = 'max_tokens' === $expected_param ? 'max_completion_tokens' : 'max_tokens';
    $this->assertArrayHasKey( $expected_param, $payload );
    $this->assertArrayNotHasKey( $other, $payload );
  }

  public function provider_openai_token_params() {
    return [
      'gpt-4.1-mini' => [ 'gpt-4.1-mini', 'max_tokens' ],
      'gpt-4o'       => [ 'gpt-4o', 'max_tokens' ],
      'o1'           => [ 'o1', 'max_completion_tokens' ],
      'o3-mini'      => [ 'o3-mini', 'max_completion_tokens' ],
      'o4-mini'      => [ 'o4-mini', 'max_completion_tokens' ],
      'gpt-5'        => [ 'gpt-5', 'max_completion_tokens' ],
      'gpt-5-mini'   => [ 'gpt-5-mini', 'max_completion_tokens' ],
      'gpt-5.1'      => [ 'gpt-5.1', 'max_completion_tokens' ],
    ];
  }

  /**
   * @dataProvider provider_truncated_responses
   */
  public function test_output_cut_off_at_the_token_limit_is_trimmed( $service, $body, $expected ) {
    $urls    = [
      'openai'    => 'https://api.openai.com/v1/chat/completions',
      'anthropic' => 'https://api.anthropic.com/v1/messages',
      'gemini'    => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent',
    ];
    $classes = [
      'openai'    => 'PWATG_OpenAI_Service',
      'anthropic' => 'PWATG_Anthropic_Service',
      'gemini'    => 'PWATG_Gemini_Service',
    ];

    $this->mock_http_response( $urls[ $service ], $this->build_http_response( 200, $body ) );

    $model = 'gemini' === $service ? 'gemini-2.5-flash' : 'model';
    $this->assertSame( $expected, call_user_func( [ $classes[ $service ], 'request_alt_text' ], 'key', $model, 'Prompt', 'image/png', 'bin' ) );
  }

  public function provider_truncated_responses() {
    $two_sentences = 'A red barn in a green field. Two horses graze beside a wooden fen';
    $one_sentence  = 'A red barn in a green field with two horses grazing beside a wooden fen';

    return [
      'openai to last sentence'     => [ 'openai', [ 'choices' => [ [ 'message' => [ 'content' => $two_sentences ], 'finish_reason' => 'length' ] ] ], 'A red barn in a green field.' ],
      'openai partial word dropped' => [ 'openai', [ 'choices' => [ [ 'message' => [ 'content' => $one_sentence ], 'finish_reason' => 'length' ] ] ], 'A red barn in a green field with two horses grazing beside a wooden' ],
      'openai complete untouched'   => [ 'openai', [ 'choices' => [ [ 'message' => [ 'content' => $one_sentence ], 'finish_reason' => 'stop' ] ] ], $one_sentence ],
      'anthropic max_tokens'        => [ 'anthropic', [ 'stop_reason' => 'max_tokens', 'content' => [ [ 'type' => 'text', 'text' => $two_sentences ] ] ], 'A red barn in a green field.' ],
      'anthropic end_turn'          => [ 'anthropic', [ 'stop_reason' => 'end_turn', 'content' => [ [ 'type' => 'text', 'text' => $one_sentence ] ] ], $one_sentence ],
      'gemini MAX_TOKENS'           => [ 'gemini', [ 'candidates' => [ [ 'finishReason' => 'MAX_TOKENS', 'content' => [ 'parts' => [ [ 'text' => $one_sentence ] ] ] ] ] ], 'A red barn in a green field with two horses grazing beside a wooden' ],
      'gemini STOP'                 => [ 'gemini', [ 'candidates' => [ [ 'finishReason' => 'STOP', 'content' => [ 'parts' => [ [ 'text' => $two_sentences ] ] ] ] ] ], $two_sentences ],
    ];
  }

  public function test_key_like_strings_are_masked_in_messages() {
    $masked = PWATG_Provider_Service::redact_secrets( 'Incorrect API key provided: sk-proj-abc1***************wxyz. You can find your API key at https://platform.openai.com/account/api-keys.' );

    $this->assertStringNotContainsString( 'sk-proj', $masked );
    $this->assertStringContainsString( 'https://platform.openai.com/account/api-keys', $masked );
    $this->assertStringNotContainsString( 'AIzaSyD1234567890', PWATG_Provider_Service::redact_secrets( 'API key AIzaSyD1234567890abcdef not valid.' ) );
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
