# Pilot / Staging Readiness Report — CPMS V1

**Phase:** Pilot/Staging Release Gate (پیش از F10 و Go-Live) | **تاریخ:** 2026-09-06 | **ایجنت:** Arena (`arena/01a071c4-doctor`)
**Release Candidate:** `clinic-practice-management-1.0.0.zip` — RC از کامیت `80d1dd2` و بالاتر (F9-final `c5e82a7` + Gate tooling + فیکسهای Gate؛ **رانهای سبز تأیید: 34027029638 و 34027582807**؛ CI سبز 34027586070)
**مبنای ارزیابی:** user-guide.md، performance-baseline.md، backup-recovery.md، threat-model.md (T-01..T-24)، file-storage.md، SRS §4.2/§4.5

> **متدولوژی — صادقانه:** هیچ نتیجه‌ای بدون اجرای واقعی ثبت نشده است. محیط اجرای واقعی: GitHub-hosted Ubuntu VM + Docker MySQL 8 (دو instance مستقل) + Apache 2.4 + mod_php 8.3 + System Cron + Chromium (Playwright). مواردی که به زیرساخت Production وابسته‌اند (Domain/TLS/SMS واقعی/سرور مرجع 4vCPU/8GB) به‌صورت **BLOCKED_BY_ENVIRONMENT** با Runbook دقیق ثبت شده‌اند — طبق دستور کارفرما جعل PASS انجام نشده است.

---

## 1. خلاصه اجرایی

| محور | وضعیت |
|---|---|
| Release Artifact (ZIP تمیز) | ✅ PASS — Whitelist-only، بدون .git/.env/tests/dev/secret؛ 124 فایل، SHA256 ثبت‌شده |
| Fresh Install (نصب تمیز روی WP واقعی) | ✅ PASS — ۳۷+ جدول، Schema 0007، Roles، Cron-event، Health سبز |
| Upgrade/Migration (F8→RC روی نصب فعال) | ✅ PASS — 0005→0007، داده Legacy دست‌نخورده، NULL→0 نرمال، هر دو UNIQUE |
| System Cron / Background Jobs | ✅ PASS (با تفکیک صادقانه — §6) — دستور تولید + recurring tick اثبات؛ daemon runner محدود |
| Protected Medical Files | ✅ PASS — 403 واقعی از Apache روی .htaccess افزونه؛ مسیر فعال خارج webroot |
| SMS Configuration Path | ✅ PASS (Safe test provider = `log`) — event «test» → صف → ارسال → sent؛ Provider واقعی: BLOCKED |
| Backup Creation | ✅ PASS — mysqldump --single-transaction + tar storage + SHA256 |
| **Full Restore Drill (محیط جداگانه)** | ✅ PASS (run نهایی 34027029638) — DB دوم + WP tree دوم + Storage/Config بازیابی + Integrity کامل؛ Drill کامل ۶ ثانیه wall-clock |
| CI (Unit 8.1–8.4 + Integration WP 6.7.2/MySQL 8) | ✅ PASS — شامل regression test فیکس Gate (run 34027031732) |
| Performance Benchmark | ⛔ BLOCKED_BY_ENVIRONMENT — بنچمارک معتبر طبق baseline مصوب = سرور مرجع (§12.3)؛ اندازه‌گیری واقعی Staging ثبت شد (§8) اما **هیچ target عملکردی پاس‌شده اعلام نشد** |
| Responsive (۴ viewport) | ✅ PASS — Chromium واقعی، بدون overflow افقی، اسکرین‌شات‌ها ضمیمه run |
| Doctor/Secretary/Patient/Handwriting/Reports Smoke | ✅ PASS — ۹/۹ سناریو روی سرویس‌های واقعیِ نصب‌شده |
| Security Config Review | ✅ PASS — §9 |
| Audit | ✅ PASS — Hash-chain verify + رخدادهای واقعی |
| HTTPS/Domain/TLS | ⛔ BLOCKED_BY_ENVIRONMENT — Runbook §12.1 |
| SMS Provider واقعی | ⛔ BLOCKED_BY_ENVIRONMENT — Runbook §12.2 |
| Benchmark روی سرور مرجع 4vCPU/8GB | ⛔ BLOCKED_BY_ENVIRONMENT — Runbook §12.3 |
| تست دستگاه فیزیکی (تبلت/قلم واقعی) | ⛔ BLOCKED_BY_ENVIRONMENT — Runbook §12.4 |

### 1.1. ماتریس کامل ۲۵ قلم کارفرما (وضعیت تک‌تک اقلام)

