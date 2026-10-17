# ENGINEERING, SECURITY, PERFORMANCE, LICENSING & MAINTENANCE BASELINE

**Version: 1.0 | ثبت: 2026-09-05 | منبع: تصمیم نهایی کارفرما**

این سند از این مرحله به بعد Engineering Baseline پروژه است.

تمام تصمیمات معماری، Backend، Frontend، Database، API، Background Jobs، Licensing، Update System و Deployment باید با این الزامات سازگار باشند.

این Requirements فقط برای مرحله نهایی پروژه نیستند و باید از ابتدای Development رعایت شوند.

اگر Requirement جدیدی با این سند تعارض داشت:
1. بدون اعلام تغییر ایجاد نکن.
2. Impact Analysis ارائه کن.
3. Security/Performance/Data Impact را مشخص کن.
4. راهکار پیشنهادی ارائه کن.
5. در صورت تغییر تصمیم معماری، ADR ثبت کن.

---

## 1. PRINCIPLES

اولویت‌های پروژه:

1. Patient Safety & Data Integrity
2. Security & Privacy
3. Availability
4. Performance
5. Maintainability
6. Auditability
7. Upgradeability
8. Usability
9. Licensing Protection

هیچ تصمیم Licensing، UI یا Business نباید باعث به خطر افتادن Medical Data یا Data Integrity شود.

Client-side validation هرگز جایگزین Server-side validation نیست.

UI Permission هرگز جایگزین Backend Authorization نیست.

هیچ داده مهمی صرفاً به دلیل خطای شبکه، License Server یا Third-party Service نباید از بین برود.

## 2. PERFORMANCE

پلاگین نباید باعث کند شدن محسوس WordPress یا صفحات عمومی سایت شود.

Performance یک Requirement اصلی و قابل تست است.

الزامات:

- از Queryهای غیرضروری جلوگیری شود.
- از N+1 Queries جلوگیری شود.
- Queryهای پرتکرار Index مناسب داشته باشند.
- Queryهای مهم با EXPLAIN/Profiling قابل بررسی باشند.
- Pagination برای لیست‌های بزرگ اجباری باشد.
- هیچ Patient/Visit/Appointment List نامحدود Load نشود.
- APIها فقط اطلاعات موردنیاز را برگردانند.
- Assets پلاگین فقط در صفحات موردنیاز Load شوند.
- CSS/JS مربوط به Doctor Dashboard در کل سایت Load نشود.
- Admin Assets نیز فقط در صفحات مرتبط Load شوند.
- از Autoload کردن Options حجیم جلوگیری شود.
- Log، Stroke Data، OCR Data یا Medical Data حجیم داخل wp_options ذخیره نشوند.
- WordPress Heartbeat/Polling بدون کنترل استفاده نشود.
- Real-time functionality دارای Interval منطقی یا Push Architecture مناسب باشد.
- Search با Index مناسب طراحی شود.
- Dashboard Queryها Optimize شوند.
- Cache با دقت و Privacy-aware استفاده شود.
- Cache هر User/Patient نباید باعث Data Leakage به User دیگر شود.

عملیات سنگین باید Background Job باشند، از جمله:

- OCR
- Handwriting Recognition
- SMS
- Notifications
- Reminder
- Email
- PDF Generation
- Large Export
- Report Generation
- Image Processing
- Maintenance

این عملیات نباید Request اصلی User را Block کنند.

Background Jobs باید:

- Idempotent
- Retryable
- Observable
- Failure Trackable
- Concurrency Safe

باشند.

برای Production از System Cron/Server Cron برای Runner استفاده شود و Workflowهای حیاتی صرفاً به Traffic سایت و WP-Cron وابسته نباشند.

Performance Baseline تعریف شود و Featureهای مهم از نظر Performance Regression بررسی شوند.

## 3. SECURITY BY DESIGN

Security باید از ابتدای Development اعمال شود.

تمام Endpointها و Business Operations حساس باید:

- Authentication
- Authorization
- Capability Check
- Resource-Level Authorization
- Ownership Check where applicable
- Input Validation
- Sanitization
- Context-aware Output Escaping
- CSRF Protection where applicable
- Rate Limiting where required

داشته باشند.

هیچ Client Request قابل اعتماد فرض نشود.

