# راهنمای ادامه پروژه CPMS برای Agentها

> این فایل **دفترچه تحویل پروژه، راهنمای عملیاتی Agentها و لاگ تغییرات** است.
> هر Agent باید قبل از شروع کار آن را بخواند و در پایان، اقدامات خود را در بخش لاگ ثبت کند.
>
> **قاعده مهم:** ورودی‌های ثبت‌شده در بخش «لاگ اقدامات Agentها» append-only هستند. نوشته‌های قبلی را حذف یا بازنویسی نکنید؛ اگر اطلاعاتی قدیمی یا اشتباه است، یک اصلاحیه با تاریخ جدید اضافه کنید.

---

## 1. مشخصات پروژه

- نام: Clinic Practice Management System (CPMS)
- نوع: افزونه اختصاصی WordPress برای مدیریت مطب و پرونده الکترونیک بیمار
- ریشه Repository: `clinic-practice-management/`
- مستندات: `docs/`
- حداقل PHP: `8.1`
- حداقل WordPress: `6.4`
- وضعیت Git هنگام ایجاد این فایل: شاخه `arena/01a07281-doctor`
- تاریخ وضعیت پایه: `2026-09-05`
- وضعیت کلان: F1، F2 و F2.5 انجام‌شده؛ F3 در حال تکمیل؛ F4 تا F9 و F10 باقی‌مانده

### ترتیب مرجع فازها

```text
F0 مستندات و طراحی
→ F1 Core و Migrations
→ F2 OTP و احراز هویت بیمار
→ F2.5 ماژول Provider-Agnostic SMS
→ F3 Booking
→ F4 Visit / Queue
→ F5 Clinical
→ F6 Finance
→ F7 Handwriting
→ F8 Notifications / Reports
→ F9 Hardening / Pilot / Go-Live
→ F10 Licensing / Secure Update
```

> F10 برای Licensing و Update System به‌عنوان فاز جدید به Roadmap اضافه شده و باید قبل از انتشار تجاری انجام شود.

---

## 2. قوانین کاری اجباری برای Agentها

### قبل از هر تغییر

1. این فایل و README مرتبط را بخوانید:
   - `AGENTS.md`
   - `clinic-practice-management/README.md`
   - مستندات فاز مربوط در `docs/`
   - `docs/engineering-baseline.md`
   - تصمیمات نهایی در `docs/decisions/`
2. وضعیت Repository را بررسی کنید:

   ```bash
   git status --short --branch
   git log --oneline -5
   ```

3. مشخص کنید تغییر مربوط به کدام فاز، نیازمندی، ADR و تست است.
4. اگر بین اسناد تعارض وجود دارد، ابتدا آن را گزارش و Impact Analysis تهیه کنید؛ تصمیم بالادستی را خودسرانه تغییر ندهید.
5. قبل از پیاده‌سازی، اثر تغییر بر امنیت، داده پزشکی، دسترسی، تراکنش، هم‌زمانی، Performance، Migration، Backup، License و تست را مشخص کنید.

### هنگام توسعه

- داده پزشکی در جدول‌های اختصاصی `cpms_*` نگهداری شود، نه `wp_posts`.
- منطق کسب‌وکار داخل Template یا Controller قرار نگیرد.
- کد جدید از لایه Repository مناسب استفاده کند؛ Repositoryها Domain-Focused باشند و God Repository ساخته نشود.
- تمام Queryها از `CpmsDb` و با Prepared Statement اجرا شوند.
- عملیات حساس از Transaction، Lock، Unique Constraint و Idempotency مناسب استفاده کنند.
- تغییر وضعیت‌ها فقط از State Machine و با History/Audit لازم انجام شود.
- Authorization باید Ownership/Resource Access و Field Access را هم بررسی کند؛ Capability به‌تنهایی کافی نیست.
- هیچ OTP، Token، Secret، Password، License Key کامل یا Credential در Log، Exception، تست و Response افشا نشود.
- Error Codeهای جدید و اصلاح‌شده با پیشوند `CLINIC_*` باشند و در `docs/api/error-codes.md` ثبت شوند.
- عملیات سنگین مانند SMS، OCR، Export، PDF و Report در مسیر تعاملی به‌صورت synchronous پیاده نشوند؛ از Queue/Job استفاده شود.
- تغییر Schema فقط با Migration نسخه‌دار، تست‌شده و failure-aware انجام شود.
- Deactivate/Uninstall/License Expiration نباید به‌صورت پیش‌فرض داده پزشکی را حذف کند.
- بدون تأیید و نیاز واقعی Dependency جدید اضافه نشود.
- هیچ Secret واقعی، داده بیمار واقعی، فایل تولیدی بزرگ یا Credential به Git اضافه نشود.

### بعد از هر تغییر

