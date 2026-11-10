# F3 — موارد باز (گپ‌های Contract/طراحی) — 2026-09-05

طبق Governance 2026-09-05: هیچ‌کدام **پیش از تأیید کارفرما** در Contract اعمال نشده‌اند (Code روی فرض پیشنهادی آماده است تا پس از تأیید، فقط Contract Update شود).

## GAP-1 — بُعد Clinician در Endpointهای Booking (Contract)

**تعارض/گپ:** `cpms_schedule_slots` با `(clinician_id, slot_date, slot_time)` یکتاست (ERD/ADR-0004). اما در Contract:
- B1 `POST /booking/hold` → Body `{slot_date, slot_time, hold_id?}` — **بدون clinician_id**
- B5 `POST /appointments/{id}/reschedule` → `{slot_date, slot_time}` — بدون clinician جدید
- D10 `POST /appointments` (منشی) → `{patient_id, slot_date, slot_time, reason?}` — بدون clinician_id

بدون clinician، Slot در دیتابیس **نامعتبر/ambiguous** است (چند پزشک = چند Slot برای همان تاریخ/ساعت).

**Impact Analysis:**
- Security: بدون clinician explicit، خطا در Data-Access (بیمار A اسلات پزشک X را بگیرد) — ریسک واقعی.
- Performance: Search کلینیک-ساز برای یافتن clinician = O(doctors) — رد.
- Data: **بدون تغییر Schema** (کلامن‌ها موجودند) — فقط Contract Field.
- Scope: ۳ Endpoint + ۱ فیلد اختیاری؛ کلاینت‌ها V1 هنوز وجود ندارند (Pre-Release) → هزینه سازگاری ≈ ۰.

**پیشنهاد (مینیمال، Additive):**
| Endpoint | تغییر |
|---|---|
| B1 hold | + `clinician_id` (**الزامی**) — UI همیشه از تقویم پزشک X آمده |
| B5 reschedule | + `clinician_id` (**اختیاری** — Default = پزشک فعلی نوبت؛ جابه‌جایی بین پزشک = V2 یا با همین فیلد) |
| D10 staff create | + `clinician_id` (**الزامی**) |

**وضعیت:** ✅ **تأیید نهایی کارفرما (2026-09-05)** — اعمال شد: Contract + Validation + Tests + Docs.

## طراحی‌های F3 (مستند برای آگاهی — نیاز به تأیید در صورت اختلاف)

| # | تصمیم پیاده‌سازی | مستندسازی |
|---|---|---|
| N-1 | **ساخت خودکار Patient هنگام B2 confirm** برای کاربر جدید (موبایل OTP-verified، هنوز Patient Record ندارد) — Minimal (mobile فقط؛ Nameها خالی تا C2) | contract §1 Note: «اکانت در گام بعد (A5/A6) ساخته می‌شود» — Patient Record در زمان confirm ساخته می‌شود (نه اکانت WP که در F2/OTP ساخته شد) |
| N-2 | **D10 staff-create = pending → confirmed** در یک operation (ویزیت حضوری/فوری بلافاصله معتبر) — هر دو Transition در AppointmentMachine (T1 + T3) | SRS FR-5.3 (ساخت/لغو/جابه‌جا در محدوده مجوز) — Status صریح نشده؛ `is_walkin_express=1` اگر روز جاری |
| N-3 | **Staff create بدون min-lead** (فقط max-future-days) — فوری/حضوری می‌تواند «امروز» باشد | FR-5.3 «نوبت حضوری/فوری» |
| N-4 | **B1 برای user+slot با Hold Active موجود** → همان Token بازگردد (Idempotent) | جلوگیری از Double-Hold همان بیمار روی یک Slot |
| N-5 | **Mobile بیمار** در Hold: (1) Patient Link primary → (2) Email کلاینت OTP (`{mobile}@otp.cpms.local` — پترن F2) → (3) `CLINIC_VALIDATION_FAILED` | برای کاربر جدید بدون Patient Record |
| N-6 | **MRN**: `MR-{YYMMDD}-{5 char random}` با Retry روی Unique Constraint (بدون counter جدی) | cpms_patients.mrn unique per clinic |
| N-7 | **Audit**: PHI (name/mobile/nid) در **AuditLog محافظت‌شده** ثبت می‌شود (کارکرد ذاتی Audit — ADR-0008)؛ **OpLog/خطای REST**: فقط Masked (mobile = ۴ رقم آخر) — **بدون PHI در Log عملیاتی/پاسخ خطا** | الزام کارفرما F3: «Audit/Operational logging without PHI leakage» |
| N-8 | **Deadline لغو/جابه‌جا** از Settings خوانده می‌شود **در لحظه تصمیم** (`booking.cancel_deadline_hours` / `reschedule_deadline_hours`، Default 24h) — تغییر Setting **Retroactive نیست**: روی نوبت‌های ثبت‌شده یا تصمیمات قبلی اثر ندارد (هیچ رکوردی Deadline ذخیره نمی‌کند) | تأیید کارفرما 2026-09-05 |

> **یادداشت (۲۰۲۶-۱۱-۱۰ — تصمیم مالک OD-A، ۲۰۲۶-۱۱-۰۹):** ردیف **N-5** مرحلهٔ (۲)
> («Email کلاینت OTP به‌عنوان مبنای هویت Hold») و فرضیه‌های «ساخت خودکار حساب/Patient بر
> پایهٔ موبایل در `otp/verify`» (شامل بند F2 در همین سند و GAP-2 پایین) **منسوخ**‌اند.
> تأیید OTP فقط possession موبایل است؛ `{mobile}@otp.cpms.local` هیچ مبنایی برای ساخت/
> یابیدن/هویت نیست و fallback هویت از ایمیل پل در `BookingService::mobile_for_user_in_clinic()`
> در ۲۰۲۶-۱۱-۱۰ حذف شد (fail-closed؛ `CLINIC_VALIDATION_FAILED`). از این پس holder_mobile در
> Hold فقط از Patient لینک‌شدهٔ همان Clinic مشتق می‌شود؛ بیمار جدید در B2 confirm ساخته
> می‌شود. **(یادداشت اصلاحی ۲۰۲۶-۱۱-۱۰، round 9):** این بندِ تفسیر فقط برای مسیر سازگاری Historical-NULL صداقت دارد — یعنی Hold تاریخیِ `patient_id=NULL` با مالک OTP و صفر لینک فعال در Clinic، محدود به TTL نگهداری Hold (`booking.hold_ttl_sec`، پیش‌فرض ۶۰۰ ثانیه، قابل تنظیم)؛ روی نصب‌هایی که migration `2026_09_20_0022_slot_holds_patient_binding` اعمال شده باشد، Hold جدیدِ پس از OD-A هرگز در این شکل ساخته نمی‌شود (B1 برای callerهای resolve-پذیر `patient_id` را می‌فشارد؛ callerِ صفرلینک اصلاً به INSERT نمی‌رسد). reachability این مسیر در نصب‌های pre-0022 ادعا نمی‌شود. موبایل مشترک هرگز مبنای ownership/access نیست. جدول برای حفظ تاریخچه دست‌نخورده
> باقی مانده است.

## GAP-2 (Note — بلاکی نیست)
Contract §1 Note به «A5/A6» (ساخت اکانت + تکمیل Profile) ارجاع دارد که در جدول Contract تعریف نشده. در F2، ساخت اکانت WP داخل `otp/verify` انجام می‌شود (پترن موجود) و تکمیل Profile = C2 (`PUT /patient/me`). **اثر:** بدون؛ پیشنهاد: Note Contract در باند مستندات F3 تصحیح شود (فقط سند).
