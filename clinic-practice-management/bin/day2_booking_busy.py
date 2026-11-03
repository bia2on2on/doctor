"""Allow-listed cause extraction for Day-2 `CLINIC_BOOKING_BUSY` failures.

Provenance (production code, read-only for this harness): when a booking lock
read fails, `BookingService::assert_read_succeeded()` records the real database
cause via `OpLogger::error('booking.lock_read_failed', $context)` and only then
answers `503 CLINIC_BOOKING_BUSY`. The context is `{read, sql_errno, sql_error}`
merged with non-PII numeric ids, and `OpLogger` persists it as `context_json`
in the `{wp_prefix}cpms_operational_logs` table. For booking transactions the
diagnostic is written after the rollback, so the row exists when the browser
observes the 503; a raw transactional boundary can roll that write back, in
which case the harness reports `busy_diag=not-found`.

This module is the harness-side reader for that sink. It is deliberately pure:
the caller supplies the DB query function (the Day-2 harness already reads the
same database through its `dbrows()` helper), and every value that can reach a
failure line passes an allow-list — event name, read label, integer errno,
sanitized/truncated sql_error, and integer `*_id` fields only. Everything else
in the stored context is dropped. This is a best-effort allow-list, not a proof
of absence: `sql_error` is untrusted driver text, so the sanitizer collapses
whitespace, caps its length and redacts phone-like digit runs, quoted SQL
fragments and e-mail-like tokens rather than guaranteeing that no PII, token,
phone number or full SQL statement can ever survive a failure message.

Staleness: `latest_busy_sql()` is bounded by the failing journey's UTC start
(captured by the harness), and a row whose allow-listed ids conflict with the
busy attempt is reported as `busy_diag=stale-or-mismatch` instead of being
attributed to this failure.
"""

import json
import re

BUSY_CODE = "CLINIC_BOOKING_BUSY"
EVENT = "booking.lock_read_failed"
ID_KEYS = ("clinic_id", "organization_id", "location_id", "clinician_id", "slot_id")
MAX_SQL_ERROR = 200
MAX_SUFFIX = 400
STALE_STATE = "stale-or-mismatch"
MISSING_STATES = ("not-found", "fetch-failed", STALE_STATE)

_READ_RE = re.compile(r"^[a-z][a-z0-9_]{0,63}$")
_TABLE_RE = re.compile(r"^[A-Za-z0-9_]{1,128}$")
_ROUTE_RE = re.compile(r"^[a-z0-9/_.-]{1,64}$")
_VIEWPORT_RE = re.compile(r"^\d{2,5}x\d{2,5}$")
_DIGITS_RE = re.compile(r"^\d{1,10}$")
_DIGIT_RUN = re.compile(r"\d{7,}")
_QUOTED = re.compile(r"'([^']*)'")
_EMAIL_RE = re.compile(r"[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}")
_SQL_DATETIME_RE = re.compile(r"^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$")


def _quoted_repl(match):
    """Keep short identifier-like quotes; redact SQL-fragment-like quotes.

    MySQL error text quotes database/table names (`Table 'db.wp_x' doesn't
    exist`) — that identifier is the diagnostic value and is not PII. A quoted
    fragment that contains whitespace, a long digit run, or is very long looks
    like embedded statement text or a literal value, so it is redacted.
    """
    inner = match.group(1)
    if len(inner) > 80 or any(ch.isspace() for ch in inner) or _DIGIT_RUN.search(inner):
        return "'…'"
    return match.group(0)


def sanitize_sql_error(value):
    """One-line, length-capped, best-effort redaction of sql_error.

    Digit runs, quoted SQL fragments and e-mail-like tokens are redacted; this
    reduces (but cannot prove the absence of) sensitive values in driver text.
    """
    if not isinstance(value, str):
        return ""
    text = value.replace('"', "'").replace("`", "")
    text = " ".join(text.split())
    text = "".join(ch for ch in text if ch.isprintable() or ch == " ")
    text = _QUOTED.sub(_quoted_repl, text)
    text = _EMAIL_RE.sub("[email]", text)
    text = _DIGIT_RUN.sub("[digits]", text)
    return text[:MAX_SQL_ERROR]


def _coerce_int(value):
    if isinstance(value, bool):
        return None
    if isinstance(value, int):
        return value
    if isinstance(value, str) and _DIGITS_RE.match(value.strip()):
        return int(value.strip())
    return None


def extract_busy_diagnostic(context):
    """Allow-listed projection of a stored `booking.lock_read_failed` context.

    Returns None when the stored value is not an object at all. An object with
    no usable fields still yields `{"event": EVENT}` so the failure line can
    prove the row existed even when its context was empty.
    """
    if not isinstance(context, dict):
        return None
    diag = {"event": EVENT}
    read = context.get("read")
    if isinstance(read, str) and _READ_RE.match(read):
        diag["read"] = read
    errno = _coerce_int(context.get("sql_errno"))
    if errno is not None and 0 <= errno <= 99999:
        diag["sql_errno"] = errno
    error = sanitize_sql_error(context.get("sql_error"))
    if error:
        diag["sql_error"] = error
    for key in ID_KEYS:
        value = _coerce_int(context.get(key))
        if value is not None and value > 0:
            diag[key] = value
    return diag