تغییر URL، ID، Query Parameter، JSON Body یا Frontend State نباید باعث دور زدن Permission شود.

## 4. ACCESS CONTROL

Least Privilege اعمال شود.

Capabilityهای Resource-Specific استفاده شوند.

از یک Capability کلی برای کل سیستم استفاده نشود.

حوزه‌های دسترسی حداقل شامل:

- Patient
- Appointment
- Visit
- Queue
- Clinical Record
- Private Clinical Note
- Prescription
- Medical Attachment
- Invoice
- Payment
- Report
- Export
- Audit
- Settings
- Licensing

باشند.

قواعد قطعی:

Patient A cannot access Patient B.

Secretary cannot access Doctor Private Notes.

Patient cannot access Doctor Private Notes.

Unauthenticated User cannot access Medical Files.

Administrator WordPress صرفاً به دلیل Administrator بودن نباید Medical Access ضمنی داشته باشد.

Medical Permission باید Explicit باشد.

Authorization نهایی همیشه Backend-side انجام شود.

## 5. MEDICAL DATA SECURITY

Medical Data باید Sensitive Data محسوب شود.

- Medical Attachments نباید Public URL آزاد داشته باشد.
- Download باید Authorization داشته باشد.
- Medical Records نباید در Logهای عمومی نوشته شوند.
- API فقط Minimum Required Data را ارسال کند.
- Data Exposure براساس Role محدود شود.
- Export داده پزشکی Permission مستقل داشته باشد.
- Export Audit شود.
- اطلاعات مهم پزشکی Silent Overwrite نشوند.
- Version/History مناسب حفظ شود.
- Correctionهای مهم قابل Trace باشند.
- Hard Delete عمومی برای Medical Data وجود نداشته باشد.

هیچ Third-party Analytics یا Telemetry نباید Medical/Patient Data دریافت کند مگر اینکه صریحاً تأیید شده باشد.

## 6. INPUT / OUTPUT / DATABASE SECURITY

SQL Injection protection الزامی است.

Prepared Query/APIهای امن استفاده شوند.

Validation و Sanitization متناسب با نوع داده انجام شود.

Escaping براساس Context انجام شود.

از Serialization/Unserialization ناامن جلوگیری شود.

Mass Assignment Protection در Endpointهای حساس در نظر گرفته شود.

Database Integrity صرفاً متکی به Application UI نباشد.

در صورت مناسب از:

- UNIQUE
- INDEX
- Transactions
- Idempotency Keys
- Concurrency Control
- Constraints / Logical Integrity

استفاده شود.

## 7. FILE SECURITY

File Uploadها بررسی شوند:

- Authentication
- Authorization
- Allowed MIME
- Allowed Extension
- File Size
- Filename
- File Signature where appropriate

Executable file upload ممنوع باشد.

Medical File Storage Protected باشد.

Direct Public Access در صورت امکان مسدود و فایل از Controlled Download Endpoint ارائه شود.

در معماری امکان Malware Scanning نیز پیش‌بینی شود.

## 8. AUTHENTICATION & OTP

OTP دارای:

- Expiration
- Resend Cooldown
- Maximum Attempts
- Rate Limit
- Abuse Protection
- Secure Generation

باشد.

OTP خام ذخیره نشود.

Password، OTP، Token یا Authentication Secret وارد Log نشود.

برای Doctor/Privileged Accounts معماری قابلیت 2FA داشته باشد.

Session Security جدی گرفته شود.

## 9. SECRETS

Secretها نباید داخل Git Repository قرار گیرند.

شامل:

- SMS API Key
- OCR Credential
- License Server Secret
- External API Secret
- Private Signing Key

Private Signing Key تحت هیچ شرایطی داخل Plugin Package قرار نگیرد.

Environment Configuration از Business Settings تفکیک شود.

## 10. COMMERCIAL LICENSING

پلاگین Commercial است و باید License Management اختصاصی داشته باشد.

هیچ سیستم PHP را نمی‌توان 100% غیرقابل کپی کرد.

هدف:

Prevent/Discourage Unauthorized Use
+
Secure Update Distribution
+
Domain/Installation Activation

باشد.

License System حداقل قابلیت‌های زیر را داشته باشد:

