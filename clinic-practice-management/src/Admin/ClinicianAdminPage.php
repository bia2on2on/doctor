<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Infrastructure\Repository\ClinicianRepository;

/**
 * صفحه «پزشکان و برنامه» — Setup UI (Part 2 / ADR-0031، ممیزی P1).
 *
 * مشکل: تا پیش از این ثبت پزشک و برنامه هفتگی فقط با دست‌کاری مستقیم
 * دیتابیس/REST ممکن بود (بزرگ‌ترین شکاف ممیزی) — پزشک غیرفنی نمی‌توانست
 * مطبش را راه بیندازد.
 *
 *  - دسترسی: فقط `cpms_config` (Administrator فنی — P-3).
 *  - لیست پزشکان + افزودن/ویرایش/غیرفعال‌سازی (حذف فیزیکی ممنوع — FK) +
 *    پیوند ۱:۱ به کاربر وردپرس (UNIQUE — Migration 0007).
 *  - برنامه هفتگی هر پزشک (۷ روز) با ذخیره از مسیر ScheduleService (Audit +
 *    تولید مجدد Slot خودکار — همان مسیر REST/تست‌شده).
 *  - استثناهای برنامه (تعطیلی/مرخصی/بستن/باز کردن) از مسیر همان سرویس.
 */
final class ClinicianAdminPage
{
    private const NOTICE_KEY = 'cpms_clinic_notice';

