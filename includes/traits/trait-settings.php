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
    add_action( 'admin_init', [ $this, 'maybe_expire_debug_logging' ] );
    add_action( 'admin_post_' . PWATG::AJAX_TEST_PROVIDER, [ $this, 'handle_test_provider' ] );
    add_action( 'admin_post_' . PWATG::ACTION_DOWNLOAD_LOG, [ $this, 'handle_debug_log_download' ] );
    add_action( 'wp_ajax_' . PWATG::AJAX_DEBUG_READ_LOG, [ $this, 'ajax_debug_read_log' ] );
    add_action( 'wp_ajax_' . PWATG::AJAX_DEBUG_CLEAR_LOG, [ $this, 'ajax_debug_clear_log' ] );
    add_action( 'admin_notices', [ $this, 'render_debug_log_expired_notice' ] );
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

    // Untitled: the Settings tab already names the section.
    add_settings_section(
      'pwatg_main_section',
      '',
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
        __( 'AI Connector', 'presswell-alt-text-generator' ),
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

    add_settings_field(
      'remove_data_on_uninstall',
      __( 'Remove Data', 'presswell-alt-text-generator' ),
      [ $this, 'render_remove_data_field' ],
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
      // Saved values are 'on' or 'off', so a non-empty check would read 'off' as on.
      'debug_logging' => ( isset( $input['debug_logging'] ) && 'on' === $input['debug_logging'] ) ? 'on' : 'off',
      'remove_data_on_uninstall' => ( isset( $input['remove_data_on_uninstall'] ) && 'on' === $input['remove_data_on_uninstall'] ) ? 'on' : '',
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
        $sanitized['core_connector'] = $this->get_default_core_connector( $core_connectors );
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
    if ( ! isset( $service_models[ $sanitized['model'] ] ) ) {
      $sanitized['model'] = $this->get_default_model( $sanitized['service'] );
    }

    if ( empty( $sanitized['model'] ) ) {
      $sanitized['model'] = $defaults['model'];
    }

    if ( '' === trim( $sanitized['prompt_seed'] ) ) {
      $sanitized['prompt_seed'] = $defaults['prompt_seed'];
    }

    // Key fields render empty so saved keys never reach the page source; a blank
    // field keeps the saved key, and the Remove checkbox clears it.
    $stored          = ( is_multisite() && ! is_main_site() ) ? get_option( PWATG::SETTINGS_KEY, [] ) : $this->get_shared_option( PWATG::SETTINGS_KEY, [] );
    $stored_api_keys = is_array( $stored ) && isset( $stored['api_keys'] ) && is_array( $stored['api_keys'] ) ? $stored['api_keys'] : [];
    $raw_api_keys    = isset( $input['api_keys'] ) && is_array( $input['api_keys'] ) ? $input['api_keys'] : [];
    $remove_api_keys = isset( $input['api_keys_remove'] ) && is_array( $input['api_keys_remove'] ) ? $input['api_keys_remove'] : [];

    $sanitized['api_keys'] = $defaults['api_keys'];
    foreach ( array_keys( $defaults['api_keys'] ) as $service_key ) {
      $submitted = isset( $raw_api_keys[ $service_key ] ) && is_scalar( $raw_api_keys[ $service_key ] )
        ? trim( (string) wp_unslash( $raw_api_keys[ $service_key ] ) )
        : '';

      if ( ! empty( $remove_api_keys[ $service_key ] ) ) {
        $sanitized['api_keys'][ $service_key ] = '';
      } elseif ( '' !== $submitted ) {
        $sanitized['api_keys'][ $service_key ] = $submitted;
      } elseif ( isset( $stored_api_keys[ $service_key ] ) && is_scalar( $stored_api_keys[ $service_key ] ) ) {
        $sanitized['api_keys'][ $service_key ] = trim( (string) $stored_api_keys[ $service_key ] );
      }
    }

    if ( isset( $input['api_key'] ) && '' !== trim( (string) $input['api_key'] ) && '' === $sanitized['api_keys']['openai'] ) {
      $sanitized['api_keys']['openai'] = is_scalar( $input['api_key'] )
        ? trim( (string) wp_unslash( $input['api_key'] ) )
        : '';
    }

    $this->track_debug_logging_clock( 'on' === $sanitized['debug_logging'] );

    return $sanitized;
  }

  /**
   * Start the 7-day debug logging clock when logging is switched on, and clear it when off.
   *
   * @param bool $enabled Whether the saved settings turn logging on.
   */
  protected function track_debug_logging_clock( $enabled ) {
    // Only the main site's settings apply on Multisite.
    if ( is_multisite() && ! is_main_site() ) {
      return;
    }

    if ( ! $enabled ) {
      $this->delete_shared_option( PWATG::OPTION_DEBUG_LOG_ENABLED_AT );

      return;
    }

    $stored      = $this->get_shared_option( PWATG::SETTINGS_KEY, [] );
    $was_enabled = is_array( $stored ) && isset( $stored['debug_logging'] ) && 'on' === $stored['debug_logging'];

    if ( ! $was_enabled || ! $this->get_shared_option( PWATG::OPTION_DEBUG_LOG_ENABLED_AT ) ) {
      $this->update_shared_option( PWATG::OPTION_DEBUG_LOG_ENABLED_AT, time() );
      $this->delete_shared_option( PWATG::OPTION_DEBUG_LOG_EXPIRED );
    }
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
    $settings['debug_logging'] = ( isset( $settings['debug_logging'] ) && 'on' === $settings['debug_logging'] ) ? 'on' : 'off';

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
        $settings['core_connector'] = $this->get_default_core_connector( $core_connectors );
      }

      if ( 'core' === $settings['connector_source'] ) {
        $core_service = $this->get_core_service_for_connector( $settings['core_connector'] );
        if ( '' !== $core_service ) {
          $settings['service'] = $core_service;
        }
      }
    }

    // A saved model that has since been retired falls back to the provider's default.
    $service_models = $this->get_available_models( $settings['service'] );
    if ( empty( $settings['model'] ) || ! isset( $service_models[ $settings['model'] ] ) ) {
      $settings['model'] = $this->get_default_model( $settings['service'] );
    }

    return $settings;
  }

  /** Return the baseline defaults applied to new installs. */
  public function get_default_settings() {
    return [
      'service'       => 'openai',
      'model'         => $this->get_default_model( 'openai' ),
      'prompt_seed'   => 'Generate concise, specific alt text (8-20 words) for accessibility. Describe key visual details and avoid filler.',
      'api_keys'      => [
        'openai'    => '',
        'anthropic' => '',
        'gemini'    => '',
      ],
      // Use WordPress AI Connectors by default only when one already has a key.
      'connector_source' => $this->has_configured_core_connector() ? 'core' : 'plugin',
      'core_connector'   => '',
      'auto_generate' => 'on',
      'debug_logging' => 'off',
      'remove_data_on_uninstall' => '',
    ];
  }

  /** Whether WordPress core AI connectors compatible with this plugin are registered. */
  public function has_active_core_connectors() {
    return ! empty( $this->get_active_core_connector_choices() );
  }

  /** Whether any compatible core AI connector has an API key set up. */
  public function has_configured_core_connector() {
    foreach ( $this->get_active_core_connector_choices() as $connector ) {
      if ( ! empty( $connector['has_key'] ) ) {
        return true;
      }
    }

    return false;
  }

  /**
   * Pick the connector to use when none is saved: the first one with an API key.
   *
   * WordPress registers its connectors whether or not they're set up, so the first
   * registered one is often unusable.
   *
   * @param array $choices Result of get_active_core_connector_choices().
   *
   * @return string Connector ID, or '' when there are none.
   */
  public function get_default_core_connector( array $choices ) {
    foreach ( $choices as $connector_id => $connector ) {
      if ( ! empty( $connector['has_key'] ) ) {
        return (string) $connector_id;
      }
    }

    return (string) array_key_first( $choices );
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
        'has_key' => '' !== $this->get_api_key_for_core_connector( $connector_id ) || PWATG_WP_AI_Client_Service::is_provider_ready( $connector_id ),
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
      $selected = $this->get_default_core_connector( $core_connectors );
    }

    $connectors_url = admin_url( 'options-connectors.php' );
    ?>
    <div class="pwatg-core-connector-wrap">
      <select name="<?php echo esc_attr( PWATG::SETTINGS_KEY ); ?>[core_connector]" class="pwatg-core-connector-select">
        <?php foreach ( $core_connectors as $connector_id => $connector ) : ?>
          <option value="<?php echo esc_attr( $connector_id ); ?>" <?php selected( $selected, $connector_id ); ?>>
            <?php
            echo esc_html(
              empty( $connector['has_key'] )
                /* translators: %s: AI connector name */
                ? sprintf( __( '%s (no API key)', 'presswell-alt-text-generator' ), $connector['label'] )
                : $connector['label']
            );
            ?>
          </option>
        <?php endforeach; ?>
      </select>
      <p class="description pwatg-core-connector-message">
        <?php
        if ( PWATG_WP_AI_Client_Service::is_provider_ready( $selected ) ) {
          echo esc_html__( 'Requests go through the WordPress AI Client, using the key saved in Connectors.', 'presswell-alt-text-generator' );
        } else {
          echo esc_html__( "This connector's provider isn't available to the WordPress AI Client, so the plugin calls it directly with the connector's key.", 'presswell-alt-text-generator' );
        }
        ?>
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
   * Only vision-capable models that accept image input are listed. The first entry
   * is the provider's default.
   *
   * @param string $service Provider slug.
   *
   * @return array Model ID => label.
   */
  public function get_available_models( $service = 'openai' ) {
    $service = sanitize_key( $service );
    $all_models = [
      'openai' => [
        'gpt-4.1-mini' => 'GPT-4.1 mini',
        'gpt-4.1'      => 'GPT-4.1',
        'gpt-4o-mini'  => 'GPT-4o mini',
        'gpt-4o'       => 'GPT-4o',
      ],
      'anthropic' => [
        'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5',
        'claude-sonnet-5'           => 'Claude Sonnet 5',
        'claude-opus-5-5'           => 'Claude Opus 5.5',
      ],
      'gemini' => [
        'gemini-2.5-flash'      => 'Gemini 2.5 Flash',
        'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash-Lite',
        'gemini-2.5-pro'        => 'Gemini 2.5 Pro',
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

    /**
     * Filter the models offered for a provider.
     *
     * @param array  $models  Model ID => label. The first entry is the default.
     * @param string $service Provider slug.
     */
    $models = apply_filters( 'pwatg_available_models', $models, $service );

    return is_array( $models ) ? $models : [];
  }

  /**
   * The model a provider uses when none (or a retired one) is saved.
   *
   * @param string $service Provider slug.
   *
   * @return string
   */
  public function get_default_model( $service ) {
    $models = $this->get_available_models( $service );

    return ! empty( $models ) ? (string) array_key_first( $models ) : '';
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
        $service   = sanitize_key( $service );
        $saved_key = isset( $settings['api_keys'][ $service ] ) ? (string) $settings['api_keys'][ $service ] : '';
        $field_id  = 'pwatg-api-key-' . $service;
        ?>
        <div class="pwatg-api-key-wrap <?php echo $current === $service ? '' : 'is-hidden'; ?>" data-service="<?php echo esc_attr( $service ); ?>">
          <input
            type="password"
            id="<?php echo esc_attr( $field_id ); ?>"
            name="<?php echo esc_attr( PWATG::SETTINGS_KEY ); ?>[api_keys][<?php echo esc_attr( $service ); ?>]"
            value=""
            class="regular-text"
            autocomplete="new-password"
            <?php if ( '' !== $saved_key ) : ?>
              placeholder="<?php echo esc_attr( sprintf( /* translators: %s: last four characters of the saved key */ __( 'Saved key ending in %s', 'presswell-alt-text-generator' ), substr( $saved_key, -4 ) ) ); ?>"
            <?php endif; ?>
          />
          <p class="description">
            <?php
            echo esc_html(
              '' !== $saved_key
                /* translators: %s: AI service name */
                ? sprintf( __( 'A key for %s is saved. Leave this blank to keep it, or enter a new key to replace it.', 'presswell-alt-text-generator' ), $label )
                /* translators: %s: AI service name */
                : sprintf( __( 'API key for %s.', 'presswell-alt-text-generator' ), $label )
            );
            ?>
          </p>
          <?php if ( '' !== $saved_key ) : ?>
            <label>
              <input type="checkbox" name="<?php echo esc_attr( PWATG::SETTINGS_KEY ); ?>[api_keys_remove][<?php echo esc_attr( $service ); ?>]" value="1" />
              <?php echo esc_html__( 'Remove saved key', 'presswell-alt-text-generator' ); ?>
            </label>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
  }

  /** Output the model select control tied to the chosen provider. */
  public function render_model_field() {
    $settings    = $this->get_settings();
    $models      = $this->get_available_models( $settings['service'] );
    $model       = (string) $settings['model'];
    $stored      = $this->get_shared_option( PWATG::SETTINGS_KEY, [] );
    $saved_model = is_array( $stored ) && isset( $stored['model'] ) ? (string) $stored['model'] : '';
    ?>
    <select name="<?php echo esc_attr( PWATG::SETTINGS_KEY ); ?>[model]">
      <?php foreach ( $models as $value => $label ) : ?>
        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $model, $value ); ?>>
          <?php echo esc_html( $label ); ?>
        </option>
      <?php endforeach; ?>
    </select>
    <?php if ( '' !== $saved_model && $saved_model !== $model ) : ?>
      <p class="description pwatg-model-replaced">
        <?php
        echo esc_html(
          sprintf(
            /* translators: 1: saved model ID, 2: model now used */
            __( '%1$s is no longer available, so %2$s is being used. Save your settings to confirm.', 'presswell-alt-text-generator' ),
            $saved_model,
            isset( $models[ $model ] ) ? $models[ $model ] : $model
          )
        );
        ?>
      </p>
    <?php endif; ?>
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
    $debug_log_url = $this->get_debug_log_viewer_url();
    ?>
    <label>
      <input type="checkbox" name="<?php echo esc_attr( PWATG::SETTINGS_KEY ); ?>[debug_logging]" value="on" <?php checked( isset( $settings['debug_logging'] ) ? $settings['debug_logging'] : 'off', 'on' ); ?> />
      <?php echo esc_html__( 'Log plugin activity', 'presswell-alt-text-generator' ); ?>
      <?php if ( isset( $settings['debug_logging'] ) && 'on' === $settings['debug_logging'] ) : ?>
      <a href="<?php echo esc_url( $debug_log_url ); ?>"><?php echo esc_html__( 'View logs', 'presswell-alt-text-generator' ); ?></a>
      <?php endif; ?>
    </label>
    <!-- <p class="description"><?php echo esc_html__( 'Turns off automatically after 7 days. Logs are kept private and viewed from the Debug tab.', 'presswell-alt-text-generator' ); ?></p> -->
    <?php
  }

  /** Output the opt-in for removing plugin data when the plugin is deleted. */
  public function render_remove_data_field() {
    $settings = $this->get_settings();
    ?>
    <label>
      <input type="checkbox" name="<?php echo esc_attr( PWATG::SETTINGS_KEY ); ?>[remove_data_on_uninstall]" value="on" <?php checked( isset( $settings['remove_data_on_uninstall'] ) ? $settings['remove_data_on_uninstall'] : '', 'on' ); ?> />
      <?php echo esc_html__( 'Remove settings and generation history when the plugin is deleted', 'presswell-alt-text-generator' ); ?>
    </label>
    <p class="description"><?php echo esc_html__( 'API keys are always removed when the plugin is deleted. Alt text is never removed; it belongs to your images.', 'presswell-alt-text-generator' ); ?></p>
    <?php
  }

  /** Display the plugin settings page markup. */
  public function render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
      return;
    }

    $is_subsite = is_multisite() && ! is_main_site();

    $active_tab = 'debug' === sanitize_key( $this->get_query_param( 'tab' ) ) ? 'debug' : 'settings';

    $this->render_view(
      'settings-page.php',
      [
        'is_subsite'       => $is_subsite,
        'active_tab'       => $active_tab,
        'settings_tab_url' => admin_url( PWATG::SETTINGS_PAGE_URL ),
        'debug_tab_url'    => $this->get_debug_log_viewer_url(),
      ]
    );
  }

  /**
   * Stop a debug-tool request unless it comes from an administrator with a valid nonce.
   */
  protected function verify_debug_request() {
    if ( ! current_user_can( 'manage_options' ) ) {
      wp_send_json_error( [ 'message' => __( 'You do not have permission to do that.', 'presswell-alt-text-generator' ) ], 403 );
    }

    if ( ! check_ajax_referer( PWATG::NONCE_DEBUG, 'nonce', false ) ) {
      wp_send_json_error( [ 'message' => __( 'Security check failed.', 'presswell-alt-text-generator' ) ], 403 );
    }
  }

  /** AJAX: return the end of the debug log for the Debug tab. */
  public function ajax_debug_read_log() {
    $this->verify_debug_request();

    $tail = $this->read_debug_log_tail();

    wp_send_json_success(
      array_merge(
        $tail,
        [
          'enabled'    => $this->is_debug_logging_enabled(),
          'expires_at' => $this->get_debug_log_expires_at(),
        ]
      )
    );
  }

  /** AJAX: delete the debug log. */
  public function ajax_debug_clear_log() {
    $this->verify_debug_request();

    $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
    if ( 'POST' !== $method ) {
      wp_send_json_error( [ 'message' => __( 'This action must be sent as a POST request.', 'presswell-alt-text-generator' ) ], 405 );
    }

    wp_send_json_success( [ 'cleared' => $this->clear_debug_log() ] );
  }

  /** Stream the debug log to an administrator; the file itself is never served publicly. */
  public function handle_debug_log_download() {
    if ( ! current_user_can( 'manage_options' ) ) {
      wp_die( esc_html__( 'You do not have permission to do that.', 'presswell-alt-text-generator' ), '', [ 'response' => 403 ] );
    }

    check_admin_referer( PWATG::NONCE_DOWNLOAD_LOG );

    $log_path = $this->get_debug_log_path();

    if ( ! is_readable( $log_path ) ) {
      wp_die( esc_html__( 'The debug log is empty.', 'presswell-alt-text-generator' ), '', [ 'response' => 404 ] );
    }

    nocache_headers();
    header( 'Content-Type: text/plain; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="pwatg-debug-' . gmdate( 'Y-m-d' ) . '.log"' );
    header( 'Content-Length: ' . filesize( $log_path ) );

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming the plugin's own log file.
    readfile( $log_path );
    exit;
  }

  /** Show, once, that debug logging was switched off by the 7-day limit. */
  public function render_debug_log_expired_notice() {
    if ( ! current_user_can( 'manage_options' ) || ! $this->get_shared_option( PWATG::OPTION_DEBUG_LOG_EXPIRED ) ) {
      return;
    }

    $this->delete_shared_option( PWATG::OPTION_DEBUG_LOG_EXPIRED );

    echo wp_kses_post(
      $this->render_view_to_string(
        'admin-notice.php',
        [
          'class' => 'notice notice-warning is-dismissible',
          'text'  => __( 'Alt Text Generator debug logging was turned off automatically after 7 days. Turn it back on in Settings if you still need it.', 'presswell-alt-text-generator' ),
        ]
      )
    );
  }

  /**
   * Transient that carries a Test Connection result to the admin who ran it.
   *
   * @return string
   */
  public function get_test_provider_notice_key() {
    return PWATG::TRANSIENT_NOTICE_TEST_PROVIDER . '_' . get_current_user_id();
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

    // In Core mode on WordPress 7.0+, the AI Client holds the key; no key is needed here.
    $ai_client_provider = '' === $api_key ? $this->get_wp_ai_client_provider( $settings ) : '';

    // The key field is blank when a key is already saved, so test with the saved one.
    if ( '' === $api_key && '' === $ai_client_provider && '' !== $service ) {
      $api_key = $this->resolve_service_api_key( $service, $settings );
    }

    $this->debug_log(
      'Testing provider connection.',
      [
        'service'          => $service,
        'model'            => $model,
        'has_api_key'      => '' !== $api_key,
        'connector_source' => $connector_source,
        'transport'        => '' !== $ai_client_provider ? 'wp_ai_client' : 'direct',
      ]
    );

    if ( '' === $service || '' === $model || ( '' === $api_key && '' === $ai_client_provider ) ) {
      $this->debug_log(
        'Provider connection test rejected due to missing parameters.',
        [
          'service'          => $service,
          'model'            => $model,
          'connector_source' => $connector_source,
        ]
      );
      set_transient(
        $this->get_test_provider_notice_key(),
        [
          'type'    => 'error',
          'message' => __( 'Service, model, and API key are required to test the connection.', 'presswell-alt-text-generator' ),
        ],
        PWATG::TRANSIENT_NOTICE_TTL
      );

      wp_safe_redirect( PWATG::SETTINGS_PAGE_URL );
      exit;
    }

    $result = '' !== $ai_client_provider
      ? PWATG_WP_AI_Client_Service::request_text( $ai_client_provider, $model, 'Reply with: OK' )
      : $this->test_provider_connection( $service, $api_key, $model );

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
        $this->get_test_provider_notice_key(),
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
        $this->get_test_provider_notice_key(),
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
