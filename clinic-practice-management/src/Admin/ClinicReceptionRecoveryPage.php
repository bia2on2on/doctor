<?php
// phpcs:disable -- Persian Clinic reception recovery UI: WP/Admin naming and inline HTML are intentional; global WPCS baseline already covers this file via collector (see drift-register §8-1).

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Membership\MembershipException;
use ClinicCore\Domain\Time\Jalali;

/**
 * بازیابی پذیرش تک‌مطبی — انتساب پزشک به محلِ فعال (Persian recovery UI).
 *
 * این صفحه برای نصبِ تک‌مطبی که پس از First-Practice Setup هیچ انتساب
 * cpms_clinician_locations ندارد، مسیر بازیابیِ مجازِ Clinic-local را فراهم می‌کند:
 *
 * - فقط برای مدیرِ مجازِ همان Clinic (عضویت فعال + cpms_config از طریق
 *   AuthorizationService)؛ WordPress Administrator بدون عضویت Clinic هیچ
 *   دسترسی‌ای ندارد — پیامِ غیرِشمارشیِ “دسترسی” برمی‌گردد.
 * - پزشک: فقط پزشکانِ فعالِ همان Clinic (c.is_active=1, wp_user_id>0)؛
 *   Location: فقط Locationهای فعالِ همان Clinic (is_active=1)؛ میان-Clinic
 *   هرگز مجاز نیست — سرویسِ MembershipService با خطایِ فارسی fail-closed می‌کند.
 * - انتساب به‌صورت اتمیک (حذفِ قدیم + درجِ نو) داخل تراکنش؛ audit اجباری
 *   CLINICIAN_LOCATION_RECOVERY در همان مرز — شکستِ audit کل انتساب را
 *   برمی‌گرداند (هیچ انتسابِ بی‌ثبت).
 * - پوششِ 0/1/چند Location، عضویتِ معلق، انتخابِ cross-tenant، درخواستِ
 *   تکراری/آزمایشی (idempotent با بازنویسیِ همان مجموعه)، rollbackِ ناشی از
 *   شکستِ audit و هر دو جریانِ مرورگر و RTL/موبایل.
 */
final class ClinicReceptionRecoveryPage {
    public const PAGE_SLUG = 'cpms-reception-recovery';
    private const NONCE_ACTION = 'cpms_reception_recovery';
    private const NOTICE_KEY = 'cpms_reception_recovery_notice';

    public static function register(): void {
        // App::boot() runs before WordPress loads the admin menu functions,
        // including on frontend, REST, CLI and activation requests.
        \add_action('admin_menu', [self::class, 'menu']);
        \add_action('admin_post_cpms_reception_recovery_assign', [self::class, 'handleAssign']);
    }

    public static function menu(): void {
        \add_submenu_page(
            CpmsAdminMenu::parent_slug(),
            'بازیابی پذیرش — انتساب پزشک به محل',
            'بازیابی پذیرش',
            RolesAndCapabilities::CONFIG,
            self::PAGE_SLUG,
            [self::class, 'render']
        );
        // Hidden from submenu by default — discovered via SystemPage guidance and direct URL.
        CpmsAdminMenu::hide_from_submenu(self::PAGE_SLUG);
    }

