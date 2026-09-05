# ENGINEERING BASELINE REVIEW

تاریخ: 2026-09-05 | سند مرجع: `docs/engineering-baseline.md` (v1.0) | تهیه: تیم مهندسی

> طبق §48: **هیچ کدی به‌خاطر این سند تغییر نکرده است.** فقط ثبت سند + Cross-check + این گزارش.

---

## Current Phase

**F2/F2.5 کامل و تأییدشده** (F2: تصمیمات D1–D4 + هسته OTP؛ F2.5: SMS Provider-Agnostic، Tag v1.0.1).
**تصمیمات باز این Review توسط کارفرما نهایی شدند (2026-09-05)** → سندهای مرجع: ADR-0019 / ADR-0020 / ADR-0021 + `docs/performance/performance-baseline.md` + بخش 1 `docs/backup/backup-recovery.md`.
فاز بعدی: **F3 (Booking)** با الزامات جدید (§Recommended Next Action).

---

## Compatible Requirements (سازگار — بدون تغییر لازم)

| § | موضوع | وضعیت فعلی پروژه |
|---|---|---|
| 1 | Principles | رعایت‌شده: Validation سمت Server، Authorization Backend، PHI در `cpms_*` (نه `wp_posts`) |
| 2 (بخش بزرگی) | Performance/Jobs | عملیات سنگین = Job (F1: JobQueue + Dispatcher)، **System Cron** (ADR-0016)، Polling کنترل‌شده بر نقش (ADR-0007)، Settings در جدول اختصاصی (نه `wp_options` autoload)، Pagination در API Contract، صفر Dependency خارجی |
| 3 | Security by Design | REST + Capability + Ownership (P-1/P-5/P-8)؛ Prepared Statement (`CpmsDb`)؛ Rate Limit (OTP + عمومی)؛ **CSRF**: Authtick با Cookie در WP-REST به‌صورت پیش‌فرض `X-WP-Nonce` الزامی است → عملی است، باید مستند شود |
| 4 (به‌جز Licensing) | Access Control | Matrix v1.1: 45 Capability نام‌افکنی‌شده، بدون Capability کلی، Admin بدون Medical ضمنی، Private Note سه‌لایه |
| 5 | Medical Data Security | Audit Hash-Chain، Soft Delete، بدون Hard Delete عمومی؛ (F6: Controlled Download — قانون ثبت شد) |
| 6 | DB Security | Prepared Queries، UNIQUE (slot claim، idempotency keys)، Transaction (slot claim atomic)، RateLimiter، Idempotency |
| 7 (قاعده) | File Security | طراحی F6: MIME/Extension/Size/Signature + کنترل دسترسی؛ (Malware Scan = قابلیت آینده) |
| 8 | OTP | F2 کامل: TTL/Cooldown/MaxAttempts/RateLimit/Abuse(locks)؛ کد خام هرگز ذخیره/لاگ نمی‌شود؛ Secure Generation (`random_int`) |
| 9 | Secrets | SMS Key فقط Env؛ repository بدون Secret (`.gitignore` + بررسی شد) |
| 22 (بخش) | Update/SemVer | Version در Header (1.0.0)؛ CI با PHP 8.1–8.4 + **phpstan level 6 (gate)** |
| 23 (بخش) | Migrations | Runner نسخه‌دار + Logged (`cpms_schema_migrations`) + Testable (MigrationTest) |
| 24 | Backward Compatibility | تغییرات D3/D4 (F2) مستند، بدون اثر Retroactive |
| 35 | Concurrency | Slot Claim Atomic (SlotClaimTest)؛ زیرساخت IdempotencyKey (F1) — دفع Double-Click در F5 |
| 36 | Telemetry | V1: بدون Telemetry (قاعده ثبت شد) |
| 39 | Dependencies | صفر Dependency خارجی (autoload داخلی) |
| 40 (بخش) | WP Compatibility | Header: **WP ≥ 6.4، PHP ≥ 8.1** (CI matrix 8.1–8.4) |
| 41 | Uninstall Safety | `uninstall.php` با گارد — بدون Wipe پیش‌فرض |
| 42 | Settings | D3/D4: بدون Hard-Code، بدون Retroactive (Snapshot نوبت) |
| 44 (بخش) | Documentation | `/docs` با سازه مطابق (گپ‌ها در زیر) |
| 45 | Support Safety | بدون Backdoor/Master Password (اصل ADR-0001) |
| 31/32 | Handwriting/OCR | Settings آماده (F2: `hw.*`)؛ جریان OCR = «پیشنهاد + تأیید پزشک» در SRS |

**نتیجه: ~25 بند به‌طور کامل یا جزئی با معماری فعلی سازگار است.**

---

## Conflicts (تناقضهای واقعی — نیاز به تصمیم)