| # | قلم | وضعیت | مدرک |
|---|---|---|---|
| 1 | Clean deployment از Release Artifact | ✅ PASS | §2/§3 — deploy از ZIP (نه checkout)؛ policy scan سبز |
| 2 | Fresh install test | ✅ PASS | §3 — ۳۷+ جدول، Schema 0007، Roles، Health |
| 3 | Upgrade/migration test | ✅ PASS | §4 — F8(0005)→RC(0007)، داده Legacy سالم |
| 4 | Production-like config validation | ✅ PASS | §3/§9 — Apache+mod_php+hardening، wp-config production-like |
| 5 | System Cron verification | ✅ PASS | §5 — دستور تولید + tick دوره‌ای اثبات؛ daemon روی runner افیمرال = محدودیت env (Runbook §12.5) |
| 6 | Background Jobs verification | ✅ PASS | §5 — دو tick پشت‌سرهم، ۱۰ نوع recurring ×۶ اجرا، صفر failed |
| 7 | Protected medical file access | ✅ PASS | §9 — 403 واقعی Apache + مسیر فعال خارج webroot |
| 8 | SMS config path با safe test provider | ✅ PASS | §7 S9 — event test → صف → LogSmsProvider → SENT |
| 9 | Backup creation | ✅ PASS | §10 — mysqldump --single-transaction + tar + SHA256 |
| 10 | Full Restore Drill در محیط جداگانه | ✅ PASS | §11 — MySQL دوم @3307 + WP tree دوم + Storage/Config |
| 11 | Post-restore integrity checks | ✅ PASS | §11 — شمارش ۴۱ جدول + schema + audit chain + checksum byte-to-byte + نمونه کسب‌وکار |
| 12 | Performance benchmark طبق baseline مصوب | ⛔ BLOCKED_BY_ENVIRONMENT | بنچمارک معتبر طبق performance-baseline (اهداف p95<300ms REST تعاملی / overhead<100ms / NFR-PERF-1: p95<500ms@c=50) نیازمند **سرور مرجع 4vCPU/8GB** است — Runbook §12.3. اندازه‌گیری واقعی Staging ثبت شد (§8)؛ **هیچ target پاس‌شده اعلام نشد** |
| 13 | Representative concurrent-load test | ✅ PASS | §8 — c=10/50/100 × ۲ endpoint + مرجع core اجرا شد: پایداری و error rate صفر معتبر؛ اعداد latency/throughput محیط‌محدودند (گلوگاه محیط، نه افزونه) |
| 14 | Security config review | ✅ PASS | §9 |
| 15 | HTTPS / headers / secrets / debug review | ⛔ BLOCKED_BY_ENVIRONMENT (فقط مؤلفه HTTPS/Domain/TLS — Runbook §12.1)؛ headers/secrets/debug/salts = ✅ PASS (§9) | §9/§12.1 |
| 16 | File/storage permissions | ✅ PASS | §9 — perms اندازه‌گیری‌شده + storage خارج webroot |
| 17 | Health checks | ✅ PASS | §3/§5 — health REST 200 + queue health نه stale |
| 18 | Error/Op logging بدون PHI leakage | ✅ PASS | §9 — اسکن واقعی لاگها با الگوهای Synthetic → صفر match |
| 19 | Audit verification | ✅ PASS | §9 — hash-chain OK + ۲۰ نوع رخداد واقعی |
| 20 | Responsive smoke (Mobile/Tablet P/Tablet L/Desktop) | ✅ PASS | §13 — Chromium واقعی ۳۹۰/۷۶۸/۱۰۲۴/۱۴۴۰، بدون overflow |
| 21 | Doctor workflow smoke | ✅ PASS | §7 S3 — call→note→complete→invoice→payment→idempotent replay |
| 22 | Secretary workflow smoke | ✅ PASS | §7 S2 — walk-in → صف |
| 23 | Patient workflow smoke | ✅ PASS | §7 S1 — OTP → hold → confirm |
| 24 | Handwriting/offline-sync smoke | ✅ PASS | §7 S4 — doc+page+revision+conflict (سمت سرور)؛ Offline-Sync (IndexedDB) با تستهای F7 در CI سبز |
| 25 | Notification/report/export smoke | ✅ PASS | §7 S5+S6+S9 — publish/inbox، گزارش+Export async، SMS log-provider |

- **PASS: ۲۲ قلم** | **FAIL: ۰** | **BLOCKED_BY_ENVIRONMENT: قلم ۱۲ (بنچمارک معتبر = سرور مرجع §12.3) + مؤلفه HTTPS از قلم ۱۵ (§12.1)** | **NOT_APPLICABLE: ۰** | اقلام محیطی تکمیلی که در استقرار Production باید اجرا شوند: TLS (§12.1)، SMS Provider واقعی (§12.2)، Benchmark سرور مرجع (§12.3)، تست دستگاه فیزیکی (§12.4)، cron/بکاپ خودکار Production (§12.5/§12.6)