- Unique License Key
- Activation
- Deactivation
- Installation ID
- Domain Binding
- Maximum Activations
- License Expiration
- License Status
- Production/Staging distinction
- Grace Period
- Secure Update Authorization

License Server مستقل از Clinic Data باشد.

Medical Data به License Server ارسال نشود.

فقط Minimum Licensing Metadata ارسال شود.

## 11. LICENSE CRYPTOGRAPHIC DESIGN

در صورت استفاده از Signed License Responses:

License Server:
Private Key

Plugin:
Public Key

داشته باشد.

Private Key هرگز داخل Plugin Source نباشد.

License Response باید Signed باشد تا جعل ساده پاسخ License Server دشوار شود.

از Cryptographic Standard معتبر استفاده شود و Custom Crypto طراحی نشود.

Obfuscation اگر استفاده شود فقط Defense-in-Depth است و Security/Licensing اصلی محسوب نمی‌شود.

## 12. LICENSE CHECK PERFORMANCE

License Server در هر Page Load Query نشود.

License State به شکل امن Cache شود.

TTL مناسب داشته باشد.

License Verification نباید سرعت سایت را کاهش دهد.

قطع اینترنت یا Down شدن License Server نباید مطب را فوراً Lock کند.

قاعده:

License Server Unreachable
!=
Invalid License

## 13. LICENSE STATES

License State Machine حداقل شامل:

ACTIVE
EXPIRING
GRACE_PERIOD
RESTRICTED
SUSPENDED
REVOKED

باشد.

Expiration عادی ابتدا وارد Grace Period شود.

Default Grace Period پیشنهادی:

7 days

این مقدار Server-configurable باشد.

## 13A. OWNER SUPERSESSION — CPMS CLASSIC (2026-10-17)

**Authoritative policy: [ADR-0023 §0](adr/ADR-0023-licensing-protocol.md).**
This explicitly supersedes historical conflicting expiry/grace rules in §§13–16,
§32 acceptance statements, and Phase 16 Slice 1's unlimited ordinary-expiry
exception. Historical seven-day grace remains warning/status metadata only; it
**does not postpone T0** or either window.

- T0 = trusted signed `expires_at`, evaluated with server UTC, not client time.
- **All new bookings stop at T0**, public/staff/REST/direct services alike;
  pre-T0 holds cannot confirm new bookings after T0.
- Existing appointments remain valid and actionable. Ongoing care, essential
  clinical documentation, authorized read/export, backup/restore and legitimate
  finance (invoices, payments, refunds, reconciliation) remain available.
- New patients: new services through **in-person reception only**, before
  **T0 + one Gregorian calendar month**.
- Verified returning Organization patients: new services before **T0 + six
  Gregorian calendar months**. Require explicit verified Organization identity
  and an encounter completed strictly before T0 in one of exactly
  `consultation_completed`, `awaiting_payment`, `paid`, `checked_out`.
  Missing identity/completion proof fails closed for returning classification.
- Windows are half-open, Gregorian UTC, month-end clamped, time-of-day retained;
  Jalali is display-only. No seven-day addition and no 30/180-day approximation.
- Nonessential SMS/reminders and premium handwriting new use stop at T0;
  authentication/security SMS and existing handwritten-record read/export remain.
  Unrelated essential clinical functions must not be arbitrarily disabled;
  commercial add-ons retain their actual entitlement contracts.
- Organization/Clinic/Location privacy, hash-chained audit, normal permissions,
  explicit DENY precedence, and finance ownership are never relaxed by expiry.

## 14. EXPIRED LICENSE POLICY

اصل اصلی:

LICENSE EXPIRATION MUST NEVER DELETE OR DESTROY MEDICAL DATA.

از T0 (بدون تعویق توسط Grace؛ قواعد جاری در §13A):

Existing Medical Data باید باقی بماند.

Doctor/Authorized Users باید طبق Permissionهای عادی امکان مشاهده سوابق قبلی را داشته باشند.

حداقل Read Access به موارد زیر حفظ شود:

- Existing Patients
- Patient Profiles
- Previous Appointments
- Previous Visits
- Medical Records
- Clinical Notes
- Doctor Private Notes for authorized doctors
- Handwritten Documents
- Confirmed OCR Results
- Prescriptions
- Recommendations
- Medical Attachments
- Existing Invoices
- Existing Payments
- Receipts

