# REST API Contract — CPMS

نسخه 1.0 | 2026-09-05 | فاز 5 | Namespace: `POST/GET /wp-json/clinic/v1/...`

## 0. کنواسیون‌ها

| موضوع | قرارداد |
|---|---|
| Auth | Cookie Session وردپرس + هدر `X-WP-Nonce` (CSRF) روی همه موتانت‌ها. Public فقط 4 Endpoint. |
| Format | JSON UTF-8. زمان‌ها ISO-8601 UTC (`2026-09-05T12:30:00.000Z`) + فیلدهای نمایش Jalali جفت (مثلاً `slot_date_jalali`). |
| خطا | `{ "code": "CLINIC_*", "message": "...", "data": {} }` + HTTP مناسب (400 validation, 403 forbidden, 404 not-found-as-forbidden برای Patient, 409 conflict). **تمام کدها با پیشوند ثابت `CLINIC_*`** — فهرست مرکزی: `docs/api/error-codes.md` (ADR-0019). |
| Pagination | `?page=1&per_page=20` → header `X-Total-Count`. |
| Idempotency | هدر `Idempotency-Key: <uuid>` روی Endpointهای `payment`, `booking/confirm`, `handwriting/page` (موتانت‌های حساس). دامنهٔ یکتایی = `(key, endpoint, wp_user_id, context_id)` — یعنی همان کلید در Endpoint/کاربر/زمینه (مثلاً PageId دست‌خط) مختلف مانعه‌ی هم نیست و هر جفتِ Request/Response را جدا نگه می‌دارد (F9: UNIQUE چهارستونه + نرمال‌سازی NULL→0). |
| Rate Limit | `X-RateLimit-Limit/Remaining/Reset`. محدودیت‌ها: OTP (10/hr), booking (10/hr), login (20/hr), upload (10/hr). |
| Versioning | `clinic/v1` — شکست‌های بعدی → `v2` (compat window). |
| Security | هر Endpoint: Capability Check + Data-Access (Permission Matrix). 404 برای «داده دیگری» (افشای وجود ندهد) + Audit `FORBIDDEN_ACCESS_ATTEMPT`. |
| CORS | Same-origin فقط؛ Origin خارجی → 403. |
| نکات اعتبارسنجی WP (F3) | پارامترهای الزامی/نوعی که WP REST قبل از Handler می‌سنجد، با خطای بومی WP برمی‌گردند: `rest_missing_callback_param` (پارامتر الزامی حذف‌شده) و `rest_invalid_param` (نوع نامعتبر) — هر دو 400 با ساختار استاندارد WP (`{code, message, data:{status}}`). کدهای دامنه/سیاست همیشه `CLINIC_*` خودمان هستند. همچنین Nonce اشتباه در درخواست Cookie-Authenticated ممکن است پیش از رسیدن به لایه ما با `rest_cookie_invalid_nonce` (403 بومی WP) رد شود — گم‌شدن Nonce همیشه با `CLINIC_INVALID_NONCE` پاسخ می‌شود. |

## 1. Public (بدون Auth)

| # | Method/Path | توضیح | Response 200 |
|---|---|---|---|
| A1 | `GET /availability?clinician_id&from&to` | تقویم آزاد (Jalali UI) | `{days:[{date, slots:[{time, capacity_left}]}]}` |
| A2 | `POST /otp/request` | `{mobile, purpose?}` + انتخابِ اختیاری `{clinician_id, slot_id, slot_date, slot_time}` (فقط Selector — Phase 8 Slice 2) | `{expires_in:300}`؛ RateLimit: 3/روز، cooldown 60s |
| A3 | `POST /otp/verify` | `{mobile, code, purpose?}` — هرگز Clinic/انتخاب در بدنه | `{user_id, patient_links:[{patient_id, mrn, first_name,last_name}], is_new_user, session_issued}` |
| A4 | `POST /booking/quote` | `{clinician_id, slot_date, slot_time}` — پیش‌بررسی آزاد بودن (بدون Hold) | `{available:bool, capacity_left}` |

> A2/A3 (به‌روزرسانی F2/F3 — رفع GAP-2): `otp/verify` برای کاربر جدید، **اکانت `cpms_patient` می‌سازد** و به رکورد Patient موجود (بر اساس موبایل) لینک می‌کند (تصمیم نهایی F2: Auto-Creation + Linking؛ تست‌شده). شماره‌گذاری قدیمی «A5/A6» از نسخه پیشین مستندات باقی‌مانده بود و در پیاده‌سازی وجود خارجی ندارد؛ تکمیل/ویرایش پروفایل از طریق C1/C2 و ساخت بیمار توسط منشی (D3) پوشش داده می‌شود.

