<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\RoleCapabilitiesPage;
use ClinicCore\Admin\StaffManagementPage;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Chunk G — نقش‌های V1 (Accountant/Manager) + مدیریت Staff + ضد ارتقاء امتیاز + رد دسترسی بالینی.
 *
 * پوشش:
 *  - نقش‌های `cpms_accountant` و `cpms_manager` واقعی WP با Capabilityهای Preset.
 *  - حسابدار: فقط مالی/گزارشی؛ هیچ دسترسی بالینی/خصوصی/نسخه/فایل.
 *  - مدیر کلینیک: مدیریت ستادی/عملیاتی؛ هیچ دسترسی بالینی/خصوصی/محتوا؛ نه ادمین وردپرس.
 *  - Staff: ساخت حسابدار/مدیر؛ رد انتساب administrator؛ غیرفعال‌سازی خود ممنوع.
 *  - ماتریس دسترسی (Role × Capability) فقط برای مالک فنی (`manage_options`).
 *  - Negative authorization واقعی از مسیر REST برای Accountant/Manager/Secretary.
 *  - برچسب یک نقش: Controller فقط Capability را گیت می‌کند (نه نام نقش).
 */
final class RoleManagementGTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    private int $adminId = 0;
    private int $managerId = 0;
    private int $accountantId = 0;
    private int $secretaryId = 0;
    private int $doctorId = 0;
    private int $clinicianId = 0;
    private int $patientId = 0;
    private int $visitId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::settings()->set('queue.auto_enqueue', true);

        global $wpdb;
        $now = App::db()->nowUtcSql();

        $this->adminId = $this->makeUser('rmg_admin', 'administrator');
        // C6 repair — عضویت فعال staff صریح است (نه fixture سراسری).
        // تست‌های patient/non-member عمداً عضویت نمی‌گیرند.
        $this->managerId = $this->makeUser('rmg_manager', RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($this->managerId, 1, 'cpms_manager');
        $this->accountantId = $this->makeUser('rmg_acc', RolesAndCapabilities::ROLE_ACCOUNTANT);
        cpms_test_seed_membership($this->accountantId, 1, 'cpms_accountant');
        $this->secretaryId = $this->makeUser('rmg_sec', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($this->secretaryId, 1, 'cpms_secretary');
        $this->doctorId = $this->makeUser('rmg_doc', RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($this->doctorId, 1, 'cpms_doctor');

        // Clinician متصل به پزشک (برای Scope در مثبت‌ها)
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (1, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr G', $this->doctorId, $now, $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, %s, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-RMG-0001', 'Role', 'Mng', '09120000001', 'active', $now, $now
            )
        );
        $this->patientId = (int) $wpdb->insert_id;

        $visit = App::visitService()->walkIn($this->secretaryId, $this->patientId, $this->clinicianId);
        $this->visitId = (int) $visit['id'];
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    // ================= 1) نقش‌های جدید =================

    public function testAccountantAndManagerRolesAreRegisteredWithPresets(): void
    {
        $this->assertNotFalse(get_role(RolesAndCapabilities::ROLE_ACCOUNTANT), 'نقش cpms_accountant باید ثبت شود');
        $this->assertNotFalse(get_role(RolesAndCapabilities::ROLE_MANAGER), 'نقش cpms_manager باید ثبت شود');

        $this->assertSame(
            $this->sorted(RolesAndCapabilities::ACCOUNTANT_CAPS),
            $this->sorted($this->liveCpmsCaps(RolesAndCapabilities::ROLE_ACCOUNTANT)),
            'حسابدار باید دقیقاً ACCOUNTANT_CAPS را داشته باشد'
        );
        $this->assertSame(
            $this->sorted(RolesAndCapabilities::MANAGER_CAPS),
            $this->sorted($this->liveCpmsCaps(RolesAndCapabilities::ROLE_MANAGER)),
            'مدیر کلینیک باید دقیقاً MANAGER_CAPS را داشته باشد'
        );
    }

    public function testAccountantHasNoClinicalOrPrivateCaps(): void
    {
        wp_set_current_user($this->accountantId);
        foreach ([
            RolesAndCapabilities::MEDICAL_READ,
            RolesAndCapabilities::NOTE_CREATE,
            RolesAndCapabilities::NOTE_UPDATE,
            RolesAndCapabilities::REC_CREATE,
            RolesAndCapabilities::RX_READ,
            RolesAndCapabilities::RX_CREATE,
            RolesAndCapabilities::FILE_READ,
            RolesAndCapabilities::FILE_UPLOAD,
            RolesAndCapabilities::PRIVATE_NOTE_READ,
            RolesAndCapabilities::PRIVATE_NOTE_CREATE,
            RolesAndCapabilities::CONSULT_START,
        ] as $cap) {
            $this->assertFalse(current_user_can($cap), "حسابدار نباید {$cap} داشته باشد");
        }
    }

    public function testManagerHasConfigButNoClinicalOrPrivateCapsAndNotAdmin(): void
    {
        wp_set_current_user($this->managerId);
        // باید‌ها: مدیریت ستادی/عملیاتی
        foreach ([RolesAndCapabilities::CONFIG, RolesAndCapabilities::SMS_CONFIG, RolesAndCapabilities::REPORT_READ, RolesAndCapabilities::PATIENT_READ] as $cap) {
            $this->assertTrue(current_user_can($cap), "مدیر کلینیک باید {$cap} داشته باشد");
        }
        // نبایدها: بالینی/خصوصی/نسخه/فایل
        foreach ([
            RolesAndCapabilities::MEDICAL_READ,
            RolesAndCapabilities::NOTE_CREATE,
            RolesAndCapabilities::REC_CREATE,
            RolesAndCapabilities::RX_CREATE,
            RolesAndCapabilities::FILE_READ,
            RolesAndCapabilities::PRIVATE_NOTE_READ,
            RolesAndCapabilities::PRIVATE_NOTE_CREATE,
        ] as $cap) {
            $this->assertFalse(current_user_can($cap), "مدیر کلینیک نباید {$cap} داشته باشد");
        }
        // نه ادمین وردپرس
        $this->assertFalse(current_user_can('manage_options'), 'مدیر کلینیک نباید manage_options داشته باشد (WordPress Administrator != Clinic Manager)');
    }

    // ================= 2) Staff (ایجاد/نقش/ضد-ارتقاء) =================

    public function testCreateAccountantAndManagerUsersViaStaffService(): void
    {
        wp_set_current_user($this->adminId);
        $acc = StaffManagementPage::upsertUser([
            'mode' => 'create', 'username' => 'accountant_g', 'display_name' => 'حسابدار تست',
            'email' => 'accountant_g@test.local', 'role' => RolesAndCapabilities::ROLE_ACCOUNTANT, 'password' => 'StrongPass123',
        ], $this->adminId);
        $this->assertSame('', $acc['error']);
        $this->assertContains(RolesAndCapabilities::ROLE_ACCOUNTANT, (array) get_user_by('login', 'accountant_g')->roles);

        $mgr = StaffManagementPage::upsertUser([
            'mode' => 'create', 'username' => 'manager_g', 'display_name' => 'مدیر تست',
            'email' => 'manager_g@test.local', 'role' => RolesAndCapabilities::ROLE_MANAGER, 'password' => 'StrongPass123',
        ], $this->adminId);
        $this->assertSame('', $mgr['error']);
        $this->assertContains(RolesAndCapabilities::ROLE_MANAGER, (array) get_user_by('login', 'manager_g')->roles);
    }

    public function testAdminRoleAssignmentRejected(): void
    {
        wp_set_current_user($this->adminId);
        $r = StaffManagementPage::upsertUser([
            'mode' => 'create', 'username' => 'evil_g', 'display_name' => 'کاربر غیرمجاز',
            'email' => 'evil_g@test.local', 'role' => 'administrator', 'password' => 'StrongPass123',
        ], $this->adminId);

        $this->assertStringContainsString('نقش غیرمجاز', $r['error'], 'انتساب administrator باید رد شود');
        $this->assertFalse((bool) get_user_by('login', 'evil_g'));
    }

    public function testManagerCanCreateStaffButCannotDeactivateSelf(): void
    {
        // مدیر کلینیک کاربر دیگری می‌سازد (Staff)
        wp_set_current_user($this->managerId);
        $r = StaffManagementPage::upsertUser([
            'mode' => 'create', 'username' => 'staff_by_mgr', 'display_name' => 'کارمند مدیر',
            'email' => 'staff_by_mgr@test.local', 'role' => RolesAndCapabilities::ROLE_SECRETARY, 'password' => 'StrongPass123',
        ], $this->managerId);
        $this->assertSame('', $r['error'], 'مدیر باید بتواند پرسنل بسازد');

        // غیرفعال‌سازی حساب خودش ممنوع (جلوگیری از قفل‌شدن)
        $selfToggle = StaffManagementPage::toggleUser($this->managerId, 'deactivate', $this->managerId);
        $this->assertStringContainsString('خودتان', $selfToggle['error']);
        $this->assertContains(RolesAndCapabilities::ROLE_MANAGER, (array) get_userdata($this->managerId)->roles);
    }

    public function testDeactivateAndReactivateManagerKeepsHistory(): void
    {
        wp_set_current_user($this->adminId);
        $d = StaffManagementPage::toggleUser($this->managerId, 'deactivate', $this->adminId);
        $this->assertSame('', $d['error']);
        $this->assertContains('subscriber', (array) get_userdata($this->managerId)->roles);
        $this->assertSame(RolesAndCapabilities::ROLE_MANAGER, (string) get_user_meta($this->managerId, 'cpms_previous_role', true));

        $a = StaffManagementPage::toggleUser($this->managerId, 'activate', $this->adminId);
        $this->assertSame('', $a['error']);
        $this->assertContains(RolesAndCapabilities::ROLE_MANAGER, (array) get_userdata($this->managerId)->roles);
    }

    // ================= 3) ماتریس دسترسی فقط برای مالک فنی =================

    public function testPermissionMatrixOnlyForTechnicalOwner(): void
    {
        wp_set_current_user($this->adminId);
        $this->assertTrue(RoleCapabilitiesPage::canEditMatrix(), 'ادمین باید بتواند ماتریس را ویرایش کند');

        wp_set_current_user($this->managerId);
        $this->assertFalse(RoleCapabilitiesPage::canEditMatrix(), 'مدیر کلینیک نباید بتواند مرز امنیتی را با چک‌باکس دور بزند');
    }

    // ================= 4) Negative authorization واقعی (REST) =================

    public function testAccountantDeniedClinicalRest(): void
    {
        wp_set_current_user($this->accountantId);
        $this->assertSame(403, $this->dispatch('GET', self::NS . '/visits/' . $this->visitId . '/record')->get_status(), 'حسابدار → clinical record DENIED');
        $this->assertSame(403, $this->dispatch('POST', self::NS . '/visits/' . $this->visitId . '/notes', ['category' => 'clinical_note', 'visibility' => 'patient_visible', 'content_text' => 'x'])->get_status(), 'حسابدار → clinical note DENIED');
        $this->assertSame(403, $this->dispatch('POST', self::NS . '/files', ['patient_id' => $this->patientId, 'category' => 'other'])->get_status(), 'حسابدار → clinical attachment DENIED');
    }

    public function testManagerDeniedClinicalRest(): void
    {
        wp_set_current_user($this->managerId);
        $this->assertSame(403, $this->dispatch('GET', self::NS . '/visits/' . $this->visitId . '/record')->get_status(), 'مدیر کلینیک → clinical record DENIED');
        $this->assertSame(403, $this->dispatch('POST', self::NS . '/visits/' . $this->visitId . '/notes', ['category' => 'clinical_note', 'visibility' => 'patient_visible', 'content_text' => 'x'])->get_status(), 'مدیر کلینیک → clinical note DENIED');
        $this->assertSame(403, $this->dispatch('POST', self::NS . '/files', ['patient_id' => $this->patientId, 'category' => 'other'])->get_status(), 'مدیر کلینیک → clinical attachment DENIED (پیش‌فرض)');
    }

    public function testSecretaryDeniedClinicalRest(): void
    {
        wp_set_current_user($this->secretaryId);
        $this->assertSame(403, $this->dispatch('GET', self::NS . '/visits/' . $this->visitId . '/record')->get_status(), 'منشی → clinical record DENIED');
        $this->assertSame(403, $this->dispatch('POST', self::NS . '/visits/' . $this->visitId . '/notes', ['category' => 'clinical_note', 'visibility' => 'patient_visible', 'content_text' => 'x'])->get_status(), 'منشی → clinical note DENIED');
    }

    public function testDoctorAllowedClinicalRest(): void
    {
        wp_set_current_user($this->doctorId);
        $this->assertSame(200, $this->dispatch('GET', self::NS . '/visits/' . $this->visitId . '/record')->get_status(), 'پزشکِ متصل باید بتواند clinical record را ببیند');
    }

    public function testAccountantAllowedFinanceButDeniedQueueToday(): void
    {
        wp_set_current_user($this->accountantId);
        // مالی مجاز (FINANCE_READ) — صفحهٔ «مالی و تسویه» حسابدار باید کار کند.
        $this->assertSame(200, $this->dispatch('GET', self::NS . '/finance/summary')->get_status(), 'حسابدار باید finance/summary را ببیند');
        // داشبورد صف امروز صرفاً با QUEUE_READ است → حسابدار (بدون آن) DENIED.
        $this->assertSame(403, $this->dispatch('GET', self::NS . '/secretary/today')->get_status(), 'حسابدار باید به «صف امروز» (QUEUE_READ) رست deny شود');
    }

    public function testManagerDeniedQueueTodayAndClinical(): void
    {
        wp_set_current_user($this->managerId);
        $this->assertSame(403, $this->dispatch('GET', self::NS . '/secretary/today')->get_status(), 'مدیر کلینیک باید به «صف امروز» (QUEUE_READ) deny شود');
        $this->assertSame(403, $this->dispatch('GET', self::NS . '/finance/summary')->get_status(), 'مدیر کلینیک بدون FINANCE_READ باید به مالی deny شود');
    }

    public function testForbiddenAccessAttemptsAudited(): void
    {
        wp_set_current_user($this->accountantId);
        $this->dispatch('GET', self::NS . '/visits/' . $this->visitId . '/record');

        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_audit_logs WHERE action = %s',
                'FORBIDDEN_ACCESS_ATTEMPT'
            ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        $this->assertGreaterThanOrEqual(1, $count, 'تلاش دسترسی ممنوع باید در Audit ثبت شود');
    }

    // ================= Helpers =================

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login . bin2hex(random_bytes(3)), 'pass-12345', $login . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }

    private function dispatch(string $method, string $route, array $body = []): \WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

        return rest_do_request($request);
    }

    /** @param list<string> $caps */
    private function sorted(array $caps): array
    {
        sort($caps);

        return $caps;
    }

    /** @return list<string> */
    private function liveCpmsCaps(string $role): array
    {
        $r = get_role($role);
        if ($r === null) {
            return [];
        }
        $caps = array_keys(array_filter($r->capabilities, static fn ($v) => $v !== false && $v !== 0 && $v !== ''));
        $caps = array_values(array_filter($caps, static fn (string $c): bool => str_starts_with($c, 'cpms_')));

        return $caps;
    }
}
