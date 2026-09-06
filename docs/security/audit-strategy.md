# استراتژی Audit Log — CPMS

نسخه 1.0 | 2026-09-05 | فاز 7 | جدول: `cpms_audit_logs` (docs/erd)

## 1. اصول
- **A-1** Audit ≠ Operational Log. Audit = رویدادهای **حقوقی/بالینی/مالی** (Who/What/When/Before-After). Operational = خطا/Job/پایش.
- **A-2** Append-only: فقط INSERT در اپلیکیشن؛ هیچ Endpoint حذف/ویرایش ندارد؛ در Production: Trigger `BEFORE UPDATE/DELETE` → raise error (اختیاری ولی توصیه‌شده).
- **A-3** **Hash Chain:** هر رکورد `row_hash = SHA-256(prev_hash + canonical(row))`؛ Job روزانه صحت‌سنجی زنجیر (شکست → Alert). این جعل «ساده» را ناممکن می‌کند (جعل کامل زنجیر نیاز به دسترسی DB دارد — با Backup + Access Control DB کنترل می‌شود).
- **A-4** **ممنوع در Audit/Log:** کد OTP، رمز عبور، Token، Secret، IP خام (فقط HMAC hash).
- **A-5** PHI در Audit به حداقل: `before_json/after_json` فقط فیلدهای تغییرکرده + Masking (مثلاً موبایل `0912***5678`)؛ متن کامل Note **نمی‌رود** در Audit (فقط مرجع id) — متن در خود Note + Version حفظ می‌شود.
- **A-6** هر `FORBIDDEN_ACCESS_ATTEMPT` ثبت می‌شود (Rate-Limited: 5/دقیقه در عمل).

## 2. فهرست رویدادها (Action Codes)

| دسته | Actionها |
|---|---|
| احراز هویت | `LOGIN_SUCCESS`, `LOGIN_FAILED`, `LOGOUT`, `OTP_REQUEST`, `OTP_SENT_OK`, `OTP_SENT_FAIL`, `OTP_VERIFY_OK`, `OTP_VERIFY_FAIL`, `CLINIC_OTP_LOCKED`, `SESSION_INVALIDATED` |
| بیمار | `PATIENT_CREATE`, `PATIENT_UPDATE` (change-set), `PATIENT_ARCHIVE`, `PATIENT_MERGE`, `PHI_READ` (طبق Policy: خواندن پرونده کامل) |
| نوبت | `APPT_CREATE`, `APPT_TRANSITION` (before/after status), `APPT_CANCEL`, `APPT_RESCHEDULE`, `HOLD_CREATE`, `CLINIC_HOLD_EXPIRED`, `NO_SHOW` |
| صف/ویزیت | `VISIT_CHECKIN`, `VISIT_WALKIN`, `VISIT_TRANSITION`, `VISIT_CANCEL`, `VISIT_RECALL`, `VISIT_COMPLETE`, `VISIT_REOPEN`, `VISIT_CHECKOUT` |
| بالینی | `NOTE_CREATED`, `NOTE_UPDATED` (version + change_reason), `MEDICAL_RECORD_VIEWED` (E7), `PRESCRIPTION_CREATED` (Draft), `PRESCRIPTION_FINALIZED`, `PRESCRIPTION_VOIDED`, `RECOMMENDATIONS_CREATED`, `FOLLOW_UP_CREATED`, `CONSULTATION_COMPLETED` (E14), `CONSULTATION_REOPENED` (E15) |
| دست‌خط/OCR | `HW_DOC_CREATE`, `HW_PAGE_SAVE`, `OCR_JOB`, `OCR_CONFIRMED`, `OCR_REJECTED` |
| فایل | `FILE_UPLOADED` (E16/C3), `FILE_READ` (حساس: doctor_private/lab_result — E17), `FILE_SOFT_DELETED` |
| مالی | `INVOICE_CREATE`, `INVOICE_VOID`, `PAYMENT_CAPTURE`, `PAYMENT_VOID`, `PAYMENT_REFUND`, `PAYMENT_ADJUST` — (F6: تغییرات تعرفه‌ها `cpms_services` با اکشن `SETTING_UPDATE` + `resource_type=service` + `meta.op`) |
| دسترسی/سیستم | `PERMISSION_GRANT`, `PERMISSION_REVOKE`, `FORBIDDEN_ACCESS_ATTEMPT`, `SEARCH_EXECUTED` (E18 — q/type/شمار نتایج), `EXPORT` (filters), `SETTING_UPDATE`, `SCHEDULE_UPDATED` |

## 3. ساختار رکورد
```jsonc
{
  "actor": {"wp_user_id": 12, "role": "clinic_secretary", "patient_id": null},
  "action": "PAYMENT_CAPTURE",
  "target": {"type": "payment", "id": 55, "ref": "PAY-260905-0001"},
  "patient_id": 301,
  "context": {"ip_hash": "...", "session_id": "...", "request_id": "req-..."},
  "change": {
    "before": {"invoice.paid_amount": 0, "invoice.balance": 750000},
    "after":  {"invoice.paid_amount": 750000, "invoice.balance": 0}
  },
  "ts": "2026-09-05T09:12:33.482Z",
  "prev_hash": "...", "row_hash": "..."
}
```

## 4. دسترسی به Audit
| نقش | دسترسی |
|---|---|
| Secretary/Doctor/Patient | ❌ |
| WP Admin | ❌ (پیش‌فرض) |
| WP Admin + `cpms_audit_read` (صریح) | ✅ فقط Read + Export (خودش هم Audit می‌شود) |
| سیستم (Job) | Read (صحت‌سنجی Hash Chain) |

## 5. نگهداری و دسترسی
- Retention: حداقل 10 سال (Setting؛ تابع قانون محل — FR-22.4).
- آرشیو: >2 سال → جدول آرشیو/فایل فشرده‌شده (همان Hash Chain).
- Query: فقط از طریق Endpoint مجوزیافته با Filer (بیمار/بازیگر/بازه/Action).
- **تضاد منافع:** Actor نمی‌تواند Audit خود را Edit کند (فقط Read) — V1.

## 6. اتصال با Operational Log
`request_id` مشترک بین دو Log → Trace کامل یک Request (Operational: فنی؛ Audit: حقوقی).
