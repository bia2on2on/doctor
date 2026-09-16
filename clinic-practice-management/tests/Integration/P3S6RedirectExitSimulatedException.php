<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

/**
 * فرارِ کنترل‌شده از redirect() پایانیِ handlerهای admin-post در تست (زیرساخت تست).
 *
 * قرارداد تولیدی: مسیرهای write صفحهٔ Clinician (و مشابه‌ها) پس از ثبت notice
 * با `wp_safe_redirect()` + ‏`exit` تمام می‌شوند. در فراخوانی مستقیمِ داخل تست،
 * همان `exit` کل پروسهٔ PHPUnit را می‌کُشد — با کد خروج ۰ و بدون هیچ خلاصهٔ
 * پایانی (سازوکار false-green که در run 35103474350/35105290047 اثبات شد:
 * `php … | tee` + pipefail نمی‌تواند آن را از موفقیت واقعی تمیز دهد).
 *
 * الگوی مستقرِ مخزن (C7PreIntegrationBoundaryTest::dispatchAdminAction) برای
 * شبیه‌سازیِ exit همان فیلتر `wp_redirect` را به استثنا تبدیل می‌کند. تفاوت این
 * کلاس: عمداً از `\RuntimeException` ارث نمی‌برد — `ClinicianAdminPage::saveClinician()`
 * در بدنهٔ try خود `catch (\RuntimeException)` دارد و استثنای آن نوع را می‌بلعد و
 * به backWithError (یعنی دوباره redirect) تبدیل می‌کند. نوع استثنا باید توسط
 * کد تولیدی بلعیده نشود تا کنترل به تست برگردد.
 *
 * مصرف‌کننده: Phase3Slice6AuthorizationRedTest (RED-1). فیلتر در finally همان
 * تست remove می‌شود؛ هیچ state سراسری باقی نمی‌ماند.
 */
final class P3S6RedirectExitSimulatedException extends \Exception
{
}
