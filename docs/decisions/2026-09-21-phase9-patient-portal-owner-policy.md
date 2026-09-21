# سیاست‌های صادرشدهٔ مالک — Phase 9 Patient Portal و کانال‌های اعلان (OWNER-ISSUED PRODUCT POLICY)

تاریخ: 2026-09-21 · دامنه: Phase 9 (Patient Portal) + کانال‌های اعلان · وضعیت: صادرشده توسط مالک (Owner-issued) · ثبت: فقط مستندات (PR closure مستنداتِ Phase 9 Slice 2 / PR #96)

این سند سیاست‌های جدیدِ صریحِ مالک را به‌طور ماندگار ثبت می‌کند. این‌ها **سیاستِ
صادرشدهٔ مالک (OWNER-ISSUED PRODUCT POLICY)** هستند — **نه الزاماتِ اصلیِ SRS**.
متون تاریخیِ SRS عمداً بازنویسی نمی‌شوند تا وانمود شود این تصمیم‌ها از ابتدا
الزامِ SRS بوده‌اند؛ همان تاریخچه حفظ می‌شود و این سند سیاستِ فعلیِ عملیاتی را
روایت می‌کند (همان الگوی `2026-09-20-phase8-slice2-owner-decisions.md`).

این ثبت به‌تنهایی **شروعِ پیاده‌سازی، تأیید UX، یا ادعای تکمیل نیست** — پوستهٔ
Patient Portal با این closure شروع نشده است.

---

## A — هویتِ Portal (Portal identity)

1. **Only the Admin portal should visually reside inside WordPress wp-admin.**
2. **Patient Portal must use an independent professional CPMS frontend shell.**
3. Patient-facing UI must **not** expose:
   - WordPress admin sidebar,
   - admin toolbar/chrome,
   - WordPress update notices,
   - WordPress admin footer,
   - WordPress version strings.
4. Future non-admin portals should follow the independent-product principle unless
   a later owner policy changes it.

## B — کیفیت بصری (Visual quality)

1. Portal UI must be **professional, simple, Persian/RTL, responsive and
   commercially presentable**.
2. **Final visual appearance of user-facing portals requires owner visual approval.**
3. **Functional acceptance of PR #96 is NOT final visual-design approval.**
4. Do **not** interpret this policy as requiring endless redesign cycles:
   **one bounded visual review, then fix only concrete owner feedback.**

## C — ترتیبِ اجراییِ بعدیِ Phase 9 (Next Phase 9 ordering)

1. **BEFORE** adding major Patient Portal sections such as **Profile, Visits,
   Prescriptions or Files**, establish the **independent professional Patient
   Portal shell/design foundation**.
2. The shell should be **simple, fast and maintainable**.
3. WordPress may remain the underlying platform, but patient UI must **not
   visually resemble wp-admin**.
4. Prefer short/simple implementation and reuse before abstraction.
5. Do **not** introduce React/Vue/build tooling without objective need.

## D — کانال‌های اعلان (Notification channels)

**CURRENT VERSION:**

- Internal Patient Portal notifications.
- SMS.

**FUTURE VERSION:**

1. Optional external channels may include **Telegram** and **Iranian messaging
   platforms**.
2. External channels require **explicit patient consent/preferences**.
3. Notification **event/domain logic must remain separable** from delivery
   transport/provider.
4. Do **not** prematurely add provider classes/tables/settings/schema until that
   future requirement is implemented.

## E — جهت مهندسی (Engineering direction)

1. Prefer the **shortest clear professional implementation** before adding
   abstractions.
2. Preserve future extensibility **without speculative complexity**.
3. Maintain compatibility with the repository-supported **WordPress/PHP matrix**.
4. User-facing portals must remain **responsive and performance-aware**.
5. Admin customization should be strong where a **real product setting** is
   required, but do not add speculative settings merely for configurability.

---

## مرزِ این ثبت (Scope of this record)

- **فقط مستندات:** هیچ کد محصول، تست، workflow، asset، schema، migration،
  configuration یا رفتار runtime با این ثبت تغییر نکرد.
- **شروع کار نیست:** پوستهٔ مستقلِ Patient Portal و هیچ کانالِ اعلانِ جدیدی با
  این سند آغاز/مجاز نمی‌شود؛ اجرای هر مورد در آینده با scope/PR جداگانه و طبق
  ترتیبِ بخش C انجام می‌شود.
- **وضعیت فاز بدون تغییر از نظرِ تکمیل:** Phase 9 = IN PROGRESS (کامل نیست)؛
  Slice 1 = CLOSED؛ Slice 2 = CLOSED / TECHNICALLY COMPLETE (BOUNDED) via PR #96
  (merge `c18c18f4a33e3690d5002f6b21d56ad60334cdde`؛ accepted head
  `0f17aa2e562dd45b2bc0f1bfdd19b1895a093940`؛ آخرین migration همچنان `0022`).

---
---

# بخش دوم — قرارداد نهایی معماری پورتال + اثبات فنیِ پوستهٔ مستقل
(OWNER-ISSUED PRODUCT/ARCHITECTURE POLICY — 2026-09-21)

تاریخ: 2026-09-21 · صادرشده توسط مالک (OWNER-ISSUED) · دامنه: Phase 9 (Patient Portal)
و معماریِ آیندهٔ پورتال‌ها · ثبت: همین سند (یک محلِ تصمیم — بدون تکرار در اسناد دیگر).

این بخش **قرارداد نهایی معماریِ صادرشدهٔ مالک** را ثبت می‌کند. این‌ها
**OWNER-ISSUED PRODUCT/ARCHITECTURE POLICY** هستند — **نه الزاماتِ اصلیِ SRS**.
متون تاریخیِ SRS عمداً بازنویسی/تغییر داده نمی‌شوند (همان انضباطِ بخش اول).
طبق `2026-09-05-document-precedence-policy.md`، «تصمیمات نهاییِ کارفرما» بالاترین
اولویتِ سندی را دارند.

**نام قرارداد (نقل‌قولِ صریحِ مالک):**

> **"Three Environments + One CPMS Core + Shared WordPress Authentication
> Foundation + Independent CPMS Portals + Hybrid Rendering + Server-side
> Authorization."**

این ثبت **شروعِ پیاده‌سازی نیست**: پورتال بیمار، Staff Portal، migration/0023،
REST جدید، authentication/token جدید، و هیچ framework کلاینتی با این سند
آغاز/مجاز نمی‌شوند.

## F — THREE ENVIRONMENTS (سه محیط)

1. **Patient Portal**
   - independent CPMS presentation (ارائهٔ مستقلِ CPMS)؛
   - فقط navigation/قابلیت‌های Patient؛
   - بدون chromeِ wp-admin؛
   - بدون navigationِ Staff/Admin.
2. **Staff Portal**
   - پورتالِ عملیاتیِ مستقلِ CPMS در آینده؛
   - role/capability/scope-aware؛
   - **در Phase 9 پیاده‌سازی نمی‌شود**؛
   - navigation/authorization بیمار و کارمند باید **جدا** بمانند.
3. **WordPress Admin**
   - Administration سیستم؛
   - پیکربندیِ افزونه؛
   - maintenance؛
   - Administration فنیِ WordPress؛
   - **مقصد روزانهٔ بلندمدتِ پورتالِ Patient/Staff نیست**.

## G — PATIENT PORTAL SHELL (تصمیم مالک)

**OWNER DECISION:** Patient Portal **باید** از یک **standalone CPMS presentation
shell** استفاده کند.

این پوسته مالکِ این‌هاست:

- Layout؛
- Header؛
- Navigation؛
- Footer؛
- design shell / هویت بصری.

تمِ فعالِ WordPress **نباید** موارد زیر را برای Patient Portal تعیین کند:

- header؛
- footer؛
- layout؛
- navigation؛
- visual shell.

**تغییرِ تمِ فعال نباید layout/ارائهٔ Patient Portal را مادّاً تغییر دهد.**
Theme فقط **host/runtime environment** وردپرس است.

**بدونِ (No):** WP Admin sidebar؛ WP Admin toolbar/chrome؛ WP update notices؛
WP admin footer/version؛ theme header/footer.

## H — PRESENTATION INDEPENDENCE ONLY (فقط استقلالِ ارائه)

**backend موازی ساخته نشود.** استفاده مجدد از:

- CPMS Core؛
- Services؛
- Repositories؛
- AuthorizationService؛
- ScopeContext؛
- REST؛
- WordPress user/session foundation.

## I — HYBRID RENDERING (رندرِ ترکیبی)

معماری همچنان:

**SERVER-RENDERED BY DEFAULT** + **REST/AJAX فقط برای تعاملاتی که محصول را
بهتر می‌کنند.**

این تصمیم **مجوز نمی‌دهد**: SPA؛ client router؛ frontend framework جدید؛
AJAX-everything.

## J — AUTHENTICATION (احراز هویت)

**Browser portal:**

- پایهٔ نشستِ موجودِ WordPress؛
- nonce موجودِ `wp_rest` در مواردِ مربوطه.

**تمایز ماندگار (Important durable distinction):**

- nonceِ `wp_rest` **فقط یک قراردادِ پیاده‌سازیِ BROWSER** است؛
- **قراردادِ احراز هویتِ آیندهٔ Android/iOS نیست**.

**Native-client authentication: UNRESOLVED FUTURE ARCHITECTURE.** اکنون طراحی
یا پیاده‌سازی نشود.

## K — STAFF FUTURE SAFETY (آینده‌نگریِ ایمنِ Staff)

بنیادِ بصری ممکن است بعداً برای Staff Portal قابل استفادهٔ مجدد باشد:

- tokens؛
- shell primitives؛
- UI primitives کوچک.

اما Patient و Staff **نباید** مشترکاً داشته باشند:

- navigation authority؛
- role assumptions؛
- authorization decisions؛
- tenant authority؛
- sensitive views.

**پیاده‌سازیِ Staff Portal اکنون ممنوع.**

## L — ROUTING / PRESENTATION DECISION (تصمیم نهاییِ روتینگ و ارائه)

پرسشِ باز (از تحلیلِ قبلی): آیا Page + shortcode کافی است؟ — با توجه به اینکه
تم نباید ارائه را کنترل کند، **مستقلاً** تعیین تکلیف شد:

**FINDING — Page + shortcode alone: INSUFFICIENT.**

- خروجیِ shortcode داخل `the_content` و **درون قالبِ تمِ فعال** (`page.php` /
  `index.php` → `get_header()`/`get_footer()`) رندر می‌شود؛
- header/footer/layout/پوسته ساختاراً در کنترلِ تم می‌مانند و جابه‌جاییِ تم،
  پوسته را مادّاً عوض می‌کند — نقضِ صریحِ بخش G؛
- shortcode حتی نمی‌تواند «سند کامل» بسازد: پیش از اجرای `the_content`، تم
  header را چاپ کرده است.

**DECISION (final):**

**Pageِ متعلق به CPMS + رهگیریِ مالکانهٔ افزونه روی `template_include` + قالبِ
standaloneِ متعلق به افزونه که کل سند HTML را می‌سازد.**

1. **Routing = یک Pageِ معمولیِ وردپرس** (مقصد/هویتِ پورتال با یک Pageِ
   CPMS-owned؛ شناساییِ درخواست از خودِ queried object — نه از شکلِ URL).
   - در **Plain** (`?page_id=N` / `?pagename=slug`) و **Pretty**
     (`/slug/`) توسط main queryِ خودِ core resolve می‌شود؛
   - **بدون هیچ rewrite rule/rewrite framework/query-var/router سفارشی** —
     صفر زیرساختِ روتینگِ حدسی (speculative).
2. **Presentation = فیلتر رسمیِ `template_include`** (مکانیسمِ پایدارِ WordPress
   از نسخه‌های بسیار قدیمی core؛ بدون `exit`، بدون دست زدن به lifecycle):
   کوکی/نشست/کاربر/is_admin/REST nonce همه مثل هر درخواستِ نرمالِ وردپرس در
   دسترس‌اند؛ قالبِ مالکِ CPMS با `<!DOCTYPE html>` شروع و با `</html>` تمام
   می‌شود و عمداً `get_header()`/`get_footer()`/`wp_head()`/`wp_footer()` را
   صدا نمی‌زند.
3. **چرا نه گزینه‌های دیگر (ثبت شده، برای جلوگیری از بازگشاییِ بی‌دلیل):**
   - Page + shortcode alone → ناکافی (بالا)؛
   - `template_redirect` + `echo` + `exit` → کارکرد مشابه ولی `exit` زودهنگام
     تست‌پذیری/انضباطِ shutdown را بدتر می‌کند و چیزی نمی‌افزاید؛
   - custom rewrite endpoint → نقضِ «بدون rewrite framework»؛ زیر Plain هم
     شکننده/نیازمند fallback است؛
   - REST endpoint که HTML برگرداند → REST قراردادِ JSON است؛ نقضِ «no REST
     endpoints» برای این اثبات و مدلِ URL اشتباه؛
   - page template داخلِ خودِ تم → استقلال از تم را ساختاراً نقض می‌کند (با
     جابه‌جاییِ تم نابود می‌شود).

این تصمیمِ روتینگ/ارائه، تصمیمِ نهایی برای Phase 9 Slice 3 است (پیاده‌سازیِ
خودِ Slice 3 با scope/PR جداگانه).

## M — TECHNICAL PROOF (اثبات فنیِ Theme Independence) — دامنه و شواهد

**ماهیت: NON-PRODUCTION / test-fixture scoped.** هیچ seam تولیدیِ جدیدی معرفی
نشد (ضرورتِ عینی نداشت): الگوی اثبات‌شده (بخش L) عیناً الگویی است که Slice 3
در scope خودش پیاده خواهد کرد. **هیچ محتوای Patient Portal پیاده‌سازی نشده**
(حداکثر shell marker/fixture). `PatientPortalPage` موجود دست نخورده است.

**فایل‌های proof (همگی زیر `tests/` و `bin/` ابزار تست — هرگز داخل release ZIP
با whitelist سیاستِ `build-release.sh`):**

| فایل | نقش |
|---|---|
| `tests/Fixtures/standalone-shell/cpms-standalone-shell-proof.php` | رهگیرِ `template_include` + `show_admin_bar` (fixture؛ بارگذاری: Integration require یا mu-plugin در محیطِ یک‌بارمصرفِ Acceptance) |
| `tests/Fixtures/standalone-shell/cpms-standalone-shell-proof-template.php` | قالبِ standalone — کل سند HTML + نشانگرهای قرارداد + JSON config (`rest_root`، `users_me_url`، nonce `wp_rest`، `logout_url`، `user` — بدون PHI) |
| `tests/Fixtures/standalone-shell/themes/cpms-proof-theme-alpha/**` | تمِ کنترلیِ A (LTR/سرامیکیِ تیره/سایدبار) با نشانگرهای `CPMS-PROOF-THEME-ALPHA-*` |
| `tests/Fixtures/standalone-shell/themes/cpms-proof-theme-beta/**` | تمِ کنترلیِ B (RTL/نوار بالای کهربایی/ستون میانی) — مادّاً متفاوت از A |
| `tests/Integration/StandaloneShellProofTest.php` | proof تستِ in-process (گیتِ Integration) |
| `bin/rwp-shell-proof.py` | proof مرورگریِ واقعی (Playwright/Chromium — گیتِ Real WordPress Acceptance) |
| `.github/workflows/real-wp-acceptance.yml` | نصبِ فیکسچر + اجرای proof (شامل انتشارِ لاگ در commit comment) |
| `.github/workflows/ci.yml` | شاهدِ صریحِ «انتخاب + اجرا» برای `StandaloneShellProofTest` در junit (الحاقی؛ بدون تغییرِ معیارِ گیت) |

**ادعاهای A–H و محلِ اثبات (متناظر با «Preferred outcome»):**

| # | ادعا | اثبات |
|---|---|---|
| A | درخواستِ نرمال به WordPress می‌رسد | هر دو: Resolutionِ صفحه با main query (`StandaloneShellProofTest`) + HTTP 200 واقعی روی Apache (`rwp-shell-proof.py`) |
| B | نشست/کاربرِ وردپرس در دسترس است | هر دو: `CPMS-SHELL-PROOF-USER:{login}` + nonce `wp_rest` + REST موجودِ `wp/v2/users/me` با همان کوکی/نشست (۲۰۰ + همان هویت؛ کنترلِ منفیِ بدون nonce → ۴۰۱) + logout واقعی (`wp_logout_url`) → `anonymous` |
| C | CPMS کل سند/پوسته را کنترل می‌کند | هر دو: `<!DOCTYPE html>` تا `</html>` + `data-cpms-standalone-shell="proof-fixture"` + حضورِ runtime افزونه (`CPMS-SHELL-PROOF-RUNTIME-LOADED`) |
| D | header/footer تمِ فعال غایب‌اند | هر دو: نبودِ نشانگرهای هر دو تم + **positive control**: همان نشانگرها وقتی تم رندر می‌شود دیده می‌شوند (ادعا vacuous نیست) |
| E | chromeِ wp-admin غایب است | هر دو: نبودِ `wpadminbar` / `wp-admin-bar-` / `adminmenu` / `wpfooter` (درخواست front-end است و `show_admin_bar` هم فقط روی همین صفحه false می‌شود) |
| F | نشانگرِ پوستهٔ CPMS حاضر است | هر دو: `CPMS-STANDALONE-SHELL-PROOF` + `data-shell-contract="v1"` |
| G | جابه‌جاییِ دو تمِ متفاوت، قراردادِ پوسته را عوض نمی‌کند | هر دو: برابریِ contract fingerprint زیر alpha/beta (شامل برابری زیر login/logout) |
| H | Plain و Pretty هر دو کار می‌کنند | **مرجع: مرورگریِ واقعی** — ماتریس `{Plain, Pretty} × {alpha, beta}` با `wp rewrite structure` واقعی + استنباطِ خود-اعتباربخشِ حالت از `rest_root` و شکلِ URL (`rwp-shell-proof.py`)؛ شواهدِ in-processِ سطح query در `StandaloneShellProofTest` (هر دو شکلِ query-var + مسیرِ Pretty) به‌عنوان پشتیبان |

**Performance / global footprint (حفظِ قرارداد):**

- اثرِ هوکیِ سراسریِ fixture دقیقاً **دو فیلترِ شرطی** است
  (`template_include` + `show_admin_bar`) — تستِ مستقیمِ footprint در
  `StandaloneShellProofTest::test_hook_footprint_is_two_conditional_filters_only`؛
- **بدون asset سراسری / بدون frontend bundle / بدون polling / بدون `wp_head`/
  `wp_footer` / بدون کوئریِ اضافه** — صفحاتِ غیرمرتبط هیچ نشانگر/asset از
  پوسته نمی‌گیرند (assert شده روی home در `rwp-shell-proof.py`)؛
- صفحاتِ authenticated/private پورتال **هرگز public-cacheable تلقی نمی‌شوند**
  (در مستند Slice 3 هم رعایت شود)؛
- شرطِ asset برای Slice 3: asset فقط صفحهٔ پورتال، فقط در صفحهٔ پورتال.

**Compatibility (صداقتِ شواهد):**

- انتخابِ مکانیسم با سازگاریِ WP 6.4+ و PHP 8.1–8.4 سازگار است (فقط APIهای
  قدیمیِ core: `template_include`, `is_page`, `wp_get_current_user`,
  `wp_create_nonce`, `rest_url` — بدون وابستگی به نسخهٔ خاص)؛
- **RUNTIME-PROVEN فقط روی محیطِ exact-head گیت‌ها: WordPress 6.7.2 + PHP 8.2 +
  Apache/mod_php + MySQL 8 + Chromium** (Real WordPress Acceptance؛ Integration
  روی WP 6.7.2 + PHP 8.2)؛
- **NOT VERIFIED (ثبت صادقانه — استنتاج ≠ اثباتِ اجرا):** اجرای runtimeِ همین
  fixture روی WP 6.4/6.5/6.6 و PHP 8.1/8.3/8.4 — **NOT RUN** (Closure Gate
  عمداً فقط release ZIP را می‌آزماید و fixture در ZIP نیست)؛ سازگاری روی آن
  ترکیب‌ها فعلاً **argument از ثباتِ API core** است، نه بازتولیدِ runtime؛
- **NOT VERIFIED:** مرورگرهای غیر از Chromium (ابزارِ ریپو فقط Chromium)؛
  روتینگِ Pretty روی سرورهای غیر Apache (محیطِ اثبات = `mod_rewrite` + `.htauth`).

**قراردادِ احراز هویت (یادآوریِ ماندگار):** nonce `wp_rest` فوق فقط قراردادِ
Browser است؛ **قراردادِ Android/iOS نیست** (بخش J — UNRESOLVED FUTURE).

**Readiness:** با پذیرشِ شواهدِ exact-head اثبات (در PR همین ثبت)، مبنای
معماری/اثبات برای شروعِ **Phase 9 Slice 3 (RED/TDD)** کامل است؛ خودِ Slice 3
**همچنان شروع نشده** و scope/PR جداگانه می‌خواهد. roadmap عمداً در این ثبت
تغییر نکرد.

*(annotationِ وضعیت — 2026-09-21، closure مستنداتِ Slice 3: بندِ Readiness بالا درستِ چک‌پوینتِ خودش بود و بازنویسی نشد؛ پس از آن **Phase 9 Slice 3 via PR #99 CLOSED / TECHNICALLY COMPLETE (BOUNDED)** شد — merge `8a324e59341851f5ced01ed37ecd5e69c725f30a`، accepted head `8d6231c19d3caccb94194836040f575a1566d345`؛ قرارداد/اثباتِ معماریِ همین سند (بخش دوم) **همچنان تصمیمِ حاکم** است؛ Phase 9 همچنان IN PROGRESS؛ هیچ Slice بعدی شروع نشده.)*

## مرزِ بخش دوم (Scope of Part 2)

- **سندِ تصمیم + proof فیکسچر/تست/هارنسِ bounded** — نه پیاده‌سازی محصول؛
  بدون تغییرِ `src/`، بدون migration/`0023`، بدون REST جدید، بدون auth/token
  جدید، بدون framework/build tooling کلاینتی، بدون تغییرِ ترتیب/وضعیت roadmap.
- **ادعای سبز در این سند نیست** (انضباطِ شواهد: NOT RUN ≠ PASS؛ Inference ≠
  fact). شواهدِ گیتِ exact-head در بدنهٔ PR (run-idها) منتشر می‌شود؛ این سند
  فقط تصمیم + دامنه + برچسب‌های صادقانه را نگه می‌دارد.