### C1 — Naming Error Codeها
- Baseline (§27): کدهای Stable با پیشوند `CLINIC_` (نمونه: `CLINIC_AUTH_FORBIDDEN`).
- API Contract تأییدشده: کدهای بدون پیشوند (`SLOT_TAKEN`, `DUPLICATE_APPOINTMENT`, `HOLD_EXPIRED`, `MOBILE_INVALID`, `OTP_LOCKED`, …) — همین کدها در کد F2 هستند.
- **Risk:** ناسازگاری نام‌گذاری در تمام سطح API؛ چون محصولی Release نشده، هزینه سازگاری ≈ صفر.
- **پیشنهاد:** پذیرش یکپارچه `CLINIC_*` (نمونه: `CLINIC_OTP_LOCKED`, `CLINIC_SLOT_TAKEN`). اعمال در Contract + کد F2 در ابتدای F3 (تغییر کوچک، بدون شکست سازگاری). → **ADR-0019**
- ✅ **تصمیم نهایی کارفرما (2026-09-05):** پذیرش — پیشوند ثابت `CLINIC_*` برای **تمام** کدهای فعلی و آینده؛ بدون Legacy Mapping (رِلیز عمومی وجود ندارد)؛ اعمال در Contract + Docs + Tests + کد F2/F2.5 **در ابتدای F3**. **ADR-0019 ثبت شد (تأیید نهایی).**

### C2 — لایه Repository
- Baseline (§25): تفکیک لایه‌ها شامل **Repositories**؛ «Database Queryها در سراسر پروژه پراکنده نشوند».
- وضعیت F1/F2: سرویس‌های Application مستقیماً از `CpmsDb` Query می‌کنند (پترن ساده‌سازی‌شده F1؛ تعداد Queryها محدود و متمرکز در سرویس‌ها).
- **Risk:** با رشد F3+ (Booking/Queue/Finance) پراکندگی Queryها بالا می‌رود.
- **پیشنهاد:** از F3 به بعد، کد جدید فقط از Repository بگذرد (`src/Infrastructure/Repository/`: Patient، Appointment، Slot، Invoice، Payment، File، License). کد F1/F2 بدون Refactor می‌ماند (مستثنا، ثبت‌شده). → **ADR-0021** (نه تناقض کارکردی، بلکه تصمیم سازه‌ای)
- ✅ **تصمیم نهایی کارفرما (2026-09-05):** تأیید — Repository از F3 الزامی؛ **Domain-Focused، بدون God Repository**؛ کد سالم F1/F2 صرفاً برای یکدستی Refactor **نمی‌شود** — فقط در صورت بروز مشکل Duplication/Tight Coupling/Testability/Security/Transaction/Concurrency با **Impact Analysis** و محدود. **ADR-0021 ثبت شد (تأیید نهایی).**

### C3 — حوزه Licensing در Access Control
- Baseline (§4): **Licensing** جزو حوزه‌های دسترسی است؛ SRS و Matrix فعلی این حوزه را ندارند (کل زیرساخت Licensing جدید است).
- **Risk:** بدون تصمیم، F3+ بدون «License Gate» ساخته می‌شود و F10 نیاز به Refactor دارد.
- **پیشنهاد:** (1) فاز جدید **F10 — Licensing & Update System** به Roadmap اضافه (پس از F9، قبل از Release). (2) از F3 به بعد، تمام عملیات ساخت (Patient/Appointment/Visit/…) از یک **LicenseGate Seam** بگذرند (Interface؛ پیاده‌سازی پیش‌فرض = ACTIVE) → در F10 بدون Refactor فعال می‌شود. (3) Capabilityهای `cpms_license_read` / `cpms_license_manage` در Matrix v1.2 (فقط اعطای Explicit). → **ADR-0023**
- ✅ **تصمیم کارفرما (2026-09-05):** Grace Period پیش‌فرض **7 روز (Server-Configurable)** نهایی شد — در ADR-0023 (F10) ثبت می‌شود. LicenseGate Seam از F3 الزامی.

---

## Missing Requirements (در SRS/معماری فعلی نیست — باید طراحی شوند)

