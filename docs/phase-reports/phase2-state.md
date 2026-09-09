# PHASE 2 — STATE (current)

| | |
|---|---|
| **آخرین به‌روزرسانی** | بر مبنای SHA پیاده‌سازی `db35873` |
| **وضعیت Phase 2** | IN PROGRESS — C1..C3 done؛ Migration Foundation ✅ APPROVED (Owner, 2026-09-09) |
| **آخرین remote SHA سبزِ تأییدشده** | `db35873` (هر ۵ گیت + job جدید WPCS) |

> این فایل state جاری است، نه گزارش؛ گزارش‌های کامل در
> [`final-pre-phase2-gate-report.md`](final-pre-phase2-gate-report.md) و
> کامنت‌های PR #11. عمداً به HEAD خودارجاع ندارد — SHA مبنا را مالک/agent
> هنگام هر به‌روزرسانی صریح می‌نویسد.

## Last known good gates (روی db35873)

| گیت | Run | نتیجه |
|---|---|---|
| CI (PHPStan + Unit×4 + Integration + **WPCS**) | 34316294584 | ✅ success |
| Real-WP (push) | 34316289951 | ✅ success |
| Real-WP (PR) | 34316294518 | ✅ success — شکست آپلود قبلی (Class C) با event جدید حل شد |
| Pilot/Staging | 34316289927 | ✅ success |
| Closure | 34316290002 | ✅ success |

### سابقه (روی f23e0c5)

| گیت | Run | نتیجه |
|---|---|---|
| CI (PHPStan + Unit×4 + Integration) | 34309588163 | ✅ success |
| Real-WP (push) | 34309588103 | ✅ success |
| Real-WP (PR) | 34309583290 | ⚠️ functional ✓ — فقط آپلود artifact شکست (**Class C infra**)؛ rerun/dispatch توسط GitHub/token رد شد؛ دوقلوی push همان SHA کامل سبز. بازبینی با event بعدی |
| Pilot/Staging | 34309583258 | ✅ success |
| Closure | 34309583310 | ✅ success |

## Current substep

- **C4 — Membership primitives**: کد/تست/داکیومنت کامل (کامیت‌های
  98ca0ff..91e5fe4)؛ Integration ✅ 517 تست (شامل ۱۰ تست membership) و
  PHPStan ✅ در run 34318212809. **در انتظار تأیید نتیجهٔ WPCS روی
  91e5fe4** — گیت تا اینجا هر بار روی خطای ruleset خودش abort شده بود
  (سه بار؛ فیکس شد) و هنوز یک بارِ موفق sniff روی کد C4 اجرا نشده
  است. تا دیدن آن، لایهٔ بعدی (C5) شروع نمی‌شود (قاعدهٔ گیت-قبل-از-لایه).

### رویداد توکن (ثبت برای handoff)

- پس از push موفق 91e5fe4 (خروجی git push تأیید شد: `005c2ba..91e5fe4`)،
  توکن GitHub با 401 رد شد (مانند رویداد مشابه قبلی در همین فاز که
  خودش بازیابی شد). retry محدود انجام شد؛ credential دستکاری نشد.
  وضعیت ریموت = 91e5fe4 (push پیش از قطعی پذیرفته شده).
  اقدام بعدی agent: پس از بازیابی توکن — بررسی runهای 91e5fe4
  (به‌ویژه WPCS) و سپس ادامهٔ queue.

## Done substeps (این فاز)

- **C1..C2 + Recovery**: کامیت‌های 1f8b36d..f23e0c5 (شرح کامل: کامیت‌ها و
  Migration Foundation Recovery Report در PR #11).
- **C3 — WPCS regression gate**: ✅ سبز (کامیت‌های b6f6c93..db35873).
  `phpcs.xml.dist` + job `wpcs` (استراتژی changed-files از baseline
  f23e0c5). سه تلاش Class C/D در راه: advisoryهای رجیستری روی phpcs 3.11.x
  و wpcs 3.1.0 (→ دامنهٔ ^3.1 + فیلتر امنیتی composer)، allow-plugins، و
  باگ f-string در expansion. جزئیات: drift-register §۸-۱.

## Queue (ترتیب مصوب مالک)

C4 Membership primitives → C5 Patient Identity foundation → C6 حذف
tenant hardcodes (census تازه از HEAD) → C7 Repository/Service isolation
(multi-org واقعی) → C8 Iran Location foundation → C9 i18n audit →
C10 Performance review → End Gate (۲۶بندی) → STOP.

## Open blockers

- هیچ.

## نکات اجرایی پابرجا

- هر واحد: CODE+TEST+DOC → atomic commit → push → monitor حداقل CI مربوط.
- هر push: verify remote SHA + ثبت run IDs در همین فایل.
- sandbox بدون PHP/MySQL — تنها اجرای واقعی = GitHub Actions؛ /tmp
  disposable؛ هر rebuild sandbox: `git fetch origin <branch>` + `git reset
  FETCH_HEAD` (mixed) — غیرممنوع و اثبات‌شده.
