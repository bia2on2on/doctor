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
| B1 | `POST /booking/hold` | `{clinician_id (الزامی), slot_date, slot_time, slot_id?, patient_id?}` | Hold (TTL 10 دقیقه). **Phase 8 Slice 3:** `patient_id` raw selector — linked-only (`patient_user_links` + trusted Clinic + active). `0` linked→`NULL` (new), `1`→auto-bind, `N>1` بدون `patient_id`→`422 CLINIC_PATIENT_SELECTION_REQUIRED` (pre-capacity), با `patient_id`→authorize vs linked active (`422 CLINIC_VALIDATION_FAILED` اگر unlinked/cross, `400` اگر inactive). Persist `Hold.patient_id` (FK RESTRICT). Response: `{hold_token, expires_at, slot:{...}}`. RateLimit: 10/hr. خطا: `CLINIC_SLOT_TAKEN`, `CLINIC_POLICY_VIOLATION`, `CLINIC_PATIENT_SELECTION_REQUIRED`, `CLINIC_VALIDATION_FAILED`. (GAP-1/G-3: 2026-09-05) |
| B2 | `POST /booking/confirm` | `{hold_token, reason?, first_name?, last_name?}` + `Idempotency-Key` (الزامی) | بازبینی نهایی Slot → Appointment `confirmed`. `Hold.patient_id` frozen إذا non-null (revalidates active/clinic/link, ignore names, immutability); إذا `NULL` historical→compat resolver: `0`→new (requires names, same-mobile collision→`400`), `1`→auto single, `N>1`→`422 CLINIC_PATIENT_SELECTION_REQUIRED` (never accept B2 `patient_id`). Response: `{reference_code, appointment_id, slot:{...jalali}, status}`. خطا: `CLINIC_SLOT_TAKEN`, `CLINIC_HOLD_EXPIRED`, `CLINIC_DUPLICATE_APPOINTMENT`, `CLINIC_PATIENT_SELECTION_REQUIRED`. Replay = پاسخ Origin. (Phase 8 Slice 2: نام‌ها فقط برای بیمارِ «جدید» در hold.clinic_id الزامی می‌شوند — تصمیم سرور؛ بیمارِ موجود نام‌های ارسالی را نادیده می‌گیرد.) |
| B3 | `GET /appointments/mine?from&to` | — | لیست نوبت‌های من (تاریخ/وضعیت) |
| B4 | `POST /appointments/{id}/cancel` | `{reason?}` | در Policy (FR-4.9 — حداقل X ساعت قبل؛ Configurable). خطا: `CLINIC_POLICY_VIOLATION`, `CLINIC_INVALID_TRANSITION` |
| B5 | `POST /appointments/{id}/reschedule` | `{slot_date, slot_time, clinician_id? (اختیاری — Default = پزشک فعلی نوبت)}` + `Idempotency-Key` (الزامی) | مسیر بیمار: در Policy (FR-4.10 — deadline + destination min-lead) + انتقال Hold. Response: `{appointment_id (جدید), reference_code, slot:{...}, previous_appointment_id}`. خطا: `CLINIC_SLOT_TAKEN`, `CLINIC_DUPLICATE_APPOINTMENT`, `CLINIC_POLICY_VIOLATION`. (GAP-1/G-3). Staff uses the same path — see D11b. |
| B6 | `GET /booking/resume?hold_token` | — | ادامه رزرو بعد از قطعی (ER-03). Response: `{hold_token, status: active|converted, expires_at?, slot?}`. خطا: `CLINIC_HOLD_EXPIRED` |

## 3. Patient (Authenticated: patient)

