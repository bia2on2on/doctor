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

# No nested plugin bootstrap: exactly one file in the package may carry a
# WordPress plugin header.
# (In an embedded build the core's own main file carries its header too — that is
# the production entry file, deliberately shipped unmodified so the core boots
# through its real code path rather than a copy of it.)
HEADERS="$(grep -rl --include='*.php' '^ \* Plugin Name:' "$PKG" | LC_ALL=C sort)"
EXPECTED="$PKG/cpms-disposable-demo.php"
if [ "$MODE" = 'standalone' ]; then
  EXPECTED="$(printf '%s\n%s' "$EXPECTED" "$PKG/core/clinic-practice-management.php" | LC_ALL=C sort)"
fi
[ "$HEADERS" = "$EXPECTED" ] || { printf 'unexpected plugin headers:\n%s\n' "$HEADERS" >&2; exit 1; }

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
  echo "production_revision=$(git -C "$ROOT" rev-parse HEAD 2>/dev/null || echo unknown)"
} | tee "$OUT_DIR/$NAME-$VERSION.proof.txt"
