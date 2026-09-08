<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\CpmsAdminMenu;
use ClinicCore\Admin\StaffManagementPage;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * Chunk C — «کاربران و دسترسی‌ها» (Staff/User Management).
 *
 * پوشش:
 *  - ثبت زیرمنوی «کاربران و دسترسی‌ها» تحت «مدیریت مطب».
 *  - ایجاد کاربر امن (WP API) + Audit؛ رمز تعیین‌شده یا خودکار (CSPRNG) بدون plaintext.
 *  - رد انتساب role=administrator (جلوگیری از privilege escalation).
 *  - رد رمز ضعیف و نام کاربری نامعتبر.
 *  - ویرایش نقش/نام/ایمیل + Audit.
 *  - غیرفعال/فعال‌سازی: نقش به‌طور موقت به subscriber تغییر می‌کند (تاریخچه حذف نمی‌شود)،
 *    نقش قبلی در usermeta نگه‌داری و هنگام فعال‌سازی بازگردانده می‌شود.
 *  - رد غیرفعال‌سازی کاربری که نقش قابل‌مدیریت ندارد (مثلاً administrator).
 */
final class StaffManagementTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    protected function makeAdmin(): int
    {
        $id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($id);

        return (int) $id;
    }

    public function testStaffSubmenuRegisteredUnderCpmsMenu(): void
    {
        $this->makeAdmin();
        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];

        CpmsAdminMenu::menu();
        StaffManagementPage::menu();

        $sub = $GLOBALS['submenu']['cpms-dashboard'] ?? [];
        $slugs = array_map(static fn ($r) => $r[2] ?? '', $sub);

        $this->assertContains(StaffManagementPage::PAGE_SLUG, $slugs, 'زیرمنوی «کاربران و دسترسی‌ها» باید تحت «مدیریت مطب» ثبت شود');
    }

    public function testCreateUserWithExplicitPassword(): void
    {
        $adminId = $this->makeAdmin();
        $r = StaffManagementPage::upsertUser([
            'mode' => 'create',
            'username' => 'staff_doc_1',
            'display_name' => 'دکتر تست',
            'email' => 'doc1@test.local',
            'role' => RolesAndCapabilities::ROLE_DOCTOR,
            'password' => 'StrongPass123',
        ], $adminId);

        $this->assertSame('', $r['error'], 'ایجاد کاربر معتبر نباید خطا بدهد');
        $this->assertSame('', $r['generated']);

        $u = get_user_by('login', 'staff_doc_1');
        $this->assertNotFalse($u, 'کاربر باید ساخته شود');
        $this->assertContains(RolesAndCapabilities::ROLE_DOCTOR, (array) $u->roles);
        $this->assertTrue(wp_check_password('StrongPass123', (string) $u->user_pass), 'رمز باید به‌صورت hash قابل اعتبارسنجی باشد');
    }

    public function testCreateUserAutoGeneratesStrongPassword(): void
    {
        $adminId = $this->makeAdmin();
        $r = StaffManagementPage::upsertUser([
            'mode' => 'create',
            'username' => 'staff_auto_1',
            'display_name' => 'منشی خودکار',
            'email' => 'auto1@test.local',
            'role' => RolesAndCapabilities::ROLE_SECRETARY,
            'password' => '',
        ], $adminId);

        $this->assertSame('', $r['error']);
        $this->assertNotSame('', $r['generated'], 'در صورت عدم تعیین رمز باید رمز قوی تولید شود');

        $u = get_user_by('login', 'staff_auto_1');
        $this->assertNotFalse($u);
        $this->assertTrue(wp_check_password($r['generated'], (string) $u->user_pass), 'رمز تولیدی باید با hash کاربر مطابقت داشته باشد');
    }

    public function testRejectsAdministratorRoleAssignment(): void
    {
        $adminId = $this->makeAdmin();
        $r = StaffManagementPage::upsertUser([
            'mode' => 'create',
            'username' => 'evil_admin',
            'display_name' => 'کاربر غیرمجاز',
            'email' => 'evil@test.local',
            'role' => 'administrator',
            'password' => 'StrongPass123',
        ], $adminId);

        $this->assertStringContainsString('نقش غیرمجاز', $r['error'], 'انتساب administrator باید رد شود (privilege escalation)');
        $this->assertFalse((bool) get_user_by('login', 'evil_admin'), 'کاربر با نقش administrator ساخته نشود');
    }

    public function testRejectsWeakPassword(): void
    {
        $adminId = $this->makeAdmin();
        $r = StaffManagementPage::upsertUser([
            'mode' => 'create',
            'username' => 'weak_user',
            'display_name' => 'رمز ضعیف',
            'email' => 'weak@test.local',
            'role' => RolesAndCapabilities::ROLE_SECRETARY,
            'password' => '123', // کوتاه
        ], $adminId);

        $this->assertStringContainsString('رمز عبور', $r['error']);
        $this->assertFalse((bool) get_user_by('login', 'weak_user'));
    }

    public function testRejectsInvalidUsername(): void
    {
        $adminId = $this->makeAdmin();
        $r = StaffManagementPage::upsertUser([
            'mode' => 'create',
            'username' => 'bad username!',
            'display_name' => 'نام کاربری بد',
            'email' => 'bad@test.local',
            'role' => RolesAndCapabilities::ROLE_SECRETARY,
            'password' => 'StrongPass123',
        ], $adminId);

        $this->assertStringContainsString('نام کاربری', $r['error']);
    }

    public function testUpdateUserRoleAndEmail(): void
    {
        $adminId = $this->makeAdmin();
        $r = StaffManagementPage::upsertUser([
            'mode' => 'create',
            'username' => 'upd_user',
            'display_name' => 'کاربر ویرایش',
            'email' => 'upd@test.local',
            'role' => RolesAndCapabilities::ROLE_DOCTOR,
            'password' => 'StrongPass123',
        ], $adminId);
        $this->assertSame('', $r['error']);
        $uid = (int) get_user_by('login', 'upd_user')->ID;

        $r2 = StaffManagementPage::upsertUser([
            'mode' => 'update',
            'user_id' => $uid,
            'display_name' => 'کاربر ویرایش جدید',
            'email' => 'upd2@test.local',
            'role' => RolesAndCapabilities::ROLE_SECRETARY,
            'password' => '',
        ], $adminId);

        $this->assertSame('', $r2['error']);
        $u = get_userdata($uid);
        $this->assertSame('کاربر ویرایش جدید', (string) $u->display_name);
        $this->assertSame('upd2@test.local', (string) $u->user_email);
        $this->assertContains(RolesAndCapabilities::ROLE_SECRETARY, (array) $u->roles);
    }

    public function testDeactivateAndReactivatePreservesHistory(): void
    {
        $adminId = $this->makeAdmin();
        $r = StaffManagementPage::upsertUser([
            'mode' => 'create',
            'username' => 'sec_toggle',
            'display_name' => 'منشی تست',
            'email' => 'sec_toggle@test.local',
            'role' => RolesAndCapabilities::ROLE_SECRETARY,
            'password' => 'StrongPass123',
        ], $adminId);
        $this->assertSame('', $r['error']);
        $uid = (int) get_user_by('login', 'sec_toggle')->ID;

        // غیرفعال
        $d = StaffManagementPage::toggleUser($uid, 'deactivate', $adminId);
        $this->assertSame('', $d['error'], 'غیرفعال‌سازی نباید خطا بدهد');
        $u = get_userdata($uid);
        $this->assertContains('subscriber', (array) $u->roles, 'نقش CPMS موقتاً به subscriber تغییر می‌کند');
        $this->assertSame(RolesAndCapabilities::ROLE_SECRETARY, (string) get_user_meta($uid, 'cpms_previous_role', true), 'نقش قبلی در usermeta حفظ شود');

        // فعال‌سازی مجدد
        $a = StaffManagementPage::toggleUser($uid, 'activate', $adminId);
        $this->assertSame('', $a['error']);
        $u2 = get_userdata($uid);
        $this->assertContains(RolesAndCapabilities::ROLE_SECRETARY, (array) $u2->roles, 'نقش قبلی باید بازگردد');
        $this->assertSame('', (string) get_user_meta($uid, 'cpms_previous_role', true), 'usermeta نقش قبلی پس از فعال‌سازی پاک شود');
    }

    public function testCannotDeactivateAdmin(): void
    {
        $adminId = $this->makeAdmin();
        $r = StaffManagementPage::toggleUser($adminId, 'deactivate', $adminId);

        $this->assertStringContainsString('قابل مدیریت نیست', $r['error']);
        $u = get_userdata($adminId);
        $this->assertContains('administrator', (array) $u->roles, 'نقش administrator دست‌نخورده بماند');
    }

    // ================= Chunk G — نقش‌های جدید (حسابدار/مدیر) =================

    public function testCreateAccountantUser(): void
    {
        $adminId = $this->makeAdmin();
        $r = StaffManagementPage::upsertUser([
            'mode' => 'create',
            'username' => 'staff_acc_g',
            'display_name' => 'حسابدار جدید',
            'email' => 'acc_g@test.local',
            'role' => RolesAndCapabilities::ROLE_ACCOUNTANT,
            'password' => 'StrongPass123',
        ], $adminId);

        $this->assertSame('', $r['error'], 'ایجاد حسابدار باید موفق باشد');
        $u = get_user_by('login', 'staff_acc_g');
        $this->assertNotFalse($u);
        $this->assertContains(RolesAndCapabilities::ROLE_ACCOUNTANT, (array) $u->roles);
        // هم‌اکنون نقشِ حسابدار به‌عنوان کاربر جاری → نباید به بالینی/خصوصی دسترسی داشته باشد.
        wp_set_current_user((int) $u->ID);
        $this->assertFalse(current_user_can(RolesAndCapabilities::MEDICAL_READ), 'حسابدار نباید به بالینی دسترسی داشته باشد');
        $this->assertFalse(current_user_can(RolesAndCapabilities::QUEUE_READ), 'حسابدار نباید به صف امروز دسترسی داشته باشد');
        $this->assertTrue(current_user_can(RolesAndCapabilities::FINANCE_READ), 'حسابدار باید به مالی دسترسی داشته باشد');
    }

    public function testCreateManagerUser(): void
    {
        $adminId = $this->makeAdmin();
        $r = StaffManagementPage::upsertUser([
            'mode' => 'create',
            'username' => 'staff_mgr_g',
            'display_name' => 'مدیر جدید',
            'email' => 'mgr_g@test.local',
            'role' => RolesAndCapabilities::ROLE_MANAGER,
            'password' => 'StrongPass123',
        ], $adminId);

        $this->assertSame('', $r['error'], 'ایجاد مدیر کلینیک باید موفق باشد');
        $u = get_user_by('login', 'staff_mgr_g');
        $this->assertNotFalse($u);
        $this->assertContains(RolesAndCapabilities::ROLE_MANAGER, (array) $u->roles);
    }

    public function testCannotDeactivateOwnAccount(): void
    {
        // مدیر کلینیکی که خودش مدیریت می‌کند نباید بتواند حساب خودش را غیرفعال کند.
        $mgrId = (int) wp_create_user('mgr_self_g', 'StrongPass123', 'mgr_self@test.local');
        wp_update_user(['ID' => $mgrId, 'role' => RolesAndCapabilities::ROLE_MANAGER]);
        wp_set_current_user($mgrId);

        $r = StaffManagementPage::toggleUser($mgrId, 'deactivate', $mgrId);
        $this->assertStringContainsString('خودتان', $r['error'], 'غیرفعال‌سازی حساب خود باید رد شود (جلوگیری از قفل‌شدن)');
        $this->assertContains(RolesAndCapabilities::ROLE_MANAGER, (array) get_userdata($mgrId)->roles);
    }
}
