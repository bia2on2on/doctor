# تصمیم‌های مالک — Phase 8 Slice 2 (OTP → Hold → Confirm)

تاریخ: 2026-09-20 · دامنه: Phase 8 Slice 2 · وضعیت: پذیرفته‌شده (مالک) · اجرا: PR #89 (GREEN)

این سند فقط دو تصمیمِ صریحِ مالک برای این Slice و پیامدِ معماری OTP را ثبت می‌کند.
متون تاریخی FR-4.2/UC-02 عمداً بازنویسی نمی‌شوند — همان تاریخچه می‌مانند و این
سند، سیاستِ فعلیِ عملیاتی را روایت می‌کند.

---

## تصمیم ۱ — زمانِ Hold (Hold-Timing)

**سیاست:**

1. **پیش از احراز:** فقط Selection (غیر-PHI: clinician/slot/date/time) حفظ
   می‌شود. کاربرِ ناشناس **هرگز** از طریق یک Hold واقعی ظرفیت مصرف نمی‌کند؛
   مرور/quote/درخواست OTP هیچ ردیفی در `cpms_slot_holds` و هیچ جابه‌جایی در
   `held_count` ایجاد نمی‌کند و B1 ناشناس unauthorized می‌ماند.
2. **پس از احرازِ موفق OTP:** سرور clinician/slot/Clinic/تاریخ/ساعتِ
   حفظ‌شده را دوباره از دادهٔ persisted حل و اعتبارسنجی می‌کند و **فقط
   بعد از آن** Holdِ استانداردِ احرازشده را می‌سازد — TTL پیش‌فرض موجود
   (۶۰۰ ثانیه)، `atomicHold`، مالکیت Hold = کاربرِ احرازشده، و سیاست
   FR-4.6 (`nearby_slots` پس از باختِ race) عیناً برقرارند.
3. **کلاینت هرگز authority نیست:** `clinic_id` خام و ادعاهای
   `mobile`/`holder_wp_user_id`/`patient_id`/`is_new_user`ِ بدنهٔ درخواست
   هرگز منبع هویت یا Clinic نیستند. موبایلِ مالکِ Hold از هویت سرور می‌آید.

**پیامد برای سشن/فرانت:** صفحهٔ عمومی برای ناشناس فقط چرخهٔ A2→A3 (ورود با
OTP) و مسیرهای موجود آن را منتشر می‌کند — بدون nonce، بدون B1/B2، بدون PHI.
رندرِ بیمارِ احرازشده `wp_rest` nonce + مسیرهای موجود B1/B2 را منتشر می‌کند
و ادامه با B1 است (B6 resume برای این جریان استفاده نمی‌شود). کاربرِ
واردشدهٔ غیربیمار هیچ continuation بیماری نمی‌گیرد.

## تصمیم ۲ — هویتِ حداقلیِ بیمارِ جدید (B2)

**سیاست:** تعیین «بودن/نبودنِ Patient» فقط با سرور و فقط در
`hold.clinic_id` انجام می‌شود؛ بیمارِ همان موبایل در Clinicِ دیگر «نبودِ
بیمار» در این Clinic است.

- **بیمارِ جدید در hold.clinic_id:** `first_name` و `last_name` پیش از B2
  الزامی‌اند؛ خالی/فقط-فاصله/بیش از ۱۲۰ نویسه → `CLINIC_VALIDATION_FAILED`
  (۴۰۰) — بدون ساخت Patient/Appointment، بدون چسبیدن رزرو Idempotency، و
  Hold فعال و قابل retry می‌ماند. با نام‌های معتبر، Patient در سطح Clinic با
  موبایلِ احرازشدهٔ Hold ساخته می‌شود (MRN/audit/link همان معنای موجود).
- **بیمارِ موجود در hold.clinic_id:** نام‌ها الزامی نیستند و نام‌های
  ارسالی **نادیده گرفته می‌شوند** — B2 هرگز route ویرایش پروفایل نیست و
  ردیف Patient را به‌روزرسانی نمی‌کند.
- **No full profile در این Slice.**

## پیامدِ معماری OTP — اتصالِ Challenge به Clinic (Migration 0021)

اثبات معماری: هیچ شناسهٔ پایدارِ قابل‌اعتمادِ موجودی، Challenge OTP را به
Clinicِ نوبت گره نمی‌زند (نه mobile، نه purpose، نه Settings محیطی).
بنابراین تغییر Schema توجیه دارد:

- `cpms_otp_tokens.clinic_id` — BIGINT UNSIGNED، NULL مجاز، بدون DEFAULT،
  FK → `cpms_clinics(id)`؛ بدون Backfill؛ بدون ایندکس صریح جدید
  (InnoDB برای FK ایندکس لازم را می‌سازد)؛ `down()` ابتدا FK سپس ستون.
- **A2:** با انتخابِ bookable، Clinic فقط از دادهٔ persisted مشتق می‌شود
  (clinician فعال → Clinic → slot داخل همان Clinic → اثباتِ رابطه) و روی
  Challenge مُهر می‌شود؛ سیاست OTP و SMS از همان Clinic. Tamper →
  404/422 تثبیت‌شده با صفر Challenge و صفر SMS. بدون انتخاب: تک-Clinic =
  رفتار تثبیت‌شده؛ چند-Clinic = fail-closed با `CLINIC_SCOPE_REQUIRED` (هرگز 500).
