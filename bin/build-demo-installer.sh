#!/usr/bin/env bash
# Build the installable ZIP(s) for the CPMS Classic disposable demo.
#
# TWO BUILDS, ONE SOURCE TREE — nothing is duplicated in the repository:
#
#   standalone (default)  cpms-disposable-demo-1.0.0.zip
#        The real CPMS Classic V1 production source is ASSEMBLED INTO the package
#        at build time from `clinic-practice-management/` at this exact revision,
#        under `core/`, and booted by CpmsDemo\Standalone. The package installs
#        and runs on a non-empty test site WITHOUT the production plugin.
#
#   companion             cpms-disposable-demo-companion-1.0.0.zip
#        No embedded core; requires the production plugin to be installed and
#        active. Kept because the empty-site ("strict") workflows and the
#        production/demo conflict drills exercise exactly that combination.
#
# Both archives contain exactly one top-level directory, one plugin main file
# with one plugin header, no tests, no docs, no repository paths, no build
# scripts and no development dependencies.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
COMPANION_SRC="$ROOT/tools/disposable-demo-installer"
PRODUCTION_SRC="$ROOT/clinic-practice-management"
OUT_DIR="${OUT_DIR:-$ROOT/dist}"
NAME="cpms-disposable-demo"
MODE="standalone"

case "${1:-}" in
  --companion) MODE="companion" ;;
  --standalone|'') MODE="standalone" ;;
  *) echo "usage: $0 [--standalone|--companion]" >&2; exit 2 ;;
esac

VERSION="$(sed -n 's/^ \* Version: *//p' "$COMPANION_SRC/cpms-disposable-demo.php" | head -1)"
[ -n "$VERSION" ] || { echo 'cannot read plugin version' >&2; exit 1; }

for required in cpms-disposable-demo.php includes/class-cpms-demo-admin.php includes/class-cpms-demo-standalone.php readme.txt; do
  [ -f "$COMPANION_SRC/$required" ] || { echo "missing $required" >&2; exit 1; }
done
grep -q '^ \* Plugin Name: *CPMS Classic' "$COMPANION_SRC/cpms-disposable-demo.php" \
  || { echo 'plugin header check failed' >&2; exit 1; }
# The standalone package must not declare a production-plugin dependency: that
# header would make WordPress refuse to activate it on a site without CPMS.
if grep -q '^ \* Requires Plugins:' "$COMPANION_SRC/cpms-disposable-demo.php"; then
  echo 'the demo main file must not declare Requires Plugins' >&2; exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

# Assemble the real production core from the current production source. This is
# the ONLY place production files enter the package, and they are copied, never
# linked or duplicated into the repository.
if [ "$MODE" = 'standalone' ]; then
  for required in clinic-practice-management.php src templates assets; do
    [ -e "$PRODUCTION_SRC/$required" ] || { echo "production source missing $required" >&2; exit 1; }
  done
fi

PKG="$STAGE/$NAME"
mkdir -p "$PKG"
cp "$COMPANION_SRC/cpms-disposable-demo.php" "$COMPANION_SRC/readme.txt" "$COMPANION_SRC/uninstall.php" "$PKG/"
cp -R "$COMPANION_SRC/includes" "$PKG/includes"
cp -R "$COMPANION_SRC/assets" "$PKG/assets"

if [ "$MODE" = 'standalone' ]; then
  CORE="$PKG/core"
  mkdir -p "$CORE"
  cp "$PRODUCTION_SRC/clinic-practice-management.php" "$PRODUCTION_SRC/uninstall.php" "$PRODUCTION_SRC/CHANGELOG.md" "$CORE/"
  cp -R "$PRODUCTION_SRC/src" "$PRODUCTION_SRC/templates" "$PRODUCTION_SRC/assets" "$CORE/"
  # Runtime dependencies only. `vendor/` is shipped when the production release
  # build produced it; tests, phpunit.phar, bin/, phpstan/phpcs configs and
  # composer manifests are development material and never packaged.
  if [ -d "$PRODUCTION_SRC/vendor" ]; then
    cp -R "$PRODUCTION_SRC/vendor" "$CORE/vendor"
  fi
  # Nothing from the repository's test/CI surface may ride along.
  find "$CORE" -type d \( -name tests -o -name .git -o -name node_modules \) -prune -exec rm -rf {} +
  find "$CORE" -type f \( -name 'phpunit.phar' -o -name '*.dist' -o -name 'composer.json' -o -name 'composer.lock' \) -delete
