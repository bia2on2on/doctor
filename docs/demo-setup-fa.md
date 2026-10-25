# محیط آزمون یک‌بارمصرف CPMS Classic — ابزار بیرونی

این سند برای **اجرای ابزار بیرونیِ `tests/disposable-site/` روی یک WordPress کاملاً یک‌بارمصرف** است. این ابزار بخشی از افزونهٔ نصب‌شونده نیست و هیچ Demo، Fixture، مسیر احراز هویت آزمایشی یا Shortcode آزمایشی به Production اضافه نمی‌کند.

> هرگز این روش را روی سایت واقعی، سایت دارای بیمار/نوبت/سند بالینی/اطلاعات مالی، یا محیطی که نمی‌خواهید کاملاً از بین برود اجرا نکنید. تأیید عبارت خطرناک بودن محیط، مجوز استفاده روی دادهٔ واقعی نیست.

## ۱) مرز Production و پیش‌نیازها

- کد Production در `clinic-practice-management/` دست نمی‌خورد و باید با `main` زنده‌ی مبنا یکسان بماند. ابزار فقط از مخزن، با WP-CLI و به‌طور صریح اجرا می‌شود؛ نصب/فعال‌سازی/به‌روزرسانی افزونه هیچ Fixture یا حسابی نمی‌سازد.
- WordPress تمیز، PHP و MySQL پشتیبانی‌شده، WP-CLI، و ZIP ساخته‌شده از همان سورس Production لازم است. برای اجرای مرورگر، Chromium و Playwright نسخهٔ پین‌شدهٔ Workflow لازم است.
- Schema مورد انتظار این نسخه `2026_10_18_0029` است. ابزار روی Schema ناسازگار Fail-Closed می‌شود.
- قبل از Provisioning، سه ردیف ساختاریِ دست‌نخوردهٔ bootstrap یعنی Organization/Clinic/Location پیش‌فرض مجاز است؛ همهٔ جداول عملیاتیِ بیمار، نوبت، بالینی، مالی، OTP و ارتباطات باید خالی باشند. شکل دیگری از داده، یا Fixture قبلی، پذیرفته نمی‌شود.
- برای جلوگیری از تولید اسلات اضافه و پردازش Job در طول آزمون، `DISABLE_WP_CRON` را در **همان نصب یک‌بارمصرف** فعال کنید. این تغییر در `wp-config.php` محیط آزمون است، نه تغییر رفتار افزونه.
- اجرای Provisioning باید دست‌کم ۵۰ دقیقه پیش از نیمه‌شب به وقت `Asia/Tehran` آغاز شود تا دو اسلات همان روز برای مسیر عمومی و پذیرش باقی بماند. نزدیک نیمه‌شب اجرا نکنید.
- ادمینِ فنیِ موجود فقط برای بوتstrapping سازمان و اجرای WP-CLI به کار می‌رود. به حساب Administrator هیچ عضویت یا اختیار بالینی/مالی CPMS داده نمی‌شود.

## ۲) ساخت ZIP و اثبات جدایی ابزار

از ریشهٔ مخزن:

```bash
bash clinic-practice-management/bin/build-release.sh
```

سپس ابزار `tests/disposable-site/assert-package.py` را با خروجی ZIP، سورسِ بایگانی‌شدهٔ `main` زنده و SHA مبنا اجرا کنید. این بررسی:

1. فایل‌های غیر Vendor داخل ZIP را بایت‌به‌بایت با همان فایل‌های Production مبنا مقایسه می‌کند.
2. مسیرهای توسعه/تست/اسناد/محرمانه و Markerهای Demo و ابزار بیرونی را رد می‌کند.
3. در اجرای دوم، رمزها و نام‌های کاربریِ تولیدشده را نیز در محتوای ZIP و بایت‌های خام آن جست‌وجو می‌کند.
4. SHA-256 و اندازهٔ ZIP واقعی را در `package-proof.json` ثبت می‌کند.

این اثبات به معنای Release رسمی، لینک دانلود عمومی یا Reproducible بودن بایت‌های ZIP نیست. ZIP محلی/CI تا بارگذاری و بررسی مستقل، یک Artifact محلی/CI است.

## ۳) فایل محرمانه و Provisioning صریح