Permissionهای عادی همچنان اعمال شوند.

Expired License نباید باعث افزایش Access شود.

## 15. RESTRICTIONS AFTER LICENSE EXPIRATION

**Superseded ordinary-expiry rules:** see §13A and ADR-0023 §0. No new booking
at/after T0. New reception services for genuinely new patients end at T0 + one
calendar month; verified returning Organization patients end at T0 + six calendar
months. Existing and ongoing care is preserved. Enforcement is backend-side,
not merely disabled buttons. Other restrictive causes retain their own policy.

## 16. EXISTING PATIENTS AFTER EXPIRATION

Existing authorized records remain readable. Existing patient rows alone do not
prove returning status. The explicit Organization identity and strictly pre-T0
qualifying completed encounter required by §13A determine the six-month window.
No new appointment is allowed at/after T0; existing appointment/ongoing treatment
continuity is not a new independent service and is not cut off at these deadlines.

## 17. PRE-EXISTING APPOINTMENTS

Appointmentهایی که قبل از Expiration ایجاد شده‌اند:

- حذف نشوند.
- Cancel خودکار نشوند.
- قابل مشاهده باقی بمانند.

Policy:

اگر Appointment قبل از پایان License ثبت شده باشد، Workflow همان Appointment اجازه تکمیل داشته باشد.

این عملیات به عنوان New Business Activity محسوب نشود.

بنابراین Patient با Appointment معتبر قبلی بتواند:

Check-In
→ Waiting
→ Consultation
→ Prescription/Recommendations
→ Invoice
→ Payment
→ Checkout

را تکمیل کند.

## 18. IN-PROGRESS VISITS

اگر Visit قبل از Restricted State شروع شده باشد:

Expiration نباید Visit را وسط کار متوقف کند.

Doctor باید بتواند:

- Consultation را کامل کند.
- Notes را ذخیره کند.
- Handwriting را ذخیره کند.
- Prescription را تکمیل کند.
- Recommendations را ثبت کند.
- Invoice را تکمیل کند.

Secretary نیز باید بتواند:

- Existing Payment را دریافت/ثبت کند.
- Checkout را تکمیل کند.

License Expiration نباید باعث Medical/Data Integrity Problem در Workflow جاری شود.

## 19. EXPORT AFTER EXPIRATION

مالک داده نباید به دلیل پایان License در Data Lock-In قرار گیرد.

Export اطلاعات موجود پس از Expiration طبق Policy و Permission سیستم قابل انجام باشد.

Export همچنان:

- Authorized
- Audited
- Privacy Controlled

باشد.

## 20. LICENSE REACTIVATION

پس از Renewal:

RESTRICTED
→ ACTIVE

سیستم بدون دستکاری Medical Data مجدداً فعال شود.

Patientها، Appointmentها، Visits یا History نباید Reset شوند.

Reactivation نباید نیازمند Database Reconstruction باشد.

## 21. LICENSE SERVER SAFETY

License Server هیچ‌گاه نباید قابلیت:

- Delete Medical Data
- Modify Medical Record
- Remote SQL
- Remote PHP Execution
- Remote Shell
- Hidden Admin Creation
- Kill Switch destructive

داشته باشد.

هیچ Backdoor ساخته نشود.

Licensing نباید تبدیل به Remote Code Execution Mechanism شود.

## 22. UPDATE SYSTEM

Plugin Update باید استاندارد، امن و قابل کنترل باشد.

Update System حداقل:

- Semantic Versioning
- Update Metadata
- Changelog
- License Validation
- Signed/Integrity-Verified Package
- Compatibility Check

داشته باشد.

نسخه‌ها:

MAJOR.MINOR.PATCH

استفاده کنند.

Production نباید با Replace دستی فایل‌ها به عنوان روش استاندارد Update شود.

## 23. DATABASE MIGRATIONS

Database Schema باید Version داشته باشد.

هر Schema Change باید Migration داشته باشد.

Migration:

- Versioned
- Deterministic
- Logged
- Testable
- Failure-aware

باشد.

Update افزونه نباید Existing Patient Data را Reset یا حذف کند.

برای Migrationهای حساس:

