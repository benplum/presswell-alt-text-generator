<?php
/**
 * Stands in for WP_AI_Client_Prompt_Builder: records the chain and returns a canned result.
 */

if ( ! class_exists( 'PWATG_Fake_Prompt_Builder' ) ) {
  class PWATG_Fake_Prompt_Builder {
    /** @var string|WP_Error */
    public static $result = 'AI Client alt text';

    /** @var PWATG_Fake_Prompt_Builder|null */
    public static $last = null;

    /** @var array<string, array> Calls by method name. */
    public $calls = [];

    public function __construct( $prompt ) {
      $this->calls['prompt'] = [ $prompt ];
      self::$last            = $this;
    }

    public function __call( $name, $arguments ) {
      $this->calls[ $name ] = $arguments;

      return 'generate_text' === $name ? self::$result : $this;
    }

    public static function reset() {
      self::$result = 'AI Client alt text';
      self::$last   = null;
    }
  }
}
