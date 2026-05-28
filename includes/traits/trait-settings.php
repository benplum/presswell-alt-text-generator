<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Settings registration, sanitization, and UI rendering helpers.
 */
trait PWATG_Settings_Trait {
  /** Attach settings-related hooks. */
  protected function construct_settings_trait() {
    add_action( 'admin_init', [ $this, 'register_settings' ] );
    add_action( 'admin_post_' . PWATG::AJAX_TEST_PROVIDER, [ $this, 'handle_test_provider' ] );
  }

  /** Register the plugin option, fields, and section definitions. */
  public function register_settings() {
    register_setting(
      'pwatg_settings_group',
      PWATG::SETTINGS_KEY,
      [
        'type'              => 'array',
        'sanitize_callback' => [ $this, 'sanitize_settings' ],
        'default'           => $this->get_default_settings(),
      ]
    );

    add_settings_section(
      'pwatg_main_section',
      __( 'Settings', 'presswell-alt-text-generator' ),
      '__return_false',
      PWATG::SETTINGS_PAGE_SLUG
    );

    if ( $this->has_active_core_connectors() ) {
      add_settings_field(
        'connector_source',
        __( 'Connector', 'presswell-alt-text-generator' ),
        [ $this, 'render_connector_source_field' ],
        PWATG::SETTINGS_PAGE_SLUG,
        'pwatg_main_section'
      );

      add_settings_field(
        'core_connector',
        __( 'AI Service', 'presswell-alt-text-generator' ),
        [ $this, 'render_core_connector_field' ],
        PWATG::SETTINGS_PAGE_SLUG,
        'pwatg_main_section'
      );
    }

    add_settings_field(
      'service',
      __( 'AI Service', 'presswell-alt-text-generator' ),
      [ $this, 'render_service_field' ],
      PWATG::SETTINGS_PAGE_SLUG,
      'pwatg_main_section'
    );

    add_settings_field(
      'api_key',
      __( 'API Key', 'presswell-alt-text-generator' ),
      [ $this, 'render_api_key_field' ],
      PWATG::SETTINGS_PAGE_SLUG,
      'pwatg_main_section'
    );

    add_settings_field(
      'model',
      __( 'Model', 'presswell-alt-text-generator' ),
      [ $this, 'render_model_field' ],
      PWATG::SETTINGS_PAGE_SLUG,
      'pwatg_main_section'
    );

    add_settings_field(
      'prompt_seed',
      __( 'Prompt', 'presswell-alt-text-generator' ),
      [ $this, 'render_prompt_seed_field' ],
      PWATG::SETTINGS_PAGE_SLUG,
      'pwatg_main_section'
    );

    add_settings_field(
      'auto_generate',
      __( 'Generate on Upload', 'presswell-alt-text-generator' ),
      [ $this, 'render_auto_generate_field' ],
      PWATG::SETTINGS_PAGE_SLUG,
      'pwatg_main_section'
    );

    add_settings_field(
      'debug_logging',
      __( 'Debug Logging', 'presswell-alt-text-generator' ),
      [ $this, 'render_debug_logging_field' ],
      PWATG::SETTINGS_PAGE_SLUG,
      'pwatg_main_section'
    );
  }