fi

# The embedded core's own uninstall handler can never run (WordPress only runs
# the uninstall.php of the plugin directory being deleted), and the demo's own
# uninstall.php is the guard. Keeping a second handler inside `core/` would be
# dead code that looks live, so it is removed rather than left to mislead.
rm -f "$PKG/core/uninstall.php"

if find "$PKG" -name '*.php' -print0 | xargs -0 grep -l -E 'CPMS_DEV_OTP_ECHO[^)]*define|md5\(' >/dev/null 2>&1; then
  echo 'refusing to package: dev-echo define or md5 found' >&2; exit 1
fi

# ---------------------------------------------------------------------------
# Exactly ONE discoverable WordPress plugin header in the standalone package.
#
# WordPress discovers plugins with get_plugins(), which scans the plugin root
# AND one level of sub-directories, and Plugin_Upgrader::plugin_info() takes
# the FIRST discovered file after sorting by plugin name. The embedded
# production bootstrap (`core/clinic-practice-management.php`) carries the
# production header "Clinic Practice Management (CPMS)", which sorts before
# "CPMS Classic — Disposable Demo Installer". Shipping it verbatim therefore
# made the wp-admin upload installer pick `core/clinic-practice-management.php`
# as the plugin file, and the "Activate Plugin" link failed validate_plugin()
# with `no_plugin_header` ("The plugin does not have a valid header").
#
# The fix is applied to the STAGED COPY ONLY: the single `Plugin Name:` header
# line of the embedded bootstrap is rewritten into a non-header docblock line.
# Nothing else in the file changes (verified below byte-for-byte), so the
# embedded bootstrap keeps its exact executable PHP behaviour: CpmsDemo\
# Standalone boots it with require_once and never reads header text. The
# production source file and the production release ZIP are never touched.
# ---------------------------------------------------------------------------
if [ "$MODE" = 'standalone' ]; then
  CORE_MAIN="$CORE/clinic-practice-management.php"
  SRC_MAIN="$PRODUCTION_SRC/clinic-practice-management.php"
  HEADER_LINES="$(grep -c -E '^[[:space:]/*#@]*Plugin Name:' "$CORE_MAIN" || true)"
  [ "$HEADER_LINES" = '1' ] || { echo "embedded core bootstrap must carry exactly one Plugin Name header (found $HEADER_LINES)" >&2; exit 1; }
  HEADER_LINE_NO="$(grep -n -E '^[[:space:]/*#@]*Plugin Name:' "$CORE_MAIN" | head -1 | cut -d: -f1)"
  # The header must live inside the leading docblock (a ` * ` comment line):
  # rewriting any other shape could change executable code, so refuse instead.
  grep -q -E '^ \* Plugin Name:[[:space:]]+Clinic Practice Management \(CPMS\)[[:space:]]*$' "$CORE_MAIN" \
    || { echo 'embedded core bootstrap header has an unexpected shape; refusing to rewrite it' >&2; exit 1; }
  NEUTRALIZED=' * Embedded Core:     Clinic Practice Management (CPMS) — shipped inside cpms-disposable-demo, not a separate WordPress plugin (header neutralized by bin/build-demo-installer.sh)'
  awk -v n="$HEADER_LINE_NO" -v repl="$NEUTRALIZED" 'NR == n { print repl; next } { print }' "$CORE_MAIN" > "$CORE_MAIN.tmp"
  mv "$CORE_MAIN.tmp" "$CORE_MAIN"
  # Byte-for-byte: the staged copy differs from the production source in that
  # ONE line and nothing else. (diff exits 1 on differences; the exact shape of
  # the difference is asserted, not just its presence.)
  DIFF_OUT="$(diff "$SRC_MAIN" "$CORE_MAIN" || true)"
  DIFF_LINES="$(printf '%s\n' "$DIFF_OUT" | grep -c -E '^[<>]' || true)"
  [ "$DIFF_LINES" = '2' ] || { echo "staged core bootstrap must differ from the production source in exactly one line (got $DIFF_LINES changed lines)" >&2; exit 1; }
  printf '%s\n' "$DIFF_OUT" | grep -q -E "^${HEADER_LINE_NO}c${HEADER_LINE_NO}\$" \
    || { echo 'staged core bootstrap changed somewhere other than the Plugin Name line' >&2; exit 1; }
  cmp -s "$SRC_MAIN" "$PRODUCTION_SRC/clinic-practice-management.php" \
    || { echo 'production source file changed during the build; refusing' >&2; exit 1; }
  grep -q -E '^[[:space:]/*#@]*Plugin Name:' "$CORE_MAIN" \
    && { echo 'embedded core bootstrap still carries a Plugin Name header' >&2; exit 1; }
  # The rewritten line is still inside the docblock, so the staged file stays
  # valid PHP. When a PHP binary is available, prove it with the real linter.
  if command -v php >/dev/null 2>&1; then
    php -l "$CORE_MAIN" >/dev/null || { echo 'staged core bootstrap does not lint' >&2; exit 1; }
  fi
