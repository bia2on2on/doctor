<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Application\Onboarding\FirstPracticeSetupException;
use ClinicCore\Application\Onboarding\FirstPracticeSetupService;
use ClinicCore\Bootstrap\App;

/**
 * «راه‌اندازی مطب من» — Simple and Secure First-Clinic Onboarding (Slice 3A).
 *
 * One server-rendered WordPress setup step for a single-doctor practice:
 *   1. Start "Set up my practice."
 *   2. Confirm practice identity (explicit, server-derived Clinic identity —
 *      the posted Clinic selector is never trusted on its own).
 *   3. Enter essential practice information (Unicode practice name + optional
 *      contact fields).
 *   4. Establish authorized management (one-shot bootstrap → existing built-in
 *      `cpms_manager` membership; no additional management role, no doctor
 *      professional identity).
 *   5. Continue to existing staff management (Users & Access, `cpms-staff`).
 *
 * Security: entry requires a WordPress administrator (`manage_options`) and a
 * valid nonce; every write goes through FirstPracticeSetupService which re-checks
 * durable one-shot eligibility under the Clinic row lock. WP administrator
 * status alone never grants clinical-record access or authority across all
 * Clinics. No SPA, no frontend framework, no parallel authorization engine.
 */
final class FirstPracticeSetupPage
{
    public const PAGE_SLUG = 'cpms-first-practice-setup';

