"""Fail-closed cause projection for Day-2 `CLINIC_BOOKING_BUSY` failures.

`BookingService::assert_read_succeeded()` records only a fixed read label,
driver errno and numeric identifiers via `OpLogger::error(...)`, then answers
`503 CLINIC_BOOKING_BUSY`. Transactional diagnostics are emitted after rollback.
Legacy rows may still contain `sql_error` or arbitrary extra fields. This reader
never publishes driver text, even from those legacy rows: redaction patterns
cannot reliably remove short names, numbers, Unicode or unquoted SQL values.

Every publication path re-projects through the same allow-list: fixed read
labels, bounded integer errno/ids, and an errno-derived category. Unknown fields
and malformed values are dropped. The journey-start bound and id mismatch guard
are unchanged; missing/fetch/stale states remain bounded fallback labels.
"""

import json
import re

BUSY_CODE = "CLINIC_BOOKING_BUSY"
EVENT = "booking.lock_read_failed"
ID_KEYS = ("clinic_id", "organization_id", "location_id", "clinician_id", "slot_id")
MAX_ID = 9223372036854775807
MAX_SUFFIX = 400
STALE_STATE = "stale-or-mismatch"
MISSING_STATES = ("not-found", "fetch-failed", STALE_STATE)

# Fixed labels emitted by BookingService::assert_read_succeeded(). A grammar
# alone is insufficient: a short patient name can also look like a label.
READ_LABELS = frozenset((
    "slot", "confirm_hold", "confirm_slot", "reschedule_appointment",
    "reschedule_first_slot", "reschedule_second_slot", "staff_create_slot",
    "no_show_appointment", "cancel_appointment", "location_active",
    "location_timezone", "location", "organization_active", "organization",
    "clinic", "persisted_slot",
))
ERROR_CATEGORIES = {0: "unknown", 1205: "lock_wait_timeout", 1213: "deadlock"}
_TABLE_RE = re.compile(r"^[A-Za-z0-9_]{1,128}$")
_ROUTE_RE = re.compile(r"^[a-z0-9/_.-]{1,64}$")
_VIEWPORT_RE = re.compile(r"^\d{2,5}x\d{2,5}$")
_DIGITS_RE = re.compile(r"[0-9]{1,10}")
_SQL_DATETIME_RE = re.compile(r"\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}")


def _coerce_int(value):
    if isinstance(value, bool):
        return None
    if isinstance(value, int):
        return value
    if isinstance(value, str) and _DIGITS_RE.fullmatch(value):
        return int(value)
    return None


def extract_busy_diagnostic(context):
    """Project current or legacy stored context without reading driver text.

    Non-object input returns None. An object with no usable fields still yields
    the fixed event label, proving that a row existed without echoing its data.
    The category is derived only from validated errno, never from stored text.
    """
    if not isinstance(context, dict):
        return None
    diag = {"event": EVENT}
    read = context.get("read")
    if isinstance(read, str) and read in READ_LABELS:
        diag["read"] = read
    errno = _coerce_int(context.get("sql_errno"))
    if errno is not None and 0 <= errno <= 99999:
        diag["sql_errno"] = errno
        diag["error_category"] = ERROR_CATEGORIES.get(errno, "driver_error")
    for key in ID_KEYS:
        value = _coerce_int(context.get(key))
        if value is not None and 0 < value <= MAX_ID:
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
    if not isinstance(table, str) or not _TABLE_RE.fullmatch(table):
        raise ValueError("unsafe operational-log table identifier")
    bound = ""
    if since is not None:
        if not isinstance(since, str) or not _SQL_DATETIME_RE.fullmatch(since):
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
    # Defend direct formatter callers too, not only the DB-reader path.
    diag = extract_busy_diagnostic(diag) if isinstance(diag, dict) else None
    bits = []
    if diag:
        bits.append(f"busy_diag={EVENT}")
        if diag.get("read"):
            bits.append(f"read={diag['read']}")
        if "sql_errno" in diag:
            bits.append(f"sql_errno={diag['sql_errno']}")
        if diag.get("error_category"):
            bits.append(f'error_category={diag["error_category"]}')
        for key in ID_KEYS:
            if key in diag:
                bits.append(f"{key}={diag[key]}")
    else:
        state = missing if missing in MISSING_STATES else "not-found"
        bits.append(f"busy_diag={state}")
    if isinstance(route, str) and _ROUTE_RE.fullmatch(route):
        bits.append(f"route={route}")
    status = _coerce_int(http_status)
    if status:
        bits.append(f"http={status}")
    if isinstance(viewport, str) and _VIEWPORT_RE.fullmatch(viewport):
        bits.append(f"viewport={viewport}")
    return " ".join(bits)[:MAX_SUFFIX]


def _short_route(route):
    if not isinstance(route, str) or "/" not in route:
        return ""
    return route.rsplit("/", 2)[-2] + "/" + route.rsplit("/", 1)[-1]


def validate_busy_suffix(suffix):
    """Re-project a busy suffix string to its canonical closed form, or "".

    The suffix is a publication boundary: only its known `key=value` tokens
    are read, each value is re-validated against the same closed schema
    `format_busy_suffix` emits from (`busy_diag` state, fixed read label,
    bounded integer errno/ids, bounded HTTP status, validated route and
    viewport), and the result is rebuilt by `format_busy_suffix`. Free text,
    unknown keys and out-of-vocabulary values are dropped — never carried,
    escaped or pattern-redacted. Anything without a valid `busy_diag=` state
    yields "", so a caller can publish the result verbatim and nothing else
    from the original string can ride along.
    """
    if not isinstance(suffix, str) or not suffix.strip():
        return ""
    fields = {}
    for token in suffix.split():
        key, sep, value = token.partition("=")
        if sep and key not in fields:
            fields[key] = value
    state = fields.get("busy_diag", "")
    if state == EVENT:
        context = {}
        if fields.get("read") in READ_LABELS:
            context["read"] = fields["read"]
        errno = _coerce_int(fields.get("sql_errno"))
        if errno is not None and 0 <= errno <= 99999:
            context["sql_errno"] = errno
        for key in ID_KEYS:
            value = _coerce_int(fields.get(key))
            if value is not None and 0 < value <= MAX_ID:
                context[key] = value
        diag, missing = extract_busy_diagnostic(context), "not-found"
    elif state in MISSING_STATES:
        diag, missing = None, state
    else:
        return ""
    route = fields.get("route", "")
    if not _ROUTE_RE.fullmatch(route):
        route = ""
    status = _coerce_int(fields.get("http"))
    if status is None or not 100 <= status <= 599:
        status = 0
    viewport = fields.get("viewport", "")
    if not _VIEWPORT_RE.fullmatch(viewport):
        viewport = ""
    return format_busy_suffix(
        diag, route=route, http_status=status, viewport=viewport, missing=missing
    )


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
