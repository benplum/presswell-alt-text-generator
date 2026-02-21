<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

trait PWATG_Providers_Trait {
  public function test_provider_connection( $service, $api_key, $model ) {
    return PWATG_Provider_Registry::request_text( $service, $api_key, $model, 'Reply with: OK' );
  }

  protected function get_rate_limit_lock_state() {
    $lock = $this->get_raw_rate_limit_lock();
    if ( ! $lock ) {
      return null;
    }

    $remaining = max( 0, $lock['until'] - time() );
    if ( $remaining <= 0 ) {
      delete_transient( PWATG::RATE_LIMIT_TRANSIENT );
      return null;
    }

    return [
      'code'      => $lock['code'],
      'provider'  => $lock['provider'],
      'remaining' => $remaining,
      'until'     => $lock['until'],
      'message'   => $this->format_rate_limit_lock_message( $lock, $remaining ),
    ];
  }

  protected function format_rate_limit_lock_message( array $lock, $remaining ) {
    $base_message = isset( $lock['message'] ) && '' !== trim( (string) $lock['message'] )
      ? trim( (string) $lock['message'] )
      : __( 'AI provider temporarily paused requests.', PWATG::TEXT_DOMAIN );

    if ( $remaining <= 0 ) {
      return $base_message;
    }

    $human = human_time_diff( time(), time() + $remaining );

    return sprintf(
      /* translators: 1: base error message. 2: human-readable duration. */
      __( '%1$s Please wait %2$s before retrying.', PWATG::TEXT_DOMAIN ),
      $base_message,
      $human
    );
  }

  protected function get_rate_limit_block_error() {
    $lock = $this->get_rate_limit_lock_state();
    if ( ! $lock ) {
      return null;
    }

    return new WP_Error(
      $lock['code'],
      $lock['message'],
      [
        'remaining' => $lock['remaining'],
        'provider'  => $lock['provider'],
      ]
    );
  }

  protected function maybe_start_rate_limit_lock( WP_Error $error ) {
    if ( ! $this->is_rate_limit_error( $error ) ) {
      return;
    }

    $duration = $this->determine_rate_limit_duration( $error );
    if ( $duration <= 0 ) {
      return;
    }

    $existing = $this->get_raw_rate_limit_lock();
    if ( $existing && isset( $existing['until'] ) && $existing['until'] > ( time() + $duration ) ) {
      return;
    }

    $provider = $this->extract_provider_slug_from_error( $error );
    $payload  = [
      'code'     => $error->get_error_code(),
      'provider' => $provider,
      'message'  => $this->build_rate_limit_base_message( $error, $provider ),
      'until'    => time() + $duration,
    ];

    set_transient( PWATG::RATE_LIMIT_TRANSIENT, $payload, $duration );
  }

  protected function determine_rate_limit_duration( WP_Error $error ) {
    if ( 'pwatg_quota_exceeded' === $error->get_error_code() ) {
      return PWATG::QUOTA_LOCK_SECONDS;
    }

    $data        = $error->get_error_data();
    $retry_after = 0;
    if ( is_array( $data ) && isset( $data['retry_after'] ) ) {
      $retry_after = (int) $data['retry_after'];
    }

    $duration = $retry_after > 0 ? $retry_after : PWATG::RATE_LIMIT_DEFAULT_SECONDS;

    return max( PWATG::RATE_LIMIT_MIN_SECONDS, min( $duration, PWATG::RATE_LIMIT_MAX_SECONDS ) );
  }

  protected function build_rate_limit_base_message( WP_Error $error, $provider_slug ) {
    $label   = $this->get_provider_label_for_slug( $provider_slug );
    $message = trim( (string) $error->get_error_message() );

    if ( '' === $message ) {
      $message = 'pwatg_quota_exceeded' === $error->get_error_code()
        ? __( 'Quota exceeded for the AI provider.', PWATG::TEXT_DOMAIN )
        : __( 'Rate limit reached for the AI provider.', PWATG::TEXT_DOMAIN );
    }

    if ( '' !== $label && false === stripos( $message, $label ) ) {
      $message = sprintf( '%s: %s', $label, $message );
    }

    return $message;
  }

  protected function get_provider_label_for_slug( $slug ) {
    $slug = sanitize_key( (string) $slug );
    if ( '' === $slug ) {
      return '';
    }

    if ( method_exists( $this, 'get_available_services' ) ) {
      $services = $this->get_available_services();
      if ( isset( $services[ $slug ] ) ) {
        return $services[ $slug ];
      }
    }

    return ucwords( str_replace( '-', ' ', $slug ) );
  }

  protected function extract_provider_slug_from_error( WP_Error $error ) {
    $data = $error->get_error_data();
    if ( is_array( $data ) && isset( $data['provider'] ) ) {
      return sanitize_key( (string) $data['provider'] );
    }

    return '';
  }

  protected function is_rate_limit_error( $error ) {
    if ( ! ( $error instanceof WP_Error ) ) {
      return false;
    }

    $code = $error->get_error_code();

    return in_array( $code, [ 'pwatg_rate_limited', 'pwatg_quota_exceeded' ], true );
  }

  protected function get_raw_rate_limit_lock() {
    $lock = get_transient( PWATG::RATE_LIMIT_TRANSIENT );
    if ( ! is_array( $lock ) ) {
      return null;
    }

    $lock['until']    = isset( $lock['until'] ) ? (int) $lock['until'] : 0;
    $lock['code']     = isset( $lock['code'] ) ? (string) $lock['code'] : 'pwatg_rate_limited';
    $lock['provider'] = isset( $lock['provider'] ) ? sanitize_key( (string) $lock['provider'] ) : '';

    if ( $lock['until'] <= time() ) {
      delete_transient( PWATG::RATE_LIMIT_TRANSIENT );
      return null;
    }

    return $lock;
  }

