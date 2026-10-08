#!/usr/bin/env python3
"""Phase 18 third-party compatibility BASELINE harness — evidence probes.

TEST-ONLY CI tooling.  Never loaded by WordPress, never part of the CPMS plugin,
never shipped in a release ZIP.  It exists so that one identical set of probes
runs before and after a subject activation, on any subject, instead of ~40
assertions being duplicated inside the workflow YAML.

Subcommands
    subject   record the frozen subject identity
    wp        WordPress / HTTP / database layer probes (pre or post activation)
    install   retrieve the exact pinned package(s), verify them, activate them
    locale    Persian fa_IR locale / RTL / UTF-8 / timezone probes (locale leg)
    browser   real-browser probes (Chromium through Playwright)
    finalize  derive the machine-readable subject result from recorded evidence

WHY THE PROBES AVOID THE WORDPRESS RUNTIME
    The environment half of the evidence (WordPress version, PHP version, MySQL
    version, table counts, option values, active plugins, administrator role,
    CPMS absence) is read from the filesystem and from MySQL directly, not
    through WP-CLI.  Exact-head evidence on ebc253b proved why this matters:
    activating elementor 4.0.9 or persian-woocommerce 9.3.5 makes WP-CLI itself
    fatal (`Call to undefined function is_plugin_active()`), so every `wp eval`
    probe returned empty and the harness recorded "PHP version expected 8.2,
    observed ''" as an *environment* failure. That was a misattribution: the PHP
    version never changed, the product broke the probe channel. Reading these
    facts without the WordPress runtime removes the whole misattribution class,
    and WP-CLI bootstrap health is kept as its own explicit product-behaviour
    check so the breakage is still reported — correctly classified.

Evidence model — every recorded check carries:
    status   PASS | FAIL | INFO | FEATURE UNAVAILABLE | UNEXECUTED | NOT RUN
    klass    product-behavior | environment | harness-fixture
             | unavailable-feature | unexecuted
    material whether a FAIL of this check may fail the subject

The subject state is derived, never asserted by hand:
    NOT RUN  commercial subject without lawful owner-supplied material
    FAIL     at least one material check FAILed
    PARTIAL  no material FAIL, but something is FAIL / UNEXECUTED / FEATURE
             UNAVAILABLE
    PASS     every check PASS or INFO

CPMS is never installed here, so taxonomy category A and B are unreachable by
construction and are never emitted; see ``classify()``.
"""

from __future__ import annotations

import argparse
import http.cookiejar
import json
import os
import re
import shutil
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from typing import Any, Dict, List, Optional, Tuple

PASS = "PASS"
FAIL = "FAIL"
INFO = "INFO"
UNAVAILABLE = "FEATURE UNAVAILABLE"
UNEXECUTED = "UNEXECUTED"
NOT_RUN = "NOT RUN"

PRODUCT = "product-behavior"
ENVIRONMENT = "environment"
HARNESS = "harness-fixture"
UNAVAILABLE_KLASS = "unavailable-feature"
UNEXECUTED_KLASS = "unexecuted"

THIRD_PARTY_BASELINE = "THIRD-PARTY BASELINE FAILURE - no CPMS classification applicable"
# The Persian baseline leg installs no third-party product, so labelling one of
# its failures as a third-party failure would be a misattribution of exactly the
# kind this harness exists to prevent. It gets its own label; like the one above
# it still asserts the half that is always true here — CPMS is absent, so no CPMS
# category can apply.
LOCALE_BASELINE = "LOCALE BASELINE FAILURE - no CPMS classification applicable"
WORDPRESS_ORG_PACKAGE = "https://downloads.wordpress.org/plugin/{slug}.{version}.zip"

# --- narrow official-stable retrieval fallback ------------------------------ #
# Two subjects, listed by name, for one specific provider condition and nothing
# else. WordPress.org reports both of these at a stable version (10.0.5 and
# 7.2.3) but publishes NO version tag for them, so the versioned ZIP URL
# `<slug>.<version>.zip` returns HTTP 404 while the plugin's own API
# `download_link` is the unversioned `.../plugin/<slug>.zip`. Verified on the live
# plugins API: every other subject in this matrix has a versioned
# `download_link`; only these two do not.
#
# This is deliberately NOT a download framework. A slug that is not listed here
# keeps the exact previous behaviour: versioned URL, three bounded attempts, fail
# closed, no fallback of any kind. Adding a slug means naming it here and
# accepting that its package is fetched from the official stable URL after the
# API has been made to prove the version matches the frozen pin.
OFFICIAL_STABLE_FALLBACK_SLUGS = frozenset({
    "persian-woocommerce",
    "persian-woocommerce-sms",
})
WORDPRESS_ORG_PLUGIN_API = "https://api.wordpress.org/plugins/info/1.2/"
# The only host a package may ever be retrieved from. GitHub mirrors, vendor
# mirrors, unofficial download sites and nulled packages are all excluded by
# this allowlist rather than by a blocklist that could be added to.
WORDPRESS_ORG_DOWNLOAD_HOST = "downloads.wordpress.org"
WORDPRESS_ORG_DOWNLOAD_PREFIX = "/plugin/"

# The authentication plugin the frozen MySQL image is expected to use. MySQL 8.4
# removed `default_authentication_plugin` and ships `mysql_native_password`
# DISABLED by default, so `caching_sha2_password` is the mechanism every
# connection this harness makes actually goes through. It is asserted rather than
# assumed: pinning the image tag alone would not notice a future image that moved
# the mechanism, and silently changing how the campaign authenticates is exactly
# what the pin exists to prevent.
MYSQL_EXPECTED_AUTH_PLUGIN = "caching_sha2_password"

USER_AGENT = "cpms-phase18-third-party-baseline/1.0 (+github-actions)"
MAX_REDIRECTS = 10
BROWSER_TIMEOUT_MS = 60_000
DB_PREFIX = "wp_"

# Default response-body capture. Kept at the original 2000 characters so every
# pre-existing probe sees byte-identical evidence; the Persian locale probes pass
# a larger limit explicitly because they assert on the exact UTF-8 text of a whole
# rendered document rather than on a status line.
DEFAULT_BODY_LIMIT = 2000
LOCALE_BODY_LIMIT = 200_000

# Unicode block covering Arabic-script letters, which is what Persian text uses.
PERSIAN_SCRIPT = re.compile(r"[\u0600-\u06FF]")
PERSIAN_TOKEN = re.compile(r"[\u0600-\u06FF][\u0600-\u06FF\u200C\u200F\u200E]*")

# Fixture content for the UTF-8 round trip on the locale-baseline leg. Written to
# this job's throwaway database by the probe itself and read back through MySQL
# and through the REST API, so the exact string is controlled by the harness
# rather than borrowed from whichever translation happens to be installed.
# Deliberately free of single quotes and backslashes: it is interpolated into a
# SQL literal, and no fixture should need escaping to be safe.
LOCALE_PROBE_SLUG = "tpb-fa-utf8-probe"
LOCALE_PROBE_TITLE = "آزمون سازگاری فاز ۱۸ — متن پارسی با کدگذاری UTF-8"
LOCALE_PROBE_BODY = (
    "این نوشته تنها برای سنجش گردشی متن پارسی میان لایه‌های پایگاه داده، "
    "وردپرس و REST ایجاد شده است و هیچ داده‌ای از محصول در آن نیست."
)
LOCALE_PROBE_GUID_ID = 990001


# --------------------------------------------------------------------------- #
# Annotations
# --------------------------------------------------------------------------- #
def annotate(level: str, message: Any) -> None:
    """Emit a GitHub Actions check-run annotation.

    This matters more than it looks: raw job logs *and* artifacts are both served
    from ``*.blob.core.windows.net``, which review tooling outside the runner
    cannot reach. Check-run annotations are the only failure channel that stays
    readable, so every material failure is mirrored into one.
    """
    safe = str(message).replace("%", "%25").replace("\r", "%0D").replace("\n", "%0A")
    print(f"::{level}::{safe[:1600]}")


# --------------------------------------------------------------------------- #
# HTTP
# --------------------------------------------------------------------------- #
class RedirectLoop(Exception):
    """A bounded redirect chain exceeded MAX_REDIRECTS or revisited a URL."""


class _BoundedRedirectHandler(urllib.request.HTTPRedirectHandler):
    """Follows redirects, records the chain, refuses loops."""

    def __init__(self) -> None:
        super().__init__()
        self.chain: List[Dict[str, Any]] = []
        self.looped = False
        self.loop_reason = ""

    def redirect_request(self, req, fp, code, msg, headers, newurl):  # noqa: ANN001
        self.chain.append({"from": req.full_url, "to": newurl, "status": int(code)})
        if len(self.chain) > MAX_REDIRECTS:
            self.looped = True
            self.loop_reason = f"redirect chain exceeded {MAX_REDIRECTS} hops"
            raise RedirectLoop(self.loop_reason)
        if newurl in [hop["to"] for hop in self.chain[:-1]]:
            self.looped = True
            self.loop_reason = f"redirect chain revisited {newurl}"
            raise RedirectLoop(self.loop_reason)
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def http_request(
    url: str,
    cookie_jar: Optional[http.cookiejar.CookieJar] = None,
    data: Optional[bytes] = None,
    headers: Optional[Dict[str, str]] = None,
    timeout: int = 60,
    body_limit: int = DEFAULT_BODY_LIMIT,
) -> Dict[str, Any]:
    """One HTTP exchange with a bounded, recorded redirect chain.

    Never raises for HTTP status codes; transport errors are captured as
    evidence.  Returns ``status``, ``final_url``, ``redirects``, ``chain``,
    ``looped``, ``loop_reason``, ``body`` (first ``body_limit`` chars),
    ``content_type``, ``transport_error``.
    """
    handler = _BoundedRedirectHandler()
    # NOTE: never write `cookie_jar or CookieJar()` here. CookieJar defines
    # __len__, so an *empty* jar is falsy and would be silently replaced by a
    # throwaway jar — the admin session cookie would never persist and every
    # subject would fail the administrator-login probe for no real reason.
    if cookie_jar is None:
        cookie_jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(handler,
                                         urllib.request.HTTPCookieProcessor(cookie_jar))
    req_headers = {"User-Agent": USER_AGENT}
    if headers:
        req_headers.update(headers)
    result: Dict[str, Any] = {
        "status": 0, "final_url": url, "redirects": 0, "chain": [],
        "looped": False, "loop_reason": "", "body": "", "transport_error": "",
        "content_type": "",
    }
    try:
        with opener.open(urllib.request.Request(url, data=data, headers=req_headers),
                         timeout=timeout) as response:
            result["status"] = int(response.status)
            result["final_url"] = response.geturl()
            result["content_type"] = response.headers.get("Content-Type", "")
            result["body"] = response.read(body_limit).decode("utf-8", "replace")
    except urllib.error.HTTPError as exc:
        result["status"] = int(exc.code)
        result["final_url"] = exc.url or url
        if exc.headers is not None:
            result["content_type"] = exc.headers.get("Content-Type", "")
        try:
            result["body"] = exc.read(body_limit).decode("utf-8", "replace")
        except Exception:  # pragma: no cover - defensive
            result["body"] = ""
    except RedirectLoop as exc:
        result["looped"] = True
        result["loop_reason"] = str(exc)
    except Exception as exc:  # noqa: BLE001 - transport failure is evidence, not a crash
        result["transport_error"] = f"{type(exc).__name__}: {exc}"
    result["chain"] = handler.chain
    result["redirects"] = len(handler.chain)
    if handler.looped:
        result["looped"] = True
        result["loop_reason"] = result["loop_reason"] or handler.loop_reason
    return result


class RetrievalError(RuntimeError):
    """A package retrieval that did not succeed, carrying the last HTTP status.

    Subclasses ``RuntimeError`` so every existing handler keeps working
    unchanged; the status is what lets a caller tell a *confirmed* 404 (a
    definitive answer from the server: this version tag does not exist) apart
    from a transport hiccup that a retry might still fix.
    """

    def __init__(self, message: str, status: int = 0) -> None:
        super().__init__(message)
        self.status = status


class UntrustedRedirect(Exception):
    """A retrieval was redirected off the allowlisted distribution host."""