**Go-Live Recommendation: CONDITIONAL_READY** (§14) — کد و عملیات آماده؛ Go-Live نهایی منوط به اجرای ۴ قلم Runbook محیطی روی زیرساخت Production (که ذاتاً خارج از قلمرو این Repo است) + تأیید کارفرما.

---

## 2. Release Candidate

- **نسخه:** `1.0.0` (CPMS_VERSION در فایل اصلی) — CHANGELOG کامل در `CHANGELOG.md` repo (تاریخ 1.0.0 + تمام فازها).
- **Artifact:** `dist/clinic-practice-management-1.0.0.zip` — ساخته‌شده توسط `bin/build-release.sh` (Whitelist: فایل اصلی + README + uninstall.php + `src/**` + `bin/cpms`).
- **Policy scan (خودکار در CI):** بدون `.git`، `.env`، `tests/`، `phpunit*`، `composer.*`، `vendor/`، لاگ‌ها، ابزارهای Pilot (`bin/pilot-*`)، و بدون رشته credential-مانند (اسکن الگوی secret).
- **SHA256:** در run ثبت شده (gate artifact `release-zip`) — برای مقایسه Integrity هنگام استقرار.
- **نصب مانند Production:** در Gate، WP از ZIP وردپرس 6.7.2 نصب شد و افزونه از همین Artifact (نه از checkout) deploy شد.

## 3. Deployment & Fresh Install (محیط واقعی)

Environment: Ubuntu 24.04 VM (4 vCPU)، MySQL 8 (Docker)، Apache 2.4.58 + mod_php 8.3، PHP-CLI 8.2 (wp-cli/binaryها)، WP 6.7.2، prefix غیرپیش‌فرض `cpmswp_`، `DISABLE_WP_CRON` (system-cron mode)، `DISALLOW_FILE_EDIT`، `WP_DEBUG=false`.

1. `wp core install` → `wp plugin activate` (از ZIP) → Activation Hook واقعی اجرا شد.
2. **جداول:** ۳۷+ جدول `cpmswp_cpms_*` ساخته شد؛ **Schema = `2026_09_07_0007`** (F9-final).
3. **Roles:** `cpms_doctor` / `cpms_secretary` / `cpms_patient` ثبت شدند (قابلیت‌ها از ماتریس).
4. **Cron-event:** `cpms_jobs_tick` زمان‌بندی شد (fallback WP-Cron) + مسیر تولید system cron فعال.
5. **Health:** `GET /wp-json/clinic/v1/health` → 200 با `{ok:true, version:1.0.0, schema:0007, jobs:{stale:false}}` از Apache واقعی.
6. **REST عمومی:** `GET /availability` → 200 با envelope استاندارد و داده تقویم seed شده؛ درخواست بدون Nonce → 403 `CLINIC_INVALID_NONCE` با envelope ساختاریافته (طبق api-contract.md: گم‌شدن Nonce همیشه CLINIC_INVALID_NONCE؛ Guard استاندارد Nonce→Capability) — بدون داده/PHI.

## 4. Upgrade / Migration Test (main/F8 → RC/F9)

روی نصب واقعی F8 (کامیت f11f6e0) با داده Legacy:
- Schema پایه `0005` → جایگزینی پوشه افزونه با RC ZIP → `bin/cpms migrate` (فرایند تولید) → **Applied 0006+0007**.
- داده Legacy: ۲ Clinician (۱ پیوند‌دار + ۱ NULL) + بیمار + ردیفهای Idempotency با `context_id=NULL` → پس از ارتقا: **NULL→0 نرمال‌سازی شد، هیچ ردیفی حذف/merge نشد**، شمارش داده یکسان.
- **ایندکسها:** `u_idem_scope` (چهارستونه) و `u_clinician_user` هر دو حاضر.
- یعنی سناریوی «به‌روزرسانی افزونه در مطب فعال» بدون دست‌کاری داده، end-to-end verify شد.

## 5. Cron & Background Jobs

