<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

if ( ! class_exists( 'PWATG_WP_AI_Client_Service' ) ) {
  /**
   * Sends requests through the WordPress AI Client (WordPress 7.0+).
   *
   * The site's connected provider handles the request, so this plugin stores no key
   * and needs no provider-specific code. Errors come back as WP_Error with an HTTP
   * status, which is mapped onto the plugin's own rate-limit and quota codes.
   */
  class PWATG_WP_AI_Client_Service {
    /**
     * Builds a prompt; replaced in tests. Defaults to wp_ai_client_prompt().
     *
     * @var callable|null
     */
    public static $prompt_factory = null;

    /**
     * Whether a provider is registered and configured; replaced in tests.
     *
     * @var callable|null
     */
    public static $provider_checker = null;

    /** Output cap; generous because some models think before answering. */
    const MAX_TOKENS = 1024;

    /**
     * Whether the WordPress AI Client can be used on this site.
     *
     * @return bool
     */
    public static function is_available() {
      if ( null !== self::$prompt_factory ) {
        return true;
      }

      return function_exists( 'wp_ai_client_prompt' ) && ( ! function_exists( 'wp_supports_ai' ) || wp_supports_ai() );
    }

    /**
     * Whether a provider is registered with the AI Client and has credentials.
     *
     * Connectors can be listed in Settings → Connectors before any plugin provides
     * the provider itself, so the registry is checked, not the connector list.
     *
     * @param string $provider_id Provider ID, e.g. openai.
     *
     * @return bool
     */
    public static function is_provider_ready( $provider_id ) {
      $provider_id = (string) $provider_id;

      if ( '' === $provider_id || ! self::is_available() ) {
        return false;
      }

      if ( null !== self::$provider_checker ) {
        return (bool) call_user_func( self::$provider_checker, $provider_id );
      }

      if ( ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
        return false;
      }

      try {
        $registry = \WordPress\AiClient\AiClient::defaultRegistry();

        return in_array( $provider_id, $registry->getRegisteredProviderIds(), true ) && $registry->isProviderConfigured( $provider_id );
      } catch ( \Throwable $e ) {
        return false;
      }
    }

    /**
     * Ask the provider to describe an image.
     *
     * @param string $provider_id  Provider ID.
     * @param string $model        Preferred model; the provider picks another if it doesn't offer it.
     * @param string $prompt       Instructions.
     * @param string $mime_type    Image MIME type.
     * @param string $image_binary Image bytes.
     *
     * @return string|WP_Error
     */
    public static function request_alt_text( $provider_id, $model, $prompt, $mime_type, $image_binary ) {
      $builder = self::create_prompt( $prompt );
      if ( is_wp_error( $builder ) ) {
        return $builder;
      }

      $builder = $builder
        ->with_file( 'data:' . $mime_type . ';base64,' . base64_encode( $image_binary ), $mime_type )
        ->using_system_instruction( 'You write clear, concise alt text for accessibility.' );

      return self::generate( $builder, $provider_id, $model, 'pwatg_empty_alt', __( 'AI response did not include alt text.', 'presswell-alt-text-generator' ) );
    }

    /**
     * Send a short text prompt, for Test Connection.
     *
     * @param string $provider_id Provider ID.
     * @param string $model       Preferred model.
     * @param string $prompt      Prompt.
     *
     * @return string|WP_Error
     */
    public static function request_text( $provider_id, $model, $prompt ) {
      $builder = self::create_prompt( $prompt );
      if ( is_wp_error( $builder ) ) {
        return $builder;
      }

      return self::generate( $builder, $provider_id, $model, 'pwatg_connection_error', __( 'No response text returned by provider.', 'presswell-alt-text-generator' ) );
    }

    /**
     * @param string $prompt Prompt text.
     *
     * @return object|WP_Error Prompt builder.
     */
    protected static function create_prompt( $prompt ) {
      if ( ! self::is_available() ) {
        return new WP_Error( 'pwatg_ai_client_unavailable', __( 'The WordPress AI Client is not available on this site.', 'presswell-alt-text-generator' ) );
      }

      $factory = null !== self::$prompt_factory ? self::$prompt_factory : 'wp_ai_client_prompt';

      return call_user_func( $factory, (string) $prompt );
    }

    /**
     * Run the prompt and normalize the result.
     *
     * @param object $builder       Prompt builder.
     * @param string $provider_id   Provider ID.
     * @param string $model         Preferred model.
     * @param string $empty_code    Error code when no text comes back.
     * @param string $empty_message Error message when no text comes back.
     *
     * @return string|WP_Error
     */
    protected static function generate( $builder, $provider_id, $model, $empty_code, $empty_message ) {
      $builder = $builder->using_provider( (string) $provider_id )->using_max_tokens( self::MAX_TOKENS );

      if ( '' !== (string) $model ) {
        $builder = $builder->using_model_preference( (string) $model );
      }

      $text = $builder->generate_text();

      if ( is_wp_error( $text ) ) {
        return self::map_error( $text, $provider_id );
      }

      $text = trim( wp_strip_all_tags( (string) $text ) );

      return '' === $text ? new WP_Error( $empty_code, $empty_message ) : $text;
    }

    /**
     * Map an AI Client error onto the plugin's error codes, so rate limits pause runs.
     *
     * @param WP_Error $error       AI Client error.
     * @param string   $provider_id Provider ID.
     *
     * @return WP_Error
     */
    protected static function map_error( WP_Error $error, $provider_id ) {
      $data   = $error->get_error_data();
      $status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
      $code   = 'pwatg_api_error';

      if ( 402 === $status ) {
        $code = 'pwatg_quota_exceeded';
      } elseif ( 429 === $status ) {
        $code = false !== stripos( $error->get_error_message(), 'quota' ) ? 'pwatg_quota_exceeded' : 'pwatg_rate_limited';
      } elseif ( in_array( $status, [ 503, 529 ], true ) && 'prompt_prevented' !== $error->get_error_code() ) {
        $code = 'pwatg_rate_limited';
      }

      $error_data = [
        'http_code'       => $status,
        'provider'        => sanitize_key( (string) $provider_id ),
        'ai_client_code'  => $error->get_error_code(),
      ];

      if ( 'pwatg_rate_limited' === $code && in_array( $status, [ 503, 529 ], true ) ) {
        $error_data['retry_after'] = 60;
      }

      return new WP_Error( $code, sanitize_text_field( $error->get_error_message() ), $error_data );
    }
  }
}
