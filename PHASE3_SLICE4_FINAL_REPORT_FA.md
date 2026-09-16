# Phase 3 — Slice 4: گزارش‌ها + CSV Export با مجوز Clinic-scoped

**وضعیت نهایی یکپارچه‌سازی (Snapshot این سند):** RED معتبر → GREEN حداقلی → رگرسیون سبز → همهٔ گیت‌های همین HEAD سبز.
**شاخه:** `arena/01a0a7a1-doctor` — **Pull Request:** Draft #54.
**نکتهٔ شفافیت:** همهٔ اعداد/شواهد زیر از خروجی اجراهای واقعی CI همین مخزن است؛ هر جا استنتاج است، صریحاً «استنتاج» برچسب خورده است.

---

## ۱) سطحِ موردِ حفاظت — مسیرهای واقعی محصول

Namespace: `clinic/v1`

| متد | مسیر | مجوز مورد نیاز (پس از این اسلایس) |
|---|---|---|
| GET | `/reports` | `REPORT_READ` (Clinic-scoped) — Cap نوع گزارش ندارد (کاتالوگ) |
| GET | `/reports/{type}` | `REPORT_READ` (Clinic-scoped) + Capهای نوع گزارش (سراسری، بی‌تغییر) |
| GET | `/reports/{type}/print` | `REPORT_READ` (Clinic-scoped) + Capهای نوع گزارش |
| POST | `/reports/{type}/export` | `REPORT_READ` + `EXPORT` (Clinic-scoped) + Capهای نوع گزارش |
| GET | `/reports/exports` | `EXPORT` (Clinic-scoped) + Cap سراسری موجود `REPORT_READ` |
| GET | `/reports/exports/{id}/download` | `EXPORT` (Clinic-scoped) + مالکیتِ پایدارِ ردیف (recipient + Clinic) + انقضا |

`{type}` به ۱۲ نوع شناخته‌شده محدود است. مسیرهای Patient-self دست‌نخورده ماندند (تست Pin مسیرهای Public در `RestPermissionCallbackTest` تغییری نکرد).

**مدلِ معیوبِ پیش از این اسلایس (فکتِ تاریخی):** در `ReportsController` و `ExportService` **هیچ** استفاده‌ای از `AuthorizationService` نبود؛ حاکمیتِ نهایی فقط Capهای سراسریِ WordPress و یک گاردِ محدودِ Phase 2 (`requireClinicMembership`) در مسیرِ Worker بود.

---

## ۲) چرخهٔ حیاتِ Worker و قانونِ مجوز در زمانِ اجرا

`REST → Scope مورد اعتماد → Enqueue (payload پایدار) → Worker → تولید گزارش → فایل → اعلان/Audit → فهرست → دانلود → انقضا/پاک‌سازی`

- **در Enqueue** (`ExportService::request()`): مجوزِ actor روی Clinicِ `App::scope()` — یعنی `REPORT_READ` + `EXPORT` — **پیش از** حلِ وابستگی‌های Clinic و **پیش از** `jobs->enqueue('report.export')`. payload شامل `actor_id`, `clinic_id` (همان Clinicِ مورد اعتمادِ سرور), `type`, `from`, `to` است.
- **در اجرا** (`ExportService::generate()`): فقط با واقعیت‌های **پایدارِ روی Job** کار می‌کند — نه کاربر جاریِ WordPress، نه Scope محیطی، نه «اولین Clinic». ترتیب: اعتبارِ عددی clinic/actor → `requireClinicMembership` (عضویتِ فعالِ پایدار) → `AuthorizationService` برای `REPORT_READ` + `EXPORT` روی همان Clinic → و تنها پس از آن حلِ وابستگی، اجرای گزارش، ساخت CSV، ذخیرهٔ فایل، اعلان و لاگِ موفقیت.
- **لغو/تعلیقِ actor بین Enqueue و اجرا** ⇒ انکارِ قطعی مجوز **پیش از** هر Side-effect؛ هیچ CSV، هیچ اعلانِ موفقیت و هیچ لاگِ عملیاتیِ موفقیت تولید نمی‌شود.
- کدِ پایدارِ موجودِ مسیر Job حفظ شد: `CLINIC_EXPORT_CLINIC_NOT_AUTHORIZED` / 403.
- **تصمیمِ صریح (مستند):** انکارِ مجوز در زمانِ اجرا از **همان معناشناسیِ شکستِ موجودِ JobQueue** استفاده می‌کند (استثنا → `fail()`/Backoff → در نهایت `failed`) و هیچ بازطراحی یا مارکر ترمینالِ جدیدی اضافه نشد؛ «انکارِ قطعی مجوز» بهانهٔ تضعیفِ authz یا تغییرِ معماری JobQueue نشد. تغییر در `purgeExpired` و `LocalFileStorage` انجام نشد.