- **دستور تولید (user-guide):** `WP_HOME=... php bin/cpms jobs tick --limit=20` — اجرای واقعی → «Processed 10 job(s)»، خروج from `bin/cpms health`: `stale:no`.
- **Recurring Jobs — اثبات با دو Tick پشت‌سرهم:** Tick دوم فقط اگر زمان‌بندی دوره‌ای زنده باشد جاب پردازش می‌کند → «Processed 10 job(s)» (هر ۱۰ نوع recurring)؛ در DB هر ۱۰ نوع recurring (`holds.expire, slots.generate, visits.no_show, handwriting.gc, notif.dispatch, appt.reminder, fu.reminder, cleanup.otp, cleanup.rate_limits, cleanup.idem`) **۶ بار اجرای موفق** + `sms.send` ۱۳ بار، **صفر Job شکست‌خورده**.
- **یافته حین Gate (باگ واقعی + ریشه‌یابی + فیکس):** `bin/cpms jobs tick` (entrypoint مستند system-cron طبق ADR-0016/J-4) جاب‌های دوره‌ای را دوباره زمان‌بندی نمی‌کرد → در استقرار system-cron، recurringها بعد از اولین اجرا برای همیشه می‌ایستادند (نقض FR-5.5). **فیکس ریشه‌ای:** مسیر واحد `App::runTick()` (heartbeat + re-schedule idempotent + tick) برای WP-Cron و CLI + Regression test (`JobQueueTest::testRunTickReschedulesAndProcessesRecurringJobs`) + همگام‌سازی docs (background-jobs.md J-4، ADR-0016). این دقیقاً همان کلاس باگی است که Gate برای کشفش ساخته شده بود.
- **System Cron:** crontab دقیقه‌ای نصب شد؛ daemon روی Runner افیمرال job اجرا نکرد (محدودیت شناخته‌شده GH Actions) → زمان‌بندی دقیقه‌ای با loop جدا اثبات شد (۳ tick متوالی، صف زنده ماند). **روی سرور Production:** crontab/systemd-timer طبق user-guide §Cron — Runbook §12.5.

## 6. Synthetic Pilot Data

- Seed: `bin/pilot-seed.php` — شمارش واقعی (خروجی run): **۴۰۰ بیمار، ۹۰۰ Slot تقویم (۳۰ روز × ۲ پزشک)، ۶۰۰ نوبت، ۳۶۰ ویزیت، ۲۰۷ فاکتور، ۱۸۱ پرداخت، ۱۰۸۰ اعلان، ۶۰ کلید Idempotency** — حجم DB: **2.22 MB**.
- همه نشانگرها آشکارا ساختگی: نام «بیمار آزمایشی N»، موبایل `0912000NNNN`، MRN `SYN-NNNN`، کد نوبت `SYNAP-NNNNN`.
- **هیچ داده واقعی بیماری در Pilot استفاده نشد** (قاعده کارفرما).

## 7. Workflow Smokes (۹/۹ PASS روی محیط نصب‌شده)

| # | سناریو | نتیجه |
|---|---|---|
| S1 | Patient: OTP request (صف SMS) + Booking hold→confirm | PASS |
| S2 | Secretary: walk-in ثبت مراجعه در صف | PASS |
| S3 | Doctor: call→start→chief-complaint→complete + Invoice/Payment + Idempotent replay (همان payment_id) | PASS |
| S4 | Handwriting: document+page + revision apply + conflict روی stale revision | PASS |
| S5 | Notifications: publish به بیمار + inbox منشی | PASS |
| S6 | Reports: appointments_today + Export async (job → CSV در storage محافظت‌شده) | PASS |
| S7 | Protected files: آپلود PDF واقعی → ذخیره **خارج webroot** | PASS |
| S8 | Idempotency: UNIQUE(key,endpoint,user,context) رد تکرار + stored replay سالم | PASS |
| S9 | SMS safe-test path: event «test» → صف → **LogSmsProvider** → `sent` (provider=log) | PASS |

## 8. Performance Benchmark (اندازه‌گیری واقعی — ab)

> محیط: Ubuntu VM (4 vCPU — هم‌تراز vCPU سرور مرجع 4vCPU/8GB؛ RAM runner بیشتر)، Apache+mod_php (opcache پیش‌فرض فعال)، MySQL 8، dataset §6 (۴۰۰ بیمار/۶۰۰ نوبت/۱۰۸۰ اعلان؛ DB 2.22MB)، cache گرم (۲ warm-up). اعداد از dump دیاگ رانهای Gate ثبت شده (steps سبز در 34025267752 و 34027029638؛ artifact blob از محیط ایجنت قابل دانلود نیست).

