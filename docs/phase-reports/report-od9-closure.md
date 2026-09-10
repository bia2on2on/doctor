# OD-9 — CLOSURE REPORT (گیت بسته‌شدن)

> **تاریخ:** 2026-09-08 · **Session:** `arena/01a082db-doctor` · **کامیت‌ها:** `3531d5d` (کد+تست+مستندات) + `cd29473` (fix تست)
> **تصمیم مالک (مبنای پیاده‌سازی):** گزینهٔ C سخت‌گیرانه — «Active backup destination باید بیرون از DocumentRoot باشد (Fail-Closed، بدون fallback بی‌صدای ناامن)؛ ریشهٔ داخل webroot فقط مبدأ legacy فقط‌خواندنی است؛ و Safety Backup پیش از Restore فقط به مقصد امن خصوصی هدایت می‌شود تا بازیابی قفل نشود.»
> **مستندات تصمیم:** [`ADR/مدل امنیتی §۵-۴`](../security/phase1-current-security-model.md) · [`drift-register.md`](../drift-register.md) (OD-9 → CLOSED)

---

## ۱. تأیید معیارهای Gate (طبق دستور Session §۵)

| # | معیار بستن OD-9 | وضعیت | مدرک |
|---|---|---|---|
| ۱ | ساخت بکاپ فعال در ریشهٔ داخل webroot **غیرممکن** شود (fail-closed، بدون silent fallback) | ✅ | `ProtectedBackupStore::active()` + `PrivateStorageLocation::assertOutsideWebRoot` (مقایسهٔ `realpath` ⇒ symlink هم دنبال می‌شود) ⇒ `StorageConfigurationException` کد `CLINIC_BACKUP_STORAGE_INSIDE_WEBROOT`. تست‌ها: `testInsideWebRootBackupRootIsRejectedFailClosed` · `testSymlinkIntoWebRootIsRejected` · `testExplicitUnsafeConfigFailsClosedWithoutSideEffects` (هیچ بایت/گاردی در webroot نوشته نمی‌شود) |
| ۲ | **Restore تست‌شده کار کند** و به‌خاطر مرز جدید قفل نشود | ✅ | تست `testRestoreFromLegacySourceRedirectsSafetyBackupToPrivateDestination`: بکاپ از ریشهٔ legacy فقط‌خواندنی → preflight ok → Safety Backup دقیقاً ۱ عدد و فقط در ریشهٔ خصوصی (note `pre-restore-safety-`) → apply تا قبل از DDL ایزولهٔ تست. + **Closure Gate «destructive restoreApply (isolated)» سبز** (run 34283425388) + **Pilot/Staging restore drill سبز** (run 34283425398) روی نصب واقعی WP |
| ۳ | بازیابی/استفاده از **legacy امن** بماند (فقط خواندنی، بدون نوشتن در webroot) | ✅ | `::legacySource()`: `createDir`/`ensureGuards`/`delete` رد؛ `listIds` بدون نوشتن گارد. تست‌ها: `testLegacySourceIsReadOnly` · `testServiceResolvesBackupThatExistsOnlyInLegacyDefaultLocation` (verify ok + preflight با source='legacy' و legacy_unverified صریح) · `testTamperedBackupIsRejected` (دستکاری ⇒ restore_safe=false) |
| ۴ | هر چهار workflow **GREEN** با run-id | ✅ | جدول §۳ (هر ۵ اجرا — شامل Real-WP دوبار) |
| ۵ | **بدون رگرسیون بازِ Critical/High** | ✅ | PHPStan lvl 3 بدون baseline سبز؛ Integration سبز (۴۸۱ تست)؛ قابلیت‌های مالی (`cpms_payment_void`/`cpms_payment_refund`) دست‌نخورده؛ صفر hardcode `clinic_id` جدید (verify با diff) |

## ۲. خلاصهٔ پیاده‌سازی (A–F)

| بخش | تغییر |
|---|---|
| **A — مرز مخزن فعال** | سازندهٔ `ProtectedBackupStore` private؛ `::active($path)` fail-closed (داخل webroot ⇒ `CLINIC_BACKUP_STORAGE_INSIDE_WEBROOT`)؛ `::legacySource($path)` فقط‌خواندنی؛ `assertWritable()` روی عملیات نوشتن؛ `listIds` در حالت readonly بدون نوشتن گارد |
| **B — مبدأ legacy** | `App::backupService()` پیکربندی ناامن (`backup.storage_path` داخل webroot) را **صریحاً** به مبدأ legacy تنزل می‌دهد (مسیر عوض نمی‌شود؛ خواندن برای verification/recovery ادامه دارد؛ نوشتن خطای صریح). کش سرویس برداشته شد (الگوی `localFileStorage`) تا تغییر Setting بلافاصله اثر کند |
| **C — زنجیرهٔ Restore** | `BackupService::resolveSourceStore()`: فعال → ریشهٔ خصوصی پیش‌فرض → ریشهٔ legacy. `safetyDestinationStore()`: Safety Backup فقط به مخزن فعالِ قابل‌نوشتن یا ریشهٔ خصوصی پیش‌فرض — **مبدأ legacy هرگز مقصد نیست**؛ اگر هیچ مقصد امنی نباشد، restore پیش از هر گام مخرب متوقف می‌ماند. `restorePreflight()` اکنون `integrity_warnings`، `legacy_unverified`، `source`، `source_root` را برمی‌گرداند و رویداد `RESTORE_APPLIED` مقصد Safety و مبدأ را Audit می‌کند (بدون PHI) |
| **D — مهاجرت idempotent** | `App::ensurePrivateStorage()` برای پیکربندی داخل webroot: جفت‌های `(ناامن→خصوصی)` با label `cpms-backups-unsafe-config` + `(legacy→خصوصی)` اگر متفاوت باشند؛ `PrivateStorageMigrator`: کپی → تأیید sha256 → rename → تأیید → حذف مبدأ؛ تعارض بدون overwrite؛ گاردهای مبدأ می‌مانند؛ Setting **عمداً** اصلاح نمی‌شود (تصمیم اپراتور) |
| **E — بدون Phase 15** | هیچ scheduling/retention UX/remote mirror ساخته نشد؛ `docs/backup/backup-recovery.md` §۷ صراحتاً خارج از دامنه ثبت کرد |
| **F — تست‌ها** | `tests/Integration/BackupStorageBoundaryTest.php` — ۱۴ متد تست (ماتریس کامل زیر) |

