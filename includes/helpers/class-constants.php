<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PWATG {

	const KEY = 'pwatg';
	const INSTANCE_KEY = 'pwatg_instance';
	const SETTINGS_KEY = 'pwatg_settings';
	const TEXT_DOMAIN = 'presswell-alt-text-generator';
	const VERSION = '0.1.0';
	
	// Settings Page
	const SETTINGS_PAGE_SLUG = 'presswell-alt-text-generator';
	const SETTINGS_PAGE_URL = 'options-general.php?page=' . self::SETTINGS_PAGE_SLUG;
	const SETTINGS_PAGE_SCREEN_ID = 'settings_page_' . self::SETTINGS_PAGE_SLUG;
	const SETTINGS_PAGE_TITLE = 'Alt Text Generator';
	
	// Bulk Page
	const BULK_PAGE_SLUG = 'presswell-alt-text-bulk-generator';
	const BULK_PAGE_URL = 'upload.php?page=' . self::BULK_PAGE_SLUG;
	const BULK_PAGE_SCREEN_ID = 'media_page_' . self::BULK_PAGE_SLUG;
	const BULK_PAGE_TITLE = 'Alt Text Generator';
	
	// Page Titles
	
	
	
	// Notices
	const BULK_NOTICE_KEY = 'pwatg_bulk_notice';
	const TEST_NOTICE_KEY = 'pwatg_test_notice';

	// Nonces
	const NONCE_GENERATE_SINGLE = 'pwatg_generate_single_';
	const NONCE_GENERATE_BULK = 'pwatg_bulk_ajax';
	
	// Meta Keys
	const ALT_TEXT_META_KEY = '_wp_attachment_image_alt';
	const LAST_GENERATED_META_KEY = '_pwatg_last_generated';
	
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
