<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Central location for plugin-wide slugs, option keys, and identifiers.
 */
class PWATG {

  const KEY = 'pwatg';
  const SETTINGS_KEY = 'pwatg_settings';
  const TEXT_DOMAIN = 'presswell-alt-text-generator';
  const VERSION = '0.1.0';
  
  // Settings Page
  const SETTINGS_PAGE_SLUG = 'presswell-alt-text-generator';
  const SETTINGS_PAGE_URL = 'options-general.php?page=' . self::SETTINGS_PAGE_SLUG;
  const SETTINGS_PAGE_SCREEN_ID = 'settings_page_' . self::SETTINGS_PAGE_SLUG;
  
  // Bulk Page
  const BULK_PAGE_SLUG = 'presswell-alt-text-bulk-generator';
  const BULK_PAGE_URL = 'upload.php?page=' . self::BULK_PAGE_SLUG;
  const BULK_PAGE_SCREEN_ID = 'media_page_' . self::BULK_PAGE_SLUG;

  // Assets
  const ASSET_HANDLE_ADMIN_CSS = 'pwatg-css-admin';
  const ASSET_HANDLE_SETTINGS_JS = 'pwatg-js-settings';
  const ASSET_HANDLE_BULK_CSS = 'pwatg-css-bulk';
  const ASSET_HANDLE_BULK_JS = 'pwatg-js-bulk';
  const ASSET_HANDLE_MEDIA_JS = 'pwatg-js-media';

  // Localized JS objects
  const JS_OBJECT_SETTINGS = 'pwatgSettingsData';
  const JS_OBJECT_BULK = 'pwatgBulkData';
  const JS_OBJECT_MEDIA = 'pwatgMediaData';
  
  // Transient notices
  const TRANSIENT_NOTICE_BULK = 'pwatg_bulk_notice';
  const TRANSIENT_NOTICE_TEST_PROVIDER = 'pwatg_test_provider_notice';
  const TRANSIENT_NOTICE_TTL = 60;
  const NOTICE_KEY_BULK = self::TRANSIENT_NOTICE_BULK;
  const NOTICE_KEY_TEST_PROVIDER = self::TRANSIENT_NOTICE_TEST_PROVIDER;

  // Nonces
  const NONCE_GENERATE_SINGLE = 'pwatg_generate_single_';
  const NONCE_GENERATE_BULK = 'pwatg_generate_bulk';

  // Rate limiting
  const RATE_LIMIT_TRANSIENT = 'pwatg_rate_limit_lock';
  const RATE_LIMIT_MIN_SECONDS = 60;
  const RATE_LIMIT_DEFAULT_SECONDS = 300;
  const RATE_LIMIT_MAX_SECONDS = 1800;
  const QUOTA_LOCK_SECONDS = 3600;

  // AJAX Actions
  const AJAX_GENERATE_SINGLE = 'pwatg_generate_single';
  const AJAX_GENERATE_BULK = 'pwatg_generate_bulk';
  const AJAX_INIT_BULK = 'pwatg_bulk_init';
  const AJAX_TEST_PROVIDER = 'pwatg_test_provider';
  const AJAX_SCAN_MISSING = 'pwatg_scan_missing_alt';
  
  // Meta Keys
  const META_KEY_ALT_TEXT = '_wp_attachment_image_alt';
  const META_KEY_LAST_GENERATED = '_pwatg_last_generated';

  // Field Keys
  const FIELD_GENERATE_SINGLE = 'pwatg_generate_alt';
  const MEDIA_COLUMN_ALT = 'pwatg_alt_text';
  
  // AI Providers  
  const PROVIDER_OPENAI = 'openai';
  const PROVIDER_ANTHROPIC = 'anthropic';
  const PROVIDER_GEMINI = 'gemini';
  const PROVIDER_MAP = [
    PWATG::PROVIDER_OPENAI => 'PWATG_OpenAI_Service',
    PWATG::PROVIDER_ANTHROPIC => 'PWATG_Anthropic_Service',
    PWATG::PROVIDER_GEMINI => 'PWATG_Gemini_Service',
  ];
  
  
}