- **A3:** بدنه فقط هویت‌سطح می‌ماند (`mobile/code/purpose` — AD-15). Clinicِ
  Challenge مرجعِ سیاست OTP، جست‌وجوی Patient و Clinicِ لینک است. ردیفِ
  تاریخی NULL: تک-Clinic = resolver تثبیت‌شده؛ چند-Clinic = fail-closed
  صریح. کلیدهای cooldown/lockout هویت‌سطح می‌مانند.
- **بازاستفاده هویت:** ~~پیش از ساخت کاربر، `{mobile}@otp.cpms.local`
  جست‌وجو و در صورت وجود بازاستفاده می‌شود~~ **منسوخ — تصمیم مالک ۲۰۲۶-۱۱-۰۹
  (OD-A، Option A):** تأیید OTP هیچ کاربری نمی‌سازد/نمی‌یابد و هیچ Patientی را
  resolve/link نمی‌کند؛ پاکت یکنواخت صفردسترسی برای هر کد معتبر؛ بدون Session،
  بدون `LOGIN_SUCCESS`. `{mobile}@otp.cpms.local` هیچ مبنای هویتی ندارد.
  دسترسی فقط از طریق حساب WP صریحاً link‌شده توسط staff؛ بیمار جدید در B2
  confirm ساخته می‌شود. **(یادداشت اصلاحی ۲۰۲۶-۱۱-۱۰ — round 9 منتشر شد، متن همان annotation در round 10 با توصیف راستی‌آزمایی‌شده تصحیح شد):** این جمله/بندِ تفسیرِ بالا فقط برای مسیر سازگاری Historical-NULL صداقت دارد: یعنی دو گروه ردیف: (i) ردیفی که روی نصبی درج شد که در همان لحظه ستونِ `patient_id` در `cpms_slot_holds` وجود نداشت (migration `2026_09_20_0022_slot_holds_patient_binding` اعمال نشده بود — در آن نصب ستون وجود ندارد و هیچ Holdی آن ستون را درج نمی‌کند)، و (ii) ردیفِ legacy که **پیش از اعمال** آن migration درج شد و NULL باقی ماند — migration **بدون backfill** است (ستون BIGINT UNSIGNED NULL + FK؛ پیش‌بررسیِ orphan با abort)؛ هر Holdِ تازه روی نصبی که migration را اعمال کرده باشد برای callerِ لینک‌دار `patient_id` را persist می‌کند. سازندهٔ نمای (i): هر کاربرِ احرازشدهٔ WP با نقش `cpms_patient` که **≥۱ Patientِ فعالِ لینک‌شده در Clinicِ نوبت** داشته باشد — این شرط لازم است ولی کافی نیست (مسیر REST به گیت RuntimeSchemaGate، nonce معتبر و کاربر موجود و نقش `cpms_patient`، rate limit، مجوز `OP_APPOINTMENT_BOOK`، clinician/slot/Location و Organization فعال، همخوانی session scope، پنجرهٔ زمانی، ظرفیت آزاد و audit موفق نیاز دارد) — و صفرلینک‌ها پیش از INSERT رد می‌شوند: یا زودتر با `CLINIC_SCOPE_REQUIRED`/۴۰۰ در گیت scope (نبودِ markerِ current یا ناهم‌خوانی Clinic)، یا با markerِ current در گیت پروفایل با `CLINIC_VALIDATION_FAILED`/۴۰۰ (`BookingService.php:567`)؛ هیچ‌کدام به INSERT نمی‌رسند. پس این ردیف‌ها نه «مالکِ OTP» دارند و نه الزامیِ «صفر لینک». پنجرهٔ فعالِ این Hold با انقضای `booking.hold_ttl_sec` (پیش‌فرض ۶۰۰ ثانیه، قابل تنظیم) بسته می‌شود — ردیف از جدول حذف نمی‌شود (`HoldsExpireHandler` فقط `status='expired'` می‌کند، `src/Application/Jobs/HoldsExpireHandler.php:48-50`). در confirm، وقتی `patient_id` ردیف Hold پوچ باشد، resolver سازگاری Historical-NULL لینک‌های فعالِ **callerِ تأییدکننده (= نگه‌دارندهٔ Hold)** را در Clinicِ Hold می‌شمرد (`linkedActivePatientsForClinic`, `BookingService.php` ~L3079، فراخوانی ~L897): صفر → New-Patient (نام‌ها الزامی؛ برخورد موبایل هم‌نام → 400)، یکی → همان هویت، بیش از یکی → 422 `CLINIC_PATIENT_SELECTION_REQUIRED`. روی نصب‌هایی که migration `2026_09_20_0022_slot_holds_patient_binding` اعمال شده باشد، Hold جدیدِ پس از OD-A هرگز در این شکل ساخته نمی‌شود (B1 برای callerهای resolve-پذیر `patient_id` را می‌فشارد؛ callerِ صفرلینک اصلاً به INSERT نمی‌رسد). reachability این مسیر در نصب‌های pre-0022 ادعا نمی‌شود.** موبایل مشترک هرگز مبنای ownership/access نیست.
  (متن اصلی برای تاریخچه باقی ماند.)

## شمارهٔ Migration

زنده‌بازبینی پیش از رزرو شماره انجام شد: آخرین Migration روی main در لحظهٔ
GREEN همان `2026_09_09_0020` بود و `0021` آزاد؛ طبق قرارداد نام‌گذاری
۴رقمی مخزن، Migration جدید `2026_09_20_0021_otp_tokens_clinic_binding.php`
با version `2026_09_20_0021` است (هنوز `2026_09_09_0020` را به‌صورت
lexicographic دنبال می‌کند و ترتیب اجرا حفظ است).
