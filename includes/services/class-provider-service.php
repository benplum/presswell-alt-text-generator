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

    /** HTTP timeout in seconds for an alt text request, which uploads an image. */
    const ALT_TEXT_TIMEOUT = 45;

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
      if ( $retry_after <= 0 ) {
        $retry_after = static::parse_retry_after_body( $body );
      }
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
     * Mask anything in a provider message that looks like a credential.
     *
     * Some providers echo part of a rejected key ("Incorrect API key provided: sk-ab***yz"),
     * so messages shown to users who can't see the settings are masked first.
     *
     * @param string $message Message.
     *
     * @return string
     */
    public static function redact_secrets( $message ) {
      $redacted = preg_replace(
        [
          '/\b(?:sk|pk|rk)[-_][A-Za-z0-9_\-\*\.]{4,}/',
          '/\bAIza[A-Za-z0-9_\-\*]{4,}/',
          '/(?<![\w\/.])[A-Za-z0-9_\-\*]{32,}(?![\w\/.])/',
        ],
        '[redacted]',
        (string) $message
      );

      return is_string( $redacted ) ? $redacted : '';
    }

    /**
     * Read a retry delay from the error body, for providers that send one there (overridden per provider).
     *
     * @param array $body Decoded error body.
     *
     * @return int Seconds, or 0 when absent.
     */
    protected static function parse_retry_after_body( array $body ) {
      return 0;
    }

    /**
     * Clean up model output, repairing it when the provider cut it off at the token limit.
     *
     * A truncated answer would otherwise be saved as if it were complete, ending mid-word.
     *
     * @param string $text      Raw output.
     * @param bool   $truncated Whether the provider reported hitting the output limit.
     * @param string $model     Model ID, for the debug log.
     *
     * @return string
     */
    protected static function finalize_text( $text, $truncated, $model = '' ) {
      $text = trim( wp_strip_all_tags( (string) $text ) );

      if ( ! $truncated || '' === $text ) {
        return $text;
      }

      $repaired = self::trim_truncated_text( $text );

      if ( function_exists( 'presswell_alt_text_generator' ) ) {
        presswell_alt_text_generator()->debug_log(
          'Provider output was cut off at the token limit; trimmed to complete words.',
          [
            'provider'        => static::PROVIDER,
            'model'           => (string) $model,
            'original_length' => mb_strlen( $text ),
            'trimmed_length'  => mb_strlen( $repaired ),
          ]
        );
      }

      return $repaired;
    }

    /**
     * Trim cut-off text to its last complete sentence, or else drop the partial last word.
     *
     * @param string $text Text that ended mid-output.
     *
     * @return string
     */
    public static function trim_truncated_text( $text ) {
      $text = trim( (string) $text );

      // Everything up to the last sentence-ending mark (plus any closing quote or bracket).
      if ( preg_match( '/^(.*[.!?\x{3002}\x{FF01}\x{FF1F}][\'"\x{201D}\x{2019})\]]*)\s/us', $text . ' ', $matches ) ) {
        return trim( $matches[1] );
      }

      return self::drop_partial_word( $text );
    }

    /**
     * Remove the last, possibly incomplete, word and any trailing separator.
     *
     * A regex rather than mb_strrpos(), which WordPress doesn't polyfill without mbstring.
     *
     * @param string $text Text.
     *
     * @return string Unchanged apart from separators when it is a single word.
     */
    public static function drop_partial_word( $text ) {
      $text    = (string) $text;
      $trimmed = preg_replace( '/\s+\S*$/u', '', $text );

      return rtrim( is_string( $trimmed ) && '' !== $trimmed ? $trimmed : $text, ' ,;:-' );
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