Preflight
Backup Strategy
Failure Recovery

در نظر گرفته شود.

## 24. BACKWARD COMPATIBILITY

هر تغییر:

- API
- Schema
- Settings
- Business Rule

باید Impact Analysis داشته باشد.

Breaking Change بدون Migration/Deprecation Strategy انجام نشود.

Upgrade از نسخه‌های قبلی در Tests پوشش داده شود.

## 25. MAINTAINABILITY

Spaghetti Architecture ممنوع است.

Separation of Concerns رعایت شود.

ساختار منطقی پروژه حداقل لایه‌های زیر را تفکیک کند:

Domain
Application
Services
Infrastructure
Repositories
REST API
Authorization
Jobs
Notifications
Migrations
Frontend
Admin
Licensing

Business Logic داخل Template قرار نگیرد.

Database Queryها در سراسر پروژه پراکنده نشوند.

Naming Convention ثابت باشد.

Magic Numbers/Stringهای غیرضروری حذف شوند.

برای Architectural Decisionهای مهم ADR نوشته شود.

## 26. DEBUGGABILITY

پلاگین باید قابل عیب‌یابی باشد بدون اینکه برای Debug نیاز به مشاهده بی‌دلیل اطلاعات پزشکی باشد.

Operational Logging استاندارد ایجاد شود.

Log Levels:

DEBUG
INFO
WARNING
ERROR
CRITICAL

در Production، Debug Mode پیش‌فرض خاموش باشد.

Log Context مناسب شامل:

- Request/Correlation ID
- Component
- Job ID
- Error Code
- Timestamp

باشد.

اما موارد زیر هرگز Log نشوند:

- Password
- OTP
- Secret
- Full License Key
- API Token
- Medical Data غیرضروری

## 27. ERROR CODES

خطاهای مهم Stable Error Code داشته باشند.

نمونه:

CLINIC_AUTH_FORBIDDEN
CLINIC_APPOINTMENT_SLOT_CONFLICT
CLINIC_PATIENT_ACCESS_DENIED
CLINIC_PAYMENT_DUPLICATE
CLINIC_OCR_PROVIDER_FAILED
CLINIC_SMS_PROVIDER_FAILED
CLINIC_LICENSE_RESTRICTED
CLINIC_MIGRATION_FAILED

Error Message کاربر و Technical Error Details از هم جدا باشند.

## 28. SYSTEM HEALTH

Diagnostic/System Health Page طراحی شود.

حداقل موارد بررسی شوند:

- Plugin Version
- Database Schema Version
- WordPress Version
- PHP Version
- Database Status
- Migration Status
- Cron Status
- Queue Status
- Failed Jobs
- Storage Status
- SMS Provider
- OCR Provider
- License Status
- Last Successful Backup if available

Diagnostic Report نباید Medical Data یا Secret افشا کند.

## 29. BACKUP & DISASTER RECOVERY

Backup شامل:

- Database
- Medical Attachments
- Host Data
- Required Configuration

باشد.

وجود Backup به تنهایی کافی نیست.

Restore Procedure باید:

- Documented
- Testable
- Periodically Tested

باشد.

Backupهای Sensitive محافظت شوند.

RPO/RTO در Production تعیین شود.

## 30. FAILURE RESILIENCE

خرابی Third-party Service نباید بدون دلیل Workflow اصلی را نابود کند.

SMS Provider Down:
Appointment محفوظ بماند.

OCR Provider Down:
Original Handwriting محفوظ بماند.

License Server Down:
Clinic فوراً Restricted نشود.

Email Down:
Medical Workflow از بین نرود.

Failed Jobها:

- Logged
- Retryable
- Inspectable

باشند.

## 31. HANDWRITING DATA SAFETY

قطع اینترنت هنگام نوشتن با قلم نباید باعث از دست رفتن دست‌خط پزشک شود.

Handwriting Editor باید:

- Auto Save
- Temporary Local Persistence
- Retry
- Sync Status
- Failure Notification

داشته باشد.

Original Handwriting همیشه حفظ شود.

OCR Result جایگزین Original Handwriting نشود.

## 32. OCR SAFETY

OCR/AI output داده پیشنهادی محسوب شود.

Workflow:

