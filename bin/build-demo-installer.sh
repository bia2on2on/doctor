#!/usr/bin/env bash
# Build the installable companion ZIP for the CPMS disposable demo.
#
# Installable means: the archive contains exactly one top-level directory named
# `cpms-disposable-demo/`, holding the plugin main file with a valid WordPress
# plugin header. No tests, docs, build scripts, evidence or repository files are
# ever packaged — the companion plugin is the whole artefact.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/tools/disposable-demo-installer"
OUT_DIR="${OUT_DIR:-$ROOT/dist}"
NAME="cpms-disposable-demo"
VERSION="$(sed -n 's/^ \* Version: *//p' "$SRC/cpms-disposable-demo.php" | head -1)"
[ -n "$VERSION" ] || { echo 'cannot read plugin version' >&2; exit 1; }

for required in cpms-disposable-demo.php includes/class-cpms-demo-admin.php readme.txt; do
  [ -f "$SRC/$required" ] || { echo "missing $required" >&2; exit 1; }
done
grep -q '^ \* Plugin Name: *CPMS Classic' "$SRC/cpms-disposable-demo.php" \
  || { echo 'plugin header check failed' >&2; exit 1; }
grep -q "^ \* Requires Plugins: *clinic-practice-management" "$SRC/cpms-disposable-demo.php" \
  || { echo 'plugin dependency header check failed' >&2; exit 1; }

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$NAME"
cp "$SRC/cpms-disposable-demo.php" "$SRC/readme.txt" "$SRC/uninstall.php" "$STAGE/$NAME/"
cp -R "$SRC/includes" "$STAGE/$NAME/includes"
cp -R "$SRC/assets" "$STAGE/$NAME/assets"

if find "$STAGE/$NAME" -name '*.php' -print0 | xargs -0 grep -l -E 'CPMS_DEV_OTP_ECHO[^)]*define|md5\(' >/dev/null 2>&1; then
  echo 'refusing to package: dev-echo define or md5 found' >&2; exit 1
fi

# Deterministic bytes: fixed mtimes + sorted entry order (reproducible ZIP).
find "$STAGE" -exec touch -h -d "2026-01-01T00:00:00Z" {} +

mkdir -p "$OUT_DIR"
ZIP="$OUT_DIR/$NAME-$VERSION.zip"
rm -f "$ZIP"
( cd "$STAGE" && find "$NAME" -type f | LC_ALL=C sort | zip -q -X -9 "$ZIP" -@ )

BYTES="$(stat -c %s "$ZIP")"
SHA="$(sha256sum "$ZIP" | cut -d' ' -f1)"
ENTRIES="$(zipinfo -1 "$ZIP" | wc -l)"
{
  echo "installer=$ZIP"
  echo "filename=$(basename "$ZIP")"
  echo "version=$VERSION"
  echo "bytes=$BYTES"
  echo "sha256=$SHA"
  echo "entries=$ENTRIES"
} | tee "$OUT_DIR/$NAME-$VERSION.proof.txt"
