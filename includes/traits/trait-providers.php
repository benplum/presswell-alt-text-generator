<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Provider-facing helpers including rate limit tracking and adapters.
 */
trait PWATG_Providers_Trait {
  /**
   * Issue a lightweight text request to confirm credentials are valid.
   *
   * @param string $service Provider slug.
   * @param string $api_key Secret token.
   * @param string $model   Model identifier.
   *
   * @return string|WP_Error
   */
  public function test_provider_connection( $service, $api_key, $model ) {
    return PWATG_Provider_Registry::request_text( $service, $api_key, $model, 'Reply with: OK' );
  }

  /**
   * Return the active rate-limit lock payload if enforced.
   *
   * @return array|null
   */
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

  /**
   * Build a localized admin-friendly rate limit notice.
   *
   * @param array $lock      Stored lock payload.
   * @param int   $remaining Seconds remaining.
   *
   * @return string
   */
  protected function format_rate_limit_lock_message( array $lock, $remaining ) {
    $base_message = isset( $lock['message'] ) && '' !== trim( (string) $lock['message'] )
      ? trim( (string) $lock['message'] )
      : __( 'AI provider temporarily paused requests.', 'presswell-alt-text-generator' );

    if ( $remaining <= 0 ) {
      return $base_message;
    }

    $human = human_time_diff( time(), time() + $remaining );

    return sprintf(
      /* translators: 1: base error message. 2: human-readable duration. */
      __( '%1$s Please wait %2$s before retrying.', 'presswell-alt-text-generator' ),
      $base_message,
      $human
    );
  }

  /**
   * Convert lock state to a WP_Error for upstream checks.
   *
   * @return WP_Error|null
   */
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

  /** Potentially persist a new lock duration if providers respond with rate limits. */
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

  /** Determine how long a lock should last given the error payload. */
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

  /**
   * Build the base error sentence combining provider label and message.
   *
   * @param WP_Error $error          Provider error.
   * @param string   $provider_slug  Provider slug.
   *
   * @return string
   */
  protected function build_rate_limit_base_message( WP_Error $error, $provider_slug ) {
    $label   = $this->get_provider_label_for_slug( $provider_slug );
    $message = trim( (string) $error->get_error_message() );

    if ( '' === $message ) {
      $message = 'pwatg_quota_exceeded' === $error->get_error_code()
        ? __( 'Quota exceeded for the AI provider.', 'presswell-alt-text-generator' )
        : __( 'Rate limit reached for the AI provider.', 'presswell-alt-text-generator' );
    }

    if ( '' !== $label && false === stripos( $message, $label ) ) {
      $message = sprintf( '%s: %s', $label, $message );
    }

    return $message;
  }

  /** Convert a provider slug into a human-friendly label. */
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

  /** Inspect error data to find which provider triggered it. */
  protected function extract_provider_slug_from_error( WP_Error $error ) {
    $data = $error->get_error_data();
    if ( is_array( $data ) && isset( $data['provider'] ) ) {
      return sanitize_key( (string) $data['provider'] );
    }

    return '';
  }

  /** Check whether an error code maps to rate-limiting behavior. */
  protected function is_rate_limit_error( $error ) {
    if ( ! ( $error instanceof WP_Error ) ) {
      return false;
    }

    $code = $error->get_error_code();

    return in_array( $code, [ 'pwatg_rate_limited', 'pwatg_quota_exceeded' ], true );
  }

  /** Fetch the raw transient payload storing rate-limit metadata. */
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

  /** Convenience wrapper returning the formatted notice text. */
  protected function get_rate_limit_notice_text() {
    $lock = $this->get_rate_limit_lock_state();

    return $lock ? $lock['message'] : '';
  }

  /**
   * Core helper that loads attachment data and asks providers for new alt text.
   *
   * @param int  $attachment_id    Attachment ID to process.
   * @param bool $force_regenerate Whether to overwrite existing text.
   *
   * @return bool|WP_Error False when skipped, true when updated, or error.
   */
  public function generate_alt_text_for_attachment( $attachment_id, $force_regenerate = false ) {
    $attachment_id = absint( $attachment_id );
    $this->debug_log(
      'Starting alt text generation for attachment.',
      [
        'attachment_id'     => $attachment_id,
        'force_regenerate'  => (bool) $force_regenerate,
      ]
    );

    if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
      $error = new WP_Error( 'pwatg_invalid_attachment', __( 'Invalid image attachment.', 'presswell-alt-text-generator' ) );
      $this->debug_log( 'Alt generation failed: invalid attachment.', [ 'attachment_id' => $attachment_id ] );
      return $error;
    }

    $lock_error = $this->get_rate_limit_block_error();
    if ( $lock_error ) {
      $this->debug_log(
        'Alt generation blocked by rate limit lock.',
        [
          'attachment_id' => $attachment_id,
          'code'          => $lock_error->get_error_code(),
          'message'       => $lock_error->get_error_message(),
        ]
      );
      return $lock_error;
    }

