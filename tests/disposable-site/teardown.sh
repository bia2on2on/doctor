#!/usr/bin/env bash
# Destroy the explicitly marked disposable site's ENTIRE database.
# WordPress/plugin files are not selectively edited; destroy the container/site
# files as well when following the documented disposable-environment procedure.
set -euo pipefail

: "${WP_DIR:?WP_DIR is required}"
: "${CPMS_DISPOSABLE_STATE:?CPMS_DISPOSABLE_STATE is required}"
: "${CPMS_DISPOSABLE_OTP_FILE:=${CPMS_DISPOSABLE_STATE}.otp.json}"
: "${CPMS_DISPOSABLE_GENERATED_SECRETS:=${CPMS_DISPOSABLE_STATE}.generated.json}"
: "${CPMS_DISPOSABLE_CONFIRM:?CPMS_DISPOSABLE_CONFIRM is required}"
: "${ADMIN_USER:?ADMIN_USER is required}"

REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
export CPMS_DISPOSABLE_STATE CPMS_DISPOSABLE_OTP_FILE CPMS_DISPOSABLE_GENERATED_SECRETS CPMS_DISPOSABLE_CONFIRM

wp eval-file "$REPO_ROOT/tests/disposable-site/wp-cli/teardown-preflight.php" \
  --use-include --path="$WP_DIR" --allow-root --user="$ADMIN_USER"

# Official WP-CLI destructive operation. There is no selective patient/tenant
# deletion and no attempt to preserve this disposable WordPress installation.
wp db reset --yes --path="$WP_DIR" --allow-root
TABLES="$(wp db query 'SHOW TABLES' --path="$WP_DIR" --allow-root)"
if [ -n "$TABLES" ]; then
  echo 'TEARDOWN FAILED: tables remain after whole-database reset' >&2
  exit 1
fi

# Remove only protected files already validated outside the web root/worktree.
python3 - "$CPMS_DISPOSABLE_STATE" "$CPMS_DISPOSABLE_OTP_FILE" "$CPMS_DISPOSABLE_GENERATED_SECRETS" "$WP_DIR" "$REPO_ROOT" <<'PY'
import os
from pathlib import Path
import stat
import sys

state, otp, generated, web, repo = sys.argv[1:]
web = os.path.realpath(web)
repo = os.path.realpath(repo)
for name in (state, otp, generated, state + '.lock'):
    if not os.path.isabs(name) or os.path.islink(name):
        raise SystemExit('refusing unsafe protected-file teardown path')
    parent = os.path.realpath(os.path.dirname(name))
    if parent in (web, repo) or parent.startswith(web + os.sep) or parent.startswith(repo + os.sep):
        raise SystemExit('refusing protected file inside web root/worktree')
    if os.path.exists(name):
        if not os.path.isfile(name) or stat.S_IMODE(os.stat(name).st_mode) & 0o077:
            raise SystemExit('refusing non-regular or non-0600 protected file')
        os.unlink(name)
print('CPMS_DISPOSABLE_TEARDOWN={"ok":true,"database_tables":0,"protected_files_removed":true}')
PY
