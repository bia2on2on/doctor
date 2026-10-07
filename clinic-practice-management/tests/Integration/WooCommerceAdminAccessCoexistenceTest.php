<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\ClinicianAdminPage;
use ClinicCore\Admin\Compatibility\WooCommerceAdminAccess;
use ClinicCore\Admin\CpmsAdminMenu;
use ClinicCore\Admin\CpmsSetupWizard;
use ClinicCore\Admin\DoctorDashboardPage;
use ClinicCore\Admin\DoctorHandwritingPage;
use ClinicCore\Admin\LocationAdminPage;
use ClinicCore\Admin\PatientAdminPage;
use ClinicCore\Admin\PrescriptionPrintPage;
use ClinicCore\Admin\RoleCapabilitiesPage;
use ClinicCore\Admin\SecretaryFinancePage;
use ClinicCore\Admin\SecretaryQueuePage;
use ClinicCore\Admin\SettingsAdmin;
use ClinicCore\Admin\SmsSettingsPage;
use ClinicCore\Admin\StaffManagementPage;
use ClinicCore\Admin\SystemPage;
use ClinicCore\Auth\RolesAndCapabilities;
use WP_UnitTestCase;

/**
 * WooCommerce coexistence — real integration path for the scoped admin-access exception.
 *
 * Contract proven here (bounded on purpose):
 *  - the exception is wired on WooCommerce's public filter and nothing else (no admin-bar,
 *    no menu, no capability, no role, no redirect handling);
 *  - an authenticated user holding the page's own CPMS capability, on a page actually
 *    registered for that request, reaches that CPMS page;
 *  - nobody else does: anonymous, unauthorized, unknown/spoofed selector, any other wp-admin
 *    screen, WooCommerce screens and other plugins' screens keep WooCommerce's verdict; and a
 *    page that is not registered for the request stays under the policy (fail closed);
 *  - the registry cannot drift: it matches the real CPMS page registrations one-to-one
 *    (slug + capability) in both directions;
 *  - no capability or role is synthesized or mutated by the mechanism, and each CPMS page
 *    still enforces its own authorization independently of it.
 *
 * Historical RED preserved (not re-manufactured here): run `37611352785`, head
 * `a1788f66c14f1d624668cbee10d683b030c887c5` — isolated CPMS + WooCommerce 10.3.8 probe saw
 * `woocommerce_prevent_admin_access` = true for doctor/secretary and a redirect to the
 * My Account page (page 9), emitted by `WC_Admin::prevent_admin_access`.
 *
 * @see \ClinicCore\Admin\Compatibility\WooCommerceAdminAccess
 */
final class WooCommerceAdminAccessCoexistenceTest extends WP_UnitTestCase
{
    /** slug => the class::menu() that registers it (test-side wiring; the registry is production). */
    private const MENU_OWNER = [
        'cpms-dashboard'          => [CpmsAdminMenu::class, 'menu'],
        'cpms-wizard'             => [CpmsSetupWizard::class, 'menu'],
        'cpms-staff'              => [StaffManagementPage::class, 'menu'],
        'cpms-locations'          => [LocationAdminPage::class, 'menu'],
        'cpms-clinicians'         => [ClinicianAdminPage::class, 'menu'],
        'cpms-system'             => [SystemPage::class, 'menu'],
        'cpms-settings'           => [SettingsAdmin::class, 'menu'],
        'cpms-sms'                => [SmsSettingsPage::class, 'menu'],
        'cpms-roles'              => [RoleCapabilitiesPage::class, 'menu'],
        'cpms-doctor'             => [DoctorDashboardPage::class, 'menu'],
        'cpms-handwriting'        => [DoctorHandwritingPage::class, 'menu'],
        'cpms-prescription-print' => [PrescriptionPrintPage::class, 'menu'],
        'cpms-queue'              => [SecretaryQueuePage::class, 'menu'],
        'cpms-finance'            => [SecretaryFinancePage::class, 'menu'],
        'cpms-patients'           => [PatientAdminPage::class, 'menu'],
    ];