> **Phase 8 Slice 2 — A2/A3 با اتصال به Clinic (تصمیم مالک: زمان‌بندی Hold):**
>
> - **A2 با انتخاب (همهٔ چهار Selector):** سرور فقط از داده‌های ذخیره‌شده (`persisted`) Clinic را استنتاج می‌کند: (۱) پزشکِ فعالِ ذخیره‌شده (`persisted clinician`) ← در غیر این صورت `CLINIC_NOT_FOUND`/۴۰۴؛ (۲) Clinic از همان پزشک؛ (۳) نوبتِ (`slot`) داخل همان Clinic ← در غیر این صورت `CLINIC_NOT_FOUND`/۴۰۴؛ (۴) اثباتِ رابطه‌ی پزشک/نوبت/تاریخ/ساعت ← در غیر این صورت `CLINIC_VALIDATION_FAILED`/۴۲۲. سپس Challenge روی همان Clinic مهر می‌شود (`cpms_otp_tokens.clinic_id` — Migration 0021) و سیاست OTP/SMS از همان Clinic اجرا می‌شود. دستکاری/ناهماهنگی انتخاب (`selection tampered`) ← قبل از هر Challenge و هر پیامک (`SMS`)، خطای بسته (`fail-closed`) می‌شود (صفر Challenge، صفر `SMS`). شناسه‌های خام (`raw`) `clinic_id` کلاینت هرگز مرجع نیست.
> - **A2 بدون انتخاب:** تک‌کلینیک (`Single-Clinic`) = همان رفتار تثبیت‌شده‌ی حل‌کننده (`resolver`)؛ چند‌کلینیک (`Multi-Clinic`) = خطای بسته (`fail-closed`) با پاکت محصول `CLINIC_SCOPE_REQUIRED`/۴۰۰ (هرگز استثنای رهاشده (`uncaught`)، هرگز ۵۰۰).
> - **A3:** مرجع Clinic، همان Clinicِ مهرشده روی ردیف Challenge است (نه بدنه‌ی درخواست، نه `Scope` محیطی): سیاست `OTP`، جست‌وجوی بیمار (`patient`) در همان Clinic و Clinicِ لینکِ بیمار از آن می‌آید. Challenge تاریخیِ `clinic_id=NULL`: تک‌کلینیک = حل‌کننده‌ی تثبیت‌شده؛ چند‌کلینیک = خطای بسته (`fail-closed`) صریح `CLINIC_SCOPE_REQUIRED`. معناشناسی ردیف آخر (`latest-row`) و کلیدهای `Cooldown/Lockout` هویت‌سطح می‌مانند (`AD-15` — بدون بازطراحی).
> - **بازاستفاده هویت OTP:** پیش از ساخت کاربر، هویت قطعیِ `{mobile}@otp.cpms.local` جست‌وجو و در صورت وجود بازاستفاده می‌شود (همان `user_id`، `is_new_user=false`، بدون ردیف تکراری `wp_users`)؛ لینکِ بیمار فقط اگر بیمار در Clinicِ همان verify وجود داشته باشد (بیمارِ کلینیک دیگر هرگز لینک نمی‌شود).

## 2. Booking (Authenticated: patient)

