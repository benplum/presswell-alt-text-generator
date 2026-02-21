<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

if ( ! class_exists( 'PWATG_Anthropic_Service' ) ) {
  /**
   * Handles Anthropic message API interactions.
   */
  class PWATG_Anthropic_Service {
    /** Request alt text using Anthropic's multimodal endpoint. */
    public static function request_alt_text( $api_key, $model, $prompt, $mime_type, $image_binary ) {
      if ( '' === trim( (string) $api_key ) ) {
        return new WP_Error( 'pwatg_missing_api_key', __( 'Missing API key in Presswell Alt Text settings.', 'presswell-alt-text' ) );
      }

      $body = [
        'model'      => $model,
        'max_tokens' => 120,
        'system'     => 'You write clear, concise alt text for accessibility.',
        'messages'   => [
          [
            'role'    => 'user',
            'content' => [
              [
                'type' => 'text',
                'text' => $prompt,
              ],
              [
                'type'   => 'image',
                'source' => [
                  'type'       => 'base64',
                  'media_type' => $mime_type,
                  'data'       => base64_encode( $image_binary ),
                ],
              ],
            ],
          ],
        ],
      ];

      $response = wp_remote_post(
        'https://api.anthropic.com/v1/messages',
        [
          'headers' => [
            'Content-Type'      => 'application/json',
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
          ],
          'body'    => wp_json_encode( $body ),
          'timeout' => 45,
        ]
      );

      if ( is_wp_error( $response ) ) {
        return $response;
      }

      $data = self::decode_or_error( $response );
      if ( is_wp_error( $data ) ) {
        return $data;
      }

      return self::extract_text_block( $data, 'pwatg_empty_alt', __( 'AI response did not include alt text.', 'presswell-alt-text' ) );
    }

    /** Request a quick text response to validate credentials/config. */
    public static function request_text( $api_key, $model, $prompt ) {
      if ( '' === trim( (string) $api_key ) ) {
        return new WP_Error( 'pwatg_missing_api_key', __( 'Missing API key in Presswell Alt Text settings.', 'presswell-alt-text' ) );
      }

      $body = [
        'model'      => $model,
        'max_tokens' => 40,
        'messages'   => [
          [
            'role'    => 'user',
            'content' => $prompt,
          ],
        ],
      ];

      $response = wp_remote_post(
        'https://api.anthropic.com/v1/messages',
        [
          'headers' => [
            'Content-Type'      => 'application/json',
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
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

      return self::extract_text_block( $data, 'pwatg_connection_error', __( 'No response text returned by provider.', 'presswell-alt-text' ) );
    }

    /** Decode JSON body and convert non-2xx responses to WP_Error. */
    private static function decode_or_error( $response ) {
      $http_code = wp_remote_retrieve_response_code( $response );
      $data      = json_decode( wp_remote_retrieve_body( $response ), true );

      if ( $http_code < 200 || $http_code >= 300 ) {
        $error_message = isset( $data['error']['message'] ) ? sanitize_text_field( $data['error']['message'] ) : __( 'Unknown API error.', 'presswell-alt-text' );
        return self::build_api_error( $http_code, $error_message, $response );
      }

      return is_array( $data ) ? $data : [];
    }

    /** Normalize Anthropic HTTP errors for upstream handling. */
    private static function build_api_error( $http_code, $error_message, $response ) {
      $code = 'pwatg_api_error';
      if ( 429 === $http_code ) {
        $code = 'pwatg_rate_limited';
      } elseif ( in_array( $http_code, [ 402, 403 ], true ) ) {
        $code = 'pwatg_quota_exceeded';
      }

      $data = [
        'http_code' => $http_code,
        'provider'  => 'anthropic',
      ];

      $retry_after = self::parse_retry_after_header( $response );
      if ( $retry_after > 0 ) {
        $data['retry_after'] = $retry_after;
      }

      return new WP_Error( $code, $error_message, $data );
    }

    /** Pull the Retry-After seconds header if present. */
    private static function parse_retry_after_header( $response ) {
      $retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
      if ( is_array( $retry_after ) ) {
        $retry_after = end( $retry_after );
      }

      if ( ! is_scalar( $retry_after ) ) {
        return 0;
      }

      $retry_after = trim( (string) $retry_after );

      return ctype_digit( $retry_after ) ? (int) $retry_after : 0;
    }

    /**
     * Traverse the response blocks to find the textual output.
     *
     * @return string|WP_Error
     */
    private static function extract_text_block( array $data, $error_code, $error_message ) {
      if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
        foreach ( $data['content'] as $block ) {
          if ( isset( $block['type'] ) && 'text' === $block['type'] && isset( $block['text'] ) ) {
            return trim( wp_strip_all_tags( (string) $block['text'] ) );
          }
        }
      }

      return new WP_Error( $error_code, $error_message );
    }
  }
}
