<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\CpmsAssets;
use ClinicCore\Admin\OrganizationManagerBootstrapPage;
use ClinicCore\Admin\OrganizationManagerMembershipPage;
use ClinicCore\Admin\OrgManagerAssets;
use WP_UnitTestCase;

/**
 * B-3 — Assets و narrow-screen برای دو صفحهٔ مدیریت سازمان.
 *
 * قراردادی که **نباید** شکسته شود (IranGeographyAddressFormsTest):
 *  `cpms-organization-managers` و `cpms-organization-manager-bootstrap` عضوِ
 *  allowlistِ `CpmsAssets` نیستند و `cpms-admin` را نمی‌گیرند. رفعِ overflow در
 *  عرضِ باریک با یک stylesheetِ اختصاصی انجام می‌شود، نه با widen کردنِ آن allowlist.
 */
final class OrgManagerResponsiveAssetsTest extends WP_UnitTestCase
{
    protected function tearDown(): void
    {
        unset($_GET['page']);
        wp_dequeue_style(OrgManagerAssets::STYLE_HANDLE);
        wp_set_current_user(0);
        parent::tearDown();
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function slugProvider(): array
    {
        return [
            'managers page' => [OrganizationManagerMembershipPage::PAGE],
            'bootstrap page' => [OrganizationManagerBootstrapPage::PAGE],
        ];
    }

    /**
     * @dataProvider slugProvider
     */
    public function testOrgManagerPagesGetTheirOwnScopedAssets(string $slug): void
    {
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        $_GET['page'] = $slug;

        self::assertTrue(OrgManagerAssets::is_org_manager_page(), "{$slug} باید صفحهٔ مدیریت سازمان شناخته شود");
        self::assertStringContainsString(
            OrgManagerAssets::BODY_CLASS,
            OrgManagerAssets::bodyClass('wp-admin'),
            "{$slug} باید body class اسکوپ‌شده بگیرد"
        );

        OrgManagerAssets::enqueue();
        self::assertTrue(wp_style_is(OrgManagerAssets::STYLE_HANDLE, 'enqueued'), "{$slug} باید stylesheet اختصاصی را بگیرد");

        // قرارداد قبلی دست‌نخورده: assets عمومیِ CPMS روی این دو صفحه لود نمی‌شوند.
        self::assertFalse(CpmsAssets::isCpmsPage(), "{$slug} نباید عضو allowlistِ CpmsAssets شود");
        self::assertSame('wp-admin', CpmsAssets::bodyClass('wp-admin'), "{$slug} نباید body class عمومی بگیرد");
    }

    public function testOtherPagesAreUnaffected(): void
    {
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));

        foreach (['cpms-dashboard', 'cpms-organizations', 'cpms-finance-delegation', 'cpms-not-a-page'] as $slug) {
            $_GET['page'] = $slug;
            self::assertFalse(OrgManagerAssets::is_org_manager_page(), "{$slug} نباید assets مدیریت سازمان بگیرد");
            self::assertSame('wp-admin', OrgManagerAssets::bodyClass('wp-admin'));
        }
    }

    public function testStylesheetHasTheNarrowScreenContract(): void
    {
        $path = dirname(__DIR__, 2) . '/assets/css/cpms-org-manager.css';
        self::assertFileExists($path);
        $css = (string) file_get_contents($path);

        // اسکوپ: هیچ قاعدهٔ سراسری روی wp-admin.
        self::assertStringContainsString('body.cpms-org-manager', $css);

        // narrow-screen: stacking فرم + کنترل‌های تمام‌عرض + جدولِ قابلِ‌اسکرول.
        self::assertStringContainsString('@media (max-width: 782px)', $css);
        self::assertMatchesRegularExpression('/select[^{]*\{[^}]*width:\s*100%/s', $css, 'select باید تمام‌عرض شود');
        self::assertStringContainsString('box-sizing: border-box', $css);
        self::assertStringContainsString('overflow-x: auto', $css, 'جدول‌های داده باید در خودشان اسکرول شوند');
        self::assertStringContainsString('min-height: 32px', $css, 'هدفِ لمسیِ دکمه‌ها');
        self::assertStringContainsString('focus-visible', $css, 'دسترسیِ کیبوردی');

        // هیچ محتوایی با overflow: hidden پنهان نمی‌شود.
        self::assertStringNotContainsString('overflow: hidden', $css);
        self::assertStringNotContainsString('text-overflow: ellipsis', $css);
    }

    public function testSectionNavigationIsStyledForEveryCpmsPage(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/cpms-admin.css');
        self::assertStringContainsString('body.cpms-admin .cpms-sectionnav', $css);
        self::assertStringContainsString('.cpms-sectionnav__link.is-current', $css);
        self::assertStringContainsString('focus-visible', $css);
    }
}