class _TrustedHostRedirectHandler(urllib.request.HTTPRedirectHandler):
    """Follows redirects only while every hop stays on an allowlisted host."""

    def __init__(self, allowed_hosts: frozenset) -> None:
        super().__init__()
        self.allowed = allowed_hosts
        self.chain: List[Dict[str, Any]] = []

    def redirect_request(self, req, fp, code, msg, headers, newurl):  # noqa: ANN001
        parts = urllib.parse.urlsplit(newurl)
        host = parts.netloc.lower().split("@")[-1].split(":")[0]
        self.chain.append({"from": req.full_url, "to": newurl,
                           "status": int(code), "host": host})
        if host not in self.allowed:
            # Refuse here rather than after the bytes arrive: following the hop
            # first and inspecting afterwards would already have fetched a
            # package from a host this harness must never retrieve from.
            raise UntrustedRedirect(
                f"refusing redirect to untrusted host {host!r} (allowed: "
                f"{sorted(self.allowed)})")
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def download(url: str, dest: str, attempts: int = 3,
             allowed_redirect_hosts: Optional[frozenset] = None) -> List[Dict[str, Any]]:
    """Retrieve a package or raise; return the recorded redirect chain.

    No fallback to another version, ever.  ``allowed_redirect_hosts`` is opt-in:
    unset keeps the previous plain behaviour for every existing call site, and the
    returned chain is empty for those, so nothing about their evidence changes.
    """
    last = ""
    status = 0
    used = attempts
    handler: Any = (_TrustedHostRedirectHandler(allowed_redirect_hosts)
                    if allowed_redirect_hosts is not None
                    else urllib.request.HTTPRedirectHandler())
    opener = urllib.request.build_opener(handler)
    for attempt in range(1, attempts + 1):
        used = attempt
        try:
            request = opener.open(
                urllib.request.Request(url, headers={"User-Agent": USER_AGENT}), timeout=300)
            with request as response, open(dest, "wb") as handle:
                shutil.copyfileobj(response, handle)
            return list(getattr(handler, "chain", []))
        except urllib.error.HTTPError as exc:
            status = int(exc.code)
            last = f"HTTPError: {exc}"
            if status == 404:
                # A 404 is the server's definitive answer, not a transient
                # failure. Retrying it only delays the caller's decision.
                break
            time.sleep(2 * attempt)
        except UntrustedRedirect as exc:
            # Never retry a refused redirect: the condition is deterministic and
            # retrying would just ask to be sent somewhere untrusted again.
            raise RetrievalError(str(exc), status) from exc
        except Exception as exc:  # noqa: BLE001
            last = f"{type(exc).__name__}: {exc}"
            time.sleep(2 * attempt)
    raise RetrievalError(f"retrieval failed after {used} attempt(s): {last}", status)


def sha256_of(path: str) -> str:
    import hashlib

    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""):
            digest.update(chunk)
    return digest.hexdigest()


# --------------------------------------------------------------------------- #
# Processes
# --------------------------------------------------------------------------- #
def run(cmd: List[str], timeout: int = 300) -> Dict[str, Any]:
    """Run a command capturing output; never raises on a non-zero exit code."""
    try:
        proc = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout,
                              check=False)
        return {"rc": proc.returncode, "stdout": proc.stdout, "stderr": proc.stderr}
    except Exception as exc:  # noqa: BLE001
        return {"rc": 127, "stdout": "", "stderr": f"{type(exc).__name__}: {exc}"}


def wp_cli(args: List[str], wp_dir: str, timeout: int = 300) -> Dict[str, Any]:
    return run(["wp", *args, f"--path={wp_dir}", "--allow-root"], timeout=timeout)


def mysql_query(sql: str, db_name: str) -> Dict[str, Any]:
    """Query this job's own database directly, without the WordPress runtime."""
    return run(["mysql", "-h", "127.0.0.1", "-P", "3306", "-uroot", "-proot",
                "-N", "-B", "--default-character-set=utf8mb4",
                "-e", sql, db_name], timeout=120)


def scalar(sql: str, db_name: str) -> Tuple[bool, str, str]:
    """Return (ok, value, diagnostic) for a single-value query."""
    result = mysql_query(sql, db_name)
    if result["rc"] != 0:
        return False, "", (result["stderr"] or f"mysql exited {result['rc']}").strip()[:400]
    return True, (result["stdout"] or "").strip(), ""


def unserialize_strings(serialized: str) -> List[str]:
    """Extract the strings from a simple serialized PHP array of strings.

    Enough for ``active_plugins``; avoids needing a PHP runtime to read one
    option, which is exactly the independence this harness needs.
    """
    if not serialized.startswith("a:"):
        return []
    return re.findall(r's:\d+:"((?:[^"\\]|\\.)*)"', serialized)


# --------------------------------------------------------------------------- #
# Evidence store
# --------------------------------------------------------------------------- #
class Store:
    """Append-only evidence store persisted as one JSON file per subject job."""

    def __init__(self, path: str) -> None:
        self.path = path
        self.data: Dict[str, Any] = {"subject": {}, "facts": {}, "checks": []}
        if os.path.isfile(path):
            with open(path, encoding="utf-8") as handle:
                loaded = json.load(handle)
            for key in ("subject", "facts", "checks"):
                if key in loaded:
                    self.data[key] = loaded[key]

    def fact(self, key: str, value: Any) -> None:
        self.data["facts"][key] = value

    def subject(self, **kwargs: Any) -> None:
        self.data["subject"].update(kwargs)

    def record(self, name: str, status: str, klass: str, detail: Any = "",
               material: bool = False, phase: str = "",
               expected: Any = None, observed: Any = None) -> None:
        self.data["checks"].append({
            "name": name, "status": status, "klass": klass, "material": bool(material),
            "phase": phase, "detail": detail, "expected": expected, "observed": observed,
        })

    def check(self, name: str, ok: bool, klass: str, detail: Any = "",
              material: bool = True, phase: str = "",
              expected: Any = None, observed: Any = None) -> bool:
        self.record(name, PASS if ok else FAIL, klass, detail, material, phase,
                    expected, observed)
        return ok

    def unexecuted(self, name: str, klass: str, reason: str, phase: str) -> None:
        """Record a check that could not run, instead of inventing a verdict."""
        self.record(name, UNEXECUTED, klass, reason, False, phase, observed=reason[:400])

    def save(self) -> None:
        os.makedirs(os.path.dirname(self.path) or ".", exist_ok=True)
        with open(self.path, "w", encoding="utf-8") as handle:
            json.dump(self.data, handle, indent=2)
            handle.write("\n")


def annotate_failures(store: Store, start: int) -> None:
    """Mirror every failure recorded since ``start`` into annotations.

    Material failures become errors, recorded-only failures become warnings. Both
    are mirrored because raw job logs and artifacts are unreachable from outside
    the runner; run 37680547043 left the single recorded-only failure on all 15
    PARTIAL subjects completely unreadable for exactly that reason.
    """
    for check in store.data["checks"][start:]:
        if check["status"] == FAIL:
            annotate("error" if check["material"] else "warning",
                     f"{check['name']} FAILED (class candidate: {check['klass']}) "
                     f"expected={json.dumps(check['expected'], ensure_ascii=False)[:300]} "
                     f"observed={json.dumps(check['observed'], ensure_ascii=False)[:600]} "
                     f"detail={json.dumps(check['detail'], ensure_ascii=False)[:400]}")


# --------------------------------------------------------------------------- #
# Runtime-independent readers
# --------------------------------------------------------------------------- #
def read_wp_core_version(wp_dir: str) -> str:
    """Read the WordPress version from wp-includes/version.php (no PHP needed)."""
    path = os.path.join(wp_dir, "wp-includes", "version.php")
    if not os.path.isfile(path):
        return ""
    with open(path, encoding="utf-8", errors="replace") as handle:
        match = re.search(r"\$wp_version\s*=\s*'([^']+)'", handle.read())
    return match.group(1) if match else ""


def read_plugin_headers(wp_dir: str) -> List[Dict[str, Any]]:
    """Parse installed plugin headers straight off disk.

    Deliberately not `wp eval get_plugins()`: a subject that makes WP-CLI fatal
    must not be able to erase the dependency evidence about itself.
    """
    plugins_dir = os.path.join(wp_dir, "wp-content", "plugins")
    headers: List[Dict[str, Any]] = []
    if not os.path.isdir(plugins_dir):
        return headers
    for entry in sorted(os.listdir(plugins_dir)):
        directory = os.path.join(plugins_dir, entry)
        if not os.path.isdir(directory):
            continue
        for name in sorted(os.listdir(directory)):
            if not name.endswith(".php"):
                continue
            path = os.path.join(directory, name)
            try:
                with open(path, encoding="utf-8", errors="replace") as handle:
                    head = handle.read(8192)
            except OSError:
                continue
            if "Plugin Name:" not in head:
                continue

            def field(label: str) -> str:
                found = re.search(rf"^[ \t\/*#@]*{label}:\s*(.+)$", head,
                                  re.IGNORECASE | re.MULTILINE)
                return found.group(1).strip() if found else ""

            requires = field("Requires Plugins")
            headers.append({
                "slug": entry,
                "file": f"{entry}/{name}",
                "name": field("Plugin Name"),
                "version": field("Version"),
                "requires_plugins": [r.strip() for r in requires.split(",") if r.strip()],
                "requires_php": field("Requires PHP"),
                "requires_wp": field("Requires at least"),
            })
            break
    return headers


# --------------------------------------------------------------------------- #
# Official-stable retrieval fallback (allowlisted slugs only)
# --------------------------------------------------------------------------- #
def wordpress_org_plugin_info(slug: str, timeout: int = 60) -> Dict[str, Any]:
    """Query the official WordPress.org plugins API for one canonical slug.

    Never raises.  Returns ``{"error": ...}`` on any failure, so a caller always
    has to look at the result rather than at an exception path.
    """
    params = urllib.parse.urlencode({
        "action": "plugin_information",
        "request[slug]": slug,
        # Trim the response to the fields this harness actually adjudicates on;
        # `versions` in particular is enormous and irrelevant here.
        **{f"request[fields][{f}]": "0" for f in (
            "sections", "description", "short_description", "contributors", "ratings",
            "screenshots", "banners", "icons", "reviews", "support_threads", "versions",
            "tags", "upgrade_notice")},
    })
    url = f"{WORDPRESS_ORG_PLUGIN_API}?{params}"
    try:
        request = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
        with urllib.request.urlopen(request, timeout=timeout) as response:
            raw = response.read(200_000).decode("utf-8", "replace")
    except Exception as exc:  # noqa: BLE001 - an unreachable API is evidence, not a crash
        return {"error": f"{type(exc).__name__}: {exc}"}
    try:
        parsed = json.loads(raw)
    except json.JSONDecodeError as exc:
        # A malformed body is refused outright: guessing at a partial parse would
        # be exactly how a wrong package ends up installed.
        return {"error": f"malformed JSON from the plugins API: {exc}",
                "body_head": raw[:200]}
    if not isinstance(parsed, dict):
        return {"error": f"plugins API returned {type(parsed).__name__}, expected an object",
                "body_head": raw[:200]}
    if parsed.get("error"):
        return {"error": f"plugins API reported an error: {parsed.get('error')}"}
    return parsed


def validate_official_stable_source(slug: str, expected_version: str,
                                    info: Dict[str, Any]) -> Tuple[str, str]:
    """Return ``(download_url, rejection_reason)`` for an API response.

    Every condition is a hard requirement, and the reason string is specific so a
    refusal in CI names the condition that failed instead of leaving a reviewer to
    reconstruct it.
    """
    if info.get("error"):
        return "", str(info["error"])
    if not isinstance(info, dict):
        return "", "the plugins API response was not an object"

    api_slug = info.get("slug")
    if api_slug != slug:
        # Exact canonical slug equality. This is what stops a fuzzy match, a
        # renamed plugin or a lookalike slug from being accepted.
        return "", f"API slug {api_slug!r} is not exactly the frozen slug {slug!r}"

    api_version = info.get("version")
    if api_version != expected_version:
        return "", (f"API stable version {api_version!r} does not equal the frozen "
                    f"expected version {expected_version!r}; refusing to install a "
                    f"different version")

    link = info.get("download_link") or ""
    parts = urllib.parse.urlsplit(link)
    host = parts.netloc.lower().split("@")[-1].split(":")[0]
    if parts.scheme != "https":
        return "", f"download_link scheme {parts.scheme!r} is not https"
    if host != WORDPRESS_ORG_DOWNLOAD_HOST:
        # This is the allowlist doing its job: a GitHub mirror, a vendor mirror,
        # an unofficial download site or a nulled package all fail here.
        return "", (f"download_link host {host!r} is not the official WordPress.org "
                    f"distribution host {WORDPRESS_ORG_DOWNLOAD_HOST!r}")
    if not parts.path.startswith(WORDPRESS_ORG_DOWNLOAD_PREFIX):
        return "", (f"download_link path {parts.path!r} is not under "
                    f"{WORDPRESS_ORG_DOWNLOAD_PREFIX!r}")
    if parts.query or parts.fragment:
        return "", f"download_link carries an unexpected query/fragment: {link!r}"
    return link, ""


