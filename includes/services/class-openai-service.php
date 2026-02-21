<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

if ( ! class_exists( 'PWATG_OpenAI_Service' ) ) {
  class PWATG_OpenAI_Service {
    public static function request_alt_text( $api_key, $model, $prompt, $mime_type, $image_binary ) {
      if ( '' === trim( (string) $api_key ) ) {
        return new WP_Error( 'pwatg_missing_api_key', __( 'Missing API key in Presswell Alt Text settings.', 'presswell-alt-text' ) );
      }

      $body = [
        'model'      => $model,
        'max_tokens' => 80,
        'messages'   => [
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

      $response = wp_remote_post(
        'https://api.openai.com/v1/chat/completions',
        [
          'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
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

      if ( isset( $data['choices'][0]['message']['content'] ) ) {
        return trim( wp_strip_all_tags( (string) $data['choices'][0]['message']['content'] ) );
      }

      return new WP_Error( 'pwatg_empty_alt', __( 'AI response did not include alt text.', 'presswell-alt-text' ) );
    }

    public static function request_text( $api_key, $model, $prompt ) {
      if ( '' === trim( (string) $api_key ) ) {
        return new WP_Error( 'pwatg_missing_api_key', __( 'Missing API key in Presswell Alt Text settings.', 'presswell-alt-text' ) );
      }

      $body = [
        'model'      => $model,
        'max_tokens' => 30,
        'messages'   => [
          [
            'role'    => 'user',
            'content' => $prompt,
          ],
        ],
      ];

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

      return new WP_Error( 'pwatg_connection_error', __( 'No response text returned by provider.', 'presswell-alt-text' ) );
    }

    private static function decode_or_error( $response ) {
      $http_code = wp_remote_retrieve_response_code( $response );
      $data      = json_decode( wp_remote_retrieve_body( $response ), true );

      if ( $http_code < 200 || $http_code >= 300 ) {
        $error_message = isset( $data['error']['message'] ) ? sanitize_text_field( $data['error']['message'] ) : __( 'Unknown API error.', 'presswell-alt-text' );
        return self::build_api_error( $http_code, $error_message, $response );
      }

      return is_array( $data ) ? $data : [];
    }

    private static function build_api_error( $http_code, $error_message, $response ) {
      $code = 'pwatg_api_error';
      if ( 429 === $http_code ) {
        $code = 'pwatg_rate_limited';
      } elseif ( in_array( $http_code, [ 402, 403 ], true ) ) {
        $code = 'pwatg_quota_exceeded';
      }

      $data = [
        'http_code' => $http_code,
        'provider'  => 'openai',
      ];

      $retry_after = self::parse_retry_after_header( $response );
      if ( $retry_after > 0 ) {
        $data['retry_after'] = $retry_after;
      }

      return new WP_Error( $code, $error_message, $data );
    }

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
  }
}
