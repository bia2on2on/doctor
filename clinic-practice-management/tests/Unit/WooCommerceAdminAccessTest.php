<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Admin\Compatibility\WooCommerceAdminAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * WooCommerce coexistence — pure decision of the scoped admin-access exception.
 *
 * Historical RED being fixed (preserved, not manufactured): run `37611352785` on head
 * `a1788f66c14f1d624668cbee10d683b030c887c5` recorded `woocommerce_prevent_admin_access`
 * = true for authenticated CPMS doctor/secretary requests and a redirect to the WooCommerce
 * My Account page. These tests pin the *bounded* replacement: the exception applies only to
 * an authorized request for a registered CPMS-owned page and never to anything else.
 *
 * No WordPress functions are involved in `decide()`/`is_registered_page()`, so the whole
 * decision matrix — including every fail-closed branch — is exercised here without WP.
 */
final class WooCommerceAdminAccessTest extends TestCase
{
    /**
     * @param array<string,string>|null $pages
     */
    private function decide(
        bool $prevent,
        string $screen,
        mixed $page,
        bool $logged_in = true,
        ?array $pages = null,
        bool $capability_granted = true,
        bool $registered = true
    ): bool {
        return WooCommerceAdminAccess::decide(
            $prevent,
            $screen,
            $page,
            $logged_in,
            $pages ?? WooCommerceAdminAccess::PAGES,
            static fn (string $capability): bool => $capability_granted,
            static fn (string $slug, string $capability): bool => $registered
        );
    }

    // ======================= POSITIVE — authorized CPMS pages =======================

    public function testAuthorizedDoctorCpmsPageSurvivesTheThirdPartyPolicy(): void
    {
        $this->assertFalse(
            $this->decide(true, 'admin.php', 'cpms-doctor'),
            'authorized CPMS doctor page must not be redirected by the third-party policy'
        );
    }

    public function testAuthorizedSecretaryCpmsPageSurvivesTheThirdPartyPolicy(): void
    {
        $this->assertFalse($this->decide(true, 'admin.php', 'cpms-queue'));
        $this->assertFalse($this->decide(true, 'admin.php', 'cpms-patients'));
        $this->assertFalse($this->decide(true, 'admin.php', 'cpms-finance'));
    }

    public function testFilterPointIsThePublicWooCommerceFilter(): void
    {
        $this->assertSame('woocommerce_prevent_admin_access', WooCommerceAdminAccess::FILTER);
        $this->assertSame('admin.php', WooCommerceAdminAccess::CPMS_ADMIN_SCREEN);
    }

    public function testRegistrationProbeReceivesTheRegistryPair(): void
    {
        $asked = [];
        $verdict = WooCommerceAdminAccess::decide(
            true,
            'admin.php',
            'cpms-handwriting',
            true,
            WooCommerceAdminAccess::PAGES,
            static fn (string $capability): bool => $capability === 'cpms_note_create',
            static function (string $slug, string $capability) use (&$asked): bool {
                $asked[] = $slug . '|' . $capability;

                return true;
            }
        );

        $this->assertFalse($verdict);
        $this->assertSame(['cpms-handwriting|cpms_note_create'], $asked);
    }

    // ======================= NEGATIVE — no widening, ever =======================

    public function testNothingIsChangedWhenThePolicyAlreadyAllowsTheRequest(): void
    {
        foreach (['cpms-doctor', 'cpms-queue', 'wc-admin', 'anything'] as $page) {
            $this->assertFalse(
                $this->decide(false, 'admin.php', $page),
                "verdict must stay 'allowed' untouched for {$page}"
            );
        }
    }

    public function testUnauthenticatedRequestGetsNoException(): void
    {
        $this->assertTrue($this->decide(true, 'admin.php', 'cpms-doctor', false));
    }