1. تست‌های مربوط به تغییر را اجرا کنید.
2. در صورت امکان Unit، Integration، Syntax و Static Analysis را اجرا کنید.
3. `git diff --check` و `git status` را بررسی کنید.
4. مستندات، Changelog و این فایل را به‌روزرسانی کنید.
5. در لاگ پایین، خلاصه دقیق اقدام، فایل‌ها، تست‌ها، ریسک‌ها و ادامه کار را ثبت کنید.
6. اگر کاری انجام نشده، ادعای انجام‌شدن آن را ثبت نکنید.

---

## 3. وضعیت فعلی پروژه در زمان ایجاد این فایل

### انجام‌شده یا موجود

#### F1 — Core و زیرساخت

- Skeleton و Autoloader افزونه
- Migration Runner و Migrationهای Schema اولیه
- Settings و Roles/Capabilities
- Audit Log با Hash Chain و Masking
- Operational Log
- Rate Limiter
- Idempotency
- Database Job Queue و Dispatcher
- State Machineهای Appointment، Visit، Invoice و Payment
- Slot Generator و Atomic Slot Claim
- Health Endpoint و CLI
- CI پایه و تست‌های Unit/Integration

#### F2 — OTP و Authentication

- OTP Request/Verify
- Hash-only OTP با TTL، Cooldown، Attempt Limit و Lockout
- Rate Limit روزانه، ساعتی و IP
- ساخت User و Link بیمار
- Login Session
- Audit امن و جلوگیری از ثبت کد خام

#### F2.5 — SMS

- `SmsService` و Provider Interface/Registry
- Provider توسعه‌ای Log و Generic API
- Credential Vault با AES-256-GCM
- Template/Variable Mapping و Preview/Test
- SSRF Guard
- SMS Queue، Retry، Dedupe و Statusها
- SMS REST/Admin Settings
- Integration با OTP
- Migration `2026_09_05_0003_sms_messages`

#### F3 — بخش‌های موجود در کد

- `BookingService` و `BookingController`
- Patient Service و Patient/Appointment/Slot Repositoryها
- Availability و Quote
- Hold/Confirm/Resume
- Cancel/Reschedule
- Staff Booking
- Idempotency و Double-Booking Protection
- Duration Snapshot
- LicenseGate فعلی از نوع `ActiveLicenseGate`
- تست‌های Booking، Slot Claim، Idempotency و Patient Flow

### وضعیت تست ثبت‌شده در مستندات

- F1: `122 tests / 189 assertions`
- F2: `157 tests / 232 assertions`
- F2.5: `187 tests / 346 assertions`

این اعداد مربوط به گزارش‌های ثبت‌شده هستند و Agent بعدی باید در محیط جاری دوباره تست را اجرا کند؛ صرفاً به این اعداد اکتفا نکند.

---

## 4. کارهای نزدیک و اولویت‌دار

### اولویت P0 — تکمیل F3

1. اجرای کامل تست‌های F3 و رفع شکست‌ها.
2. تکمیل Acceptance Criteria رزرو و سناریوی E2E:
   - Availability
   - OTP
   - Profile
   - Hold
   - Confirm
   - SMS
   - نمایش نوبت
3. تکمیل UI واقعی رزرو، Calendar، Slot Selection، OTP و Profile؛ در Snapshot فعلی Frontend کامل وجود ندارد.
4. تکمیل تست‌های Ownership و IDOR:
   - بیمار A به بیمار B دسترسی نداشته باشد.
   - کاربر نتواند Slot یا Appointment پزشک/کلینیک نامرتبط را انتخاب کند.
5. بررسی همه Error Codeها و ثبت موارد در `docs/api/error-codes.md` با قرارداد `CLINIC_*`.
6. افزودن/تکمیل Correlation ID در REST و Operational Log.
7. اطمینان از اینکه Queryهای جدید F3 از Repositoryهای Domain-Focused عبور می‌کنند.
8. اجرای Performance Baseline اولیه، مخصوصاً هدف REST p95 کمتر از 300ms.
9. بستن رسمی گزارش F3 و ثبت Acceptance Criteria سبز.

### اولویت P1 — فازهای محصول

- F4: Check-in، Walk-in، Queue، Call/Recall/Skip، History، Polling و داشبورد منشی
- F5: Visit، Clinical Notes/Versions، Private Notes، Prescription، Follow-up، Files و داشبورد پزشک
- F6: Services، Invoice، Payment، Adjustment، Refund/Void، Receipt و Checkout
- F7: Canvas دست‌خط، Stroke Storage، Auto-save و Offline Sync با IndexedDB
- F8: Notification Layer، Reminderها، Reports و Export ممیزی‌شده

### اولویت P1 — کیفیت و انتشار

- F9: Security Review، Backup/Restore، Performance Benchmark، Accessibility، CI کامل، Documentation کاربری و Pilot
- تنظیمات Production و سلامت Cron/Queue
- تست واقعی نصب، Upgrade، Migration و Restore

### اولویت P2 — قبل از انتشار تجاری

