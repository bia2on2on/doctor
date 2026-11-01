#!/usr/bin/env python3
"""Derived contract: every production rate-limit window prefix is owned.

This is NOT runtime WordPress acceptance. It proves, statically and from the
current production source, that the companion's closed `cpms_rate_limits`
ownership allow-list still covers *every* window prefix production can create
— either as a fixture-owned key or as a declared globally shared per-IP key.

Rationale: the fail-closed foreign-row check in `Teardown::verify()` blocks
teardown when a single `cpms_rate_limits` row is outside the fixture scope. When
production introduces a new window (as schema 0031 did with
`otp-send-guard:<sha256(mobile)>`), the allow-list silently stops covering it
and the teardown that is supposed to protect data starts blocking a clean site.
A hardcoded list here would rot the same way, so the prefixes are derived from
production on every run.
"""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PRODUCTION_SRC = ROOT / "clinic-practice-management" / "src"
COMPANION = ROOT / "tools" / "disposable-demo-installer"
PLATFORM = COMPANION / "includes" / "class-cpms-demo-platform.php"
TEARDOWN = COMPANION / "includes" / "class-cpms-demo-teardown.php"
SCOPED_TEARDOWN = COMPANION / "includes" / "class-cpms-demo-scoped-teardown.php"

# Where production calls a limiter with a literal prefix.
PRODUCTION_CALLS = (
    re.compile(r"->hit\(\s*'([^']+)'"),          # RateLimiter::hit('prefix' ...)
    re.compile(r"rateLimit\(\s*[^,]+,\s*'([^']+)'"),  # RestBase::rateLimit(..., 'prefix' ...)
    re.compile(r"isOver\(\s*'([^']+)'"),          # LoginRateLimiter::isOver('prefix' ...)
    re.compile(r"\$guard_key\s*=\s*'([^']+)'"),   # OtpService issue guard
)


def production_prefixes() -> dict[str, set[str]]:
    """Map every literal rate-limit prefix production can mint to its files."""
    found: dict[str, set[str]] = {}
    for path in sorted(PRODUCTION_SRC.rglob("*.php")):
        text = path.read_text(encoding="utf-8")
        if "cpms_rate_limits" not in text and "rateLimit(" not in text and "isOver(" not in text:
            continue
        for pattern in PRODUCTION_CALLS:
            for prefix in pattern.findall(text):
                found.setdefault(prefix, set()).add(path.name)
    return found


def shared_ip_prefixes() -> set[str]:
    """Shared per-IP prefixes, parsed from the platform predicate itself."""
    text = PLATFORM.read_text(encoding="utf-8")
    match = re.search(
        r"function shared_ip_rate_limits_where\(\).*?return array\((.*?)\);",
        text,
        re.S,
    )
    assert match is not None, "shared_ip_rate_limits_where() not found"
    return set(re.findall(r"window_key LIKE '([^%']+)%'", match.group(1)))


def owned_literals() -> tuple[str, str, str]:
    return (
        PLATFORM.read_text(encoding="utf-8"),
        TEARDOWN.read_text(encoding="utf-8"),
        SCOPED_TEARDOWN.read_text(encoding="utf-8"),
    )


class RateLimitOwnershipContract(unittest.TestCase):
    def test_production_prefixes_were_found(self) -> None:
        prefixes = production_prefixes()
        self.assertGreaterEqual(len(prefixes), 15, prefixes)
        self.assertIn("otp-send-guard:", prefixes)

    def test_shared_predicate_declares_the_per_ip_windows(self) -> None:
        shared = shared_ip_prefixes()
        self.assertEqual({"otp-ip:", "otp-verify-ip:", "login-ip:"}, shared)

    def test_every_production_prefix_is_owned_or_declared_shared(self) -> None:
        shared = shared_ip_prefixes()
        owned = owned_literals()
        unowned = {
            prefix: sorted(files)
            for prefix, files in production_prefixes().items()
            if prefix not in shared
            and not any(f"'{prefix}'" in text for text in owned)
        }
        self.assertEqual(
            {},
            unowned,
            "production rate-limit windows not covered by the companion allow-list: "
            + repr(unowned),
        )

    def test_fixture_owned_otp_keys_are_derived_from_the_synthetic_mobile(self) -> None:
        """The OTP window keys must be derived, never matched by shape."""
        platform = PLATFORM.read_text(encoding="utf-8")
        self.assertIn("'otp-day:' . $patient_mobile", platform)
        self.assertIn("'otp-hour:' . $patient_mobile", platform)
        self.assertIn("'otp-send-guard:' . hash( 'sha256', $patient_mobile )", platform)
        # Ownership must stay conditional on the synthetic 0900 mobile.
        self.assertIn("preg_match( '/^0900\\d{7}$/', $patient_mobile )", platform)
        # Shared per-IP counters are never in the owned allow-list.
        for key in shared_ip_prefixes():
            self.assertNotIn(f"$keys[] = '{key}'", platform)


if __name__ == "__main__":  # pragma: no cover
    unittest.main()