---

## ۳) قراردادِ تغییر (CHANGE CONTRACT) — نگاشتِ پذیرش

| بند | وضعیت |
|---|---|
| ۱ authN ≠ authZ | ✅ لایهٔ مجوزِ Clinic-scoped جدا از احراز هویت |
| ۲ عضویت فعال ≠ مجوز دلخواه | ✅ عضویتِ فعال + مجوزِ درخواستی، هر دو لازم |
| ۳ Cap سراسری به‌تنهایی مجوز نیست | ✅ تستِ اختصاصی (Administrator نصب با حداکثر Cap سراسری) |
| ۴ فقط Clinic مورد اعتماد از `App::scope()` | ✅ هیچ Clinic‌ای از payload خوانده نمی‌شود |
| ۵ `REPORT_READ` برای خواندن/چاپ | ✅ |
| ۶ `EXPORT` جداگانه برای درخواست/فهرست/دانلود | ✅ |
| ۷ Capهای نوع گزارش حفظ شد | ✅ بدون ادغام با `REPORT_READ`/`EXPORT`/`FINANCE_READ` |
| ۸ Deny صریح > Grant/Preset | ✅ تستِ deny صریح |
| ۹ Clinic A ↛ Clinic B | ✅ read/export/download |
| ۱۰ Administrator نصب بدون مجوز Clinic | ✅ تستِ اختصاصی این اسلایس |
| ۱۱ دانلود = مالکیتِ پایدار + Clinic + `EXPORT` | ✅ |
| ۱۲ انکار پیش از Enqueue | ✅ صفر Job برای درخواستِ غیرمجاز |
| ۱۳ اجرای غیرمجاز ⇒ بدون CSV/اعلان/Audit موفقیت | ✅ |
| ۱۴ بدون Fixture «اولین Clinic»/`clinic_id=0`/ردیف اول | ✅ Fixtureها پویا با شناسهٔ DB |
| ۱۵ مسیرهای Patient-self دست‌نخورده | ✅ |
| ۱۶ بدون Migration؛ `0021` ساخته نشد | ✅ |

**مجوزها به‌ازای هر عملیات:** read/print = `REPORT_READ` (+ Cap نوع)؛ درخواست Export = `REPORT_READ` + `EXPORT` (+ Cap نوع)؛ فهرست/دانلود = `EXPORT`؛ اجرای Worker = همان مجوزهای گزارشِ درخواست‌شده روی Clinic/actorِ پایدارِ Job.

---

## ۴) شواهد RED (روی `main` معیوب)

### RED A — مسیر واقعی محصول، انکارِ scoped را دور می‌زند
- اجرا: **CI run `35040975361`** (head `f1dc29e`)؛ تنها Job «Integration (WP 6.7 + MySQL 8)» شکست خورد: `Tests: 817, Assertions: 9057, Failures: 6`.
- تست: `testRedACoarseGlobalCapabilityCannotBypassScopedExportDenialOnExportRequest`
- پیش‌شرط‌های اثبات‌شده در تست: Cap سراسری کامل (`cpms_accountant`)، عضویتِ فعالِ پایدار با `role_key=cpms_manager`، `AuthorizationService::can(actor, clinic, EXPORT) === false` (deny صریحِ عضویت).
- رفتارِ معیوب: `POST /clinic/v1/reports/revenue/export` با Nonce معتبر و هدرِ Clinic مورد اعتماد → **HTTP 202** (ورود به مسیر درخواست Export). انتظار: **403**.