fi

# No nested plugin bootstrap: exactly one file in the package may carry a
# WordPress plugin header, in BOTH builds, and it is the demo main file. The
# pattern mirrors WordPress's own header regex prefix (`[ \t/*#@]*`), so a
# header hidden behind `#`, `//` or `@` would be caught too.
HEADERS="$(grep -rl --include='*.php' -E '^[[:space:]/*#@]*Plugin Name:' "$PKG" | LC_ALL=C sort)"
EXPECTED="$PKG/cpms-disposable-demo.php"
[ "$HEADERS" = "$EXPECTED" ] || { printf 'unexpected plugin headers (exactly one expected, at the package root):\n%s\n' "$HEADERS" >&2; exit 1; }
if [ "$MODE" = 'standalone' ]; then
  # And the embedded bootstrap is still the real production entry file: its
  # executable anchors are present verbatim.
  for anchor in "define('CPMS_VERSION'" "define('CPMS_PLUGIN_FILE', __FILE__)" "defined('ABSPATH') || exit;"; do
    grep -qF "$anchor" "$PKG/core/clinic-practice-management.php" \
      || { echo "embedded core bootstrap lost its executable anchor: $anchor" >&2; exit 1; }
  done
fi

# Deterministic bytes: fixed mtimes + sorted entry order (reproducible ZIP).
find "$STAGE" -exec touch -h -d "2026-01-01T00:00:00Z" {} +

mkdir -p "$OUT_DIR"
if [ "$MODE" = 'standalone' ]; then
  ZIP="$OUT_DIR/$NAME-$VERSION.zip"
else
  ZIP="$OUT_DIR/$NAME-companion-$VERSION.zip"
fi
rm -f "$ZIP"
( cd "$STAGE" && find "$NAME" -type f | LC_ALL=C sort | zip -q -X -9 "$ZIP" -@ )

BYTES="$(stat -c %s "$ZIP")"
SHA="$(sha256sum "$ZIP" | cut -d' ' -f1)"
ENTRIES="$(zipinfo -1 "$ZIP" | wc -l)"
{
  echo "installer=$ZIP"
  echo "filename=$(basename "$ZIP")"
  echo "mode=$MODE"
  echo "version=$VERSION"
  echo "bytes=$BYTES"
  echo "sha256=$SHA"
  echo "entries=$ENTRIES"
  echo "plugin_headers=1"
  echo "plugin_header_file=$NAME/cpms-disposable-demo.php"
  if [ "$MODE" = 'standalone' ]; then
    echo "embedded_core_bootstrap=$NAME/core/clinic-practice-management.php"
    echo "embedded_core_header=neutralized"
    echo "embedded_core_bootstrap_sha256=$(sha256sum "$PKG/core/clinic-practice-management.php" | cut -d' ' -f1)"
    echo "production_bootstrap_sha256=$(sha256sum "$PRODUCTION_SRC/clinic-practice-management.php" | cut -d' ' -f1)"
  fi
  echo "production_revision=$(git -C "$ROOT" rev-parse HEAD 2>/dev/null || echo unknown)"
} | tee "$OUT_DIR/$NAME-$VERSION.proof.txt"
