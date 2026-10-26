<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\CpmsAdminMenu;
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

    /**
     * B-1 (unified menu) — قرارداد بازنویسی‌شده، با حفظ کاملِ نیتِ امنیتی.
     *
     * نیتِ اصلی: صفحهٔ «واگذاری مالی حساس» نباید زیرِ والدِ `cpms_config` بنشیند،
     * وگرنه مدیرِ Clinic-scoped (بدون capability وردپرسیِ CONFIG) نه منو را
     * می‌بیند و نه با URL مستقیم رد نمی‌شود. الزام «Top-Level بودنِ والد» با
     * «دقیقاً یک منوی CPMS» ناسازگار بود؛ اکنون **والدِ واحد** همان
     * `CpmsAdminMenu::parent_slug()` است و capability آن `read` است (نه CONFIG).
     */
    public function testFinanceDelegationIsReachableFromAReadGatedParent(): void
    {
        $GLOBALS['menu']             = [];
        $GLOBALS['submenu']          = [];
        $GLOBALS['_parent_pages']    = [];
        $GLOBALS['_registered_pages'] = [];

        CpmsAdminMenu::menu();
        CustomRoleDefinitionsPage::menu();
        FinanceDelegationPage::menu();

        $parent = $this->findTopLevel( CpmsAdminMenu::parent_slug() );
        self::assertNotNull( $parent, 'والدِ واحد «مدیریت مطب» باید ثبت شود.' );
        self::assertSame( 'read', $parent[1], 'والد نباید به cpms_config وابسته باشد.' );

        self::assertSame(
            CpmsAdminMenu::parent_slug(),
            $GLOBALS['_parent_pages'][ FinanceDelegationPage::PAGE_SLUG ] ?? null,
            'صفحهٔ واگذاری مالی باید زیرِ والدِ read-gated ثبت شود.'
        );
        self::assertArrayHasKey(
            'cpms-dashboard_page_' . FinanceDelegationPage::PAGE_SLUG,
            $GLOBALS['_registered_pages'],
            'ثبتِ صفحه باید واقعاً رخ داده باشد (نه فقط ادعا).'
        );
        self::assertSame(
            'read',
            CpmsAdminMenu::PAGES[ FinanceDelegationPage::PAGE_SLUG ][2],
            'لایهٔ منو نباید چیزی بیش از read مطالبه کند (مجوز واقعی سمتِ سرور است).'
        );
    }

    public function testFinanceDelegationNoLongerHidesBehindTheConfigGatedDashboard(): void
    {
        $GLOBALS['menu']              = [];
        $GLOBALS['submenu']           = [];
        $GLOBALS['_parent_pages']     = [];
        $GLOBALS['_registered_pages'] = [];

        // B-1: منوی واحد با `read` ثبت می‌شود؛ دیگر هیچ ظرفِ CONFIGمحوری وجود
        // ندارد که صفحهٔ مالی پشتِ آن پنهان شود.
        CpmsAdminMenu::menu();
        CustomRoleDefinitionsPage::menu();
        FinanceDelegationPage::menu();

        $dashboard = $this->findTopLevel( CpmsDashboard::PAGE_SLUG );
        self::assertNotNull( $dashboard, 'منوی واحد باید ثبت شود.' );
        self::assertNotSame(
            'cpms_config',
            $dashboard[1],
            'منوی واحد نباید CONFIGمحور باشد (وگرنه مدیرِ Clinic-scoped آن را نمی‌بیند).'
        );
        self::assertSame( 'read', $dashboard[1] );

        // کنترلِ مثبت: ثبت واقعاً رخ داده و والدِ آن همان منوی واحد است.
        self::assertSame(
            CpmsAdminMenu::parent_slug(),
            $GLOBALS['_parent_pages'][ FinanceDelegationPage::PAGE_SLUG ] ?? null,
            'صفحهٔ واگذاری مالی باید زیرِ منوی واحدِ read-gated ثبت شود.'
        );
        self::assertSame(
            CpmsAdminMenu::parent_slug(),
            $GLOBALS['_parent_pages'][ CustomRoleDefinitionsPage::PAGE_SLUG ] ?? null,
            'صفحهٔ نقش‌های سفارشی هم باید زیرِ همان منوی واحد ثبت شود (نه Top-Level).'
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
