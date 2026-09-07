# ADR-0030 — Override مدیریتی Capabilities نقش‌ها + Scope صف پزشک + Console بیمار

| | |
|---|---|
| **وضعیت** | Accepted |
| **تاریخ** | 2026-09-07 |
| **تصمیم‌گیر** | کارفرما (دستور رفع ایرادات ممیزی — Part 1) |
| **مربوط** | ADR-0026 (نقش‌های پویا V2)، ADR-0027 (یک محصول چندپزشکی)، permission-matrix v1.4 |
| **محرک** | ممیزی مستقل کد (`6e42519`) — یافته‌های P2، P5، P8 |

## Context

ممیزی مستقل سه شکاف واقعی را ثبت کرد که هر سه با اصول Master Context در تعارض بودند:

1. **(P2)** Self-healing نقش‌ها (TP-10) هر `cpms_*` خارج از Template کلاس را در هر بار بارگذاری **بی‌صدا حذف می‌کرد** — یعنی اعطای عمدی ادمین (مثلاً `cpms_export` به منشی با افزونه جانبی) در همان رفرش بعدی پاک می‌شد. هیچ UI/Workflow مدیریت دسترسی هم وجود نداشت؛ ادمین عملاً توان ویرایش دسترسی نقش‌ها را نداشت.
2. **(P8)** `/queue`، `rt/queue` و آمار داشبورد برای نقش doctor **کل کلینیک** را برمی‌گرداند (فقط `QUEUE_READ` چک می‌شد) — تعارض با Master Context §8: «Doctor نباید به صورت implicit داده Doctor دیگر را ببیند.» (اکشن‌های پزشک از F9 محافظت شده بودند اما Read-Feed نه.)
3. **(P5)** بیمارِ `cpms_patient` بعد از OTP به پیشخوان پیش‌فرض وردپرس می‌رسید؛ نه مقصد فارسی داشت نه Admin Bar مخفی می‌شد.

## Decision

### 1) Override مدیریتی Capabilities (به‌جای حذف بی‌صدا)

- Option جدید `cpms_role_caps_override` (autoload): `role => list<string caps>` فقط برای `cpms_secretary`/`cpms_doctor`، فقط زیرمجموعه `RolesAndCapabilities::ALL_CAPS`.
- وقتی Override برای نقشی وجود دارد، **Self-healing مبنایش Override است نه Template کلاس**؛ خارج از فهرست موثر همچنان پاک می‌شود (Least Privilege برقرار می‌ماند — TP-10 سالم).
- ذخیره فقط از مسیر صفحه جدید «CPMS (دسترسی‌ها)» (`cpms_config` + Nonce + Audit `ROLE_PERMISSION_CHANGED`/`ROLE_PERMISSION_RESET` با before/after).
- نقش بیمار هرگز قابل ویرایش نیست (P-5 — Ownership فقط). اگر فهرست ارسالی == Template پیش‌فرض → Override حذف می‌شود (Semantics «بازگشت به پیش‌فرض»).
- Capهای جدید نسخه‌های آینده در UI با تیک‌خاموش ظاهر می‌شوند؛ تا فعال‌سازی عمدی ادمین به نقش‌های Override-دار **اضافه نمی‌شوند** (قابل پیش‌بینی).
- نقش‌های دینامیک کامل (ساخت/کپی نقش) همچنان V2/ADR-0026 است — این ADR فقط «ویرایش Templateهای موجود» را مدیریت‌شده می‌کند.

### 2) Scope صف/Feed برای پزشکِ متصل

- `VisitService` برای نقش doctor که به Clinician فعالی متصل است: `today()`، `eventsSince()`، `lastEventId()` فقط ویزیت‌های همان Clinician را برمی‌گردانند (پارامتر ورودی نادیده — Scope سرور-side).
- پزشکِ بدون اتصال: **هیچ** (صفر نتیجه) — نه کل کلینیک.
- منشی/سایر `QUEUE_READ`: دامنه مطب (بدون تغییر — V1 تک-کلینیک ADR-0003).
- پشتیبانی در Repository با پارامتر اختیاری `?int $clinicianId` روی `statsFor`/`eventsSince`/`lastEventId` (سازگار با مهاجرت — بدون Schema change).
- تست: `DoctorQueueScopeTest` (سه سناریو: اسکوپ+آمار، فید Real-time، پزشک بدون اتصال).

### 3) Console بیمار «نوبت‌های من»

- صفحه Admin جدید `cpms-patient` فقط برای «بیمارِ خالص» (نقش `cpms_patient` بدون نقش ستادی)؛ خواندنی؛ داده فقط خودش از مسیر `BookingService::listMine` (Ownership — cpms_patient_user_links).
- `login_redirect` بیمارِ خالص → همین صفحه؛ `show_admin_bar` برای او مخفی؛ هر GET دیگر از wp-admin → redirect به همین صفحه (بدون دخالت POST/AJAX/REST/CLI).
- رزرو آنلاین UI در این Part عرضه نمی‌شود — پیام صادقانه «به‌زودی + تماس با مطب».

## Consequences

- ✅ ادمین می‌تواند دسترسی نقش‌ها را از UI مدیریت کند و تعمدش پایدار می‌ماند (Audit دارد).
- ✅ «پزشک داده پزشک دیگر را ضمنی نمی‌بیند» اکنون روی مسیرهای Read هم enforce است، نه فقط اکشن‌ها.
- ✅ بیمار بعد از ورود، تجربه فارسی و منظم دارد.
- ⚠️ رفتار قدیمی «پزشکِ بدون اتصال، کل کلینیک را می‌دید» عمداً حذف شد — breaking نمی‌چرخد چون داشبورد پزشک از ابتدا own-clinician بود و این مسیر فقط مصرف داخلی/اشتباه داشت (تست جدید قفلش می‌کند).
- ⚠️ Overrideها در بکاپ `cpms_settings`/options می‌مانند؟ **خیر** — Option جدای WP است و در dump اختصاصی `cpms_*` بکاپ فعلی نمی‌آید؛ گم شدنش فقط یعنی بازگشت به Template پیش‌فرض (fail-safe). در report-remediation-part1 ثبت شد.

## Alternatives Considered

- **حذف کامل Self-healing:** رد شد — Least Privilege (TP-10/F9) را از بین می‌برد.
- **فقط Audit on-removal بدون Override:** رد شد — مشکل کاربر (ناتوانی ادمین) حل نمی‌شد.
- **Scope پزشک در Controller:** رد شد — Scope باید سرور-side در Service باشد (P-1).
- **Portal کامل بیمار:** خارج از Part 1 — بعد از تصمیم محصول (roadmap).