| # | Method/Path | Body | توضیح |
|---|---|---|---|
| B1 | `POST /booking/hold` | `{clinician_id (الزامی), slot_date, slot_time}` | Hold (TTL 10 دقیقه). Response: `{hold_token, expires_at, slot:{...}}`. RateLimit: 10/hr. خطا: `CLINIC_SLOT_TAKEN`, `CLINIC_POLICY_VIOLATION`. (GAP-1/G-3: 2026-09-05) |
| B2 | `POST /booking/confirm` | `{hold_token, reason?, first_name?, last_name?}` + `Idempotency-Key` (الزامی) | بازبینی نهایی Slot → Appointment `confirmed`. Response: `{reference_code, appointment_id, slot:{...jalali}, status}`. خطا: `CLINIC_SLOT_TAKEN`, `CLINIC_HOLD_EXPIRED`, `CLINIC_DUPLICATE_APPOINTMENT`. Replay = پاسخ Origin. (Phase 8 Slice 2: نام‌ها فقط برای بیمارِ «جدید» در hold.clinic_id الزامی می‌شوند — تصمیم سرور؛ بیمارِ موجود نام‌های ارسالی را نادیده می‌گیرد.) |
| B3 | `GET /appointments/mine?from&to` | — | لیست نوبت‌های من (تاریخ/وضعیت) |
| B4 | `POST /appointments/{id}/cancel` | `{reason?}` | در Policy (FR-4.9 — حداقل X ساعت قبل؛ Configurable). خطا: `CLINIC_POLICY_VIOLATION`, `CLINIC_INVALID_TRANSITION` |
| B5 | `POST /appointments/{id}/reschedule` | `{slot_date, slot_time, clinician_id? (اختیاری — Default = پزشک فعلی نوبت)}` + `Idempotency-Key` (الزامی) | مسیر بیمار: در Policy (FR-4.10 — deadline + destination min-lead) + انتقال Hold. Response: `{appointment_id (جدید), reference_code, slot:{...}, previous_appointment_id}`. خطا: `CLINIC_SLOT_TAKEN`, `CLINIC_DUPLICATE_APPOINTMENT`, `CLINIC_POLICY_VIOLATION`. (GAP-1/G-3). Staff uses the same path — see D11b. |
| B6 | `GET /booking/resume?hold_token` | — | ادامه رزرو بعد از قطعی (ER-03). Response: `{hold_token, status: active|converted, expires_at?, slot?}`. خطا: `CLINIC_HOLD_EXPIRED` |

## 3. Patient (Authenticated: patient)

| # | Method/Path | توضیح |
|---|---|---|
| C1 | `GET /patient/me` | پروفایل خود (فیلدهای مجاز) |
| C2 | `PUT /patient/me` | ویرایش فیلدهای مجاز Policy (فرآیند تغییر → Audit) |
| C3 | `POST /patients/{patient_id}/files` | آپلود (multipart) — Validation: MIME/Extension/Size |
| C4 | `GET /patients/{patient_id}/files` | فایل‌های مجاز |
| C5 | `GET /visits?from&to` | تاریخچه ویزیت — **فقط فیلدهای patient_visible** |
| C6 | `GET /visits/{id}` | جزئیات ویزیت (نماهای مجاز: notes patient_visible, prescription, recommendations, follow-ups) |
| C7 | `GET /prescriptions` | نسخه‌های من (مجاز) |
| C8 | `GET /invoices` | فقط اگر `patient.profile_invoices_visible=true` |

## 4. Secretary (Authenticated: `clinic_secretary` + Capabilities)