- F10: Licensing، Activation، Domain Binding، Signed Response با Ed25519، Grace Period، Read-only States و Update System امن
- ADRهای مربوط به Licensing/Update و Migrationهای آن

### خارج از V1

OCR فارسی، TOTP 2FA، Merge UI پیشرفته، پرداخت آنلاین، بیمه، چندشعبه فعال، Lab Integration، Push و Mobile App در V1 نیستند مگر تصمیم رسمی جدید ثبت شود.

---

## 5. منابع اصلی تصمیم‌گیری

در تعارض اسناد، ترتیب مصوب را از این فایل تصمیم‌گیری استفاده کنید:

1. تصمیمات نهایی کارفرما
2. ADRهای Approved در Scope خود
3. Engineering Baseline
4. SRS
5. State Machines و ERD
6. API Contract
7. Settings Reference
8. Implementation

منابع کلیدی:

- `docs/engineering-baseline.md`
- `docs/phase-reports/engineering-baseline-review.md`
- `docs/roadmap/roadmap.md`
- `docs/scope/mvp-scope.md`
- `docs/decisions/2026-09-05-f2-final-decisions.md`
- `docs/decisions/2026-09-05-f3-booking-gaps.md`
- `docs/api/api-contract.md`
- `docs/api/error-codes.md`
- `docs/testing/testing-plan.md`
- `docs/backup/backup-recovery.md`

---

## 6. قالب ثبت اقدام هر Agent

هر Agent باید در پایان کار یک ورودی مانند قالب زیر به انتهای بخش لاگ اضافه کند:

```markdown
### YYYY-MM-DD — Agent: <شناسه یا نام Agent> — فاز: <F#>

- **هدف درخواست:**
- **وضعیت قبل از شروع:**
- **اقدامات انجام‌شده:**
  - `path/to/file`: توضیح تغییر
- **تصمیم‌ها و فرض‌ها:**
- **تست‌های اجراشده:**
  - دستور: `...`
  - نتیجه: سبز/قرمز/اجرا نشد + خلاصه
- **مستندات به‌روزشده:**
- **ریسک‌ها یا موارد باز:**
- **گام پیشنهادی Agent بعدی:**
- **وضعیت Git / Commit:**
```

اگر Agent فقط پروژه را بررسی کرده و تغییری نداده است، همین موضوع را شفاف ثبت کند و بنویسد `Working tree unchanged`.

---

## 7. لاگ اقدامات Agentها

### 2026-09-05 — Agent: Arena Agent — بررسی اولیه و ساخت دفترچه تحویل

- **هدف درخواست:** مطالعه وضعیت پروژه و ایجاد راهنمای پایدار برای Agentهای بعدی.
- **وضعیت قبل از شروع:** Repository شامل مستندات کامل و کد F1/F2/F2.5 و بخش‌هایی از F3 بود؛ F3 در Roadmap در حال انجام ثبت شده است.
- **اقدامات انجام‌شده:**
  - بررسی ساختار کامل Repository، مستندات، کد و تست‌ها.
  - ایجاد این فایل: `AGENTS.md`.
  - ثبت وضعیت فازها، اصول توسعه، کارهای باقی‌مانده و قالب لاگ.
- **تصمیم‌ها و فرض‌ها:** هیچ تصمیم معماری جدیدی گرفته نشد؛ این فایل صرفاً وضعیت موجود و فرآیند تحویل را ثبت می‌کند.
- **تست‌های اجراشده:** تست نرم‌افزار اجرا نشد؛ فقط `git status` و بررسی Repository انجام شد.
- **مستندات به‌روزشده:** `AGENTS.md`.
- **ریسک‌ها یا موارد باز:** وضعیت رسمی F3 باید با اجرای تست‌ها و بررسی Acceptance Criteria نهایی شود؛ اعداد تست موجود در گزارش‌ها باید در محیط جاری راستی‌آزمایی شوند.
- **گام پیشنهادی Agent بعدی:** ابتدا تست‌های F3 را اجرا و یک Gap Analysis واقعی برای Booking، UI، Ownership/IDOR و Error Codeها ثبت کند؛ سپس فقط با Scope تأییدشده ادامه دهد.
- **وضعیت Git / Commit:** فایل `AGENTS.md` اضافه شد؛ Commit/Push انجام نشده است.

---

## 8. چک‌لیست کوتاه پایان کار

- [ ] تغییرات با فاز و سند مرجع سازگار است.
- [ ] Secret یا داده واقعی بیمار اضافه نشده است.
- [ ] تست مناسب اجرا شده یا دلیل اجرا نشدن ثبت شده است.
- [ ] Migration/ADR/API Contract در صورت نیاز به‌روزرسانی شده است.
- [ ] `git diff --check` اجرا شده است.
- [ ] وضعیت Git ثبت شده است.
- [ ] ورودی جدید در لاگ این فایل اضافه شده است.
- [ ] گام بعدی مشخص و قابل اجرا است.
