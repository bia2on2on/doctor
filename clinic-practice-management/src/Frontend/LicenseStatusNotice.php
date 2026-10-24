<?php

declare(strict_types=1);

namespace ClinicCore\Frontend;

use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Licensing\LicenseStatus;

/** Presentation-only notice: no authority or license document is passed to the browser. */
final class LicenseStatusNotice
{
    /** @param array{status:string,reason?:string} $state */
    public static function text(array $state): ?string
    {
        $status = $state['status'];
        if ($status === LicenseStatus::GRACE) {
            return 'مجوز در مهلت پس از انقضا است؛ تمدید آن را پیگیری کنید. ثبت نوبت و بیمار جدید در این وضعیت همچنان مجاز است و مراقبت بیماران موجود ادامه دارد.';
        }
        if ($status === LicenseStatus::RESTRICTED) {
            $reason = $state['reason'] ?? '';
            if ($reason === 'expired' || $reason === 'expired_unreachable') {
                return 'مهلت مجوز منقضی شده است؛ ثبت بیمار و نوبت جدید محدود است. ادامهٔ مراقبت بیماران موجود، امور مالی مجاز، خروجی و پشتیبان‌گیری و بازیابی طبق مجوزهای کاربری برقرار است.';
            }
            return 'مجوز معتبر برای فعالیت جدید تأیید نشده است؛ ثبت بیمار و نوبت جدید محدود است. وضعیت ممکن است ناشی از فعال‌سازی‌نشدن یا اشکال اعتبار مجوز باشد؛ با مدیر سامانه پیگیری کنید. دسترسی به سوابق و عملیات مجاز ادامه دارد.';
        }
        if ($status === LicenseStatus::INVALID) {
            return 'اعتبار مجوز تأیید نشده است؛ فعالیت تجاری جدید محدود است. با مدیر سامانه پیگیری کنید؛ عملیات مجاز برای سوابق موجود همچنان تابع کنترل دسترسی است.';
        }
        return null;
    }

    public static function render(): void
    {
        $text = self::text(App::licenseService()->currentState());
        if ($text !== null) {
            echo '<div class="cpms-license-notice" role="status" dir="rtl" style="margin:1rem 0;padding:1rem;border:2px solid #a66b15;border-radius:.5rem;background:#fff8e8;color:#382a13;line-height:1.8">'
                . esc_html($text) . '</div>';
        }
    }
}
