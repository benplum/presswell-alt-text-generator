<?php
/**
 * Read and write the rate-limit lock the way the plugin stores it: a site
 * transient on Multisite (the key is shared by every site), a transient otherwise.
 */

function pwatg_test_set_lock( array $payload, $ttl ) {
  return is_multisite() ? set_site_transient( PWATG::RATE_LIMIT_TRANSIENT, $payload, $ttl ) : set_transient( PWATG::RATE_LIMIT_TRANSIENT, $payload, $ttl );
}

function pwatg_test_get_lock() {
  return is_multisite() ? get_site_transient( PWATG::RATE_LIMIT_TRANSIENT ) : get_transient( PWATG::RATE_LIMIT_TRANSIENT );
}

function pwatg_test_delete_lock() {
  return is_multisite() ? delete_site_transient( PWATG::RATE_LIMIT_TRANSIENT ) : delete_transient( PWATG::RATE_LIMIT_TRANSIENT );
}
