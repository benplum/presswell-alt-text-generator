<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

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
  
  // Notices
  const NOTICE_KEY_BULK = 'pwatg_bulk_notice';
  const NOTICE_KEY_TEST_PROVIDER = 'pwatg_test_provider_notice';

  // Nonces
  const NONCE_GENERATE_SINGLE = 'pwatg_generate_single_';
  const NONCE_GENERATE_BULK = 'pwatg_generate_bulk';

  // AJAX Actions
  const AJAX_GENERATE_SINGLE = 'pwatg_generate_single';
  const AJAX_GENERATE_BULK = 'pwatg_generate_bulk';
  const AJAX_INIT_BULK = 'pwatg_bulk_init';
  const AJAX_TEST_PROVIDER = 'pwatg_test_provider';
  
  // Meta Keys
  const META_KEY_ALT_TEXT = '_wp_attachment_image_alt';
  const META_KEY_LAST_GENERATED = '_pwatg_last_generated';

  // Field Keys
  const FIELD_GENERATE_SINGLE = 'pwatg_generate_alt';
  
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
