# REST API Contract — CPMS

نسخه 1.0 | 2026-09-05 | فاز 5 | Namespace: `POST/GET /wp-json/clinic/v1/...`

## 0. کنواسیون‌ها

| موضوع | قرارداد |
|---|---|
| Auth | Cookie Session وردپرس + هدر `X-WP-Nonce` (CSRF) روی همه موتانت‌ها. Public فقط 4 Endpoint. |
| Format | JSON UTF-8. زمان‌ها ISO-8601 UTC (`2026-09-05T12:30:00.000Z`) + فیلدهای نمایش Jalali جفت (مثلاً `slot_date_jalali`). |
| خطا | `{ "code": "CLINIC_*", "message": "...", "data": {} }` + HTTP مناسب (400 validation, 403 forbidden, 404 not-found-as-forbidden برای Patient, 409 conflict). **تمام کدها با پیشوند ثابت `CLINIC_*`** — فهرست مرکزی: `docs/api/error-codes.md` (ADR-0019). |
| Pagination | `?page=1&per_page=20` → header `X-Total-Count`. |
| Idempotency | هدر `Idempotency-Key: <uuid>` روی Endpointهای `payment`, `booking/confirm`, `handwriting/page` (موتانت‌های حساس). |
| Rate Limit | `X-RateLimit-Limit/Remaining/Reset`. محدودیت‌ها: OTP (10/hr), booking (10/hr), login (20/hr), upload (10/hr). |
| Versioning | `clinic/v1` — شکست‌های بعدی → `v2` (compat window). |
| Security | هر Endpoint: Capability Check + Data-Access (Permission Matrix). 404 برای «داده دیگری» (افشای وجود ندهد) + Audit `FORBIDDEN_ACCESS_ATTEMPT`. |
| CORS | Same-origin فقط؛ Origin خارجی → 403. |

## 1. Public (بدون Auth)

| # | Method/Path | توضیح | Response 200 |
|---|---|---|---|
| A1 | `GET /availability?clinician_id&from&to` | تقویم آزاد (Jalali UI) | `{days:[{date, slots:[{time, capacity_left}]}]}` |
| A2 | `POST /otp/request` | `{mobile}` | `{expires_in:300}`؛ RateLimit: 3/روز، cooldown 60s |
| A3 | `POST /otp/verify` | `{mobile, code}` | `{user_id, patient_links:[{patient_id, mrn, first_name,last_name}], is_new_user}` |
| A4 | `POST /booking/quote` | `{clinician_id, slot_date, slot_time}` — پیش‌بررسی آزاد بودن (بدون Hold) | `{available:bool, capacity_left}` |

> A2/A3: `otp/verify` برای کاربر جدید، اکانت ساخت **نمی‌کند** — فقط هویت موبایل؛ اکانت در گام بعد (A5/A6) ساخته می‌شود تا Profile کامل شود.

## 2. Booking (Authenticated: patient)

| # | Method/Path | Body | توضیح |
|---|---|---|---|
| B1 | `POST /booking/hold` | `{clinician_id (الزامی), slot_date, slot_time}` | Hold (TTL 10 دقیقه). Response: `{hold_token, expires_at, slot:{...}}`. RateLimit: 10/hr. خطا: `CLINIC_SLOT_TAKEN`, `CLINIC_POLICY_VIOLATION`. (GAP-1/G-3: 2026-09-05) |
| B2 | `POST /booking/confirm` | `{hold_token, reason?}` + `Idempotency-Key` (الزامی) | بازبینی نهایی Slot → Appointment `confirmed`. Response: `{reference_code, appointment_id, slot:{...jalali}, status}`. خطا: `CLINIC_SLOT_TAKEN`, `CLINIC_HOLD_EXPIRED`, `CLINIC_DUPLICATE_APPOINTMENT`. Replay = پاسخ Origin. |
| B3 | `GET /appointments/mine?from&to` | — | لیست نوبت‌های من (تاریخ/وضعیت) |
| B4 | `POST /appointments/{id}/cancel` | `{reason?}` | در Policy (FR-4.9 — حداقل X ساعت قبل؛ Configurable). خطا: `CLINIC_POLICY_VIOLATION`, `CLINIC_INVALID_TRANSITION` |
| B5 | `POST /appointments/{id}/reschedule` | `{slot_date, slot_time, clinician_id? (اختیاری — Default = پزشک فعلی نوبت)}` + `Idempotency-Key` (الزامی) | در Policy (FR-4.10) + انتقال Hold. Response: `{appointment_id (جدید), reference_code, slot:{...}, previous_appointment_id}`. خطا: `CLINIC_SLOT_TAKEN`, `CLINIC_DUPLICATE_APPOINTMENT`, `CLINIC_POLICY_VIOLATION`. (GAP-1/G-3) |
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
| D12 | `POST /invoices` | `cpms_invoice_create` | `{visit_id, items:[{service_id?, description, qty, price}], discount?, tax?}` |
| D13 | `POST /invoices/{id}/payments` | `cpms_payment_create` | `{amount, method, transaction_ref?}` + **Idempotency-Key** |
| D14 | `POST /payments/{id}/void` | `cpms_payment_void` | `{reason}` |
| D15 | `POST /invoices/{id}/adjustments` | `cpms_invoice_adjust` | `{type, amount, reason}` |
| D16 | `POST /visits/{id}/checkout` | `cpms_queue_checkout` | `{waive_invoice?: {reason}}` |
| D17 | `GET /invoices/{id}/receipt` | `cpms_invoice_read` | Receipt (PDF) |
| D18 | `GET /finance/summary?from&to` | `cpms_finance_read` | آمار مالی (Revenue, Open Balances, Methods) |

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
| F1 | `POST /handwriting/documents` | `{visit_id, title?, pages:[]}` → Document |
| F2 | `PUT /handwriting/pages/{id}` | `{page_index, stroke_data (gzip+base64), width, height, client_revision}` — `Idempotency-Key`؛ Conflict → `CLINIC_CONFLICT` (دریافت‌نکردن/ادغام) |
| F3 | `GET /handwriting/pages/{id}` | Stroke data + preview |
| F4 | `POST /handwriting/pages/{id}/ocr` | `{provider?}` → OCR Job (V1.5) |
| F5 | `GET /ocr/jobs/{id}` | وضعیت + extracted_text (تا تأیید: `review_status=pending`) |
| F6 | `PUT /ocr/jobs/{id}/review` | `{confirmed_text (ویرایش‌شده), action: confirm\|reject}` |

## 7. Admin/Config (Capability صریح)

| # | Method/Path | Cap |
|---|---|---|
| G1 | CRUD `/config/schedules` | `cpms_config` |
| G2 | CRUD `/config/services` | `cpms_config` |
| G3 | PUT `/settings` | `cpms_config` |
| G4 | GET `/audit?filters` | `cpms_audit_read` (Explicit) — **تأیید Admin** |
| G5 | GET `/reports/{type}?from&to` + `POST /reports/{type}/export` | `cpms_report_read` / `cpms_export` |
| G6 | GET `/notifications` (Internal, منشی/پزشک/بیمار) | نقش خود |

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
| R2 | `GET /rt/notifications` | اعلان‌های Internal جدید (badge) |

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