| # | Method/Path | Cap | توضیح |
|---|---|---|---|
| D1 | `GET /secretary/today` | `cpms_queue_read` | داشبورد امروز (همه ستون‌های صف + آمار) — یک Query بهینه |
| D2 | `GET /patients/search?q=&fields=` | `cpms_patient_read` | جستجو (نام/موبایل/کدملی/MRN) |
| D3 | `GET /patients/{id}` | `cpms_patient_read` + Data-Access | پروفایل کامل |
| D4 | `POST /patients` | `cpms_patient_create` | Patient جدید (MRN خودکار) |
| D5 | `PUT /patients/{id}` | `cpms_patient_update` | فرآیند مجاز |
| D6 | `POST /visits/checkin` | `cpms_queue_checkin` | `{patient_id, appointment_id?}` → Visit + history |
| D7 | `POST /visits/walk-in` | `cpms_queue_checkin` | `{patient_id}` |
| D8 | `POST /visits/{id}/status` | `cpms_queue_advance` | `{to_status, note?}` — Transitionهای مجاز منشی (enqueue, cancel, ...) |
| D9 | `GET /appointments?date&status` | `cpms_appt_read` | لیست نوبت‌های روز |
| D10 | `POST /appointments` | `cpms_appt_create` | نوبت حضوری/فوری `{patient_id, clinician_id (الزامی), slot_date, slot_time, reason?}` — بدون min-lead (فوری/حضوری)؛ `is_walkin_express` اگر روز جاری. (GAP-1/G-3) |
| D11 | `POST /appointments/{id}/cancel` | `cpms_appt_cancel` | با دلیل |
| D11c | `POST /appointments/{id}/no-show` | `cpms_appt_no_show` + trusted Clinic scope | FR-5.5 مسیر کارکنی (T8): `{reason}` الزامی (whitespace-only نامعتبر — `CLINIC_VALIDATION_FAILED`). `confirmed` → `no_show`؛ `no_show_at` نوشته می‌شود؛ دلیل فقط در شواهد Audit `APPOINTMENT_NO_SHOW` حفظ می‌شود (`appointments.reason` بازنویسی نمی‌شود؛ شمارنده‌های اسلات تغییر نمی‌کنند). اشاره‌گر کهنهٔ `active_visit_id` مسدود نمی‌کند و در T8 موفق پاک می‌شود. خطا: `HAS_ACTIVE_VISIT` (409 — ویزیت واقعاً فعال)، `CLINIC_INVALID_TRANSITION` (409)، `CLINIC_NOT_FOUND` (404 parity برای Clinic دیگر)، `CLINIC_PERMISSION_DENIED` (بدون مجوز scoped). بدون اعلان بیمار. |\n| D11b | `POST /appointments/{id}/reschedule` | `cpms_appt_reschedule` + trusted Clinic scope + `Idempotency-Key` (UUID الزامی) | مسیر کارکنی روی همان سطح موتیشن B5. Body: `{slot_date, slot_time, clinician_id?, slot_id?}`. `confirmed` → `rescheduled` + یک نوبت `confirmed` جایگزین با پیوند دوطرفه. خطا: `HAS_ACTIVE_VISIT` (409)، `CLINIC_NOT_FOUND` (404 parity برای شناسهٔ Clinic دیگر)، `CLINIC_PERMISSION_DENIED` (بدون مجوز scoped). **Owner-issued product policy (NOT original SRS wording):** staff is **not** subject to the patient 24-hour reschedule deadline (FR-4.10) and **not** subject to the patient destination min-lead restriction; a successful staff reschedule sends **both** the internal patient change notification and the existing reschedule SMS/change notification. Patient B5 deadline/min-lead/ownership remain unchanged. |
| D12 | `POST /invoices` | `cpms_invoice_create` | `{visit_id, items:[{service_id?, description, quantity|qty, unit_price|price, discount?}], discount?, tax?}` — مبالغ ریالِ صحیح (TP-18)؛ وضعیت ویزیت `consultation_completed`/`awaiting_payment`؛ V11 سیستمی؛ **201** |
| D12b | `GET /invoices/{id}` | `cpms_invoice_read` | نمای کامل فاکتور (اقلام/پرداخت‌ها/اصلاحات) — UI تسویه |
| D12c | `GET /visits/{id}/invoice` | `cpms_invoice_read` | فاکتور فعال ویزیت (رفع ویزیت→فاکتور در UI)؛ بدون فاکتور → 404 |
| D13 | `POST /invoices/{id}/payments` | `cpms_payment_create` | `{amount, method, transaction_ref?}` + **Idempotency-Key** (الزامی — بدون آن 400)؛ اولین ثبت **201**، تکرار همان کلید **200** + `code=CLINIC_IDEMPOTENCY_REPLAY` + همان `payment_id` (M-1/TP-02) |
| D14 | `POST /payments/{id}/void` | `cpms_payment_void` | `{reason}` — فقط همان روز ثبت (UTC؛ `CLINIC_VOID_WINDOW_EXPIRED`)؛ Invoice بازگردانی؛ ویزیت دست‌نخورده (V12 یک‌طرفه) |
| P3 | `POST /payments/{id}/refund` | `cpms_payment_refund` | `{reason, amount?}` — پیش‌فرض: کل مبلغ باقیماندهٔ قابل بازگردانی؛ جزئی → `captured` می‌ماند، کامل → `refunded` |
| D15 | `POST /invoices/{id}/adjustments` | `cpms_invoice_adjust` | `{type: credit|debit, amount, reason}` — فقط فاکتور `open/partial` (M-6) |
| D16 | `POST /visits/{id}/checkout` | `cpms_queue_checkout` | `{waive_invoice?: {reason}}` — فاکتور باز → `CLINIC_NOT_SETTLED` (V14) مگر مسیر معافیت |
| D17 | `GET /invoices/{id}/receipt` | `cpms_invoice_read` | رسید **JSON ساخت‌یافته + نمای چاپ UI (window.print)** — Deterministic (M-5) + تاریخ جلالی؛ PDF سمت سرور = Backlog (بدون Dependency جدید) |
| D18 | `GET /finance/summary?from&to` | `cpms_finance_read` | آمار مالی (Revenue, By-Method, Refunded, Open Balances, آخرین پرداخت‌ها) — تاریخ‌ها `YYYY-MM-DD` (پیش‌فرض امروز UTC) |

## 5. Doctor (Authenticated: `clinic_doctor` + Capabilities)

