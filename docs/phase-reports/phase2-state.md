# PHASE 2 — STATE (current)

| | |
|---|---|
| **آخرین به‌روزرسانی** | بر مبنای SHA پیاده‌سازی `f23e0c5` (+ C3 in progress) |
| **وضعیت Phase 2** | IN PROGRESS — Migration Foundation ✅ APPROVED (Owner, 2026-09-09) |
| **آخرین remote SHA سبزِ تأییدشده** | `f23e0c5` |

> این فایل state جاری است، نه گزارش؛ گزارش‌های کامل در
> [`final-pre-phase2-gate-report.md`](final-pre-phase2-gate-report.md) و
> کامنت‌های PR #11. عمداً به HEAD خودارجاع ندارد — SHA مبنا را مالک/agent
> هنگام هر به‌روزرسانی صریح می‌نویسد.

## Last known good gates (روی f23e0c5)

| گیت | Run | نتیجه |
|---|---|---|
| CI (PHPStan + Unit×4 + Integration) | 34309588163 | ✅ success |
| Real-WP (push) | 34309588103 | ✅ success |
| Real-WP (PR) | 34309583290 | ⚠️ functional ✓ — فقط آپلود artifact شکست (**Class C infra**)؛ rerun/dispatch توسط GitHub/token رد شد؛ دوقلوی push همان SHA کامل سبز. بازبینی با event بعدی |
| Pilot/Staging | 34309583258 | ✅ success |
| Closure | 34309583310 | ✅ success |

## Current substep

- **C3 — WPCS regression gate**: در حال پیاده‌سازی
  (`phpcs.xml.dist` + job `wpcs` در `ci.yml`، استراتژی changed-files از
  baseline `f23e0c5`؛ جزئیات: drift-register §۸-۱).

## Queue (ترتیب مصوب مالک)

C4 Membership primitives → C5 Patient Identity foundation → C6 حذف
tenant hardcodes (census تازه از HEAD) → C7 Repository/Service isolation
(multi-org واقعی) → C8 Iran Location foundation → C9 i18n audit →
C10 Performance review → End Gate (۲۶بندی) → STOP.

## Open blockers

- هیچ blocker محصولی. آیتم بازِ زیرساختی: Real-WP PR-event آپلود
  (Class C؛ راه‌حل = event بعدی طبیعی).

## نکات اجرایی پابرجا

- هر واحد: CODE+TEST+DOC → atomic commit → push → monitor حداقل CI مربوط.
- هر push: verify remote SHA + ثبت run IDs در همین فایل.
- sandbox بدون PHP/MySQL — تنها اجرای واقعی = GitHub Actions؛ /tmp
  disposable؛ هر rebuild sandbox: `git fetch origin <branch>` + `git reset
  FETCH_HEAD` (mixed) — غیرممنوع و اثبات‌شده.