| # | مورد | منبع | فاز پیشنهادی | تصمیم کارفرما؟ |
|---|---|---|---|---|
| M1 | **2FA برای Doctor/Privileged Accounts** — فعلاً منشی/پزشک با Password وردپرس وارد می‌شوند | §8 | F5 | ✅ **نهایی: TOTP (RFC 6238)** — Policy بر پایه **Access** (هر کاربر با دسترسی Medical/Sensitive: PHI/مالی حساس/Export/Audit/تنظیمات حساس — فعلی: Doctor + Secretary؛ آینده: خودکار)؛ Recovery Codes یک‌بارمصرف امن؛ **SMS-OTP روش Recovery پیش‌فرض نیست**؛ ماژول SMS بیمار مستقل؛ Secret/Recovery خام Log/ذخیره نمی‌شود؛ Reset/Disable = Permission + Audit → **ADR-0020** |
| M2 | **Licensing System کامل** — Key، Activation، Domain Binding، Max Activations، States، Grace، Signed Response | §10–21 | **F10 (جدید)** | ✅ **نهایی: Grace Period = 7 روز (Server-Configurable)** — ثبت در ADR-0023 |
| M3 | **Update System امن** — Signed Package، Compatibility Check، بدون Replace دستی | §22 | F10 | بخشی از ADR-0024 |
| M4 | **Backup/DR** — DB + Attachments + Handwriting + Config؛ Restore مصور + تست‌شده؛ **RPO/RTO** | §29 | F9 (مستند) + عملیات | ✅ **نهایی: RPO ≤ 6h، RTO ≤ 8h** (مبنای Production)؛ ارتقا به RPO ≤ 1h **بدون بازطراحی** → `docs/backup/backup-recovery.md` §1 |
| M5 | **Performance Baseline** + Regression — مقادیر هدف (p95 API، تأثیر Page Load) + بنچمارک | §2 | تعریف: ابتدای F3؛ بنچمارک: F9 | ✅ **نهایی: REST p95 < 300ms** (Core/تعاملی)؛ Overhead صفحه عمومی **p95 < 100ms**؛ عملیات سنگین (OCR/SMS/Export/PDF/Report) **خارج → async/Queue**؛ Benchmark باید محیط/حجم داده/Cache/هم‌زمانی را صریح کند → `docs/performance/performance-baseline.md` |
| M6 | **Activation Requirement Check + Fail Gracefully** | §40 | F3 (کوچک) | ✖ (فنی) |
| M7 | **Migration Protocol حساس** — Preflight + Backup + Failure Recovery | §23 | ADR-0022 + F9 | ✖ (فنی) |
| M8 | **Diagnostic Page کامل** — WP/PHP/DB/Cron/Queue/Storage/SMS/OCR/License/Backup (بدون افشای PHI) | §28 | F9 + F10 | ✖ (F1 بخشی ساخته: Health + Queue) |
| M9 | **File Security کامل + Malware Scan Hook** | §7 | F6 | ✖ (فنی) |
| M10 | **Log Correlation ID** + سطح CRITICAL + Context استاندارد | §26 | F3 (ارزان) | ✖ (فنی) |
| M11 | **CI بسط** — `composer audit` + Secret Scan + PHPCS (Coding Standards) | §38 | F9 | ✖ (فنی؛ phpstan + tests فعلاً موجودند) |
| M12 | **Source Control** — Git اجباری | §37 | **الان (انجام شد — فقط فرآیند)** | ✖ |
| M13 | **Purge Data (اختیاری)** — Explicit/Privileged/Multi-step/Audited | §41 | F9 | ✖ |

---

## Required ADRs

| ADR | موضوع | وضعیت |
|---|---|---|
| **ADR-0019** | Naming Convention Error Code (`CLINIC_*`) + جداسازی Message کاربر/فنی | ✅ **ثبت شد — تأیید نهایی کارفرما 2026-09-05** |
| **ADR-0020** | 2FA برای حساب‌های Privileged: TOTP + Policy بر پایه Access | ✅ **ثبت شد — تأیید نهایی کارفرما 2026-09-05** |
| **ADR-0021** | adoption لایه Repository از F3 (تقریبی برای کد موجود) | ✅ **ثبت شد — تأیید نهایی کارفرما 2026-09-05** |
| **ADR-0022** | پروتکل Migration حساس: Preflight/Backup/Recovery | قبل از Migration‌های حساس آینده |
| **ADR-0023** | معماری Licensing: State Machine (6 حالت)، امضای **Ed25519** (Server=Private، Plugin=Public)، Offline Grace (Server Down ≠ Invalid)، LicenseGate در Application، ممنوعیت عملیات مخرب (قاعده سفت §21) | فاز F10 |
| **ADR-0024** | Update Channel امن (Signed Package + SemVer + Compatibility Check) | فاز F10 |
| — | State Machine جدید: `docs/state-machines/license-state.md` (بر اساس الزام State Machines پروژه) | همراه ADR-0023 |

---

## Security Impact

- **افزودنی (نه شکسته):** Licensing (مدیریت کلیدها، امضا، بدون Backdoor — §21 قاعده سفت)، 2FA، Diagnostic (بدون افشای PHI)، مستندسازی CSRF/Nonce.
- **مهم:** هرگز Medical Data به License Server نمی‌رود (فقط Minimum Metadata: Installation ID، Domain، Version، Status).
- **بدون تأثیر:** داده‌های F1/F2 (هیچ تغییر Schema/Access در این دوره).
- **Session Security:** کوک‌های نشست با Flag امن (Secure/HttpOnly)؛ Session برای OTP در F2 ایجاد شد — بررسی Flagها در F9 (Hardening).