| # | Method/Path | توضیح |
|---|---|---|
| C0 | `GET /patient/my-records` | انتخاب‌گر پرونده‌های خود: در `data` فقط آرایه‌ای از `{link_id, clinic_id, clinic_name, patient_id, patient_display_name, mrn, is_primary}` برای پیوند پایدارِ کاربرِ احرازشده با Patient فعال و Clinicِ هم‌خوان برمی‌گردد؛ هیچ فیلد پروفایل/PHI دیگری ندارد. |
| C1 | `GET /patient/me?link_id?` | پروفایل خود (فیلدهای مجاز). `link_id` فقط selector است؛ `0` پیوند فعال → `404 CLINIC_NOT_FOUND`، `1` → رفتار خودکار سازگار، `N>1` بدون selector → `422 CLINIC_SELECTION_REQUIRED` (بدون fallback primary/اولین). selector خارجی/غیرفعال/ناموجود همگی `404 CLINIC_NOT_FOUND` غیرقابل‌شمارش‌اند. |
| C2 | `PUT /patient/me` | ویرایش فیلدهای مجاز Policy (فرآیند تغییر → Audit) روی همان انتخاب C1؛ `link_id?` selector است و شناسهٔ Clinic/Patient کلاینت authority نیست. تعارض valid national ID فقط در Clinicِ ذخیره‌شدهٔ همان Patient → `400 CLINIC_VALIDATION_FAILED` پیش از mutation/audit. |
| C3 | `POST /patients/{patient_id}/files` | آپلود (multipart) — Validation: MIME/Extension/Size |
| C4 | `GET /patients/{patient_id}/files` | فایل‌های مجاز |
| C5 | `GET /visits?from&to&link_id?` | تاریخچه ویزیت — **فقط فیلدهای patient_visible** |
| C6 | `GET /visits/{id}?link_id?` | جزئیات ویزیت (نماهای مجاز: notes patient_visible, prescription, recommendations, follow-ups) |
| C7 | `GET /prescriptions?link_id?` | نسخه‌های من (مجاز) — **فقط فیلدهای patient_visible و غیر-draft** |
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

### Phase 12 Slice 1 — Staff Portal Finance (read-only)

| Method/Path | Existing read authority | Contract |
|---|---|---|
| `GET /staff/portal/finance/context` | `cpms_finance_read` + `cpms_invoice_read` + `cpms_queue_read`, each via the existing global and same-Clinic authorization layers | Returns only trusted Clinic id and the actor's active/assigned Location choices. Zero eligible Locations returns no choices; one may resolve automatically; multiple require an explicit trusted Location selector. Raw ids never grant authority. |
| `GET /staff/portal/finance/awaiting-payment` | Same three existing read capabilities | Current trusted Clinic + Location, Location-local current operational date, and only `awaiting_payment`. Maximum 100 returned rows (`has_more` signals truncation). Projection: patient and clinician display names, visit date/time and state; active invoice status/totals/paid/remaining/currency when legitimately linked. `invoice: null` means no active invoice; no financial values are inferred. Jalali date is presentation only. No mutation or audit side effect. |

Both routes are GET-only, nonce-authenticated, and mounted in the independent Staff Portal. They do not expose invoice items, payment records, patient identifiers, clinical data, or finance actions.

### Phase 12 Slice 2 — Staff Portal Finance: first invoice issuance (bounded)

Scope: the NORMAL first-invoice workflow for Visits whose current status is exactly `consultation_completed`. The re-issue/recovery path for `awaiting_payment` Visits (including a Visit whose previous invoice was voided) is deliberately **NOT** exposed here.

| Method/Path | Existing authority | Contract |
|---|---|---|
| `GET /staff/portal/finance/invoice-eligible` | `cpms_finance_read` + `cpms_invoice_read` + `cpms_queue_read`, each via the existing global and same-Clinic authorization layers (identical to the Slice 1 board) | Current trusted Clinic + CURRENT trusted operational Location (0 eligible ⇒ fail closed; 1 ⇒ auto-resolution; N>1 ⇒ explicit trusted Location selector required), Location-local operational date, and only `consultation_completed`. Deterministic order (scheduled slot time, else check-in time, then Visit id). Maximum 100 returned rows; `has_more` signals truncation (LIMIT 101). Projection is minimal: `visit_id` (selector only), patient display name, clinician display name, operational date/Jalali date/time, and the status literal. No mobile, national id, clinical, prescription, file, invoice-item or payment data. No cross-Location/global fallback. |
| `POST /staff/portal/finance/visits/{id}/invoice` | `cpms_invoice_create` via the existing global capability check plus the same-Clinic authorization layer; the delegated service re-authorizes identically | Body `{items:[{description, quantity, unit_price}]}` — the smallest commercially useful item composition; invoice-level and item-level `discount`, `tax`, `service_id` are not accepted or forwarded by this portal route. Before delegation the Visit must belong to the trusted Clinic **and** to the CURRENT trusted operational Location **and** be exactly `consultation_completed`; otherwise the route fails closed without disclosing the Visit (404 `CLINIC_NOT_FOUND`), or returns 409 `CLINIC_INVALID_TRANSITION` for a same-Location Visit in another state. On success it delegates to the EXISTING `FinanceService::issueInvoice()` (unchanged) and returns that existing invoice view with **201**; totals remain computed by the existing `InvoiceCalc`/`FinanceService` path. The existing duplicate-active-invoice guard (409 `CLINIC_POLICY_VIOLATION`) and 404 parity are preserved unchanged. The existing backend transition `invoice_ready` (VisitMachine V11: `consultation_completed` → `awaiting_payment`) then moves the Visit out of this list and onto the existing Slice 1 awaiting-payment board. No payment capture/settle/waive/void/refund/adjustment/receipt/online payment/POS device integration is added. |