| # | Method/Path | Cap | توضیح |
|---|---|---|---|
| E1 | `GET /doctor/today` | `cpms_queue_read` | آمار + صف زنده |
| E2 | `GET /queue` | `cpms_queue_read` | صف (waiting + called + ...) |
| E3 | `POST /visits/{id}/call` | `cpms_queue_call` | `{room?}` → اعلان Real-Time به منشی |
| E4 | `POST /visits/{id}/recall` | `cpms_queue_call` | بازگشت به صف |
| E5 | `POST /visits/{id}/start` | `cpms_consult_start` | `in_consultation` |
| E6 | `POST /visits/{id}/skip` | `cpms_queue_call` | `{reason}` (الزامی) |
| E7 | `GET /visits/{id}/record` | `cpms_medical_read` | **پرونده کامل**: بیمار، آلرژی‌ها، سوابق، ویزیت‌های قبلی، نسخه‌ها، فایل‌ها (Role=Doctor) |
| E8 | `POST /visits/{id}/notes` | `cpms_note_create` | `{category, visibility, content_text, change_reason?}` |
| E9 | `PUT /notes/{id}` | `cpms_note_update` | ویرایش/Correction (نسخه جدید) |
| E10 | `POST /visits/{id}/prescriptions` | `cpms_rx_create` | `{items:[...], is_patient_visible}` → Draft |
| E11 | `POST /prescriptions/{id}/finalize` | `cpms_rx_create` | نهایی‌سازی |
| E12 | `POST /visits/{id}/recommendations` | `cpms_rec_create` | `{items:[{type, text, is_patient_visible}]}` |
| E13 | `POST /visits/{id}/follow-ups` | `cpms_rec_create` | `{is_needed, suggested_date, interval_days, reason}` |
| E14 | `POST /visits/{id}/complete` | `cpms_consult_complete` | Validation → `consultation_completed` (یک‌بار) |
| E15 | `POST /visits/{id}/reopen` | `cpms_consult_reopen` | Correction (مجوز بالا) `{reason}` |
| E16 | `POST /files` | `cpms_file_upload` | آپلود (patient_id/visit_id) |
| E17 | `GET /files/{id}/stream` | `cpms_file_read` + Data-Access | Stream مجاز (Audit برای private) |
| E18 | `GET /search?q=&type=patient|note|rx&from&to` | `cpms_search` | جستجوی جامع Role-Aware |

## 6. Handwriting (Doctor)

| # | Method/Path | توضیح |
|---|---|---|
| F1 | `POST /handwriting/documents` | `{visit_id, title?, pages:[]}` → Document + صفحات اولیه (پیش‌فرض: یک صفحه A4 خط‌دار) — Cap `cpms_note_create` + مالکیت ویزیت (§4.3)؛ Audit `HW_DOC_CREATE` |
| F1b | `GET /handwriting/documents?visit_id=` | آخرین سند ویزیت + فهرست صفحات (id/revision/version) برای بازکردن مجدد ویرایشگر — Cap `cpms_medical_read` + مالکیت |
| F1c | `POST /handwriting/documents/{id}/pages` | افزودن صفحه در انتها (`width/height/background_template?/background_attachment_id?`) — Cap `cpms_note_create` + مالکیت؛ Audit `HW_PAGE_ADD` |
| F2 | `PUT /handwriting/pages/{id}` | `{stroke_data (base64(gzip(JSON)) **یا** base64(JSON) — تشخیص magic gzip سمت سرور), width, height, client_revision, saved_by?, background_template?, background_attachment_id?, conflict_reason?}` — `Idempotency-Key` **الزامی** (بدون هدر → 400)؛ پروتکل ADR-0014: apply فقط اگر `client_revision == server.client_revision + 1` → `version++` + INSERT نسخه append-only؛ در غیر این صورت `409 CLINIC_CONFLICT` + `data.server` (revision/version/strokes) برای دیالوگ «نسخه من/سرور» — **بدون ادغام خودکار**؛ بازنویسی پس از تضاد = load سرور سپس Save با `conflict_reason` (Audit meta). رترای همان کلید = پاسخ ذخیره‌شده بدون version bump |
| F3 | `GET /handwriting/pages/{id}` | `{width, height, background_template, background_attachment_id, client_revision, version, strokes[] (decode شده)}` — Cap `cpms_medical_read` + مالکیت؛ Preview PNG سمت کلاینت Render می‌شود |
| F4 | `POST /handwriting/pages/{id}/ocr` | `{provider?}` → OCR Job (V1.5) |
| F5 | `GET /ocr/jobs/{id}` | وضعیت + extracted_text (تا تأیید: `review_status=pending`) |
| F6 | `PUT /ocr/jobs/{id}/review` | `{confirmed_text (ویرایش‌شده), action: confirm\|reject}` |