Handwriting
→ OCR/AI
→ Suggested Text
→ Doctor Review
→ Edit if required
→ Doctor Confirmation

OCR نباید بدون تأیید Doctor دارو، Dose یا Medical Instruction نهایی تولید کند.

OCR Failure نباید Visit را خراب کند.

## 33. TESTING

Testing باید همراه Development انجام شود، نه فقط در انتهای پروژه.

حداقل:

- Unit Tests
- Integration Tests
- API Tests
- Authorization Tests
- Migration Tests
- Appointment Concurrency Tests
- Payment Idempotency Tests
- File Security Tests
- Licensing Tests
- Expired License Tests
- Upgrade Tests

وجود داشته باشد.

Feature مهم بدون Test مناسب Complete محسوب نشود.

## 34. MANDATORY SECURITY TESTS

حتماً Automated Test وجود داشته باشد برای:

Patient A cannot read Patient B.

Patient A cannot modify Patient B.

Secretary cannot read Doctor Private Note.

Unauthenticated User cannot access Medical Attachment.

Unauthorized User cannot Export Medical Data.

Ordinary expiry: new reception patients/services allowed only before T0 + one Gregorian month (see §13A).

No new appointment at/after signed T0, including confirmation of pre-T0 holds (see §13A).

Expired License can read Existing Authorized Patient Data.

Pre-existing Appointment can complete its Workflow.

In-progress Visit can safely complete after Expiration.

License Server outage does not immediately lock Clinic.

## 35. CONCURRENCY & IDEMPOTENCY

برای عملیات حساس:

- Database Transaction
- Unique Constraint
- Lock where appropriate
- Idempotency

استفاده شود.

Double Click نباید باعث:

- Duplicate Appointment
- Duplicate Payment
- Duplicate Visit
- Duplicate Invoice

شود.

دو بیمار نباید یک Slot تک‌ظرفیتی را همزمان رزرو کنند.

## 36. PRIVACY-SAFE TELEMETRY

در صورت ایجاد Telemetry:

Default باید Privacy-Safe باشد.

هیچ مورد زیر ارسال نشود:

- Patient Name
- National ID
- Mobile
- Diagnosis
- Prescription
- Medical Note
- Handwriting
- Medical Attachment

Telemetry تجاری/فنی از Medical Data کاملاً جدا باشد.

## 37. SOURCE CONTROL

Git اجباری است.

Releaseها باید دارای:

- Version
- Git Tag
- Changelog
- Build Artifact

باشند.

هیچ Hotfix بدون ثبت در Version Control روی Production باقی نماند.

## 38. CI / QUALITY GATE

قبل از Release حداقل:

- Automated Tests
- Static Analysis (PHPStan L3 در CI — از F1-3/گروه 4؛ ارتقا به L4+ در backlog)
- Coding Standards
- Security Checks
- Migration Tests if needed
- Dependency Vulnerability Check
- Secret Scan
- Changelog
- Version Check

اجرا شوند.

Critical Security Failure باید Release را Block کند.

## 39. DEPENDENCY MANAGEMENT

هر Dependency قبل از اضافه شدن بررسی شود:

- Maintenance Status
- Security History
- License Compatibility
- Size
- Performance Impact
- Necessity

Dependency غیرضروری اضافه نشود.

Versionها مدیریت و Audit شوند.

## 40. WORDPRESS COMPATIBILITY

Minimum Supported Versions برای:

- WordPress
- PHP
- MySQL/MariaDB

تعریف شوند.

Plugin Activation باید Requirement Check داشته باشد.

اگر Environment ناسازگار بود، Fail Gracefully انجام شود و Medical Data آسیب نبیند.

## 41. UNINSTALL SAFETY

Uninstall Plugin نباید به شکل پیش‌فرض Medical Data را حذف کند.

Disable Plugin:
NO DATA DELETION

Uninstall:
NO AUTOMATIC MEDICAL DATA DELETION

اگر امکان Purge Data ساخته شود:

- Explicit
- Privileged
- Multi-step confirmation
- Backup warning
- Audit where possible

باشد.

هیچ Update/Deactivate/License Expiration نباید Medical Data را حذف کند.

## 42. SETTINGS

Business Settings نباید Hard-Coded باشند.

از جمله:

