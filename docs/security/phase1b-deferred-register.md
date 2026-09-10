# Phase 1B — دفتر اقلام معوق (Deferred Register)

> **قاعدهٔ حاکم:** Phase 1B مجوز **وابسته به Scope** است و به مدل داده‌ای Phase 2 (Organization → Clinic → Location) و ماتریس Capability فاز ۳ وابسته است.
>
> **دو قید غیرقابل‌مذاکره:**
> 1. هیچ قلمی از این دفتر نباید روی `clinic_id = 1` پیاده یا **شبیه‌سازی** شود (AD-13).
> 2. هیچ **Bypass موقتی** مجاز نیست. برای هر قلم، قوی‌ترین گاردِ مستقل از Scope همین حالا در Phase 1A اعمال شده و مالکیت نهایی اینجا ثبت شده است.
>
> | | |
> |---|---|
> | ایجاد | 2026-09-08، پایان Phase 1A |
> | مرجع | [`phase1-current-security-model.md`](phase1-current-security-model.md) |

---

## ۱. مجوز مبتنی بر مالکیت و Scope

| # | قلم | گاردِ فعلی (1A) | آنچه در 1B/3 لازم است | وابسته به |
|---|---|---|---|---|
| **B-01** | ویزیت/نُت/نسخه — پزشک فقط روی رکورد **خودش** | Capability پزشکی در `permission_callback` + گارد مالکیتِ موجود در `VisitService` | `AuthorizationService::can(userId, cap, ScopeContext)` با Scope کلینیک | Phase 2 + 3 |
| **B-02** | پروندهٔ بالینی بیمار — ایزوله در سطح **Clinic** (AD-14) | نقش/Capability بررسی می‌شود؛ **ایزولاسیون کلینیک بررسی نمی‌شود** | فیلتر Scope در Repository، نه در Controller | Phase 2 |
| **B-03** | `GET /clinic/v1/search` — جست‌وجوی سراسری بیمار | `SEARCH` capability | محدودسازی نتایج به کلینیک‌های مجاز کاربر | Phase 2 |
| **B-04** | `POST/GET /patients/{id}/files` | نقش `cpms_patient` | تأیید اینکه `patient_id` واقعاً متعلق به کاربر جاری است، **و** کلینیک | Phase 2 |
| **B-05** | `GET /files/{id}/stream` | احراز هویت + مجوز سطح Resource در `MedicalFileService` | Scope کلینیک روی فایل | Phase 2 |
| **B-06** | `GET /reports/exports/{id}/download` | Capability `EXPORT` + مالکیت `recipient_wp_user_id` در Service | Scope کلینیک روی خروجی | Phase 2 |
| **B-07** | `GET /config/services` — مجوز OR در لایهٔ Service | `permAuthenticated()` در Route | Capability صریح پس از تثبیت ماتریس فاز ۳ | Phase 3 |
| **B-08** | Endpointهای مالی (`/invoices`، `/payments/*`) | Capability مالی در Route | Scope کلینیک روی فاکتور/پرداخت | Phase 2 |

> ⚠️ **قابلیت‌های مالی پزشک** (`cpms_payment_void`، `cpms_payment_refund`) طبق دستور دائمی کارفرما در هیچ فازی تغییر نمی‌کنند.

---

## ۲. Rate Limiting وابسته به Scope

| # | قلم | وضعیت 1A | تصمیم لازم |
|---|---|---|---|
| **B-09** | سقف per-Clinic برای OTP/ورود/رزرو | سقف‌ها **سراسری** هستند | **Q6** — دامنهٔ جدول `cpms_rate_limits` (سراسری / per-Clinic / دوسطحی) |
| **B-10** | سقف per-Location | وجود ندارد | پس از AD-15 (هر Clinic حداقل یک Location) |

---

## ۳. ممیزی (Audit)

| # | قلم | وضعیت 1A | مانع |
|---|---|---|---|
| **B-11** | رویدادهای ورود (`LOGIN_FAILED`، `LOGIN_THROTTLED`) در Audit رسمی | فقط در `OpLogger` ثبت می‌شوند | `AuditLogger::log()` پارامتر `$clinicId` با پیش‌فرض `1` دارد؛ ورود رویدادی **پیش از** هر Scope کلینیکی است ⇒ ثبت آن روی `clinic_id = 1` دقیقاً همان فرضی است که AD-13 ممنوع کرده |
| **B-12** | Audit سطح Organization | وجود ندارد | Phase 2 |
| **B-13** | Pepper زنجیرهٔ Audit (**OD-5**) | مقدار ثابت درونِ کد باقی مانده | تعویض آن زنجیرهٔ رکوردهای موجود را نامعتبر می‌کند — نیازمند تصمیم کارفرما و احتمالاً مهاجرت |

---

## ۴. هویت بیمار (AD-14)

