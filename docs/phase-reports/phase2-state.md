# PHASE 2 — STATE (current)

| | |
|---|---|
| **آخرین به‌روزرسانی** | بر مبنای SHA پیاده‌سازی `41b24dc` |
| **وضعیت Phase 2** | IN PROGRESS — C1..C4 done؛ Migration Foundation ✅ APPROVED (Owner, 2026-09-09) |
| **آخرین remote SHA سبزِ تأییدشده** | `41b24dc` (هر ۵ گیت؛ اولین سبزیِ کامل WPCS روی کد C4) |

> این فایل state جاری است، نه گزارش؛ گزارش‌های کامل در
> [`final-pre-phase2-gate-report.md`](final-pre-phase2-gate-report.md) و
> کامنت‌های PR #11. عمداً به HEAD خودارجاع ندارد — SHA مبنا را مالک/agent
> هنگام هر به‌روزرسانی صریح می‌نویسد.

## Last known good gates (روی 41b24dc — C4 کامل)

| گیت | Run | نتیجه |
|---|---|---|
| CI (PHPStan + Unit×4 + Integration + **WPCS line-scoped**) | 34330257136 | ✅ success |
| Real-WP (PR) | 34330256991 | ✅ success |
| Real-WP (push) | 34330252423 | ✅ success |
| Pilot/Staging | 34330252351 | ✅ success |
| Closure | 34330252342 | ✅ success |

### سابقه (روی db35873 — C3)

| گیت | Run | نتیجه |
|---|---|---|
| CI (PHPStan + Unit×4 + Integration + WPCS changed-files) | 34316294584 | ✅ success |
| Real-WP (push) | 34316289951 | ✅ success |
| Real-WP (PR) | 34316294518 | ✅ success |
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

- **C4 — Membership primitives: ✅ کامل (هر ۵ گیت سبز روی `41b24dc`)** — کد/تست/داکیومنت
  (کامیت‌های 98ca0ff..91e5fe4) + WPCS-cleanup (کامیت‌های 67e1208..41b24dc):
  بازنویسی سه فایل جدید در سبک WordPress بدون تغییر منطق (snake_case؛
  `App::membership_service()`)، پالایش `phpcs.xml.dist` (پیشوند ClinicCore +
  هفت exclusion مستند)، ارتقای گیت WPCS به **line-scoped** (فقط نقض روی
  خطوط add شده نسبت به baseline می‌شکند؛ legacy مثل App.php با ~۶۵۰ نقض
  تاریخی فقط روی خطوط جدیدش سنجیده می‌شود). دو باگ ابزارِ گیت در راه
  رفع شد (هر دو Class D، با annotation/کامنت قابل‌مشاهده شدند): پیشوند
  progress در خروجی `--report=json` (ruleset `sp`) که JSON را می‌شکست
  (run 34328451996 → فیکس parse در 1364c37) و newlineِ `print()` خالی که
  گیت را روی اجرای سبز قرمز می‌کرد (run 34329655919 → فیکس در 41b24dc).
  اعتبارسنجی لوکال پیش از push: رانر phpcs داخل php-wasm با درخت
  وابستگی resolution مورد انتظار CI (wpcs 3.4.1 + phpcs 3.13.6 +
  PHPCSUtils 1.2.3 + PHPCSExtra 1.5.1) — ۰ نقض روی ۸۶۰ خط add شده؛
  Universal/NormalizedArrays از PHPCSExtra می‌آیند (نه خود phpcs).
- **قدم بعدی: C5 — Patient Identity** طبق queue.

### رویداد توکن (ثبت برای handoff)

- پس از push موفق 91e5fe4 (خروجی git push تأیید شد: `005c2ba..91e5fe4`)،
  توکن GitHub با 401 رد شد (مانند رویداد مشابه قبلی در همین فاز که
  خودش بازیابی شد). retry محدود انجام شد؛ credential دستکاری نشد.
  وضعیت ریموت = 91e5fe4 (push پیش از قطعی پذیرفته شده).
  وضعیت: توکن بازیابی شد؛ runهای 91e5fe4 بررسی شدند (WPCS = 34318905233،
  نقض‌های واقعی کد C4 → فیکس در کامیت بعدی).

## Done substeps (این فاز)

- **C4 — Membership primitives**: ✅ (کامیت‌های 98ca0ff..91b24dc؛ جزئیات در
  Current substep و drift-register §۸-۱ و یادداشت C4 در target-model).
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
