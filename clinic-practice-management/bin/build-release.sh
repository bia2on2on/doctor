#!/usr/bin/env bash
#
# build-release.sh — ساخت Release Artifact تمیز (Pilot/Staging Gate)
#
# خروجی: dist/clinic-practice-management-<version>.zip + .sha256 + manifest
# سیاست محتوا (Whitelist — نه Blacklist): فقط فایلهای Production داخل ZIP می‌آیند.
#   شامل:  clinic-practice-management.php, README.md, uninstall.php, src/**, bin/cpms
#   هرگز:  .git، .env، tests/، phpunit*، composer.*، vendor/، logs، فایلهای Pilot/Gate
#
# استفاده: bin/build-release.sh [version]   (پیش‌فرض از CPMS_VERSION فایل اصلی)

set -euo pipefail
cd "$(dirname "$0")/.."

MAIN='clinic-practice-management.php'
VERSION="${1:-$(grep -oE "CPMS_VERSION',\s*'[^']+'" "$MAIN" | grep -oE "[0-9]+\.[0-9]+\.[0-9]+")}"
NAME='clinic-practice-management'
OUT='dist'
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

# --- Whitelist copy ---
mkdir -p "$STAGE/$NAME/src" "$STAGE/$NAME/bin"
cp "$MAIN" "$STAGE/$NAME/"
cp README.md "$STAGE/$NAME/"
cp uninstall.php "$STAGE/$NAME/"
cp bin/cpms "$STAGE/$NAME/bin/cpms"
chmod +x "$STAGE/$NAME/bin/cpms"
cp -R src/. "$STAGE/$NAME/src/"
# فایلهای غیرضروری احتمالی داخل src (نباید وجود داشته باشند — دفاعی)
find "$STAGE" -name '.DS_Store' -delete 2>/dev/null || true
find "$STAGE" -name '*.log' -delete 2>/dev/null || true

# --- ZIP ---
mkdir -p "$OUT"
ZIP="$OUT/${NAME}-${VERSION}.zip"
rm -f "$ZIP" "$OUT/${NAME}-${VERSION}.zip.sha256" "$OUT/${NAME}-${VERSION}-manifest.txt"
(cd "$STAGE" && zip -qr "$OLDPWD/$ZIP" "$NAME")
(cd "$STAGE/$NAME" && find . -type f | LC_ALL=C sort) > "$OUT/${NAME}-${VERSION}-manifest.txt"
SHA=$(sha256sum "$ZIP" | cut -d' ' -f1)
echo "$SHA  ${NAME}-${VERSION}.zip" > "$OUT/${NAME}-${VERSION}.zip.sha256"

echo "VERSION:    $VERSION"
echo "ZIP:        $ZIP ($(du -h "$ZIP" | cut -f1))"
echo "FILES:      $(wc -l < "$OUT/${NAME}-${VERSION}-manifest.txt")"
echo "SHA256:     $SHA"

# --- Policy self-check (شکست = build fail) ---
MANIFEST="$OUT/${NAME}-${VERSION}-manifest.txt"
if grep -Eq '(^|/)(\.git|\.env|tests|phpunit|composer\.(json|lock)|vendor|.*\.log|pilot-)' "$MANIFEST"; then
  echo 'POLICY VIOLATION: forbidden file in manifest' >&2
  grep -E '(^|/)(\.git|\.env|tests|phpunit|composer\.(json|lock)|vendor|.*\.log|pilot-)' "$MANIFEST" >&2
  exit 1
fi
echo 'POLICY:     OK (whitelist-only, no forbidden files)'