def inspect_zip_plugin_identity(zip_path: str, slug: str,
                                expected_version: str) -> Dict[str, Any]:
    """Read the plugin's own headers out of the downloaded ZIP, before installing.

    Proves the archive is the plugin it claims to be: one top-level directory
    named after the slug, a PHP file inside carrying a ``Plugin Name`` header, and
    a ``Version`` header equal to the frozen pin. Done before ``wp plugin install``
    so a wrong or tampered package is rejected on its own contents rather than
    after it has been unpacked into the tree under measurement.
    """
    outcome: Dict[str, Any] = {"ok": False, "reason": "", "top_level": [],
                               "plugin_file": "", "header_name": "",
                               "header_version": "", "header_slug_dir": ""}
    try:
        import zipfile

        with zipfile.ZipFile(zip_path) as archive:
            bad = archive.testzip()
            if bad is not None:
                outcome["reason"] = f"corrupt entry in the package: {bad}"
                return outcome
            names = archive.namelist()
            top = sorted({n.split("/")[0] for n in names if n.split("/")[0]})
            outcome["top_level"] = top[:10]
            candidates = [n for n in names
                          if n.count("/") == 1 and n.lower().endswith(".php")]
            header = ""
            plugin_file = ""
            for name in sorted(candidates):
                try:
                    head = archive.read(name)[:8192].decode("utf-8", "replace")
                except Exception:  # noqa: BLE001
                    continue
                if "Plugin Name:" not in head:
                    continue
                header, plugin_file = head, name
                break
    except Exception as exc:  # noqa: BLE001
        outcome["reason"] = f"the package could not be read as a ZIP: {type(exc).__name__}: {exc}"
        return outcome

    if not header:
        outcome["reason"] = "no PHP file with a `Plugin Name:` header was found in the package"
        return outcome

    def field(label: str) -> str:
        found = re.search(rf"^[ \t\/*#@]*{label}:\s*(.+)$", header,
                          re.IGNORECASE | re.MULTILINE)
        return found.group(1).strip() if found else ""

    outcome["plugin_file"] = plugin_file
    outcome["header_name"] = field("Plugin Name")
    outcome["header_version"] = field("Version")
    outcome["header_slug_dir"] = plugin_file.split("/")[0]

    if outcome["header_version"] != expected_version:
        outcome["reason"] = (f"package header Version {outcome['header_version']!r} does not "
                             f"equal the frozen pin {expected_version!r}")
    elif outcome["header_slug_dir"] != slug:
        outcome["reason"] = (f"package top-level directory {outcome['header_slug_dir']!r} does "
                             f"not equal the frozen slug {slug!r}")
    else:
        outcome["ok"] = True
    return outcome


def db_active_plugins(db_name: str) -> Tuple[bool, List[str], str]:
    ok, value, diag = scalar(
        f"SELECT option_value FROM {DB_PREFIX}options WHERE option_name='active_plugins'",
        db_name)
    if not ok:
        return False, [], diag
    return True, sorted(unserialize_strings(value)), ""


def db_option(db_name: str, name: str) -> Tuple[bool, str, str]:
    return scalar(f"SELECT option_value FROM {DB_PREFIX}options WHERE option_name='{name}'",
                  db_name)


def db_count(db_name: str, sql: str) -> Tuple[bool, int, str]:
    ok, value, diag = scalar(sql, db_name)
    if not ok:
        return False, -1, diag
    return True, int(value), "" if value.lstrip("-").isdigit() else "non-numeric result"


def db_administrator_roles(db_name: str) -> Tuple[bool, str, str]:
    return scalar(
        "SELECT meta_value FROM " + DB_PREFIX + "usermeta WHERE meta_key='"
        + DB_PREFIX + "capabilities' AND meta_value LIKE '%administrator%'", db_name)


def debug_log_state(wp_dir: str) -> Dict[str, Any]:
    path = os.path.join(wp_dir, "wp-content", "debug.log")
    if not os.path.isfile(path):
        return {"exists": False, "lines": 0, "fatal": 0, "warning": 0, "samples": []}
    with open(path, encoding="utf-8", errors="replace") as handle:
        lines = handle.read().splitlines()
    fatal = [ln for ln in lines if re.search(r"Fatal error|Uncaught", ln)]
    warning = [ln for ln in lines if re.search(r"\b(Warning|Notice|Deprecated)\b", ln)]
    return {"exists": True, "lines": len(lines), "fatal": len(fatal),
            "warning": len(warning), "samples": (fatal + warning)[:20]}


# --------------------------------------------------------------------------- #
# Locale / RTL readers (Persian baseline leg)
# --------------------------------------------------------------------------- #
def persian_text_evidence(text: str) -> Dict[str, Any]:
    """Count and sample Arabic-script characters in a rendered document.

    "The locale is configured" and "the locale is loaded" are different claims.
    A configured WPLANG option proves nothing about what a browser received, so
    the actual served text is measured instead of the setting being trusted.
    """
    return {
        "persian_char_count": len(PERSIAN_SCRIPT.findall(text)),
        "persian_token_count": len(PERSIAN_TOKEN.findall(text)),
        "samples": PERSIAN_TOKEN.findall(text)[:12],
        "bytes": len(text.encode("utf-8")),
    }


def html_root_attributes(body: str) -> Dict[str, str]:
    """Read ``lang`` and ``dir`` off the ``<html>`` element of a served document.

    Read from the bytes actually served over HTTP, not from ``wp eval`` or a
    configuration option, so a locale that is set but never applied cannot pass.
    """
    match = re.search(r"<html[^>]*>", body, re.IGNORECASE)
    tag = match.group(0) if match else ""

    def attribute(name: str) -> str:
        found = re.search(rf"""\b{name}\s*=\s*["']([^"']*)["']""", tag, re.IGNORECASE)
        return found.group(1).strip() if found else ""

    return {"lang": attribute("lang"), "dir": attribute("dir"), "html_tag": tag[:200]}


def charset_of(content_type: str) -> str:
    """Extract the charset parameter from a Content-Type header, lowercased."""
    match = re.search(r"charset\s*=\s*\"?([\w\-]+)", content_type or "", re.IGNORECASE)
    return match.group(1).lower() if match else ""


# --------------------------------------------------------------------------- #
# Shared HTTP probes
# --------------------------------------------------------------------------- #
def admin_credentials() -> tuple[str, str]:
    """Credentials come from the environment — never from argv or a log line."""
    return os.environ.get("TPB_ADMIN_USER", ""), os.environ.get("TPB_ADMIN_PASS", "")


def admin_login_session(base_url: str) -> Dict[str, Any]:
    """Prove a real administrator login and session over plain HTTP."""
    user, password = admin_credentials()
    outcome: Dict[str, Any] = {"ok": False, "login_status": 0, "admin_status": 0,
                               "admin_final_url": "", "marker_found": False, "error": ""}
    if not user or not password:
        outcome["error"] = "TPB_ADMIN_USER/TPB_ADMIN_PASS not provided"
        return outcome

    jar = http.cookiejar.CookieJar()
    # WordPress only accepts the test cookie when the login page issued it.
    http_request(f"{base_url}/wp-login.php", cookie_jar=jar)
    payload = urllib.parse.urlencode({
        "log": user, "pwd": password, "wp-submit": "Log In",
        "redirect_to": f"{base_url}/wp-admin/", "testcookie": "1",
    }).encode()
    login = http_request(f"{base_url}/wp-login.php", cookie_jar=jar, data=payload,
                         headers={"Referer": f"{base_url}/wp-login.php"})
    outcome["login_status"] = login["status"]
    if login["transport_error"]:
        outcome["error"] = login["transport_error"]
        return outcome

    admin = http_request(f"{base_url}/wp-admin/", cookie_jar=jar)
    outcome["admin_status"] = admin["status"]
    outcome["admin_final_url"] = admin["final_url"]
    marker = "wp-admin" in admin["body"] and "wp-login.php" not in admin["final_url"]
    outcome["marker_found"] = marker
    outcome["ok"] = admin["status"] == 200 and marker
    if not outcome["ok"] and not outcome["error"]:
        outcome["error"] = (f"wp-admin returned {admin['status']} at {admin['final_url']}"
                            + ("" if marker else "; auth marker absent"))
    return outcome


def admin_ajax_probe(base_url: str) -> Dict[str, Any]:
    """Prove WordPress-layer admin-ajax reachability.

    ``GET /wp-admin/admin-ajax.php`` with no ``action`` is *expected* to be
    answered by WordPress itself with body ``0`` (HTTP 400 on modern WordPress,
    200 on older).  That is a correct application response, not a failure.  A
    negative control against a path that cannot exist proves the harness can
    tell a WordPress answer apart from an Apache 404.
    """
    probe = http_request(f"{base_url}/wp-admin/admin-ajax.php")
    body = probe["body"].strip()
    negative = http_request(f"{base_url}/wp-admin/__tpb_negative_control__.php")
    return {
        "status": probe["status"], "body": body[:80],
        "reached_wp_layer": probe["status"] in (200, 400) and body == "0",
        "transport_error": probe["transport_error"],
        "negative_status": negative["status"],
        "negative_distinguishable": negative["status"] == 404,
    }


def web_runtime_probe(probe_url: str) -> Dict[str, Any]:
    """Read PHP/SAPI/server identity from the Apache probe alias, outside WP."""
    response = http_request(probe_url, timeout=30)
    payload: Dict[str, Any] = {"php_version": "", "php_sapi": "", "server_software": "",
                               "transport_error": response["transport_error"]}
    if response["status"] == 200:
        try:
            parsed = json.loads(response["body"])
            payload.update({"php_version": parsed.get("php_version", ""),
                            "php_sapi": parsed.get("php_sapi", ""),
                            "server_software": parsed.get("server_software", "")})
        except json.JSONDecodeError:
            payload["transport_error"] = "probe response was not JSON"
    else:
        payload["transport_error"] = payload["transport_error"] or f"HTTP {response['status']}"
    return payload


def cpms_absence(wp_dir: str, db_name: str) -> Dict[str, Any]:
    """CPMS must be absent from the filesystem, plugin set, options and tables."""
    plugin_files: List[str] = []
    for root, _dirs, files in os.walk(wp_dir):
        for name in files:
            if name == "clinic-practice-management.php":
                plugin_files.append(os.path.relpath(os.path.join(root, name), wp_dir))

    ok_active, active, _diag = db_active_plugins(db_name)
    entries = [p for p in active
               if "clinic-practice-management" in p or "cpms" in p.split("/")[0]]

    ok_tables, tables, _diag = db_count(
        db_name,
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() "
        "AND table_name LIKE '%cpms\\_%'")
    ok_options, options, _diag = db_count(
        db_name, f"SELECT COUNT(*) FROM {DB_PREFIX}options WHERE option_name LIKE '%cpms%'")

    return {
        "plugin_dir_present": os.path.isdir(
            os.path.join(wp_dir, "wp-content", "plugins", "clinic-practice-management")),
        "plugin_files_found": plugin_files,
        "plugin_entries_found": entries,
        "table_count": tables if ok_tables else -1,
        "cpms_option_count": options if ok_options else -1,
        "active_plugins_readable": ok_active,
    }


# --------------------------------------------------------------------------- #
# subcommand: subject
# --------------------------------------------------------------------------- #
def cmd_subject(args: argparse.Namespace) -> int:
    store = Store(args.store)
    store.subject(id=args.id, name=args.name, slug=args.slug,
                  version_requested=args.version, source_type=args.source, kind=args.kind,
                  lane=args.lane,
                  dependencies=[d for d in (args.deps or "").split(",") if d and d != "-"])
    # Recorded only when a leg actually pins them, so a product leg's subject
    # record stays byte-identical to the one this harness already published.
    if args.locale or args.site_timezone:
        store.subject(locale=args.locale, site_timezone=args.site_timezone)
    if args.unavailable_feature:
        # Marked for that one feature only: not a PASS, and not a whole-plugin FAIL.
        store.record(f"feature::{args.unavailable_feature}", UNAVAILABLE, UNAVAILABLE_KLASS,
                     f"{args.unavailable_reason} - FEATURE UNAVAILABLE for this feature only",
                     False)
    store.save()
    print(f"[probe:subject] {args.id} lane={args.lane} "
          f"deps={store.data['subject']['dependencies']}")
    return 0