    /** @var array<string,mixed> */
    private array $globals_backup = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'pagenow', '_GET', 'menu', 'submenu', 'admin_page_hooks',
            '_registered_pages', '_parent_pages', '_wp_submenu_nopriv', '_wp_real_parent_file',
        ] as $key) {
            $this->globals_backup[$key] = $GLOBALS[$key] ?? null;
        }
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        unset($_GET['page'], $GLOBALS['pagenow']);
        foreach ($this->globals_backup as $key => $value) {
            if ($value === null) {
                unset($GLOBALS[$key]);
                continue;
            }
            $GLOBALS[$key] = $value;
        }
        parent::tearDown();
    }

    // ============================ helpers ============================

    /**
     * Throwaway role/user holding `read` plus exactly the listed capabilities.
     *
     * @param list<string> $capabilities
     */
    private function user_holding(array $capabilities): int
    {
        sort($capabilities);
        $role_key = 'cpms_probe_' . substr(md5(implode(',', $capabilities)), 0, 8);
        if (get_role($role_key) === null) {
            $caps = ['read' => true];
            foreach ($capabilities as $capability) {
                $caps[$capability] = true;
            }
            add_role($role_key, 'CPMS coexistence probe', $caps);
        }

        return (int) self::factory()->user->create(['role' => $role_key]);
    }

    /** Resets the menu globals the way a fresh wp-admin request has them. */
    private function resetMenuGlobals(): void
    {
        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];
        $GLOBALS['_wp_submenu_nopriv'] = [];
    }

    /**
     * Simulates the wp-admin request WooCommerce's policy evaluates.
     *
     * `wp-admin/admin.php` fires `admin_menu` (menu.php) before `admin_init`, so a faithful
     * request simulation registers the menus first — with `$build_menus` the current user's
     * real page registrations are in place, exactly as the policy would see them.
     *
     * @param mixed $page
     */
    private function serve_admin_request(string $screen, $page, bool $build_menus = false): void
    {
        $GLOBALS['pagenow'] = $screen;
        if (is_string($page)) {
            $_GET['page'] = $page;
        } else {
            unset($_GET['page']);
        }

        $this->resetMenuGlobals();
        if ($build_menus) {
            foreach (array_keys(self::MENU_OWNER) as $slug) {
                call_user_func(self::MENU_OWNER[$slug]);
            }
        }
    }

    /** WooCommerce's own call site: `apply_filters( 'woocommerce_prevent_admin_access', $prevent )`. */
    private function verdict(): bool
    {
        return (bool) apply_filters(WooCommerceAdminAccess::FILTER, true);
    }

    /**
     * Runs one page's real registration and returns every [slug => capability] it produced.
     *
     * @param array{0:class-string,1:string} $menu_owner
     *
     * @return array<string,string>
     */
    private function registered_pages_via(array $menu_owner): array
    {
        $this->resetMenuGlobals();

        call_user_func([$menu_owner[0], $menu_owner[1]]);

        $found = [];
        foreach ((array) $GLOBALS['menu'] as $item) {
            if (is_array($item) && isset($item[1], $item[2]) && is_string($item[2]) && is_string($item[1])) {
                $found[$item[2]] = $item[1];
            }
        }
        foreach ((array) $GLOBALS['submenu'] as $group) {
            foreach ((array) $group as $item) {
                if (is_array($item) && isset($item[1], $item[2]) && is_string($item[2]) && is_string($item[1])) {
                    $found[$item[2]] = $item[1];
                }
            }
        }

        return $found;
    }

    /**
     * Captures a render's output and a `wp_die()` denial without leaking buffers.
     */
    private function capture_render(callable $fn): string
    {
        $level = ob_get_level();
        $messages = [];
        add_filter('wp_die_handler', static function () use (&$messages): callable {
            return static function ($message) use (&$messages): void {
                if (is_string($message) || is_numeric($message)) {
                    $messages[] = (string) $message;
                }
                throw new \RuntimeException('CPMS_WP_DIE');
            };
        }, PHP_INT_MAX);

        ob_start();
        try {
            $fn();
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== 'CPMS_WP_DIE') {
                while (ob_get_level() > $level) {
                    ob_end_clean();
                }
                throw $e;
            }
        } catch (\WPDieException $e) {
            $messages[] = $e->getMessage();
        }

        $html = '';
        while (ob_get_level() > $level) {
            $html .= (string) ob_get_clean();
        }

        return $html . implode('|', $messages);
    }

    // ============================ wiring ============================

    public function testExceptionIsWiredOnThePublicWooCommerceFilterOnly(): void
    {
        // Registered by the production bootstrap (App::boot), not by this test.
        $this->assertSame(10, has_filter(WooCommerceAdminAccess::FILTER), 'the callback must sit on the public WooCommerce filter');
        $this->assertFalse(has_action('admin_init', [WooCommerceAdminAccess::class, 'allow_authorized_cpms_page']));
        $this->assertFalse(has_action('admin_menu', [WooCommerceAdminAccess::class, 'register']));
        // The admin-bar half of WooCommerce's policy is untouched: no widening of the toolbar.
        $this->assertFalse(has_filter('woocommerce_disable_admin_bar'));
    }

    // ============================ positive ============================

    public function testAuthorizedDoctorReachesAuthorizedDoctorPageWithWooCommercePolicyActive(): void
    {
        $doctor = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_DOCTOR]);
        wp_set_current_user($doctor);
        $this->assertTrue(current_user_can(RolesAndCapabilities::QUEUE_READ));
        $this->assertFalse(current_user_can('edit_posts'), 'precondition: the historical trigger still holds');

        $this->serve_admin_request('admin.php', 'cpms-doctor', true);
        $this->assertFalse($this->verdict(), 'authorized CPMS doctor page must survive the third-party policy');
    }

    public function testAuthorizedSecretaryReachesAuthorizedSecretaryPageWithWooCommercePolicyActive(): void
    {
        $secretary = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_SECRETARY]);
        wp_set_current_user($secretary);
        $this->assertTrue(current_user_can(RolesAndCapabilities::QUEUE_READ));
        $this->assertFalse(current_user_can('manage_woocommerce'));

        $this->serve_admin_request('admin.php', 'cpms-queue', true);
        $this->assertFalse($this->verdict(), 'authorized CPMS secretary page must survive the third-party policy');

        $this->serve_admin_request('admin.php', 'cpms-patients', true);
        $this->assertFalse($this->verdict(), 'the authorized secretary patient entry is a CPMS-owned page too');
    }

    public function testEveryRegisteredPageIsReachableByAUserHoldingExactlyThatPagesCapability(): void
    {
        foreach (WooCommerceAdminAccess::PAGES as $slug => $capability) {
            wp_set_current_user($this->user_holding([$capability]));
            $this->serve_admin_request('admin.php', $slug, true);

            $this->assertFalse(
                $this->verdict(),
                "a user holding the page's own capability ({$capability}) must reach {$slug}"
            );

            if ($capability !== 'manage_options') {
                $this->assertFalse(
                    current_user_can('manage_woocommerce') || current_user_can('edit_posts'),
                    "reaching {$slug} must not carry store/content capabilities with it"
                );
            }
        }
    }

    // ============================ negative ============================

    public function testUnauthenticatedRequestGetsNoException(): void
    {
        wp_set_current_user(0);

        $this->serve_admin_request('admin.php', 'cpms-doctor', true);
        $this->assertTrue($this->verdict());

        $this->serve_admin_request('admin.php', 'cpms-queue', true);
        $this->assertTrue($this->verdict());
    }

    public function testUserAuthorizedForOtherPagesGetsNoException(): void
    {
        // Accountant holds finance capabilities only: clinical/technical CPMS pages stay locked.
        $accountant = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_ACCOUNTANT]);
        wp_set_current_user($accountant);

        $this->serve_admin_request('admin.php', 'cpms-doctor', true);
        $this->assertTrue($this->verdict(), 'accountant must not be exempted on a clinical CPMS page');

        $this->serve_admin_request('admin.php', 'cpms-system', true);
        $this->assertTrue($this->verdict());

        // …while its own authorized CPMS page is exempted.
        $this->serve_admin_request('admin.php', 'cpms-finance', true);
        $this->assertFalse($this->verdict());
    }

    public function testUnknownOrSpoofedSelectorGetsNoException(): void
    {
        $doctor = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_DOCTOR]);
        wp_set_current_user($doctor);
        $this->serve_admin_request('admin.php', 'cpms-doctor', true);

        foreach (['cpms-not-a-page', 'cpms-doctor-admin', 'cpms', 'cpms-', '../cpms-doctor', 'CPMS-DOCTOR'] as $slug) {
            $this->serve_admin_request('admin.php', $slug, true);
            $this->assertTrue($this->verdict(), "unknown selector {$slug} must fail closed");
        }

        $this->serve_admin_request('admin.php', null, true);
        $this->assertTrue($this->verdict(), 'admin.php without a page parameter is not a CPMS page request');
    }

    public function testUnregisteredPageGetsNoExceptionEvenForAnAuthorizedUser(): void
    {
        // Fail closed on registration: caps + allowlist, but the page was never registered for
        // this request (no menu build) → the third-party policy keeps its verdict.
        $doctor = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_DOCTOR]);
        wp_set_current_user($doctor);
        $this->assertTrue(current_user_can(RolesAndCapabilities::QUEUE_READ));

        $this->serve_admin_request('admin.php', 'cpms-doctor', false);
        $this->assertTrue($this->verdict(), 'a page not registered for this request must not be exempted');
    }

    public function testExceptionNeverGrantsWooCommerceOrUnrelatedAdminScreens(): void
    {
        $doctor = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_DOCTOR]);
        wp_set_current_user($doctor);

        foreach ([
            'wc-admin', 'wc-settings', 'wc-status', 'wc-addons', 'woocommerce',
            'edit.php', 'users.php', 'options-general.php', 'plugins.php', 'themes.php',
            'tools.php', 'import.php', 'export.php',
        ] as $page) {
            $this->serve_admin_request('admin.php', $page, true);
            $this->assertTrue($this->verdict(), "WooCommerce/unrelated admin page {$page} must stay under the policy");
        }

        foreach (['index.php', 'edit.php', 'tools.php', 'options-general.php', 'profile.php', 'async-upload.php'] as $screen) {
            $this->serve_admin_request($screen, 'cpms-doctor', true);
            $this->assertTrue($this->verdict(), "a CPMS slug on {$screen} must not become an admin-access grant");
        }
    }

    public function testDoctorNeverGainsWooCommerceCapabilitiesThroughTheMechanism(): void
    {
        $doctor = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_DOCTOR]);
        wp_set_current_user($doctor);
        $user = wp_get_current_user();
        $caps_before = $user->allcaps;
        $roles_before = $user->roles;

        $this->serve_admin_request('admin.php', 'cpms-doctor', true);
        $this->assertFalse($this->verdict(), 'precondition: the exception applies to this request');

        $this->assertSame($caps_before, wp_get_current_user()->allcaps, 'the exception must not add or synthesize capabilities');
        $this->assertSame($roles_before, wp_get_current_user()->roles, 'the exception must not change roles');
        foreach (['edit_posts', 'manage_woocommerce', 'view_admin_dashboard', 'publish_products', 'manage_options'] as $cap) {
            $this->assertFalse(current_user_can($cap), "capability {$cap} must stay absent");
        }

        // The next non-CPMS request in the same session is still subject to WooCommerce's policy.
        $this->serve_admin_request('admin.php', 'wc-admin', true);
        $this->assertTrue($this->verdict());
    }

    // ============================ registry ↔ registration parity ============================

    public function testRegistryMatchesRealCpmsPageRegistrationsOneToOne(): void
    {
        $this->assertSame(
            array_keys(self::MENU_OWNER),
            array_keys(WooCommerceAdminAccess::PAGES),
            'every CPMS-owned page must be in the registry and vice versa'
        );

        $observed = [];
        foreach (self::MENU_OWNER as $slug => $menu_owner) {
            $capability = WooCommerceAdminAccess::PAGES[$slug];
            wp_set_current_user($this->user_holding([$capability]));
            $registered = $this->registered_pages_via($menu_owner);

            $this->assertArrayHasKey($slug, $registered, "{$slug} must be registered by {$menu_owner[0]}::{$menu_owner[1]}()");
            $this->assertSame(
                $capability,
                $registered[$slug],
                "registry capability for {$slug} must equal the capability of its real registration"
            );
            $observed[$slug] = $capability;

            // A user without that capability gets neither a registration nor an exemption.
            wp_set_current_user($this->user_holding(['cpms_nonexistent_probe_capability']));
            $this->assertArrayNotHasKey(
                $slug,
                $this->registered_pages_via($menu_owner),
                "{$slug} must not be registered for a user lacking {$capability}"
            );
            $this->serve_admin_request('admin.php', $slug, true);
            $this->assertTrue($this->verdict(), "{$slug} must stay under the policy without {$capability}");
        }

        $this->assertSame(WooCommerceAdminAccess::PAGES, $observed);
    }

    public function testNoCpmsMenuEntryIsLeftOutOfTheRegistry(): void
    {
        // A CPMS page registered but absent from the registry would silently keep the
        // third-party redirect → drift guard in the other direction. The pure-patient legacy
        // slug is intentionally not listed (patients have no wp-admin CPMS surface by design).
        wp_set_current_user($this->user_holding(array_values(array_unique(array_values(WooCommerceAdminAccess::PAGES)))));
        $this->resetMenuGlobals();

        foreach (array_keys(self::MENU_OWNER) as $slug) {
            call_user_func(self::MENU_OWNER[$slug]);
        }

        $found = [];
        foreach ((array) $GLOBALS['menu'] as $item) {
            if (is_array($item) && isset($item[2]) && is_string($item[2])) {
                $found[] = $item[2];
            }
        }
        foreach ((array) $GLOBALS['submenu'] as $group) {
            foreach ((array) $group as $item) {
                if (is_array($item) && isset($item[2]) && is_string($item[2])) {
                    $found[] = $item[2];
                }
            }
        }

        $cpms_slugs = array_values(array_unique(array_filter(
            $found,
            static fn (string $slug): bool => str_starts_with($slug, 'cpms-')
        )));
        sort($cpms_slugs);

        $registered = array_keys(WooCommerceAdminAccess::PAGES);
        sort($registered);

        $this->assertSame($registered, $cpms_slugs, 'the registry and the CPMS page registrations must agree exactly');
    }

    // ============================ page authorization stays independent ============================

    public function testCpmsPageAuthorizationIsStillEnforcedOnItsOwn(): void
    {
        // The exception only removes a third-party redirect; every page still guards itself.
        $accountant = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_ACCOUNTANT]);
        wp_set_current_user($accountant);
        $this->assertFalse(current_user_can(RolesAndCapabilities::QUEUE_READ));

        $denied = $this->capture_render(static function (): void {
            DoctorDashboardPage::render();
        });
        $this->assertStringContainsString('دسترسی ندارید', $denied, 'the page render must deny by itself');

        // An authorized doctor gets real page content — from the page's own guard, not the filter.
        $doctor = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_DOCTOR]);
        wp_set_current_user($doctor);
        $this->serve_admin_request('admin.php', 'cpms-doctor', true);
        $this->assertFalse($this->verdict(), 'precondition: the coexistence exception applies');

        $html = $this->capture_render(static function (): void {
            DoctorDashboardPage::render();
        });
        $this->assertStringContainsString('امروز پزشک', $html);
        $this->assertStringNotContainsString('دسترسی ندارید', $html);
    }

    public function testTenantStateIsIrrelevantToTheCompatibilityDecision(): void
    {
        // No Clinic scope is established for this user/process: the decision must not consult,
        // select or default a tenant (no first-Clinic behaviour) yet stay deterministic.
        $doctor = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_DOCTOR]);
        wp_set_current_user($doctor);

        $this->serve_admin_request('admin.php', 'cpms-doctor', true);
        $this->assertFalse($this->verdict());

        $this->serve_admin_request('admin.php', 'wc-admin', true);
        $this->assertTrue($this->verdict());
    }
}