### RED B — مستقل: درخواستِ غیرمجاز، Job پایدار می‌سازد
- اجرا: **CI run `35041298447`** (head `35c70b7`)؛ تنها «Integration» شکست خورد: `Tests: 819, Assertions: 9087, Failures: 7`.
- تست: `testRedBUnauthorizedExportRequestMustNotEnqueueReportExportJob`
- پیامِ شکست: `Authorization denial must happen before jobs->enqueue(report.export). The request returned HTTP 202 and created 1 durable job(s) for Clinic 74356.`
- ادعای شمارشِ Job **پیش از** ادعای وضعیت HTTP اجرا می‌شود (الزامِ استقلالِ RED B).

### نقصِ تست (نه نقصِ محصول) و اصلاحِ آن
در اجرای اول، شمارندهٔ Job از ستونِ ناموجودِ `cpms_jobs.clinic_id` می‌خواند؛ اما `cpms_jobs` هیچ ستونِ `clinic_id` ندارد و scope پایدارِ Job فقط در `payload_json` است ⇒ شمارنده همیشه صفر می‌شد و یکی از تست‌ها به‌غلط سبز بود. در کامیت `35c70b7`:
- شمارنده بر `CAST(JSON_EXTRACT(payload_json,'$.clinic_id') AS UNSIGNED)` (و در صورت نیاز `$.actor_id`) بازنویسی شد؛
- تستِ «لغو بین Enqueue و اجرا» به حالتِ واقعیِ شکاف تغییر کرد: عضویت **فعال** می‌ماند و `EXPORT` با deny صریح لغو می‌شود؛
- ادعاهای Side-effect به قبل از ادعاهای وضعیت منتقل شدند.

### سایرِ شواهدِ DENY روی main (پس از اصلاحِ شمارنده)
`RED A` (202 به‌جای 403)، «درخواست غیرمجاز ⇒ ۱ Job پایدار»، «بدون `REPORT_READ` ⇒ Job ساخته می‌شود»، «بدون `EXPORT` ⇒ Job ساخته می‌شود»، «deny صریح `REPORT_READ` ⇒ Job ساخته می‌شود»، «دانلود پس از لغو مجوز ⇒ 200 به‌جای 403»، «لغو بین Enqueue و اجرا ⇒ artifact تولید می‌شود».

---

## ۵) شواهد GREEN (رگرسیون روی همین HEAD)

- DENY: بدون عضویت (با Cap سراسری)، **Administrator نصب بدون مجوز Clinic**، عضویت معلق، فاقد `REPORT_READ`، فاقد `EXPORT`، deny صریح `REPORT_READ`، Clinic A → Clinic B، لغو `EXPORT` بین Enqueue و اجرا، تعلیق بین Enqueue و اجرا، دانلودِ artifactِ actor دیگر در همان Clinic، دانلود بین‌Clinics، و «صفر Job برای درخواست غیرمجاز».
- ALLOW: خواندن/چاپ با عضویت مجاز، چرخهٔ کامل Export مجاز (request → job → list → download)، grant صریحِ عضویت، یک actor در دو Clinic به‌طور مستقل، و **اجرای Worker بدون هیچ کاربر جاریِ WordPress** (`wp_set_current_user(0)`).
- اصلاح Fixture (بدون تضعیفِ هیچ بررسیِ تولیدی):
  1. `ReportsAuthzTest::testExportFlowAuthorizationAuditAndFormulaInjection` — اعطای `EXPORT` از عضویتِ پایدار (`set_capability(..., 'grant')`) به‌جای اتکا به `add_cap` سراسری.
  2. `RestTrustedClinicContextTest::testExportRestPutsRequestClinicOnJobNotLeftoverScope` — همان اصلاح، با حفظِ قصدِ تست (Clinicِ درخواست باید روی Job بنشیند، نه Scope باقی‌مانده).
  3. `bin/pilot-smoke.php` سناریوی S6 — مسیر مجازِ Export از عضویتِ پایدارِ Clinic گرفته می‌شود؛ اسموک حالا **اثبات** می‌کند Cap سراسری به‌تنهایی کافی نیست.

