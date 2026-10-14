<?php

declare(strict_types=1);

namespace ClinicCore\Frontend;

/**
 * Patient Portal's guest OTP login form. This is presentation/configuration
 * for the existing public OTP routes; authentication remains in OtpService.
 */
final class PatientOtpLogin
{
    private const REST_NAMESPACE = 'clinic/v1';
    private const OTP_REQUEST_PATH = '/otp/request';
    private const OTP_VERIFY_PATH = '/otp/verify';

    public static function render(): void
    {
        $id = function_exists('wp_unique_id') ? wp_unique_id('cpms-patient-otp-') : 'cpms-patient-otp-' . wp_rand(1000, 999999);
        $config = [
            'rest_root' => rtrim(rest_url(self::REST_NAMESPACE), '/'),
            'otp_request_path' => self::OTP_REQUEST_PATH,
            'otp_verify_path' => self::OTP_VERIFY_PATH,
        ];
        $json = wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($json)) {
            $json = '{}';
        }
        ?>
        <section class="cpms-patient-portal-shell__notice cpms-pp-login" data-role="portal-login">
            <h2>ورود به پورتال بیمار</h2>
            <p>شمارهٔ موبایل تأییدشدهٔ خود را وارد کنید تا کد یک‌بارمصرف را دریافت کنید.</p>
            <form class="cpms-pp-login__form" data-role="patient-otp-login" novalidate>
                <div class="cpms-pp-login__field">
                    <label for="<?php echo esc_attr($id); ?>-mobile">شمارهٔ موبایل</label>
                    <input id="<?php echo esc_attr($id); ?>-mobile" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" data-role="patient-otp-mobile" required>
                </div>
                <div class="cpms-pp-login__field" data-role="patient-otp-code-step" hidden>
                    <label for="<?php echo esc_attr($id); ?>-code">کد ۶ رقمی پیامک‌شده</label>
                    <input id="<?php echo esc_attr($id); ?>-code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" dir="ltr" data-role="patient-otp-code" required>
                </div>
                <div class="cpms-pp-login__actions">
                    <button type="button" class="cpms-pp-btn cpms-pp-btn--primary" data-role="patient-otp-request">دریافت کد ورود</button>
                    <button type="button" class="cpms-pp-btn cpms-pp-btn--primary" data-role="patient-otp-verify" hidden>تأیید و ورود</button>
                </div>
                <p class="cpms-pp-login__status" data-role="patient-otp-status" role="status" aria-live="polite" hidden></p>
            </form>
            <p class="cpms-pp-login__scope-hint">در نصب چند-Clinic، برای تعیین مرکز از مسیر رزرو و انتخاب یک نوبت اقدام کنید؛ این صفحه Clinic را از ورودی مرورگر نمی‌پذیرد.</p>
            <script type="application/json" class="cpms-patient-portal__auth-config"><?php echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with script-safe HEX flags. ?></script>
        </section>
        <?php
    }
}
