<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\ClinicianAdminPage;
use ClinicCore\Admin\CpmsAssets;
use ClinicCore\Admin\CpmsUi;
use ClinicCore\Admin\RoleCapabilitiesPage;
use ClinicCore\Admin\StaffManagementPage;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * Chunk F — طراحی/Responsive/Accessibility + Empty states + Dangerous-action UX.
 *
 * پوشش:
 *  - Asset ها فقط برای صفحات CPMS (allowlist) به‌صورت scoped لود می‌شوند؛ body class افزوده می‌شود
 *    (عملکرد: بدون کندی سراسری / تداخل با دیگر صفحات).
 *  - Empty state حرفه‌ای با next action (بدون پزشک / بدون پرسنل / بدون بکاپ).
 *  - صفحهٔ Permissions بازطراحی: Role Preset (`می‌تواند / نمی‌تواند`) + توضیح فارسی نقش +
 *    هشدار حساس + Advanced Permissions (جمع‌شونده، گروه‌بندی، جستجو) — با حفظ نام فیلد
 *    `role_caps[role][]` و مسیر امن backend.
 */
final class AdminUxDesignTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        unset($_GET['page'], $_GET['clinician_id']);
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function testAssetsDetectCpmsPages(): void
    {
        $_GET['page'] = 'cpms-roles';
        $this->assertTrue(CpmsAssets::isCpmsPage(), 'صفحهٔ cpms-roles باید CPMS محسوب شود');
        $this->assertStringContainsString('cpms-admin', (string) CpmsAssets::bodyClass('foo'), 'body class اسکوپ افزوده شود');

        $_GET['page'] = 'cpms-not-a-page';
        $this->assertFalse(CpmsAssets::isCpmsPage(), 'صفحهٔ ناشناس نباید CPMS محسوب شود');
        $this->assertSame('foo', (string) CpmsAssets::bodyClass('foo'), 'در صفحهٔ ناشناس body class تغییر نکند');
    }

    public function testEmptyStateRendersMarkupWithNextAction(): void
    {
        $html = CpmsUi::emptyState('📅', 'عنوان', 'توضیح گام بعدی', 'شروع', 'https://example.org/wizard');
        $this->assertStringContainsString('cpms-empty', $html);
        $this->assertStringContainsString('عنوان', $html);
        $this->assertStringContainsString('توضیح گام بعدی', $html);
        $this->assertStringContainsString('شروع', $html);
        $this->assertStringContainsString('https://example.org/wizard', $html);
    }

    public function testEmptyStateWithoutActionOmitsButton(): void
    {
        $html = CpmsUi::emptyState('📊', 'بدون نتیجه', 'گزارشی موجود نیست');
        $this->assertStringContainsString('cpms-empty', $html);
        $this->assertStringNotContainsString('cpms-empty-action', $html);
    }

    public function testRolePresetRenderShowsNormalAndAdvancedModes(): void
    {
        $html = $this->renderRoles();

        // Normal mode
        $this->assertStringContainsString('می‌تواند', $html, 'حالت عادی: می‌تواند');
        $this->assertStringContainsString('نمی‌تواند', $html, 'حالت عادی: نمی‌تواند');
        $this->assertStringContainsString('مسئول پذیرش و صف', $html, 'توضیح فارسی نقش منشی');
        // Advanced collapsible
        $this->assertStringContainsString('Advanced Permissions', $html, 'Advanced مجزا/جمع‌شونده باشد');
        $this->assertStringContainsString('cpms-cap-search', $html, 'جستجوی Capability باشد');
        // نام فیلد حفظ شده (Backend security)
        $this->assertStringContainsString('name="role_caps[cpms_secretary][]"', $html);
        // هشدار حساس (حداقل یکی از اجزای ⚠️)
        $this->assertStringContainsString('cpms-sensitive', $html);
    }

    public function testClinicianListShowsEmptyState(): void
    {
        // Slice-6A: render() فقط در Clinic-scoped معتبر فهرست را نشان می‌دهد؛
        // ادمینِ نصبِ بدون عضویت پوستهٔ fail-closed می‌گیرد (تست StaffList بالا
        // الگوی همان رفتار برای صفحهٔ پرسنل است). برای پوششِ واقعیِ empty-state،
        // با fixture داینامیک (Clinic + مدیر تک‌عضویت) و در مسیر scoped معتبر
        // رندر می‌کنیم — بدون هیچ ID ثابت/رزروی (قاعدهٔ tenant).
        $this->setUpScopedClinicWithManager();
        unset($_GET['clinician_id']);
        $html = $this->render(fn () => ClinicianAdminPage::render());
        $this->assertStringContainsString('cpms-empty', $html);
        $this->assertStringContainsString('هنوز پزشکی ثبت نشده', $html);
        $this->assertStringContainsString('افزودن اولین پزشک', $html);
    }

    public function testStaffListShowsEmptyState(): void
    {
        $html = $this->render(fn () => StaffManagementPage::render());
        $this->assertStringContainsString('cpms-empty', $html);
        $this->assertStringContainsString('هنوز پرسنلی ثبت نشده', $html);
    }

    private function renderRoles(): string
    {
        return $this->render(fn () => RoleCapabilitiesPage::render());
    }

    /**
     * Clinic داینامیک + مدیرِ تک‌عضویت برای مسیر scoped معتبرِ render()
     * (بدون ID ثابت/رزروی — قاعدهٔ tenant fixtures).
     */
    private function setUpScopedClinicWithManager(): int
    {
        global $wpdb;

        $unique = bin2hex(random_bytes(4));
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'UX Org ' . $unique,
                'ux-org-' . $unique,
                'active',
                $now,
                $now
            )
        );
        $orgId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $orgId, 'precondition: dynamic organization fixture');

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $orgId,
                'UX Clinic ' . $unique,
                'ux-clinic-' . $unique,
                'UTC',
                $now,
                $now
            )
        );
        $clinicId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $clinicId, 'precondition: dynamic clinic fixture');

        $mgrId = (int) self::factory()->user->create(['role' => 'cpms_manager']);
        cpms_test_seed_membership($mgrId, $clinicId, 'cpms_manager');
        wp_set_current_user($mgrId);

        return $clinicId;
    }

    /**
     * @param callable(): void $fn
     */
    private function render(callable $fn): string
    {
        ob_start();
        try {
            $fn();
        } finally {
            $out = (string) ob_get_clean();
        }

        return $out;
    }
}
