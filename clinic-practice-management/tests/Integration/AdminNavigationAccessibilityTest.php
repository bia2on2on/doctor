<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\CpmsAssets;
use ClinicCore\Admin\CpmsDashboard;
use ClinicCore\Admin\CustomRoleDefinitionsPage;
use ClinicCore\Admin\FinanceDelegationPage;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * CPMS Classic Admin UX (A) — دسترسی‌پذیریِ ناوبری و اسکوپِ assets.
 *
 * ریشهٔ نقصی که این تست تثبیت می‌کند:
 *  - صفحهٔ «واگذاری مالی حساس» با capability عمومی `read` ثبت می‌شد اما زیرِ
 *    منوی «مدیریت مطب» (`cpms-dashboard`) قرار داشت که خودش `cpms_config`
 *    (یک Capability WordPress-global) می‌خواهد. مدیری که مجوزش فقط در سطح
 *    Clinic (عضویت فعال + نقش/مجوز در پایگاه‌داده) است، منو را نمی‌دید و
 *    `user_can_access_admin_page()` مسیر مستقیم را هم رد می‌کرد.
 *
 * این تست فقط «ثبت/دسترسی‌پذیریِ منو» را می‌سنجد و هیچ مجوزی را ادعا نمی‌کند؛
 * مجوز واقعی همچنان در FinanceDelegationService/AuthorizationService سنجیده
 * می‌شود (پوشش در CustomRoleLifecycleTest و PermissionMatrixTest).
 */
final class AdminNavigationAccessibilityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // add_submenu_page() بازگشتِ خودکار برای capability کاربرِ جاری دارد؛
        // این کلاس «ثبتِ منو» را می‌سنجد، نه مجوز را — پس یک Administrator
        // (که `read` دارد) زمینهٔ لازم است. مجوز واقعی همچنان در سرویس‌ها
        // سنجیده می‌شود و در این فایل ادعا نمی‌شود.
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    protected function tearDown(): void
    {
        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];
        wp_set_current_user(0);
        App::resetScope();
        parent::tearDown();
    }

    /**
     * @return array<int, mixed>|null
     */
    private function findTopLevel( string $slug ): ?array
    {
        foreach ( $GLOBALS['menu'] ?? [] as $item ) {
            if ( is_array( $item ) && ( $item[2] ?? '' ) === $slug ) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function submenuOf( string $parent ): array
    {
        $rows = $GLOBALS['submenu'][ $parent ] ?? [];

        return is_array( $rows ) ? array_values( $rows ) : [];
    }

    public function testFinanceDelegationIsReachableFromAReadGatedParent(): void
    {
        $GLOBALS['menu']    = [];
        $GLOBALS['submenu'] = [];

        CustomRoleDefinitionsPage::menu();
        FinanceDelegationPage::menu();

        $parent = $this->findTopLevel( CustomRoleDefinitionsPage::PAGE_SLUG );
        self::assertNotNull( $parent, 'والدِ read-gated باید ثبت شود.' );
        self::assertSame( 'read', $parent[1], 'والد نباید به cpms_config وابسته باشد.' );

        $found = null;
        foreach ( $this->submenuOf( CustomRoleDefinitionsPage::PAGE_SLUG ) as $row ) {
            if ( ( $row[2] ?? '' ) === FinanceDelegationPage::PAGE_SLUG ) {
                $found = $row;
            }
        }

        self::assertNotNull( $found, 'صفحهٔ واگذاری مالی باید از یک والدِ read-gated قابلِ دسترس باشد.' );
        self::assertSame( 'read', $found[1], 'لایهٔ منو نباید چیزی بیش از read مطالبه کند (مجوز واقعی سمتِ سرور است).' );
    }

    public function testFinanceDelegationNoLongerHidesBehindTheConfigGatedDashboard(): void
    {
        $GLOBALS['menu']    = [];
        $GLOBALS['submenu'] = [];

        // والدِ CONFIGمحور فقط به‌عنوان «ظرف» ثبت می‌شود (add_menu_page بررسی
        // capability ندارد) تا مطمئن شویم صفحه به این والد نمی‌چسبد.
        add_menu_page( 'داشبورد', 'داشبورد', 'read', CpmsDashboard::PAGE_SLUG, '__return_null' );

        CustomRoleDefinitionsPage::menu();
        FinanceDelegationPage::menu();

        $dashboard_slugs = array_map(
            static fn ( array $row ): string => (string) ( $row[2] ?? '' ),
            $this->submenuOf( CpmsDashboard::PAGE_SLUG )
        );
        self::assertNotContains(
            FinanceDelegationPage::PAGE_SLUG,
            $dashboard_slugs,
            'صفحهٔ واگذاری مالی نباید زیرِ منوی CONFIGمحورِ داشبورد باشد.'
        );

        // کنترلِ مثبت: همان ثبت واقعاً رخ داده و زیرِ والدِ read-gated نشسته است؛
        // وگرنه ادعای بالا تهی می‌بود.
        $parent_slugs = array_map(
            static fn ( array $row ): string => (string) ( $row[2] ?? '' ),
            $this->submenuOf( CustomRoleDefinitionsPage::PAGE_SLUG )
        );
        self::assertContains(
            FinanceDelegationPage::PAGE_SLUG,
            $parent_slugs,
            'صفحهٔ واگذاری مالی باید زیرِ والدِ read-gated ثبت شود.'
        );
    }

    public function testPageSlugsArePreservedForBookmarks(): void
    {
        // Slugها بخشی از قرارداد (bookmark/redirect/اتوماسیون) هستند.
        self::assertSame( 'cpms-finance-delegation', FinanceDelegationPage::PAGE_SLUG );
        self::assertSame( 'cpms-custom-roles', CustomRoleDefinitionsPage::PAGE_SLUG );
        self::assertSame( 'cpms-dashboard', CpmsDashboard::PAGE_SLUG );
    }

    /**
     * CPMS Classic Admin UX (A) — هر صفحهٔ مدیریتیِ ثبت‌شده باید assets
     * اسکوپ‌شدهٔ خودش را بگیرد؛ صفحاتی که پیش از این بی‌استایل می‌ماندند.
     */
    public function testEveryRegisteredCpmsManagementScreenReceivesScopedAssets(): void
    {
        $slugs = [
            'cpms-custom-roles',
            'cpms-finance-delegation',
            'cpms-facility-setup',
            'cpms-shortcodes',
            // صفحاتی که از قبل در allowlist بودند و نباید حذف شوند.
            'cpms-dashboard',
            'cpms-system',
            'cpms-settings',
            'cpms-sms',
            'cpms-wizard',
        ];

        foreach ( $slugs as $slug ) {
            $_GET['page'] = $slug;

            self::assertTrue( CpmsAssets::isCpmsPage(), "صفحهٔ {$slug} باید CPMS محسوب شود." );
            self::assertStringContainsString(
                'cpms-admin',
                CpmsAssets::bodyClass( 'wp-admin' ),
                "صفحهٔ {$slug} باید body class اسکوپ‌شده بگیرد."
            );
        }

        unset( $_GET['page'] );
    }

    public function testUnrelatedAdminPagesRemainUntouched(): void
    {
        foreach ( [ 'cpms-not-a-page', 'some-other-plugin-page', '' ] as $slug ) {
            $_GET['page'] = $slug;

            self::assertFalse( CpmsAssets::isCpmsPage(), "صفحهٔ {$slug} نباید CPMS محسوب شود." );
            self::assertSame( 'wp-admin', CpmsAssets::bodyClass( 'wp-admin' ) );
        }

        unset( $_GET['page'] );
    }
}