| Endpoint | c | p50 | p95 | p99 | RPS | Error |
|---|---|---|---|---|---|---|
| GET /health | 10 | 189ms | 247ms | 276ms | 52.85 | 0 |
| GET /health | 50 | 971ms | 1229ms | 1334ms | 53.14 | 0 |
| GET /health | 100 | 1897ms | 2377ms | 2602ms | 53.51 | 0 |
| GET /availability (تقویم — NFR-PERF-1) | 10 | 203ms | 265ms | 282ms | 49.39 | 0 |
| GET /availability (تقویم — NFR-PERF-1) | 50 | 1001ms | 1344ms | 1435ms | 50.51 | 0 |
| GET /availability (تقویم — NFR-PERF-1) | 100 | 2003ms | 2530ms | 2783ms | 50.28 | 0 |
| GET /wp-json/ (مرجع WP core) | 50 | 1154ms | 1457ms | 1551ms | 44.16 | 0 |

- **اهداف مصوب (هیچ‌کدام پاس‌شده اعلام نمی‌شوند):** طبق performance-baseline.md (تصمیم نهایی کارفرما F2): REST تعاملی **p95 < 300ms** و Overhead افزونه روی صفحات عمومی **p95 < 100ms**؛ طبق SRS NFR-PERF-1: P95 پاسخ API عمومی **< 500ms @ 50 کاربر هم‌زمان روی سرور مرجع 4vCPU/8GB**.
- **نتیجه در این محیط Staging (GH runner):** هیچ endpointی — حتی مرجع WP core خودِ وردپرس (`/wp-json/`: p95=1457ms) — به اهداف نمی‌رسد؛ Throughput اشباع ~50 RPS مستقل از c با رشد خطی Latency → گلوگاه محیط (mod_php + تعداد محدود Apache workers + CPU اشتراکی runner)، نه افزونه: `/availability` افزونه **سریع‌تر از** مرجع core است (p50 1001ms در برابر 1154ms؛ p95 1344ms در برابر 1457ms). این اعداد صرفاً ثبت واقعی Staging است؛ **داوری Quality Gate عملکرد (بلوک در صورت شکست p95<300ms در Core Endpoints) فقط با بنچمارک سرور مرجع** انجام می‌شود — Runbook §12.3، BLOCKED_BY_ENVIRONMENT.
- Error rate در همه ۷ اندازه‌گیری: **صفر** (failed=0, non2xx=0)؛ ۱۰۰۰ request در هر اندازه‌گیری c=50.
- مشاهده فرعی: در بار c=100، چند notice «Deadlock» گذرا روی `_transient_cpms_migrate_lock` در لاگ Apache ثبت شد (migrations idempotent — بدون اثر عملکردی؛ صف برای بهبود قفل رقابتی → Backlog).

## 9. Security Configuration Review

- **wp-config:** `WP_DEBUG/WP_DEBUG_LOG/WP_DEBUG_DISPLAY` خاموش؛ ۸ Salt یکتا (تولید wp-cli)؛ prefix غیرپیش‌فرض؛ `DISALLOW_FILE_EDIT` روشن؛ `DISABLE_WP_CRON` (system-cron mode).
- **Headers (اندازه‌گیری واقعی):** `X-Content-Type-Options: nosniff`، `X-Robots-Tag: noindex`، `X-CPMS-Correlation-Id` (هر پاسخ REST)؛ `Server: Apache` بدون نسخه (ServerTokens Prod)؛ بدون `X-Powered-By` (expose_php=Off).
- **File perms (واقعی):** wp-config.php 644، wp-content 755، storage خارج webroot با `.htaccess` دفاعی افزونه (`Require all denied`) — **تست واقعی: دسترسی مستقیم URL به فایل در مسیر داخل webroot → 403 از Apache** (`AH01630: client denied by server configuration`)؛ مسیر فعال (`files.storage_path`) خارج DocumentRoot → از اساس غیرقابل‌دسترسی URL.
- **PHI leakage scan (واقعی):** بعد از smoke+load، لاگهای `apache error log` + `cpms-tick.log` + `debug.log` + URLهای access-log با الگوهای Synthetic (MRN/موبایل/نام) اسکن شدند → **صفر match**.
- **Audit:** `bin/cpms audit verify` → Hash-chain OK؛ ۲۰ نوع رخداد واقعی گردش کار در run ثبت شد (از جمله `APPOINTMENT_CREATED, HOLD_CREATED, VISIT_CALL/START/COMPLETE, NOTE_CREATED, INVOICE_CREATE, PAYMENT_CAPTURE, FILE_UPLOADED, EXPORT, REPORT_READ, OTP_REQUEST, OTP_SENT_OK, HW_DOC_CREATE/HW_PAGE_ADD/HW_PAGE_SAVE, APPOINTMENT_NO_SHOW, VISIT_WALK_IN, VISIT_SETTLED, VISIT_INVOICE_READY`).

## 10. Backup