## 7. Admin/Config (Capability صریح)

| # | Method/Path | Cap |
|---|---|---|
| G1 | `GET/POST /config/schedules` + `PUT/DELETE /config/schedules/{id}` — برنامه هفتگی هر پزشک (یک رکورد به‌ازای هر (روز هفته، Location)؛ `day_of_week` 0=شنبه..6=جمعه؛ `start_time/end_time` HH:MM؛ `appointment_duration_min` 5–240؛ `slot_capacity` 1–50؛ بازه استراحت اختیاری داخل بازه کاری). **Phase 6 Slice 3:** `POST` (ساخت) `location_id` **الزامی** است — Location باید واقعی، فعال و متعلق به Clinic معتبرِ Scope باشد؛ بیگانه/ناموجود/غیرفعال در **مرز REST** پیش از رسیدن به سرویس با یک پاکت یکسان `403 CLINIC_SCOPE_UNAVAILABLE` (`reason=location`) رد می‌شود — چون `location_id` هم‌زمان selector مکانیِ همان درخواست است (`RestClinicContext::extractIds` + `TrustedClinicEstablisher::verifiedScope`) و پاریت حفظ می‌شود بدون افشای وجود؛ در **سطح سرویس و مسیر wp-admin** قرارداد `404 CLINIC_NOT_FOUND` (هم‌پاکت با «محل یافت نشد» — عدم شمارش) پابرجاست — و نبودِ فیلد ⇒ `400 CLINIC_VALIDATION_FAILED` `errors.location_id=required` (هیچ جایگزینی بی‌صدا با Location اصلی/اولی انجام نمی‌شود). **Phase 6 Slice 4 + Slice 7:** **Multi-shift فعال است** — چند شیفت **نامتقاطع** در یک (Clinic، Location، clinician، `day_of_week`) مجاز است (و همان روز هفته در Location دیگرِ همان Clinic نیز)؛ همپوشانی شیفت‌های ACTIVE در create/update ⇒ `400 CLINIC_VALIDATION_FAILED` با `errors["start_time,end_time"]=overlapping_shift`؛ تکرار دقیق `start_time` ⇒ `duplicate_schedule_day`؛ مرز مماس (پایان = شروع) مجاز است؛ ردیف غیرفعال مانع نیست ولی فعال‌سازی داخل همپوشانی رد می‌شود. اجرای موازی concurrency-safe است (قفل ردیف پزشک + تراکنش — Slice 7، ادغام‌شده در `bd5e6a18`). `GET`/view مقدار ذخیره‌شدهٔ `location_id` را باز می‌گرداند؛ Slotها Location و timezone محلیِ Location ذخیره‌شده را به‌ ارث می‌برند. تغییر/حذف → حذف Slotهای **خالیِ آینده** و بازتولید اتمیک (ADR-0004)؛ Slot دارای رزرو/hold هرگز حذف نمی‌شود. | `cpms_config` |
| G1b | `GET/POST /config/schedule-exceptions` + `DELETE /config/schedule-exceptions/{id}` — استثنائات (`holiday`/`leave` = تعطیلی کل روز؛ `blocked`/`open_override` = بازه ساعتی الزامی). تاریخ باید آینده باشد. ثبت/حذف همان سیاست Regeneration را اجرا می‌کند. **Phase 6 Slice 6:** `location_id` اختیاری (قرارداد Migration 0015 — بدون migration جدید) — غایب/تهی ⇒ `NULL` = «همهٔ Locationهای Clinic معتبر» و مقدار ⇒ فقط همان Location (باید واقعی، فعال و متعلق به Clinic معتبرِ Scope باشد)؛ تولید Slot برای هر ردیف برنامه با `(location_id IS NULL OR location_id = <row>)` مطابقت می‌کند و `GET`/view مقدار nullable را برمی‌گرداند. در **مرز REST** استثنای Location بیگانه/ناموجود/غیرفعال ⇒ `403 CLINIC_SCOPE_UNAVAILABLE` (`reason=location`)؛ قرارداد `404` پاریتی در سطح سرویس و مسیر wp-admin است. (ثبت افزایشی F3 — خارج از شماره‌گذاری اصلی) | `cpms_config` |
| G2 | CRUD `/config/services` | نوشتن: `cpms_config` (admin فنی)؛ خواندن: `cpms_invoice_read` (منشی/پزشک — فاکتورسازی سریع FR-14.9) **یا** `cpms_config` (admin فنی — P-3) | `GET ?scope=active|all`، `POST`، `PUT /{id}`، `DELETE /{id}` (غیرفعال‌سازی منطقی)؛ `{code, name, price}` — کد یکتا per-clinic |
| G3 | PUT `/settings` | `cpms_config` |
| G4 | GET `/audit?filters` | `cpms_audit_read` (Explicit) — **تأیید Admin** |
| G5 | GET `/reports` — کاتالوگ ۱۲ گزارش مجاز Actor (label/missing/scope) | `cpms_report_read` (پیش‌فرض فقط پزشک — ماتریس §3) |
| G5 | GET `/reports/{type}?from&to` — اجرای گزارش. **type ∈** `appointments_today, appointments_week, cancellations, no_shows, walk_ins, visits, avg_waiting, visit_duration, revenue, payment_methods, open_balances, follow_ups_due`. Scope سرور-side (ADR-0026): پزشکِ متصل به Clinician = **OWN** (فیلتر `clinician_id` اجباری — cross-doctor هرگز)؛ Aggregate مطب فقط برای دارنده `cpms_report_read` **بدون** Clinician-Link (اعطای صریح — الگوی حسابدار ماتریس §6). تفکیک Aggregate⊥Detail (D-8): مالی (`revenue/payment_methods/open_balances`) نیاز `cpms_finance_read` و بدون نام بیمار؛ عملیاتیِ دارای نام بیمار نیاز `cpms_patient_read`؛ `follow_ups_due` نیاز `cpms_medical_read` (بدون reason). بازه bounded (`reports.max_range_days`، پیش‌فرض ۳۶۶)؛ Audit `REPORT_READ` | `cpms_report_read` + Cap نوع |
| G5 | GET `/reports/{type}/print?from&to` — نسخه چاپی HTML با **Watermark** (کاربر+زمان+Scope) برای چاپ/PDF مرورگر (PDF سرور = Backlog، پیش‌زمینه F6) | همان `GET /reports/{type}` |
| G5 | POST `/reports/{type}/export` — درخواست CSV **async** (Job `report.export` — performance-baseline §18) → `{job_id, status:"queued"}`؛ Audit `EXPORT` (filters)؛ فایل CSV با BOM + محافظت Formula-Injection در Storage محافظت‌شده (خارج webroot) + اعلان Internal «آماده شد»؛ Retention `reports.export_retention_days` | `cpms_report_read` + Cap نوع + **`cpms_export`** (هیچ‌کس پیش‌فرض) |
| G5 | GET `/reports/exports` — فهرست Exportهای خود Actor (از اعلان‌های `report_export_ready`) | `cpms_report_read` + `cpms_export` |
| G5 | GET `/reports/exports/{id}/download` — دانلود محافظت‌شده: فقط مالک اعلان؛ منقضی → `410 CLINIC_EXPORT_EXPIRED`؛ Audit `EXPORT` | `cpms_report_read` + `cpms_export` + مالکیت |
| G6 | GET `/notifications?unread=&limit=` — Inbox نقش خود (منشی/پزشک → گیرنده WP؛ بیمار متصل → `recipient_patient_id`) + `unread_count`؛ فقط رکوردهای خود Actor (IDOR-safe) | نقش CPMS (staff یا بیمار متصل) |
| G6 | POST `/notifications/read` — `{ids:[..]}` یا `{all:true}` → علامت‌گذاری خوانده‌شده (فقط رکوردهای خود Actor) | نقش CPMS |

