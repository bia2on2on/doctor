# Clinic Practice Management (CPMS) — WordPress Plugin

افزونه اختصاصی مدیریت مطب. مستندات معماری: `../docs/`.

> 🤖 **ایجنت‌ها:** قبل از هر کاری [`../docs/agent-guide.md`](../docs/agent-guide.md) را بخوانید (وضعیت + قواعد + پروتکل لاگ کار ایجنت‌ها).

## ساختار (ADR-0001)
```
src/
  Domain/           ← خالص (بدون WP): State Machines, OtpPolicy, InvoiceCalc, SlotGenerator
  Migrations/       ← Migration System + 0001 (37 جدول cpms_*)
  Infrastructure/   ← CpmsDb, Audit (Hash Chain), OpLog, RateLimiter, Idempotency, JobQueue
  Application/      ← Services + Job Handlers
  Auth/             ← Roles/Capabilities
  Admin/            ← Settings (فنی)
  Rest/             ← Base REST (nonce/cap/error shape)
tests/
  Unit/             ← بدون WP (PHPUnit) — هر CI
  Integration/      ← WP Test Suite — با MySQL واقعی
bin/cpms            ← CLI: migrate | jobs tick | audit verify | slots generate
```

## نصب و کار با CLI (سرور)
```
wp plugin activate clinic-practice-management
bin/cpms migrate            # ساخت جداول (idempotent)
bin/cpms jobs tick          # اجرای Jobهای سررسید (Cron OS: هر دقیقه)
bin/cpms audit verify 10000 # صحت زنجیر هش Audit
```

## نصب از طریق ZIP و محدودیت‌های میزبان (D3)