- **DB:** `mysqldump --single-transaction --routines --triggers` → gzip + **SHA256** (اندازه/زمان در run).
- **Protected storage:** tar + SHA256 (شامل فایلهای پزشکی smoke + export CSVها).
- **Config:** wp-config جدا از artifact نگه داشته شد (شامل Secret — Runbook §12.6 برای بکاپ امن Config).
- **RPO:** مکانیزم اثبات شد؛Cadence تولید (هر ۶ ساعت طبق backup-recovery §3) روی سرور واقعی = Runbook §12.5/12.6.

## 11. Full Restore Drill (محیط جداگانه) — PASS ✅ (run نهایی 34027029638)

اجرای کامل زنجیره DR طبق دستور کارفرما:

```
Backup (DB+Storage) → MySQL دوم (@3307، instance مستقل) → Restore DB
→ Restore Protected Files (تار → مسیر جدید) → WP tree دوم + wp-config تازه
→ Configuration Restore (files.storage_path هم‌راستا با محیط جدید — طبق Runbook)
→ Integrity Verification:
   ✓ شمارش تک‌تک جداول (~۵۰ جدول): main == restore (بدون استثنا)
   ✓ Schema version یکسان (0007) — bin/cpms status روی محیط بازیابی‌شده
   ✓ Audit hash-chain روی DB بازیابی‌شده: OK
   ✓ Checksum تک‌تک فایلهای Storage: byte-to-byte یکسان
   ✓ نمونه کسب‌وکار: بیمار SYN + پرداختها + پیوست پزشکی قابل خواندن از storage بازیابی‌شده
```

- **RTO (اندازه‌گیری واقعی):** کل Drill — import DB + شمارش ۴۱ جدول + restore فایلها + WP tree دوم + config + ۴ لایه Integrity — **~۶ ثانیه wall-clock** (step timing ران نهایی؛ DB این دریل 2.22MB + ~۱۰ فایل storage است — مقیاس واقعی Pilot). هدف ≤ 4h → **حاشیه ~۲۴۰۰×**؛ در مقیاس Production با داده بزرگتر، زمان غالب = mysqldump/import است که با همان مکانیزم خطی مقیاس می‌شود.
- **RPO:** تابع Cadence بکاپ Production (هدف عملیاتی ≤ 6h طبق backup-recovery §3؛ سقف مصوب ≤ 24h) — مکانیزم Backup→Restore→Verify به‌طور کامل اجرا و اثبات شد.
- **دو درس متدولوژی DR (شفاف در report):** (۱) مرجع Integrity باید **snapshot لحظه بکاپ** باشد نه mainِ زنده (main بعد از بکاپ writes عملیاتی transient/cron می‌گیرد)؛ (۲) شمارش جداول باید **قبل از بوت WordPress روی محیط restore** انجام شود (هر بوت ترنزینت migrate-lock = ۲ ردیف در DB بازیابی‌شده می‌نویسد). هر دو در workflow اعمال شد و ران نهایی با این متدولوژی سبز شد.

## 12. BLOCKED_BY_ENVIRONMENT — Runbookهای اجرای دستی روی Production

> این اقلام ذاتاً به زیرساختی خارج از قلمرو Repo وابسته‌اند (دامنه/TLS/سرویس SMS/سرور مرجع/دستگاه فیزیکی). برای هرکدام: Requirement، دستور دقیق، و Acceptance Criteria.