روی هاست یک‌بارمصرف، مسیرهای زیر را با مسیرهای واقعی خود جایگزین کنید. دایرکتوری محرمانه باید **خارج از DocumentRoot و خارج از Git worktree** و با mode `0700` باشد:

```bash
export WP_DIR=/path/to/disposable-wordpress
export CPMS_DISPOSABLE_STATE=/secure/cpms-disposable/credentials.json
export CPMS_DISPOSABLE_OTP_FILE=/secure/cpms-disposable/otp.json
install -d -m 700 "$(dirname "$CPMS_DISPOSABLE_STATE")"
wp config set DISABLE_WP_CRON true --raw --path="$WP_DIR"
```

با حساب Administrator موجود (نه حساب ساخته‌شدهٔ آزمون) اجرا کنید:

```bash
CPMS_DISPOSABLE_CONFIRM=I_UNDERSTAND_THIS_IS_A_DISPOSABLE_EMPTY_SITE \
  wp eval-file tests/disposable-site/wp-cli/provision.php --use-include \
  --path="$WP_DIR" --user=existing_admin
```

اجرای بدون عبارت تأیید، مسیر داخل Web root/Git، Schema نامعتبر، یا سایت غیرخالی باید شکست بخورد. اجرای دوباره با همان State فقط همان Fixture را گزارش می‌کند و داده‌ای نمی‌سازد؛ ساخت Fixture دوم روی همان سایت رد می‌شود.

فعال‌سازی طبیعی CPMS ممکن است heartbeat سیستمیِ `jobs.last_tick_at` را برای Clinic پیش‌فرض بسازد. Verifier فقط همین مقدارِ مثبتِ System-written را به‌عنوان وضعیت پایه می‌پذیرد؛ هر Setting دیگر و هر ردیف بیمار/نوبت/بالینی/مالی همچنان باعث رد Provisioning می‌شود.

### شش حساب و محدودهٔ دسترسی

| حساب | نقش WordPress | اختیار CPMS |
|---|---|---|
| Organization Manager | `subscriber` | عضویت Organization فقط برای سازمان ساختگی |
| Clinic Manager | `subscriber` | عضویت `cpms_manager` در Clinic ساختگی |
| Doctor | `subscriber` | عضویت Doctor، Clinician و Location ساختگی |
| Receptionist | `subscriber` | عضویت Secretary در Clinic/Location ساختگی |
| Accountant | `subscriber` | عضویت Accountant در Clinic ساختگی |
| Patient | نقش CPMS Patient | پیوند بیمار فقط پس از OTP عادی و برای بیمار ساختگی |

هر رمز به‌طور مستقل با CSPRNG ساخته می‌شود. رمز مشترک، Master Password، OTP ثابت، Bypass دائمی یا اختیار خودکار Administrator وجود ندارد.

### خواندن امن رمزها

State شامل رمزهاست و فقط باید به‌صورت محلی و با mode `0600` خوانده شود. این فایل را در PR، Issue، Log, Screenshot، Artifact یا پاسخ عمومی کپی نکنید:

```bash
stat -c '%a %n' "$CPMS_DISPOSABLE_STATE"  # باید 600 باشد
# فقط روی ترمینال محلیِ امن؛ خروجی را در هیچ گزارشی نگذارید:
less -- "$CPMS_DISPOSABLE_STATE"
```

برای دیدن فقط شناسه‌ها بدون رمز، می‌توانید روی همان ترمینال محلی از JSON استفاده کنید؛ حتی این خروجی را نیز عمومی نکنید. ابزار در stdout فقط شناسه‌های غیرمحرمانه، تعداد حساب‌ها، پیوندها و Shortcode را گزارش می‌کند.

## ۴) Shortcode و پیوندهای واقعی

Shortcode Production برای فهرست پزشکان و رزرو عمومی:

```text
[cpms_public_booking clinic_id="N"]
```

به‌جای `N` فقط `clinic_id` واقعیِ گزارش‌شده توسط Provisioner را بگذارید؛ آن را حدس نزنید. Provisioner یک برگهٔ عمومی با همین Shortcode واقعی می‌سازد. شناسهٔ Clinic پیش‌فرض bootstrap جایگزین Clinic ساختگی نمی‌شود.

پیوندهای واقعی در State ثبت می‌شوند:

- رزرو عمومی: پیوند برگهٔ ساخته‌شده با Shortcode بالا.
- Patient Portal: صفحهٔ `cpms-patient-portal`.
- Staff Portal canonical: صفحهٔ `cpms-staff-portal` با ماژول‌های `reception`، `doctor` و `finance`.
- Doctor Portal legacy: صفحهٔ `cpms-doctor-portal`؛ برای پزشک واجد شرایط به Staff Portal canonical هدایت می‌شود و برای دیگران سطح Denial واقعی را نشان می‌دهد.
- Management Portal: صفحهٔ `cpms-management-portal` با انتخاب صریح Organization/Clinic.

هیچ Shortcode یا Route آزمایشی جدیدی وجود ندارد. مسیرهای REST همان مسیرهای Production هستند.

## ۵) OTP و جریان واقعی رزرو

در مرورگر، روی برگهٔ رزرو عمومی اسلات را انتخاب کنید، شمارهٔ ساختگی را وارد کنید و **درخواست OTP را از UI واقعی بفرستید**. سپس، فقط برای Challenge تازهٔ همان بیمار ساختگی، ابزار بیرونی را اجرا کنید:

```bash
CPMS_DISPOSABLE_STATE="$CPMS_DISPOSABLE_STATE" \
CPMS_DISPOSABLE_OTP_FILE="$CPMS_DISPOSABLE_OTP_FILE" \
  wp eval-file tests/disposable-site/wp-cli/otp.php --use-include \
  --path="$WP_DIR" --user=existing_admin
```

این ابزار:

- فقط جدیدترین Challenge فعالِ همان Clinic و شمارهٔ ساختگی را با قفل و شرایط مصرف‌نشده/بدون Attempt پیدا می‌کند.
- یک کد شش‌رقمی CSPRNG می‌سازد و فقط Hash آن را با Pepper عادیِ سرور در همان Token می‌نویسد.
- کد را فقط در فایل محرمانهٔ `0600` می‌گذارد؛ آن را چاپ نمی‌کند.
- جایگزین احراز هویت نیست: مرورگر باید OTP را از طریق Route عادی Verify کند، سپس Hold و Confirm واقعی انجام شود و Token مصرف شود.

Runner مرورگر فایل OTP را بلافاصله پس از خواندن حذف می‌کند. هیچ `CPMS_DEV_OTP_ECHO`، کد ثابت یا Master Code مجاز نیست. در اجرای دستی نیز فایل OTP را پس از استفاده حذف کنید.

برای جلوگیری از SMS واقعی، Clinic ساختگی از Provider موجودِ `log` استفاده می‌کند؛ Provider یا Endpoint بیرونی تنظیم نکنید. پرداخت‌ها فقط با روش آفلاین `other` و بدون Gateway انجام می‌شوند. این محدودیت‌ها Production OTP یا پرداخت را تضعیف نمی‌کنند.

## ۶) آزمون مرورگر و بررسی‌ها

با State محرمانه و همان نصب:

```bash
BASE=http://localhost:8080 WP_DIR="$WP_DIR" OUT=/tmp/cpms-disposable-evidence \
ADMIN_USER=existing_admin \
CPMS_DISPOSABLE_STATE="$CPMS_DISPOSABLE_STATE" \
CPMS_DISPOSABLE_OTP_FILE="$CPMS_DISPOSABLE_OTP_FILE" \
  python3 tests/disposable-site/browser-acceptance.py
```

Runner برای هر Persona یک BrowserContext و Cookie Jar مستقل می‌سازد و این مسیرها را از UI/REST واقعی اجرا می‌کند:

1. ورود شش حساب، نمایش محدوده‌ها و Denial بیمار/کارکنان غیرمجاز.
2. Shortcode عمومی، Quote، درخواست و Verify عادی OTP، Hold، Confirm و Receipt.
3. انتخاب دقیق Clinic/Location در Reception، حضوریافت و ورود به صف.
4. Call/Start پزشک، یادداشت، شکایت اصلی، نسخهٔ غیرواقعی، Finalize و تأیید واقعی Complete.
5. صدور صورتحساب توسط Accountant با `invoice_id` واقعی، پرداخت آفلاین و Checkout توسط Reception.
6. بررسی مدیریت، RTL، موبایل، Overflow، تغییرات دیتابیس، زنجیرهٔ Audit و عدم ارسال SMS/پرداخت بیرونی.

سپس Verifier را اجرا کنید:

```bash
CPMS_DISPOSABLE_EXPECT=browser-complete \
  wp eval-file tests/disposable-site/wp-cli/verify.php --use-include \
  --path="$WP_DIR" --user=existing_admin
python3 tests/disposable-site/scan-evidence.py \
  --root /tmp/cpms-disposable-evidence \
  --state "$CPMS_DISPOSABLE_STATE" \
  --output /tmp/cpms-disposable-evidence/evidence-scan.json
```

اسکن اسناد فقط نشت رمزها و ابعاد Screenshot را بررسی می‌کند؛ این به معنای بازبینی بصری Pixel-by-Pixel نیست. رمزها، OTP، Cookie و HTML صفحه در نتایج ذخیره نمی‌شوند.

## ۷) Teardown — ترجیحاً نابودی کل محیط

بهترین Teardown حذف کل Container/VM و دیتابیس یک‌بارمصرف است. ابزار موجود برای همان سایتِ دارای Marker، پس از تأیید صریح، کل Database را Reset می‌کند و سپس State/OTP/Lock محرمانه را حذف می‌کند؛ هیچ حذف انتخابی بیمار یا Tenant انجام نمی‌دهد:

```bash
CPMS_DISPOSABLE_CONFIRM=DESTROY_DISPOSABLE_SITE_AND_DATABASE \
WP_DIR="$WP_DIR" ADMIN_USER=existing_admin \
CPMS_DISPOSABLE_STATE="$CPMS_DISPOSABLE_STATE" \
CPMS_DISPOSABLE_OTP_FILE="$CPMS_DISPOSABLE_OTP_FILE" \
  bash tests/disposable-site/teardown.sh
```

پس از آن Container/VM و فایل‌های WordPress یک‌بارمصرف را نیز نابود کنید. اگر Reset شکست بخورد، فایل‌های محرمانه عمداً باقی می‌مانند تا وضعیت پنهان یا گزارش موفقیت کاذب نشود.

## ۸) وضعیت شواهد و محدودیت‌های شناخته‌شده

وضعیت هر مورد باید از Run واقعیِ همان Head گزارش شود: **PASS / FAIL / PARTIAL / NOT EXECUTED**. تا ثبت Run معتبر، هیچ موردی را «قبول‌شده» نامیید.

| مورد | وضعیت اولیهٔ این سند |
|---|---|
| Lint، Compile و تست‌های Offline | در Run محلی ثبت می‌شود |
| ساخت ZIP و اثبات جدایی/Provenance | در CI همان Head ثبت می‌شود |
| WordPress واقعی و Schema/Prefixها | در CI همان Head ثبت می‌شود |
| مرورگر، شش حساب، OTP/Hold/Confirm، Workflowها | در CI همان Head ثبت می‌شود |
| Security/Denial/Isolation/Idempotency | در CI همان Head ثبت می‌شود |
| RTL/Mobile و Screenshot Evidence | در CI همان Head ثبت می‌شود |
| Teardown و حذف فایل‌های محرمانه | در CI همان Head ثبت می‌شود |
| Reproducible ZIP، Release رسمی، یا دانلود عمومی | ادعا نمی‌شود |

یافته‌های Baseline زیر عمداً پنهان یا با دادن مجوز اضافی «رفع» نشده‌اند:

- اختیار Doctor در Void/Refund همچنان موضوع Baseline است و این ابزار آن را تغییر نمی‌دهد.
- شکاف همگام‌سازی Clinician/Location در Walk-in شناخته‌شده است؛ ابزار از تخصیص صریح Location استفاده می‌کند و ادعای رفع عمومی ندارد.
- محدودهٔ OTP در Patient Portal مستقلِ چند-Clinic شناخته‌شده است؛ این Fixture تک‌Clinicِ ساختگی را هدف می‌گیرد، نه اثبات رفع آن نقص را.
- MariaDB 11.8 پشتیبانی‌شده نیست؛ نتیجهٔ MySQL/نسخه‌های پشتیبانی‌شده را به آن تعمیم ندهید.
- بایت‌های ZIP لزوماً Reproducible نیستند؛ SHA-256 فقط برای Artifact دقیقِ گزارش‌شده معتبر است.

این ابزار وابسته به Production است، اما Production برای کار عادی به آن وابسته نیست. هیچ Hook انقضا، Demo Runtime یا تغییر رفتار لازم برای آزمون در افزونه باقی نمانده است.
