# C6 Deferred Boundaries

> **تاریخ:** ۱۴۰۱/۰۶/۱۹
> **SHA پیاده‌سازی:** `3fc5a54`
> **تصمیم:** مالک/معمار — closure رسمی C6

این سند مرزهایی را ثبت می‌کند که عمداً از scope C6 خارج شدند و به فازها/واحدهای بعدی محول شدند.
اینها نقص یا بدهی C6 نیستند — تصمیم‌های آگاهانهٔ scope هستند.

---

## A — سیاست نهایی نقش/دسترسی (Role/Capability Policy)

| | |
|---|---|
| **تصمیم** | DEFERRED → Phase 3 |
| **دلیل** | معماری نقش و دسترسی فراتر از scope ایزولاسیون tenant است. C6 فقط تضمین می‌کند Clinic context صحیح است و Membership فعال تأیید می‌شود. سیاست نهایی چه کسی چه capability را دارد، Phase 3 است. |

## B — Staff Onboarding UI/API

| | |
|---|---|
| **تصمیم** | DEFERRED → LATER_STAFF_ADMIN_UX |
| **دلیل** | onboarding عضویت staff یک workflow صریحِ scope-aware است. هیچ provisioning خودکار عضویت در Production اضافه نشد (نه `user_register`، نه `set_user_role`، نه نگاشت نقش سراسری WP). |
| **قید دائمی** | نقش سراسری WP نباید Membership خودکار ایجاد کند. هر عضویت باید صریح و scope-aware باشد. |

## C — SMS Resend CHAR(64) Truncation

| | |
|---|---|
| **تصمیم** | KNOWN_MEDIUM_DEBT |
| **SHA مشاهده** | `2d13f2d` |
| **توضیح** | مسیر resend با الحاق `-{id}` به کلید ۶۴تایی، در ستون `CHAR(64)` truncate می‌شود. رفع نیاز به تغییر schema دارد ⇒ بدون Migration. بلوکر C6 نیست. |
| **مسیر رفع** | Migration آینده (با تأیید Owner). نیازمند `ALTER TABLE` روی `cpms_sms_messages`. |

## D — Historical Closure Hash Mismatch

| | |
|---|---|
| **تصمیم** | EXPECTED_NEGATIVE_TEST |
| **SHA مشاهده** | historical (`c2bff76` era) |
| **توضیح** | عدم تطابق hash در تست‌های closure تاریخی = خروجی مورد انتظار تست منفی. این یک defect نیست. |
| **قید** | هرگز بدون شواهد minimize نشود. هرگز به‌عنوان bug گزارش نشود. |

---

## ثبت‌شده در

- `docs/phase-reports/phase2-state.md` — بخش C6 Deferred Boundaries
- `docs/project-current-state.md` — اشاره به deferred boundaries
- `docs/phase-reports/c6-deferred-boundaries.md` — این فایل (canonical)