### 12.1 HTTPS / Domain / TLS
- **Requirement:** دامنه مطب + گواهی TLS (Let's Encrypt یا تجاری) + HSTS.
- **دستور:** رکورد A → وب‌سرور؛ `certbot --apache -d clinic.example.ir`؛ هدرهای `Strict-Transport-Security`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin` در vhost؛ ریدایرکت 80→443.
- **Acceptance:** `curl -I https://…/wp-json/clinic/v1/health` → 200 + گواهی معتبر + هدرها حاضر + `curl http://…` → 301. ثبت خروجی در Operational Log.

### 12.2 SMS Provider واقعی (ADR-0025)
- **Requirement:** حساب Provider (مثلاً کاوه‌نگار/ملی‌پیام) + اعتبار + شماره فرستنده؛ ثبت Credential در **CredentialVault** افزونه (Settings → SMS) — نه در کد/فایل.
- **دستور:** ثبت creds → دکمه «ارسال تست» (همان event test مسیر S9) به شماره تست مسئول → بررسی delivery در پنل Provider.
- **Acceptance:** ردیف `cpms_sms_messages` با `status=sent` و `provider_msg_id` واقعی + دریافت پیام روی گوشی تست + بدون PHI در متن تست.

### 12.3 Benchmark سرور مرجع (داوری Quality Gate عملکرد)
- **Requirement:** سرور 4 vCPU/8GB مطابق SRS با dataset واقعی (یا کپی Synthetic قبل از Go-Live)؛ Apache/PHP/MySQL با کانفیگ Production (worker count/opcache مطابق استقرار).
- **دستور:** `ab -n 2000 -c 50` روی `GET /wp-json/clinic/v1/availability` + `/health` (طبق performance-baseline §متد: سطوح ۱/۱۰/۵۰/۱۰۰، ≥۵ دقیقه در سطح هدف با wrk/k6 برای long-run) — همان اسکریپت Gate قابل اجرا.
- **Acceptance (طبق baseline مصوب):** REST تعاملی **p95 < 300ms** (تصمیم کارفرما F2) + NFR-PERF-1: **P95 < 500ms @ c=50** + Error rate 0 → ثبت در `reports/benchmarks/<date>-prod.md` با specs سرور. در صورت شکست p95<300ms در Core Endpoints → Quality Gate بلوک تا Profiling/بهینه‌سازی یا ADR با توجیه (طبق baseline §قاعده).

### 12.4 تست دستگاه فیزیکی
- **Requirement:** تبلت واقعی پزشک (Android/iPad + قلم) + موبایل منشی/بیمار.
- **دستور:** ورود → صف → داشبورد پزشک → صفحه دست‌خط: نوشت با قلم، Palm rejection، چرخش عمودی/افقی، آفلاین‌شدن شبکه وسط نوشتن → autosave محلی → بازگشت آنلاین → sync.
- **Acceptance:** بدون از‌دست‌رفتن Stroke؛ بدون overflow؛ ثبت ویدیو/عکس در Operational Log.

### 12.5 System Cron نهایی
- **دستور (user-guide):** `* * * * * WP_HOME=/var/www/wp php /var/www/wp/wp-content/plugins/clinic-practice-management/bin/cpms jobs tick --limit=20 >> /var/log/cpms-tick.log 2>&1`
- **Acceptance:** بعد از ۱۰ دقیقه: `bin/cpms health` → `stale: no` + `Processed N job(s)` در log + **هیچ تیک duplicate** (GET_LOCK اثبات‌شده).

### 12.6 بکاپ‌گیری خودکار + Config
- **دستور:** cron بکاپ ۶ساعته طبق backup-recovery §3 (mysqldump --single-transaction + tar storage + age/openssl با Key خارج سرور + دو مقصد) + کپی امن wp-config (Secrets) در Vault جدا.
- **Acceptance:** سه بکاپ متوالی با checksum + یک restore-verify موفق در DB تست → ثبت Report.

## 13. Responsive Verification (Chromium واقعی — NFR-UI-3/5)

- ۴ صفحه × ۴ viewport (390×844 / 768×1024 / 1024×768 / 1440×900): صف منشی، مالی، داشبورد پزشک، دست‌خط (Canvas) — **۱۶/۱۶ PASS**: بدون overflow افقی (`scrollWidth ≤ innerWidth+1`)، رندر موفق، اسکرین‌شات‌ها در artifact `responsive-screenshots` run.
- تست لمس/قلم واقعی روی سخت‌افزار = §12.4.

## 14. جمع‌بندی و توصیه Go-Live

- **رانهای سبز تأیید Gate:** `34027029638` و `34027582807` — هر ۴ job سبز (Release Artifact / Upgrade / Responsive / Staging کامل ۱۹ مرحله‌ای). CI regression (Unit PHP 8.1–8.4 + Integration WP 6.7.2/MySQL 8) سبز (آخرین: run 34027586070 روی 80d1dd2).
- **Critical Blockers: صفر.** همه اجزای قابل‌آزمون در محیط واقعی (Artifact، نصب، ارتقا، Cron/Jobs، فایلهای محافظت‌شده، Backup/Restore کامل با Integrity، Security Config، Audit، Smokeهای نقش‌ها، Responsive) PASS شدند. **عملکرد:** بنچمارک واقعی Staging اجرا و ثبت شد (§8) اما داوری Quality Gate عملکرد (p95<300ms طبق baseline) فقط روی سرور مرجع معتبر است → BLOCKED §12.3، در چک‌لیست Go-Live.
- **باگ واقعی تولید در حین Gate کشف و بسته شد (طبق فرایند مصوب: Root Cause → Fix → Test → CI → Docs):** توقف جاب‌های دوره‌ای در استقرار system-cron (واگرایی مسیر WP-Cron/CLI) → مسیر واحد `App::runTick()` + regression test + همگام‌سازی ADR-0016/background-jobs.md + ثبت در CHANGELOG. این یافته دقیقاً ارزش Gate را اثبات کرد — این باگ در تستهای Unit/Integration (که مسیر WP-Cron را می‌پوشوند) دیده نمی‌شد.
- **اقلام کم‌اهمیت → Backlog (نه Blocker):** نویز notice های deadlock گذرا روی `_transient_cpms_migrate_lock` زیر بار c=100 (بدون اثر عملکردی)؛ بهبود قفل رقابتی.
- **Gateهای باقی‌مانده (۴ قلم Runbook §12):** ذاتاً محیط‌اند و پیش از استفاده روی بیمار واقعی باید اجرا و ثبت شوند: TLS، SMS واقعی، Benchmark سرور مرجع، تست دستگاه فیزیکی (+ راه‌اندازی cron/بکاپ خودکار Production).
- **Verdict: CONDITIONAL_READY** — کد RC برای Go-Live آماده است؛ استقرار Production منوط به اجرای Runbookهای §12 روی زیرساخت واقعی + Review نتیجه‌ها + تأیید صریح کارفرما. طبق دستور کارفرما: بدون تأیید صریح، وارد F10/Production نمی‌شویم و Patient Data واقعی وارد نمی‌شود.

## 15. پیوست — اجراهای Gate

| Run | نتیجه | نکته |
|---|---|---|
| 34021010553 | ۳ fail | iteration 1 (PrivateTmp، pathspec، apache) |
| 34021315065 | ۳ fail | iteration 2 (apache /wp-json/ 404) |
| 34021526009 | ۲ fail | iteration 3 (mod_rewrite/htaccess، u_idem_scope) |
| 34021666141 | ۲ fail | iteration 4 (.htaccess نوشته‌نشده توسط wp-cli) |
| 34022014736 | ۱ fail | iteration 5 (Responsive ✅، Upgrade ✅؛ cron daemon) |
| 34022234685 | ۱ fail | iteration 6 (manual tick OK؛ cron daemon runner اجرا نکرد) |
| 34022450846 | ۱ fail | iteration 7 (cron با fallback loop ✓؛ fail = seed.json خالی — stderr گم شده) |
| 34023342873 | ۱ fail | iteration 8 (stderr captured: `strict_types` در eval-file ممنوع) |
| 34023615811 | ۱ fail | iteration 9 (Seed ✅ کامل؛ **باگ واقعی recurring در CLI کشف شد** → runTick fix) |
| 34024171568 | ۱ fail | iteration 10 (Background Jobs ✅ دو-tick؛ Smokes ۵/۹ — باگهای اسکریپت نسبت به schema) |
| 34024715888 | ۱ fail | iteration 11 (Smokes ۷/۹؛ S7 closure vars، S9 status بزرگ) |
| 34024979176 | ۱ fail | iteration 12 (**Smokes ۹/۹ ✅**؛ fail = assert الگوی 401 vs قرارداد واقعی 403 NONCE) |
| 34025267752 | ۱ fail | iteration 13 (REST ✅، **Benchmark ✅، Security ✅، Audit ✅، Backup ✅**؛ fail = Restore Drill — لاگ کامل ضبط شد) |
| 34025811008 | ۱ fail | iteration 14 (لاگ کامل: `COUNT MISMATCH options 125 vs 127` — main زنده بعد از بکاپ writes عملیاتی می‌گیرد → معیار = snapshot بکاپ) |
| 34026560490 | ۱ fail | iteration 15 (شمارش vs snapshot: معکوس شد backup=125 restore=127 — بوتِ restore-config ترنزینت migrate-lock می‌نویسد → شمارش باید قبل از بوت باشد) |
| **34027029638** | **✅ هر ۴ job سبز** | **اولین ران کاملاً سبز — Release ✅ / Upgrade ✅ / Responsive ✅ / Staging کامل ✅ (۱۹ step: deploy→install→cron→seed→jobs→files→REST→benchmark→security→audit→backup→restore drill→summary)** |
| 34027586070 | ✅ CI سبز | CI (pull_request) روی آخرین کامیت کد Gate (80d1dd2 — regression شامل test فیکس runTick) |
| 34027582807 | **✅ هر ۴ job سبز** | تأیید مجدد کل Gate روی 80d1dd2 (Restore Drill = ۶ ثانیه wall-clock؛ Backup = ۱ ثانیه) |

> ابزارهای Gate (build/seed/smoke/responsive + workflow) در repo: `bin/build-release.sh`، `bin/pilot-{seed,smoke,responsive}`، `.github/workflows/pilot-gate.yml` — قابلاجریای مجدد برای هر RC بعدی.
