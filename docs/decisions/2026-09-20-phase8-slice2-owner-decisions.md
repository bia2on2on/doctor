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
- **بازاستفاده هویت:** پیش از ساخت کاربر، `{mobile}@otp.cpms.local`
  جست‌وجو و در صورت وجود بازاستفاده می‌شود (همان user_id،
  `is_new_user=false`، بدون ردیف wp_users تکراری؛ بدون جدول هویت جدید؛
  بدون merge تخریبی Patient). لینک بیمار فقط اگر Patient در Clinicِ Challenge
  وجود داشته باشد.

## شمارهٔ Migration

زنده‌بازبینی پیش از رزرو شماره انجام شد: آخرین Migration روی main در لحظهٔ
GREEN همان `2026_09_09_0020` بود و `0021` آزاد؛ طبق قرارداد نام‌گذاری
۴رقمی مخزن، Migration جدید `2026_09_20_0021_otp_tokens_clinic_binding.php`
با version `2026_09_20_0021` است (هنوز `2026_09_09_0020` را به‌صورت
lexicographic دنبال می‌کند و ترتیب اجرا حفظ است).
