#!/usr/bin/env bash
#
# نصب WordPress PHPUnit Test Suite برای تست‌های Integration افزونه.
#
# اقتباس از اسکریپت کلاسیک `tests/phpunit/includes/install-wp-tests.sh`
# (که در جریان بازسازی tooling به wp-env از trunk هسته حذف شد؛ سازوکار
# تست افزونه بدون تغییر باقی مانده است). Test Library نسخه 6.7 اختلافات
# نسخه PHPUnit را به yoast/phpunit-polyfills واگذار می‌کند؛ بنابراین
# PHPUnit 10/11 نیز پشتیبانی می‌شود.
#
# Usage:
#   bash tests/bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
#
# Env overrides: WP_TESTS_DIR, WP_CORE_DIR (پیش‌فرض /tmp/wordpress-test-lib, /tmp/wordpress)

set -euo pipefail

if [ $# -lt 3 ]; then
    echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version]" >&2
    exit 1
fi

DB_NAME="$1"
DB_USER="$2"
DB_PASS="$3"
DB_HOST="${4:-127.0.0.1}"
WP_VERSION="${5:-6.7.2}"

WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-test-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"

download() {
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL "$1" -o "$2"
    elif command -v wget >/dev/null 2>&1; then
        wget -nv -O "$2" "$1"
    else
        echo "Neither curl nor wget is available" >&2
        exit 1
    fi
}

install_wp() {
    if [ -d "$WP_CORE_DIR/wp-includes" ]; then
        echo "WP core already present at $WP_CORE_DIR"
        return
    fi
    mkdir -p "$WP_CORE_DIR"
    echo "Downloading WordPress ${WP_VERSION} ..."
    curl -fsSL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" \
        | tar -xz --strip-components=1 -C "$WP_CORE_DIR"
}

install_test_suite() {
    if [ -d "$WP_TESTS_DIR/includes" ]; then
        echo "Test library already present at $WP_TESTS_DIR"
        return
    fi
    mkdir -p "$WP_TESTS_DIR/includes"
    echo "Checking out WP test library ${WP_VERSION} (svn) ..."
    svn co -q "https://develop.svn.wordpress.org/tags/${WP_VERSION}/tests/phpunit/includes/" \
        "$WP_TESTS_DIR/includes"
    download "https://develop.svn.wordpress.org/tags/${WP_VERSION}/wp-tests-config-sample.php" \
        "$WP_TESTS_DIR/wp-tests-config.php"
}

install_db() {
    # plugin tests: جداول اختصاصی cpms_* — بدون نصب WP در DB
    echo "Creating database ${DB_NAME} (if not exists) ..."
    mysql -h "$DB_HOST" -u "$DB_USER" "-p${DB_PASS}" \
        -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`"
}

write_config() {
    cd "$WP_TESTS_DIR"
    # tarball رسمی wordpress.org ساختار /src ندارد (برخلاف repo develop)
    sed -i.bak "s:dirname( __FILE__ ) . '/src/':$WP_CORE_DIR:" wp-tests-config.php
    sed -i.bak "s/youremptytestdbnamehere/$DB_NAME/" wp-tests-config.php
    sed -i.bak "s/yourusernamehere/$DB_USER/" wp-tests-config.php
    sed -i.bak "s/yourpasswordhere/$DB_PASS/" wp-tests-config.php
    sed -i.bak "s|localhost|${DB_HOST}|" wp-tests-config.php
    rm -f wp-tests-config.php.bak
    echo "wp-tests-config.php written at $WP_TESTS_DIR"
}

install_wp
install_test_suite
install_db
write_config
echo "WP test suite ready: WP_TESTS_DIR=$WP_TESTS_DIR WP_CORE_DIR=$WP_CORE_DIR"