  /**
   * Validate and clean the settings payload saved via the Settings API.
   *
   * @param array $input Raw option array.
   *
   * @return array
   */
  public function sanitize_settings( $input ) {
    $defaults = $this->get_default_settings();
    $input    = is_array( $input ) ? $input : [];

    $sanitized = [
      'service'       => isset( $input['service'] ) ? sanitize_key( $input['service'] ) : $defaults['service'],
      'model'         => isset( $input['model'] ) ? sanitize_text_field( $input['model'] ) : $defaults['model'],
      'prompt_seed'   => isset( $input['prompt_seed'] ) ? sanitize_textarea_field( $input['prompt_seed'] ) : $defaults['prompt_seed'],
      'auto_generate' => ! empty( $input['auto_generate'] ) ? 'on' : '',
      'debug_logging' => ! empty( $input['debug_logging'] ) ? 'on' : 'off',
      'connector_source' => isset( $input['connector_source'] ) ? sanitize_key( $input['connector_source'] ) : $defaults['connector_source'],
      'core_connector'   => isset( $input['core_connector'] ) ? sanitize_key( $input['core_connector'] ) : $defaults['core_connector'],
    ];

    if ( ! in_array( $sanitized['connector_source'], [ 'plugin', 'core' ], true ) ) {
      $sanitized['connector_source'] = $defaults['connector_source'];
    }

    $core_connectors = $this->get_active_core_connector_choices();
    if ( empty( $core_connectors ) ) {
      $sanitized['connector_source'] = 'plugin';
      $sanitized['core_connector']   = '';
    } else {
      if ( '' === $sanitized['core_connector'] || ! isset( $core_connectors[ $sanitized['core_connector'] ] ) ) {
        $sanitized['core_connector'] = (string) array_key_first( $core_connectors );
      }

      if ( 'core' === $sanitized['connector_source'] ) {
        $core_service = $this->get_core_service_for_connector( $sanitized['core_connector'] );
        if ( '' !== $core_service ) {
          $sanitized['service'] = $core_service;
        }
      }
    }

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
        $sanitized['api_keys'][ $service_key ] = is_scalar( $raw_api_keys[ $service_key ] )
          ? trim( (string) wp_unslash( $raw_api_keys[ $service_key ] ) )
          : '';
      }
    }

    if ( isset( $input['api_key'] ) && '' !== trim( (string) $input['api_key'] ) && '' === $sanitized['api_keys']['openai'] ) {
      $sanitized['api_keys']['openai'] = is_scalar( $input['api_key'] )
        ? trim( (string) wp_unslash( $input['api_key'] ) )
        : '';
    }

    return $sanitized;
  }

  /** Retrieve saved settings merged with defaults. */
  public function get_settings() {
    $defaults = $this->get_default_settings();
    if ( is_multisite() ) {
      $main_site_id = get_main_site_id();
      $raw_settings = function_exists('get_blog_option')
        ? get_blog_option( $main_site_id, PWATG::SETTINGS_KEY, [] )
        : get_option( PWATG::SETTINGS_KEY, [] );
      $settings = wp_parse_args( $raw_settings, $defaults );
    } else {
      $settings = wp_parse_args( get_option( PWATG::SETTINGS_KEY, [] ), $defaults );
    }

    if ( ! isset( $settings['api_keys'] ) || ! is_array( $settings['api_keys'] ) ) {
      $settings['api_keys'] = $defaults['api_keys'];
    }

    foreach ( array_keys( $defaults['api_keys'] ) as $service_key ) {
      if ( ! isset( $settings['api_keys'][ $service_key ] ) ) {
        $settings['api_keys'][ $service_key ] = '';
      }
    }

    if ( isset( $settings['api_key'] ) && '' !== trim( (string) $settings['api_key'] ) && '' === $settings['api_keys']['openai'] ) {
      $settings['api_keys']['openai'] = is_scalar( $settings['api_key'] )
        ? trim( (string) $settings['api_key'] )
        : '';
    }

    $settings['auto_generate'] = ! empty( $settings['auto_generate'] ) ? 'on' : '';
    $settings['debug_logging'] = ! empty( $settings['debug_logging'] ) ? 'on' : 'off';

    $settings['connector_source'] = isset( $settings['connector_source'] ) ? sanitize_key( (string) $settings['connector_source'] ) : 'plugin';
    if ( ! in_array( $settings['connector_source'], [ 'plugin', 'core' ], true ) ) {
      $settings['connector_source'] = 'plugin';
    }

    $core_connectors = $this->get_active_core_connector_choices();
    if ( empty( $core_connectors ) ) {
      $settings['connector_source'] = 'plugin';
      $settings['core_connector']   = '';
    } else {
      $settings['core_connector'] = isset( $settings['core_connector'] ) ? sanitize_key( (string) $settings['core_connector'] ) : '';
      if ( '' === $settings['core_connector'] || ! isset( $core_connectors[ $settings['core_connector'] ] ) ) {
        $settings['core_connector'] = (string) array_key_first( $core_connectors );
      }

      if ( 'core' === $settings['connector_source'] ) {
        $core_service = $this->get_core_service_for_connector( $settings['core_connector'] );
        if ( '' !== $core_service ) {
          $settings['service'] = $core_service;
        }
      }
    }

    $service_models = $this->get_available_models( $settings['service'] );
    $model_keys     = array_keys( $service_models );
    if ( empty( $settings['model'] ) || ! in_array( $settings['model'], $model_keys, true ) ) {
      $settings['model'] = isset( $model_keys[0] ) ? (string) $model_keys[0] : $defaults['model'];
    }

    return $settings;
  }

  /** Return the baseline defaults applied to new installs. */
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
      'connector_source' => 'core',
      'core_connector'   => '',
      'auto_generate' => 'on',
      'debug_logging' => 'off',
    ];
  }

  /** Whether WordPress core AI connectors compatible with this plugin are registered. */
  public function has_active_core_connectors() {
    return ! empty( $this->get_active_core_connector_choices() );
  }

  /**
   * Return registered core AI connectors compatible with this plugin.
   *
   * @return array
   */
  public function get_active_core_connector_choices() {
    if ( ! function_exists( 'wp_get_connectors' ) ) {
      return [];
    }

    $choices = [];
    foreach ( wp_get_connectors() as $connector_id => $connector ) {
      $connector_id = sanitize_key( (string) $connector_id );
      if ( '' === $connector_id || ! is_array( $connector ) ) {
        continue;
      }

      if ( ! isset( $connector['type'] ) || 'ai_provider' !== $connector['type'] ) {
        continue;
      }

      $service = $this->get_core_service_for_connector( $connector_id );
      if ( '' === $service ) {
        continue;
      }

      $name = isset( $connector['name'] ) && is_string( $connector['name'] ) && '' !== trim( $connector['name'] )
        ? trim( $connector['name'] )
        : ucfirst( $connector_id );

      $choices[ $connector_id ] = [
        'label'   => $name,
        'service' => $service,
      ];
    }

    return $choices;
  }

  /** Map a core connector ID to a local provider slug. */
  public function get_core_service_for_connector( $connector_id ) {
    $connector_id = sanitize_key( (string) $connector_id );
    if ( '' === $connector_id ) {
      return '';
    }

    $map = [
      'openai'    => 'openai',
      'anthropic' => 'anthropic',
      'google'    => 'gemini',
      'gemini'    => 'gemini',
    ];

    $map = apply_filters( 'pwatg_core_connector_service_map', $map );
    if ( ! isset( $map[ $connector_id ] ) ) {
      return '';
    }

    $service           = sanitize_key( (string) $map[ $connector_id ] );
    $supported_service = array_keys( $this->get_available_services() );

    return in_array( $service, $supported_service, true ) ? $service : '';
  }

  /**
   * Resolve a core connector API key from env, constants, or options.
   *
   * @param string $connector_id Core connector ID.
   *
   * @return string
   */
  public function get_api_key_for_core_connector( $connector_id ) {
    $connector_id = sanitize_key( (string) $connector_id );
    if ( '' === $connector_id || ! function_exists( 'wp_get_connector' ) ) {
      return '';
    }

    $connector = wp_get_connector( $connector_id );
    if ( ! is_array( $connector ) ) {
      return '';
    }

    $auth = isset( $connector['authentication'] ) && is_array( $connector['authentication'] )
      ? $connector['authentication']
      : [];

    if ( isset( $auth['env_var_name'] ) && is_string( $auth['env_var_name'] ) && '' !== $auth['env_var_name'] ) {
      $env_value = getenv( $auth['env_var_name'] );
      if ( is_string( $env_value ) && '' !== trim( $env_value ) ) {
        return trim( $env_value );
      }
    }

    if ( isset( $auth['constant_name'] ) && is_string( $auth['constant_name'] ) && '' !== $auth['constant_name'] && defined( $auth['constant_name'] ) ) {
      $constant_value = constant( $auth['constant_name'] );
      if ( is_string( $constant_value ) && '' !== trim( $constant_value ) ) {
        return trim( $constant_value );
      }
    }

    $setting_name = isset( $auth['setting_name'] ) && is_string( $auth['setting_name'] ) && '' !== $auth['setting_name']
      ? $auth['setting_name']
      : 'connectors_ai_' . str_replace( '-', '_', $connector_id ) . '_api_key';

    return $this->get_connector_setting_option( $setting_name );
  }

  /** Read connector option values from the effective site context. */
  protected function get_connector_setting_option( $setting_name ) {
    $setting_name = (string) $setting_name;
    if ( '' === $setting_name ) {
      return '';
    }

    if ( is_multisite() && function_exists( 'get_blog_option' ) ) {
      $raw_value = get_blog_option( get_main_site_id(), $setting_name, '' );
    } else {
      $raw_value = get_option( $setting_name, '' );
    }

    return is_string( $raw_value ) ? trim( $raw_value ) : '';
  }

  /** Output connector source radios when core connectors are available. */
  public function render_connector_source_field() {
    $settings = $this->get_settings();
    $source   = isset( $settings['connector_source'] ) ? sanitize_key( $settings['connector_source'] ) : 'plugin';
    ?>
    <fieldset class="pwatg-connector-source-group" role="radiogroup" aria-label="<?php echo esc_attr__( 'Connector source', 'presswell-alt-text-generator' ); ?>">
      <label class="pwatg-connector-source-option">
        <input type="radio" name="<?php echo esc_attr( PWATG::SETTINGS_KEY ); ?>[connector_source]" value="plugin" <?php checked( $source, 'plugin' ); ?> />
        <span class="button"><?php echo esc_html__( 'Plugin', 'presswell-alt-text-generator' ); ?></span>
      </label>
      <label class="pwatg-connector-source-option">
        <input type="radio" name="<?php echo esc_attr( PWATG::SETTINGS_KEY ); ?>[connector_source]" value="core" <?php checked( $source, 'core' ); ?> />
        <span class="button"><?php echo esc_html__( 'Core', 'presswell-alt-text-generator' ); ?></span>
      </label>
    </fieldset>
    <?php
  }

  /** Output selectable active core connectors and a settings link. */
  public function render_core_connector_field() {
    $settings        = $this->get_settings();
    $core_connectors = $this->get_active_core_connector_choices();
    $selected        = isset( $settings['core_connector'] ) ? sanitize_key( (string) $settings['core_connector'] ) : '';
    if ( '' === $selected || ! isset( $core_connectors[ $selected ] ) ) {
      $selected = (string) array_key_first( $core_connectors );
    }

    $connectors_url = admin_url( 'options-connectors.php' );
    ?>
    <div class="pwatg-core-connector-wrap">
      <select name="<?php echo esc_attr( PWATG::SETTINGS_KEY ); ?>[core_connector]" class="pwatg-core-connector-select">
        <?php foreach ( $core_connectors as $connector_id => $connector ) : ?>
          <option value="<?php echo esc_attr( $connector_id ); ?>" <?php selected( $selected, $connector_id ); ?>>
            <?php echo esc_html( $connector['label'] ); ?>
          </option>
        <?php endforeach; ?>
      </select>
      <p class="description pwatg-core-connector-message">
        <a href="<?php echo esc_url( $connectors_url ); ?>"><?php echo esc_html__( 'Configure AI Connectors', 'presswell-alt-text-generator' ); ?></a>
      </p>
    </div>
    <?php
  }

  /** List human-friendly provider labels keyed by slug. */
  public function get_available_services() {
    $labels = [
      'openai'    => __( 'OpenAI', 'presswell-alt-text-generator' ),
      'anthropic' => __( 'Anthropic', 'presswell-alt-text-generator' ),
      'gemini'    => __( 'Google Gemini', 'presswell-alt-text-generator' ),
    ];

    $services = [];
    foreach ( array_keys( PWATG::PROVIDER_MAP ) as $service_key ) {
      $services[ $service_key ] = isset( $labels[ $service_key ] )
        ? $labels[ $service_key ]
        : ucwords( str_replace( '-', ' ', $service_key ) );
    }

    return apply_filters( 'pwatg_available_services', $services );
  }

  /**
   * Return the selectable models for a given provider.
   *
   * @param string $service Provider slug.
   *
   * @return array
   */
  public function get_available_models( $service = 'openai' ) {
    $service = sanitize_key( $service );
    $all_models = [
      'openai' => [
        'gpt-4o'         => 'gpt-4o',
        'gpt-4-turbo'    => 'gpt-4-turbo',
        'gpt-4.1'        => 'gpt-4.1',
        'gpt-4.1-mini'   => 'gpt-4.1-mini',
        'gpt-4o-mini'    => 'gpt-4o-mini',
        // 'dalle-3'        => 'dalle-3',
      ],
      'anthropic' => [
        'claude-3-opus'     => 'claude-3-opus',
        'claude-3-sonnet'   => 'claude-3-sonnet',
        'claude-3-haiku'    => 'claude-3-haiku',
        // 'claude-3-5-haiku-20241022'  => 'claude-3-5-haiku-20241022',
        // 'claude-3-5-sonnet-20240620' => 'claude-3-5-sonnet-20240620',
        // 'claude-3-opus-20240229'     => 'claude-3-opus-20240229',
      ],
      'gemini' => [
        'gemini-1.5-pro'    => 'gemini-1.5-pro',
        'gemini-1.5-flash'  => 'gemini-1.5-flash',
        'gemini-2.0-flash'      => 'gemini-2.0-flash',
        'gemini-2.0-flash-lite' => 'gemini-2.0-flash-lite',
        // 'imagen-2'          => 'imagen-2',
      ],
    ];

    $provider_keys = array_keys( PWATG::PROVIDER_MAP );
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

  /** Output the provider select control. */
  public function render_service_field() {
    $settings = $this->get_settings();
    $services = $this->get_available_services();
    ?>
    <select name="<?php echo esc_attr( PWATG::SETTINGS_KEY ); ?>[service]">
      <?php foreach ( $services as $value => $label ) : ?>
        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['service'], $value ); ?>>
          <?php echo esc_html( $label ); ?>
        </option>
      <?php endforeach; ?>
    </select>
    <?php
  }

  /** Render grouped API key inputs per provider. */
  public function render_api_key_field() {
    $settings = $this->get_settings();
    $services = $this->get_available_services();
    $current  = isset( $settings['service'] ) ? sanitize_key( $settings['service'] ) : 'openai';
    ?>
    <div class="pwatg-plugin-api-key-group">
      <?php foreach ( $services as $service => $label ) : ?>
        <?php
        $service = sanitize_key( $service );
        $value   = isset( $settings['api_keys'][ $service ] ) ? (string) $settings['api_keys'][ $service ] : '';
        ?>
        <div class="pwatg-api-key-wrap <?php echo $current === $service ? '' : 'is-hidden'; ?>" data-service="<?php echo esc_attr( $service ); ?>">
          <input
            type="password"
            name="<?php echo esc_attr( PWATG::SETTINGS_KEY ); ?>[api_keys][<?php echo esc_attr( $service ); ?>]"
            value="<?php echo esc_attr( $value ); ?>"
            class="regular-text"
            autocomplete="off"
          />
          <p class="description">
            <?php
            echo esc_html(
              sprintf(
                /* translators: %s: AI service name */
                  __( 'API key for %s.', 'presswell-alt-text-generator' ),
                $label
              )
            );
            ?>
          </p>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
  }

  /** Output the model select control tied to the chosen provider. */
  public function render_model_field() {
    $settings = $this->get_settings();
    $models   = $this->get_available_models( $settings['service'] );
    $model    = (string) $settings['model'];
    ?>
    <select name="<?php echo esc_attr( PWATG::SETTINGS_KEY ); ?>[model]">
      <?php foreach ( $models as $value => $label ) : ?>
        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $model, $value ); ?>>
          <?php echo esc_html( $label ); ?>
        </option>
      <?php endforeach; ?>
    </select>
    <?php
  }

  /** Render textarea for the prompt seed configuration. */
  public function render_prompt_seed_field() {
    $settings = $this->get_settings();
    ?>
    <textarea name="<?php echo esc_attr( PWATG::SETTINGS_KEY ); ?>[prompt_seed]" rows="4" cols="50" class="large-text"><?php echo esc_textarea( $settings['prompt_seed'] ); ?></textarea>
    <p class="description"><?php echo esc_html__( 'Base instruction prepended to each request. Keep it concise and accessibility-focused.', 'presswell-alt-text-generator' ); ?></p>
    <?php
  }

  /** Output checkbox toggle for auto-generation setting. */
  public function render_auto_generate_field() {
    $settings = $this->get_settings();
    ?>
    <label>
      <input type="checkbox" name="<?php echo esc_attr( PWATG::SETTINGS_KEY ); ?>[auto_generate]" value="on" <?php checked( ! empty( $settings['auto_generate'] ) ); ?> />
      <?php echo esc_html__( 'Generate alt text automatically when an image is uploaded.', 'presswell-alt-text-generator' ); ?>
    </label>
    <?php
  }

  /** Output checkbox toggle for debug logging setting. */
  public function render_debug_logging_field() {
    $settings      = $this->get_settings();
    $debug_log_url = $this->get_debug_log_url();
    ?>
    <label>
      <input type="checkbox" name="<?php echo esc_attr( PWATG::SETTINGS_KEY ); ?>[debug_logging]" value="on" <?php checked( isset( $settings['debug_logging'] ) ? $settings['debug_logging'] : 'off', 'on' ); ?> />
      <?php echo esc_html__( 'Log plugin activity', 'presswell-alt-text-generator' ); ?>
      <?php if ( isset( $settings['debug_logging'] ) && 'on' === $settings['debug_logging'] ) : ?>
      <a href="<?php echo esc_url( $debug_log_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'View logs', 'presswell-alt-text-generator' ); ?></a>
      <?php endif; ?>
    </label>
    <?php
  }

  /** Display the plugin settings page markup. */
  public function render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
      return;
    }

    if ( is_multisite() && ! is_main_site() ) {
      echo '<div class="notice notice-info"><p>' . esc_html__( 'Settings are inherited from the main site. Changes here will not take effect.', 'presswell-alt-text-generator' ) . '</p></div>';
    }

    $this->render_view( 'settings-page.php' );
  }

  /** Process the "Test Connection" helper form. */
  public function handle_test_provider() {
    if ( ! current_user_can( 'manage_options' ) ) {
      wp_die( esc_html__( 'You do not have permission to do that.', 'presswell-alt-text-generator' ) );
    }

    check_admin_referer( PWATG::AJAX_TEST_PROVIDER, 'pwatg_test_provider_nonce' );

    $service = isset( $_POST['service'] ) ? sanitize_key( wp_unslash( $_POST['service'] ) ) : '';
    $model   = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
    $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

    $settings         = $this->get_settings();
    $connector_source = isset( $settings['connector_source'] ) ? sanitize_key( (string) $settings['connector_source'] ) : 'plugin';
    if ( ! in_array( $connector_source, [ 'plugin', 'core' ], true ) ) {
      $connector_source = 'plugin';
    }

    if ( '' === $api_key ) {
      if ( 'core' === ( isset( $settings['connector_source'] ) ? sanitize_key( (string) $settings['connector_source'] ) : 'plugin' ) ) {
        $core_connector = isset( $settings['core_connector'] ) ? sanitize_key( (string) $settings['core_connector'] ) : '';
        $core_service   = $this->get_core_service_for_connector( $core_connector );
        if ( '' !== $core_service ) {
          $service = $core_service;
        }
        $api_key = $this->get_api_key_for_core_connector( $core_connector );
      }
    }

    $this->debug_log(
      'Testing provider connection.',
      [
        'service'          => $service,
        'model'            => $model,
        'has_api_key'      => '' !== $api_key,
        'connector_source' => $connector_source,
      ]
    );

    if ( '' === $service || '' === $model || '' === $api_key ) {
      $this->debug_log(
        'Provider connection test rejected due to missing parameters.',
        [
          'service'          => $service,
          'model'            => $model,
          'connector_source' => $connector_source,
        ]
      );
      set_transient(
        PWATG::TRANSIENT_NOTICE_TEST_PROVIDER,
        [
          'type'    => 'error',
          'message' => __( 'Service, model, and API key are required to test the connection.', 'presswell-alt-text-generator' ),
        ],
        PWATG::TRANSIENT_NOTICE_TTL
      );

      wp_safe_redirect( PWATG::SETTINGS_PAGE_URL );
      exit;
    }

    $result = $this->test_provider_connection( $service, $api_key, $model );

    if ( is_wp_error( $result ) ) {
      $this->debug_log(
        'Provider connection test failed.',
        [
          'service'          => $service,
          'model'            => $model,
          'connector_source' => $connector_source,
          'code'             => $result->get_error_code(),
          'message'          => $result->get_error_message(),
        ]
      );
      set_transient(
        PWATG::TRANSIENT_NOTICE_TEST_PROVIDER,
        [
          'type'    => 'error',
          'message' => sprintf(
            /* translators: %s: provider error message */
            __( 'Connection failed: %s', 'presswell-alt-text-generator' ),
            $result->get_error_message()
          ),
        ],
        PWATG::TRANSIENT_NOTICE_TTL
      );
    } else {
      $this->debug_log(
        'Provider connection test succeeded.',
        [
          'service'          => $service,
          'model'            => $model,
          'connector_source' => $connector_source,
        ]
      );
      $response_text = sanitize_text_field( (string) $result );
      if ( '' !== $response_text && mb_strlen( $response_text ) > 120 ) {
        $response_text = mb_substr( $response_text, 0, 120 ) . '...';
      }

      $message = __( 'Connection successful.', 'presswell-alt-text-generator' );
      if ( '' !== $response_text ) {
        $message = sprintf(
          /* translators: %s: provider response text */
          __( 'Connection successful. Response: %s', 'presswell-alt-text-generator' ),
          $response_text
        );
      }

      set_transient(
        PWATG::TRANSIENT_NOTICE_TEST_PROVIDER,
        [
          'type'    => 'success',
          'message' => $message,
        ],
        PWATG::TRANSIENT_NOTICE_TTL
      );
    }

    wp_safe_redirect( PWATG::SETTINGS_PAGE_URL );
    exit;
  }
}
