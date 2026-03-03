<?php
/**
 * Plugin Name: Presswell Alt Text Generator
 * Description: Generate AI-powered alt text for WordPress media.
 * Version: 1.0.0
 * Author: Ben Plum
 * License: GPLv2 or later
 * Text Domain: presswell-alt-text-generator
 *
 * @package Presswell Alt Text Generator
 * @author Presswell
 *
 * Presswell Alt Text Generator is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 *
 * Presswell Alt Text Generator is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Presswell Alt Text Generator. If not, see <http://www.gnu.org/licenses/>.
*/

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

require_once __DIR__ . '/includes/helpers/class-constants.php';
require_once __DIR__ . '/includes/traits/trait-assets.php';
require_once __DIR__ . '/includes/traits/trait-bulk.php';
require_once __DIR__ . '/includes/traits/trait-helpers.php';
require_once __DIR__ . '/includes/traits/trait-media.php';
require_once __DIR__ . '/includes/traits/trait-pages.php';
require_once __DIR__ . '/includes/traits/trait-providers.php';
require_once __DIR__ . '/includes/traits/trait-settings.php';
require_once __DIR__ . '/includes/services/class-openai-service.php';
require_once __DIR__ . '/includes/services/class-anthropic-service.php';
require_once __DIR__ . '/includes/services/class-gemini-service.php';
require_once __DIR__ . '/includes/services/class-provider-registry.php';
require_once __DIR__ . '/includes/services/class-bulk-service.php';

if ( ! class_exists( 'Presswell_Alt_Text_Generator' ) ) {
  /**
   * Bootstrap container for all Presswell Alt Text Generator features.
   */
  class Presswell_Alt_Text_Generator {
	  use PWATG_Assets_Trait;
    use PWATG_Bulk_Trait;
	  use PWATG_Helpers_Trait;
    use PWATG_Media_Trait;
	  use PWATG_Pages_Trait;
    use PWATG_Providers_Trait;
    use PWATG_Settings_Trait;
    
    const PLUGIN_FILE = __FILE__;
	
    /**
     * Cached singleton instance.
     *
     * @var Presswell_Alt_Text_Generator|null
     */
    private static $instance = null;

    /**
     * Wire trait constructors and boot runtime hooks.
     */
    protected function __construct() {
	    $this->construct_assets_trait();
      $this->construct_bulk_trait();
      $this->construct_media_trait();
	    $this->construct_pages_trait();
	    $this->construct_settings_trait();
    }
	
    /**
     * Prevent cloning the singleton.
     */
	  public function __clone() {}

    /**
     * Prevent unserializing the singleton.
     */
    public function __wakeup() {}

    /**
     * Return the shared plugin instance.
     *
     * @return Presswell_Alt_Text_Generator
     */
    public static function instance() {
      if ( null === self::$instance ) {
        self::$instance = new self();
      }

      return self::$instance;
    }

  }
}

if ( ! function_exists( 'presswell_alt_text_generator' ) ) {
  /**
   * Helper to access the singleton instance from procedural code.
   *
   * @return Presswell_Alt_Text_Generator
   */
  function presswell_alt_text_generator() {
    return Presswell_Alt_Text_Generator::instance();
  }
}

presswell_alt_text_generator();