# --------------------------------------------------------------------------- #
# subcommand: wp
# --------------------------------------------------------------------------- #
def cmd_wp(args: argparse.Namespace) -> int:
    store = Store(args.store)
    phase = args.phase
    db_name = args.db_name
    base_url = args.wp_url.rstrip("/")
    start = len(store.data["checks"])
    store.fact(f"phase_{phase}_executed", True)

    # -- runtime / server identity (no WordPress involved) ------------------ #
    runtime = web_runtime_probe(args.probe_url)
    for key, value in (("web_php_version", runtime["php_version"]),
                       ("web_php_sapi", runtime["php_sapi"]),
                       ("server_software", runtime["server_software"])):
        store.fact(f"{key}_{phase}", value)
    if runtime["transport_error"]:
        store.check(f"web_runtime_probe_{phase}", False, HARNESS, runtime["transport_error"],
                    True, phase, observed=runtime["transport_error"])
    store.check(f"php_web_version_exact_{phase}",
                runtime["php_version"].startswith(args.expect_php), ENVIRONMENT,
                "the PHP serving HTTP must match the frozen Lane A pin", True, phase,
                args.expect_php, runtime["php_version"])
    store.record(f"server_identity_{phase}", INFO, ENVIRONMENT,
                 {"server_software": runtime["server_software"],
                  "php_sapi": runtime["php_sapi"],
                  "web_php_version": runtime["php_version"]}, phase=phase)

    wp_version = read_wp_core_version(args.wp_dir)
    store.fact(f"wp_core_version_{phase}", wp_version)
    store.check(f"wp_core_version_exact_{phase}", wp_version == args.expect_wp_version,
                ENVIRONMENT, "WordPress core must equal the frozen Lane A pin", True, phase,
                args.expect_wp_version, wp_version)

    cli = run(["php", "-r", "echo PHP_VERSION;"])
    cli_version = (cli["stdout"] or "").strip()
    store.fact(f"cli_php_version_{phase}", cli_version)
    store.check(f"php_cli_version_exact_{phase}", cli_version.startswith(args.expect_php),
                ENVIRONMENT,
                "read with `php -r`, never through WP-CLI, so a subject that breaks "
                "WP-CLI cannot fake a PHP version failure", True, phase,
                args.expect_php, cli_version or cli["stderr"][:200])

    ok, mysql_version, diag = scalar("SELECT VERSION()", db_name)
    store.fact(f"mysql_version_{phase}", mysql_version)
    store.record(f"mysql_version_{phase}", PASS if ok else FAIL, ENVIRONMENT,
                 diag or "MySQL server version serving this subject", True, phase,
                 observed=mysql_version or diag)

    # The recorded version above only proves the query succeeded. When the caller
    # froze a pin, the RUNNING server has to match it: an image tag that floats
    # must never be able to move the lane out from under the campaign.
    if args.expect_mysql:
        major_minor = ".".join(mysql_version.split(".")[:2]) if ok else ""
        store.check(f"mysql_version_exact_{phase}",
                    ok and major_minor == args.expect_mysql, ENVIRONMENT,
                    diag or "the MySQL server serving this subject must equal the frozen "
                            "image pin", True, phase, args.expect_mysql,
                    major_minor or diag)

    # Authentication mechanism, as evidence rather than assumption. See
    # MYSQL_EXPECTED_AUTH_PLUGIN for why the pin alone is not enough.
    ok_policy, policy, diag_policy = scalar("SELECT @@authentication_policy", db_name)
    ok_plugin, plugin, diag_plugin = scalar(
        "SELECT DISTINCT plugin FROM mysql.user WHERE user='root'", db_name)
    store.fact(f"mysql_auth_{phase}", {
        "authentication_policy": policy if ok_policy else diag_policy,
        "root_plugin": plugin if ok_plugin else diag_plugin,
    })
    store.record(f"mysql_authentication_policy_{phase}", INFO, ENVIRONMENT,
                 "MySQL 8.4 removed default_authentication_plugin, so authentication_policy "
                 "is the surviving setting; recorded as an environment fact, never asserted "
                 "to a particular value", phase=phase,
                 observed=policy if ok_policy else diag_policy)
    if ok_plugin:
        store.check(f"mysql_auth_mechanism_{phase}",
                    plugin.strip() == MYSQL_EXPECTED_AUTH_PLUGIN, ENVIRONMENT,
                    f"the frozen MySQL image authenticates with "
                    f"{MYSQL_EXPECTED_AUTH_PLUGIN}; anything else means the image's "
                    f"authentication semantics changed underneath this campaign, which must "
                    f"never happen silently. Enabling the deprecated mysql_native_password "
                    f"to make this pass would be the wrong fix and is not done anywhere here.",
                    True, phase, MYSQL_EXPECTED_AUTH_PLUGIN, plugin.strip() or diag_plugin)
    else:
        # Could not read it — say so instead of guessing, but do not fail the
        # subject on a privilege difference in the probe channel.
        store.unexecuted(f"mysql_auth_mechanism_{phase}", HARNESS,
                         f"the root account's authentication plugin could not be read: "
                         f"{diag_plugin}", phase)

    ok_tz, tz, diag_tz = db_option(db_name, "timezone_string")
    ok_lang, lang, diag_lang = db_option(db_name, "WPLANG")
    store.fact(f"timezone_{phase}", tz)
    store.fact(f"locale_{phase}", lang)
    if ok_tz and ok_lang:
        store.record(f"timezone_and_locale_{phase}", PASS, ENVIRONMENT,
                     {"timezone": tz, "WPLANG": lang}, phase=phase)
    else:
        store.check(f"timezone_and_locale_{phase}", False, ENVIRONMENT,
                    diag_tz or diag_lang, True, phase, observed=diag_tz or diag_lang)

    ok_roles, roles_value, diag_roles = db_administrator_roles(db_name)
    store.check(f"administrator_login_capability_{phase}", ok_roles and bool(roles_value),
                ENVIRONMENT,
                diag_roles or "an administrator account must exist in this job's database",
                True, phase, "administrator", roles_value[:120] or diag_roles)

    # -- plugin set, read from the option table ---------------------------- #
    ok_active, plugins, diag_active = db_active_plugins(db_name)
    store.fact(f"active_plugins_{phase}", plugins)
    if not ok_active:
        store.check(f"active_plugins_readable_{phase}", False, ENVIRONMENT, diag_active,
                    True, phase, observed=diag_active)
    elif phase == "pre":
        store.check("active_plugins_baseline_clean_pre", plugins == [], HARNESS,
                    "a clean baseline has no active plugin before the subject is installed",
                    True, phase, [], plugins)
    else:
        expected = sorted(store.data["facts"].get("expected_active_post", []))
        if store.data["subject"].get("kind") == "locale-baseline":
            # The Persian baseline leg applies a locale fixture and installs no
            # plugin, so "the subject is still active" has no meaning here. What
            # must hold instead is that the plugin set is STILL empty — a locale
            # leg that quietly ended up with an active plugin would be a
            # different environment from the one it claims to measure.
            store.check("active_plugins_baseline_clean_post", plugins == [], HARNESS,
                        "the locale fixture installs no plugin, so the active-plugin set "
                        "must still be empty after the fixture is applied",
                        True, phase, [], plugins)
        else:
            store.check("subject_active_after_activation",
                        bool(expected) and all(any(p.startswith(e + "/") or p == e
                                                   for p in plugins) for e in expected),
                        PRODUCT, "every expected plugin must still be active after activation",
                        True, phase, expected, plugins)

    # -- database ----------------------------------------------------------- #
    ok_count, count, diag_count = db_count(
        db_name, "SELECT COUNT(*) FROM information_schema.tables "
                 "WHERE table_schema = DATABASE()")
    store.fact(f"table_count_{phase}", count if ok_count else -1)
    store.record(f"table_count_{phase}", PASS if ok_count else FAIL, ENVIRONMENT,
                 diag_count or "table count in this subject's own database", True, phase,
                 observed=count if ok_count else diag_count)

    # -- public surfaces ---------------------------------------------------- #
    front = http_request(f"{base_url}/")
    store.fact(f"front_page_status_{phase}", front["status"])
    store.check(f"front_page_http_200_{phase}",
                front["status"] == 200 and not front["transport_error"],
                PRODUCT if phase == "post" else ENVIRONMENT,
                front["transport_error"] or {"final_url": front["final_url"]}, True, phase,
                200, front["status"] or front["transport_error"])
    store.check(f"no_redirect_loop_front_{phase}", not front["looped"],
                PRODUCT if phase == "post" else ENVIRONMENT,
                front["loop_reason"] or {"redirects": front["redirects"],
                                         "chain": front["chain"][:6]}, True, phase,
                observed={"looped": front["looped"], "redirects": front["redirects"]})

    rest = http_request(f"{base_url}/?rest_route=/")
    store.check(f"rest_index_reachable_{phase}",
                rest["status"] == 200 and '"namespaces"' in rest["body"],
                PRODUCT if phase == "post" else ENVIRONMENT,
                {"status": rest["status"], "transport_error": rest["transport_error"],
                 "body_head": rest["body"][:160]}, True, phase,
                "HTTP 200 JSON index", rest["status"])

    ajax = admin_ajax_probe(base_url)
    store.fact(f"admin_ajax_{phase}", ajax)
    store.check(f"admin_ajax_wp_layer_reachable_{phase}", ajax["reached_wp_layer"],
                PRODUCT if phase == "post" else ENVIRONMENT,
                "admin-ajax with no action must be answered by WordPress itself (body '0', "
                "HTTP 200/400); a correct application response, not a failure", True, phase,
                "HTTP 200/400 with body '0'",
                {"status": ajax["status"], "body": ajax["body"]})
    store.check(f"admin_ajax_negative_control_{phase}", ajax["negative_distinguishable"],
                HARNESS, "a path that cannot exist must 404, proving that answer is WordPress",
                True, phase, 404, ajax["negative_status"])

    login = admin_login_session(base_url)
    store.fact(f"admin_login_{phase}", login)
    store.check(f"admin_login_session_{phase}", login["ok"],
                PRODUCT if phase == "post" else ENVIRONMENT, login["error"], True, phase,
                "HTTP 200 on /wp-admin/ with auth marker",
                {"login": login["login_status"], "admin": login["admin_status"]})
    if phase == "post":
        store.check("wp_admin_reachable_post", login["admin_status"] == 200, PRODUCT,
                    login["error"], True, phase, 200, login["admin_status"])

    # -- PHP diagnostics ---------------------------------------------------- #
    log = debug_log_state(args.wp_dir)
    store.fact(f"debug_log_{phase}", log)
    summary = {k: log[k] for k in ("exists", "lines", "fatal", "warning")}
    if phase == "pre":
        store.record("php_debug_log_baseline_pre", PASS if log["fatal"] == 0 else FAIL,
                     ENVIRONMENT, "debug-log baseline before the subject is installed",
                     True, phase, observed=summary)
    else:
        store.check("no_php_fatal_after_activation", log["fatal"] == 0, PRODUCT,
                    log["samples"][:10], True, phase, 0, log["fatal"])
        if log["warning"]:
            store.record("php_warnings_after_activation", FAIL, PRODUCT,
                         "non-fatal PHP diagnostics recorded; never fails the subject alone",
                         False, phase, observed=log["warning"])

    # -- CPMS absence ------------------------------------------------------- #
    absence = cpms_absence(args.wp_dir, db_name)
    store.fact(f"cpms_absence_{phase}", absence)
    store.check(f"cpms_plugin_directory_absent_{phase}",
                not absence["plugin_dir_present"] and not absence["plugin_files_found"],
                HARNESS, absence["plugin_files_found"], True, phase,
                "no clinic-practice-management directory or plugin file",
                absence["plugin_files_found"])
    store.check(f"cpms_plugin_entry_absent_{phase}",
                absence["active_plugins_readable"] and not absence["plugin_entries_found"],
                HARNESS, absence["plugin_entries_found"], True, phase,
                "the active-plugins option is readable and contains no CPMS entry",
                absence["plugin_entries_found"])
    store.check(f"cpms_tables_absent_{phase}", absence["table_count"] == 0, HARNESS,
                "no CPMS table may exist in this subject's database", True, phase,
                0, absence["table_count"])
    store.check(f"cpms_options_absent_{phase}", absence["cpms_option_count"] == 0, HARNESS,
                "no cpms_* option may exist", True, phase, 0, absence["cpms_option_count"])

    # -- WP-CLI bootstrap health, as its own product-behaviour check -------- #
    # Legitimate compatibility evidence: a subject that makes WP-CLI fatal is a
    # real finding. It is recorded here as product behaviour instead of being
    # allowed to masquerade as a broken environment probe.
    bootstrap = wp_cli(["eval", "echo 1;"], args.wp_dir, timeout=120)
    store.fact(f"wp_cli_bootstrap_{phase}",
               {"rc": bootstrap["rc"], "stderr": bootstrap["stderr"][:800]})
    store.check(f"wp_cli_bootstrap_{phase}",
                bootstrap["rc"] == 0 and "1" in (bootstrap["stdout"] or ""),
                PRODUCT if phase == "post" else ENVIRONMENT,
                (bootstrap["stderr"] or "").strip()[:600], True, phase,
                "WP-CLI must bootstrap", bootstrap["rc"])

    annotate_failures(store, start)
    store.save()
    print(f"[probe:wp] phase={phase} checks={len(store.data['checks'])}")
    return 0