    $current_alt = trim( (string) get_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, true ) );
    if ( ! $force_regenerate && '' !== $current_alt ) {
      $this->debug_log( 'Alt generation skipped because alt text already exists.', [ 'attachment_id' => $attachment_id ] );
      return false;
    }

    $settings = $this->get_settings();

    $file_path = get_attached_file( $attachment_id );
    if ( ! $file_path || ! file_exists( $file_path ) ) {
      return new WP_Error( 'pwatg_missing_file', __( 'Image file does not exist.', 'presswell-alt-text-generator' ) );
    }

    $file_size     = filesize( $file_path );
    $max_size      = 5 * 1024 * 1024;
    $used_fallback = false;

    if ( false === $file_size ) {
      return new WP_Error( 'pwatg_unreadable_file', __( 'Could not read image file.', 'presswell-alt-text-generator' ) );
    }

    // If original is too large, try generated subsizes before failing.
    if ( $file_size > $max_size ) {
      $sizes_to_try = [ 'large', 'medium_large', 'medium', /* 'thumbnail' */ ];
      $base_dir     = trailingslashit( pathinfo( $file_path, PATHINFO_DIRNAME ) );

      foreach ( $sizes_to_try as $size ) {
        $intermediate = image_get_intermediate_size( $attachment_id, $size );
        if ( ! is_array( $intermediate ) || empty( $intermediate['file'] ) ) {
          continue;
        }

        $scaled_path = $base_dir . ltrim( (string) $intermediate['file'], '/' );
        if ( ! file_exists( $scaled_path ) ) {
          continue;
        }

        $scaled_size = filesize( $scaled_path );
        if ( false === $scaled_size || $scaled_size > $max_size ) {
          continue;
        }

        $file_path     = $scaled_path;
        $file_size     = $scaled_size;
        $used_fallback = true;
        break;
      }

      if ( ! $used_fallback ) {
        return new WP_Error( 'pwatg_file_too_large', __( 'Image file is too large to send for alt text generation.', 'presswell-alt-text-generator' ) );
      }
    }

    $image_binary = file_get_contents( $file_path );
    if ( false === $image_binary ) {
      return new WP_Error( 'pwatg_unreadable_file', __( 'Could not read image file.', 'presswell-alt-text-generator' ) );
    }

    $mime_type = get_post_mime_type( $attachment_id );
    if ( empty( $mime_type ) ) {
      $mime_type = 'image/jpeg';
    }
    // If we used a fallback file, infer type from the selected file path.
    if ( $used_fallback ) {
      $filetype = wp_check_filetype( wp_basename( $file_path ) );
      if ( ! empty( $filetype['type'] ) ) {
        $mime_type = $filetype['type'];
      }
    }

    $prompt = sprintf(
      /* translators: %s: filename */
      __( 'Filename context: %s. Return only the alt text with no quotes.', 'presswell-alt-text-generator' ),
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

    $this->debug_log(
      'Prepared provider request context for attachment.',
      [
        'attachment_id' => $attachment_id,
        'service'       => $service,
        'model'         => $model,
      ]
    );

    if ( '' === $model ) {
      $error = new WP_Error( 'pwatg_missing_model', __( 'Missing model in Presswell Alt Text settings.', 'presswell-alt-text-generator' ) );
      $this->debug_log( 'Alt generation failed: missing model.', [ 'attachment_id' => $attachment_id ] );
      return $error;
    }

    $api_key = '';
    if ( isset( $settings['api_keys'][ $service ] ) ) {
      $api_key = trim( (string) $settings['api_keys'][ $service ] );
    }

    if ( '' === $api_key && isset( $settings['api_key'] ) ) {
      $api_key = trim( (string) $settings['api_key'] );
    }

    if ( '' === $api_key ) {
      $error = new WP_Error( 'pwatg_missing_api_key', __( 'Missing API key in Alt Text Generator settings.', 'presswell-alt-text-generator' ) );
      $this->debug_log( 'Alt generation failed: missing API key.', [ 'attachment_id' => $attachment_id, 'service' => $service ] );
      return $error;
    }

    $alt_text = PWATG_Provider_Registry::request_alt_text( $service, $api_key, $model, $full_prompt, $mime_type, $image_binary );

    if ( is_wp_error( $alt_text ) ) {
      $this->maybe_start_rate_limit_lock( $alt_text );
      $this->debug_log(
        'Provider request returned an error.',
        [
          'attachment_id' => $attachment_id,
          'service'       => $service,
          'model'         => $model,
          'code'          => $alt_text->get_error_code(),
          'message'       => $alt_text->get_error_message(),
        ]
      );
      return $alt_text;
    }

    $alt_text = sanitize_text_field( $alt_text );
    if ( '' === $alt_text ) {
      $error = new WP_Error( 'pwatg_empty_alt', __( 'AI response did not include alt text.', 'presswell-alt-text-generator' ) );
      $this->debug_log( 'Provider returned empty alt text.', [ 'attachment_id' => $attachment_id, 'service' => $service, 'model' => $model ] );
      return $error;
    }

    if ( mb_strlen( $alt_text ) > 220 ) {
      $alt_text = mb_substr( $alt_text, 0, 220 );
    }

    update_post_meta( $attachment_id, PWATG::META_KEY_ALT_TEXT, $alt_text );
    update_post_meta( $attachment_id, PWATG::META_KEY_LAST_GENERATED, (string) current_time( 'timestamp', true ) );

    $this->debug_log(
      'Alt text generation completed successfully.',
      [
        'attachment_id' => $attachment_id,
        'service'       => $service,
        'model'         => $model,
        'alt_length'    => mb_strlen( $alt_text ),
      ]
    );

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
