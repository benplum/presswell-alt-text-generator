#!/usr/bin/env bash
# Usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]
set -euo pipefail

DB_NAME=${1:-wordpress_test}
DB_USER=${2:-root}
DB_PASS=${3:-root}
DB_HOST=${4:-localhost}
WP_VERSION=${5:-latest}
SKIP_DB_CREATE=${6:-false}

TMP_DIR=${TMPDIR:-/tmp}
WP_CORE_DIR="${TMP_DIR}/wordpress/"
WP_TESTS_DIR="${TMP_DIR}/wordpress-tests-lib"

download() {
  if command -v curl >/dev/null 2>&1; then
    curl -sSL "$1" -o "$2"
  elif command -v wget >/dev/null 2>&1; then
    wget -q "$1" -O "$2"
  else
    echo "Error: curl or wget is required." >&2
    exit 1
  fi
}

install_wp() {
  if [ -d "$WP_CORE_DIR" ]; then
    return
  fi

  mkdir -p "$WP_CORE_DIR"
  local archive="${TMP_DIR}/wordpress.tar.gz"

  if [ "$WP_VERSION" = 'latest' ]; then
    download https://wordpress.org/latest.tar.gz "$archive"
  else
    download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" "$archive"
  fi

  tar --strip-components=1 -zxmf "$archive" -C "$WP_CORE_DIR"
  rm -f "$archive"
}

install_test_suite() {
  if [ -d "$WP_TESTS_DIR" ]; then
    return
  fi

  mkdir -p "$WP_TESTS_DIR"
  svn co --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/includes/ "$WP_TESTS_DIR/includes"
  svn co --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/data/ "$WP_TESTS_DIR/data"
}

configure() {
  local config_file="$WP_TESTS_DIR/wp-tests-config.php"

  if [ -f "$config_file" ]; then
    return
  fi

  download https://develop.svn.wordpress.org/trunk/wp-tests-config-sample.php "$config_file"
  sed -i '' "s:dirname( __FILE__ ) . '/src/':'${WP_CORE_DIR//\//\/}':" "$config_file"
  sed -i '' "s/youremptytestdbnamehere/${DB_NAME}/" "$config_file"
  sed -i '' "s/yourusernamehere/${DB_USER}/" "$config_file"
  sed -i '' "s/yourpasswordhere/${DB_PASS}/" "$config_file"
  sed -i '' "s|localhost|${DB_HOST}|" "$config_file"
}

install_db() {
  if [ "$SKIP_DB_CREATE" = "true" ]; then
    return
  fi

  mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" --silent || true
}

install_wp
install_test_suite
configure
install_db
