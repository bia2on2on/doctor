<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;

/**
 * «بیماران» — Patient Management Entry (Operational) — Chunk G.
 *
 * هدف: یک ورود عملیاتیِ حداقلی برای پرسنل مجاز، با استفادهٔ مجدد از backend موجود
 * («بک‌اند مرجع دست‌نخورده می‌ماند» — بدون منطق business تکراری).
 *
 *  - منو/دسترسی کاملاً Capability-محور: `cpms_patient_read` (gold rule — hidden menu ≠ authorize).
 *  - در IA فعلی، «بیماران» به‌عنوان منوی Top-Level نقش‌محور ثبت می‌شود (همان الگوی
 *    «صف امروز»، «امروز پزشک») تا منشی/پزشک/مدیرِ دارای `cpms_patient_read` به آن برسند.
 *    (اگر PO بعداً با Refactor §1.5 همه را زیر «مدیریت مطب» ادغام کند، این entry به آن‌جا می‌رود.)
 *  - جستجو: GET server-side (فرم GET → بازرندر)، bounded (SEARCH_LIMIT)؛ از
 *    `PatientService::search()` (searchView = بدون فیلدهای بالینی/خصوصی؛ کد ملی masked).
 *  - ایجاد: فقط در صورت `cpms_patient_create`؛ POST به admin-post با **Nonce (CSRF)** +
 *    Capability + Sanitize؛ از `PatientService::create()` (audit `PATIENT_CREATED` + اضافهٔ
 *    OpLog/audit با mobile mask؛ بدون ثبت PHI اضافه).
 *  - Page render با `cpms_patient_read` گیت می‌شود؛ WP Administrator صرفاً با `manage_options`
 *    بهmedical patient data دسترسی ندارد.
 *  - بدون raw REST tooling؛ بدون نمایش شناسهٔ capability در UI عادی؛ RTL + Persian-first.
 *
 * پشتیبانی (back-end): search / get / create / update موجود است. «تاریخچه/جزئیات بالینی کامل» و
 * «فهرست همهٔ بیماران بدون query» در این ورود عملیاتی سطح نمی‌شوند (بیش‌ازحدِ ضرورت، و
 * برای جزئیات بالینی ذخیره‌سازیِ عمدی متمایز لازم است — out of scope).
 */
final class PatientAdminPage
{
    public const PAGE_SLUG = 'cpms-patients';