## 8. Payload نمونه‌ها

```jsonc
// B2 confirm — Response
{
  "reference_code": "AP-260405-12",
  "appointment_id": 981,
  "slot": {"date":"2026-09-20","time":"10:40","jalali":"1405/06/29","jalali_time":"10:40"},
  "status": "confirmed"
}

// D13 payment — Request
{ "amount": 500000, "method": "cash", "transaction_ref": null }
// Header: Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000
// Response: { "payment_id": 55, "payment_number":"PAY-260905-0001", "invoice":{"status":"paid","balance":0} }

// E8 note — Request
{ "category":"diagnosis","visibility":"doctor_private","content_text":"..." }

// D12 invoice — Request
{ "visit_id": 301, "items":[{"service_id":7,"description":"ویزیت","quantity":1,"unit_price":500000}], "discount": 0, "tax": 0 }
```

## 9. Real-Time Endpoint (V1: Controlled Polling)

| # | Method/Path | توضیح |
|---|---|---|
| R1 | `GET /rt/queue?since={event_id}` | تغییرات جدید صف از آخرین event (Etag-like)؛ منشی 3s، پزشک 5s؛ `ETag`/`304` برای کاهش بار |
| R2 | `GET /rt/notifications?since={id}` | اعلان‌های Internal جدید (badge) — `{notifications[], last_id, unread_count}`؛ `ETag`/`304` (الگوی R1)؛ Rate 60/min؛ منشی/پزشک/بیمار — Inbox خودشان (F8) |

