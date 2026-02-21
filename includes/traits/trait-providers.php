<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait PWATG_Providers_Trait {
	public function test_provider_connection( $service, $api_key, $model ) {
		return PWATG_Provider_Registry::request_text( $service, $api_key, $model, 'Reply with: OK' );
	}

	public function generate_alt_text_for_attachment( $attachment_id, $force_regenerate = false ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error( 'pwatg_invalid_attachment', __( 'Invalid image attachment.', PWATG::TEXT_DOMAIN ) );
		}

		$current_alt = trim( (string) get_post_meta( $attachment_id, PWATG::ALT_TEXT_META_KEY, true ) );
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
			return $alt_text;
		}

		$alt_text = sanitize_text_field( $alt_text );
		if ( '' === $alt_text ) {
			return new WP_Error( 'pwatg_empty_alt', __( 'AI response did not include alt text.', PWATG::TEXT_DOMAIN ) );
		}

		if ( mb_strlen( $alt_text ) > 220 ) {
			$alt_text = mb_substr( $alt_text, 0, 220 );
		}

		update_post_meta( $attachment_id, PWATG::ALT_TEXT_META_KEY, $alt_text );
		update_post_meta( $attachment_id, PWATG::LAST_GENERATED_META_KEY, (string) current_time( 'timestamp', true ) );

		return true;
	}

	public function request_openai_alt_text( $api_key, $model, $prompt, $mime_type, $image_binary ) {
		return PWATG_OpenAI_Service::request_alt_text( $api_key, $model, $prompt, $mime_type, $image_binary );
	}

	public function request_openai_text( $api_key, $model, $prompt ) {
		return PWATG_OpenAI_Service::request_text( $api_key, $model, $prompt );
	}

	public function request_anthropic_alt_text( $api_key, $model, $prompt, $mime_type, $image_binary ) {
		return PWATG_Anthropic_Service::request_alt_text( $api_key, $model, $prompt, $mime_type, $image_binary );
	}

	public function request_anthropic_text( $api_key, $model, $prompt ) {
		return PWATG_Anthropic_Service::request_text( $api_key, $model, $prompt );
	}

	public function request_gemini_alt_text( $api_key, $model, $prompt, $mime_type, $image_binary ) {
		return PWATG_Gemini_Service::request_alt_text( $api_key, $model, $prompt, $mime_type, $image_binary );
	}

	public function request_gemini_text( $api_key, $model, $prompt ) {
		return PWATG_Gemini_Service::request_text( $api_key, $model, $prompt );
	}
}