# --------------------------------------------------------------------------- #
# subcommand: install
# --------------------------------------------------------------------------- #
def cmd_install(args: argparse.Namespace) -> int:
    """Retrieve the exact pinned package(s), verify them, activate them.

    Fails closed: a retrieval failure is never answered by installing latest or
    another version, and a version mismatch is a hard class D failure.
    """
    store = Store(args.store)
    phase = "install"
    start = len(store.data["checks"])
    os.makedirs(args.package_dir, exist_ok=True)

    pairs: List[tuple[str, str, str]] = []
    for dep in [d for d in (args.deps or "").split(",") if d and d != "-"]:
        slug, _, version = dep.partition("=")
        pairs.append((slug, version, "dependency"))
    pairs.append((args.subject_slug, args.subject_version, "subject"))

    provenance: List[Dict[str, Any]] = []
    installed: List[str] = []
    hard_fail = False

    for slug, version, role in pairs:
        url = WORDPRESS_ORG_PACKAGE.format(slug=slug, version=version)
        entry: Dict[str, Any] = {
            "slug": slug, "role": role, "requested_version": version,
            "installed_version": "", "source_type": "wordpress_org_versioned_package",
            "source_url": url, "sha256": "", "activation_rc": None, "activation_output": "",
        }
        provenance.append(entry)
        zip_path = os.path.join(args.package_dir, f"{slug}-{version}.zip")

        # -- 1. the official versioned ZIP, always tried first -------------- #
        try:
            entry["redirect_chain"] = download(url, zip_path)
        except RetrievalError as exc:
            entry["versioned_url_status"] = exc.status
            if exc.status != 404 or slug not in OFFICIAL_STABLE_FALLBACK_SLUGS:
                # Unchanged for every other subject, and unchanged for any
                # non-404 failure on these two: fail closed, no substitution.
                store.check(f"package_retrieved::{slug}", False, HARNESS,
                            f"{exc}. Failing closed: no latest-version and no "
                            "alternate-version substitution. Separate C "
                            "(transport/provider) from D (bad pin).",
                            True, phase, url, str(exc))
                hard_fail = True
                continue

            # -- 2. confirmed 404 on an allowlisted slug: official stable ---- #
            # The version tag does not exist on WordPress.org, so the official
            # plugins API is asked for the canonical slug's own download_link —
            # and made to prove the version first.
            store.record(f"package_versioned_url_404::{slug}", INFO, HARNESS,
                         "the official versioned ZIP returned a confirmed HTTP 404; this "
                         "slug publishes no version tag, so the official stable package is "
                         "retrieved instead after the API proves the version",
                         phase=phase, observed={"url": url, "status": exc.status})

            info = wordpress_org_plugin_info(slug)
            source_url, rejection = validate_official_stable_source(slug, version, info)
            entry["api_slug"] = info.get("slug", "")
            entry["api_version_at_retrieval"] = info.get("version", "")
            entry["api_download_link"] = info.get("download_link", "")
            entry["api_rejection"] = rejection
            store.check(f"official_stable_source_validated::{slug}", bool(source_url), HARNESS,
                        rejection or "the official API must return the exact canonical slug, "
                                     "a stable version equal to the frozen pin, and an "
                                     "official WordPress.org distribution URL",
                        True, phase,
                        {"slug": slug, "version": version,
                         "host": WORDPRESS_ORG_DOWNLOAD_HOST},
                        {"api_slug": entry["api_slug"],
                         "api_version": entry["api_version_at_retrieval"],
                         "download_link": entry["api_download_link"],
                         "rejection": rejection})
            if not source_url:
                hard_fail = True
                continue

            try:
                entry["redirect_chain"] = download(
                    source_url, zip_path,
                    allowed_redirect_hosts=frozenset({WORDPRESS_ORG_DOWNLOAD_HOST}))
            except RetrievalError as exc2:
                entry["source_url"] = source_url
                store.check(f"package_retrieved::{slug}", False, HARNESS,
                            f"{exc2}. Failing closed: the official stable package could not "
                            "be retrieved, and no mirror, GitHub source, vendor source or "
                            "alternate version is substituted for it.",
                            True, phase, source_url, str(exc2))
                hard_fail = True
                continue
            entry["source_type"] = "wordpress_org_official_stable_fallback"
            entry["source_url"] = source_url

        # -- 3. identity, then SHA-256, then install ------------------------- #
        entry["sha256"] = sha256_of(zip_path)
        store.record(f"package_sha256::{slug}", PASS, HARNESS,
                     "SHA-256 of the exact retrieved package", phase=phase,
                     observed=entry["sha256"])

        identity = inspect_zip_plugin_identity(zip_path, slug, version)
        entry["package_identity"] = identity
        store.check(f"package_identity_verified::{slug}", identity["ok"], HARNESS,
                    identity["reason"] or "the package must contain one top-level directory "
                                          "named after the frozen slug, with a Plugin Name "
                                          "header whose Version equals the frozen pin",
                    True, phase, {"slug": slug, "version": version}, identity)
        if not identity["ok"]:
            hard_fail = True
            os.remove(zip_path)
            continue

        if entry["source_type"] == "wordpress_org_official_stable_fallback":
            # The API is asked a SECOND time, immediately before install: if the
            # provider moved its stable version between metadata retrieval and
            # installation, the bytes already downloaded no longer correspond to
            # the pin and must not be installed.
            recheck = wordpress_org_plugin_info(slug)
            recheck_version = recheck.get("version", "")
            entry["api_version_at_install"] = recheck_version
            stable_now = (recheck_version == version
                          and recheck.get("slug") == slug
                          and not recheck.get("error"))
            store.check(f"official_stable_version_unchanged::{slug}", stable_now, HARNESS,
                        recheck.get("error", "")
                        or "the official stable version must not move between metadata "
                           "retrieval and installation",
                        True, phase, version, recheck_version or recheck.get("error"))
            if not stable_now:
                hard_fail = True
                os.remove(zip_path)
                continue

        install = wp_cli(["plugin", "install", zip_path], args.wp_dir)
        if install["rc"] != 0:
            store.check(f"package_installed::{slug}", False, HARNESS,
                        (install["stdout"] + install["stderr"])[:1200], True, phase, 0,
                        install["rc"])
            hard_fail = True
            os.remove(zip_path)
            continue
        installed_version = (wp_cli(["plugin", "get", slug, "--field=version"],
                                    args.wp_dir)["stdout"] or "").strip()
        entry["installed_version"] = installed_version
        store.check(f"installed_version_exact::{slug}", installed_version == version, HARNESS,
                    "installed version must equal the requested pin exactly", True, phase,
                    version, installed_version)
        if installed_version != version:
            hard_fail = True

        # The installed slug is verified from the tree on disk, not from what
        # WP-CLI claims: the directory must be named after the frozen slug and the
        # package inside must carry a `Plugin Name` header. This is what proves
        # the bytes that were downloaded are the plugin the subject names — and it
        # still works if the subject makes WP-CLI fatal.
        installed_headers = [h for h in read_plugin_headers(args.wp_dir)
                             if h.get("slug") == slug]
        entry["installed_header_version"] = (installed_headers[0].get("version", "")
                                             if installed_headers else "")
        entry["installed_header_name"] = (installed_headers[0].get("name", "")
                                          if installed_headers else "")
        store.check(f"installed_slug_present::{slug}", bool(installed_headers), HARNESS,
                    f"a plugin directory named exactly {slug!r} with a `Plugin Name` header "
                    "must exist under wp-content/plugins after installation",
                    True, phase, slug, entry["installed_header_name"] or "not found")
        if not installed_headers:
            hard_fail = True

        # The package is deleted once installed; it never reaches an artifact.
        os.remove(zip_path)
        installed.append(slug)

    if not hard_fail:
        for slug in installed:
            activation = wp_cli(["plugin", "activate", slug], args.wp_dir)
            for entry in provenance:
                if entry["slug"] == slug:
                    entry["activation_rc"] = activation["rc"]
                    entry["activation_output"] = (activation["stdout"]
                                                  + activation["stderr"])[-4000:]
            store.check(f"activation_succeeded::{slug}", activation["rc"] == 0, PRODUCT,
                        (activation["stdout"] + activation["stderr"])[:800], True, phase,
                        0, activation["rc"])

    # Dependency evidence is read from the exact installed package's own header,
    # straight off disk — branding is never accepted as proof of a dependency,
    # and a subject that breaks WP-CLI cannot erase the evidence about itself.
    headers = read_plugin_headers(args.wp_dir) if not hard_fail else []
    store.check("plugin_header_metadata_read", bool(headers) or hard_fail, HARNESS,
                "dependency needs must come from the exact package metadata", True, phase,
                observed=len(headers))
    for header in headers:
        store.record(f"requires_plugins::{header['slug']}", INFO, PRODUCT,
                     "declared `Requires Plugins` header of the exact installed package; "
                     "recorded as evidence instead of assuming a dependency",
                     phase=phase, observed=header["requires_plugins"])

    annotate_failures(store, start)
    store.fact("provenance", provenance)
    store.fact("expected_active_post", [e["slug"] for e in provenance if e["installed_version"]])
    store.fact("plugin_headers", headers)

    with open(os.path.join(args.package_dir, "provenance.json"), "w", encoding="utf-8") as h:
        json.dump(provenance, h, indent=2)
        h.write("\n")
    store.save()

    print(f"[probe:install] pairs={len(pairs)} installed={installed} hard_fail={hard_fail}")
    return 1 if hard_fail else 0


