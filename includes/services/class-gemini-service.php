<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

if ( ! class_exists( 'PWATG_Gemini_Service' ) ) {
  /** Interface to Google's Gemini generative language endpoint. */
  class PWATG_Gemini_Service extends PWATG_Provider_Service {
    const PROVIDER = 'gemini';

    /** Request alt text for a specific image. */
    public static function request_alt_text( $api_key, $model, $prompt, $mime_type, $image_binary ) {
      if ( '' === trim( (string) $api_key ) ) {
        return new WP_Error( 'pwatg_missing_api_key', __( 'Missing API key in Presswell Alt Text settings.', 'presswell-alt-text-generator' ) );
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
        'generationConfig' => self::generation_config( $model ),
      ];

      $response = self::request( self::build_url( $model ), $api_key, $body, 45 );
      if ( is_wp_error( $response ) ) {
        return $response;
      }

      return self::extract_text_parts( $response, 'pwatg_empty_alt', __( 'AI response did not include alt text.', 'presswell-alt-text-generator' ) );
    }

    /** Request text-only completions for diagnostics. */
    public static function request_text( $api_key, $model, $prompt ) {
      if ( '' === trim( (string) $api_key ) ) {
        return new WP_Error( 'pwatg_missing_api_key', __( 'Missing API key in Presswell Alt Text settings.', 'presswell-alt-text-generator' ) );
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
        'generationConfig' => self::generation_config( $model ),
      ];

      $response = self::request( self::build_url( $model ), $api_key, $body, 30 );
      if ( is_wp_error( $response ) ) {
        return $response;
      }

      return self::extract_text_parts( $response, 'pwatg_connection_error', __( 'No response text returned by provider.', 'presswell-alt-text-generator' ) );
    }

    /**
     * Output settings for a short answer.
     *
     * Gemini 2.5 models think before answering, and thinking tokens count toward
     * maxOutputTokens, so a small cap can leave no room for the answer. Flash models
     * can turn thinking off; Pro can't, so the cap leaves room for its minimum budget.
     *
     * @param string $model Model ID.
     *
     * @return array
     */
    private static function generation_config( $model ) {
      $config = [
        'maxOutputTokens' => 1024,
      ];

      if ( false !== strpos( (string) $model, 'flash' ) ) {
        $config['thinkingConfig'] = [ 'thinkingBudget' => 0 ];
      }

      return $config;
    }

    /** Compose the REST endpoint for the selected model. */
    private static function build_url( $model ) {
      return 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent';
    }

    /**
     * Fire a POST request and convert failures to WP_Error.
     *
     * The key goes in a header rather than the query string, so it stays out of
     * request URLs that proxies, HTTP logs, and error messages may record.
     */
    private static function request( $url, $api_key, array $body, $timeout ) {
      $response = wp_remote_post(
        $url,
        [
          'headers' => [
            'Content-Type'   => 'application/json',
            'x-goog-api-key' => $api_key,
          ],
          'body'    => wp_json_encode( $body ),
          'timeout' => $timeout,
        ]
      );

      if ( is_wp_error( $response ) ) {
        return $response;
      }

      return self::decode_or_error( $response );
    }

    /** Extract the concatenated text parts from a successful response. */
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

    /** Gemini reports both rate and quota limits as 429 RESOURCE_EXHAUSTED; the message tells them apart. */
    protected static function is_quota_error( $http_code, array $body, $message ) {
      return 429 === $http_code && false !== stripos( $message, 'quota' );
    }

    /** Gemini returns 503 when a model is overloaded. */
    protected static function is_overloaded( $http_code, array $body, $message ) {
      return 503 === $http_code;
    }
  }
}