- Appointment Duration
- Booking Limits
- Cancellation Rules
- Reminder Timing
- OTP Policies
- File Limits
- Working Schedule

مقادیر Default قابل تغییر باشند.

تغییر Default نباید Historical Data را Retroactively تغییر دهد.

مثلاً تغییر Default Appointment Duration نباید Appointmentهای ثبت‌شده قبلی را تغییر دهد.

## 43. PRODUCTION HARDENING

قبل از Go-Live:

- Debug disabled
- Secrets reviewed
- HTTPS enforced
- Security headers reviewed
- File access tested
- Rate limits tested
- Permission tests passed
- Cron verified
- Queue verified
- Backup completed
- Restore tested
- Database migrations verified
- Logging reviewed
- Cache leakage tested
- Update mechanism tested
- License behavior tested
- Performance benchmark executed

شود.

## 44. DOCUMENTATION

Documentation همزمان با Development Update شود.

ساختار پیشنهادی:

```
/docs
    /srs
    /architecture
    /permissions
    /state-machines
    /erd
    /api
    /security
    /threat-model
    /adr
    /performance
    /migrations
    /licensing
    /deployment
    /troubleshooting
    /backup-recovery
    /testing
```

Documentation یک کار انتهای پروژه محسوب نشود.

## 45. SUPPORT SAFETY

هیچ Master Password، Hidden Administrator، Universal Token یا Backdoor ساخته نشود.

اگر Remote Support در آینده اضافه شد باید:

- Explicitly enabled by customer
- Time-limited
- Revocable
- Audited

باشد.

Remote Support نباید به معنی دسترسی مخفی دائمی توسعه‌دهنده باشد.

## 46. FINAL ENGINEERING CHECKLIST

برای هر Feature قبل از Implementation بررسی کن:

1. Performance impact چیست؟
2. چه Database Queryهایی ایجاد می‌کند؟
3. Index لازم دارد؟
4. Security implications چیست؟
5. چه Capability لازم دارد؟
6. Resource-level authorization چیست؟
7. چه Medical Data درگیر است؟
8. آیا داده اضافی Exposure می‌شود؟
9. Failure mode چیست؟
10. آیا Retry لازم است؟
11. Idempotency لازم است؟
12. Audit لازم است؟
13. Migration لازم است؟
14. Backup/Recovery را تحت تأثیر قرار می‌دهد؟
15. License State چه اثری روی آن دارد؟
16. چگونه Test خواهد شد؟
17. چگونه Debug خواهد شد؟
18. آیا Documentation نیاز به Update دارد؟

اگر Feature با Engineering Baseline تعارض دارد:
STOP

و ابتدا:

- Impact Analysis
- Risk Analysis
- Proposed Solution

ارائه کن.

## 47. PHASE GATE

این سند باعث تغییر ترتیب Phaseهای قبلی پروژه نمی‌شود.

تمام Requirements این سند باید در Phase مناسب اعمال شوند.

هیچ‌کدام از این Requirements مجوز شروع زودهنگمام Coding نیست.

همچنین Development Process مصوب پروژه رعایت شود:

SRS
→ Permission Matrix
→ State Machines
→ ERD
→ API Contract
→ Wireframe
→ Security / Threat Model
→ MVP Scope
→ Migration & Core
→ Tests alongside Development
→ UI
→ OCR / Handwriting
→ Production Hardening

در پایان هر Phase بررسی کن که خروجی آن با این Engineering Baseline سازگار باشد.

## 48. REQUIRED ACTION NOW

فعلاً صرفاً به دلیل دریافت این سند شروع به Refactor یا Implementation نکن.

ابتدا:

1. این سند را به عنوان Engineering Baseline v1.0 ثبت کن.
2. آن را با SRS و تصمیمات فعلی پروژه Cross-check کن.
3. Conflictها را شناسایی کن.
4. Missing Requirements را گزارش کن.
5. ADRهای موردنیاز را مشخص کن.
6. تأثیر آن بر Phase فعلی را مشخص کن.
7. هیچ کدی را فقط به دلیل این Prompt تغییر نده مگر Phase فعلی اجازه دهد.

سپس گزارش ENGINEERING BASELINE REVIEW ارائه کن.

بعد از گزارش طبق Phase Gate پروژه ادامه بده.