    public static function render(): void {
        $actor_id = (int) \get_current_user_id();
        if ($actor_id <= 0) {
            \wp_die(\esc_html__('دسترسی ندارید', 'cpms'), 403);
        }

        $requested_clinic_id = AdminClinicSelector::requested_clinic_id($_GET); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selector; verified below
        $choices = AdminClinicSelector::choices($actor_id, RolesAndCapabilities::CONFIG);

        // No eligible Clinic — show selector shell (non-enumerating).
        if ($requested_clinic_id !== null) {
            $scope = AdminClinicSelector::establish($actor_id, $requested_clinic_id, RolesAndCapabilities::CONFIG);
            if ($scope === null) {
                self::render_selector_shell($choices, true);
                return;
            }
            $clinic_id = (int) $scope->clinicId;
        } else {
            // Single-membership shortcut (existing pattern in ClinicianAdminPage).
            if (count($choices) === 1) {
                $clinic_id = (int) ($choices[0]['clinic_id'] ?? 0);
                $scope = AdminClinicSelector::establish($actor_id, $clinic_id, RolesAndCapabilities::CONFIG);
                if ($scope === null) {
                    self::render_selector_shell($choices, true);
                    return;
                }
            } else {
                self::render_selector_shell($choices, false);
                return;
            }
        }

        // Verified Clinic context — now enforce explicit CONFIG authorization per Clinic.
        try {
            App::authorization_service()->authorize($actor_id, $clinic_id, RolesAndCapabilities::CONFIG);
        } catch (\Throwable) {
            \wp_die(\esc_html__('دسترسی به این درمانگاه مجاز نیست', 'cpms'), 403);
        }

        $clinic_name = (string) (App::db()->fetchValue('SELECT name FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d', [$clinic_id]) ?? '');
        $locations = App::db()->fetchAll(
            'SELECT id, name, slug, is_primary FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = %d AND is_active = 1 ORDER BY is_primary DESC, name ASC',
            [$clinic_id]
        );
        $locations = is_array($locations) ? $locations : [];

        $clinicians = App::db()->fetchAll(
            'SELECT id, full_name, wp_user_id, is_active FROM ' . App::db()->table('cpms_clinicians') . ' WHERE clinic_id = %d AND is_active = 1 AND wp_user_id > 0 ORDER BY full_name ASC, id ASC LIMIT 100',
            [$clinic_id]
        );
        $clinicians = is_array($clinicians) ? $clinicians : [];

        // Current assignments for display.
        $assignments = [];
        if ($clinicians !== []) {
            $clinician_ids = array_map(static fn($r) => (int) ($r['id'] ?? 0), $clinicians);
            $placeholders = implode(',', array_fill(0, count($clinician_ids), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built from int list, values sanitized below
            $rows = App::db()->fetchAll(
                'SELECT clinician_id, location_id, is_primary FROM ' . App::db()->table('cpms_clinician_locations') . ' WHERE clinician_id IN (' . $placeholders . ') ORDER BY clinician_id, location_id',
                $clinician_ids
            );
            foreach (is_array($rows) ? $rows : [] as $r) {
                $cid = (int) ($r['clinician_id'] ?? 0);
                $assignments[$cid][] = $r;
            }
        }

        $notice = self::pull_notice();
        $has_eligible = $locations !== [] && $clinicians !== [];

        // Header with Clinic context.
        echo '<div class="wrap cpms-reception-recovery" dir="rtl">';
        echo '<h1>بازیابی پذیرش — انتساب پزشک به محل</h1>';
        echo '<p>درمانگاهِ فعال: <strong>' . \esc_html($clinic_name) . '</strong> (شناسه ' . \esc_html((string) $clinic_id) . ') — ' . \esc_html(Jalali::todayJalali()) . '</p>';

        if ($notice !== null) {
            $class = $notice['type'] === 'success' ? 'notice notice-success is-dismissible' : 'notice notice-error is-dismissible';
            echo '<div class="' . \esc_attr($class) . '"><p>' . \esc_html($notice['message']) . '</p></div>';
        }

        // Guidance for empty assignment (the reported reception failure).
        if ($clinicians === [] || $locations === []) {
            echo '<div class="notice notice-warning"><p>';
            if ($clinicians === [] && $locations === []) {
                echo \esc_html__('هیچ پزشکِ فعال و هیچ محلِ فعالی برای این درمانگاه یافت نشد — ابتدا یک پزشک و یک محل بسازید.', 'cpms');
            } elseif ($clinicians === []) {
                echo \esc_html__('هیچ پزشکِ فعالِ دارای حساب کاربری در این درمانگاه نیست — ابتدا در «پزشکان و برنامه کاری» یک پزشک بسازید.', 'cpms');
            } else {
                echo \esc_html__('هیچ محلِ فعالِ تأییدشده‌ای برای این درمانگاه نیست — در «شعبه‌ها و محل‌ها» یک محلِ فعال بسازید.', 'cpms');
            }
            echo '</p></div>';
        }

        // Show current state table (for 0/1/multiple locations visibility).
        echo '<h2>وضعیتِ کنونیِ انتساب</h2>';
        if ($clinicians === []) {
            echo '<p>—</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>پزشک</th><th>محل‌های منتسب</th><th>اولویت</th></tr></thead><tbody>';
            foreach ($clinicians as $cl) {
                $cid = (int) ($cl['id'] ?? 0);
                $cname = (string) ($cl['full_name'] ?? '');
                $assigned = $assignments[$cid] ?? [];
                $loc_names = [];
                $primary = null;
                foreach ($assigned as $a) {
                    $lid = (int) ($a['location_id'] ?? 0);
                    foreach ($locations as $loc) {
                        if ((int) ($loc['id'] ?? 0) === $lid) {
                            $loc_names[] = (string) ($loc['name'] ?? '');
                            if ((int) ($a['is_primary'] ?? 0) === 1) {
                                $primary = (string) ($loc['name'] ?? '');
                            }
                        }
                    }
                }
                echo '<tr><td>' . \esc_html($cname) . ' (' . \esc_html((string) $cid) . ')</td>';
                echo '<td>' . \esc_html($loc_names === [] ? '— بدون انتساب (پذیرش خالی)' : implode('، ', $loc_names)) . '</td>';
                echo '<td>' . \esc_html($primary ?? '—') . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        if (!$has_eligible) {
            echo '<p><a href="' . \esc_url(\admin_url('admin.php?page=cpms-clinicians&clinic_id=' . $clinic_id)) . '" class="button">رفتن به «پزشکان و برنامه کاری»</a> ';
            echo '<a href="' . \esc_url(\admin_url('admin.php?page=cpms-locations&clinic_id=' . $clinic_id)) . '" class="button">رفتن به «شعبه‌ها و محل‌ها»</a></p>';
            echo '</div>';
            return;
        }

        // Assignment form (Persian, RTL, explicit Clinic authority).
        echo '<h2>انتسابِ جدید / ویرایش</h2>';
        echo '<p>یک پزشکِ فعال و یک یا چند محلِ فعالِ <strong>همان درمانگاه</strong> را انتخاب کنید؛ انتساب به‌صورت اتمیک جایگزینِ انتسابِ قبلی همان پزشک می‌شود و با audit ثبت می‌گردد.</p>';

        echo '<form method="post" action="' . \esc_url(\admin_url('admin-post.php')) . '" class="cpms-recovery-form">';
        \wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="cpms_reception_recovery_assign" />';
        echo '<input type="hidden" name="clinic_id" value="' . \esc_attr((string) $clinic_id) . '" />';

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="cpms_rr_clinician">پزشک</label></th><td>';
        echo '<select id="cpms_rr_clinician" name="clinician_id" required>';
        echo '<option value="">— انتخاب کنید —</option>';
        foreach ($clinicians as $cl) {
            echo '<option value="' . \esc_attr((string) ($cl['id'] ?? '')) . '">' . \esc_html((string) ($cl['full_name'] ?? '') . ' — ' . (string) ($cl['id'] ?? '')) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th scope="row">محل‌های خدمت</th><td>';
        foreach ($locations as $loc) {
            $lid = (int) ($loc['id'] ?? 0);
            $lname = (string) ($loc['name'] ?? '');
            $is_primary = (int) ($loc['is_primary'] ?? 0) === 1;
            echo '<label style="display:block;margin:6px 0;"><input type="checkbox" name="location_ids[]" value="' . \esc_attr((string) $lid) . '" /> ' . \esc_html($lname) . ' (' . \esc_html((string) $lid) . ')';
            if ($is_primary) {
                echo ' — <em>اصلی</em>';
            }
            echo '</label>';
        }
        echo '<p class="description">حداقل یک محل را انتخاب کنید؛ حداقل یک انتساب برای فعال‌شدنِ انتخاب‌گرِ پذیرش الزامی است (جدولِ خالی ⇒ انتخاب‌گرِ خالی).</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="cpms_rr_primary">محلِ اصلی (اختیاری)</label></th><td>';
        echo '<select id="cpms_rr_primary" name="primary_location_id">';
        echo '<option value="">— بدونِ اولویت —</option>';
        foreach ($locations as $loc) {
            echo '<option value="' . \esc_attr((string) ($loc['id'] ?? '')) . '">' . \esc_html((string) ($loc['name'] ?? '')) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">اگر تعیین شود، باید جزوِ محل‌های انتخاب‌شده باشد.</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
        echo '<p class="submit"><button type="submit" class="button button-primary">ذخیرهٔ انتساب</button> ';
        echo '<a href="' . \esc_url(\admin_url('admin.php?page=' . self::PAGE_SLUG . '&clinic_id=' . $clinic_id)) . '" class="button">بازنشانی</a></p>';
        echo '</form>';

        echo '<div class="cpms-help" style="margin-top:24px;padding:12px;background:#fff;border-right:4px solid #2271b1;">';
        echo '<p><strong>راهنما:</strong> پس از ذخیره، «پزشکان و برنامه کاری» و «پذیرش → انتخاب پزشک» باید پزشک را در محلِ مربوط نشان دهند. اگر هنوز خالی است، کشِ مرورگر را نوسازی کنید و از عدمِ تعلیقِ عضویتِ پزشک اطمینان یابید.</p>';
        echo '</div>';

        echo '</div>';
    }

    public static function handleAssign(): void {
        $actor_id = (int) \get_current_user_id();
        if ($actor_id <= 0) {
            \wp_die(\esc_html__('دسترسی ندارید', 'cpms'), 403);
        }
        if (!isset($_POST['_wpnonce']) || !\wp_verify_nonce(\sanitize_text_field(\wp_unslash($_POST['_wpnonce'])), self::NONCE_ACTION)) {
            self::push_notice('error', 'درخواست نامعتبر است — دوباره تلاش کنید.');
            self::redirect();
        }

        $clinic_id = isset($_POST['clinic_id']) ? (int) $_POST['clinic_id'] : 0;
        $clinician_id = isset($_POST['clinician_id']) ? (int) $_POST['clinician_id'] : 0;
        $location_ids = isset($_POST['location_ids']) && is_array($_POST['location_ids'])
            ? array_map('intval', $_POST['location_ids'])
            : [];
        $primary_location_id = isset($_POST['primary_location_id']) && (string) $_POST['primary_location_id'] !== ''
            ? (int) $_POST['primary_location_id']
            : null;

        // Scope & authorization: explicit Clinic, membership + CONFIG.
        $scope = AdminClinicSelector::establish($actor_id, $clinic_id, RolesAndCapabilities::CONFIG);
        if ($scope === null || (int) $scope->clinicId !== $clinic_id) {
            self::push_notice('error', 'دسترسی به این درمانگاه مجاز نیست.');
            self::redirect();
        }
        try {
            App::authorization_service()->authorize($actor_id, $clinic_id, RolesAndCapabilities::CONFIG);
        } catch (\Throwable) {
            self::push_notice('error', 'مجوزِ پیکربندی برای این درمانگاه ندارید.');
            self::redirect();
        }

        if ($clinician_id <= 0 || $location_ids === []) {
            self::push_notice('error', 'پزشک و حداقل یک محل را انتخاب کنید.');
            self::redirect();
        }

        // Filter to ints >0, unique.
        $location_ids = array_values(array_unique(array_filter($location_ids, static fn($v) => $v > 0)));

        try {
            // Mandatory audit in same transactional boundary as the assignment
            // (fail-closed: if audit fails, the replace is rolled back; no
            // orphan assignment without audit). The service's own transactional
            // becomes a SAVEPOINT inside this outer transaction, so release on
            // success and rollback on audit failure both behave atomically.
            App::db()->transactional(function () use ($clinician_id, $location_ids, $primary_location_id, $actor_id, $clinic_id): void {
                App::membership_service()->assign_clinician_locations($clinician_id, $location_ids, $primary_location_id);
                App::audit()->log(
                    'CLINICIAN_LOCATION_RECOVERY',
                    ['wp_user_id' => $actor_id, 'role' => 'clinic_manager'],
                    'clinician',
                    $clinician_id,
                    null,
                    null,
                    ['location_ids' => $location_ids, 'primary_location_id' => $primary_location_id],
                    ['clinic_id' => $clinic_id],
                    $clinic_id
                );
            });
        } catch (MembershipException $e) {
            $msg = $e->getMessage();
            // Preserve Persian message from service; fallback to generic.
            self::push_notice('error', $msg !== '' ? $msg : 'انتساب مجاز نیست — محل یا پزشک به این درمانگاه تعلق ندارد.');
            self::redirect();
        } catch (\Throwable $e) {
            \error_log('[CPMS][ReceptionRecovery] assign failed: ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            self::push_notice('error', 'ذخیرهٔ انتساب انجام نشد — ورودی را بررسی و دوباره تلاش کنید.');
            self::redirect();
        }

        self::push_notice('success', 'انتسابِ پزشک به محل با موفقیت ذخیره شد — پذیرش اکنون پزشک را نشان می‌دهد.');
        \wp_safe_redirect(\admin_url('admin.php?page=' . self::PAGE_SLUG . '&clinic_id=' . $clinic_id . '&assigned=1'));
        exit;
    }

    private static function render_selector_shell(array $choices, bool $denied): void {
        echo '<div class="wrap" dir="rtl"><h1>بازیابی پذیرش</h1>';
        if ($denied) {
            echo '<div class="notice notice-error"><p>دسترسی به درمانگاهِ انتخاب‌شده مجاز نیست.</p></div>';
        }
        if ($choices === []) {
            echo '<p>هیچ درمانگاهِ واجدِ شرایطی برای شما یافت نشد.</p>';
        } else {
            echo '<p>درمانگاه را انتخاب کنید:</p><ul>';
            foreach ($choices as $c) {
                $cid = (int) ($c['clinic_id'] ?? 0);
                $cname = (string) ($c['clinic_name'] ?? $c['clinic_slug'] ?? '');
                echo '<li><a href="' . \esc_url(\admin_url('admin.php?page=' . self::PAGE_SLUG . '&clinic_id=' . $cid)) . '">' . \esc_html($cname) . ' (' . \esc_html((string) $cid) . ')</a></li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    private static function push_notice(string $type, string $message): void {
        \set_transient(self::NOTICE_KEY . '_' . (int) \get_current_user_id(), ['type' => $type, 'message' => $message], 60);
    }

    private static function pull_notice(): ?array
    {
        $key = self::NOTICE_KEY . '_' . (int) \get_current_user_id();
        $notice = \get_transient($key);
        if (is_array($notice)) {
            \delete_transient($key);
            return $notice;
        }
        return null;
    }

    private static function redirect(): void {
        $clinic_id = isset($_POST['clinic_id']) ? (int) $_POST['clinic_id'] : (isset($_GET['clinic_id']) ? (int) $_GET['clinic_id'] : 0);
        $url = \admin_url('admin.php?page=' . self::PAGE_SLUG);
        if ($clinic_id > 0) {
            $url .= '&clinic_id=' . $clinic_id;
        }
        \wp_safe_redirect($url);
        exit;
    }
}
