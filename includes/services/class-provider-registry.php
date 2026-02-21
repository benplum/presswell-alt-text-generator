<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

if ( ! class_exists( 'PWATG_Provider_Registry' ) ) {
  class PWATG_Provider_Registry {
    public static function request_alt_text( $service, $api_key, $model, $prompt, $mime_type, $image_binary ) {
      $provider = self::resolve( $service );
      if ( ! is_callable( [ $provider, 'request_alt_text' ] ) ) {
        return new WP_Error( 'pwatg_invalid_provider', __( 'Selected AI provider is not supported.', 'presswell-alt-text' ) );
      }

      return call_user_func( [ $provider, 'request_alt_text' ], $api_key, $model, $prompt, $mime_type, $image_binary );
    }

    public static function request_text( $service, $api_key, $model, $prompt ) {
      $provider = self::resolve( $service );
      if ( ! is_callable( [ $provider, 'request_text' ] ) ) {
        return new WP_Error( 'pwatg_invalid_provider', __( 'Selected AI provider is not supported.', 'presswell-alt-text' ) );
      }

      return call_user_func( [ $provider, 'request_text' ], $api_key, $model, $prompt );
    }

    private static function resolve( $service ) {
      $service = sanitize_key( (string) $service );
      $map     = PWATG::PROVIDER_MAP;

      $map = apply_filters( 'pwatg_provider_registry', $map );

      if ( isset( $map[ $service ] ) ) {
        return $map[ $service ];
      }

      return isset( $map[ PWATG::PROVIDER_OPENAI ] ) ? $map[ PWATG::PROVIDER_OPENAI ] : 'PWATG_OpenAI_Service';
    }
  }
}
