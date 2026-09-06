# گزارش فاز F10 — خودمختاری تجاری (لایسنس، بکاپ، بهروزرسانی امن، Health) — وضعیت اجرا

تاریخ: 2026-09-06 | وضعیت: **IN_PROGRESS — کد کامل؛ تأیید CI در انتظار push/PR** | شاخه: `arena/01a076ad-doctor`
مراجع: F10 spec §1–§52؛ ADR-0023/0028/0029؛ docs/agent-guide.md §10؛ `/home/user/cpms-handover-audit-report.md` (خط پایه)

## 1. خط پایه و بازبینی دلتا
- ممیزی کامل قبلی (۲۴ بخش + ماتریس ۳۲ ردیف) خط پایه است؛ **تکرار نشد**.
- Git delta: HEAD `a68241bce…` == `origin/main`؛ شاخه `arena/01a076ad-doctor`؛ tree تمیز در شروع؛ PR #1 merged / PR #2 closed — هیچ تغییری از ممیزی. **بدون FOUNDATIONAL_CONFLICT.**
- طبقهبندی دامنههای F10: Licensing = MISSING (seam READY)؛ Backup/Restore = MISSING؛ Update = MISSING؛ Cron/Jobs = READY؛ Health/Compatibility = PARTIAL؛ همه بهصورت پشت Seamهای موجود پیاده شد.

## 2. آنچه پیاده شد (Commitها e8e058a → e90b186)
| حوزه | شواهد |
|---|---|
| **بنیان معماری** | ADR-0023 (پروتکل لایسنس: state محلیِ امضاشده، مهلت ۷ روز GRACE، قطع شبکه ≠ نامعتبر، REVOKED/SUSPENDED فقط از سند معتبر)؛ ADR-0028 (مرز Data/Control Plane + Allowlist ابرداده؛ بدون PHI)؛ ADR-0029 (امضای Ed25519 جدا برای انتشار؛ بدون eval/کد راه دور) |
| **لایسنس** | Domain: `LicenseStatus/Policy/StateMachine/EntitlementRegistry/SignedLicenseGate/LicenseSignature/LicenseKeys`؛ Infra: `VendorGateway + HttpVendorGateway` (HTTPS+SSRF+Timeout)، `LicenseRepository`، Migration `2026_09_07_0008`؛ App: `LicenseService` (فعالسازی سرور/آفلاین، refresh Backoff)؛ `App::licenseGate()` واقعی؛ Job `license.refresh`؛ نصب فعالنشده = `NOT_CONFIGURED` (مجاز ولی برجسته — اولویت §1) |
| **بکاپ/بازیابی** | موتور داخل افزونه (بدون mysqldump؛ snapshot سازگار؛ فقط cpms_*) + mirror storage + مانیفست sha256؛ ProtectedBackupStore؛ Job `backup.run`؛ Retention؛ Preflight + Safety Backup + Restore با تأیید صریح؛ CLI `bin/cpms backup …`؛ تنظیمات `backup.*` |
| **بهروزرسانی امن** | `ReleaseManifest/Signature/Keys` + `HttpUpdateMetadataGateway` + `UpdateService` (entitlement گیت `updates`؛ کش؛ بدون شبکه در صفحات عادی)؛ تنظیمات `update.*` |
| **Health/UX** | `SystemHealthService` (چکهای بدون PHI؛ Host Capability SUPPORTED/WARNINGS/UNSUPPORTED) + صفحه «CPMS (سیستم)» (مجوز/Health/بکاپ/Restore/بهروزرسانی؛ `cpms_config`+nonce؛ بدون PHI)؛ `bin/cpms status` → وضعیت مجوز |
| **تستها (افزوده)** | واحد خالص ۳۲ تست (StateMachine، Entitlement، SignedLicenseGate، Signature سدیم-گیت، Manifest، Splitter، HostClassification، ReleaseManifest)؛ Integration (CI): LicenseLifecycle (۱۰)، BackupEngine (۶)، UpdateService (۷)، SlotCapacityOneHundredWay (§27/§28؛ ۱۰۰ فرایند + اتصال مستقل MySQL روی `atomicBook` واقعی) |
| **Error codes** | ثبت کامل `CLINIC_LICENSE_*` در docs/api/error-codes.md (ADR-0019) |

## 3. وضعیت شواهد (صادقانه)
| نوع | وضعیت |
|---|---|
| واحد تست (محلی WASM PHP 8.2) | **۲۷۰ تست، ۱۶٬۶۰۵ اِسert، ۰ شکست** (۸ خطای محیطی شناختهشده 32-bit بدون /dev/urandom؛ ۸ skip نیازمند sodium — در CI اجرا میشوند) |
| Lint (php -l همه فایلهای تغییر/جدید) | ✅ تمام فایلهای src/tests/bin — بدون خطای Syntax |
| Integration/CI (WP 6.7 + MySQL 8 + pcntl + PHPUnit 9.6) | ⏳ **در انتظار اجرا** پس از push + PR (این sandbox MySQL/WP ندارد — BLOCKED_BY_ENVIRONMENT) |
| 100-way پذیرش (§27/§28) | ⏳ CI (pcntl + MySQL) — کد نوشته و با الگوی VisitConcurrencyTest هماهنگ است |
| سرور مرکزی فروشنده (لایسنس/انتشار) | ⛔ خارج از repo — قرارداد + mock/fixture + Runbook (تأیید زنده BLOCKED_BY_ENVIRONMENT) |
| اعمال نهایی Restore (DROP/ایمپورت) | ⛔ فقط CLI/Admin با تأیید؛ Preflight+Safety در CI اثبات میشود؛ اعمال مخرب = فرایند DR در محیط مجزا |
| PHPStan | بدون ابزار موجود (همان F1–F9) — ادعای جعلی نمیشود |

## 4. تصمیمهای مهندسی (بدون نیاز به تأیید مجدد — طبق اسپک)
1. نصب فعالنشده = `NOT_CONFIGURED` و **مجاز** (اولویت §1: ایمنی بیمار > اجبار تجاری)؛ با Health/Admin برجسته. انفاذ واقعی از لحظهی وجود سندِ امضاشدهی معتبر.
2. عملیات در RESTRICTED: فعالیت مستقل جدید مسدود؛ لغو نوبت/بهروزرسانی بیمار/تاریخچه/Export/گردشکار در جریان مجاز (spec §16).
3. بکاپ V1 مقصد محلیِ محافظتشده؛ Remote (S3/SFTP) = V1.1 (interface آماده؛ Ops-runbook فعلی).
4. بهروزرسانی V1: authorize + integrity-verify؛ نصب از صفحه استاندارد وردپرس/CLI؛ دانلود خودکار = V1.1.
5. Entitlement کلید ناشناخته = fail-closed؛ شمارش/سقف پزشکان = V1.5 (بدون scope-creep).

## 5. گامهای بعدی (پس از این گزارش — طبق §51 بدون تأیید کارفرما ادامه داده نمیشود)
1. push شاخه + PR (CI واقعی)؛ رفع هر شکست CI بر اساس شواهد.
2. تأیید کارفرما → merge و ادامه DoD باقیمانده (V1.1 items خیر).

## 6. ریسکهای باز (مستند)
- تستهای Integration تا اجرای CI تأیید نشدهاند (کد مطابق الگوهای موجود؛ ریسک پایین ولی صفر نیست).
- کلیدهای رسمی Production (مجوز/انتشار) Placeholder هستند — fail-closed تا Release (مستند در ReleaseKeys).
- عمق تستِ Health (رندر صفحه) در CI پوشش مستقیم ندارد — تأیید دستی در Pilot.