    public function testAuthenticatedButUnauthorizedUserGetsNoException(): void
    {
        // Same page, same registry, but the page's own capability is not held → deny stays.
        $this->assertTrue($this->decide(true, 'admin.php', 'cpms-doctor', true, null, false));
        $this->assertTrue($this->decide(true, 'admin.php', 'cpms-system', true, null, false));
    }

    public function testPageNotRegisteredForThisRequestGetsNoException(): void
    {
        // Authorized + allowlisted, but no such page was registered for this request.
        $this->assertTrue(
            $this->decide(true, 'admin.php', 'cpms-doctor', true, null, true, false),
            'a page that is not actually registered must fail closed'
        );
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unknownPageSelectors(): array
    {
        return [
            'unknown cpms prefix' => ['cpms-not-a-page'],
            'spoofed suffix' => ['cpms-doctor-admin'],
            'spoofed prefix' => ['x-cpms-doctor'],
            'bare prefix' => ['cpms'],
            'empty' => [''],
            'null' => [null],
            'bool' => [true],
            'int' => [42],
            'list' => [['cpms-doctor']],
            'array map' => [['page' => 'cpms-doctor']],
            'object' => [(object) ['page' => 'cpms-doctor']],
            'uppercase alias' => ['CPMS-DOCTOR'],
            'whitespace padded' => [' cpms-doctor '],
            'encoded path' => ['..%2Fcpms-doctor'],
            'query smuggling' => ['cpms-doctor&page=wc-admin'],
        ];
    }

    #[DataProvider('unknownPageSelectors')]
    public function testUnknownMalformedOrSpoofedPageFailsClosed(mixed $page): void
    {
        // Even a user who holds every capability gets no exception for a non-registry selector.
        $this->assertTrue(
            $this->decide(true, 'admin.php', $page),
            'non-registry page selector must never relax the policy'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function otherAdminScreens(): array
    {
        return [
            'dashboard' => ['index.php'],
            'legacy tools' => ['tools.php'],
            'legacy options-general' => ['options-general.php'],
            'posts list' => ['edit.php'],
            'profile' => ['profile.php'],
            'async upload' => ['async-upload.php'],
            'admin post' => ['admin-post.php'],
            'options reading' => ['options-reading.php'],
            'plugin editor' => ['plugin-editor.php'],
            'theme editor' => ['theme-editor.php'],
            'user list' => ['users.php'],
            'import' => ['import.php'],
            'export' => ['export.php'],
            'customize' => ['customize.php'],
        ];
    }

    /**
     * A valid CPMS slug on any screen other than admin.php must not be exempted — so no other
     * wp-admin screen becomes reachable through this mechanism.
     */
    #[DataProvider('otherAdminScreens')]
    public function testOnlyTheCpmsAdminScreenIsAffected(string $screen): void
    {
        $this->assertTrue(
            $this->decide(true, $screen, 'cpms-doctor'),
            "{$screen} must keep the third-party policy verdict"
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function thirdPartyAndCoreScreens(): array
    {
        return [
            'woo admin app' => ['wc-admin'],
            'woo settings' => ['wc-settings'],
            'woo status' => ['wc-status'],
            'woo addons' => ['wc-addons'],
            'woo coupons' => ['wc-coupons'],
            'woocommerce parent' => ['woocommerce'],
            'woo order post type' => ['edit.php?post_type=shop_order'],
            'core posts' => ['edit.php'],
            'core users' => ['users.php'],
            'core options general' => ['options-general.php'],
            'core tools' => ['tools.php'],
            'core themes' => ['themes.php'],
            'core plugins' => ['plugins.php'],
            'edd' => ['download'],
            'wordfence' => ['wfengine'],
            'contact form 7' => ['wpcf7'],
            'autoptimize' => ['autoptimize'],
            'the seo framework' => ['the-seo-framework'],
        ];
    }

    /**
     * WooCommerce admin screens and unrelated wp-admin screens are never in the registry, so
     * the exception cannot become a general admin-access grant even for a fully capped user.
     */
    #[DataProvider('thirdPartyAndCoreScreens')]
    public function testThirdPartyAndUnrelatedPagesAreNeverExempted(string $page): void
    {
        $this->assertTrue($this->decide(true, 'admin.php', $page));
        $this->assertArrayNotHasKey($page, WooCommerceAdminAccess::PAGES);
    }

    public function testRegistryIsClosedToCpmsOwnedPagesOnly(): void
    {
        foreach (WooCommerceAdminAccess::PAGES as $slug => $capability) {
            $this->assertStringStartsWith('cpms-', $slug, "registry entry {$slug} is not a CPMS-owned page");
            $this->assertNotSame('', $capability, "registry entry {$slug} needs a real capability");
            $this->assertMatchesRegularExpression(
                '/^(cpms_[a-z_]+|manage_options)$/',
                (string) $capability,
                "registry entry {$slug} must require an existing CPMS capability (or the WP administrator capability)"
            );
        }
    }

    /**
     * The frozen boundary: the exception must not hinge on commerce/content/broad WP
     * capabilities, because doing so would make it a de-facto store-management grant path.
     */
    public function testRegistryNeverReliesOnCommerceOrContentCapabilities(): void
    {
        $forbidden = [
            'manage_woocommerce',
            'edit_posts',
            'edit_others_posts',
            'publish_posts',
            'view_admin_dashboard',
            'edit_products',
            'publish_products',
            'activate_plugins',
            'promote_users',
            'list_users',
            'edit_users',
            'unfiltered_html',
            'read',
            'exist',
        ];

        foreach (WooCommerceAdminAccess::PAGES as $slug => $capability) {
            if ($slug === 'cpms-roles') {
                // That page's own registration already requires the WP administrator
                // capability; the registry keeps it identical instead of widening it.
                $this->assertSame('manage_options', $capability);
                continue;
            }
            $this->assertNotContains((string) $capability, $forbidden, "registry entry {$slug} must not hinge on {$capability}");
        }
    }

    public function testDoctorAndSecretaryAcceptancePagesAreRegistered(): void
    {
        foreach (['cpms-doctor', 'cpms-queue', 'cpms-patients', 'cpms-finance', 'cpms-handwriting'] as $slug) {
            $this->assertArrayHasKey($slug, WooCommerceAdminAccess::PAGES);
        }
    }

    public function testExceptionIsDrivenByTheRegistryNotAHardcodedNotion(): void
    {
        // Registry without the entry → no exception, even for a fully authorized user.
        $this->assertTrue($this->decide(true, 'admin.php', 'cpms-doctor', true, ['cpms-queue' => 'cpms_queue_read']));
        $this->assertFalse($this->decide(true, 'admin.php', 'cpms-queue', true, ['cpms-queue' => 'cpms_queue_read']));
    }

    public function testEmptyOrBrokenRegistryEntryCannotGrantAccess(): void
    {
        $this->assertTrue($this->decide(true, 'admin.php', 'cpms-queue', true, []));
        $this->assertTrue($this->decide(true, 'admin.php', 'cpms-queue', true, ['cpms-queue' => '']));
    }

    public function testCapabilityProbeIsAskedForTheRegistryCapability(): void
    {
        $asked = [];
        $verdict = WooCommerceAdminAccess::decide(
            true,
            'admin.php',
            'cpms-handwriting',
            true,
            WooCommerceAdminAccess::PAGES,
            static function (string $capability) use (&$asked): bool {
                $asked[] = $capability;

                return $capability === 'cpms_note_create';
            },
            static fn (string $slug, string $capability): bool => true
        );

        $this->assertFalse($verdict);
        $this->assertSame(['cpms_note_create'], $asked, "the page's own capability is the only authorization consulted");
    }

    public function testVerdictIsNotCachedAcrossRequests(): void
    {
        $granted = WooCommerceAdminAccess::decide(
            true,
            'admin.php',
            'cpms-doctor',
            true,
            WooCommerceAdminAccess::PAGES,
            static fn (string $capability): bool => true,
            static fn (string $slug, string $capability): bool => true
        );
        $denied = WooCommerceAdminAccess::decide(
            true,
            'admin.php',
            'cpms-doctor',
            true,
            WooCommerceAdminAccess::PAGES,
            static fn (string $capability): bool => false,
            static fn (string $slug, string $capability): bool => true
        );

        $this->assertFalse($granted);
        $this->assertTrue($denied);
    }

    // ======================= live menu registration proof =======================

    public function testIsRegisteredPageAcceptsTopLevelAndSubmenuRows(): void
    {
        $menu = $GLOBALS['menu'] ?? null;
        $submenu = $GLOBALS['submenu'] ?? null;

        try {
            $GLOBALS['menu'] = [
                ['امروز پزشک', 'cpms_queue_read', 'cpms-doctor', 'امروز پزشک'],
            ];
            $GLOBALS['submenu'] = [
                'cpms-dashboard' => [
                    ['سلامت سیستم', 'cpms_config', 'cpms-system', 'سلامت سیستم'],
                ],
                '' => [
                    ['چاپ نسخه', 'cpms_rx_read', 'cpms-prescription-print', 'چاپ نسخه'],
                ],
            ];

            $this->assertTrue(WooCommerceAdminAccess::is_registered_page('cpms-doctor', 'cpms_queue_read'));
            $this->assertTrue(WooCommerceAdminAccess::is_registered_page('cpms-system', 'cpms_config'));
            $this->assertTrue(WooCommerceAdminAccess::is_registered_page('cpms-prescription-print', 'cpms_rx_read'));

            // Same slug, different capability → not a match for that guard.
            $this->assertFalse(WooCommerceAdminAccess::is_registered_page('cpms-doctor', 'manage_options'));
            // Unknown page and third-party pages → never registered by CPMS.
            $this->assertFalse(WooCommerceAdminAccess::is_registered_page('cpms-not-a-page', 'cpms_queue_read'));
            $this->assertFalse(WooCommerceAdminAccess::is_registered_page('wc-admin', 'manage_woocommerce'));
        } finally {
            $this->restore($menu, $submenu);
        }
    }

    public function testIsRegisteredPageFailsClosedWithoutUsableMenuGlobals(): void
    {
        $menu = $GLOBALS['menu'] ?? null;
        $submenu = $GLOBALS['submenu'] ?? null;

        try {
            unset($GLOBALS['menu'], $GLOBALS['submenu']);
            $this->assertFalse(WooCommerceAdminAccess::is_registered_page('cpms-doctor', 'cpms_queue_read'));

            $GLOBALS['menu'] = 'not-an-array';
            $GLOBALS['submenu'] = null;
            $this->assertFalse(WooCommerceAdminAccess::is_registered_page('cpms-doctor', 'cpms_queue_read'));

            $GLOBALS['menu'] = [ 'scalar row', [9 => 'wrong shape'], [ 'title', 'cpms_queue_read' ] ];
            $GLOBALS['submenu'] = [ 'group' => [ [ 'title', 1, 2 ] ] ];
            $this->assertFalse(WooCommerceAdminAccess::is_registered_page('cpms-doctor', 'cpms_queue_read'));
        } finally {
            $this->restore($menu, $submenu);
        }
    }

    /**
     * Restores whatever the menu globals were (including "absent").
     */
    private function restore(mixed $menu, mixed $submenu): void
    {
        if ($menu === null) {
            unset($GLOBALS['menu']);
        } else {
            $GLOBALS['menu'] = $menu;
        }
        if ($submenu === null) {
            unset($GLOBALS['submenu']);
        } else {
            $GLOBALS['submenu'] = $submenu;
        }
    }
}
