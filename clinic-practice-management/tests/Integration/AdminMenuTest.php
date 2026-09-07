<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\CpmsAdminMenu;
use ClinicCore\Admin\CpmsDashboard;
use ClinicCore\Auth\RolesAndCapabilities;
use WP_UnitTestCase;

/**
 * IA Chunk A — منوی Top-Level «مدیریت مطب» + زیرمنوها + Role-Aware visibility.
 *
 * این تست‌ها فقط «ثبت منو» و «نمایش بر اساس Capability» را می‌سنجند، نه authorize
 * بک‌اند (که در PermissionMatrixTest و سیستم authorization پوشش داده می‌شود).
 */
final class AdminMenuTest extends WP_UnitTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    /**
     * اجرای مستقیم ثبت‌کننده منو (بدون action side-effect و بدون وابستگی به
     * ترتیب/وضعیت اکشن‌های admin_menu از تست‌های دیگر).
     */
    private function runAdminMenu(): void
    {
        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];
        CpmsAdminMenu::menu();
    }

    public function testTopLevelMenuRegistered(): void
    {
        $this->runAdminMenu();

        $item = $this->findTopLevel('cpms-dashboard');
        $this->assertNotNull($item, 'Top-Level «مدیریت مطب» باید ثبت شود');
        $this->assertSame('مدیریت مطب', $item[0], 'عنوان منوی Top-Level باید «مدیریت مطب» باشد');
    }

    /**
     * @return array<int, mixed>|null
     */
    private function findTopLevel(string $slug): ?array
    {
        foreach ($GLOBALS['menu'] ?? [] as $item) {
            if (is_array($item) && ($item[2] ?? '') === $slug) {
                return $item;
            }
        }

        return null;
    }

    public function testRehomedSubmenusExist(): void
    {
        $this->runAdminMenu();

        $sub = $GLOBALS['submenu']['cpms-dashboard'] ?? [];
        $slugs = [];
        foreach ($sub as $row) {
            $slugs[] = $row[2] ?? '';
        }

        foreach (['cpms-system', 'cpms-settings', 'cpms-clinicians', 'cpms-roles', 'cpms-sms'] as $expected) {
            $this->assertContains($expected, $slugs, "زیرمنوی {$expected} باید تحت «مدیریت مطب» باشد");
        }
    }

    public function testRehomedSubmenusDoNotAppearUnderToolsOrSettings(): void
    {
        $this->runAdminMenu();

        $tools = $GLOBALS['submenu']['tools.php'] ?? [];
        $settings = $GLOBALS['submenu']['options-general.php'] ?? [];
        $slugs = [];
        foreach (array_merge($tools, $settings) as $row) {
            $slugs[] = $row[2] ?? '';
        }

        foreach (['cpms-system', 'cpms-settings', 'cpms-clinicians', 'cpms-roles', 'cpms-sms'] as $expected) {
            $this->assertNotContains($expected, $slugs, "زیرمنوی {$expected} نباید در Tools/Settings باشد");
        }
    }

    public function testActionLinksPresentForAdmin(): void
    {
        wp_set_current_user($this->admin);

        $links = CpmsAdminMenu::actionLinks(['<a href="#">Activate</a>']);

        $joined = implode(' ', $links);
        $this->assertStringContainsString('داشبورد CPMS', $joined, 'مدیر باید لینک «داشبورد CPMS» را ببیند');
        $this->assertStringContainsString('تنظیمات', $joined, 'مدیر باید لینک «تنظیمات» را ببیند');
    }

    public function testActionLinksHiddenForCapabilitylessUser(): void
    {
        $user = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user);
        // subscriber هیچ cpms_* capability ندارد → لینک نباید ظاهر شود (بدون توان ارتقاء).
        $links = CpmsAdminMenu::actionLinks(['<a href="#">Activate</a>']);

        $this->assertSame(['<a href="#">Activate</a>'], $links, 'کاربر بدون مجوز نباید لینک‌های مدیر را ببیند');
    }

    public function testRoleAwareVisibilityOfDashboard(): void
    {
        // مدیر: باید دسترسی داشته باشد.
        wp_set_current_user($this->admin);
        $this->assertTrue(current_user_can(RolesAndCapabilities::CONFIG));

        // منشی (بدون cpms_config): نباید دسترسی داشبورد مدیریتی داشته باشد.
        $secretaryUser = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_SECRETARY]);
        wp_set_current_user($secretaryUser);
        $this->assertFalse(current_user_can(RolesAndCapabilities::CONFIG), 'منشی نباید cpms_config داشته باشد');
    }
}