## Performance Impact

- License Check: **Cache با TTL**، بدون Query به Server در هر Page Load (§12) — در طراحی F10 الزام است.
- در این دوره **بدهی Performance جدید ایجاد نشده است**.
- Performance Baseline (M5) باید قبل از F3 تعریف شود تا Featureهای جدید با معیار بسنجند.

## Database Impact

- **این دوره: بدون Migration** (هیچ تغییر Schema).
- Jداول `cpms_license` (+ `cpms_license_events`) در **Migration 0003** (فاز F10) — طرح: Installation ID، Key Hash، Domain، State، ExpiresAt، GraceEndAt، LastVerifiedAt، Signature.
- از F3: Queryهای جدید فقط از Repository (C2).

## Licensing Impact

- **فاز جدید F10** (Licensing & Update System) به Roadmap اضافه می‌شود — ترتیب F1–F9 بدون تغییر (مطابق §47).
- **اثر روی F3 (فوری):** LicenseGate Seam در سرویس‌های ساخت — بدون آن F10 نیاز به Refactor خواهد داشت.
- سیاست Expired (§14–20): **Read-only روی داده‌های موجود + بلاکِ عملیات جدید** (Patient/Appointment/Walk-in/Visit مستقل/Public Booking) + **تکمیل Workflow نوبت‌های قبلی** + **تکمیل Visit در حال اجرا** + **Export مجاز** + Reactivation بدون دستکاری داده. این سیاست به‌صورت قاعده در SRS (بخش جدید) و Matrix ثبت می‌شود.

## Testing Impact

افزودن به Test Plan (فازهای مناسب):

| تست اجباری (§34) | فاز |
|---|---|
| Patient A نمی‌تواند Patient B را بخواند/بازنویسی کند (IDOR) | F3/F5 (TP-07 موجود، بسط) |
| Secretary/Patient نمی‌توانند Doctor Private Note را بخوانند | F5 (TP-08) |
| Unauthenticated نمی‌تواند Medical Attachment را دریافت کند | F6 (TP-15) |
| Unauthorized نمی‌تواند Export کند | F6 (TP-06) |
| Expired License: ساخت Patient/Appointment ممنوع + خواندن مجاز + تکمیل نوبت قبلی + تکمیل Visit جاری + Server Outage ≠ Lock | **F10** |
| Payment Idempotency / Slot Concurrency | F5 (زیرساخت F1 موجود) |
| Migration/Upgrade Tests | F9 |
| Performance Regression (بنچمارک) | F9 (Baseline از M5) |

---

## Recommended Next Action

1. ✅ **انجام شد (فقط فرآیند، بدون تغییر کد):** ثبت Baseline (`docs/engineering-baseline.md`) + **Git init + Commit + Tag v1.0.0** + `CHANGELOG.md` (مطابق §37).
2. ✅ **تصمیمات کارفرما — نهایی‌شده (2026-09-05):** C1 (`CLINIC_*`، بدون Legacy Mapping، اعمال در ابتدای F3)؛ C2 (Repository Domain-Focused از F3، Refactor F1/F2 فقط با Impact Analysis در صورت مشکل)؛ M1 (TOTP + Access-based + Recovery Codes امن + بدون SMS-OTP به‌عنوان Recovery پیش‌فرض)؛ M2 (Grace 7 روز Server-Configurable)؛ M4 (RPO ≤ 6h / RTO ≤ 8h + مسیر ارتقا)؛ M5 (REST p95 < 300ms / Overhead < 100ms / عملیات سنگین async + روش Benchmark).
3. ✅ پس از تأیید: ثبت **ADR-0019/0020/0021** + `performance-baseline.md` + به‌روزرسانی `backup-recovery.md` (انجام شد 2026-09-05). باقی‌مانده در F3: به‌روزرسانی API Contract (کدهای `CLINIC_*`) + SRS v2.1 (بخش 2FA/Licensing/Backup/Performance) + Matrix (Capabilityهای license در F10).
4. **شروع F3** با چهار الزام جدید: **LicenseGate Seam** + **Repository** + **کدهای CLINIC_*** + **Correlation ID در Log**.
5. به‌روزرسانی Roadmap: **F10 — Licensing & Update System** + بسط F9 (Hardening: CI کامل، Diagnostic، Backup، Performance Benchmark، Purge).

---

## پیوست: وضعیت Source Control (M12 — انجام‌شده)

- `git init` در ریشه workspace + Commit اولیه (تمام docs + plugin) + **Tag `v1.0.0`**
- `clinic-practice-management/CHANGELOG.md` ایجاد شد (فرمت Keep-a-Changelog)
- هیچ فایل کدی تغییر نکرده — فقط ثبت نسخه.
