<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

if ( ! class_exists( 'PWATG_Gemini_Service' ) ) {
  /** Interface to Google's Gemini generative language endpoint. */
  class PWATG_Gemini_Service extends PWATG_Provider_Service {
    const PROVIDER = 'gemini';

    /** Smallest thinking budget Pro models accept; alt text needs little reasoning. */
    const PRO_THINKING_BUDGET = 128;

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

      $response = self::request( self::build_url( $model ), $api_key, $body, self::ALT_TEXT_TIMEOUT );
      if ( is_wp_error( $response ) ) {
        return $response;
      }

      return self::extract_text_parts( $response, 'pwatg_empty_alt', __( 'AI response did not include alt text.', 'presswell-alt-text-generator' ), $model );
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
     * can turn thinking off. Pro can't, and left to itself can spend the whole cap
     * thinking and return no text, so it gets its minimum thinking budget.
     *
     * @param string $model Model ID.
     *
     * @return array
     */
    private static function generation_config( $model ) {
      $model  = (string) $model;
      $config = [
        'maxOutputTokens' => 1024,
      ];

      if ( false !== strpos( $model, 'flash' ) ) {
        $config['thinkingConfig'] = [ 'thinkingBudget' => 0 ];
      } elseif ( false !== strpos( $model, '-pro' ) ) {
        $config['thinkingConfig'] = [ 'thinkingBudget' => self::PRO_THINKING_BUDGET ];
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

    /**
     * Extract the concatenated text parts from a successful response.
     *
     * @param array  $data          Decoded response.
     * @param string $error_code    Error code when there is no text.
     * @param string $error_message Error message when there is no text.
     * @param string $model         Model ID, for the debug log.
     *
     * @return string|WP_Error
     */
    private static function extract_text_parts( array $data, $error_code, $error_message, $model = '' ) {
      if ( isset( $data['candidates'][0]['content']['parts'] ) && is_array( $data['candidates'][0]['content']['parts'] ) ) {
        $parts = $data['candidates'][0]['content']['parts'];
        $texts = [];
        foreach ( $parts as $part ) {
          if ( isset( $part['text'] ) ) {
            $texts[] = (string) $part['text'];
          }
        }

        if ( ! empty( $texts ) ) {
          $truncated = isset( $data['candidates'][0]['finishReason'] ) && 'MAX_TOKENS' === $data['candidates'][0]['finishReason'];

          return self::finalize_text( implode( ' ', $texts ), $truncated, $model );
        }
      }

      return new WP_Error( $error_code, $error_message );
    }

    /**
     * Whether a 429 means a daily quota is used up, rather than a per-minute limit.
     *
     * Gemini reports both as 429 RESOURCE_EXHAUSTED and words both "You exceeded your
     * current quota", so only the QuotaFailure details tell them apart: daily quota IDs
     * contain "PerDay" (e.g. GenerateRequestsPerDayPerProjectPerModel-FreeTier).
     *
     * @param int    $http_code HTTP status.
     * @param array  $body      Decoded error body.
     * @param string $message   Provider error message.
     *
     * @return bool
     */
    protected static function is_quota_error( $http_code, array $body, $message ) {
      if ( 429 !== $http_code ) {
        return false;
      }

      foreach ( self::get_error_details( $body, 'google.rpc.QuotaFailure' ) as $detail ) {
        $violations = isset( $detail['violations'] ) && is_array( $detail['violations'] ) ? $detail['violations'] : [];

        foreach ( $violations as $violation ) {
          if ( ! is_array( $violation ) ) {
            continue;
          }

          foreach ( [ 'quotaId', 'quotaMetric' ] as $field ) {
            if ( isset( $violation[ $field ] ) && is_scalar( $violation[ $field ] ) && preg_match( '/per_?day/i', (string) $violation[ $field ] ) ) {
              return true;
            }
          }
        }
      }

      return false;
    }

    /**
     * Read the RetryInfo delay (e.g. "34s" or "1.5s") that Gemini sends with rate limits.
     *
     * @param array $body Decoded error body.
     *
     * @return int Seconds, or 0 when absent.
     */
    protected static function parse_retry_after_body( array $body ) {
      foreach ( self::get_error_details( $body, 'google.rpc.RetryInfo' ) as $detail ) {
        $delay = isset( $detail['retryDelay'] ) && is_scalar( $detail['retryDelay'] ) ? trim( (string) $detail['retryDelay'] ) : '';

        if ( preg_match( '/^(\d+(?:\.\d+)?)s$/', $delay, $matches ) ) {
          return max( 1, (int) ceil( (float) $matches[1] ) );
        }
      }

      return 0;
    }

    /**
     * The error.details entries of one type.
     *
     * @param array  $body Decoded error body.
     * @param string $type Type name without the type.googleapis.com/ prefix.
     *
     * @return array[]
     */
    private static function get_error_details( array $body, $type ) {
      if ( ! isset( $body['error']['details'] ) || ! is_array( $body['error']['details'] ) ) {
        return [];
      }

      $matches = [];
      foreach ( $body['error']['details'] as $detail ) {
        if ( is_array( $detail ) && isset( $detail['@type'] ) && is_string( $detail['@type'] ) && str_ends_with( $detail['@type'], '/' . $type ) ) {
          $matches[] = $detail;
        }
      }

      return $matches;
    }

    /** Gemini returns 503 when a model is overloaded. */
    protected static function is_overloaded( $http_code, array $body, $message ) {
      return 503 === $http_code;
    }
  }
}
