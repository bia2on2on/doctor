#!/usr/bin/env bash
#
# build-release.sh — ساخت Release Artifact تمیز (Pilot/Staging Gate)
#
# خروجی: dist/clinic-practice-management-<version>.zip + .sha256 + manifest
# سیاست محتوا (Whitelist — نه Blacklist): فقط فایلهای Production داخل ZIP می‌آیند.
#   شامل: clinic-practice-management.php, README.md, THIRD-PARTY-NOTICES.md,
#          uninstall.php, src/**, assets/**, templates/**, bin/cpms,
#          locked production vendor (runtime autoload + upstream licenses/NOTICE).
#   هرگز: .git، .env، tests/، dev dependencies، composer.json/lock، auth/cache، logs، ابزارهای Gate
#
# استفاده: bin/build-release.sh [version]   (پیش‌فرض از CPMS_VERSION فایل اصلی)

set -euo pipefail
cd "$(dirname "$0")/.."
SOURCE="$PWD"

# Never let Composer install solve a new tree because the release lock is absent.
[ -f composer.lock ] || { echo 'BUILD FAILED: committed composer.lock is required' >&2; exit 1; }
composer validate --strict --no-check-publish

MAIN='clinic-practice-management.php'
VERSION="${1:-$(grep -oE "CPMS_VERSION',\s*'[^']+'" "$MAIN" | grep -oE "[0-9]+\.[0-9]+\.[0-9]+")}"
NAME='clinic-practice-management'
OUT='dist'
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

# --- Whitelist copy ---
mkdir -p "$STAGE/$NAME/src" "$STAGE/$NAME/bin" "$STAGE/$NAME/assets" "$STAGE/$NAME/templates"
cp "$MAIN" "$STAGE/$NAME/"
cp README.md THIRD-PARTY-NOTICES.md uninstall.php "$STAGE/$NAME/"
cp bin/cpms "$STAGE/$NAME/bin/cpms"
chmod +x "$STAGE/$NAME/bin/cpms"
cp -R src/. "$STAGE/$NAME/src/"
cp -R assets/. "$STAGE/$NAME/assets/"
if [ -d templates ]; then
  cp -R templates/. "$STAGE/$NAME/templates/"
fi

# A fresh stage, NEVER the checkout's potentially dev-contaminated vendor.
# Composer home/cache are siblings of the plugin stage and cannot enter the ZIP.
cp composer.json composer.lock "$STAGE/$NAME/"
(
  cd "$STAGE/$NAME"
  unset COMPOSER COMPOSER_AUTH COMPOSER_VENDOR_DIR COMPOSER_IGNORE_PLATFORM_REQS COMPOSER_IGNORE_PLATFORM_REQ
  export COMPOSER_HOME="$STAGE/composer-home" COMPOSER_CACHE_DIR="$STAGE/composer-cache"
  composer install --no-dev --prefer-dist --no-interaction --no-progress \
    --no-plugins --no-scripts --optimize-autoloader
  cmp composer.lock "$SOURCE/composer.lock"
  composer check-platform-reqs --no-dev
  # Any advisory or abandoned production package blocks release packaging.
  composer audit --locked --no-dev --abandoned=fail --format=json
  composer licenses --no-dev --format=json > "$STAGE/production-licenses.json"
  php -r '
    $data = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $licenses = [];
    foreach ($data["dependencies"] as $package) {
        foreach ($package["license"] as $license) {
            $licenses[$license] = true;
        }
    }
    $ids = array_keys($licenses);
    sort($ids);
    if (count($data["dependencies"]) !== 14 || $ids !== ["Apache-2.0", "MIT"]) {
        fwrite(STDERR, "BUILD FAILED: runtime license closure differs from reviewed evidence\n");
        exit(1);
    }
    echo "LICENSES:   14 runtime packages; Apache-2.0 + MIT\n";
  ' "$STAGE/production-licenses.json"
)

# Retain complete official runtime code/service models, Composer runtime PHP,
# upstream license texts/copyrights and AWS NOTICE. Strip only non-runtime content.
VENDOR="$STAGE/$NAME/vendor"
# Both AWS packages autoload only src/. Native-extension source, SDK generators,
# compatibility fixtures and build configuration are not PHP runtime dependencies.
find "$VENDOR/aws/aws-sdk-php" -mindepth 1 -maxdepth 1 \
  ! -name src ! -name LICENSE ! -name NOTICE ! -name THIRD-PARTY-LICENSES -exec rm -rf {} +
find "$VENDOR/aws/aws-crt-php" -mindepth 1 -maxdepth 1 \
  ! -name src ! -name LICENSE ! -name NOTICE -exec rm -rf {} +
find "$VENDOR" -type d \( -iname tests -o -iname test -o -iname docs -o -iname examples \
  -o -name .github -o -name .git -o -name .cache -o -name node_modules -o -name bin \) \
  -prune -exec rm -rf {} +
find "$VENDOR" -type f \( -name composer.json -o -name composer.lock -o -name installed.json \
  -o -name '.git*' -o -name '.editorconfig' -o -name 'phpunit*' -o -name 'phpstan*' \
  -o -name 'phpcs*' -o -name '*.neon' -o -iname 'README*' -o -iname 'CHANGELOG*' \
  -o -iname 'UPGRADING*' -o -iname 'CONTRIBUTING*' -o -name Makefile \) -delete
# These repository/build manifests are not needed by Composer's runtime loader.
rm "$STAGE/$NAME/composer.json" "$STAGE/$NAME/composer.lock"
find "$STAGE/$NAME" -name '.DS_Store' -delete
find "$STAGE/$NAME" -name '*.log' -delete

# --- ZIP ---
mkdir -p "$OUT"
ZIP="$OUT/${NAME}-${VERSION}.zip"
rm -f "$ZIP" "$OUT/${NAME}-${VERSION}.zip.sha256" "$OUT/${NAME}-${VERSION}-manifest.txt"
(cd "$STAGE" && zip -qr "$SOURCE/$ZIP" "$NAME")
(cd "$STAGE/$NAME" && find . -type f | LC_ALL=C sort) > "$OUT/${NAME}-${VERSION}-manifest.txt"
SHA=$(sha256sum "$ZIP" | cut -d' ' -f1)
echo "$SHA  ${NAME}-${VERSION}.zip" > "$OUT/${NAME}-${VERSION}.zip.sha256"

echo "VERSION:    $VERSION"
echo "ZIP:        $ZIP ($(du -h "$ZIP" | cut -f1))"
echo "FILES:      $(wc -l < "$OUT/${NAME}-${VERSION}-manifest.txt")"
echo "SHA256:     $SHA"
echo "VENDOR:     $(du -sh "$VENDOR" | cut -f1) / $(find "$VENDOR" -type f | wc -l) files"

# --- Policy self-check (شکست = build fail) ---
MANIFEST="$OUT/${NAME}-${VERSION}-manifest.txt"
FORBIDDEN='(^|/)(\.git[^/]*|\.env[^/]*|tests?|docs|node_modules|\.cache|cache|auth\.json|credentials|phpunit[^/]*|phpstan|php-stubs|yoast|szepeviktor|composer\.(json|lock)|installed\.json|[^/]*\.log|pilot-[^/]*)(/|$)'
if grep -Eqi "$FORBIDDEN" "$MANIFEST"; then
  echo 'POLICY VIOLATION: forbidden file in manifest' >&2
  grep -Ei "$FORBIDDEN" "$MANIFEST" >&2
  exit 1
fi
[ -f "$VENDOR/autoload.php" ] && [ -f "$VENDOR/aws/aws-sdk-php/src/S3/S3Client.php" ] \
  || { echo 'POLICY VIOLATION: required runtime SDK/autoload missing' >&2; exit 1; }
echo 'POLICY:     OK (whitelist + locked production runtime; no forbidden files)'
