<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Application\Demo\DemoService;
use ClinicCore\Bootstrap\App;
use Throwable;

/** Administrator-only, nonce-protected demo lifecycle and one-time secrets UI. */
final class DemoSetupPage
{
    public const PAGE = 'cpms-demo';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 30);
    }

    public static function menu(): void
    {
        add_submenu_page(
            CpmsAdminMenu::parentSlug(),
            'Temporary CPMS Demo',
            'Temporary Demo',
            'manage_options',
            self::PAGE,
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        $actor_id = (int) get_current_user_id();
        App::demo_service()->assert_administrator($actor_id);
        $credentials = [];
        $otp = '';
        $notice = '';
        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            check_admin_referer('cpms_demo_action');
            $action = isset($_POST['demo_action']) ? sanitize_key(wp_unslash($_POST['demo_action'])) : '';
            try {
                switch ($action) {
                    case 'provision':
                        $ack = isset($_POST['ack']) && sanitize_text_field(wp_unslash($_POST['ack'])) === '1';
                        $result = App::demo_service()->provision($actor_id, $ack);
                        $credentials = $result['credentials'];
                        $notice = $result['created']
                            ? 'Demo created. Copy these passwords now: they are not stored and cannot be retrieved later.'
                            : 'A demo state already exists. No new identities or credentials were created.';
                        break;
                    case 'rotate':
                        $key = isset($_POST['account']) ? sanitize_key(wp_unslash($_POST['account'])) : '';
                        $credentials[$key] = App::demo_service()->rotate_password($actor_id, $key);
                        $notice = 'Password rotated and all existing sessions for this account were destroyed. Copy it now.';
                        break;
                    case 'otp':
                        $otp = App::demo_service()->latest_otp($actor_id);
                        break;
                    case 'end':
                        App::demo_service()->end($actor_id);
                        $notice = 'Demo access is revoked and the demo Clinic is suspended.';
                        break;
                    case 'cleanup':
                        $ack = isset($_POST['cleanup_ack']) && sanitize_text_field(wp_unslash($_POST['cleanup_ack'])) === '1';
                        if (!$ack) {
                            throw new \RuntimeException('Confirm deletion of demo-owned operational data first.');
                        }
                        App::demo_service()->cleanup($actor_id);
                        $notice = 'Demo users and operational rows were removed. Suspended tenant tombstones and append-only logs remain.';
                        break;
                    default:
                        throw new \RuntimeException('Unknown demo action.');
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $state = App::demo_service()->state();
        echo '<div class="wrap" dir="rtl"><h1>Temporary CPMS Classic Demo</h1>';
        echo '<p><strong>فقط برای سایت یک‌بارمصرف و دادهٔ کاملاً ساختگی.</strong> این امکان به‌صورت پیش‌فرض خاموش است و هیچ دسترسی میزبان، دورکاری یا رمز مشترک ندارد.</p>';
        if ($notice !== '') {
            echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';
        }
        if ($error !== '') {
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }
        self::render_credentials($credentials);
        if ($otp !== '') {
            echo '<div class="notice notice-info"><p>کد OTP آزمایشی بیمار (فقط همین پاسخ): <code data-demo-otp="1" style="font-size:1.5em">' . esc_html($otp) . '</code></p></div>';
        }

        if ($state === []) {
            self::render_provision_form();
            echo '</div>';
            return;
        }

        $status = (string) ($state['status'] ?? 'unknown');
        echo '<h2>وضعیت</h2><table class="widefat striped" style="max-width:900px"><tbody>';
        echo '<tr><th>Demo ID</th><td><code>' . esc_html((string) ($state['demo_id'] ?? '')) . '</code></td></tr>';
        echo '<tr><th>Status</th><td><code>' . esc_html($status) . '</code></td></tr>';
        echo '<tr><th>Clinic ID</th><td><code>' . (int) ($state['clinic_id'] ?? 0) . '</code></td></tr>';
        echo '<tr><th>Expires (UTC)</th><td><code>' . esc_html(gmdate('Y-m-d H:i:s', (int) ($state['expires_at'] ?? 0))) . '</code></td></tr>';
        echo '</tbody></table>';

        if ($status === 'active') {
            self::render_links($state);
            self::render_rotation_form($state);
            echo '<h2>OTP امن آزمایشی</h2><p>ابتدا در صفحهٔ رزرو/ورود بیمار با شمارهٔ ساختگی <code>09000000000</code> درخواست کد کنید؛ سپس با POST و nonce کد رمزنگاری‌شده را همین‌جا بخوانید. هیچ پیامک واقعی ارسال نمی‌شود.</p>';
            self::form_open();
            echo '<input type="hidden" name="demo_action" value="otp">';
            submit_button('خواندن آخرین OTP آزمایشی', 'secondary', 'submit', false);
            echo '</form>';
        }

        echo '<hr><h2>پایان Demo</h2><p>ابتدا وضعیت revoked ذخیره می‌شود؛ سپس همهٔ sessionهای شش حساب از بین می‌رود و Clinic معلق می‌شود. انقضا نیز همین مسدودسازی سروری را دارد.</p>';
        self::form_open();
        echo '<input type="hidden" name="demo_action" value="end">';
        submit_button('End Demo — revoke now', 'secondary', 'submit', false);
        echo '</form>';

        echo '<h2>پاک‌سازی دادهٔ Demo</h2><p>کاربران و دادهٔ عملیاتی مالکِ Demo حذف می‌شوند. رکوردهای tombstone سازمان/Clinic معلق و لاگ‌های append-only برای حفظ زنجیرهٔ ممیزی باقی می‌مانند؛ این ادعای پاک‌سازی کامل پایگاه‌داده نیست.</p>';
        self::form_open();
        echo '<input type="hidden" name="demo_action" value="cleanup">';
        echo '<label><input type="checkbox" name="cleanup_ack" value="1" required> تأیید می‌کنم فقط دادهٔ عملیاتی همین Demo حذف شود.</label><br><br>';
        submit_button('Delete demo-owned operational data', 'delete', 'submit', false);
        echo '</form></div>';
    }

    private static function render_provision_form(): void
    {
        echo '<h2>راه‌اندازی صریح</h2><p>شش حساب جدا با رمزهای تصادفی امن ساخته می‌شود. پنج حساب کارکنان Subscriber با عضویت CPMS محدود هستند؛ بیمار نقش موجود <code>cpms_patient</code> را دارد. Administrator به‌خودی‌خود مجوز بالینی/مالی نمی‌گیرد.</p>';
        echo '<p>این فرایند سازمان، Clinic، Location، پزشک، برنامه، بیمار، نوبت، ویزیت، نسخهٔ غیرواقعی و پرداخت ساختگیِ bounded می‌سازد و حداکثر ۳۰ روز معتبر است.</p>';
        self::form_open();
        echo '<input type="hidden" name="demo_action" value="provision">';
        echo '<label><input type="checkbox" name="ack" value="1" required> تأیید می‌کنم این سایت یک‌بارمصرف است، فقط دادهٔ ساختگی دارم/می‌سازم و رمزهای یک‌بارنمایش را امن نگه می‌دارم.</label><br><br>';
        submit_button('Create temporary demo', 'primary', 'submit', false);
        echo '</form>';
    }

    /** @param array<string, mixed> $state */
    private static function render_links(array $state): void
    {
        $clinic_id = (int) ($state['clinic_id'] ?? 0);
        echo '<h2>صفحات واقعی و راهنمای سریع</h2>';
        echo '<p>Shortcode دقیق doctor listing + public booking:</p><input data-demo-shortcode="1" class="regular-text code" readonly value="' . esc_attr('[cpms_public_booking clinic_id="' . $clinic_id . '"]') . '">';
        $links = [
            'Doctor listing + public booking' => (int) ($state['booking_page_id'] ?? 0),
            'Patient Portal (mobile + OTP)' => (int) get_option('cpms_patient_portal_page_id', 0),
            'Staff Portal — Reception / Doctor / Accountant' => (int) get_option('cpms_staff_portal_page_id', 0),
            'Management Portal — Organization / Clinic' => (int) get_option('cpms_management_portal_page_id', 0),
        ];
        echo '<ul>';
        foreach ($links as $label => $page_id) {
            $link_key = str_starts_with($label, 'Doctor') ? 'booking' : (str_starts_with($label, 'Patient') ? 'patient' : (str_starts_with($label, 'Staff') ? 'staff' : 'management'));
            if ($page_id > 0) {
                echo '<li><a href="' . esc_url((string) get_permalink($page_id)) . '" target="_blank" rel="noopener noreferrer" data-demo-link="' . esc_attr($link_key) . '">' . esc_html($label) . '</a></li>';
            } else {
                echo '<li>' . esc_html($label) . ': page unavailable</li>';
            }
        }
        echo '</ul><p>جزئیات کپی‌پیست و ترتیب سناریوها در <code>docs/demo-setup-fa.md</code> آمده است. نوبت آینده برای مسیر public booking است؛ ویزیت walk-in در انتظار، مسیر عملیاتی همان‌روز را بدون تغییر قواعد زمانی ممکن می‌کند.</p>';
    }

    /** @param array<string, mixed> $state */
    private static function render_rotation_form(array $state): void
    {
        echo '<h2>چرخش رمز یک حساب</h2><p>رمز قبلی قابل بازیابی نیست. چرخش، رمز تازهٔ CSPRNG می‌سازد و sessionهای موجود همان حساب را فوراً باطل می‌کند.</p>';
        self::form_open();
        echo '<input type="hidden" name="demo_action" value="rotate"><select name="account" required>';
        foreach ((array) ($state['users'] ?? []) as $key => $entry) {
            echo '<option value="' . esc_attr((string) $key) . '">' . esc_html((string) $key . ' — ' . (string) ($entry['username'] ?? '')) . '</option>';
        }
        echo '</select> ';
        submit_button('Rotate password', 'secondary', 'submit', false);
        echo '</form>';
    }

    /** @param array<string, array{username: string, password: string}> $credentials */
    private static function render_credentials(array $credentials): void
    {
        if ($credentials === []) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>Secrets — only in this response. Do not screenshot, log, commit, or paste into the PR.</strong></p></div>';
        echo '<table class="widefat striped" data-demo-credentials="1"><thead><tr><th>Account</th><th>Username</th><th>One-time password</th></tr></thead><tbody>';
        foreach ($credentials as $key => $entry) {
            echo '<tr data-demo-account="' . esc_attr((string) $key) . '"><td><code>' . esc_html((string) $key) . '</code></td><td><code class="demo-username">' . esc_html($entry['username']) . '</code></td><td><code class="demo-password">' . esc_html($entry['password']) . '</code></td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function form_open(): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::PAGE)) . '" autocomplete="off">';
        wp_nonce_field('cpms_demo_action');
    }
}