# --------------------------------------------------------------------------- #
# subcommand: locale
# --------------------------------------------------------------------------- #
def cmd_locale(args: argparse.Namespace) -> int:
    """Persian (fa_IR) WordPress baseline probes.

    Runs on the ``locale-baseline`` leg only.  That leg installs no third-party
    product: its subject under test is the Persian WordPress environment itself,
    so every check here is classified ``environment`` (category C) unless the
    probe plumbing itself breaks, which is ``harness-fixture`` (category D).

    The distinction this whole subcommand exists to enforce is between a locale
    that is *configured* and a locale that is *actually loaded*.  Configuring is
    one option row; loading is what a browser receives.  So every claim below is
    read from the served document, from the database, or from PHP and the
    filesystem directly — never from ``wp eval``, and never inferred from the
    setting that was just written.
    """
    store = Store(args.store)
    phase = args.phase
    db_name = args.db_name
    base_url = args.wp_url.rstrip("/")
    start = len(store.data["checks"])
    store.fact(f"locale_probes_{phase}_executed", True)
    store.fact("locale_expected", {"WPLANG": args.expect_locale,
                                   "timezone_string": args.expect_timezone})

    # -- exact environment identity, without the WordPress runtime ---------- #
    wp_version = read_wp_core_version(args.wp_dir)
    runtime = web_runtime_probe(args.probe_url)
    ok_mysql, mysql_version, diag_mysql = scalar("SELECT VERSION()", db_name)
    mysql_major = mysql_version.split(".")[0] if ok_mysql else ""
    store.fact("fa_environment_identity", {
        "wordpress": wp_version, "php_web": runtime["php_version"],
        "mysql": mysql_version, "probe_transport_error": runtime["transport_error"],
    })
    identity_ok = (wp_version == args.expect_wp_version
                   and runtime["php_version"].startswith(args.expect_php)
                   and mysql_major == args.expect_mysql_major
                   and not runtime["transport_error"])
    store.check("fa_environment_identity_exact", identity_ok, ENVIRONMENT,
                diag_mysql or runtime["transport_error"]
                or "the Persian fixture must run on the exact frozen lane pins",
                True, phase,
                {"wordpress": args.expect_wp_version, "php": args.expect_php,
                 "mysql_major": args.expect_mysql_major},
                {"wordpress": wp_version, "php": runtime["php_version"],
                 "mysql_major": mysql_major})
    store.check("fa_mysql_major_version_exact", ok_mysql and mysql_major == args.expect_mysql_major,
                ENVIRONMENT, diag_mysql or "MySQL must be the frozen major version",
                True, phase, args.expect_mysql_major, mysql_major or diag_mysql)

    # -- the fixture's own settings, read back from the database ------------ #
    ok_lang, wp_lang, diag_lang = db_option(db_name, "WPLANG")
    store.fact("fa_wp_lang_option", wp_lang)
    store.check("fa_locale_option_exact", ok_lang and wp_lang == args.expect_locale,
                ENVIRONMENT, diag_lang or "the WPLANG option must equal the frozen locale pin",
                True, phase, args.expect_locale, wp_lang or diag_lang)

    ok_tz, site_tz, diag_tz = db_option(db_name, "timezone_string")
    store.fact("fa_timezone_option", site_tz)
    store.check("fa_timezone_option_exact", ok_tz and site_tz == args.expect_timezone,
                ENVIRONMENT,
                diag_tz or "timezone_string must equal the explicit fixture timezone",
                True, phase, args.expect_timezone, site_tz or diag_tz)

    ok_gmt, gmt_offset, _diag_gmt = db_option(db_name, "gmt_offset")
    store.fact("fa_gmt_offset_option", gmt_offset)
    store.record("fa_gmt_offset_recorded", INFO, ENVIRONMENT,
                 "the derived gmt_offset stored alongside timezone_string; recorded as "
                 "evidence, never hand-set to a guessed value", phase=phase,
                 observed={"gmt_offset": gmt_offset if ok_gmt else _diag_gmt})

    # The pinned IANA zone must be a timezone PHP itself accepts, and the offset
    # it yields is recorded rather than assumed.
    tz_probe = run(["php", "-r",
                    "$tz = new DateTimeZone($argv[1]);"
                    "$now = new DateTime('now', $tz);"
                    "printf('%s %d %s', $tz->getName(), $now->getOffset(),"
                    " $now->format('Y-m-d H:i:s'));",
                    args.expect_timezone])
    tz_out = (tz_probe["stdout"] or "").strip()
    ok_php_tz = tz_probe["rc"] == 0 and tz_out.startswith(args.expect_timezone + " ")
    offset_seconds = tz_out.split(" ")[1] if ok_php_tz and len(tz_out.split(" ")) > 1 else ""
    store.fact("fa_php_timezone_probe", {"rc": tz_probe["rc"], "output": tz_out,
                                         "offset_seconds": offset_seconds,
                                         "stderr": tz_probe["stderr"][:300]})
    store.check("fa_timezone_valid_in_php", ok_php_tz, ENVIRONMENT,
                (tz_probe["stderr"] or "").strip()[:400]
                or "PHP must accept the pinned IANA timezone", True, phase,
                args.expect_timezone, tz_out or tz_probe["stderr"][:200])
    if ok_php_tz and gmt_offset:
        # Cross-check the stored option against what PHP derives, so a stale or
        # guessed gmt_offset is visible in the evidence instead of silently
        # disagreeing with the IANA zone the site claims.
        try:
            derived = round(int(offset_seconds) / 3600, 2)
            stored = round(float(gmt_offset), 2)
            store.check("fa_gmt_offset_agrees_with_php", derived == stored, ENVIRONMENT,
                        "the stored gmt_offset must agree with the offset PHP derives "
                        "from the pinned IANA zone", True, phase, derived, stored)
        except ValueError:
            store.record("fa_gmt_offset_agrees_with_php", UNEXECUTED, HARNESS,
                         "gmt_offset was not numeric, so the cross-check could not run",
                         False, phase, observed={"gmt_offset": gmt_offset})

    # -- the translation package actually landed on disk -------------------- #
    languages_dir = os.path.join(args.wp_dir, "wp-content", "languages")
    required_mo = [f"{args.expect_locale}.mo", f"admin-{args.expect_locale}.mo"]
    present = sorted(n for n in (os.listdir(languages_dir)
                                 if os.path.isdir(languages_dir) else [])
                     if args.expect_locale in n)
    sizes = {n: os.path.getsize(os.path.join(languages_dir, n)) for n in present}
    missing = [n for n in required_mo if sizes.get(n, 0) <= 0]
    store.fact("fa_translation_files", {"dir": languages_dir, "sizes": sizes,
                                        "missing": missing})
    store.check("fa_core_translation_files_present", not missing, ENVIRONMENT,
                "the official core translation catalogues for this locale must be on "
                "disk and non-empty", True, phase, required_mo,
                {"missing": missing, "sizes": sizes})

    # -- is the locale ACTUALLY LOADED in a served document? ---------------- #
    login_page = http_request(f"{base_url}/wp-login.php", body_limit=LOCALE_BODY_LIMIT)
    login_root = html_root_attributes(login_page["body"])
    login_text = persian_text_evidence(login_page["body"])
    store.fact("fa_login_page", {"status": login_page["status"],
                                 "content_type": login_page["content_type"],
                                 "redirects": login_page["redirects"],
                                 "root": login_root, "text": login_text,
                                 "transport_error": login_page["transport_error"]})
    store.check("fa_login_page_locale_loaded",
                login_page["status"] == 200 and login_root["lang"] == "fa-IR"
                and login_text["persian_char_count"] >= 20, ENVIRONMENT,
                login_page["transport_error"]
                or "wp-login.php must be served with lang=fa-IR and real Persian text; "
                   "a configured option that never reaches the document is not a loaded "
                   "locale", True, phase,
                {"status": 200, "lang": "fa-IR", "min_persian_chars": 20},
                {"status": login_page["status"], "lang": login_root["lang"],
                 **login_text})
    store.check("fa_login_page_rtl", login_root["dir"] == "rtl", ENVIRONMENT,
                "the login document must declare dir=rtl", True, phase,
                "rtl", login_root["dir"] or login_root["html_tag"])
    store.check("fa_login_page_content_type_utf8", charset_of(login_page["content_type"]) == "utf-8",
                ENVIRONMENT, "Persian text requires a UTF-8 response charset", True, phase,
                "utf-8", charset_of(login_page["content_type"]) or login_page["content_type"])

    # -- authenticated wp-admin: locale, RTL and a live session ------------- #
    jar = http.cookiejar.CookieJar()
    login = admin_login_session(base_url)
    store.fact("fa_admin_login", login)
    store.check("fa_admin_login_session", login["ok"], ENVIRONMENT, login["error"],
                True, phase, "HTTP 200 on /wp-admin/ with the auth marker",
                {"login": login["login_status"], "admin": login["admin_status"],
                 "final_url": login["admin_final_url"]})

    http_request(f"{base_url}/wp-login.php", cookie_jar=jar)
    payload = urllib.parse.urlencode({
        "log": os.environ.get("TPB_ADMIN_USER", ""),
        "pwd": os.environ.get("TPB_ADMIN_PASS", ""),
        "wp-submit": "Log In", "redirect_to": f"{base_url}/wp-admin/", "testcookie": "1",
    }).encode()
    http_request(f"{base_url}/wp-login.php", cookie_jar=jar, data=payload,
                 headers={"Referer": f"{base_url}/wp-login.php"})
    admin_page = http_request(f"{base_url}/wp-admin/", cookie_jar=jar,
                              body_limit=LOCALE_BODY_LIMIT)
    admin_root = html_root_attributes(admin_page["body"])
    admin_text = persian_text_evidence(admin_page["body"])
    store.fact("fa_admin_page", {"status": admin_page["status"],
                                 "final_url": admin_page["final_url"],
                                 "content_type": admin_page["content_type"],
                                 "redirects": admin_page["redirects"],
                                 "root": admin_root, "text": admin_text,
                                 "transport_error": admin_page["transport_error"]})
    store.check("fa_admin_locale_loaded",
                admin_page["status"] == 200 and admin_root["lang"] == "fa-IR"
                and admin_text["persian_char_count"] >= 50, ENVIRONMENT,
                admin_page["transport_error"]
                or "wp-admin must be served with lang=fa-IR and real Persian text",
                True, phase, {"status": 200, "lang": "fa-IR", "min_persian_chars": 50},
                {"status": admin_page["status"], "lang": admin_root["lang"], **admin_text})
    store.check("fa_admin_rtl", admin_root["dir"] == "rtl", ENVIRONMENT,
                "the WordPress admin document must declare dir=rtl", True, phase,
                "rtl", admin_root["dir"] or admin_root["html_tag"])
    store.check("fa_admin_content_type_utf8", charset_of(admin_page["content_type"]) == "utf-8",
                ENVIRONMENT, "Persian text requires a UTF-8 response charset", True, phase,
                "utf-8", charset_of(admin_page["content_type"]) or admin_page["content_type"])

    # -- UTF-8 round trip: database -> WordPress -> REST and -> public HTML -- #
    # The exact string is the harness's own, so a match cannot be an accident of
    # whichever translation happens to be installed.
    guid = f"{base_url}/?p={LOCALE_PROBE_GUID_ID}"
    ok_existing, existing_id, _diag_existing = scalar(
        f"SELECT ID FROM {DB_PREFIX}posts WHERE post_name='{LOCALE_PROBE_SLUG}'", db_name)
    post_id = existing_id if ok_existing and existing_id else ""
    if not post_id:
        insert = mysql_query(
            "INSERT INTO " + DB_PREFIX + "posts "
            "(post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt,"
            " post_status, comment_status, ping_status, post_password, post_name, to_ping,"
            " pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent,"
            " guid, menu_order, post_type, post_mime_type, comment_count) VALUES"
            f" (1, NOW(), UTC_TIMESTAMP(), '{LOCALE_PROBE_BODY}', '{LOCALE_PROBE_TITLE}',"
            f" '', 'publish', 'open', 'open', '', '{LOCALE_PROBE_SLUG}', '', '',"
            f" NOW(), UTC_TIMESTAMP(), '', 0, '{guid}', 0, 'post', '', 0)", db_name)
        if insert["rc"] != 0:
            store.check("fa_utf8_fixture_created", False, HARNESS,
                        (insert["stderr"] or insert["stdout"])[:600], True, phase,
                        0, insert["rc"])
        else:
            ok_id, post_id, diag_id = scalar(
                f"SELECT ID FROM {DB_PREFIX}posts WHERE post_name='{LOCALE_PROBE_SLUG}'",
                db_name)
            store.check("fa_utf8_fixture_created", ok_id and bool(post_id), HARNESS,
                        diag_id, True, phase, "a post id", post_id or diag_id)

    ok_back, read_back, diag_back = scalar(
        f"SELECT post_title FROM {DB_PREFIX}posts WHERE post_name='{LOCALE_PROBE_SLUG}'",
        db_name)
    store.fact("fa_utf8_database", {"post_id": post_id, "read_back": read_back})
    store.check("fa_utf8_database_round_trip",
                ok_back and read_back == LOCALE_PROBE_TITLE, ENVIRONMENT,
                diag_back or "the Persian title must survive the MySQL utf8mb4 round trip "
                             "byte for byte", True, phase, LOCALE_PROBE_TITLE,
                read_back or diag_back)

    rest = http_request(f"{base_url}/?rest_route=/wp/v2/posts&slug={LOCALE_PROBE_SLUG}",
                        body_limit=LOCALE_BODY_LIMIT)
    decoded_title, parse_error = "", ""
    try:
        parsed = json.loads(rest["body"])
        decoded_title = (parsed[0].get("title", {}).get("rendered", "")
                         if isinstance(parsed, list) and parsed else "")
    except (json.JSONDecodeError, AttributeError, TypeError, IndexError) as exc:
        parse_error = f"{type(exc).__name__}: {exc}"
    store.fact("fa_utf8_rest", {"status": rest["status"], "decoded_title": decoded_title,
                                "content_type": rest["content_type"],
                                "transport_error": rest["transport_error"],
                                "parse_error": parse_error})
    store.check("fa_utf8_rest_round_trip",
                rest["status"] == 200 and decoded_title == LOCALE_PROBE_TITLE, ENVIRONMENT,
                rest["transport_error"] or parse_error
                or "the Persian title must come back through the REST API exactly as stored",
                True, phase, LOCALE_PROBE_TITLE,
                {"status": rest["status"], "decoded_title": decoded_title,
                 "parse_error": parse_error})

    public_page = http_request(f"{base_url}/?p={post_id or LOCALE_PROBE_GUID_ID}",
                               body_limit=LOCALE_BODY_LIMIT)
    public_text = persian_text_evidence(public_page["body"])
    store.fact("fa_public_page", {"status": public_page["status"],
                                  "title_present": LOCALE_PROBE_TITLE in public_page["body"],
                                  "text": public_text,
                                  "transport_error": public_page["transport_error"]})
    store.check("fa_public_page_renders_persian",
                public_page["status"] == 200
                and LOCALE_PROBE_TITLE in public_page["body"]
                and public_text["persian_char_count"] >= 20, ENVIRONMENT,
                public_page["transport_error"]
                or "the public page must render the Persian title as raw UTF-8 in the "
                   "served HTML", True, phase,
                {"status": 200, "title": LOCALE_PROBE_TITLE},
                {"status": public_page["status"],
                 "title_present": LOCALE_PROBE_TITLE in public_page["body"],
                 **public_text})

    # -- no unexpected redirects -------------------------------------------- #
    front = http_request(f"{base_url}/", body_limit=LOCALE_BODY_LIMIT)
    front_root = html_root_attributes(front["body"])
    front_text = persian_text_evidence(front["body"])
    chains = {"front": front["chain"][:6], "login": login_page["chain"][:6],
              "admin": admin_page["chain"][:6]}
    store.fact("fa_redirects", {"front_status": front["status"],
                                "front_redirects": front["redirects"],
                                "front_root": front_root,
                                "front_text": front_text, "chains": chains,
                                "looped": front["looped"] or login_page["looped"]
                                or admin_page["looped"]})
    no_redirects = (front["redirects"] == 0 and login_page["redirects"] == 0
                    and admin_page["redirects"] == 0
                    and not front["looped"] and not login_page["looped"]
                    and not admin_page["looped"])
    store.check("fa_no_unexpected_redirect", no_redirects, ENVIRONMENT,
                "a Persian locale must not introduce a redirect on the front page, the "
                "login page or the admin; any hop is recorded with its chain",
                True, phase, 0, chains)
    store.check("fa_front_page_locale_and_rtl",
                front["status"] == 200 and front_root["lang"] == "fa-IR"
                and front_root["dir"] == "rtl"
                and front_text["persian_char_count"] >= 1, ENVIRONMENT,
                front["transport_error"]
                or "the public front page must serve HTTP 200 and declare the Persian "
                   "locale and direction. The character count alone is deliberately not "
                   "the signal: post content is Persian regardless of the active locale, "
                   "so only the document's own lang/dir attributes prove the locale was "
                   "applied to a public surface",
                True, phase,
                {"status": 200, "lang": "fa-IR", "dir": "rtl"},
                {"status": front["status"], "lang": front_root["lang"],
                 "dir": front_root["dir"], **front_text})

    # -- explicit scope boundary, recorded in the artifact itself ----------- #
    # Not a claim, and not a gap to be read as a failure: this leg measures a
    # Persian WordPress with CPMS ABSENT. Everything below needs CPMS and is
    # deliberately not exercised here.
    store.record("locale_baseline_scope_boundary", INFO, HARNESS,
                 "this leg measures WordPress + fa_IR + Asia/Tehran with CPMS absent. "
                 "NOT tested here, because each requires CPMS and belongs to a later "
                 "coexistence slice", phase=phase, observed=[
                     "CPMS Jalali/Shamsi calendar rendering or date conversion",
                     "CPMS patient records, patient search, or any patient data",
                     "CPMS booking, slots, holds or visit scheduling",
                     "CPMS Location-level timezone authority over the site timezone",
                     "CPMS authorization, roles, capabilities or session policy",
                     "Clinic A vs Clinic B tenant isolation",
                 ])

    annotate_failures(store, start)
    store.save()
    print(f"[probe:locale] locale={args.expect_locale} tz={args.expect_timezone} "
          f"checks={len(store.data['checks'])}")
    return 0