**تست‌های §F (۱۴):** default خارج webroot · safe path پذیرفته/قابل‌نوشتن · inside-webroot رد (کد+path) · symlink رد · downgrade صریح ناامن (readonly + createBackup رد + عارضهٔ صفر) · legacySource فقط‌خواندنی (۳ عملیات رد) · رزولوشن سرویس از legacy (verify+preflight+source) · restore از legacy (Safety فقط در خصوصی، DB دست‌نخورده) · restore با config امن (Safety در مخزن فعال) · tampered رد (بدون Safety) · legacy_unverified صریح · مهاجرت ناامن→خصوصی (بایت‌به‌بایت، حذف مبدأ پس از verify، .htaccess می‌ماند، idempotent، Setting دست‌نخورده) · تعارض بدون overwrite (moved=0/conflict=1) · Health: `storage.backups=FAIL` + `HOST_UNSUPPORTED`.

## ۳. شواهد گیت (run-id)

| Workflow | Event | Run ID | SHA | نتیجه |
|---|---|---|---|---|
| CI (PHPStan + Unit ×4 + Integration WP6.7/MySQL8) | pull_request (#11) | **34283426837** | `cd29473` | ✅ GREEN |
| Real WordPress Acceptance (ZIP → clean WP → browser) | pull_request (#11) | **34283426829** | `cd29473` | ✅ GREEN |
| Real WordPress Acceptance | push | **34283425385** | `cd29473` | ✅ GREEN |
| Closure Gate (GO-LIVE evidence closure — شامل destructive restoreApply) | push | **34283425388** | `cd29473` | ✅ GREEN |
| Pilot/Staging Readiness Gate (شامل Restore Drill) | push | **34283425398** | `cd29473` | ✅ GREEN |

**اجرای میانی `3531d5d`:** CI در run 34282802365 با **یک خطای تست** (نه کد محصول) قرمز شد: `testMigrationConflictNeverOverwritesAndSourceSurvives` — `mkdir(dirname($dst))` فقط والدِ مقصد را می‌ساخت، نه دایرکتوری خود بکاپ ⇒ `file_put_contents` خطا. طبقه‌بندی طبق پروتکل: **D (نقص تست)**. Fix در `cd29473` (یک خط). سایر اجراهای `3531d5d` (Closure 34282781976، Real-WP 34282781873/34282802243) سبز بودند و Pilot 34282781932 با push جدید منسوخ شد.

## ۴. مستندات به‌روزشده (CODE + TEST + DOC همزمان)

- `docs/api/error-codes.md` — ثبت `CLINIC_BACKUP_STORAGE_INSIDE_WEBROOT`
- `docs/security/phase1-current-security-model.md` — §۵-۴ (بسته‌شدن + جدول قواعد/پیاده‌سازی)، V-10 (WARNING→FAIL)، §۸ (پیش‌فرض Setting)
- `docs/backup/backup-recovery.md` — §۷ مرز مقصد بکاپ + Runbook نصب ناامن (۵ گام)
- `docs/drift-register.md` — OD-9 → CLOSED
- `docs/roadmap/roadmap.md` + `docs/handoff/phase1a-to-next-agent.md` — وضعیت‌ها
- `docs/Settings` — کامنت `backup.storage_path`

## ۵. ریسک‌های باقی‌مانده/محدودیت‌های صادقانه

1. `restoreApply` در PHPUnit فقط تا قبل از DDL اجرا می‌شود (ایزوله‌سازی تست)؛ مسیر مخرب کامل توسط Restore Drill سطح OS در Pilot Gate (سبز، run 34283425398) و Closure destructive restoreApply (سبز، run 34283425388) پوشش داده شده است.
2. مهاجرت ریشهٔ ناامن در طول دورهٔ تعارض، در هر request ادمن/REST تلاش مجدد می‌کند (رفتار مستند؛ تعارض‌ها در Operational Log گزارش می‌شوند و بازنویسی نمی‌شوند).
3. `.htaccess` هنوز به‌عنوان Defense-in-Depth نگه داشته می‌شود؛ مرز واقعی = خروج بکاپ از webroot (و توصیهٔ nginx deny در Runbook).
4. Setting ناامن اصلاح خودکار نمی‌شود — عمداً؛ اصلاح آن تصمیم اپراتور است و Health تا اصلاح FAIL می‌ماند.

## ۶. نتیجهٔ گیت

> **OD-9: CLOSED ✅** — هر پنج معیار §۱ برآورده شد؛ هر ۵ اجرای workflow روی `cd29473` سبز است.
> هیچ عملیات merge/tag/release انجام نشده است؛ PR #11 (DRAFT) صرفاً برای اجرای CI باز شده و PR #10 همچنان OPEN + DRAFT است.
> پس از این گزارش، طبق دستور Session §۶: PHASE 2 PRE-IMPLEMENTATION GATE REPORT تهیه شد و session متوقف می‌شود.
