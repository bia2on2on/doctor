# طراحی احراز هویت و مجوز — CPMS

نسخه 1.0 | 2026-09-05 | وابسته به: SRS §3.1, Permission Matrix

## 1. مدل احراز هویت (Authentication)

### 1.1 بیمار: Mobile + OTP
```
بیمار ──mobile──▶ API ──valid?──▶ SmsGateway ──OTP──▶ SMS
              ◀── {expires_in}
بیمار ──code──▶ API ──hash compare──▶ otp_tokens
              ├─ موفق: Session (WP User) + patient_links
              └─ شکست: attempts++ → قفل (5 → 15 دقیقه)
```

| پارامتر پیش‌فرض | مقدار |
|---|---|
| طول کد | 6 رقم (CSPRNG) |
| Expiration | 300s |
| Max attempts | 5 |
| Resend cooldown | 60s |
| Max code/day | 3 |
| Lockout | 15 دقیقه |
| ذخیره | `SHA-256(code + pepper)` فقط (ADR-0006) |

**Binding Session به Mobile:** `patient_user_links.mobile_at_link` — اگر موبایل بیمار تغییر کند، Flow ارتقا (تأیید شماره جدید) فعال می‌شود.

### 1.2 Doctor/Secretary
- V1: WP Login (username+password، سیاست رمز قوی) + Session.
- V1.5: TOTP 2FA (فیلدها و Flow آماده؛ Setting `2fa.enabled`) — (FR-1.9).

### 1.3 Session
- Cookie: `Secure`, `HttpOnly`, `SameSite=Lax`.
- Site Binding: هدر Origin/Referer در موتانت‌ها؛ تکرار غیرمعمول → Invalid.
- Timeout: 12 ساعت (بیمار)، 12 ساعت (کارکنان) — Setting.
- Re-hash رمز در هر Login (ورودهای WP).

## 2. مدل مجوز (Authorization)

### 2.1 لایه‌بندی (هر Endpoint)
```
1) Authentication  → کاربر کیست؟ (Session/Nonce)
2) Capability      → نقش اجازه کلی دارد؟ (wp_capabilities)
3) Data-Access     → روی این رکورد/این بیمار؟ (Policy: can_access)
4) Field-Access    → فیلدها/سوییسیت (visibility filter در Repository)
5) Action Rules    → شرط کسب‌وکاری (Policy لغو، بازه Void، ...)
```
هر لایه مستقل؛ شکست هرکدام → 403 + Audit (با Rate Limit روی Audit).

### 2.2 Capabilities افزونه (فهرست)
```
cpms_config, cpms_medical_read, cpms_audit_read, cpms_export,
cpms_patient_read/create/update, cpms_appt_read/create/cancel,
cpms_queue_read/checkin/advance/call/checkout,
cpms_note_create/update, cpms_rx_create, cpms_rec_create,
cpms_file_upload/read, cpms_invoice_create/read/adjust/void,
cpms_payment_create/void/refund, cpms_finance_read,
cpms_report_read, cpms_consult_start/complete/reopen, cpms_search
```
نقش‌ها → مجموعه Capability (Permission Matrix §2). Administrator وردپرس: فقط `cpms_config` (+ فنی) — **بدون** `cpms_medical_read/audit_read` تا اعطای صریح.

### 2.3 Policy واحد
```php
interface AccessPolicy {
  public function canAccess(WP_User $u, string $resource, string $action, ?int $patientId): bool;
  public function visibleFields(WP_User $u, string $resource): array; // field filter
  public function visibleRows(WP_User $u, string $table, array $args): array; // query filter (visibility)
}
```
- یک پیاده‌سازی برای V1 (`DefaultAccessPolicy`)؛ قابل جایگزینی (تیم‌های پزشکی V2).
- **Repositoryها خودکار فیلتر می‌کنند:** `ClinicalNoteRepository::forUser($u, $patientId)` → اگر Secretary/Patient: `WHERE visibility='patient_visible'`.

### 2.4 Ownership (Patient)
`can_access(patient, *, *, patientId) ⇔ exists patient_user_links(user, patientId)`.
Endpointهای `/{id}` برای بیمار: ابتدا Ownership → اگر نه: **404** (نه 403؛ افشای وجود) + Audit.

## 3. طراحی OTP (جزئیات امنیتی)

| تهدید | کنترل |
|---|---|
| Brute-force کد | 5 attempts + قفل 15 دقیقه + Rate Limit |
| SMS Bombing | 3 کد/روز + 10/hr + (آینده: CAPTCHA پس از N تلاش) |
| نشت کد در Log | فقط `code_hash` در جدول؛ Operational Log بدون کد (FR-21.3) |
| Timing attack | `hash_equals` (constant-time) |
| کد نایاب | CSPRNG `random_int` |
| OTP منقضی/استفاده‌شده | `expires_at` + `consumed_at` (تک‌بار) |
| تغییر موبایل | Flow ارتقا با تأیید شماره جدید + Audit |

## 4. CSRF / Replay / IDOR
- **CSRF:** Nonce (`X-WP-Nonce`) روی همه POST/PUT/DELETE — Nonce به Session bound.
- **Replay مالی:** `Idempotency-Key` (مطلب 7)؛ **Replay رزرو:** Hold token تک‌بار (converted).
- **IDOR:** Data-Access + 404-as-403 + Audit attempt (TP-07).
- **Mass Assignment:** فقط Whitelist فیلدها در Repository (هر PUT).

## 5. Secret Management
| Secret | مکان |
|---|---|
| App Key/Pepper (OTP hash) | `wp-config.php`/Env — خارج Git |
| SMS API Key | `wp-config`/Env |
| OCR API Key (V1.5) | `wp-config`/Env |
| Backup Key | خارج سرور (کایند/فایل امن) |

## 6. Auditing احراز هویت
`LOGIN_SUCCESS`, `LOGIN_FAILED`, `OTP_REQUEST`, `OTP_SENT`, `OTP_VERIFY_FAIL`, `CLINIC_OTP_LOCKED`, `LOGOUT`, `FORBIDDEN_ACCESS_ATTEMPT`, `PERMISSION_GRANT`, `PERMISSION_REVOKE` — همه با Actor/Session/Request Context (بیشتر در Audit، جزئیات نشتی در Operational).