    private const NONCE_ACTION = 'cpms_patient_create';
    private const NOTICE_KEY = 'cpms_patient_notice';
    private const SEARCH_LIMIT = 10;

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_cpms_patient_create', [self::class, 'handleCreate']);
    }

    public static function menu(): void
    {
        add_menu_page(
            'بیماران',
            'بیماران',
            RolesAndCapabilities::PATIENT_READ,
            self::PAGE_SLUG,
            [self::class, 'render'],
            'dashicons-id',
            27
        );
    }

    /** دسترسی به فرم «ایجاد بیمار» — فقط دارندگان `cpms_patient_create`. */
    public static function canCreate(): bool
    {
        return current_user_can(RolesAndCapabilities::PATIENT_CREATE);
    }

    public static function render(): void
    {
        if (!current_user_can(RolesAndCapabilities::PATIENT_READ)) {
            wp_die('دسترسی ندارید', 403);
        }

        $notice = get_transient(self::NOTICE_KEY);
        if ($notice !== false) {
            delete_transient(self::NOTICE_KEY);
        }

        $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- فقط خواندن/نمایش جستجو (GET خواندنی)
        $results = [];
        $searchError = '';
        $searched = false;
        if ($q !== '') {
            $searched = true;
            if (mb_strlen($q) < 2) {
                $searchError = 'جستجو باید حداقل ۲ کاراکتر باشد.';
            } else {
                try {
                    $results = App::patientService()->search($q, self::SEARCH_LIMIT);
                } catch (BookingException $e) {
                    $searchError = $e->getMessage();
                }
            }
        }

        $canCreate = self::canCreate();
        ?>
        <div class="wrap" dir="rtl">
            <h1>بیماران</h1>
            <p class="description">یافتن سریع بیمار برای عملیات روزانه (نوبت/صف/مالی) و ثبت بیمار جدید توسط پرسنل مجاز.
                این صفحه فقط اطلاعات عملیاتیِ لازم را نشان می‌دهد؛ جزئیات بالینی/تاریخچهٔ درمان از مسیرهای مجاز خودش انجام می‌شود.</p>

            <?php if (is_string($notice) && $notice !== '') : ?>
                <div class="notice <?php echo str_starts_with($notice, 'خطا') ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="cpms-autofocus">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="cpms_pat_q">جستجوی بیمار</label></th>
                        <td>
                            <input type="search" id="cpms_pat_q" name="q" class="regular-text" value="<?php echo esc_attr($q); ?>"
                                   placeholder="نام، نام خانوادگی، موبایل، کد ملی یا کد پرونده (MRN)" autocomplete="off">
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" class="button button-primary">جستجو</button>
                    <?php if ($q !== '') : ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>">پاک‌کردن</a><?php endif; ?>
                </p>
            </form>

            <?php if ($searchError !== '') : ?>
                <div class="notice notice-error"><p><?php echo esc_html($searchError); ?></p></div>
            <?php elseif ($searched && $results === []) : ?>
                <?php echo CpmsUi::emptyState('🔍', 'بیماری یافت نشد.', 'هیچ بیماری با این مشخصات پیدا نشد. املای نام/موبایل/کد ملی را بررسی کنید یا از «افزودن بیمار» استفاده کنید.', $canCreate ? 'افزودن بیمار' : '', $canCreate ? admin_url('admin.php?page=' . self::PAGE_SLUG . '#cpms-add-patient') : ''); ?>
            <?php elseif ($results !== []) : ?>
                <h2>نتایج (تا <?php echo (int) self::SEARCH_LIMIT; ?> مورد)</h2>
                <table class="widefat striped" role="presentation">
                    <thead>
                        <tr><th>کد پرونده</th><th>نام و نام خانوادگی</th><th>موبایل</th><th>کد ملی</th><th>وضعیت</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($results as $p) : ?>
                        <tr>
                            <td><?php echo esc_html((string) $p['mrn']); ?></td>
                            <td><?php echo esc_html(trim((string) $p['first_name'] . ' ' . (string) $p['last_name'])); ?></td>
                            <td><?php echo esc_html((string) $p['mobile']); ?></td>
                            <td><?php echo $p['national_id'] !== null && $p['national_id'] !== '' ? esc_html((string) $p['national_id']) : '—'; ?></td>
                            <td><?php echo $p['status'] === 'active' ? '<span style="color:#00a32a;">فعال</span>' : '<span style="color:#d63638;">' . esc_html((string) $p['status']) . '</span>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description">پس از یافتن بیمار، او را از صفحهٔ «صف امروز» برای منشی وارد نوبت/صف کنید.</p>
            <?php elseif ($searched === false) : ?>
                <p class="description">برای شروع، یک نام/موبایل/کد ملی/کد پرونده وارد کنید.</p>
            <?php endif; ?>

            <?php if ($canCreate) : ?>
                <hr>
                <h2 id="cpms-add-patient">افزودن بیمار</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cpms-autofocus">
                    <?php wp_nonce_field(self::NONCE_ACTION); ?>
                    <input type="hidden" name="action" value="cpms_patient_create">
                    <table class="form-table" role="presentation">
                        <tr><th><label for="cp_pat_first">نام *</label></th><td><input type="text" id="cp_pat_first" name="first_name" class="regular-text" required></td></tr>
                        <tr><th><label for="cp_pat_last">نام خانوادگی *</label></th><td><input type="text" id="cp_pat_last" name="last_name" class="regular-text" required></td></tr>
                        <tr><th><label for="cp_pat_mobile">موبایل *</label></th><td><input type="tel" id="cp_pat_mobile" name="mobile" class="regular-text" required inputmode="tel" dir="ltr" placeholder="0912xxxxxxx"></td></tr>
                        <tr><th><label for="cp_pat_nid">کد ملی</label></th><td><input type="text" id="cp_pat_nid" name="national_id" class="regular-text" inputmode="numeric" dir="ltr"></td></tr>
                        <tr><th><label for="cp_pat_birth">تاریخ تولد</label></th><td><input type="text" id="cp_pat_birth" name="birth_date" class="regular-text" placeholder="YYYY-MM-DD" dir="ltr"></td></tr>
                        <tr><th><label for="cp_pat_gender">جنسیت</label></th>
                            <td><select id="cp_pat_gender" name="gender">
                                <option value=""></option>
                                <option value="unknown">نامشخص</option>
                                <option value="male">مرد</option>
                                <option value="female">زن</option>
                                <option value="other">دیگر</option>
                            </select></td>
                        </tr>
                    </table>
                    <p class="submit"><button type="submit" class="button button-primary">ثبت بیمار</button></p>
                </form>
            <?php else : ?>
                <p class="description">نقش شما تنها مجاز به جستجوی بیمار است؛ برای ثبت بیمار با پرسنل مجاز (منشی) هماهنگ کنید.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * هندلر ایجاد بیمار — admin_post با Nonce + Capability + Sanitize.
     */
    public static function handleCreate(): void
    {
        if (!is_user_logged_in() || !current_user_can(RolesAndCapabilities::PATIENT_CREATE)) {
            wp_die('دسترسی ندارید', 403);
        }
        check_admin_referer(self::NONCE_ACTION);

        $in = [
            'first_name' => isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            'last_name' => isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            'mobile' => isset($_POST['mobile']) ? sanitize_text_field(wp_unslash($_POST['mobile'])) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            'national_id' => isset($_POST['national_id']) ? sanitize_text_field(wp_unslash($_POST['national_id'])) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            'birth_date' => isset($_POST['birth_date']) ? sanitize_text_field(wp_unslash($_POST['birth_date'])) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            'gender' => isset($_POST['gender']) ? sanitize_key(wp_unslash($_POST['gender'])) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        ];

        $result = self::createPatient($in, (int) get_current_user_id());
        set_transient(self::NOTICE_KEY, $result['error'] !== ''
            ? 'خطا: ' . $result['error']
            : 'بیمار «' . $result['name'] . '» ثبت شد (کد پرونده: ' . $result['mrn'] . ').',
            60
        );
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    /**
     * ایجاد بیمار — خالص و قابل تست (بدون exit/die). فقط مسیر Authorization در
     * handler بررسی می‌شود؛ این متد مثل StaffManagementPage::upsertUser فرض می‌کند
     * Call-site مجاز است و Validation/Data-Access را به PatientService می‌سپارد.
     *
     * @param array<string, mixed> $in
     *
     * @return array{error:string, id:int, name:string, mrn:string}
     */
    public static function createPatient(array $in, int $actorUserId): array
    {
        $fields = array_intersect_key($in, array_flip([
            'first_name', 'last_name', 'mobile', 'national_id', 'birth_date', 'gender',
        ]));

        try {
            $patient = App::patientService()->create($fields, $actorUserId);
        } catch (BookingException $e) {
            return ['error' => $e->getMessage(), 'id' => 0, 'name' => '', 'mrn' => ''];
        }

        return [
            'error' => '',
            'id' => (int) ($patient['id'] ?? 0),
            'name' => trim((string) ($patient['first_name'] ?? '') . ' ' . (string) ($patient['last_name'] ?? '')),
            'mrn' => (string) ($patient['mrn'] ?? ''),
        ];
    }
}
