# گزارش رفع ایرادات — Part 1 (از ممیزی مستقل 6e42519)

**تاریخ:** 2026-09-07 | **Branch:** `arena/01a077e9-doctor` | **PR:** به main |
**مبنای ممیزی:** گزارش «Product Reality / ممیزی کامل» روی commit `6e42519` |
**وضعیت:** ۴ ایراد از ۱۳ رفع شد (P2، P5، P8، P10) + تست + مستندات

> قرارداد پارت‌بندی: هر Part = رفع + تست + سند + CI سبز + گزارش → توقف تا تأیید کارفرما.

---

## چه چیزی در این Part رفع شد

| # | ایراد (ممیزی) | شدت | راه‌حل پیاده‌شده | فایل‌ها |
|---|---|---|---|---|
| 1 | **P2** — ادمین نمی‌توانست دسترسی نقش‌ها را ویرایش کند؛ Self-healing اعطای عمدی را بی‌صدا حذف می‌کرد | 🔴 بحرانی | Override مدیریتی (`cpms_role_caps_override`) + صفحه «CPMS (دسترسی‌ها)» با ماتریس فارسی + Audit `ROLE_PERMISSION_CHANGED`/`ROLE_PERMISSION_RESET` + دکمه «بازگشت به پیش‌فرض» | `src/Auth/RolesAndCapabilities.php`، `src/Admin/RoleCapabilitiesPage.php` (جدید)، `src/Bootstrap/App.php` |
| 2 | **P8** — پزشک در `/queue` و `rt/queue` و آمار، کل کلینیک را می‌دید | 🟠 امنیتی/حریم | Scope سرور-side: پزشکِ متصل → فقط ویزیت‌های خودش (صف + فید + آمار + ETag)؛ پزشک بدون اتصال → هیچ؛ منشی بدون تغییر | `src/Application/Visits/VisitService.php`، `src/Infrastructure/Repository/VisitRepository.php` |
| 3 | **P5** — بیمار بعد از OTP به پیشخوان انگلیسی وردپرس می‌رسید | 🟠 UX | صفحه «نوبت‌های من» (فقط داده خودش — Ownership) + login_redirect + مخفی‌کردن Admin Bar + هدایت همه GETهای wp-admin به همان صفحه | `src/Admin/PatientPortalPage.php` (جدید) |
| 4 | **P10** — دو منوی «CPMS (سیستم)» و «CPMS (فنی)» گیج‌کننده بودند | 🟡 | نام دوم به «CPMS (فنی و لاگ)» تغییر کرد | `src/Admin/SettingsAdmin.php` |

## چه چیزی عمداً به Partهای بعد رفت

- **Part 2 (پیشنهادی):** UI ثبت پزشک + برنامه هفتگی (P1 — بزرگ‌ترین شکاف) + چاپ نسخه (P12)
- **Part 3:** UI گزارش‌ها (P4)
- **Part 4:** سازگاری — CI matrix روی MariaDB + WP 6.4 (P6/P7)، استخراج i18n (P9)، جداسازی فایل‌های JS/CSS (P11)
- **Part 5 (نیازمند تصمیم محصول):** پورتال کامل بیمار — فرم رزرو عمومی (P3)
- **ثبت‌شده به‌عنوان تصمیم عمدی (نیازمند ADR در آینده):** Notes بدون optimistic locking (P13) — رفتار فعلی «آخرین ذخیره برنده + تاریخچه» + نسخه‌ها؛ در report فازهای قبلی ثبت شده و ریسک واقعی ندارند.

## تست‌های جدید (Integration — WP/MySQL)

1. `DoctorQueueScopeTest` — ۳ تست:
   - پزشک متصل: صف/آمار فقط خودش؛ منشی: هر دو ویزیت.
   - فید Real-time پزشک: بدون رویداد ویزیت پزشک دیگر؛ ETag scope-دار.
   - پزشک بدون اتصال: صفر نتیجه (نه کل کلینیک).
2. `RoleCapabilitiesOverrideTest` — ۵ تست:
   - Override عمدی از Self-healing جان سالم به در می‌برد + drift خارج از فهرست همچنان پاک می‌شود.
   - فیلتر Capهای ناموجود + ذخیره صحیح Option.
   - حذف عمدی یک Cap اعمال می‌شود؛ بازگشت به پیش‌فرض، Override را حذف می‌کند.
   - نقش بیمار و نقش ناشناخته رد می‌شوند (P-5).
   - `capsMap` بدون Override == Template کلاس (سازگاری TP-10 قبلی).
3. `PatientPortalTest` — ۳ تست: تشخیص «بیمار خالص» (شامل multi-role)، login_redirect فقط برای بیمار، Admin Bar فقط برای بیمار مخفی.

## ناسازگاری‌های عمدی/توجه‌های عملیاتی

1. **رفتار قبلی که تغییر کرد:** پزشکی که آدرس «صف امروز» را دستی باز می‌کرد، صف کلینیک را می‌دید؛ حالا فقط خودش را می‌بیند (یا هیچ). ریسک رگرسیون UX نزدیک صفر — داشبورد پزشک از ابتدا own بود و JS آن دوباره فیلتر سمت کلاینت هم دارد.
2. **Override و بکاپ:** Option `cpms_role_caps_override` در dump اختصاصی `cpms_*` بکاپ فعلی نمی‌آید (Option وردپرسی است). گم‌شدنش = بازگشت امن به Template پیش‌فرض. اگر بعداً «در بکاپ بودن» لازم شد، به Migration بکاپ اضافه می‌شود (ثبت شد).
3. **Capهای آینده:** نسخه‌های بعدی اگر Cap جدید اضافه کنند، برای نقش‌های Override-دار **خودکار فعال نمی‌شود** — در UI با تیک خاموش ظاهر می‌شود تا ادمین عمداً فعال کند. این انتخاب آگاهانه‌ی «قابل پیش‌بینی بودن» است.

## شواهد

- کد: کامیت‌های این شاخه (delta فقط-افزوده بدون تغییر Schema/API contract).
- CI: لینک run در لاگ agent-guide (بعد از push) — Unit PHP 8.1–8.4 + Integration WP 6.7.2/MySQL 8.
- سند تصمیم: `docs/adr/ADR-0030-role-capability-override.md`.

## چک‌لیست توقف Part (طبق §100 Master Context)

- [ ] CI سبز روی pushed commit
- [ ] تست‌ها اجرا شده (توسط CI)
- [ ] Security reviewed — Scope/Nonce/Capability/Audit همه سمت سرور؛ بدون PHI جدید در لاگ
- [x] Documentation synchronized (ADR + permission-matrix + user-guide + CHANGELOG + agent-guide)
- [ ] تأیید کارفرما → سپس Part 2
