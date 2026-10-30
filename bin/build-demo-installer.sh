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
# Both archives contain exactly one top-level directory and exactly ONE
# WordPress-discoverable plugin header. In the standalone build the staged
# embedded bootstrap keeps every executable byte of the production entry file;
# only its docblock metadata key is neutralized so WordPress cannot offer the
# embedded core as a second plugin. No tests, docs, repository paths, build
# scripts or development dependencies are packaged.
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
  # Neutralize ONLY the staged copy's discoverable header key. WordPress scans
  # the installed plugin directory's immediate PHP files; `core/*.php` competes
  # with the outer bootstrap and get_plugins() sorts by Name, so the embedded
  # "CPMS Classic" entry could win Plugin_Upgrader::plugin_info() and the admin
  # screen would activate the wrong basename. Keep the PHP bootstrap executable
  # and byte-identical apart from this one metadata key. The production source
  # and the production release builder are never touched.
  python3 - "$CORE/clinic-practice-management.php" "$PRODUCTION_SRC/clinic-practice-management.php" <<'PY'
import pathlib, re, sys
staged = pathlib.Path(sys.argv[1])
source = pathlib.Path(sys.argv[2]).read_bytes()
pattern = rb'(?m)^(?P<prefix>[ \t]*\*[ \t]*)Plugin Name:(?P<space>[ \t]*)'
matches = list(re.finditer(pattern, source[:8192]))
if len(matches) != 1:
    raise SystemExit('embedded bootstrap must have exactly one Plugin Name header')
m = matches[0]
neutralized = source[:m.start()] + m.group('prefix') + b'CPMS Embedded Core:' + m.group('space') + source[m.end():]
if neutralized == source:
    raise SystemExit('header neutralization did not change the staged copy')
staged.write_bytes(neutralized)
# Every byte outside the metadata key is identical, including all PHP code.
delta = len(b'CPMS Embedded Core:') - len(b'Plugin Name:')
if neutralized[:m.start()] != source[:m.start()] or neutralized[m.end() + delta:] != source[m.end():]:
    raise SystemExit('embedded bootstrap executable bytes changed')
PY
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

# Exactly ONE WordPress-discoverable header in either build. Mirror the
# permissive core get_file_data() regex over the first 8 KiB, not just the
# canonical docblock spelling, so an alternate but valid header cannot slip in.
python3 - "$PKG" <<'PY'
import pathlib, re, sys
root = pathlib.Path(sys.argv[1])
pattern = re.compile(rb'^(?:[ \t]*<\?(?:php)?)?[ \t\/*#@]*Plugin Name:(.*)$', re.M)
found = []
for path in sorted(root.rglob('*.php')):
    if pattern.search(path.read_bytes()[:8192]):
        found.append(str(path.relative_to(root)))
if found != ['cpms-disposable-demo.php']:
    raise SystemExit('unexpected WordPress-discoverable plugin headers: ' + repr(found))
PY

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
