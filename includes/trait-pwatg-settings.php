<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait PWATG_Settings_Trait {
	public function register_settings() {
		register_setting(
			'pwatg_settings_group',
			'pwatg_settings',
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
				'default'           => $this->get_default_settings(),
			]
		);

		add_settings_section(
			'pwatg_main_section',
			__( 'Settings', 'presswell-alt-text' ),
			'__return_false',
			'presswell-alt-text'
		);

		add_settings_field(
			'service',
			__( 'AI Service', 'presswell-alt-text' ),
			[ $this, 'render_service_field' ],
			'presswell-alt-text',
			'pwatg_main_section'
		);

		add_settings_field(
			'api_key',
			__( 'API Key', 'presswell-alt-text' ),
			[ $this, 'render_api_key_field' ],
			'presswell-alt-text',
			'pwatg_main_section'
		);

		add_settings_field(
			'model',
			__( 'Model', 'presswell-alt-text' ),
			[ $this, 'render_model_field' ],
			'presswell-alt-text',
			'pwatg_main_section'
		);

		add_settings_field(
			'prompt_seed',
			__( 'Prompt', 'presswell-alt-text' ),
			[ $this, 'render_prompt_seed_field' ],
			'presswell-alt-text',
			'pwatg_main_section'
		);

		add_settings_field(
			'auto_generate',
			__( 'Generate on Upload', 'presswell-alt-text' ),
			[ $this, 'render_auto_generate_field' ],
			'presswell-alt-text',
			'pwatg_main_section'
		);
	}

	public function sanitize_settings( $input ) {
		$defaults = $this->get_default_settings();
		$input    = is_array( $input ) ? $input : [];

		$sanitized = [
			'service'       => isset( $input['service'] ) ? sanitize_key( $input['service'] ) : $defaults['service'],
			'model'         => isset( $input['model'] ) ? sanitize_text_field( $input['model'] ) : $defaults['model'],
			'prompt_seed'   => isset( $input['prompt_seed'] ) ? sanitize_textarea_field( $input['prompt_seed'] ) : $defaults['prompt_seed'],
			'auto_generate' => ! empty( $input['auto_generate'] ) ? 1 : 0,
		];

		$allowed_services = array_keys( $this->get_available_services() );
		if ( ! in_array( $sanitized['service'], $allowed_services, true ) ) {
			$sanitized['service'] = $defaults['service'];
		}

		$service_models = $this->get_available_models( $sanitized['service'] );
		if ( empty( $service_models ) ) {
			$service_models = $this->get_available_models( $defaults['service'] );
		}
		$model_keys = array_keys( $service_models );
		if ( ! in_array( $sanitized['model'], $model_keys, true ) ) {
			$sanitized['model'] = isset( $model_keys[0] ) ? (string) $model_keys[0] : $defaults['model'];
		}

		if ( empty( $sanitized['model'] ) ) {
			$sanitized['model'] = $defaults['model'];
		}

		if ( '' === trim( $sanitized['prompt_seed'] ) ) {
			$sanitized['prompt_seed'] = $defaults['prompt_seed'];
		}

		$sanitized['api_keys'] = $defaults['api_keys'];
		$raw_api_keys          = isset( $input['api_keys'] ) && is_array( $input['api_keys'] ) ? $input['api_keys'] : [];
		foreach ( array_keys( $defaults['api_keys'] ) as $service_key ) {
			if ( isset( $raw_api_keys[ $service_key ] ) ) {
				$sanitized['api_keys'][ $service_key ] = sanitize_text_field( $raw_api_keys[ $service_key ] );
			}
		}

		if ( isset( $input['api_key'] ) && '' !== trim( (string) $input['api_key'] ) && '' === $sanitized['api_keys']['openai'] ) {
			$sanitized['api_keys']['openai'] = sanitize_text_field( $input['api_key'] );
		}

		return $sanitized;
	}

	public function get_settings() {
		$defaults = $this->get_default_settings();
		$settings = wp_parse_args( get_option( 'pwatg_settings', [] ), $defaults );

		if ( ! isset( $settings['api_keys'] ) || ! is_array( $settings['api_keys'] ) ) {
			$settings['api_keys'] = $defaults['api_keys'];
		}

		foreach ( array_keys( $defaults['api_keys'] ) as $service_key ) {
			if ( ! isset( $settings['api_keys'][ $service_key ] ) ) {
				$settings['api_keys'][ $service_key ] = '';
			}
		}

		if ( isset( $settings['api_key'] ) && '' !== trim( (string) $settings['api_key'] ) && '' === $settings['api_keys']['openai'] ) {
			$settings['api_keys']['openai'] = sanitize_text_field( $settings['api_key'] );
		}

		return $settings;
	}

	public function get_default_settings() {
		return [
			'service'       => 'openai',
			'model'         => 'gpt-4.1-mini',
			'prompt_seed'   => 'Generate concise, specific alt text (8-20 words) for accessibility. Describe key visual details and avoid filler.',
			'api_keys'      => [
				'openai'    => '',
				'anthropic' => '',
				'gemini'    => '',
			],
			'auto_generate' => 1,
		];
	}

	public function get_available_services() {
		$labels = [
			'openai'    => __( 'OpenAI', 'presswell-alt-text' ),
			'anthropic' => __( 'Anthropic', 'presswell-alt-text' ),
			'gemini'    => __( 'Google Gemini', 'presswell-alt-text' ),
		];

		$services = [];
		foreach ( array_keys( Presswell_Alt_Text_Generator::PROVIDER_MAP ) as $service_key ) {
			$services[ $service_key ] = isset( $labels[ $service_key ] )
				? $labels[ $service_key ]
				: ucwords( str_replace( '-', ' ', $service_key ) );
		}

		return apply_filters( 'pwatg_available_services', $services );
	}

	public function get_available_models( $service = 'openai' ) {
		$service = sanitize_key( $service );
		$all_models = [
			'openai' => [
				'gpt-4.1-mini' => 'gpt-4.1-mini',
				'gpt-4.1'      => 'gpt-4.1',
				'gpt-4o-mini'  => 'gpt-4o-mini',
			],
			'anthropic' => [
				'claude-3-5-haiku-latest'  => 'claude-3-5-haiku-latest',
				'claude-3-7-sonnet-latest' => 'claude-3-7-sonnet-latest',
			],
			'gemini' => [
				'gemini-2.0-flash' => 'gemini-2.0-flash',
				'gemini-1.5-flash' => 'gemini-1.5-flash',
			],
		];

		$provider_keys = array_keys( Presswell_Alt_Text_Generator::PROVIDER_MAP );
		$map           = array_intersect_key( $all_models, array_flip( $provider_keys ) );

		if ( empty( $map ) ) {
			$map = [
				'openai' => $all_models['openai'],
			];
		}

		$fallback_key = isset( $map['openai'] ) ? 'openai' : array_key_first( $map );
		$models       = isset( $map[ $service ] ) ? $map[ $service ] : $map[ $fallback_key ];

		return apply_filters( 'pwatg_available_models', $models );
	}

	public function render_service_field() {
		$settings = $this->get_settings();
		$services = $this->get_available_services();
		?>
		<select name="<?php echo esc_attr( 'pwatg_settings' ); ?>[service]">
			<?php foreach ( $services as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['service'], $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public function render_api_key_field() {
		$settings = $this->get_settings();
		$services = $this->get_available_services();
		$current  = isset( $settings['service'] ) ? sanitize_key( $settings['service'] ) : 'openai';
		?>
		<?php foreach ( $services as $service => $label ) : ?>
			<?php
			$service = sanitize_key( $service );
			$value   = isset( $settings['api_keys'][ $service ] ) ? (string) $settings['api_keys'][ $service ] : '';
			?>
			<div class="pwatg-api-key-wrap <?php echo $current === $service ? '' : 'is-hidden'; ?>" data-service="<?php echo esc_attr( $service ); ?>">
				<input
					type="password"
					name="<?php echo esc_attr( 'pwatg_settings' ); ?>[api_keys][<?php echo esc_attr( $service ); ?>]"
					value="<?php echo esc_attr( $value ); ?>"
					class="regular-text"
					autocomplete="off"
				/>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: AI service name */
							__( 'API key for %s.', 'presswell-alt-text' ),
							$label
						)
					);
					?>
				</p>
			</div>
		<?php endforeach; ?>
		<?php
	}

	public function render_model_field() {
		$settings = $this->get_settings();
		$models   = $this->get_available_models( $settings['service'] );
		$model    = (string) $settings['model'];
		?>
		<select name="<?php echo esc_attr( 'pwatg_settings' ); ?>[model]">
			<?php foreach ( $models as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $model, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public function render_prompt_seed_field() {
		$settings = $this->get_settings();
		?>
		<textarea name="<?php echo esc_attr( 'pwatg_settings' ); ?>[prompt_seed]" rows="4" cols="50" class="large-text"><?php echo esc_textarea( $settings['prompt_seed'] ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Base instruction prepended to each request. Keep it concise and accessibility-focused.', 'presswell-alt-text' ); ?></p>
		<?php
	}

	public function render_auto_generate_field() {
		$settings = $this->get_settings();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( 'pwatg_settings' ); ?>[auto_generate]" value="1" <?php checked( ! empty( $settings['auto_generate'] ) ); ?> />
			<?php esc_html_e( 'Generate alt text automatically when an image is uploaded.', 'presswell-alt-text' ); ?>
		</label>
		<?php
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		require $this->get_plugin_path( 'includes/views/settings-page.php' );
	}

	public function handle_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'presswell-alt-text' ) );
		}

		check_admin_referer( 'pwatg_test_connection', 'pwatg_test_connection_nonce' );

		$service = isset( $_POST['service'] ) ? sanitize_key( wp_unslash( $_POST['service'] ) ) : '';
		$model   = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

		if ( '' === $service || '' === $model || '' === $api_key ) {
			set_transient(
				'pwatg_test_notice',
				[
					'type'    => 'error',
					'message' => __( 'Service, model, and API key are required to test the connection.', 'presswell-alt-text' ),
				],
				60
			);

			wp_safe_redirect( admin_url( 'options-general.php?page=presswell-alt-text-generator' ) );
			exit;
		}

		$result = $this->test_provider_connection( $service, $api_key, $model );

		if ( is_wp_error( $result ) ) {
			set_transient(
				'pwatg_test_notice',
				[
					'type'    => 'error',
					'message' => sprintf(
						/* translators: %s: provider error message */
						__( 'Connection failed: %s', 'presswell-alt-text' ),
						$result->get_error_message()
					),
				],
				60
			);
		} else {
			$response_text = sanitize_text_field( (string) $result );
			if ( '' !== $response_text && mb_strlen( $response_text ) > 120 ) {
				$response_text = mb_substr( $response_text, 0, 120 ) . '…';
			}

			$message = __( 'Connection successful.', 'presswell-alt-text' );
			if ( '' !== $response_text ) {
				$message = sprintf(
					/* translators: %s: provider response text */
					__( 'Connection successful. Response: %s', 'presswell-alt-text' ),
					$response_text
				);
			}

			set_transient(
				'pwatg_test_notice',
				[
					'type'    => 'success',
					'message' => $message,
				],
				60
			);
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=presswell-alt-text-generator' ) );
		exit;
	}
}