def parse_busy_rows(rows):
    """Latest-row context_json cells (harness `dbrows()` shape) -> diagnostic."""
    if not rows:
        return None
    row = rows[0]
    cell = row[0] if row else None
    if not isinstance(cell, str) or not cell.strip():
        return None
    try:
        context = json.loads(cell)
    except (ValueError, TypeError):
        return None
    return extract_busy_diagnostic(context)


def latest_busy_sql(table, since=None):
    """SQL for the newest lock-read-failure row, bounded by the journey start.

    `table` must be a bare identifier; `since` (when supplied) must be a UTC
    ``YYYY-MM-DD HH:MM:SS`` string captured by the harness before the journey's
    first REST call. `cpms_operational_logs.created_at` is a UTC DATETIME(3)
    written by OpLogger, so the bound excludes a row left by an earlier run.
    An invalid value raises, which degrades to `busy_diag=fetch-failed` in the
    caller. With no `since`, the legacy unbounded query is returned unchanged.
    """
    if not isinstance(table, str) or not _TABLE_RE.match(table):
        raise ValueError("unsafe operational-log table identifier")
    bound = ""
    if since is not None:
        if not isinstance(since, str) or not _SQL_DATETIME_RE.match(since):
            raise ValueError("unsafe journey-start timestamp")
        bound = f" AND created_at >= '{since}'"
    return (
        f"SELECT context_json FROM {table} "
        f"WHERE message='{EVENT}'{bound} ORDER BY id DESC LIMIT 1"
    )


def find_busy_attempt(entries):
    """Last probe entry whose REST error code is CLINIC_BOOKING_BUSY, if any."""
    for entry in reversed(list(entries or [])):
        if isinstance(entry, dict) and entry.get("code") == BUSY_CODE:
            return entry
    return None


def _stale_for_attempt(attempt, diag):
    """Detect a stored row that cannot belong to this busy attempt.

    Only allow-listed integer ids shared by both sides are compared; a conflict
    means the newest row came from another journey/slot and must not be
    published as this failure's cause. With no shared id the journey-start
    bound is the only guard, so the row is kept rather than dropped on a guess.
    """
    if not isinstance(attempt, dict) or not isinstance(diag, dict):
        return False
    for key in ID_KEYS:
        left = _coerce_int(attempt.get(key))
        right = _coerce_int(diag.get(key))
        if (left is not None and right is not None
                and left > 0 and right > 0 and left != right):
            return True
    return False


def format_busy_suffix(diag, *, route="", http_status=0, viewport="", missing="not-found"):
    """Single-line `key=value` suffix, in the existing harness message style."""
    bits = []
    if diag:
        bits.append(f"busy_diag={EVENT}")
        if diag.get("read"):
            bits.append(f"read={diag['read']}")
        if "sql_errno" in diag:
            bits.append(f"sql_errno={diag['sql_errno']}")
        if diag.get("sql_error"):
            bits.append(f'sql_error="{diag["sql_error"]}"')
        for key in ID_KEYS:
            if key in diag:
                bits.append(f"{key}={diag[key]}")
    else:
        state = missing if missing in MISSING_STATES else "not-found"
        bits.append(f"busy_diag={state}")
    if isinstance(route, str) and _ROUTE_RE.match(route):
        bits.append(f"route={route}")
    status = _coerce_int(http_status)
    if status:
        bits.append(f"http={status}")
    if isinstance(viewport, str) and _VIEWPORT_RE.match(viewport):
        bits.append(f"viewport={viewport}")
    return " ".join(bits)[:MAX_SUFFIX]


def _short_route(route):
    if not isinstance(route, str) or "/" not in route:
        return ""
    return route.rsplit("/", 2)[-2] + "/" + route.rsplit("/", 1)[-1]


def busy_suffix_for(entries, query, table, viewport="", since=None):
    """Full failure suffix for a probe history, or "" when nothing was busy.

    `query` is the harness DB reader (sql -> row tuples). It is invoked only
    when a booking call actually returned CLINIC_BOOKING_BUSY, and any error
    it raises degrades to `busy_diag=fetch-failed`: producing this diagnostic
    must never mask or replace the real journey failure. `since` bounds the row
    to this journey's start; a row whose ids conflict with the attempt yields
    `busy_diag=stale-or-mismatch` instead of a misleading cause.
    """
    attempt = find_busy_attempt(entries)
    if attempt is None:
        return ""
    try:
        diag = parse_busy_rows(query(latest_busy_sql(table, since)))
        missing = "not-found"
        if diag is not None and _stale_for_attempt(attempt, diag):
            diag, missing = None, STALE_STATE
    except Exception:  # noqa: BLE001 - diagnostics must never mask the real failure
        diag, missing = None, "fetch-failed"
    return format_busy_suffix(
        diag,
        route=_short_route(attempt.get("route", "")),
        http_status=attempt.get("status", 0),
        viewport=viewport,
        missing=missing,
    )
