<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PWATG_Anthropic_Service' ) ) {
	class PWATG_Anthropic_Service {
		public static function request_alt_text( $api_key, $model, $prompt, $mime_type, $image_binary ) {
			if ( '' === trim( (string) $api_key ) ) {
				return new WP_Error( 'pwatg_missing_api_key', __( 'Missing API key in Presswell Alt Text settings.', 'presswell-alt-text' ) );
			}

			$body = [
				'model'      => $model,
				'max_tokens' => 120,
				'system'     => 'You write clear, concise alt text for accessibility.',
				'messages'   => [
					[
						'role'    => 'user',
						'content' => [
							[
								'type' => 'text',
								'text' => $prompt,
							],
							[
								'type'   => 'image',
								'source' => [
									'type'       => 'base64',
									'media_type' => $mime_type,
									'data'       => base64_encode( $image_binary ),
								],
							],
						],
					],
				],
			];

			$response = wp_remote_post(
				'https://api.anthropic.com/v1/messages',
				[
					'headers' => [
						'Content-Type'      => 'application/json',
						'x-api-key'         => $api_key,
						'anthropic-version' => '2023-06-01',
					],
					'body'    => wp_json_encode( $body ),
					'timeout' => 45,
				]
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$data = self::decode_or_error( $response );
			if ( is_wp_error( $data ) ) {
				return $data;
			}

			return self::extract_text_block( $data, 'pwatg_empty_alt', __( 'AI response did not include alt text.', 'presswell-alt-text' ) );
		}

		public static function request_text( $api_key, $model, $prompt ) {
			if ( '' === trim( (string) $api_key ) ) {
				return new WP_Error( 'pwatg_missing_api_key', __( 'Missing API key in Presswell Alt Text settings.', 'presswell-alt-text' ) );
			}

			$body = [
				'model'      => $model,
				'max_tokens' => 40,
				'messages'   => [
					[
						'role'    => 'user',
						'content' => $prompt,
					],
				],
			];

			$response = wp_remote_post(
				'https://api.anthropic.com/v1/messages',
				[
					'headers' => [
						'Content-Type'      => 'application/json',
						'x-api-key'         => $api_key,
						'anthropic-version' => '2023-06-01',
					],
					'body'    => wp_json_encode( $body ),
					'timeout' => 30,
				]
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$data = self::decode_or_error( $response );
			if ( is_wp_error( $data ) ) {
				return $data;
			}

			return self::extract_text_block( $data, 'pwatg_connection_error', __( 'No response text returned by provider.', 'presswell-alt-text' ) );
		}

		private static function decode_or_error( $response ) {
			$http_code = wp_remote_retrieve_response_code( $response );
			$data      = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $http_code < 200 || $http_code >= 300 ) {
				$error_message = isset( $data['error']['message'] ) ? sanitize_text_field( $data['error']['message'] ) : __( 'Unknown API error.', 'presswell-alt-text' );
				return new WP_Error( 'pwatg_api_error', $error_message );
			}

			return is_array( $data ) ? $data : [];
		}

		private static function extract_text_block( array $data, $error_code, $error_message ) {
			if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
				foreach ( $data['content'] as $block ) {
					if ( isset( $block['type'] ) && 'text' === $block['type'] && isset( $block['text'] ) ) {
						return trim( wp_strip_all_tags( (string) $block['text'] ) );
					}
				}
			}

			return new WP_Error( $error_code, $error_message );
		}
	}
}
