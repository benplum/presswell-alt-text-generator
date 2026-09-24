<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

if ( ! class_exists( 'PWATG_Provider_Service' ) ) {
  /**
   * Shared HTTP error handling for the AI provider services.
   *
   * Each provider signals limits differently, so status codes alone aren't enough:
   * OpenAI reports an exhausted balance as a 429, Anthropic as a 400, and a 403 is
   * usually a permissions problem rather than a quota.
   */
  abstract class PWATG_Provider_Service {
    /** Provider slug, set by each service. */
    const PROVIDER = '';

    /** Seconds to pause after an "overloaded" response that has no Retry-After header. */
    const OVERLOADED_RETRY_SECONDS = 60;

    /**
     * Decode a JSON response body, converting non-2xx responses to WP_Error.
     *
     * @param array $response wp_remote_post() response.
     *
     * @return array|WP_Error
     */
    protected static function decode_or_error( $response ) {
      $http_code = (int) wp_remote_retrieve_response_code( $response );
      $data      = json_decode( wp_remote_retrieve_body( $response ), true );
      $data      = is_array( $data ) ? $data : [];

      if ( $http_code < 200 || $http_code >= 300 ) {
        $message = isset( $data['error']['message'] ) ? sanitize_text_field( (string) $data['error']['message'] ) : __( 'Unknown API error.', 'presswell-alt-text-generator' );

        return static::build_api_error( $http_code, $message, $response, $data );
      }

      return $data;
    }

    /**
     * Build a WP_Error whose code tells the plugin whether to pause requests.
     *
     * @param int    $http_code HTTP status.
     * @param string $message   Provider error message.
     * @param array  $response  wp_remote_post() response.
     * @param array  $body      Decoded error body.
     *
     * @return WP_Error
     */
    protected static function build_api_error( $http_code, $message, $response, array $body = [] ) {
      $code = static::classify_error( (int) $http_code, $body, (string) $message );
      $data = [
        'http_code' => (int) $http_code,
        'provider'  => static::PROVIDER,
      ];

      $retry_after = static::parse_retry_after_header( $response );
      if ( $retry_after <= 0 && static::is_overloaded( (int) $http_code, $body, (string) $message ) ) {
        $retry_after = static::OVERLOADED_RETRY_SECONDS;
      }

      if ( $retry_after > 0 ) {
        $data['retry_after'] = $retry_after;
      }

      return new WP_Error( $code, $message, $data );
    }

    /**
     * Map a provider error to pwatg_quota_exceeded, pwatg_rate_limited, or pwatg_api_error.
     *
     * @param int    $http_code HTTP status.
     * @param array  $body      Decoded error body.
     * @param string $message   Provider error message.
     *
     * @return string
     */
    protected static function classify_error( $http_code, array $body, $message ) {
      if ( 402 === $http_code || static::is_quota_error( $http_code, $body, $message ) ) {
        return 'pwatg_quota_exceeded';
      }

      if ( 429 === $http_code || static::is_overloaded( $http_code, $body, $message ) ) {
        return 'pwatg_rate_limited';
      }

      return 'pwatg_api_error';
    }

    /**
     * Whether the account is out of credit or quota (overridden per provider).
     *
     * @param int    $http_code HTTP status.
     * @param array  $body      Decoded error body.
     * @param string $message   Provider error message.
     *
     * @return bool
     */
    protected static function is_quota_error( $http_code, array $body, $message ) {
      return false;
    }

    /**
     * Whether the provider is temporarily overloaded and the request can be retried.
     *
     * @param int    $http_code HTTP status.
     * @param array  $body      Decoded error body.
     * @param string $message   Provider error message.
     *
     * @return bool
     */
    protected static function is_overloaded( $http_code, array $body, $message ) {
      return false;
    }

    /**
     * Read the Retry-After header in seconds, if present.
     *
     * @param array $response wp_remote_post() response.
     *
     * @return int
     */
    protected static function parse_retry_after_header( $response ) {
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
