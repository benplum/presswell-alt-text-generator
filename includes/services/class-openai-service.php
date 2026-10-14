<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

if ( ! class_exists( 'PWATG_OpenAI_Service' ) ) {
  /**
   * Thin wrapper around OpenAI's chat completions API.
   */
  class PWATG_OpenAI_Service extends PWATG_Provider_Service {
    const PROVIDER = 'openai';

    /** Output cap for reasoning models, whose hidden reasoning tokens count toward it. */
    const REASONING_MAX_COMPLETION_TOKENS = 1024;

    /** Request alt text describing the supplied image. */
    public static function request_alt_text( $api_key, $model, $prompt, $mime_type, $image_binary ) {
      if ( '' === trim( (string) $api_key ) ) {
        return new WP_Error( 'pwatg_missing_api_key', __( 'Missing API key in Presswell Alt Text settings.', 'presswell-alt-text-generator' ) );
      }

      $body = [
        'model'    => $model,
        'messages' => [
          [
            'role'    => 'system',
            'content' => 'You write clear, concise alt text for accessibility.',
          ],
          [
            'role'    => 'user',
            'content' => [
              [
                'type' => 'text',
                'text' => $prompt,
              ],
              [
                'type'      => 'image_url',
                'image_url' => [
                  'url' => 'data:' . $mime_type . ';base64,' . base64_encode( $image_binary ),
                ],
              ],
            ],
          ],
        ],
      ];
      $body += self::token_limit( $model, 80 );

      $response = wp_remote_post(
        'https://api.openai.com/v1/chat/completions',
        [
          'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
          ],
          'body'    => wp_json_encode( $body ),
          'timeout' => self::ALT_TEXT_TIMEOUT,
        ]
      );

      if ( is_wp_error( $response ) ) {
        return $response;
      }

      $data = self::decode_or_error( $response );
      if ( is_wp_error( $data ) ) {
        return $data;
      }

      if ( isset( $data['choices'][0]['message']['content'] ) ) {
        $truncated = isset( $data['choices'][0]['finish_reason'] ) && 'length' === $data['choices'][0]['finish_reason'];

        return self::finalize_text( $data['choices'][0]['message']['content'], $truncated, $model );
      }

      return new WP_Error( 'pwatg_empty_alt', __( 'AI response did not include alt text.', 'presswell-alt-text-generator' ) );
    }

    /** Request a short text-only completion (used for testing credentials). */
    public static function request_text( $api_key, $model, $prompt ) {
      if ( '' === trim( (string) $api_key ) ) {
        return new WP_Error( 'pwatg_missing_api_key', __( 'Missing API key in Presswell Alt Text settings.', 'presswell-alt-text-generator' ) );
      }

      $body = [
        'model'    => $model,
        'messages' => [
          [
            'role'    => 'user',
            'content' => $prompt,
          ],
        ],
      ];
      $body += self::token_limit( $model, 30 );

      $response = wp_remote_post(
        'https://api.openai.com/v1/chat/completions',
        [
          'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
          ],
          'body'    => wp_json_encode( $body ),
          'timeout' => 30,
        ]
      );

      if ( is_wp_error( $response ) ) {
        return $response;
      }

      $data = self::decode_or_error( $response );
      if ( is_wp_error( $data ) ) {
        return $data;
      }

      if ( isset( $data['choices'][0]['message']['content'] ) ) {
        return trim( wp_strip_all_tags( (string) $data['choices'][0]['message']['content'] ) );
      }

      return new WP_Error( 'pwatg_connection_error', __( 'No response text returned by provider.', 'presswell-alt-text-generator' ) );
    }

    /**
     * The output cap, under the parameter name the model accepts.
     *
     * Reasoning models (o-series, GPT-5) reject max_tokens and count their hidden
     * reasoning toward the cap, so they get max_completion_tokens with room to think.
     *
     * @param string $model  Model ID.
     * @param int    $tokens Cap for the answer itself.
     *
     * @return array
     */
    private static function token_limit( $model, $tokens ) {
      if ( preg_match( '/^(o1|o3|o4|gpt-5)\b/i', (string) $model ) ) {
        return [ 'max_completion_tokens' => max( (int) $tokens, self::REASONING_MAX_COMPLETION_TOKENS ) ];
      }

      return [ 'max_tokens' => (int) $tokens ];
    }

    /** OpenAI reports an exhausted balance as a 429 with code insufficient_quota. */
    protected static function is_quota_error( $http_code, array $body, $message ) {
      $error_code = isset( $body['error']['code'] ) ? (string) $body['error']['code'] : '';
      $error_type = isset( $body['error']['type'] ) ? (string) $body['error']['type'] : '';

      return 'insufficient_quota' === $error_code || 'insufficient_quota' === $error_type;
    }
  }
}
