"""Unfiltered browser-error watch for the CPMS production harnesses.

Owner defect 12 ("JavaScript error related to serialize") was described in the
project history as a WordPress-core `user-profile.js` TypeError raised in a
`beforeunload` handler on `wp-admin/profile.php` for patient/shared-role users,
but it was never reproduced with an UNFILTERED page-error listener: the existing
gates filter on the page URL (`is_cpms_url`), on a message allow-list, or they
attach no listener at all to the context that visits `wp-admin/profile.php`.

This module adds one unfiltered listener pair (`page.on("pageerror")` plus
console errors) and classifies every captured error by the SOURCE of the error,
never by the page it happened on and never by the word "serialize":

  * a source frame under the plugin's own path
    (`/wp-content/plugins/clinic-practice-management/`) is a FAIL line, printed
    as `FAIL <line>` so the CI annotation names the defect;
  * every other source (WordPress core, another plugin, an inline theme script)
    is an `INFO <line>` line only and never fails the step.

Every record carries the message, the sanitized source URL (query string and
token-like values removed) and the page path. Nothing here replaces or weakens
an existing check: the caller keeps its own gates and only gains these lines.
"""

import re
from urllib.parse import urlparse

# The plugin's own served path. Anything under it is CPMS code, so an error
# whose source frame lives there must fail the step instead of being filtered.
PLUGIN_ASSETS_PREFIX = "/wp-content/plugins/clinic-practice-management/"

# Pages that WordPress core owns: an error there is core/other, never CPMS.
_MAX_MESSAGE = 300
_MAX_SOURCES = 6

_SECRET_PAIR_RE = re.compile(
    r"(?i)((?:[a-z0-9_-]*(?:nonce|token|password|passwd|secret|auth|sig|key|hash)"
    r"[a-z0-9_-]*)\s*[=:]\s*)[^\s&<>\"',;]+"
)
_OPAQUE_RE = re.compile(r"\b[A-Za-z0-9_\-]{32,}\b")
_URL_RE = re.compile(r"https?://[^\s)\"'<>\\]+")


def sanitize_url(value):
    """Return a URL with no query string, no fragment and no token-like part."""
    raw = str(value or "").strip()
    if not raw:
        return ""
    parsed = urlparse(raw)
    if not parsed.scheme and not parsed.netloc:
        # Relative or hash-only reference: keep the path, drop query/fragment.
        return _OPAQUE_RE.sub("[redacted]", raw.split("?", 1)[0].split("#", 1)[0])
    # Keep host:port, drop any userinfo (it can carry credentials).
    netloc = parsed.netloc.rsplit("@", 1)[-1]
    path = _OPAQUE_RE.sub("[redacted]", parsed.path or "")
    if not netloc:
        return path
    return f"{parsed.scheme}://{netloc}{path}" if parsed.scheme else f"//{netloc}{path}"


def page_path(value):
    """Return only the page path (no host, query string or fragment)."""
    clean = sanitize_url(value)
    if not clean:
        return ""
    parsed = urlparse(clean)
    return parsed.path if parsed.path else clean


def sanitize_message(value):
    """Redact token-like `key=value` pairs and long opaque blobs, then trim."""
    text = " ".join(str(value or "").split())
    text = _SECRET_PAIR_RE.sub(r"\g<1>[redacted]", text)
    text = _OPAQUE_RE.sub("[redacted]", text)
    if len(text) > _MAX_MESSAGE:
        text = text[:_MAX_MESSAGE] + "…"
    return text


def sources_from_text(value):
    """Collect the http(s) URLs in a stack trace, in order, without duplicates."""
    found = []
    for match in _URL_RE.finditer(str(value or "")):
        url = match.group(0).rstrip(".,;:")
        if url not in found:
            found.append(url)
        if len(found) >= _MAX_SOURCES:
            break
    return found


def is_plugin_source(sources):
    """True when any source frame is served by the plugin itself."""
    return any(PLUGIN_ASSETS_PREFIX in str(item or "") for item in sources)


def record(kind, message, sources, page_url):
    """Normalize one captured error into the record shape used by `report`."""
    clean_sources = []
    for item in sources:
        clean = sanitize_url(item)
        if clean and clean not in clean_sources:
            clean_sources.append(clean)
    return {
        "kind": kind,
        "message": sanitize_message(message),
        "sources": clean_sources,
        "source": clean_sources[0] if clean_sources else "",
        "page": page_path(page_url),
        "plugin": is_plugin_source(sources),
    }


def attach(page, records):
    """Attach the unfiltered listener pair to `page` and append to `records`."""
    def on_pageerror(error):
        text = " ".join(
            str(part or "")
            for part in (
                getattr(error, "name", ""),
                getattr(error, "message", ""),
                getattr(error, "stack", ""),
            )
        )
        sources = sources_from_text(getattr(error, "stack", "") or "")
        message = " ".join(
            part for part in (getattr(error, "name", ""), getattr(error, "message", "")) if part
        )
        records.append(record("pageerror", message or text, sources, page.url))

    def on_console(message):
        if getattr(message, "type", "") != "error":
            return
        location = {}
        try:
            location = message.location or {}
        except Exception:  # a location getter must never break the journey
            location = {}
        source = location.get("url", "") if isinstance(location, dict) else ""
        records.append(record("console", message.text, [source], page.url))

    page.on("pageerror", on_pageerror)
    page.on("console", on_console)
    return records


def format_line(item):
    """Human/annotation readable one-liner: message, source and page path."""
    return (
        f"{item['kind']} message={item['message'] or '(empty)'} "
        f"source={item['sources'][0] if item['sources'] else '(unknown)'} "
        f"page={item['page'] or '(unknown)'}"
    )


def report(records, label, emit=print):
    """Print INFO for foreign sources and return the FAIL lines for plugin ones.

    The returned lines already start with `FAIL ` so the caller can print them
    verbatim and a CI annotation can name the exact error.
    """
    fail_lines = []
    for item in records:
        line = format_line(item)
        if item["plugin"]:
            fail_lines.append(f"FAIL {label} plugin-owned error: {line}")
        else:
            emit(f"INFO {label} non-plugin error (does not fail this step): {line}")
    return fail_lines
