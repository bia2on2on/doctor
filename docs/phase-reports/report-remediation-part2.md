# گزارش رفع ایرادات — Part 2 (از ممیزی مستقل 6e42519)

**تاریخ:** 2026-09-07 | **Branch:** `arena/01a077e9-doctor` | **PR:** #4 (همراه Part 1) |
**مبنای ممیزی:** گزارش «ممیزی کامل» روی commit `6e42519` |
**وضعیت:** ۲ ایراد رفع شد (P1 — بحرانی‌ترین، و P12) + تست + مستندات

---

## چه چیزی در این Part رفع شد

| # | ایراد (ممیزی) | شدت | راه‌حل پیاده‌شده | فایل‌ها |
|---|---|---|---|---|
| 1 | **P1** — راه‌اندازی پزشک و برنامه هفتگی فقط با دست‌کاری مستقیم دیتابیس/REST ممکن بود (هیچ CRUD clinician حتی در backend نبود) | 🔴 بحرانی | صفحه «پزشکان و برنامه» (`cpms_config`): لیست/ثبت/ویرایش/غیرفعال‌سازی پزشک + پیوند ۱:۱ کاربر + ویرایش ۷ روز برنامه هفتگی + استثناهای تعطیلی/مرخصی/بستن — همه از مسیر ScheduleService (Audit + بازتولید Slot خودکار)؛ `ClinicianRepository` جدید با چک ۱:۱ صریح | `src/Admin/ClinicianAdminPage.php`، `src/Infrastructure/Repository/ClinicianRepository.php`، `src/Bootstrap/App.php` |
| 2 | **P12** — نسخه چاپ نداشت؛ پزشک باید موازی کاغذی می‌نوشت | 🟠 UX/عملیاتی | دکمه «🖨️ چاپ» کنار هر نسخه → نمای چاپی فارسی/RTL (سربرگ مطب، بیمار/MRN/سن، تاریخ جلالی، شکایت اصلی، جدول اقلام، امضا) + واترمارک «پیش‌نویس/ابطال‌شده» + خط چاپ؛ مجوز `cpms_rx_read` + `requireOwnVisit` + Audit `PRESCRIPTION_PRINTED` | `ClinicalService::prescriptionForPrint()`، `src/Admin/PrescriptionPrintPage.php`، `DoctorDashboardPage` |

## تصمیم‌های درون‌فازی (طبق §95 — بدون توقف)

1. **حذف فیزیکی clinician ممنوع** — فقط فعال/غیرفعال (FK از Visits/Schedule؛ همراستا با «Deactivate never deletes»).
2. **چک ۱:۱ در Repository صریح شد** — چون `wpdb` روی نقض UNIQUE خطا نمی‌اندازد؛ قید DB (Migration 0007) لایه دوم در برابر Race است؛ صفحه با پیام فارسی رد می‌کند نه Fatal.
3. **برنامه هفتگی از مسیر ScheduleService** (نه SQL مستقیم) — Audit `SCHEDULE_*` + بازتولید Slot همان مسیر تست‌شده REST.
4. **چاپ = دامنه پزشکِ خودش** (ماتریس 4.3) — چاپ نسخه محتوای بالینی است؛ منشی/پزشک دیگر → 403/404 + Audit.
5. **انتخاب نسخه برای چاپ** = آخرین نسخه غیرواقعی (forVisit DESC)؛ واترمارک draft/voided همچنان رندر می‌شود (شفافیت، نه مخفی‌کاری).
6. «ذخیره روز» برنامه = update/create خودکار بر اساس وجود ردیف همان روز (`u_sched_day`) — بدون دو دکمه جدا.

## تست‌های جدید (۸ تست — Integration)

1. `ClinicianRepositoryTest` (۳): roundtrip create/find/update/list + فیلتر فعال؛ پیوند ۱:۱ (تشخیص تعارض + RuntimeException هنگام نقض)؛ schedule_days + user_login در فهرست.
2. `PrescriptionPrintTest` (۵): نمای کامل (اقلام/بیمار/سن/جلالی/شکایت) + Audit چاپ؛ انتخاب آخرین نسخه غیرواقعی؛ rx ویزیت دیگر → 404؛ پزشک دیگر → 404 مالکیت؛ منشی → 403 با `CLINIC_PERMISSION_DENIED`.

## خروج از زنجیره بحرانی ممیزی

با این Part، هر دو قلم **CRITICAL BEFORE CUSTOMER**ِ ممیزی (§35) بسته شد:
- ✅ UI راه‌اندازی (پزشک + برنامه + پزشک-به-کاربر) — این Part
- ⏳ UI بیمار — Part 5 (نیازمند تصمیم محصول؛ Console خواندنی بیمار از Part 1 هست)

Part 3 پیشنهادی: **UI گزارش‌ها (P4)** — ۱۲ گزارش backend آماده.

## چک‌لیست توقف Part (§100)

- [x] CI سبز روی pushed commit `4d5dcf7` — ۱۴/۱۴ (Integration ۳۱۵ تست/۰ شکست، Unit 8.1–8.4، Pilot/Staging، Closure)
- [x] تست‌ها نوشته‌شده (اجرای نهایی با CI)
- [x] Security — سه‌لایه مجوز (cap/nonce/مالکیت) + Audit همه تغییرات + بدون PHI در لاگ
- [x] Documentation synchronized (user-guide، CHANGELOG، agent-guide، این گزارش)
- [ ] تأیید کارفرما → سپس Part 3
