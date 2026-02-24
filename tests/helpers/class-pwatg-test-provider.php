<?php
/**
 * Shared provider stub used in tests.
 */

if ( ! class_exists( 'PWATG_Test_Provider' ) ) {
  class PWATG_Test_Provider {
    public static $response = 'Stub alt text.';
    public static $last_request = null;

    public static function reset() {
      self::$response = 'Stub alt text.';
      self::$last_request = null;
    }

    public static function request_alt_text( $api_key, $model, $prompt, $mime_type, $image_binary ) {
      self::$last_request = compact( 'api_key', 'model', 'prompt', 'mime_type', 'image_binary' );
      return self::$response;
    }

    public static function request_text( $api_key, $model, $prompt ) {
      return 'OK';
    }
  }
}
