# HANDOFF — Phase 1A → Agent بعدی

**سند canonical تحویل.** نسخه ۱ · ثبت‌شده در پایان Phase 1A · وضعیت مرجع: **`9bc6f7faa5706c3b24004808747d6194209623e0`**

> ⚠️ **این سند یک ادعا است، نه یک اثبات.** هر بند قابل راستی‌آزمایی از خود مخزن است. Agent بعدی موظف است پیش از هر تغییر، بند **I** را اجرا کند و هیچ عددی را کورکورانه نپذیرد.

---

## A. وضعیت مخزن

| قلم | مقدار |
|---|---|
| Remote | `https://github.com/bia2on2on/doctor.git` |
| Branch کاری | `arena/01a0808c-doctor` |
| **HEAD محلی** | `28b20274ad58d1a6c1b61f3141e78b43f39a37be` |
| **HEAD ریموت** (`origin/arena/01a0808c-doctor`) | `28b20274ad58d1a6c1b61f3141e78b43f39a37be` — **یکسان** |
| **HEAD تأییدشدهٔ Phase 1A (کد)** | **`9bc6f7faa5706c3b24004808747d6194209623e0`** — تأیید مالک روی همین SHA |
| فاصلهٔ `9bc6f7f` تا HEAD | یک commit، **فقط مستندات** (`28b2027` = همین سند تحویل). هیچ کد/تست/workflow/migration تغییر نکرد |
| **`origin/main` HEAD** | `8087b42e19a1721e38eb073aa1a17aff1cbac97b` |
| ahead / behind نسبت به `main` | **ahead 16 / behind 0** |
| Tags | **۰** — هیچ tag ای ساخته نشد |
| PR | **[#10](https://github.com/bia2on2on/doctor/pull/10)** — `OPEN` + **`DRAFT`** · merge نشد · close نشد |

> Clone محلی **shallow** است (`.git/shallow` = `8087b42`). **`git fetch --unshallow` ممنوع است.** برای تاریخچه از `gh api repos/bia2on2on/doctor/commits` (فقط‌خواندنی) استفاده کن.

### فایل‌های untracked که نباید لمس شوند

| مسیر | وضعیت الزامی |
|---|---|
| `docs/commercial-gap-audit.md` | untracked بماند · محتوا تغییر نکند · **هرگز** commit نشود · sha256 = `893ec3ff14c1b5ddbe0108a4bc1fe52f96768a5d788307b6eb54a3524fd51004` · ۹۱۷۳۰ بایت |
| `.download/` | untracked بماند · حذف نشود · ignore نشود |

> ⚠️ **`git add -A docs` دو بار این فایل را وارد index کرده است.** همیشه اسناد را با **مسیر صریح** stage کن.
> ⚠️ **`git reset --hard` / `git clean -fd` / `git checkout .` / `git restore .` ممنوع‌اند.**

### آخرین اجراهای CI/Gate روی `9bc6f7f`

| Workflow | Run ID | نتیجه |
|---|---|---|
| CI (PHPStan · Unit 8.1/8.2/8.3/8.4 · Integration WP 6.7 + MySQL 8) | `34273491315` | ✅ success (۶/۶ job) |
| Pilot/Staging Readiness Gate | `34273485824` | ✅ success (۴/۴ job) |
| Closure Gate (GO-LIVE evidence closure) | `34273485816` | ✅ success |
| Real WordPress Acceptance — `push` | `34273486084` | ✅ success |
| Real WordPress Acceptance — `pull_request` | `34273491347` | ✅ success |

### اجراهای Gate روی `28b2027` (commit فقط-مستندات)

هیچ‌کدام از workflowها path filter ندارند، پس یک تغییر صرفاً مستنداتی هم کل مجموعه را trigger می‌کند. نتیجه ثبت شد:

| Workflow | Run ID | نتیجه |
|---|---|---|
| CI | `34275626527` | ✅ success (۶/۶ job) |
| Pilot/Staging Readiness Gate | `34275623751` | ✅ success (۴/۴ job) |
| Closure Gate | `34275623757` | ✅ success |
| Real-WP Acceptance — `push` | `34275623949` | ✅ success |
| Real-WP Acceptance — `pull_request` | `34275626627` | ✅ success |

> `ci.yml` روی `push` به `arena/**` اجرا **نمی‌شود** (triggerها: `push: [main]`, `pull_request`, `workflow_dispatch`). تنها دلیل اجرای CI روی این branch، **باز بودن PR #10** است. اگر PR بسته شود، CI پوشش خود را از دست می‌دهد.
> `workflow_dispatch` در دسترس نیست (`HTTP 403 — Resource not accessible by integration`).

---

## B. مرجع حقیقت پروژه

> **تنها نظام فازبندی معتبر و اجرایی = Owner-approved Roadmap، Phase 0..20** — بخش ۰ سند [`docs/roadmap/roadmap.md`](../roadmap/roadmap.md). این نظام **AUTHORITATIVE** است.

نظام‌های زیر **Legacy / Historical** اند و فقط برای traceability تاریخی نگه داشته شده‌اند. **هیچ ایجنتی حق ندارد آن‌ها را به‌عنوان Roadmap اجرایی تفسیر کند:**

- `F0..F10` (تقسیم‌بندی feature تاریخی)
- `Doc-Phase 1..8` (فازهای *مستندسازی* — «Doc-Phase 2» ≠ «Phase 2»)
- برچسب‌های نسخه‌ای `V1` / `V1.5` / `V2`

اسناد تاریخی **Superseded** علامت خورده‌اند و **هرگز حذف نمی‌شوند**.

---

## C. فازهای تکمیل‌شده

| فاز | وضعیت | مدرک |
|---|---|---|
| **Phase 0** — Git / Project Stabilization | ✅ **CLOSED** | [`report-phase-0-reverification.md`](../phase-reports/report-phase-0-reverification.md) — قیود C-1…C-9 قفل |
| **Phase 0.5** — Target Architecture & Migration Plan | ✅ **CLOSED / APPROVED** (`6500bff`) | [`phase0.5-target-model.md`](../architecture/phase0.5-target-model.md) · [`ADR-0031`](../adr/ADR-0031-organization-clinic-location-scoped-authorization.md) |
| **Phase 1A** — Security Hardening (مستقل از Scope) | ✅ **CLOSED at `9bc6f7f`** — تأیید مالک | [`phase1-current-security-model.md`](../security/phase1-current-security-model.md) |
| **Phase 1B** — Scoped / Object Authorization | 🕒 **DEFERRED** تا Multi-Clinic Scope (وابسته به Phase 2 و Phase 3) | [`phase1b-deferred-register.md`](../security/phase1b-deferred-register.md) |
| **Phase 2** — Multi-Clinic Core | ⛔ **NOT STARTED** — نیازمند Gate صریح مالک | — |

> 🔴 **Phase 1B نباید با معماری موقت `clinic_id = 1` پیاده‌سازی شود.** ساخت bypass موقت ممنوع است.

---

## D. Invariantهای معماری (الزام‌آور)

از [ADR-0031](../adr/ADR-0031-organization-clinic-location-scoped-authorization.md) و [`phase0.5-target-model.md`](../architecture/phase0.5-target-model.md):

### دامنه

1. زنجیرهٔ قطعی: **`Organization → Clinic → Location`**.
2. **Organization اجباری است** — Multi-Clinic/Multi-Location جزو **Core** است، نه افزودنی و نه V2. مطب تک‌پزشکی هم از همین زنجیره استفاده می‌کند (AD-04)؛ «حالت مطب» فقط تطبیق UX است، **هرگز یک مسیر کدی جدا نیست**.
3. هر Clinic دقیقاً به یک Organization تعلق دارد.
4. **هر Clinic حداقل یک Location دارد** (AD-15) — هیچ special-case «بدون Location» وجود ندارد ⇒ `location_id NOT NULL` در `cpms_schedule`, `cpms_schedule_slots`, `cpms_appointments`, `cpms_visits`.
5. **User/Doctor می‌تواند به چند Clinic تعلق داشته باشد** (M:N).

### مجوزدهی

6. **Role یک ویژگی زوج `(User, Clinic)` است**، نه یک ویژگی سراسری کاربر ⇒ هدف: `AuthorizationService::can(userId, cap, ScopeContext)` (AD-06). **در Phase 1A ساخته نشد و نباید زودهنگام ساخته شود.**
7. هیچ bypass بالینی فراگیر برای نقش `administrator` وجود ندارد.
8. Namespace قابلیت سازمان = **`cpms_org_*`** (AD-16) — بدون capability واحد همه‌کاره؛ ماتریس نهایی در **Phase 3**.

### هویت و داده

9. **Patient Identity در سطح Organization** با **immutable internal ID**؛ موبایل نرمال‌شده فقط lookup/verification است و **کلید هویت نیست** (AD-14).
10. **Clinical Patient Record ایزوله در سطح Clinic** — هیچ cross-clinic medical visibility ضمنی وجود ندارد.
11. **Timezone در سطح Location** تعریف می‌شود و مستقل است؛ **UTC مرجع canonical ذخیره‌سازی** است.
12. Migrationها **forward-only** اند.

### قیود سخت کدنویسی

13. ⛔ **هیچ `clinic_id = 1` / `organization_id = 1` / `location_id = 1` جدیدی در کد جدید** (AD-13). *(وضعیت موجود: ۵۴ مورد hardcoded در ۲۳ فایل — بدهی Phase 2، نه مجوز افزودن.)*
14. ⛔ **هیچ فرض ضمنی «کاربر جاری == پزشک»** در کد جدید.
15. ⛔ **`current_user_can('cpms_*')` مستقیم و جدید به‌عنوان معماری تکثیر نشود** — مسیر معتبر `RestBase::requireCap()` است.
16. ✅ **ذخیره‌سازی بالینی فعال باید بیرون از DocumentRoot بماند** — Fail-Closed در `LocalFileStorage::__construct()`؛ مسیر داخل webroot استثنای `CLINIC_STORAGE_INSIDE_WEBROOT` می‌دهد و **هیچ fallback بی‌صدایی** وجود ندارد. مهاجرت مجاز است مسیر legacy را **فقط به‌عنوان مبدأ فقط‌خواندنی** بخواند.
17. ✅ **`verify_mobile` هیچ کاربری provision نمی‌کند** — `PROVISIONING_PURPOSES = [PURPOSE_LOGIN]`؛ شمارهٔ ناشناخته یا بدون پیوند `user_id = 0` می‌گیرد و session صادر نمی‌شود.
18. تحویل فایل بالینی فقط از یک مسیر: `GET /clinic/v1/files/{id}/stream` (احراز هویت + مجوز سطح resource در Service).

---

## E. وضعیت امنیتی

سند مرجع: [`docs/security/phase1-current-security-model.md`](../security/phase1-current-security-model.md)
ثبت موارد معوق: **[`docs/security/phase1b-deferred-register.md`](../security/phase1b-deferred-register.md)** (B-01…B-21 و T-01…T-08)

### دفترچهٔ نقص‌ها

| # | نقص | شدت اولیه | وضعیت |
|---|---|---|---|
| **V-1** | امضای نادرست پارامتر IP ⇒ خطای ۵۰۰ و rate limit غیرفعال | بحرانی | ✅ **FIXED** |
| **V-2** | نبود کامل کنترل bruteforce ورود | بحرانی | ✅ **FIXED** — `login-ip` ۲۰/۹۰۰ + `login-user:{sha256[:32]}` ۱۰/۹۰۰، hook `authenticate` اولویت ۵ |
| **V-3** | سردرگمی Purpose در OTP | بحرانی | ✅ **FIXED** — enum مسیر + `assertPurpose()` + `WHERE mobile AND purpose` |
| **V-4** | Pepper پیش‌فرض درون کد | متوسط | ✅ **FIXED** — HMAC-SHA256 + pepper ۲۵۶ بیتی در `cpms_otp_pepper` + `hash_equals` |
| **V-5** | نبود محدودسازی مسیر (traversal) | متوسط | ✅ **FIXED** |
| **V-6** | صحت‌سنجی بکاپ Fail-Open | متوسط | ✅ **FIXED** — `ok_quick` / `corrupt` (fatal) / `legacy_unverified` (warn, restorable) |
| **V-7** | اتکا به `.htaccess` به‌تنهایی؛ داده بالینی داخل DocumentRoot | متوسط | ✅ **CLOSED at `9bc6f7f`** — بند زیر |
| **V-8** | شکاف Nonce — ۶۷ مورد `__return_true` | بحرانی | ✅ **FIXED** — اکنون **۰** مورد |
| **V-9** | — | — | **وجود ندارد** (شماره استفاده نشده؛ اختراع نکن) |
| **V-10** | گزارش سلامت، `PASS` کاذب می‌داد | متوسط | ✅ **FIXED** — و در همین دور، `filesBase()` که هنوز مسیر قدیمی را گزارش می‌کرد اصلاح شد؛ ریشهٔ بالینی داخل webroot اکنون `FAIL` است |

### V-7 — دلیل بسته شدن

Invariant اثبات‌شده: *«در یک پیکربندی پشتیبانی‌شده، ذخیره‌سازی بالینی فعال نمی‌تواند داخل DocumentRoot باشد و فایل بالینی فقط از مسیر مجوزدار برنامه تحویل می‌شود.»*

- مسیر پیش‌فرض و مسیر فعال هر دو بیرون از DocumentRoot — روی **Apache واقعی** در Pilot Gate آزموده شد.
- `LocalFileStorage::__construct()` هر ریشهٔ داخل webroot را رد می‌کند (مقایسه روی `realpath` ⇒ symlink هم دنبال می‌شود).
- تحویل مجوزدار سالم: ناشناس ۴۰۱/۴۰۳ · کاربر مجاز ۲۰۰.

| وضعیت نصب | ذخیره‌سازی فعال | فایل legacy داخل webroot | دفاع |
|---|---|---|---|
| قبل از مهاجرت | ریشهٔ خصوصی (بیرون) از اولین boot | همه هنوز آنجا | `.htaccess` + `web.config` + راهنمای nginx |
| مهاجرت ناقص | بیرون | زیرمجموعهٔ باقی‌مانده | همان + تلاش idempotent در هر request |
| بعد از مهاجرت | بیرون | هیچ | مسیر بی‌ربط |

**حضور فایل legacy هرگز آن مسیر را به ذخیره‌سازی فعال تبدیل نمی‌کند.** deny سطح سرور فقط Defense in Depth است.

### ریسک‌ها و تصمیم‌های باز

| شناسه | موضوع | شدت | وضعیت |
|---|---|---|---|
| **OD-9** | ریشهٔ بکاپ داخل DocumentRoot فقط `WARNING` می‌گیرد | **متوسط** | ✅ **بسته شد (Session بعدی، تصمیم مالک: گزینهٔ C سخت‌گیرانه + پیاده‌سازی)** — بند F به‌روز شد |
| **OD-6** | رمزنگاری فایل بالینی و بکاپ در حالت سکون | متوسط | ⚠️ باز — تصمیم محصولی (مدیریت کلید/Restore) |
| **OD-5** | چرخش pepper زنجیرهٔ Audit | پایین | ⚠️ باز |
| **OD-3** | ناهماهنگی شمارهٔ نسخه (`1.0.0`) | پایین | ⚠️ باز — **نسخه را یک‌جانبه تغییر نده**؛ `closure-gate.yml:130,460` آن را hard-assert می‌کند |
| **Q6** | Scope جدول `cpms_rate_limits` (سراسری / per-Clinic / دوسطحی) | پایین | ⚠️ باز — مرتبط با Phase 1A/2 |
| **KR-1…KR-5** | ریسک‌های پذیرفته‌شده: overshoot غیر اتمیک `peek()` · سطل جدا برای username/email · DoS قفل ۱۵ دقیقه‌ای هدفمند · خوانا بودن pepper با دسترسی خواندن DB · نبود pepper در بکاپ | پایین | ✅ پذیرفته‌شده — بازسازی بزرگ لازم نیست |
| **B-01…B-21** · **T-01…T-08** | مجوزدهی scoped و تست‌های آن | — | 🕒 معوق به Phase 1B/2/3 — [register](../security/phase1b-deferred-register.md) |

### مواردی که **آسیب‌پذیری نیستند** (دوباره ممیزی نکن)

اعتبارسنجی MIME آپلود سالم است (`finfo_buffer`) · ۲۵/۲۵ `admin_post` دارای nonce + cap، صفر `admin_post_nopriv_`، صفر `wp_ajax` · همهٔ متدهای عمومی `ProtectedBackupStore` `assertSafeId()` را صدا می‌زنند · مصرف OTP اتمیک است · `ReportsController::download()` فقط nonce را چک می‌کند — cap و بررسی گیرنده در `ExportService::download()` است.

---

## F. OD-9 — ✅ بسته شد (تصمیم مالک + پیاده‌سازی در session بعدی)

**وضعیت: CLOSED.** مالک گزینهٔ C سخت‌گیرانه را تصویب کرد: ریشهٔ فعال Fail-Closed (`CLINIC_BACKUP_STORAGE_INSIDE_WEBROOT`)، ریشهٔ داخل webroot فقط مبدأ legacy فقط‌خواندنی، و Safety Backup پیش از Restore فقط به مقصد امن خصوصی. پیاده‌سازی + تست + مستندات: `docs/phase-reports/report-od9-closure.md`. متن تاریخی تصمیم (نگهداری‌شده برای traceability):

**Target:** ذخیره‌سازی بکاپِ **فعال** نیز باید بیرون از DocumentRoot باشد — همان اصل ذخیره‌سازی بالینی. بکاپ حاوی همان PHI است و یک dump کامل پایگاه داده دارد.

**Constraint (اثبات‌شده از کد — راستی‌آزمایی کن، فرض نگیر):**

```
BackupService::restoreApply()          BackupService.php:343
  └─ createBackup('pre-restore-safety-…')
       └─ ProtectedBackupStore::createDir()
            └─ ProtectedBackupStore::ensureGuards()   ← نقطهٔ نوشتن
```

⇒ هر Fail-Closed سمت **نوشتن** (چه در سازنده، چه در `ensureGuards`/`createDir`) دقیقاً در لحظه‌ای که اپراتور می‌خواهد از یک نصب معیوب بازیابی کند، **restore را هم قفل می‌کند**. این یک ریسک دسترس‌پذیری در بدترین زمان ممکن است.

**واقعیت امروز:** پس از OD-7 ریشهٔ **پیش‌فرض** بکاپ هم بیرون از DocumentRoot است. تنها راه بازگرداندن آن به داخل، تنظیم صریح و دستی `backup.storage_path` است؛ آن حالت اکنون `WARNING` می‌گیرد. `ProtectedBackupStore` یک primitive **جدا** است و از `LocalFileStorage` عبور نمی‌کند.

**گزینه‌های ثبت‌شده** (تفصیل در `phase1-current-security-model.md` §۵-۴):

| گزینه | رفتار | هزینه |
|---|---|---|
| A | Fail-Closed کامل در سازندهٔ `ProtectedBackupStore` | فهرست/تأیید/بازیابی نصب‌های دارای مسیر داخل webroot کاملاً بسته می‌شود |
| B | Fail-Closed فقط سمت نوشتن | بکاپ ایمنیِ پیش از restore هم ممنوع ⇒ restore عملاً بسته |
| C | بکاپ جدید ممنوع، ولی بکاپ ایمنیِ داخلی به ریشهٔ خصوصی هدایت شود | restore باز می‌ماند؛ ولی «بکاپ ایمنی کجا نوشته شود» یک تصمیم محصولی است |

> 🔴 **دستور برای Agent بعدی:** OD-9 باید **پیش از شروع Phase 2** تصمیم‌گیری و حداقل طراحی شود. **صرفاً یک fail-closed سطحی اضافه نکن که restore را بشکند.** بدون تأیید صریح مالک روی یکی از گزینه‌ها، تغییری در `ProtectedBackupStore` اعمال نکن.

---

## G. تصمیم‌ها و blockerهای Phase 2

> فقط آنچه در مخزن ثبت شده. **هیچ تصمیم جدیدی اختراع نشده است.**

### تصمیم‌های بسته‌شده (ورودی Phase 2 — دوباره باز نکن)

| # | پرسش | تصمیم |
|---|---|---|
| **Q2** | مدل Patient Identity (P-A / P-B / P-C) | ✅ **P-B — AD-14.** Identity سطح Organization · Clinical Record ایزوله سطح Clinic · immutable internal ID · موبایل فقط lookup، کلید هویت نیست · تغییر موبایل/duplicate/merge/resolution همه explicit و audit‌شده · بدون cross-clinic medical visibility ضمنی |
| **Q8** | آیا هر Clinic حداقل یک Location دارد؟ | ✅ **بله — AD-15.** بدون special-case «بدون Location» ⇒ `location_id NOT NULL` در ۴ جدول عملیاتی |
| **Q10** | Namespace قابلیت سازمان | ✅ **`cpms_org_*` — AD-16.** بدون capability همه‌کاره؛ الگوی `cpms_{resource}_{action}` حفظ می‌شود؛ ماتریس نهایی در Phase 3 |
| OD-1 | نقشه راه رسمی | ✅ بسته — Phase 0..20 |
| OD-7 | انتقال storage به بیرون DocumentRoot | ✅ بسته — گزینهٔ A، پیاده‌سازی‌شده |
| OD-8 | provisioning از `verify_mobile` | ✅ بسته — گزینهٔ الف، پیاده‌سازی‌شده |

### Blockerهای واقعی باقی‌مانده

| # | blocker | مدرک |
|---|---|---|
| **BL-1** | **Gate مالک برای Phase 2 صادر نشده.** هر فاز فقط با تأیید صریح مالک آغاز می‌شود | `roadmap.md` §قانون Gate |
| **BL-2** | ~~OD-9 باز است~~ — ✅ بسته و پیاده‌سازی شد | [`report-od9-closure.md`](../phase-reports/report-od9-closure.md) |
| **BL-3** | **قیود ساختاری schema:** `UNIQUE u_sched_day` · `UNIQUE u_slot` · `cpms_clinicians UNIQUE u_clinician_user(wp_user_id)` — این آخری مستقیماً **AD-05 (پزشک در چند کلینیک) را مسدود می‌کند** | `docs/drift-register.md` |
| **BL-4** | **۵۴ مورد hardcoded `clinic_id = 1` در ۲۳ فایل** + seed `cpms_clinics id=1` (`0001:736`)؛ `clinic_id` در ۲۵ جدول ولی FK فقط روی ۴ | `docs/drift-register.md` §۲ |
| **BL-5** | **D-01…D-21** (Domain/Schema) به Phase 2 محول شده‌اند | `docs/drift-register.md` §۲ |
| **BL-6** | `MobileValidator::normalize()` فقط ایران را پشتیبانی می‌کند؛ TZ پیش‌فرض کلینیک `Asia/Tehran` | baseline |
| **BL-7** | `cpms_role_caps_override` قابلیت‌های پیش‌فرض را **جایگزین** می‌کند (نه merge) — بر طراحی نقش scoped اثر دارد | `RolesAndCapabilities.php:186-190` |

**Baseline پایگاه داده:** ۴۱ جدول · ۳۹ FK · migrationهای `0001`–`0009` · ۵ نقش / ۴۶ capability. PHPStan: level 3، مسیرهای `src` + `bin`، **بدون baseline** ⇒ هر خطای جدید CI را قرمز می‌کند.

---

## H. حقیقت تست — چه چیزی واقعاً اجرا شد

> **هیچ تست اجرا‌نشده‌ای به‌عنوان PASS معرفی نشده است.**

### واقعاً اجرا شد و سبز شد (روی `9bc6f7f`)

| Gate | Run ID | jobهای سبز |
|---|---|---|
| CI | `34273491315` | PHPStan · Unit 8.1 · Unit 8.2 · Unit 8.3 · Unit 8.4 · Integration (WP 6.7 + MySQL 8) |
| Pilot/Staging Readiness Gate | `34273485824` | Release Artifact · Staging Gate (fresh install → restore drill) · Upgrade path (main → RC) · Responsive smoke (Chromium ×۴ viewport) |
| Closure Gate | `34273485816` | ✅ |
| Real-WP Acceptance (push) | `34273486084` | ✅ |
| Real-WP Acceptance (pull_request) | `34273491347` | ✅ |

مرحلهٔ کلیدی امنیتی `Protected files — private storage outside webroot + legacy deny (Apache واقعی)` = **success** و ۴ ادعای مستقل A/B/C/D را روی Apache واقعی اثبات می‌کند؛ بدون `continue-on-error`، بدون نادیده‌گرفتن exit code، بدون skip.

### آنچه **اجرا نشد** یا **قابل اثبات نیست**

- ⛔ **هیچ اجرای محلی تستی وجود ندارد.** در sandbox این‌ها **غایب**اند: `php` (هر نسخه) · `composer` · `docker` · `podman` · `act` · `mysql` · `sqlite3` · `wp-cli` · `vendor/`. `phpunit.phar` هست ولی بدون PHP بی‌فایده است. **⇒ تنها مسیر راستی‌آزمایی، GitHub Actions است.**
- ⛔ **اعداد دقیق PHPUnit برای اجراهای سبز در دسترس نیست.** workflow فقط روی **شکست** لاگ را به‌صورت کامنت در PR منتشر می‌کند؛ `gh run view --log-failed`، `actions/jobs/<id>/logs` و دانلود artifact همگی با خطای Azure blob `EOF` شکست می‌خورند. **عدد جعل نکن.**
- 🕒 تست‌های ایزولاسیون scoped کلینیک (**T-01…T-08**) **نوشته نشده‌اند** و عمداً به Phase 1B/2/3 موکول شده‌اند. **آن‌ها را با `clinic_id = 1` شبیه‌سازی نکن.**

### آمار تست (قابل شمارش از مخزن)

در `9bc6f7f`: **۹۰ فایل تست** (Unit 36 / Integration 54). Phase 1A مجموعاً **۸ فایل / ۷۱ متد** افزود:

`RestPermissionCallbackTest` (8) · `OtpSecurityTest` (16) · `ClientIpTest` (8) · `LoginRateLimitTest` (8) · `LocalFileStoragePathTest` (6) · `BackupSecurityTest` (6) · `PrivateStorageMigrationTest` (10) · **`PrivateStorageConfigurationTest` (9)**.

> نویز `WordPress database error` در اجرای Integration (`SAVEPOINT cpms_sp_0 does not exist`، duplicate-key عمدی، جدول ناموجود، یک `Lock wait timeout` در `VisitConcurrencyTest`) **طبیعی است** و توسط تست‌های سبز انتظار می‌رود — شکست نیست.

---

## I. پروتکل شروع کار Agent بعدی

**قبل از هر تغییری، به همین ترتیب:**

1. **همین سند را کامل بخوان** — `docs/handoff/phase1a-to-next-agent.md`.
2. **Roadmap معتبر را بخوان** — [`docs/roadmap/roadmap.md`](../roadmap/roadmap.md) §۰ (Phase 0..20). `F*` و `Doc-Phase` را به‌عنوان فاز اجرایی تفسیر نکن.
3. **ADR-0031 را بخوان** — [`docs/adr/ADR-0031-organization-clinic-location-scoped-authorization.md`](../adr/ADR-0031-organization-clinic-location-scoped-authorization.md) (AD-01…AD-16).
4. **مدل هدف Phase 0.5 را بخوان** — [`docs/architecture/phase0.5-target-model.md`](../architecture/phase0.5-target-model.md).
5. **ثبت معوقات Phase 1B را بخوان** — [`docs/security/phase1b-deferred-register.md`](../security/phase1b-deferred-register.md) (B-01…B-21، T-01…T-08).
6. **وضعیت git/remote/PR را مستقلاً راستی‌آزمایی کن:**
   ```bash
   git rev-parse HEAD
   gh api repos/bia2on2on/doctor/git/ref/heads/arena/01a0808c-doctor --jq .object.sha
   gh api repos/bia2on2on/doctor/git/ref/heads/main --jq .object.sha
   git status --porcelain
   gh pr view 10 --json state,isDraft,url
   gh run list --branch arena/01a0808c-doctor --limit 10
   ```
7. **هیچ ادعای این handoff را بدون راستی‌آزمایی از خود مخزن نپذیر.** `main` و کد، مرجع نهایی‌اند — نه این سند و نه هیچ گزارش دیگری.

### قیود همیشگی (الزام‌آور)

- گزارش‌ها به **فارسی**؛ نام‌ها/شناسه‌ها/routeها/نمادهای DB طبق قراردادهای موجود.
- **هیچ تصمیم محصولی/امنیتی یک‌جانبه** — از `NEEDS_PRODUCT_DECISION` / `OPEN DECISION` با OPTION A/B و trade-off استفاده کن.
- **هیچ مفهوم دامنه‌ای اختراع نکن** — `MISSING` / `NOT_FOUND` گزارش کن.
- **بدون برآورد تقویمی**، بدون بنچمارک جعلی، بدون ادعای سازگاری/انطباق راستی‌آزمایی‌نشده.
- هر تغییر با **CODE + TEST + DOCUMENTATION** (+ MIGRATION فقط اگر واقعاً لازم باشد). تغییر امنیتی بدون تست رگرسیون **ناقص** است.
- **پایگاه داده فقط‌خواندنی.** بدون نصب/تغییر dependency و بدون تغییر محیط خارج از مخزن.
- **بدون حذف فایل**، بدون refactor پنهان، commitهای کوچک اتمیک و buildable.
- **بازنویسی تاریخچه ممنوع** — مجوز یک‌بارهٔ قبلی مصرف شده است.
- Push فقط به `arena/01a0808c-doctor`. **ممنوع:** push روی `main` · force push · ساخت tag · merge · merge کردن PR · **ساخت workflow جدید** · دور زدن CI برای سبز کردن.
- در صورت شکست Gate: **توقف** و طبقه‌بندی A/B/C/D پیش از هر تغییر. **بدون patch دوم حدسی.**
- **قابلیت‌های مالی Doctor را تغییر نده** (`cpms_payment_void`, `cpms_payment_refund`). branch `arena/01a07d25-doctor` را حذف نکن.

---

## خلاصهٔ یک‌خطی

Phase 1A روی `9bc6f7f` بسته شد (HEAD فعلی `28b2027` = فقط همین سند تحویل)؛ همهٔ Gateها روی هر دو SHA سبز؛ V-1…V-8 و V-10 برطرف، V-7 بسته؛ هیچ Critical/High بازی نمانده. **OD-9 بعداً در session بعدی بسته و پیاده‌سازی شد ([report-od9-closure.md](../phase-reports/report-od9-closure.md)). NEXT SAFE ACTION = Gate صریح مالک برای Phase 2.**