---

## ۶) فایل‌های تغییر‌یافته

```
clinic-practice-management/src/Application/Reports/ExportService.php   (+105/-…)
clinic-practice-management/src/Rest/ReportsController.php              (+106/-…)
clinic-practice-management/src/Bootstrap/App.php                       (+4/-1)
clinic-practice-management/tests/Integration/ReportsExportClinicAuthorizationTest.php (جدید)
clinic-practice-management/tests/Integration/ReportsAuthzTest.php      (اصلاح Fixture)
clinic-practice-management/tests/Integration/RestTrustedClinicContextTest.php (اصلاح Fixture)
clinic-practice-management/bin/pilot-smoke.php                         (اصلاح Fixture)
```

**Migration:** هیچ فایلی در `src/Migrations` تغییر نکرد؛ آخرین Migration همان `2026_09_09_0020_idempotency_clinic_scope.php` است. `0021` ساخته نشد.

---

## ۷) گیت‌ها (وضعیتِ Snapshot در زمانِ نوشتنِ این سند)

| گیت | Run | نتیجه |
|---|---|---|
| CI (PHPStan، WPCS، Tenant Tripwire، Unit 8.1–8.4، Integration WP 6.7 + MySQL 8) | `35042644236` | ✅ success |
| Real WordPress Acceptance (ZIP → WP پاک → browser) | `35042644234` | ✅ success |
| Closure Gate | `35042641364` | ✅ success |
| Pilot/Staging Readiness Gate (اسموک ۹/۹ شامل S6) | `35042641374` | ✅ success |

اجرای ناموفقِ Pilot پیش از اصلاح Fixture: `35042149667` (تنها شکست: `FAIL S6 — Reports/Export ... دسترسی لازم را ندارید`).
گیت Performance (`ab`) طبق سیاست `CI-Cost-1` روی کارِ عادی PR اجرا نمی‌شود.

**استنتاج (نه مشاهدهٔ مستقیم):** تعدادِ تست‌های Integration روی HEAD نهایی از متن خلاصهٔ اجراهای قبلی (`819`) به‌علاوهٔ یک تستِ افزوده (`Administrator نصب`) ≈ **`820`** است؛ گزارشِ اجرای سبز هیچ جزئیات/خلاصه‌ای منتشر نمی‌کند، بنابراین این عدد «استنتاج» است و نه نقل‌قول. متنِ artifactهای اجرا از Sandbox در دسترس نیست (خطای `EOF` روی Blob Storage؛ پیش‌تر هم تأیید شده بود).

---

## ۸) موانع / نکاتِ باقی‌مانده

- **بدون مانعِ بازِ شناخته‌شده** روی گیت‌های اجراشدهٔ همین HEAD.
- محدودیتِ محیط توسعه: اجرای لوکالِ Integration در این Sandbox ممکن نیست (بدون PHP/MySQL؛ دانلودِ منابعِ خارجی بلاک است)؛ بنابراین همهٔ شواهدِ اجرا از CI همین مخزن است و تأییدِ نهاییِ محلی انجام نشده — این یک محدودیتِ ابزار است، نه ابهامِ نتیجه.
- این PR عمداً **Draft** است؛ هیچ Ready/merge/release/tag/force-push/rebase انجام نشده و تاریخِ `main` دست‌نخورده است.