  protected function get_rate_limit_notice_text() {
    $lock = $this->get_rate_limit_lock_state();

    return $lock ? $lock['message'] : '';
  }

  public function generate_alt_text_for_attachment( $attachment_id, $force_regenerate = false ) {
    $attachment_id = absint( $attachment_id );
    if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
      return new WP_Error( 'pwatg_invalid_attachment', __( 'Invalid image attachment.', PWATG::TEXT_DOMAIN ) );
    }

    $lock_error = $this->get_rate_limit_block_error();
    if ( $lock_error ) {
      return $lock_error;
    }

    $current_alt = trim( (string) get_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, true ) );
    if ( ! $force_regenerate && '' !== $current_alt ) {
      return false;
    }

    $settings = $this->get_settings();

    $file_path = get_attached_file( $attachment_id );
    if ( ! $file_path || ! file_exists( $file_path ) ) {
      return new WP_Error( 'pwatg_missing_file', __( 'Image file does not exist.', PWATG::TEXT_DOMAIN ) );
    }

    $file_size = filesize( $file_path );
    if ( false === $file_size || $file_size > 5 * 1024 * 1024 ) {
      return new WP_Error( 'pwatg_file_too_large', __( 'Image file is too large to send for alt text generation.', PWATG::TEXT_DOMAIN ) );
    }

    $image_binary = file_get_contents( $file_path );
    if ( false === $image_binary ) {
      return new WP_Error( 'pwatg_unreadable_file', __( 'Could not read image file.', PWATG::TEXT_DOMAIN ) );
    }

    $mime_type = get_post_mime_type( $attachment_id );
    if ( empty( $mime_type ) ) {
      $mime_type = 'image/jpeg';
    }

    $prompt = sprintf(
      /* translators: %s: filename */
      __( 'Filename context: %s. Return only the alt text with no quotes.', PWATG::TEXT_DOMAIN ),
      basename( $file_path )
    );

    $prompt_seed = isset( $settings['prompt_seed'] ) ? trim( (string) $settings['prompt_seed'] ) : '';
    if ( '' === $prompt_seed ) {
      $defaults    = $this->get_default_settings();
      $prompt_seed = $defaults['prompt_seed'];
    }

    $full_prompt = trim( $prompt_seed ) . "\n\n" . $prompt;
    $service     = isset( $settings['service'] ) ? sanitize_key( $settings['service'] ) : 'openai';
    $model       = isset( $settings['model'] ) ? trim( (string) $settings['model'] ) : '';

    if ( '' === $model ) {
      return new WP_Error( 'pwatg_missing_model', __( 'Missing model in Presswell Alt Text settings.', PWATG::TEXT_DOMAIN ) );
    }

    $api_key = '';
    if ( isset( $settings['api_keys'][ $service ] ) ) {
      $api_key = trim( (string) $settings['api_keys'][ $service ] );
    }

    if ( '' === $api_key && isset( $settings['api_key'] ) ) {
      $api_key = trim( (string) $settings['api_key'] );
    }

    if ( '' === $api_key ) {
      return new WP_Error( 'pwatg_missing_api_key', __( 'Missing API key in Alt Text Generator settings.', PWATG::TEXT_DOMAIN ) );
    }

    $alt_text = PWATG_Provider_Registry::request_alt_text( $service, $api_key, $model, $full_prompt, $mime_type, $image_binary );

    if ( is_wp_error( $alt_text ) ) {
      $this->maybe_start_rate_limit_lock( $alt_text );
      return $alt_text;
    }

    $alt_text = sanitize_text_field( $alt_text );
    if ( '' === $alt_text ) {
      return new WP_Error( 'pwatg_empty_alt', __( 'AI response did not include alt text.', PWATG::TEXT_DOMAIN ) );
    }

    if ( mb_strlen( $alt_text ) > 220 ) {
      $alt_text = mb_substr( $alt_text, 0, 220 );
    }

    update_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, $alt_text );
    update_post_meta( $attachment_id, PWATG::META_KEY_LAST_GENERATED, (string) current_time( 'timestamp', true ) );

    return true;
  }

  protected function request_openai_alt_text( $api_key, $model, $prompt, $mime_type, $image_binary ) {
    return PWATG_OpenAI_Service::request_alt_text( $api_key, $model, $prompt, $mime_type, $image_binary );
  }

  protected function request_openai_text( $api_key, $model, $prompt ) {
    return PWATG_OpenAI_Service::request_text( $api_key, $model, $prompt );
  }

  protected function request_anthropic_alt_text( $api_key, $model, $prompt, $mime_type, $image_binary ) {
    return PWATG_Anthropic_Service::request_alt_text( $api_key, $model, $prompt, $mime_type, $image_binary );
  }

  protected function request_anthropic_text( $api_key, $model, $prompt ) {
    return PWATG_Anthropic_Service::request_text( $api_key, $model, $prompt );
  }

  protected function request_gemini_alt_text( $api_key, $model, $prompt, $mime_type, $image_binary ) {
    return PWATG_Gemini_Service::request_alt_text( $api_key, $model, $prompt, $mime_type, $image_binary );
  }

  protected function request_gemini_text( $api_key, $model, $prompt ) {
    return PWATG_Gemini_Service::request_text( $api_key, $model, $prompt );
  }
}