> Transport Layer (ADR-0007): کلاینت فقط یک `Transport` interface دارد؛ تعویض با WebSocket/SSE بعداً بدون تغییر BUI.

## 10. کدهای خطای مشترک
`CLINIC_VALIDATION_FAILED`, `CLINIC_PERMISSION_DENIED`, `CLINIC_NOT_FOUND`, `CLINIC_INVALID_TRANSITION`, `CLINIC_POLICY_VIOLATION`, `CLINIC_SLOT_TAKEN`, `CLINIC_HOLD_EXPIRED`, `CLINIC_DUPLICATE_ACTIVE_VISIT`, `CLINIC_OVERPAYMENT`, `CLINIC_IDEMPOTENCY_REPLAY` (200 + پاسخ قبلی), `CLINIC_FILE_INVALID`, `CLINIC_CONFLICT` (handwriting), `CLINIC_RATE_LIMITED`, `CLINIC_OTP_LOCKED`.

## SMS (F2.5 — ADR-0025 | Capability: `cpms_sms_config` | Nonce اجباری)

| # | Method/Path | Body | توضیح / Response |
|---|---|---|---|
| SM-1 | `GET /sms/status` | — | وضعیت `NOT_CONFIGURED\|CONFIGURED\|VERIFIED\|ERROR` + Provider + Credentials **Mask** (`••••••••abcd`) + last_test + advanced. هیچ Secret. |
| SM-2 | `GET /sms/providers` | — | Registry Adapterها: `{id, label, capabilities{...}, auth_methods[], auth_fields{...}}` — UI بر اساس Capability می‌سازد. |
| SM-3 | `POST /sms/settings` | `{provider, auth_method, credentials{...}, sender, advanced{...}, generic{...}}` | ذخیره تنظیمات. Credential خالی = حفظ قبلی؛ `__CLEAR__` = حذف صریح. خطا: `CLINIC_SMS_PROVIDER_UNKNOWN`, `CLINIC_SMS_AUTH_METHOD_INVALID`, `CLINIC_SMS_ENDPOINT_INVALID`, `CLINIC_SSRF_BLOCKED`. |
| SM-4 | `POST /sms/test-connection` | `{provider?, auth_method?, credentials?}` | تست اتصال: `{ok, message}` — Technical در OpLog (بدون Secret). RateLimit: 10/hr. |
| SM-5 | `POST /sms/test-send` | `{mobile, message}` | پیامک آزمایشی: `{status: SENT\|RETRYING\|FAILED, provider_msg_id, message}`. RateLimit: 10/hr. |
| SM-6 | `GET /sms/templates` | — | رویدادها + متغیرهای مجاز/الزامی + Template IDهای فعلی + وضعیت Validation. |
| SM-7 | `POST /sms/templates` | `{event, template_id}` | ذخیره Template (Validation: `CLINIC_SMS_TEMPLATE_NOT_SUPPORTED` اگر Provider پشتیبانی نکند). |
| SM-8 | `POST /sms/templates/test` | `{event, mobile, vars{}}` | Preview + ارسال به شماره تست: `{status, preview, provider_msg_id}`. RateLimit: 20/hr. |
| SM-9 | `GET /sms/logs?status=&page=&per_page=` | — | Log عملیاتی: موبایل **Mask**، بدون OTP خام/Credential. با Pagination. |
| SM-10 | `GET /sms/balance` | — | `{balance, currency}` یا `null` (اگر Provider پشتیبانی نکند). |

> **Security:** Nonce (CSRF) روی همه؛ Capability `cpms_sms_config`؛ Generic API: SSRF Guard + بدون Code/eval + Timeout اجباری (ADR-0025).
