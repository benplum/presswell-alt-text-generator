<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

if ( ! class_exists( 'PWATG_Gemini_Service' ) ) {
  class PWATG_Gemini_Service {
    public static function request_alt_text( $api_key, $model, $prompt, $mime_type, $image_binary ) {
      if ( '' === trim( (string) $api_key ) ) {
        return new WP_Error( 'pwatg_missing_api_key', __( 'Missing API key in Presswell Alt Text settings.', 'presswell-alt-text' ) );
      }

      $body = [
        'contents' => [
          [
            'parts' => [
              [
                'text' => $prompt,
              ],
              [
                'inlineData' => [
                  'mimeType' => $mime_type,
                  'data'     => base64_encode( $image_binary ),
                ],
              ],
            ],
          ],
        ],
        'generationConfig' => [
          'maxOutputTokens' => 80,
        ],
      ];

      $url = self::build_url( $model, $api_key );
      $response = self::request( $url, $body, 45 );
      if ( is_wp_error( $response ) ) {
        return $response;
      }

      return self::extract_text_parts( $response, 'pwatg_empty_alt', __( 'AI response did not include alt text.', 'presswell-alt-text' ) );
    }

    public static function request_text( $api_key, $model, $prompt ) {
      if ( '' === trim( (string) $api_key ) ) {
        return new WP_Error( 'pwatg_missing_api_key', __( 'Missing API key in Presswell Alt Text settings.', 'presswell-alt-text' ) );
      }

      $body = [
        'contents' => [
          [
            'parts' => [
              [
                'text' => $prompt,
              ],
            ],
          ],
        ],
        'generationConfig' => [
          'maxOutputTokens' => 30,
        ],
      ];

      $url = self::build_url( $model, $api_key );
      $response = self::request( $url, $body, 30 );
      if ( is_wp_error( $response ) ) {
        return $response;
      }

      return self::extract_text_parts( $response, 'pwatg_connection_error', __( 'No response text returned by provider.', 'presswell-alt-text' ) );
    }

    private static function build_url( $model, $api_key ) {
      return 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $api_key );
    }

    private static function request( $url, array $body, $timeout ) {
      $response = wp_remote_post(
        $url,
        [
          'headers' => [
            'Content-Type' => 'application/json',
          ],
          'body'    => wp_json_encode( $body ),
          'timeout' => $timeout,
        ]
      );

      if ( is_wp_error( $response ) ) {
        return $response;
      }

      $http_code = wp_remote_retrieve_response_code( $response );
      $data      = json_decode( wp_remote_retrieve_body( $response ), true );

      if ( $http_code < 200 || $http_code >= 300 ) {
        $error_message = isset( $data['error']['message'] ) ? sanitize_text_field( $data['error']['message'] ) : __( 'Unknown API error.', 'presswell-alt-text' );
        return new WP_Error( 'pwatg_api_error', $error_message );
      }

      return is_array( $data ) ? $data : [];
    }

    private static function extract_text_parts( array $data, $error_code, $error_message ) {
      if ( isset( $data['candidates'][0]['content']['parts'] ) && is_array( $data['candidates'][0]['content']['parts'] ) ) {
        $parts = $data['candidates'][0]['content']['parts'];
        $texts = [];
        foreach ( $parts as $part ) {
          if ( isset( $part['text'] ) ) {
            $texts[] = (string) $part['text'];
          }
        }

        if ( ! empty( $texts ) ) {
          return trim( wp_strip_all_tags( implode( ' ', $texts ) ) );
        }
      }

      return new WP_Error( $error_code, $error_message );
    }
  }
}