    /** نام روزها — 0=شنبه … 6=جمعه (قرارداد ScheduleService). */
    private const DAYS = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_cpms_clinician_save', [self::class, 'saveClinician']);
        add_action('admin_post_cpms_clinician_toggle', [self::class, 'toggleClinician']);
        add_action('admin_post_cpms_schedule_save', [self::class, 'saveSchedules']);
        add_action('admin_post_cpms_schedule_delete', [self::class, 'deleteSchedule']);
        add_action('admin_post_cpms_exception_create', [self::class, 'createException']);
        add_action('admin_post_cpms_exception_delete', [self::class, 'deleteException']);
    }

    public static function menu(): void
    {
        add_submenu_page(
            CpmsAdminMenu::parentSlug(),
            'پزشکان و برنامه کاری',
            'پزشکان و برنامه کاری',
            RolesAndCapabilities::CONFIG,
            'cpms-clinicians',
            [self::class, 'render']
        );
    }

    // ================= Render =================

    public static function render(): void
    {
        if (!current_user_can(RolesAndCapabilities::CONFIG)) {
            wp_die('دسترسی ندارید', 403);
        }

        $notice = get_transient(self::NOTICE_KEY);
        if ($notice !== false) {
            delete_transient(self::NOTICE_KEY);
        }

        $repo = App::clinicianRepository();
        $selectedId = isset($_GET['clinician_id']) ? absint($_GET['clinician_id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- فقط انتخاب نما؛ هر تغییر با Nonce جدا محافظت می‌شود
        $selected = $selectedId > 0 ? $repo->find($selectedId) : null;
        ?>
<div class="wrap" dir="rtl">
    <h1>پزشکان و برنامه هفتگی</h1>
    <?php if (is_string($notice) && $notice !== '') : ?>
        <div class="notice <?php echo str_starts_with($notice, 'خطا') ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
    <?php endif; ?>

    <?php if ($selected === null) : ?>
        <?php self::renderList($repo); ?>
    <?php else : ?>
        <?php self::renderClinician($repo, $selected); ?>
    <?php endif; ?>
</div>
        <?php
    }

    private static function renderList(ClinicianRepository $repo): void
    {
        $rows = $repo->listAll(App::scope()->clinicId);
        $users = self::wpUsers();
        ?>
    <h2 class="title">پزشکان</h2>
    <table class="widefat striped cpms-table-responsive" style="max-width:1000px">
        <thead><tr><th>نام</th><th>تخصص</th><th>اتاق</th><th>کاربر متصل</th><th>روزهای برنامه</th><th>وضعیت</th><th></th></tr></thead>
        <tbody>
        <?php if ($rows === []) : ?>
            <tr><td colspan="7"><?php echo CpmsUi::emptyState('🩺', 'هنوز پزشکی ثبت نشده', 'برای شروع، اولین پزشک را با فرم پایین اضافه کنید — یا می‌توانید هم‌زمان حساب ورود او را با نقش «پزشک» بسازید.', 'افزودن اولین پزشک', admin_url('admin.php?page=cpms-clinicians')); ?></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r) : ?>
            <tr>
                <td data-label="نام"><strong><?php echo esc_html((string) $r['full_name']); ?></strong></td>
                <td data-label="تخصص"><?php echo esc_html((string) ($r['specialty'] ?? '')); ?></td>
                <td data-label="اتاق"><?php echo esc_html((string) ($r['room'] ?? '')); ?></td>
                <td data-label="کاربر متصل"><span class="ltr"><?php echo esc_html((string) ($r['wp_user_login'] ?? '—')); ?></span></td>
                <td data-label="روزهای برنامه"><?php echo (int) $r['schedule_days']; ?> روز</td>
                <td data-label="وضعیت"><?php echo (int) $r['is_active'] === 1 ? '<span class="cpms-badge cpms-ok">فعال</span>' : '<span class="cpms-badge cpms-danger">غیرفعال</span>'; ?></td>
                <td class="cpms-actions-cell" data-label="عملیات"><a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=cpms-clinicians&clinician_id=' . (int) $r['id'])); ?>">مدیریت برنامه</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2 class="title" style="margin-top:20px">افزودن پزشک</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:640px">
        <?php wp_nonce_field('cpms_clinician_save'); ?>
        <input type="hidden" name="action" value="cpms_clinician_save">
        <table class="form-table" role="presentation">
            <tr><th>نام پزشک *</th><td><input type="text" name="full_name" required class="regular-text"></td></tr>
            <tr><th>تخصص</th><td><input type="text" name="specialty" class="regular-text"></td></tr>
            <tr><th>اتاق</th><td><input type="text" name="room" class="regular-text"></td></tr>
            <tr><th>کاربر وردپرس</th><td>
                <label><input type="checkbox" name="create_account" value="1" id="cpms-create-account"> ایجاد حساب کاربری جدید (نقش: پزشک) — بدون نیاز به صفحهٔ جداگانهٔ «کاربران»</label>
                <div id="cpms-account-fields" style="display:none; margin-top:8px">
                    <p><label>نام کاربری * <input type="text" name="account_username" class="regular-text" autocomplete="off"></label></p>
                    <p><label>ایمیل * <input type="email" name="account_email" class="regular-text" autocomplete="off"></label></p>
                    <p><label>رمز عبور <input type="password" name="account_password" class="regular-text" autocomplete="new-password"></label>
                        <span class="description">خالی بگذارید تا رمز قوی تصادفی ساخته و یک‌بار نمایش داده شود (هرگز plaintext ذخیره نمی‌شود).</span></p>
                </div>
                <select name="wp_user_id">
                    <option value="0">— بدون اتصال —</option>
                    <?php foreach ($users as $u) : ?>
                        <option value="<?php echo (int) $u['id']; ?>"><?php echo esc_html($u['label']); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description">یا یک پزشک موجود را انتخاب کنید. فقط اتصال ۱:۱ به «امروز پزشک» و صف خودش دسترسی می‌دهد.</p>
            </td></tr>
        </table>
        <p><button type="submit" class="button button-primary">ثبت پزشک</button></p>
    </form>
    <script>
        (function(){
            var c = document.getElementById('cpms-create-account');
            var box = document.getElementById('cpms-account-fields');
            if (!c || !box) { return; }
            c.addEventListener('change', function(){
                box.style.display = c.checked ? 'block' : 'none';
            });
        })();
    </script>
        <?php
    }

    /**
     * @param array<string, mixed> $clinician
     */
    private static function renderClinician(ClinicianRepository $repo, array $clinician): void
    {
        $cid = (int) $clinician['id'];
        $scheduleService = App::scheduleService();
        $schedules = $scheduleService->list($cid); // requireClinician — رکورد هست
        $byDay = [];
        foreach ($schedules as $s) {
            $byDay[(int) $s['day_of_week']] = $s;
        }
        $users = self::wpUsers();
        $exceptions = $scheduleService->listExceptions($cid, gmdate('Y-m-d'), gmdate('Y-m-d', strtotime('+120 days')));
        ?>
    <a href="<?php echo esc_url(admin_url('admin.php?page=cpms-clinicians')); ?>">← بازگشت به فهرست</a>
    <h1><?php echo esc_html((string) $clinician['full_name']); ?>
        <span class="description"><?php echo esc_html((string) ($clinician['specialty'] ?? '')); ?></span>
    </h1>

    <!-- پروفایل -->
    <h2 class="title">پروفایل</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:720px">
        <?php wp_nonce_field('cpms_clinician_save'); ?>
        <input type="hidden" name="action" value="cpms_clinician_save">
        <input type="hidden" name="clinician_id" value="<?php echo $cid; ?>">
        <table class="form-table" role="presentation">
            <tr><th>نام پزشک *</th><td><input type="text" name="full_name" required value="<?php echo esc_attr((string) $clinician['full_name']); ?>" class="regular-text"></td></tr>
            <tr><th>تخصص</th><td><input type="text" name="specialty" value="<?php echo esc_attr((string) ($clinician['specialty'] ?? '')); ?>" class="regular-text"></td></tr>
            <tr><th>اتاق</th><td><input type="text" name="room" value="<?php echo esc_attr((string) ($clinician['room'] ?? '')); ?>" class="regular-text"></td></tr>
            <tr><th>کاربر وردپرس</th><td>
                <select name="wp_user_id">
                    <option value="0">— بدون اتصال —</option>
                    <?php foreach ($users as $u) : ?>
                        <option value="<?php echo (int) $u['id']; ?>" <?php selected((int) ($clinician['wp_user_id'] ?? 0), (int) $u['id']); ?>><?php echo esc_html($u['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </td></tr>
        </table>
        <p>
            <button type="submit" class="button button-primary">ذخیره پروفایل</button>
            <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=cpms_clinician_toggle&clinician_id=' . $cid), 'cpms_clinician_toggle_' . $cid)); ?>"
                data-cpms-confirm="<?php echo (int) $clinician['is_active'] === 1 ? 'غیرفعال‌سازی' : 'فعال‌سازی'; ?> این پزشک؟ تاریخچهٔ او حذف نمی‌شود.">
                <?php echo (int) $clinician['is_active'] === 1 ? '⛔ غیرفعال‌سازی' : '✅ فعال‌سازی'; ?>
            </a>
        </p>
    </form>

    <!-- برنامه هفتگی — یک فرم برای کل جدول (نام‌گذاری آرایه‌ای sched[day]) -->
    <h2 class="title">برنامه هفتگی (ساعت‌ها به وقت مطب)</h2>
    <?php if ((int) $clinician['is_active'] === 1) : $impact = App::scheduleService()->impact($cid); ?>
        <div class="notice notice-info inline" style="max-width:1150px">
            <p><strong>تأثیر تغییر برنامه (پیش‌نمایش):</strong>
                با ذخیرهٔ هر تغییر، <strong><?php echo (int) $impact['future_empty_slots']; ?></strong> اسلات خالی آینده
                حذف و بازتولید می‌شود (بازتولید خودکار)؛ و <strong><?php echo (int) $impact['future_reserved_slots']; ?></strong>
                اسلات دارای رزرو/Hold <strong>هرگز حذف نمی‌شوند</strong> (امانت داده حفظ می‌شود). برای اینکه تغییری بی‌صدا
                اعمال نشود، این عدد پیش از ثبت نمایش داده می‌شود.
            </p>
        </div>
    <?php endif; ?>
    <p class="description">پس از ذخیره، Slotهای رزرو خودکار بازتولید می‌شوند (Job slots.generate). برای حذف یک روز از دکمه «حذف» همان ردیف استفاده کنید.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('cpms_schedule_save'); ?>
        <input type="hidden" name="action" value="cpms_schedule_save">
        <input type="hidden" name="clinician_id" value="<?php echo $cid; ?>">
        <table class="widefat striped cpms-table-responsive" style="max-width:1150px">
            <thead><tr><th>روز</th><th>شروع</th><th>پایان</th><th>وقفه از</th><th>وقفه تا</th><th>مدت نوبت (دقیقه)</th><th>ظرفیت هر Slot</th><th>فعال</th><th></th></tr></thead>
            <tbody>
            <?php foreach (self::DAYS as $day => $dayLabel) : $s = $byDay[$day] ?? null; ?>
                <tr>
                    <td data-label="روز"><strong><?php echo esc_html($dayLabel); ?></strong></td>
                    <td data-label="شروع"><input type="time" name="sched[<?php echo $day; ?>][start_time]" value="<?php echo esc_attr((string) ($s['start_time'] ?? '09:00')); ?>" required></td>
                    <td data-label="پایان"><input type="time" name="sched[<?php echo $day; ?>][end_time]" value="<?php echo esc_attr((string) ($s['end_time'] ?? '13:00')); ?>" required></td>
                    <td data-label="وقفه از"><input type="time" name="sched[<?php echo $day; ?>][break_start]" value="<?php echo esc_attr((string) ($s['break_start'] ?? '')); ?>"></td>
                    <td data-label="وقفه تا"><input type="time" name="sched[<?php echo $day; ?>][break_end]" value="<?php echo esc_attr((string) ($s['break_end'] ?? '')); ?>"></td>
                    <td data-label="مدت نوبت (دقیقه)"><input type="number" name="sched[<?php echo $day; ?>][appointment_duration_min]" min="5" max="240" value="<?php echo esc_attr((string) ($s['appointment_duration_min'] ?? '20')); ?>" style="width:80px"></td>
                    <td data-label="ظرفیت هر Slot"><input type="number" name="sched[<?php echo $day; ?>][slot_capacity]" min="1" max="50" value="<?php echo esc_attr((string) ($s['slot_capacity'] ?? '1')); ?>" style="width:70px"></td>
                    <td data-label="فعال"><input type="checkbox" name="sched[<?php echo $day; ?>][is_active]" value="1" <?php checked($s === null || !empty($s['is_active'])); ?>></td>
                    <td class="cpms-actions-cell" data-label="عملیات">
                        <button type="submit" name="sched_submit[<?php echo $day; ?>]" value="1" class="button button-small"><?php echo $s === null ? 'افزودن روز' : 'ذخیره روز'; ?></button>
                        <?php if ($s !== null) : ?>
                            <button type="button" class="button button-small" data-cpms-schedule-delete="<?php echo (int) $s['id']; ?>" data-cpms-confirm="حذف برنامه این روز؟" style="color:#b32d2e">حذف</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </form>
    <form id="cpms-sched-del" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('cpms_schedule_delete'); ?>
        <input type="hidden" name="action" value="cpms_schedule_delete">
        <input type="hidden" name="clinician_id" value="<?php echo $cid; ?>">
        <input type="hidden" name="schedule_id" value="">
    </form>
    <!-- استثناها -->
    <h2 class="title" style="margin-top:18px">استثناها (تعطیلی / مرخصی / بستن / باز کردن)</h2>
    <?php if ($exceptions !== []) : ?>
        <table class="widefat striped" style="max-width:800px">
            <thead><tr><th>تاریخ</th><th>نوع</th><th>ساعت</th><th>علت</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($exceptions as $e) : ?>
                <tr>
                    <td><?php echo esc_html(Jalali::formatYmd((string) $e['date'])); ?> <span class="description">(<?php echo esc_html((string) $e['date']); ?>)</span></td>
                    <td><?php echo esc_html(self::exceptionTypeLabel((string) $e['type'])); ?></td>
                    <td dir="ltr"><?php echo esc_html(($e['start_time'] ?? '—') . ' تا ' . ($e['end_time'] ?? '—')); ?></td>
                    <td><?php echo esc_html((string) ($e['reason'] ?? '')); ?></td>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-cpms-confirm="حذف این استثنا؟">
                            <?php wp_nonce_field('cpms_exception_delete'); ?>
                            <input type="hidden" name="action" value="cpms_exception_delete">
                            <input type="hidden" name="clinician_id" value="<?php echo $cid; ?>">
                            <input type="hidden" name="exception_id" value="<?php echo (int) $e['id']; ?>">
                            <button type="submit" class="button-link" style="color:#b32d2e">حذف</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else : ?>
        <p class="description">استثنایی برای ۱۲۰ روز آینده ثبت نشده است.</p>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px">
        <?php wp_nonce_field('cpms_exception_create'); ?>
        <input type="hidden" name="action" value="cpms_exception_create">
        <input type="hidden" name="clinician_id" value="<?php echo $cid; ?>">
        <label>تاریخ (میلادی): <input type="date" name="date" required></label>
        <label>نوع:
            <select name="type">
                <option value="holiday">تعطیل رسمی</option>
                <option value="leave">مرخصی پزشک</option>
                <option value="blocked">بستن ساعت خاص (زیر)</option>
                <option value="open_override">باز کردن استثنایی (زیر)</option>
            </select>
        </label>
        <label>از ساعت: <input type="time" name="start_time"></label>
        <label>تا ساعت: <input type="time" name="end_time"></label>
        <label>علت: <input type="text" name="reason" class="regular-text"></label>
        <button type="submit" class="button">افزودن استثنا</button>
    </form>
    <p class="description">برای «تعطیل/مرخصی» ساعت‌ها لازم نیست؛ برای «بستن/باز کردن» بازه ساعت الزامی است.</p>
        <?php
    }

    // ================= Handlers =================

    public static function saveClinician(): void
    {
        self::guard('cpms_clinician_save');
        $repo = App::clinicianRepository();
        $fields = self::clinicianFields();
        $id = isset($_POST['clinician_id']) ? absint($_POST['clinician_id']) : 0;
        $generated = '';

        try {
            if ($id > 0) {
                $current = $repo->find($id);
                if ($current === null) {
                    self::backWithError($id, 'خطا: پزشک یافت نشد.');
                }
                $repo->update($id, $fields);
                App::audit()->log('CLINICIAN_UPDATED', ['wp_user_id' => get_current_user_id()], 'clinician', $id, null, null, ['fields' => array_keys($fields)]);
                self::back($id, 'پروفایل پزشک ذخیره شد.');
            }

            if (trim((string) ($fields['full_name'] ?? '')) === '') {
                self::backWithError(0, 'خطا: نام پزشک الزامی است.');
            }

            // Chunk D — ساخت حساب در همان جریان «افزودن پزشک» (بدون صفحهٔ جداگانهٔ کاربران).
            $accountCreated = self::maybeCreateDoctorAccount($fields, $generated);
            if (($accountCreated['error'] ?? '') !== '') {
                self::backWithError(0, 'خطا: ' . $accountCreated['error']);
            }
            if (!empty($accountCreated['wp_user_id'])) {
                $fields['wp_user_id'] = (int) $accountCreated['wp_user_id'];
            }

            if ($fields['wp_user_id'] !== null && $repo->isUserLinked((int) $fields['wp_user_id'])) {
                self::backWithError(0, 'خطا: این کاربر وردپرس قبلاً به پزشک دیگری متصل است (پیوند باید ۱:۱ باشد).');
            }
            $newId = $repo->create(App::scope()->clinicId, $fields);
            App::audit()->log('CLINICIAN_CREATED', ['wp_user_id' => get_current_user_id()], 'clinician', $newId, null, null, ['full_name' => (string) $fields['full_name']]);
            $message = 'پزشک ثبت شد — حالا برنامه هفتگی او را تنظیم کنید.';
            if (($accountCreated['generated'] ?? '') !== '') {
                $message .= ' رمز یک‌بارهٔ حساب: ' . $accountCreated['generated'] . ' (فقط همین حالا نمایش داده می‌شود — آن را به پزشک بدهید.)';
            }
            self::back($newId, $message);
        } catch (\RuntimeException $e) {
            // Race انتساب ۱:۱ (UNIQUE 0007) — پیام فارسی، بدون Fatal
            self::backWithError($id, 'خطا: ' . $e->getMessage());
        }
    }

    public static function toggleClinician(): void
    {
        self::guard('cpms_clinician_toggle_');
        $repo = App::clinicianRepository();
        $id = isset($_GET['clinician_id']) ? absint($_GET['clinician_id']) : 0;
        $current = $repo->find($id);
        if ($current === null) {
            self::backWithError(0, 'خطا: پزشک یافت نشد.');
        }
        $newState = (int) $current['is_active'] === 1 ? 0 : 1;
        $repo->update($id, ['is_active' => $newState]);
        App::audit()->log(
            'CLINICIAN_STATUS_CHANGED',
            ['wp_user_id' => get_current_user_id()],
            'clinician',
            $id,
            null,
            ['is_active' => (int) $current['is_active']],
            ['is_active' => $newState]
        );
        self::back($id, $newState === 1 ? 'پزشک فعال شد.' : 'پزشک غیرفعال شد (داده‌ها حفظ شد — حذف فیزیکی ممنوع).');
    }

    /**
     * ذخیره برنامه روز/روزها — فقط ردیفی که دکمه‌اش زده شده اعمال می‌شود.
     */
    public static function saveSchedules(): void
    {
        self::guard('cpms_schedule_save');
        $service = App::scheduleService();
        $userId = get_current_user_id();
        $cid = isset($_POST['clinician_id']) ? absint($_POST['clinician_id']) : 0;
        $submitted = isset($_POST['sched_submit']) && is_array($_POST['sched_submit']) ? wp_unslash($_POST['sched_submit']) : [];
        $all = isset($_POST['sched']) && is_array($_POST['sched']) ? wp_unslash($_POST['sched']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- پاک‌سازی فیلدبه‌فیلد در ادامه
        $day = (int) (array_key_first((array) $submitted));
        if (!isset($all[$day]) || !is_array($all[$day])) {
            self::backWithError($cid, 'خطا: ردیف برنامه ناقص است.');
        }
        $row = $all[$day];

        $fields = [
            'clinician_id' => $cid,
            'day_of_week' => $day,
            'start_time' => (string) ($row['start_time'] ?? ''),
            'end_time' => (string) ($row['end_time'] ?? ''),
            'appointment_duration_min' => max(5, min(240, (int) ($row['appointment_duration_min'] ?? 20))),
            'slot_capacity' => max(1, min(50, (int) ($row['slot_capacity'] ?? 1))),
            'is_active' => !empty($row['is_active']),
        ];
        foreach (['break_start', 'break_end'] as $k) {
            $v = trim((string) ($row[$k] ?? ''));
            if ($v !== '') {
                $fields[$k] = $v;
            }
        }

        try {
            // پیش‌نمایش تأثیر (Chunk D) پیش از بازتولید — تا مدیر بداند چند اسلات خالی
            // قرار است حذف/بازتولید شود و چند اسلات رزرو/Hold محافظت می‌شود (نه invalidate بی‌صدا).
            $impact = App::scheduleService()->impact($cid);
            $impactNote = sprintf(
                ' — بازتولید %d اسلات خالی آینده؛ %d اسلات رزرو/Hold حفظ شد.',
                (int) $impact['future_empty_slots'],
                (int) $impact['future_reserved_slots']
            );

            // کلید «ذخیره روز» همیشه schedule_id فعلی همان روز را دارد؛ تشخیص
            // update/create از وجود رکورد همان روز انجام می‌شود (u_sched_day).
            $existing = App::db()->fetchValue(
                'SELECT id FROM ' . App::db()->table('cpms_schedule') . ' WHERE clinician_id = %d AND day_of_week = %d LIMIT 1',
                [$cid, $day]
            );
            if ($existing !== null) {
                $service->update($userId, (int) $existing, $fields);
                self::back($cid, 'برنامه روز ذخیره شد — Slotها بازتولید می‌شوند.' . $impactNote);
            }
            $service->create($userId, $fields);
            self::back($cid, 'روز به برنامه اضافه شد — Slotها بازتولید می‌شوند.' . $impactNote);
        } catch (BookingException $e) {
            self::backWithError($cid, 'خطا: ' . $e->getMessage());
        }
    }

    public static function deleteSchedule(): void
    {
        self::guard('cpms_schedule_delete');
        $cid = isset($_POST['clinician_id']) ? absint($_POST['clinician_id']) : 0;
        $id = isset($_POST['schedule_id']) ? absint($_POST['schedule_id']) : 0;
        try {
            App::scheduleService()->delete(get_current_user_id(), $id);
            self::back($cid, 'برنامه روز حذف شد.');
        } catch (BookingException $e) {
            self::backWithError($cid, 'خطا: ' . $e->getMessage());
        }
    }

    public static function createException(): void
    {
        self::guard('cpms_exception_create');
        $cid = isset($_POST['clinician_id']) ? absint($_POST['clinician_id']) : 0;
        $date = (string) ($_POST['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            self::backWithError($cid, 'خطا: تاریخ نامعتبر است.');
        }
        $type = (string) ($_POST['type'] ?? '');
        if (!in_array($type, ['holiday', 'leave', 'blocked', 'open_override'], true)) {
            self::backWithError($cid, 'خطا: نوع استثنا نامعتبر است.');
        }

        $fields = ['clinician_id' => $cid, 'date' => $date, 'type' => $type];
        foreach (['start_time', 'end_time', 'reason'] as $k) {
            $v = sanitize_text_field((string) ($_POST[$k] ?? ''));
            if ($v !== '') {
                $fields[$k] = $v;
            }
        }

        try {
            App::scheduleService()->createException(get_current_user_id(), $fields);
            self::back($cid, 'استثنا ثبت شد.');
        } catch (BookingException $e) {
            self::backWithError($cid, 'خطا: ' . $e->getMessage());
        }
    }

    public static function deleteException(): void
    {
        self::guard('cpms_exception_delete');
        $cid = isset($_POST['clinician_id']) ? absint($_POST['clinician_id']) : 0;
        $id = isset($_POST['exception_id']) ? absint($_POST['exception_id']) : 0;
        try {
            App::scheduleService()->deleteException(get_current_user_id(), $id);
            self::back($cid, 'استثنا حذف شد.');
        } catch (BookingException $e) {
            self::backWithError($cid, 'خطا: ' . $e->getMessage());
        }
    }

    // ================= Helpers =================

    private static function guard(string $nonceAction): void
    {
        if (!current_user_can(RolesAndCapabilities::CONFIG) || !is_user_logged_in()) {
            wp_die('دسترسی ندارید', 403);
        }
        check_admin_referer($nonceAction);
    }

    /**
     * @return array<string, mixed>
     */
    private static function clinicianFields(): array
    {
        $fields = [
            'full_name' => sanitize_text_field((string) ($_POST['full_name'] ?? '')),
            'specialty' => sanitize_text_field((string) ($_POST['specialty'] ?? '')),
            'room' => sanitize_text_field((string) ($_POST['room'] ?? '')),
        ];
        $wpUserId = (int) ($_POST['wp_user_id'] ?? 0);
        $fields['wp_user_id'] = $wpUserId > 0 ? $wpUserId : null;
        if ($fields['specialty'] === '') {
            $fields['specialty'] = null;
        }
        if ($fields['room'] === '') {
            $fields['room'] = null;
        }

        return $fields;
    }

    /**
     * Chunk D — اگر در «افزودن پزشک» گزینهٔ ایجاد حساب انتخاب شده باشد، کاربر وردپرس را
     * با نقش `cpms_doctor` از مسیر امن StaffManagementPage::upsertUser می‌سازد (بدون
     * plaintext رمز در DB) و شناسهٔ آن را برای پیوند ۱:۱ برمی‌گرداند. اگر حساب انتخابی
     * نباشد (یا کاربر موجود انتخاب شده باشد) کاری نمی‌کند.
     *
     * @param array<string, mixed> $fields پارامترها by-ref برای تنظیم later.
     * @param string               $generated رمز یک‌بارهٔ تولیدی (by-ref).
     *
     * @return array{error?: string, wp_user_id?: int, generated?: string}
     */
    private static function maybeCreateDoctorAccount(array &$fields, string &$generated): array
    {
        if (empty($_POST['create_account'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce در guard
            return [];
        }
        $in = [
            'mode' => 'create',
            'username' => isset($_POST['account_username']) ? sanitize_user(wp_unslash($_POST['account_username']), true) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            'display_name' => (string) ($fields['full_name'] ?? ''),
            'email' => isset($_POST['account_email']) ? sanitize_email(wp_unslash($_POST['account_email'])) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            'role' => RolesAndCapabilities::ROLE_DOCTOR,
            'password' => (string) wp_unslash($_POST['account_password'] ?? ''), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        ];
        $result = StaffManagementPage::upsertUser($in, (int) get_current_user_id());
        if (($result['error'] ?? '') !== '') {
            return ['error' => $result['error']];
        }
        $generated = (string) ($result['generated'] ?? '');

        return ['wp_user_id' => (int) ($result['user_id'] ?? 0), 'generated' => $generated];
    }

    /**
     * فهرست کاربران وردپرس برای Select اتصال (سقف ۵۰۰ — بدون ایمیل/هش).
     *
     * @return list<array{id: int, label: string}>
     */
    private static function wpUsers(): array
    {
        $out = [];
        $users = get_users(['number' => 500, 'orderby' => 'display_name']);
        foreach ($users as $u) {
            $label = ($u->display_name !== '' ? $u->display_name : $u->user_login) . ' (' . $u->user_login . ')';
            $out[] = ['id' => (int) $u->ID, 'label' => $label];
        }

        return $out;
    }

    private static function exceptionTypeLabel(string $type): string
    {
        return match ($type) {
            'holiday' => 'تعطیل رسمی',
            'leave' => 'مرخصی پزشک',
            'blocked' => 'بستن ساعت',
            'open_override' => 'باز کردن استثنایی',
            default => $type,
        };
    }

    private static function back(int $clinicianId, string $message): never
    {
        self::redirect($clinicianId, $message);
    }

    private static function backWithError(int $clinicianId, string $message): never
    {
        self::redirect($clinicianId, $message);
    }

    private static function redirect(int $clinicianId, string $message): never
    {
        set_transient(self::NOTICE_KEY, $message, 90);
        $url = admin_url('admin.php?page=cpms-clinicians');
        if ($clinicianId > 0) {
            $url .= '&clinician_id=' . $clinicianId;
        }
        wp_safe_redirect($url);
        exit;
    }
}