# --------------------------------------------------------------------------- #
# subcommand: browser
# --------------------------------------------------------------------------- #
def cmd_browser(args: argparse.Namespace) -> int:
    try:
        from playwright.sync_api import sync_playwright
    except ImportError as exc:
        store = Store(args.store)
        store.record("browser_probe", UNEXECUTED, UNEXECUTED_KLASS,
                     f"Playwright unavailable: {exc}", False, args.phase)
        store.save()
        print("[probe:browser] UNEXECUTED - Playwright unavailable")
        return 0

    store = Store(args.store)
    phase = args.phase
    start = len(store.data["checks"])
    base_url = args.wp_url.rstrip("/")
    user, password = admin_credentials()

    console_errors: List[str] = []
    page_errors: List[str] = []
    failed_important: List[str] = []
    failed_other: List[str] = []
    notices: List[str] = []
    screenshots: List[str] = []
    nav: Dict[str, Any] = {}
    login: Dict[str, Any] = {"ok": False, "final_url": "", "error": ""}
    ajax: Dict[str, Any] = {}
    rest_status = 0
    document: Dict[str, Any] = {}

    def record_probe_failure(name: str, klass: str, detail: str, material: bool = True) -> None:
        """A browser sub-probe that could not run is recorded, not fatal.

        One subject breaking wp-login.php used to raise inside the single try
        block, which aborted every remaining browser probe AND was recorded as a
        harness-fixture failure. Exact-head run 37680547043 showed both effects on
        elementor and persian-woocommerce: `Page.fill` timed out waiting for
        input[name='log'] because the product had already replaced the login page
        with a PHP fatal. That is product behaviour with a downstream consequence,
        not a harness defect, and it must not cost the other browser evidence.
        """
        store.check(name, False, klass, detail[:600], material, phase)

    try:
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(args=["--no-sandbox"])
            context = browser.new_context(viewport={"width": 1366, "height": 900},
                                          user_agent=USER_AGENT)
            page = context.new_page()
            page.on("console", lambda m: console_errors.append(m.text[:300])
                    if m.type == "error" else None)
            page.on("pageerror", lambda e: page_errors.append(str(e)[:300]))

            def on_request_failed(request) -> None:  # noqa: ANN001
                entry = f"{request.resource_type} {request.url[:200]}"
                important = request.url.startswith(base_url) and request.resource_type in (
                    "document", "script", "stylesheet", "xhr", "fetch")
                (failed_important if important else failed_other).append(entry)

            page.on("requestfailed", on_request_failed)

            def walk_redirects(response) -> List[Dict[str, Any]]:  # noqa: ANN001
                chain: List[Dict[str, Any]] = []
                seen = set()
                current = response.request.redirected_from if response else None
                while current is not None and current.url not in seen:
                    seen.add(current.url)
                    chain.append({"url": current.url[:200]})
                    current = current.redirected_from
                return chain

            try:
                response = page.goto(f"{base_url}/", wait_until="domcontentloaded",
                                     timeout=BROWSER_TIMEOUT_MS)
                nav = {"front_status": response.status if response else 0,
                       "front_url": page.url, "front_redirects": walk_redirects(response)}
                page.screenshot(path=os.path.join(args.out_dir, "front.png"))
                screenshots.append("front.png")
            except Exception as exc:  # noqa: BLE001
                record_probe_failure("browser_front_page_navigated", PRODUCT,
                                     f"{type(exc).__name__}: {exc}")

            try:
                page.goto(f"{base_url}/wp-login.php", wait_until="domcontentloaded",
                          timeout=BROWSER_TIMEOUT_MS)
                # Ask whether the form exists instead of blindly filling it. A
                # subject that fataled has no login form, and waiting 30s for one
                # only converts product damage into an apparent harness defect.
                if page.query_selector("input[name='log']") is None:
                    login = {"ok": False, "final_url": page.url,
                             "error": "the login form is absent from wp-login.php after "
                                      "activation; recorded as product behaviour"}
                else:
                    page.fill("input[name='log']", user)
                    page.fill("input[name='pwd']", password)
                    page.click("input#wp-submit")
                    page.wait_for_load_state("domcontentloaded",
                                             timeout=BROWSER_TIMEOUT_MS)
                    login["final_url"] = page.url
                    login["ok"] = ("/wp-admin/" in page.url
                                   and "wp-login.php" not in page.url)
                    if not login["ok"]:
                        login["error"] = f"landed on {page.url}"
                    else:
                        page.screenshot(path=os.path.join(args.out_dir, "wp-admin.png"))
                        screenshots.append("wp-admin.png")
                        # Document direction and language of the ADMIN a real
                        # browser actually rendered. Only asked for on the
                        # locale-baseline leg; every other leg passes an empty
                        # --expect-dir and this block never runs, so their
                        # evidence is unchanged.
                        if args.expect_dir:
                            try:
                                probed = page.evaluate(
                                    "() => ({dir: document.documentElement.dir,"
                                    " lang: document.documentElement.lang,"
                                    " text: (document.body"
                                    " && document.body.innerText || '').slice(0, 20000)})")
                                document = {"dir": probed.get("dir", ""),
                                            "lang": probed.get("lang", ""),
                                            "text": persian_text_evidence(
                                                probed.get("text", "")),
                                            "url": page.url}
                            except Exception as exc:  # noqa: BLE001
                                document = {"dir": "", "lang": "", "url": page.url,
                                            "error": f"{type(exc).__name__}: {exc}"[:300]}
                        # Setup wizards / HTTPS recommendations / license and
                        # enrollment prompts are RECORDED here. They are never
                        # dismissed, bypassed or answered, and no product setting
                        # is weakened to reach green.
                        for element in page.query_selector_all(".notice, .updated, .error"):
                            text = (element.inner_text() or "").strip()
                            if text:
                                notices.append(text[:300])
            except Exception as exc:  # noqa: BLE001
                login = {"ok": False, "final_url": page.url,
                         "error": f"{type(exc).__name__}: {exc}"[:300]}

            try:
                rest = page.goto(f"{base_url}/?rest_route=/",
                                 wait_until="domcontentloaded", timeout=BROWSER_TIMEOUT_MS)
                rest_status = rest.status if rest else 0
            except Exception as exc:  # noqa: BLE001
                record_probe_failure("browser_rest_index_navigated", PRODUCT,
                                     f"{type(exc).__name__}: {exc}")

            try:
                api = context.request.get(f"{base_url}/wp-admin/admin-ajax.php",
                                          timeout=BROWSER_TIMEOUT_MS)
                body = api.text().strip()
                ajax = {"status": api.status, "body": body[:80],
                        "reached_wp_layer": api.status in (200, 400) and body == "0"}
            except Exception as exc:  # noqa: BLE001
                ajax = {"status": 0, "body": "", "reached_wp_layer": False,
                        "error": f"{type(exc).__name__}: {exc}"[:200]}
            browser.close()
    except Exception as exc:  # noqa: BLE001 - only a browser that cannot start at all
        store.check("browser_probe_executed", False, HARNESS,
                    f"the browser itself could not run: {type(exc).__name__}: {exc}"[:600],
                    True, phase)
        annotate_failures(store, start)
        store.save()
        return 0

    store.fact(f"browser_{phase}", {
        "nav": nav, "login": login, "rest_status": rest_status, "admin_ajax": ajax,
        "console_errors": console_errors[:50], "page_errors": page_errors[:50],
        "failed_important_requests": failed_important[:50],
        "failed_other_requests": failed_other[:50],
        "notices": notices[:30], "screenshots": screenshots,
        "document": document,
    })
    store.check("browser_front_page_render", nav.get("front_status") == 200, PRODUCT, nav,
                True, phase, 200, nav.get("front_status"))
    store.check("browser_no_redirect_loop",
                len(nav.get("front_redirects") or []) <= MAX_REDIRECTS, PRODUCT,
                nav.get("front_redirects"), True, phase,
                observed=len(nav.get("front_redirects") or []))
    store.check("browser_admin_login_session", login["ok"], PRODUCT, login["error"], True,
                phase, observed=login["final_url"])
    if args.expect_dir:
        # Locale-baseline leg only: prove the RTL admin in the browser that
        # rendered it, not only in the bytes served over HTTP.
        store.check("browser_admin_document_direction",
                    document.get("dir") == args.expect_dir, ENVIRONMENT,
                    document.get("error", "")
                    or "the rendered admin document must report the expected direction",
                    True, phase, args.expect_dir, document.get("dir", ""))
        store.check("browser_admin_document_lang",
                    (document.get("lang") or "").lower() == "fa-ir", ENVIRONMENT,
                    "the rendered admin document must declare the Persian locale",
                    True, phase, "fa-IR", document.get("lang", ""))
        admin_text = document.get("text") or {}
        store.check("browser_admin_persian_text_rendered",
                    admin_text.get("persian_char_count", 0) >= 50, ENVIRONMENT,
                    "the rendered admin must contain real Persian text, which is what "
                    "distinguishes a loaded locale from a configured one", True, phase,
                    ">= 50 Persian characters", admin_text)
    store.check("browser_rest_index", rest_status == 200, PRODUCT,
                "REST index must load in a real browser", True, phase, 200, rest_status)
    store.check("browser_admin_ajax_wp_layer", ajax.get("reached_wp_layer") is True, PRODUCT,
                "admin-ajax answered by WordPress with body '0' is a correct response", True,
                phase, observed=ajax)
    store.check("browser_no_uncaught_page_errors", not page_errors, PRODUCT, page_errors[:10],
                True, phase, [], page_errors[:10])
    for name, findings, detail in (
        ("browser_console_errors", console_errors,
         "console errors recorded; never fails the subject on its own"),
        ("browser_failed_important_requests", failed_important,
         "same-origin document/script/stylesheet/xhr/fetch requests failed"),
    ):
        if findings:
            store.record(name, FAIL, PRODUCT, detail, False, phase, observed=findings[:20])
    if failed_other:
        store.record("browser_failed_external_requests", INFO, PRODUCT,
                     "external-service requests failed; expected on a network-restricted "
                     "runner and never turned into a product failure", phase=phase,
                     observed=failed_other[:20])
    if notices:
        store.record("admin_notices_recorded", INFO, PRODUCT,
                     "setup wizards / HTTPS recommendations / license and enrollment prompts "
                     "recorded only - not bypassed, not answered, no setting weakened",
                     phase=phase, observed=notices[:20])

    annotate_failures(store, start)
    store.save()
    print(f"[probe:browser] console_errors={len(console_errors)} page_errors={len(page_errors)}")
    return 0


