#!/usr/bin/env python3
"""
Tenant-hardcode tripwire — Phase 2 / C6 (ADR-0031, AD-13).

هدف: production executable tenant hardcode = 0.

اسکن مسیرهای production (src بدون Migrations، bin، فایل ورودی افزونه،
uninstall.php) برای الگوهای شناخته‌شدهٔ فرضِ ضمنی tenant. هر violation باید
یا اصلاح شود یا در allowlist با «class + دلیل مشخص» فایل/الگوی ثبت شود.

قواعد allowlist (bin/tenant-tripwire-allowlist.json):
  - فقط کلاس‌های مجاز: non-tenant-literal | migration-historical |
    pilot-fixture | test-fixture (آخرین فقط خارج از production roots معنا
    دارد و اسکن نمی‌شود).
  - هر entry باید هنوز match شود؛ entry بی‌استفاده (stale) = خطا —
    allowlist نمی‌تواند زباله انباشته کند.
  - file-specific + pattern_id-specific + reason الزامی.

نکتهٔ معماری: src/Migrations عمداً خارج از اسکن است — migrationهای تاریخی
حالتِ دادهٔ موجود را توصیف می‌کنند (مثلاً seed سازمان از تنها کلینیک موجود
در 0010) و «فرض tenant در کد اجراییِ runtime» معنا ندارد. تغییر آنها =
بازنویسی تاریخچه.

استفاده: python3 bin/tenant-tripwire.py [--root DIR] [--allowlist FILE]
خروجی: گزارش + exit 0 (سبز) / 1 (violation یا stale allowlist).
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

# الگوهای شناخته‌شدهٔ فرض ضمنی tenant (id → regex).
# «حداقل الگوهای شناخته‌شده» — الگوی جدید هنگام کشف اضافه می‌شود.
PATTERNS: dict[str, str] = {
    # SQL literal: WHERE clinic_id = 1 / a.clinic_id = 1
    "sql-clinic-literal-1": r"\bclinic_id\s*=\s*1\b",
    # آرایه/insert literal: 'clinic_id' => 1
    "array-clinic-literal-1": r"['\"]clinic_id['\"]\s*=>\s*1\b",
    # انتساب/پیش‌فرض پارامتر: $clinicId = 1 / int $clinic_id = 1
    "var-clinic-assign-1": r"\$\w*[Cc]linic(?:_id|Id)\s*=\s*1\b",
    # آرگومان literal-1 به متدهای clinic-first شناخته‌شده
    "call-clinic-first-arg-1": (
        r"->(?:availability|findByClinicianSlot|findByMobile|findByMrn"
        r"|search|forUser|forPatient|lastIdForUser|lastIdForPatient"
        r"|unreadCountForUser|unreadCountForPatient|listAll)"
        r"\(\s*1\s*,"
    ),
    # store(..., 1, ...) — کلینیک فایل
    "call-store-clinic-1": r"->store\(\s*[^,]+,\s*1\s*,",
}

ALLOWED_CLASSES = {"non-tenant-literal", "migration-historical", "pilot-fixture", "test-fixture"}

# مسیرهای production (نسبی به repo root یا پلاگین — هر دو پذیرفته می‌شود).
PRODUCTION_ROOTS = ["src", "bin"]
PRODUCTION_FILES = ["clinic-practice-management.php", "uninstall.php"]
SKIPPED_DIRS = {"src/Migrations", "src/Dev"}  # Dev در صورت وجود (ابزار dev)

COMMENT_RE = re.compile(r"^\s*(//|\*|/\*|#)")


def scan_file(path: Path, plugin_root: Path) -> list[dict]:
    rel = path.relative_to(plugin_root).as_posix()
    hits = []
    try:
        text = path.read_text(encoding="utf-8")
    except (OSError, UnicodeDecodeError):
        return hits
    for lineno, line in enumerate(text.splitlines(), start=1):
        if COMMENT_RE.match(line):
            continue  # کامنت‌ها/E مستنداتی جداگانه reports می‌شوند نه violation
        for pid, rx in PATTERNS.items():
            if re.search(rx, line):
                hits.append(
                    {
                        "file": rel,
                        "line": lineno,
                        "pattern_id": pid,
                        "text": line.strip()[:160],
                    }
                )
    return hits


def main() -> int:
    default_root = Path(__file__).resolve().parent.parent
    ap = argparse.ArgumentParser(description="Tenant-hardcode tripwire (C6)")
    ap.add_argument("--root", default=str(default_root), help="plugin root (default: repo layout auto)")
    ap.add_argument("--allowlist", default=str(Path(__file__).resolve().parent / "tenant-tripwire-allowlist.json"))
    args = ap.parse_args()

    root = Path(args.root).resolve()
    if not (root / "phpcs.xml.dist").exists() and (root.parent / "phpcs.xml.dist").exists():
        root = root.parent  # اجرا از داخل clinic-practice-management
    allowlist_path = Path(args.allowlist).resolve()

    entries = []
    if allowlist_path.exists():
        try:
            entries = json.loads(allowlist_path.read_text(encoding="utf-8"))
        except json.JSONDecodeError as e:
            print(f"TRIPWIRE FAIL: allowlist JSON نامعتبر: {e}")
            return 1
    else:
        print(f"TRIPWIRE FAIL: allowlist پیدا نشد: {allowlist_path}")
        return 1

    for i, e in enumerate(entries):
        if not {"file", "pattern_id", "reason", "class"} <= set(e):
            print(f"TRIPWIRE FAIL: allowlist[{i}] فیلدهای الزامی (file/pattern_id/reason/class) ندارد")
            return 1
        if e["class"] not in ALLOWED_CLASSES:
            print(f"TRIPWIRE FAIL: allowlist[{i}] class نامعتبر: {e['class']}")
            return 1

    # جمع‌آوری فایل‌های production
    files: list[Path] = []
    for sub in PRODUCTION_ROOTS:
        base = root / sub
        if not base.is_dir():
            continue
        for p in sorted(base.rglob("*.php")):
            rel = p.relative_to(root).as_posix()
            if any(rel.startswith(skip.rstrip("/") + "/") or rel == skip for skip in SKIPPED_DIRS):
                continue
            files.append(p)
    for f in PRODUCTION_FILES:
        p = root / f
        if p.is_file():
            files.append(p)

    violations: list[dict] = []
    comments: list[dict] = []
    for p in files:
        rel = p.relative_to(root).as_posix()
        text = p.read_text(encoding="utf-8") if p.exists() else ""
        for lineno, line in enumerate(text.splitlines(), start=1):
            for pid, rx in PATTERNS.items():
                if re.search(rx, line):
                    rec = {"file": rel, "line": lineno, "pattern_id": pid, "text": line.strip()[:160]}
                    if COMMENT_RE.match(line):
                        comments.append(rec)
                    else:
                        violations.append(rec)

    # اعمال allowlist
    used: set[int] = set()
    unmatched: list[dict] = violations
    for idx, e in enumerate(entries):
        matched = False
        remaining = []
        for v in unmatched:
            if v["file"] == e["file"] and v["pattern_id"] == e["pattern_id"]:
                if "line" in e and e["line"] != v["line"]:
                    remaining.append(v)
                    continue
                matched = True
                used.add(idx)
                break
            remaining.append(v)
        if not matched and idx not in used:
            continue  # بعداً به‌عنوان stale گزارش می‌شود
        unmatched = remaining

    stale = [e for idx, e in enumerate(entries) if idx not in used]

    ok = True
    if unmatched:
        ok = False
        print(f"TRIPWIRE FAIL: {len(unmatched)} tenant violation(s) خارج از allowlist:")
        for v in unmatched:
            print(f"  {v['file']}:{v['line']} [{v['pattern_id']}] {v['text']}")
    if stale:
        ok = False
        print(f"TRIPWIRE FAIL: {len(stale)} allowlist entry بدون تطبیق (stale) — حذف یا به‌روزرسانی کنید:")
        for e in stale:
            print(f"  {e['file']} [{e['pattern_id']}] — {e['reason']}")
    if comments:
        print(f"NOTE: {len(comments)} occurrence در کامنت/داک (غیر اجرایی) — با بازنویسی داک پاکسازی شود:")
        for c in comments[:20]:
            print(f"  {c['file']}:{c['line']} [{c['pattern_id']}]")

    if ok:
        print(f"TRIPWIRE PASS: production tenant hardcode = 0 "
              f"({len(used)} allowlist entry فعال، {len(comments)} کامنت قدیمی).")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