    private const NONCE_ACTION = 'cpms_first_practice_setup';
    private const NOTICE_KEY   = 'cpms_first_practice_setup_notice';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_cpms_first_practice_setup', [self::class, 'handle']);
    }

    public static function menu(): void
    {
        add_submenu_page(
            CpmsAdminMenu::parentSlug(),
            __('راه‌اندازی مطب من', 'cpms'),
            __('راه‌اندازی مطب من', 'cpms'),
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('دسترسی ندارید', 'cpms'), 403);
        }

        $notice   = self::pull_notice();
        $service  = App::first_practice_setup_service();
        $candidate = $service->first_setup_candidate();

        echo '<div class="wrap" dir="rtl">';
        echo '<h1>' . esc_html__('راه‌اندازی مطب من', 'cpms') . '</h1>';

        if ($notice !== null) {
            $type = ($notice['type'] ?? 'info') === 'success' ? 'success' : 'error';
            echo '<div class="notice notice-' . esc_attr($type) . ' inline"><p>' . esc_html((string) ($notice['message'] ?? '')) . '</p></div>';
        }

        echo '<div class="notice notice-info inline"><p>';
        echo esc_html__('این یک اقدام یک‌باره برای اولین مطب است: مدیریت مجاز همین مطب ساخته می‌شود (نقش مدیر مطب) و وضعیت Administrator وردپرس به‌تنهایی هیچ دسترسی بالینی یا مدیریت همهٔ مطب‌ها را نمی‌دهد. هویت پزشکی به‌صورت خودکار ساخته نمی‌شود.', 'cpms');
        echo '</p></div>';

        if ($candidate === null) {
            echo '<div class="notice notice-warning inline"><p>';
            echo esc_html__('راه‌اندازی اولیه در دسترس نیست. این مطب قبلاً راه‌اندازی شده است یا نصب در حالت تک‌مطبِ اولیه نیست. برای مدیریت پرسنل از صفحهٔ «کاربران و دسترسی‌ها» استفاده کنید.', 'cpms');
            echo '</p><p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=cpms-staff')) . '">';
            echo esc_html__('ادامه به کاربران و دسترسی‌ها', 'cpms') . '</a></p></div>';
            echo '</div>';

            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="cpms_first_practice_setup" />';
        wp_nonce_field(self::NONCE_ACTION);
        // Explicit Clinic confirmation: the server-derived identity is displayed
        // and posted back; missing/zero/forged selectors are denied server-side.
        echo '<input type="hidden" name="clinic_id" value="' . esc_attr((string) $candidate['clinic_id']) . '" />';

        echo '<h2>' . esc_html__('۱. تأیید هویت مطب', 'cpms') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('سازمان', 'cpms') . '</th><td>' . esc_html($candidate['organization_name']) . '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__('مطب', 'cpms') . '</th><td>' . esc_html($candidate['clinic_name']) . ' (' . esc_html($candidate['clinic_slug']) . ')</td></tr>';
        echo '<tr><th scope="row">' . esc_html__('تأیید', 'cpms') . '</th><td><label>';
        echo '<input type="checkbox" name="confirm_identity" value="1" required /> ';
        echo esc_html__('تأیید می‌کنم که این مطبِ من است و می‌خواهم مدیریت مجاز همین مطب را برقرار کنم.', 'cpms');
        echo '</label></td></tr>';
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('۲. اطلاعات اصلی مطب', 'cpms') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="cpms_fp_name">' . esc_html__('نام مطب', 'cpms') . '</label></th><td>';
        echo '<input type="text" class="regular-text" id="cpms_fp_name" name="practice_name" value="' . esc_attr($candidate['clinic_name']) . '" required maxlength="190" />';
        echo '<p class="description">' . esc_html__('نام مطب به هر زبان (Unicode)؛ نام فارسی و نیم‌فاصله بدون تغییر حفظ می‌شوند.', 'cpms') . '</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="cpms_fp_phone">' . esc_html__('تلفن', 'cpms') . '</label></th><td>';
        echo '<input type="text" class="regular-text" id="cpms_fp_phone" name="phone" value="" maxlength="32" />';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="cpms_fp_address">' . esc_html__('نشانی', 'cpms') . '</label></th><td>';
        echo '<input type="text" class="regular-text" id="cpms_fp_address" name="address" value="" maxlength="255" />';
        echo '</td></tr>';
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('۳. برقراری مدیریت مجاز', 'cpms') . '</h2>';
        echo '<p>' . esc_html__('با ثبت، شما به‌عنوان «مدیر مطب» همین مطب مجاز می‌شوید؛ سپس می‌توانید پزشک و منشی را از صفحهٔ کاربران و دسترسی‌ها اضافه کنید.', 'cpms') . '</p>';
        echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__('راه‌اندازی مطب من', 'cpms') . '</button></p>';
        echo '</form>';

        echo '</div>';
    }

    public static function handle(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('دسترسی ندارید', 'cpms'), 403);
        }
        if (
            !isset($_POST['_wpnonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), self::NONCE_ACTION)
        ) {
            self::push_notice('error', __('درخواست نامعتبر است؛ دوباره تلاش کنید.', 'cpms'));
            self::redirect();
        }

        $actor     = (int) get_current_user_id();
        $clinic_id = isset($_POST['clinic_id']) ? absint($_POST['clinic_id']) : 0;
        $confirmed = isset($_POST['confirm_identity']) && (string) wp_unslash($_POST['confirm_identity']) === '1'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- exact literal comparison below
        $name      = isset($_POST['practice_name']) ? sanitize_text_field(wp_unslash($_POST['practice_name'])) : '';
        $phone     = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : null;
        $address   = isset($_POST['address']) ? sanitize_text_field(wp_unslash($_POST['address'])) : null;

        if (!$confirmed) {
            self::push_notice('error', __('تأیید هویت مطب الزامی است.', 'cpms'));
            self::redirect();
        }

        try {
            $result = App::first_practice_setup_service()->claim(
                $actor,
                $clinic_id,
                $name,
                ($phone !== null && $phone !== '') ? $phone : null,
                ($address !== null && $address !== '') ? $address : null
            );
        } catch (FirstPracticeSetupException $e) {
            self::push_notice('error', self::message_for($e->reason_code()));
            self::redirect();
        } catch (\Throwable) {
            self::push_notice('error', __('راه‌اندازی انجام نشد؛ هیچ تغییری ثبت نشد. دوباره تلاش کنید.', 'cpms'));
            self::redirect();
        }

        self::push_notice(
            'success',
            sprintf(
                /* translators: %s: practice name. */
                __('مطب «%s» با موفقیت راه‌اندازی شد و مدیریت مجاز شما برقرار شد. اکنون می‌توانید پزشک و منشی اضافه کنید.', 'cpms'),
                $name
            )
        );

        // Continue to existing staff management (product contract step 5).
        wp_safe_redirect(admin_url('admin.php?page=cpms-staff&cpms_first_setup=done'));
        exit;
    }

    private static function message_for(string $reason_code): string
    {
        return match ($reason_code) {
            FirstPracticeSetupException::CODE_ALREADY_CLAIMED =>
                __('این مطب قبلاً راه‌اندازی شده است؛ راه‌اندازی اولیه فقط یک بار مجاز است.', 'cpms'),
            FirstPracticeSetupException::CODE_NOT_AVAILABLE =>
                __('راه‌اندازی اولیه برای این مطب در دسترس نیست (مطب قبلاً مدیریت شده است یا نصب چندمطبی است).', 'cpms'),
            FirstPracticeSetupException::CODE_INVALID_CLINIC =>
                __('انتخاب مطب نامعتبر است؛ لطفاً از صفحهٔ راه‌اندازی دوباره اقدام کنید.', 'cpms'),
            FirstPracticeSetupException::CODE_NOT_ADMINISTRATOR =>
                __('فقط Administrator وردپرس می‌تواند راه‌اندازی اولیه را آغاز کند.', 'cpms'),
            FirstPracticeSetupException::CODE_INVALID_INPUT =>
                __('ورودی نامعتبر است؛ نام مطب باید متن معتبر Unicode و کوتاه‌تر باشد.', 'cpms'),
            default =>
                __('راه‌اندازی انجام نشد؛ هیچ تغییری ثبت نشد. دوباره تلاش کنید.', 'cpms'),
        };
    }

    private static function redirect(): void
    {
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    /** @param array{type:string, message:string} $notice */
    private static function push_notice(string $type, string $message): void
    {
        set_transient(self::NOTICE_KEY . '_' . (int) get_current_user_id(), ['type' => $type, 'message' => $message], 60);
    }

    /** @return array{type:string, message:string}|null */
    private static function pull_notice(): ?array
    {
        $key    = self::NOTICE_KEY . '_' . (int) get_current_user_id();
        $notice = get_transient($key);
        if (is_array($notice)) {
            delete_transient($key);

            return $notice;
        }

        return null;
    }
}