The Slice 1 `GET /staff/portal/finance/context` response additionally carries the server-derived `can_issue_invoice` flag (existing `cpms_invoice_create` + same-Clinic authority) so the module only renders the issuance panel for an actor who could actually issue; the flag is a UI hint and never authority — the mutation route re-checks.

### Phase 12 Slice 3 — Staff Portal Finance: manual payment capture for an existing invoice (bounded)

Scope: recording **one manual payment** (a partial amount or the exact remaining balance) against an **existing** invoice whose persisted Visit belongs to the CURRENT trusted operational Location of the trusted Clinic — the manual counterpart of the existing D13 capture, entered from the Slice 1 awaiting-payment board. No checkout, waive, refund, void, adjustment, receipt or online-payment work is added, and **no POS/card-terminal device integration of any kind** (no provider/device settings, SDK, discovery, polling or device API).

| Method/Path | Existing authority | Contract |
|---|---|---|
| `POST /staff/portal/finance/invoices/{id}/payments` | `cpms_payment_create` via the existing global capability check plus the same-Clinic authorization layer; the delegated service re-authorizes identically | Header `Idempotency-Key` (canonical UUID, existing `RestBase` validation) is **mandatory**: missing/malformed ⇒ 400 `CLINIC_VALIDATION_FAILED` and nothing is written. Body `{amount, method, transaction_ref?}` — `amount` must be a positive integer Rial value `≤` the server-authoritative invoice balance (over-payment ⇒ 422 `CLINIC_OVERPAYMENT` with `{amount, balance}`; zero / negative / non-integer ⇒ 422 `CLINIC_VALIDATION_FAILED`); `method` is limited **by this portal route** to `cash` \| `card_pos` \| `other` — `online` is deliberately not exposed in the Staff Portal UI and is rejected here with 422 `CLINIC_VALIDATION_FAILED`; `transaction_ref` keeps the existing service contract (optional, `mb_substr(…, 0, 128)`, never an authority field). Client-supplied `clinic_id`/`location_id`/`visit_id`/`patient_id`/`total`/`balance`/`paid_amount`/`status` are not forwarded and can never become authority. **Before delegation** the persisted invoice is loaded server-side and its persisted Visit must belong to the trusted Clinic **and** to the CURRENT trusted operational Location (and, when the invoice carries a Location, it must match the Visit's); foreign Clinic / same-Clinic foreign Location / unknown invoice id fail closed with the established non-enumerating 404 `CLINIC_NOT_FOUND` parity, and an unavailable/foreign Location selector returns the established 403 `CLINIC_SCOPE_UNAVAILABLE`. The mutation delegates to the EXISTING `FinanceService::recordPayment()` — there is no second payment/totals/state engine — so invoice state-machine behaviour (`CLINIC_INVOICE_NOT_MODIFIABLE` 409), the audit action `PAYMENT_CAPTURE`, and the idempotency replay contract are unchanged. Success returns the existing payment result with **201**; a replay of the same key returns the same payment with **200** + `code=CLINIC_IDEMPOTENCY_REPLAY` and **never** creates a second payment. A partial capture leaves the Visit in `awaiting_payment` (with the server-derived reduced balance), so the row stays on the board with the new remaining amount; an **exact** full settlement marks the invoice `paid` and applies the existing `settled` Visit transition (`awaiting_payment` → `paid`), so the row leaves this board. **No checkout is triggered.** No new migration, role, capability, dependency, framework, state machine or finance architecture is introduced. `card_pos` here means only “the payment was taken outside CPMS (e.g. by a card terminal) and is being recorded manually” — it performs and implies no device communication.

The Slice 1 awaiting-payment rows additionally carry the two **selector-only** ids the action needs: `visit_id` and `invoice_id` (`invoice_id` is `null` exactly when the row has no legitimately linked active invoice, in which case no payment action is offered). They are selectors, never authority: the payment route re-derives trusted Clinic + CURRENT Location from the persisted Invoice → Visit and never accepts them as authorization. The Slice 1 `GET /staff/portal/finance/context` response additionally carries the server-derived `can_capture_payment` flag (existing `cpms_payment_create` + same-Clinic authority) under the same rule as `can_issue_invoice`: a UI hint only, never authority — the mutation route re-checks.

### Phase 12 Slice 4 — Staff Portal Finance: checkout for paid Visits at the CURRENT trusted Location (bounded)

Scope: the NORMAL paid checkout workflow only — an actor with the EXISTING `cpms_queue_checkout` authority checks out a Visit whose persisted status is exactly `paid`, i.e. after its invoice has been fully settled; the explicit secretary counterpart of the existing D16 checkout, entered from the new paid/checkout-ready board. The **waive/free checkout path is deliberately NOT exposed** on this Staff Portal surface (the existing D16 backend route keeps its unchanged behaviour, including its documented waiver path, and this slice neither resolves nor modifies that accounting policy), and no refund, void, adjustment, receipt, online-payment or POS/card-terminal integration of any kind is added.

| Method/Path | Existing authority | Contract |
|---|---|---|
| `GET /staff/portal/finance/paid` | `cpms_finance_read` + `cpms_invoice_read` + `cpms_queue_read`, each via the existing global and same-Clinic authorization layers (identical to the Slice 1 board) | Current trusted Clinic + CURRENT trusted operational Location (0 eligible ⇒ empty board, fail closed; 1 ⇒ auto-resolution; N>1 ⇒ explicit trusted Location selector required), Location-local operational date, and only the exact persisted status `paid`. Deterministic order (scheduled slot time, else check-in time, then Visit id). Maximum 100 returned rows; `has_more` signals truncation (LIMIT 101); ONE bounded joined query serves the whole projection (no per-row lookups). Projection is minimal: `visit_id` (selector only), patient display name, clinician display name, operational date/Jalali date/time, `visit_status = "paid"`, and — only when an active (non-voided) invoice is legitimately linked — its settlement summary (`status`/`total`/`paid`/`remaining`/`currency`) used for checkout confirmation; `invoice: null` when there is none, no financial values inferred. No invoice number, payment number, mobile, national id, MRN, `patient_id`, clinical notes, prescriptions, files, private notes or other finance internals. No cross-Location/global fallback. No mutation or audit side effect. |
| `POST /staff/portal/finance/visits/{id}/checkout` | `cpms_queue_checkout` via the existing global capability check plus the same-Clinic authorization layer; the delegated service re-authorizes identically | No request schema: the route reads no body field. The Visit id in the path is a **selector, never authority**: before delegation the PERSISTED Visit is loaded server-side and must belong to the trusted Clinic **and** to the CURRENT trusted operational Location (a valid Location selector may choose among eligible Locations but never creates authority; raw Clinic/Location/Visit values are selectors only); a foreign Clinic, a same-Clinic foreign/unassigned/inactive Location or an unknown Visit id fails closed with the established non-enumerating 404 `CLINIC_NOT_FOUND` parity, and an unavailable/foreign Location selector returns the established 403 `CLINIC_SCOPE_UNAVAILABLE`. The persisted status must be **exactly `paid`**; every other persisted state (`consultation_completed`, `awaiting_payment`, `checked_out`, …) is rejected with 409 `CLINIC_INVALID_TRANSITION` + `visit_status` — the portal never accepts a waive reason and never exposes the `awaiting_payment → waive` path. The mutation delegates to the EXISTING `VisitService::checkout(actor, visitId, null)` (unchanged) — no second checkout engine — so the V14 unsettled-invoice guard (a server-side open/partial invoice balance ⇒ 409 `CLINIC_NOT_SETTLED` with the server-derived `open_invoices`/`balance`, never a client figure), the state-machine terminal behaviour, the `paid → checked_out` transition (V14), `checked_out_at`, `active = 0`, the append-only Visit history/audit (`VISIT_CHECK_OUT`) and the established appointment-completion side effect all remain exactly as the existing D16 route produces them. Client-supplied status/balance/amount/ids are never forwarded and can never become authority. A duplicate checkout fails through the existing terminal-state behaviour (409 `CLINIC_INVALID_TRANSITION`) with **no** duplicate history/audit row. Success returns the existing delegated visit view with **200**. Checkout does NOT mutate invoice/payment amounts or states, create a payment, void/refund/adjust anything, or perform any receipt/POS/online operation. |

The Slice 1 `GET /staff/portal/finance/context` response additionally carries the server-derived `can_check_out` flag (existing `cpms_queue_checkout` + same-Clinic authority) under the same rule as `can_issue_invoice`/`can_capture_payment`: a UI hint only, never authority — the mutation route re-checks. The module renders the paid/checkout-ready panel to module readers; the per-row checkout control is offered only when `can_check_out` is true. Checkout remains an explicit secretary action: a Slice 3 full settlement only moves a Visit onto this board, and nothing checks it out automatically. No new migration, role, capability, dependency, framework, state machine or finance architecture is introduced.

### Phase 12 Slice 5 — Staff Portal Finance: read-only printed receipt for a NORMAL fully settled invoice (bounded)

Scope: a **read-only, GET-only, nonce-authenticated** receipt for the **normal fully settled invoice** of a Visit at the CURRENT trusted operational Location — the independent-Staff-Portal counterpart of the existing back-office D17 JSON receipt, printed through the browser's `window.print()`. It is **not** a new receipt engine: it is a bounded Staff Portal **projection/adapter** over the existing persisted finance data. The existing D17 route (`GET /invoices/{id}/receipt`) and the wp-admin print flow (`SecretaryFinancePage`) are **deliberately unchanged** in this slice, including their existing behaviour where the receipt date is derived from the stored UTC instant and where the MRN is printed. No server-side PDF is generated, no refund/void/adjustment/waive/new-payment/checkout/online-payment work is added, and there is **no POS/card-terminal integration of any kind** (`card_pos` may appear only as the already-recorded manual payment method, never as device communication or proof of integration).

**Proven eligibility contract (derived from durable tables only — no new “clean receipt” state, no schema change):** a receipt is returned **only** when every one of the following is true, evaluated server-side from persisted rows:
- the Visit exists and belongs to the **trusted Clinic** and the **CURRENT trusted operational Location**;
- the persisted **patient row** behind the Visit is re-read through the existing repository (no ad-hoc query) and must be exactly the Visit's `patient_id` with `clinic_id` equal to the trusted Clinic — a missing row, a mismatched id or **another Clinic's patient row** fails closed like any other ownership mismatch, so a Visit→Patient cross-Clinic inconsistency can never render a foreign patient's display name;
- the target invoice and **every** payment row used for it are **durably owned by that Visit** (the persisted Visit is the tenant/Location anchor): `cpms_invoices.visit_id` = the Visit, `cpms_invoices.clinic_id` = the trusted Clinic, `cpms_invoices.patient_id` = the Visit's patient, and `cpms_invoices.location_id` is either that Visit's `location_id` or **NULL**. The **Visit architecture carries a mandatory, backfilled Location** (migration `0013` — NOT NULL + deterministic backfill), so NULL exists on the *finance* columns only: `cpms_invoices.location_id` / `cpms_payments.location_id` remain nullable for **legacy/backward-compatible durable rows** (migration `0015`), and existing finance reads already tolerate that NULL in specific established paths (the Slice 4 paid board joins `location_id = %d OR location_id IS NULL`). Slice 5 therefore honours a NULL finance Location **only as that bounded legacy-compatibility case**, after Clinic/Visit/patient ownership is proven, and requires every non-NULL finance Location to equal the persisted Visit Location exactly; no legacy row is migrated or backfilled by this slice. Each `cpms_payments` row of that invoice carries that same `invoice_id`, the trusted `clinic_id` and the Visit's `patient_id`, with its nullable `location_id` following the same rule. Any ownership/linkage mismatch is **never repaired, never partially rendered and never disclosed**: it fails closed with the established non-enumerating **404 `CLINIC_NOT_FOUND`** parity (indistinguishable from a missing Visit) and returns no receipt data;
- the Visit has exactly **one non-voided invoice** (the target) and **no voided invoice** on the same Visit (a voided invoice = durable correction history);
- that invoice has `status = 'paid'` exactly, is not voided (`void_reason`/`voided_at` empty), and its durable settlement is internally consistent: `total > 0`, `paid_amount = total`, `balance = 0`, and `paid_amount` equals the sum of the rows in `cpms_payments` for that invoice;
- **every** payment row of that invoice is a clean capture: `status = 'captured'` **and** `refunded_amount = 0` **and** no `void_reason`/`voided_at`/`voided_by_wp_user_id` (at least one such payment row is required);
- **no** row exists in `cpms_payment_adjustments` for that invoice (a credit/debit adjustment is durable correction evidence);
- the invoice has at least one line item in `cpms_invoice_items`;
- the Visit's append-only `cpms_visit_status_history` contains **no** waiver: no `awaiting_payment → checked_out` transition (the only transition that leaves `awaiting_payment` for `checked_out` is the existing `waive` event).

Anything else fails closed with **409 `CLINIC_RECEIPT_NOT_ELIGIBLE`** and a bounded, non-enumerating `reason`: `invoice_missing` (no active invoice — the waive/no-invoice path), `invoice_not_settled` (state/balance not fully settled), `correction_evidence` (voided invoice, ambiguous invoice set, non-clean payment row or adjustment row), `settlement_integrity` (durable amounts do not reconcile), `items_missing`, `waive_evidence`. Nothing is written on any rejected path and the correction/refund data itself is **never mutated or erased** by this slice. Durable ownership/linkage mismatches use the 404 parity above instead of the 409 eligibility error, so which relation broke is never enumerated.

| Method/Path | Existing authority | Contract |
|---|---|---|
| `GET /staff/portal/finance/visits/{id}/receipt` | Existing `cpms_invoice_read` via the existing global capability check **plus** the same-Clinic authorization layer (identical to the existing D12b/D17 read authority; the delegated data is the same class of finance data those routes already return) | Read-only receipt of the Visit's **normal fully settled** invoice, as defined by the proven eligibility contract above. The Visit id in the path is a **selector, never authority**: the persisted Visit is loaded server-side and must belong to the trusted Clinic **and** to the CURRENT trusted operational Location; foreign Clinic / same-Clinic foreign / unassigned / inactive / unknown selectors fail closed with the established non-enumerating 404 `CLINIC_NOT_FOUND` parity, and the established Location policy is unchanged (0 eligible ⇒ 403 `CLINIC_SCOPE_UNAVAILABLE` fail-closed; 1 ⇒ auto-resolution; N>1 without a selector ⇒ 400 `CLINIC_SCOPE_REQUIRED`). **Timezone rule:** the authoritative IANA timezone is taken from the current trusted Location row; the stored UTC invoice/payment instants are converted to that Location timezone **first** and only then passed to the repository `Jalali` utility (no UTC date substring, no WP/PHP ambient timezone, no hardcoded `Asia/Tehran`, no browser timezone); if the trusted Location/timezone cannot be established the route fails closed rather than fabricating a date. **Projection (only what the receipt needs):** `clinic {name, address, phone}` (existing display identity), `patient {name}`, `invoice_number`, `invoice_date` (Location-local `Y-m-d`) + `jalali_invoice_date`, `items[{description, quantity, unit_price, amount}]`, `totals{subtotal, discount, tax, total, paid_amount, balance, currency}` as durably stored, and `payments[{payment_number, method, amount, paid_at (Location-local `Y-m-d`), jalali_paid_at}]` for the clean captured payments of this bounded normal path. **Deliberately excluded from the payload and the printed receipt:** MRN, mobile, national ID, clinical notes, prescriptions, files, private notes, transaction references, internal ids (`patient_id`/`visit_id`/`invoice_id`/`payment_id`/`location_id`) and every refund/void/adjustment marker. The route performs **no** POST/mutation and produces **no** audit side effect. The Staff Portal module renders a bounded receipt panel plus a print action; printing uses the browser's `window.print()` with the receipt surface isolated from the portal/theme chrome (Persian/RTL preserved), and **no server-side PDF** is produced. The module's paid/checkout-ready board offers the receipt control (read action) alongside the existing checkout control; the receipt control carries only the Visit selector and never an invoice number or identifier. |

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

### Phase 9 My Visits — C5/C6 record selection

C5 visit rows and the C6 `visit` object preserve Gregorian `visit_date` (`Y-m-d`)
and pair it with `visit_jalali = Jalali::formatYmd(visit_date)` for portal display.

C5/C6 reuse the Profile resolver: optional `link_id` is a selector, never tenant
or patient authority. The authenticated WP user must own a durable link whose
Patient is active and whose persisted Clinic matches the link. Zero eligible
records or a foreign/inactive/nonexistent selector returns `404 CLINIC_NOT_FOUND`;
one eligible record may auto-resolve; multiple records without a selector return
`422 CLINIC_SELECTION_REQUIRED`. No primary/first fallback. Client Clinic/Patient/
Organization/role fields do not authorize access. Existing nonce/session guards,
C5 date defaults and 100-row bound, C6 ownership audit and query-level visibility
remain. Portal display is deliberately restricted to visit date/clinician and
patient-visible note text/recommendation text; internal workflow/actor/correction
metadata is not a display contract. No separate Prescriptions or Files UI.

### Phase 9 My Prescriptions — C7 record selection

C7 preserves Gregorian `created_at` (`Y-m-d H:i:s.000`) and pairs it with
`created_at_jalali = Jalali::formatYmd(local_date)` for portal display, where
`local_date` is converted to the Location's authoritative timezone before
formatting.

C7 reuses the Profile/Visits resolver: optional `link_id` is a selector, never tenant
or patient authority. The authenticated WP user must own a durable link whose
Patient is active and whose persisted Clinic matches the link. Zero eligible
records or a foreign/inactive/nonexistent selector returns `404 CLINIC_NOT_FOUND`;
one eligible record may auto-resolve; multiple records without a selector return
`422 CLINIC_SELECTION_REQUIRED`. No primary/first fallback. Client Clinic/Patient/
Organization/role fields do not authorize access. Query-level `is_patient_visible = 1`
and server-side draft exclusion remain strictly enforced. Portal display is
restricted to prescription number, Jalali date, and item clinical details (generic/brand
name, strength, form, dose, frequency, route, duration, instructions); internal
operational fields (`void_reason`, `is_patient_visible`, `drug_ref_id`,
`correction_of_prescription_id`) are never exposed. Read-only: no refill, mutation,
printing, or pharmacy integration.

### Phase 13 Slice 1 — Doctor Portal structured prescription print

| Method/Path | Existing authority | Contract |
|---|---|---|
| `GET /doctor/portal/visits/{id}/prescriptions/{prescription_id}/print` | Existing Doctor Portal nonce/session + doctor-role + `cpms_rx_read` guard; server-derived active clinician; trusted Clinic and existing trusted-Location 0/1/N policy | Read-only projection for one **finalized** structured prescription whose persisted Visit, Clinic, patient, clinician and trusted active Location all match. Path IDs are selectors only. Missing, draft, voided, cross-Visit, cross-doctor, foreign-Clinic or otherwise mismatched prescription returns the established non-enumerating `404 CLINIC_NOT_FOUND`; unavailable trusted Location/timezone fails closed. `finalized_at` is stored UTC; convert through the persisted Visit Location's valid IANA timezone before returning the local Gregorian datetime and `Jalali::formatYmd(local_date)`. Response contains only `prescription_number`, patient display name, clinician name/specialty, trusted Location name, local finalized date/time, Jalali finalized date, and the structured item display fields (generic/brand/strength/form/dose/frequency/route/duration/instructions). No patient MRN, mobile, national ID, internal IDs, notes, files, audit metadata, or correction fields. No mutation and no audit side effect. The Doctor Portal invokes the browser's `window.print()` with a temporary RTL print surface isolated from portal/theme chrome; no server PDF or wp-admin/theme dependency. |