| # | قلم | وضعیت 1A | وابسته به |
|---|---|---|---|
| **B-14** | `cpms_patient_identities` در سطح Organization با شناسهٔ داخلی تغییرناپذیر | ✅ **پیاده‌سازی شد (C5، 2026-09-09)** — جدول + سرویس/ریپازیتوری primitive + لینک WP User (کامیت‌های 0018/C1-C2 و 0019/C5) | انجام شد |
| **B-15** | تغییر شمارهٔ موبایل — صریح و Audit شده | `OtpService` جست‌وجوی بیمار را از **Clinicِ پیکربندی‌شدهٔ Settings** می‌خواند (پس از fix تصحیحی Pre-Phase-2 Gate — دیگر هیچ literal `clinic_id = 1` در آن فایل نیست) | Phase 2 |
| **B-16** | تشخیص تکراری و Merge هویت | Schema موجود است، `resolvePatient()` وجود ندارد | Phase 2 |
| **B-17** | اگر ستون Lookup هش شود، باید HMAC کلیددار باشد نه SHA-256 خام | همچنان هیچ ستون هش‌شده‌ای وجود ندارد — C5 طبق دستور مالک ستون plaintext `normalized_mobile` ساخت («normalized lookup field»)؛ قید HMAC فقط در صورت مهاجرت آینده به هش فعال می‌شود | باز (فقط در صورت تغییر تصمیم) |

> 🔴 **تصحیح (2026-09-09 — Pre-Phase-2 Gate):** جملهٔ قبلی این بند («Phase 1A هیچ نمونهٔ جدیدی اضافه نکرده») **نادرست بود** — git blame نشان داد کامیت `4c16009` (OD-8، Phase 1A) یک نسخهٔ کپی‌شده از کوئری `clinic_id = 1` در `findExistingUser` اضافه کرده بود (نقض AD-13). در همان Gate با fix کوچک اصلاح شد: کوئریِ تکراری به یک helper واحد پارامتری‌شده با `Settings::clinicId()` تبدیل شد (بدون ساخت مفهوم Scope جدید — آن کار Phase 2 است) + تست رگرسیون `OtpFlowTest::testVerifyResolvesPatientInConfiguredClinicNotHardcodedDefault`. بازطراحی کامل این مسیر مطابق AD-14 همچنان Phase 2 می‌ماند.

---

## ۵. تست‌های ایزولاسیون که باید در Phase 2/3 نوشته شوند

این تست‌ها عمداً **نوشته نشده‌اند**، چون نوشتن آن‌ها امروز یعنی شبیه‌سازی با `clinic_id = 1` که ممنوع است.

| # | سناریوی تست | پیش‌نیاز |
|---|---|---|
| **T-01** | کاربر کلینیک الف نتواند بیمار کلینیک ب را بخواند | ≥۲ Clinic واقعی |
| **T-02** | پزشک عضو دو کلینیک فقط داده‌های کلینیک فعال را ببیند | AD-05 (M:N) |
| **T-03** | جست‌وجوی بیمار در مرز کلینیک قطع شود | Phase 2 |
| **T-04** | Stream فایل در مرز کلینیک قطع شود | Phase 2 |
| **T-05** | خروجی گزارش فقط شامل کلینیک‌های مجاز باشد | Phase 2 |
| **T-06** | مدیر سیستم بدون دسترسی بالینی (AD-11) | Phase 3 |
| **T-07** | نبودِ Bypass سراسری برای نقش `administrator` (AD-10) | Phase 3 |
| **T-08** | `cpms_org_*` جدا از دسترسی داده‌های بالینی (AD-16) | Phase 3 |

---

## ۵-۱ ریسک‌های شناخته‌شدهٔ ثبت‌شده در بازبینی تأییدی

| # | قلم | مالک |
|---|---|---|
| **B-18** | اتمیک کردن مسیر `peek()` + اعمال سقف (قفل یا `SELECT … FOR UPDATE`) | Phase 17 (Performance) یا هر زمان که سقف دقیق لازم شد |
| **B-19** | یکسان‌سازی سطل نام‌کاربری و ایمیل در محدودکنندهٔ ورود | Phase 3 (پس از تثبیت مدل هویت کاربر) |
| **B-20** | چرخش خودکار Pepper + مستندسازی عملیاتی | همراه OD-5 |
| **B-21** | ~~انتقال ریشهٔ ذخیره‌سازی به خارج از DocumentRoot + مهاجرت idempotent~~ | ✅ **CLOSED (2026-09-08)** — OD-7 قبلاً و OD-9 (مرز کامل بکاپ + مهاجرت idempotent + Fail-Closed) در همین session بسته شد: [`report-od9-closure.md`](../phase-reports/report-od9-closure.md) |

---

## ۶. مواردی که عمداً در Phase 1A انجام **نشد**

| مورد | دلیل |
|---|---|
| ساخت `AuthorizationService` نهایی | ساخت آن روی مدل ناقص، بدهی معماری تولید می‌کند (دستور صریح کارفرما) |
| ساخت Schema برای Organization/Clinic/Location | Phase 2 |
| افزودن `current_user_can('cpms_*')` مستقیم به‌عنوان معماری | Phase 3 جای آن را با مجوز Scope دار می‌گیرد؛ در 1A فقط از `requireCap()` موجود استفاده شد |
| رزرو عمومی جدید | Phase 8 |
| بازطراحی UI | Phase 20 |
| قابلیت‌های جدید بکاپ | Phase 15 |
| تغییر شمارهٔ نسخه | **OD-3** باز است |