# --------------------------------------------------------------------------- #
# subcommand: finalize
# --------------------------------------------------------------------------- #
def classify(check: Dict[str, Any], kind: str = "") -> str:
    """Map a material failure to the frozen failure taxonomy.

    CPMS is never installed here, so category A (regression from current CPMS
    work) and B (pre-existing CPMS defect) are unreachable by construction and
    are never emitted.  Category E does not exist and is never invented.

    ``kind`` only distinguishes the label of a genuine product-class failure: the
    locale-baseline leg installs no third-party product, so calling one of its
    failures a third-party failure would be a misattribution.
    """
    if check["klass"] == ENVIRONMENT:
        return "C (infrastructure/environment)"
    if check["klass"] == HARNESS:
        return "D (test/test-infrastructure/fixture defect)"
    if check["klass"] == PRODUCT:
        return LOCALE_BASELINE if kind == "locale-baseline" else THIRD_PARTY_BASELINE
    return "unclassified"


def cmd_finalize(args: argparse.Namespace) -> int:
    store = Store(args.store)
    facts = store.data["facts"]
    subject = store.data["subject"]
    subject.setdefault("id", args.subject_id)

    if not args.not_run and facts.get("phase_post_executed") is not True:
        # A job that never reached the post-activation probes produced no
        # compatibility evidence. Record that gap loudly instead of reporting a
        # subject as passing on evidence it never collected.
        store.record("post_activation_probes_executed", FAIL, HARNESS,
                     "the post-activation probe phase never ran, so no compatibility "
                     "evidence exists for this subject in this environment",
                     True, "post", "post probes executed", "post probes absent")

    checks = store.data["checks"]
    counts: Dict[str, int] = {}
    for check in checks:
        counts[check["status"]] = counts.get(check["status"], 0) + 1

    if args.not_run:
        state, reason = NOT_RUN, args.not_run
    else:
        material_failures = [c for c in checks if c["material"] and c["status"] == FAIL]
        soft = [c for c in checks if c["status"] in (UNAVAILABLE, UNEXECUTED)
                or (c["status"] == FAIL and not c["material"])]
        if material_failures:
            state, reason = FAIL, ""
        elif soft:
            state = "PARTIAL"
            reason = "; ".join(sorted({f"{c['name']}={c['status']}" for c in soft}))[:600]
        else:
            state, reason = PASS, ""

    taxonomy = sorted({classify(c, subject.get("kind", ""))
                       for c in checks if c["status"] == FAIL and c["material"]})
    forbidden = [code for code in taxonomy if code[:2] in ("A ", "B ", "E ")]
    if forbidden:  # pragma: no cover - guarded invariant
        raise SystemExit(f"refusing to emit forbidden taxonomy category: {forbidden}")

    pre_tables, post_tables = facts.get("table_count_pre"), facts.get("table_count_post")
    env = os.environ
    run_url = (f"{env.get('GITHUB_SERVER_URL', '')}/{env.get('GITHUB_REPOSITORY', '')}"
               f"/actions/runs/{env.get('GITHUB_RUN_ID', '')}")
    result = {
        "schema": "cpms-phase18-third-party-baseline/v1",
        "subject": subject,
        "state": state,
        "reason": reason,
        "counts": counts,
        "taxonomy": taxonomy,
        "table_counts": {
            "before_activation": pre_tables, "after_activation": post_tables,
            "delta": (post_tables - pre_tables)
            if isinstance(pre_tables, int) and isinstance(post_tables, int) else None,
        },
        "cpms_absence": {"before": facts.get("cpms_absence_pre"),
                         "after": facts.get("cpms_absence_post")},
        "environment": {
            "lane": subject.get("lane", args.lane),
            "wordpress": facts.get("wp_core_version_post", facts.get("wp_core_version_pre")),
            "php_web": facts.get("web_php_version_post", facts.get("web_php_version_pre")),
            "php_cli": facts.get("cli_php_version_post", facts.get("cli_php_version_pre")),
            "php_sapi": facts.get("web_php_sapi_post", facts.get("web_php_sapi_pre")),
            "server_software": facts.get("server_software_post",
                                         facts.get("server_software_pre")),
            "mysql": facts.get("mysql_version_post", facts.get("mysql_version_pre")),
            "timezone": facts.get("timezone_post", facts.get("timezone_pre")),
            "locale": facts.get("locale_post", facts.get("locale_pre")),
        },
        "checks": checks,
        "facts": facts,
        # Deterministic follow-up rerun evidence. Any material FAIL must be
        # repeated once in a fresh environment before causal attribution; a
        # workflow_dispatch run of this workflow gives exactly that, because one
        # subject is one job with a fresh runner, container and WordPress.
        "reproduction": {
            "command": "gh workflow run third-party-baseline.yml",
            "note": "one subject == one job == fresh runner + fresh MySQL + fresh WordPress",
            "pinned_environment": {"wordpress": args.expect_wp_version, "php": args.expect_php,
                                   "mysql_image": args.mysql_image, "server": args.server},
            "subject": args.subject_id, "dependencies": args.deps,
        },
        "github": {"run_id": env.get("GITHUB_RUN_ID", ""),
                   "run_attempt": env.get("GITHUB_RUN_ATTEMPT", ""),
                   "job": env.get("GITHUB_JOB", ""), "head_sha": env.get("GITHUB_SHA", ""),
                   "ref": env.get("GITHUB_REF", ""), "run_url": run_url},
    }

    os.makedirs(os.path.dirname(args.out) or ".", exist_ok=True)
    with open(args.out, "w", encoding="utf-8") as handle:
        json.dump(result, handle, indent=2)
        handle.write("\n")
    store.save()

    # The state is mirrored into an annotation because the artifact that carries
    # it lives on blob storage, unreachable from outside the runner.
    annotate("notice" if state in (PASS, NOT_RUN) else "warning",
             f"SUBJECT {args.subject_id} STATE={state} counts={json.dumps(counts)} "
             f"taxonomy={json.dumps(taxonomy)}")
    print(f"[probe:finalize] {args.subject_id} -> {state}")
    if reason:
        print(f"[probe:finalize] reason: {reason}")
    return 0


# --------------------------------------------------------------------------- #
def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Phase 18 third-party compatibility baseline evidence probes.")
    sub = parser.add_subparsers(dest="command", required=True)

    subject_p = sub.add_parser("subject", help="record the frozen subject identity")
    subject_p.add_argument("--store", required=True)
    subject_p.add_argument("--id", required=True)
    subject_p.add_argument("--name", required=True)
    subject_p.add_argument("--slug", required=True)
    subject_p.add_argument("--version", required=True)
    subject_p.add_argument("--source", required=True)
    subject_p.add_argument("--kind", required=True)
    subject_p.add_argument("--deps", default="-")
    subject_p.add_argument("--lane", default=os.environ.get("LANE", "A"))
    subject_p.add_argument("--unavailable-feature", default="")
    subject_p.add_argument("--unavailable-reason", default="")
    # Empty for every product leg; only the locale-baseline leg pins them.
    subject_p.add_argument("--locale", default="")
    subject_p.add_argument("--site-timezone", default="")
    subject_p.set_defaults(func=cmd_subject)

    locale_p = sub.add_parser("locale",
                              help="Persian fa_IR locale / RTL / UTF-8 / timezone probes")
    locale_p.add_argument("--store", required=True)
    locale_p.add_argument("--phase", required=True, choices=["pre", "post"])
    locale_p.add_argument("--wp-dir", required=True)
    locale_p.add_argument("--wp-url", required=True)
    locale_p.add_argument("--probe-url", required=True)
    locale_p.add_argument("--db-name", required=True)
    locale_p.add_argument("--expect-locale", required=True)
    locale_p.add_argument("--expect-timezone", required=True)
    locale_p.add_argument("--expect-wp-version", required=True)
    locale_p.add_argument("--expect-php", required=True)
    locale_p.add_argument("--expect-mysql-major", default="8")
    locale_p.set_defaults(func=cmd_locale)

    wp_p = sub.add_parser("wp", help="WordPress / HTTP / database probes")
    wp_p.add_argument("--store", required=True)
    wp_p.add_argument("--phase", required=True, choices=["pre", "post"])
    wp_p.add_argument("--wp-dir", required=True)
    wp_p.add_argument("--wp-url", required=True)
    wp_p.add_argument("--probe-url", required=True)
    wp_p.add_argument("--db-name", required=True)
    wp_p.add_argument("--expect-wp-version", required=True)
    wp_p.add_argument("--expect-php", required=True)
    # Optional so the probe stays usable without a database pin; empty means the
    # exact-version check is simply not added, and nothing else changes.
    wp_p.add_argument("--expect-mysql", default="")
    wp_p.set_defaults(func=cmd_wp)

    install_p = sub.add_parser("install", help="retrieve, verify and activate exact pins")
    install_p.add_argument("--store", required=True)
    install_p.add_argument("--wp-dir", required=True)
    install_p.add_argument("--package-dir", required=True)
    install_p.add_argument("--subject-slug", required=True)
    install_p.add_argument("--subject-version", required=True)
    install_p.add_argument("--deps", default="-")
    install_p.set_defaults(func=cmd_install)

    browser_p = sub.add_parser("browser", help="real-browser probes")
    browser_p.add_argument("--store", required=True)
    browser_p.add_argument("--phase", required=True)
    browser_p.add_argument("--wp-url", required=True)
    browser_p.add_argument("--out-dir", required=True)
    # Empty for every product leg, so their browser evidence is unchanged; the
    # locale-baseline leg passes "rtl".
    browser_p.add_argument("--expect-dir", default="")
    browser_p.set_defaults(func=cmd_browser)

    final_p = sub.add_parser("finalize", help="derive the machine-readable subject result")
    final_p.add_argument("--store", required=True)
    final_p.add_argument("--out", required=True)
    final_p.add_argument("--subject-id", required=True)
    final_p.add_argument("--not-run", default="")
    final_p.add_argument("--deps", default="-")
    final_p.add_argument("--lane", default=os.environ.get("LANE", "A"))
    final_p.add_argument("--expect-wp-version", default=os.environ.get("WP_VERSION", ""))
    final_p.add_argument("--expect-php", default=os.environ.get("PHP_VERSION", ""))
    final_p.add_argument("--mysql-image", default="mysql:8.4.11")
    final_p.add_argument("--server", default="apache2-mod-php")
    final_p.set_defaults(func=cmd_finalize)

    return parser


def main() -> int:
    args = build_parser().parse_args()
    return int(args.func(args))


if __name__ == "__main__":
    sys.exit(main())
