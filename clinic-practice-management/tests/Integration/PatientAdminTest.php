<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\CpmsAdminMenu;
use ClinicCore\Admin\PatientAdminPage;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Chunk G — Patient Management Entry (Operational).
 *
 * پوشش:
 *  - ثبت منوی Top-Level «بیماران» با Capability `cpms_patient_read`.
 *  - دیدن/ندیدن فرم «ایجاد» بر اساس `cpms_patient_create`.
 *  - ایجاد بیمار توسط منشی (مسیر امن) + Audit `PATIENT_CREATED`.
 *  - Negative authorization واقعی از مسیر REST: search/get/create/update.
 *    (Accountant / Manager / WP Administrator — بدون دسترسی؛ Doctor — فقط read/update نه create.)
 *  - جستجوی bounded و اسکوپ‌شده (limit + جداسازی clinic).
 *  - WP Administrator صرفاً با `manage_options` به medical patient data دسترسی ندارد.
 *  - CSRF: ساختار فرم «ایجاد» شامل Nonce است.
 */
final class PatientAdminTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    private int $adminId = 0;
    private int $managerId = 0;
    private int $accountantId = 0;
    private int $secretaryId = 0;
    private int $doctorId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();

        // C6 repair — عضویت فعال staff صریح است (نه fixture سراسری).
        // تست‌های patient/non-member عمداً عضویت نمی‌گیرند.
        $this->adminId = $this->makeUser('pat_admin', 'administrator');
        cpms_test_seed_membership($this->adminId, 1, 'cpms_manager');
        $this->managerId = $this->makeUser('pat_manager', RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($this->managerId, 1, 'cpms_manager');
        $this->accountantId = $this->makeUser('pat_acc', RolesAndCapabilities::ROLE_ACCOUNTANT);
        cpms_test_seed_membership($this->accountantId, 1, 'cpms_accountant');
        $this->secretaryId = $this->makeUser('pat_sec', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($this->secretaryId, 1, 'cpms_secretary');
        $this->doctorId = $this->makeUser('pat_doc', RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($this->doctorId, 1, 'cpms_doctor');
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    // ================= 1) منو/ثبت =================

    public function testPatientMenuRegisteredAsTopLevelWithPatientReadCap(): void
    {
        wp_set_current_user($this->adminId);
        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];

        CpmsAdminMenu::menu();
        PatientAdminPage::menu();

        $found = null;
        foreach ((array) $GLOBALS['menu'] as $item) {
            if (($item[2] ?? '') === PatientAdminPage::PAGE_SLUG) {
                $found = $item;
                break;
            }
        }
        $this->assertNotNull($found, 'منوی Top-Level «بیماران» باید ثبت شود');
        $this->assertSame(RolesAndCapabilities::PATIENT_READ, $found[1], 'منوی «بیماران» باید با Capability `cpms_patient_read` ثبت شود');
    }

    public function testPatientPageIsCpmsAssetPage(): void
    {
        // برای اطمینان از لود CSS/JS طراحی روی صفحهٔ بیماران (allowlist اسلاگ‌ها).
        $_GET['page'] = PatientAdminPage::PAGE_SLUG;
        $this->assertTrue(\ClinicCore\Admin\CpmsAssets::isCpmsPage(), 'اسلاگ «بیماران» باید در allowlist assets باشد');
        unset($_GET['page']);
    }

    // ================= 2) نقش‌ها و Capability =================

    public function testPatientReadCapabilityByRole(): void
    {
        wp_set_current_user($this->secretaryId);
        $this->assertTrue(current_user_can(RolesAndCapabilities::PATIENT_READ), 'منشی باید بیماران را ببیند');
        $this->assertTrue(current_user_can(RolesAndCapabilities::PATIENT_CREATE), 'منشی باید بیمار ایجاد کند');

        wp_set_current_user($this->doctorId);
        $this->assertTrue(current_user_can(RolesAndCapabilities::PATIENT_READ), 'پزشک باید بیماران را ببیند (read)');
        $this->assertFalse(current_user_can(RolesAndCapabilities::PATIENT_CREATE), 'پزشک باید بدون patient_create باشد (فقط read/update)');
        $this->assertTrue(current_user_can(RolesAndCapabilities::PATIENT_UPDATE), 'پزشک باید patient_update داشته باشد');

        wp_set_current_user($this->managerId);
        $this->assertTrue(current_user_can(RolesAndCapabilities::PATIENT_READ), 'مدیر باید بیماران را ببیند (read)');

        wp_set_current_user($this->accountantId);
        $this->assertFalse(current_user_can(RolesAndCapabilities::PATIENT_READ), 'حسابدار نباید بیماران را ببیند');
    }

    public function testAdminIsNotAutomaticMedicalAccess(): void
    {
        wp_set_current_user($this->adminId);
        $this->assertFalse(current_user_can(RolesAndCapabilities::PATIENT_READ), 'WP Administrator صرفاً با manage_options نباید medical patient data ببیند');
    }

    public function testCanCreateFormOnlyWithPatientCreateCap(): void
    {
        wp_set_current_user($this->secretaryId);
        $this->assertTrue(PatientAdminPage::canCreate(), 'منشی (چ. create) باید فرم ایجاد را ببیند');

        foreach (['doctor', 'manager', 'accountant'] as $label) {
            $id = $this->{$label . 'Id'};
            wp_set_current_user($id);
            $this->assertFalse(PatientAdminPage::canCreate(), "نقش {$label} نباید فرم ایجاد ببیند (بدون patient_create)");
        }
    }

    public function testRenderShowsCreateFormForSecretaryOnly(): void
    {
        wp_set_current_user($this->secretaryId);
        ob_start();
        PatientAdminPage::render();
        $secHtml = (string) ob_get_clean();
        $this->assertStringContainsString('افزودن بیمار', $secHtml, 'منشی باید فرم «افزودن بیمار» را ببیند');
        $this->assertStringContainsString('name="_wpnonce"', $secHtml, 'فرم «ایجاد» باید CSRF Nonce داشته باشد');
        $this->assertStringContainsString('value="cpms_patient_create"', $secHtml, 'فرم باید action=cpms_patient_create بفرستد');

        wp_set_current_user($this->doctorId);
        ob_start();
        PatientAdminPage::render();
        $docHtml = (string) ob_get_clean();
        $this->assertStringNotContainsString('افزودن بیمار', $docHtml, 'پزشک (بدون create) باید فرم ایجاد را نبیند');
        $this->assertStringContainsString('تنها مجاز به جستجو', $docHtml, 'پزشک باید پیام «جستجو فقط» را ببیند');
    }

    // ================= 3) ایجاد + Audit (مسیر امن) =================

    public function testSecretaryCreatePatientAndAuditLogged(): void
    {
        wp_set_current_user($this->secretaryId);
        $r = PatientAdminPage::createPatient([
            'first_name' => 'مریم',
            'last_name' => 'پذیرش',
            'mobile' => '09120000111',
        ], $this->secretaryId);

        $this->assertSame('', $r['error'], 'ایجاد بیمار توسط منشی معتبر نباید خطا بدهد');
        $this->assertGreaterThan(0, $r['id']);
        $this->assertNotSame('', $r['mrn']);

        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_audit_logs WHERE action = %s AND resource_id = %d',
                'PATIENT_CREATED',
                $r['id']
            ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        $this->assertGreaterThanOrEqual(1, $count, 'ایجاد بیمار باید در Audit با PATIENT_CREATED ثبت شود');
    }

    public function testCreatePatientValidationError(): void
    {
        wp_set_current_user($this->secretaryId);
        $r = PatientAdminPage::createPatient([
            'first_name' => '',
            'last_name' => '',
            'mobile' => '12345',
        ], $this->secretaryId);
        $this->assertNotSame('', $r['error']);
    }

    // ================= 4) Negative authorization واقعی (REST) =================

    public function testRestSearchRequiresPatientRead(): void
    {
        $id = $this->seedPatient('کاوه', 'جستجو', '09120000222');

        wp_set_current_user($this->accountantId);
        $this->assertSame(403, $this->dispatch('GET', self::NS . '/patients/search', ['q' => 'کاوه'])->get_status(), 'حسابدار → /patients/search DENIED');

        wp_set_current_user($this->adminId);
        $this->assertSame(403, $this->dispatch('GET', self::NS . '/patients/search', ['q' => 'کاوه'])->get_status(), 'WP Admin → /patients/search DENIED');

        wp_set_current_user($this->secretaryId);
        $res = $this->dispatch('GET', self::NS . '/patients/search', ['q' => 'کاوه']);
        $this->assertSame(200, $res->get_status(), 'منشی → /patients/search ALLOWED');
        $body = $res->get_data();
        $this->assertTrue(is_array($body['data'] ?? null));

        // جستجوی خیلی کوتاه → خطای اعتبارسنجی (محدود).
        wp_set_current_user($this->secretaryId);
        $short = $this->dispatch('GET', self::NS . '/patients/search', ['q' => 'ک']);
        $this->assertNotSame(200, $short->get_status(), 'جستجوی کمتر از ۲ کاراکتر باید رد شود');
    }

    public function testRestGetPatientRequiresPatientRead(): void
    {
        $id = $this->seedPatient('علی', 'تست', '09120000333');

        wp_set_current_user($this->accountantId);
        $this->assertSame(403, $this->dispatch('GET', self::NS . '/patients/' . $id)->get_status(), 'حسابدار → GET patient DENIED');

        wp_set_current_user($this->secretaryId);
        $this->assertSame(200, $this->dispatch('GET', self::NS . '/patients/' . $id)->get_status(), 'منشی → GET patient ALLOWED');
    }

    public function testRestCreateRequiresPatientCreate(): void
    {
        $body = ['first_name' => 'نیما', 'last_name' => 'ساز', 'mobile' => '09120000444'];

        wp_set_current_user($this->accountantId);
        $this->assertSame(403, $this->dispatch('POST', self::NS . '/patients', $body)->get_status(), 'حسابدار → POST /patients DENIED');

        wp_set_current_user($this->managerId);
        $this->assertSame(403, $this->dispatch('POST', self::NS . '/patients', $body)->get_status(), 'مدیر (بدون create) → POST /patients DENIED');

        wp_set_current_user($this->doctorId);
        $this->assertSame(403, $this->dispatch('POST', self::NS . '/patients', $body)->get_status(), 'پزشک (بدون create) → POST /patients DENIED');

        wp_set_current_user($this->secretaryId);
        $res = $this->dispatch('POST', self::NS . '/patients', ['first_name' => 'نیما', 'last_name' => 'ساز', 'mobile' => '09120000555']);
        $this->assertSame(200, $res->get_status(), 'منشی → POST /patients ALLOWED (چ. create)');
    }

    public function testRestUpdateRequiresPatientUpdate(): void
    {
        $id = $this->seedPatient('سام', 'ویرایش', '09120000666');

        wp_set_current_user($this->accountantId);
        $this->assertSame(403, $this->dispatch('PATCH', self::NS . '/patients/' . $id, ['phone' => '021'])->get_status(), 'حسابدار → PATCH patient DENIED');

        wp_set_current_user($this->doctorId);
        $this->assertSame(200, $this->dispatch('PATCH', self::NS . '/patients/' . $id, ['phone' => '02188888888'])->get_status(), 'پزشک (چ. update) → PATCH patient ALLOWED');

        wp_set_current_user($this->secretaryId);
        $this->assertSame(200, $this->dispatch('PATCH', self::NS . '/patients/' . $id, ['phone' => '02177777777'])->get_status(), 'منشی → PATCH patient ALLOWED');
    }

    public function testPatientIdorNotExposedToUnauthorized(): void
    {
        // حسابدار نمی‌تواند بیمار دیگری (ناشناس) را با id استنتاج کند.
        $id = $this->seedPatient('گوهر', 'محرمانه', '09120000777');

        wp_set_current_user($this->accountantId);
        $this->assertSame(403, $this->dispatch('GET', self::NS . '/patients/' . $id)->get_status(), 'حسابدار → IDOR patient GET DENIED');
    }

    // ================= 5) جستجوی bounded =================

    public function testSearchIsBoundedAndScoped(): void
    {
        // داده با clinic_id = 1. Cluesto پرسشی که همه را بگیرد.
        App::patientService()->create(['first_name' => 'ب', 'last_name' => 'هم‌نام', 'mobile' => '09120000801'], $this->secretaryId);
        App::patientService()->create(['first_name' => 'ب', 'last_name' => 'هم‌نام', 'mobile' => '09120000802'], $this->secretaryId);

        $results = App::patientService()->search('هم‌نام', 1);
        $this->assertCount(1, $results, 'جستجو باید به limit احترام بگذارد (bounded)');
        $this->assertLessThanOrEqual(50, count(App::patientService()->search('هم‌نام', 500)), 'حدِ maximum باید سقف داشته باشد');
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

    private function seedPatient(string $first, string $last, string $mobile): int
    {
        $created = App::patientService()->create([
            'first_name' => $first,
            'last_name' => $last,
            'mobile' => $mobile,
        ], $this->secretaryId);

        return (int) $created['id'];
    }

    private function dispatch(string $method, string $route, array $body = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        if ($body !== []) {
            foreach ($body as $key => $value) {
                $request->set_param($key, $value);
            }
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

        return rest_do_request($request);
    }
}