بستهٔ انتشار CPMS (ساخته‌شده با \`bash bin/build-release.sh\`) شامل کاتالوگ آفلاین ۳۱ استان و runtime vendor است و معمولاً حدود ۱۰–۱۶ مگابایت (بسته به نسخهٔ وابستگی‌ها) حجم دارد.

**مقادیر پایهٔ PHP (نه تضمین تنظیمات هاست):** مستندات PHP، در نبود تنظیم جایگزین، `post_max_size=8M` و `upload_max_filesize=2M` را به‌عنوان مقادیر پیش‌فرض معرفی می‌کند؛ کنترل‌پنل و فایل‌های تنظیم واقعی هر هاست ممکن است متفاوت باشند ([`post_max_size`](https://www.php.net/manual/en/ini.core.php#ini.post-max-size)، [`upload_max_filesize`](https://www.php.net/manual/en/ini.core.php#ini.upload-max-filesize)).

**علت پیام را از علت‌های دیگر جدا کنید:** اگر بدنهٔ کامل درخواست `multipart/form-data` از `post_max_size` بزرگ‌تر شود، PHP پیش از اجرای کد WordPress، `$_POST` و `$_FILES` را خالی می‌کند. در مسیر core «بارگذاری افزونه»، بررسی nonce پیش از پردازش فایل انجام می‌شود؛ بنابراین nonce مفقود می‌تواند به پیام عمومی «پیوند منقضی شده» / “The link you followed has expired” برسد. این پیام به‌تنهایی ثابت نمی‌کند nonce منقضی یا ZIP خراب است. اگر فقط `upload_max_filesize` رد شود و کل درخواست زیر `post_max_size` بماند، WordPress مسیر خطای اندازه/آپلود فایل را دارد؛ این دو سقف علت‌های متفاوت‌اند.

**راهنما (بدون افزایش بی‌صدا):** CPMS این سقف‌ها را خودکار تغییر نمی‌دهد؛ آن‌ها در سطح میزبان/PHP هستند.
- «رسانه → افزودن» سقف مؤثر آپلود در WordPress را نشان می‌دهد؛ صفحهٔ «CPMS (سیستم) → سلامت سیستم» هر دو مقدار را پس از فعال‌شدن افزونه گزارش می‌کند. این بررسی پس از فعال‌سازی در دسترس است و نمی‌تواند درخواستِ نصب اولیه‌ای را که در لایهٔ میزبان/PHP رد یا ناقص شده است، بازیابی کند.
- بستهٔ **داخلیِ قابل‌نصب** ساخته‌شده با `bin/build-release.sh` در دو اجرای CI شاخهٔ PR #266 در ۲۴ اکتبر ۲۰۲۶، دقیقاً **10,302,900 بایت** بود؛ ZIP بیرونیِ artifact در اجرای دوم **8,717,478 بایت** است و جایگزین اندازه‌گیری بستهٔ داخلی نیست. بازسازی مستقل محلی (PHP/Composer) در محیط عامل انجام نشد؛ SHA بسته‌های مستقل با وجود تعداد بایت یکسان متفاوت بود. این اندازه مربوط به همان اجراهاست، نه تضمین بسته‌های آینده.
- آستانهٔ محتاطانهٔ سلامت سیستم **32M برای upload_max_filesize و 40M برای post_max_size** است (۸ مگابایت حاشیهٔ درخواست)؛ این اعداد اندازهٔ ZIP نیستند و برای بسته‌های آینده نیز باید با اندازهٔ ZIP داخلی و سقف واقعی میزبان سنجیده شوند.
- اگر سقف کمتر از ZIP قابل‌نصب است، اندازهٔ خود ZIP را (نه ZIP بیرونیِ GitHub Actions) اندازه بگیرید؛ در \`php.ini\`، \`.user.ini\` یا پنل هاست \`upload_max_filesize\` را بالاتر از حجم ZIP و \`post_max_size\` را با فاصلهٔ کافی بالاتر از مجموع آپلود و سربار فرم تنظیم کنید. 20M را بدون اندازه‌گیری کافی فرض نکنید.
- جایگزین بدون تغییر سقف: ZIP را از طریق FTP/پنل فایل در \`wp-content/plugins/\` باز کنید یا با WP-CLI نصب کنید: \`wp plugin install --activate dist/clinic-practice-management-*.zip\`.

ایمنی: افزایش سقف فقط در پیکربندی میزبان انجام می‌شود و هیچ نانس یا بررسی امنیتی تضعیف نمی‌شود.

## Release dependency foundation (Phase 15 Slice 2A)

- PHP support remains **8.1–8.4**. Composer resolves against **PHP 8.1.0**, even
  on a newer runner. Commit `composer.lock`; never commit `vendor/`.
- Run `composer install` for source tests. The plugin retains its internal
  application autoload fallback when a source checkout has no vendor tree.
- `bash bin/build-release.sh` requires the committed lock and Composer 2 with
  `audit --abandoned=fail` support. It installs production dependencies into a
  fresh stage with `--no-dev --no-plugins --no-scripts`, never copies source
  vendor, never updates/solves the release dependency tree, and blocks on audit
  advisories, abandoned packages or an unreviewed runtime license closure.
- The ZIP includes runtime vendor, Composer runtime autoload and upstream
  license/NOTICE files; `composer.json`/`composer.lock`, installed.json, dev
  dependencies, tests, auth/cache and configuration/credential files stay out.
  See the shipped `THIRD-PARTY-NOTICES.md` for Apache-2.0 / MIT attribution.
- `python3 tests/bin/release-runtime-contract.py` checks the built ZIP and
  constructs the official S3Client in a fresh PHP process using dummy constants
  and a throwing HTTP handler. It makes **zero network requests**. CI repeats
  production installation, platform checks and this smoke on PHP 8.1–8.4.
- **No S3 backup integration or remote-protection claim is delivered here.**

## اصول سفت
- داده پزشکی فقط در `cpms_*` (نه wp_posts).
- بدون کد OTP/رمز/Token در Log.
- هر تغییر وضعیت = State Machine + History + Audit.
- بدون Endpoint جدید بدون API Contract (`docs/api/api-contract.md`).
