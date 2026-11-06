<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\Compatibility\WooCommerceAdminAccess;
use ClinicCore\Admin\PatientPortalPage;
use ClinicCore\Auth\RolesAndCapabilities;
use WP_UnitTestCase;

/**
 * M12 — پیشیکیتِ «پروفایلِ خودی» روی woocommerce_prevent_admin_access.
 *
 * این فایل مسیرِ واقعیِ فیلتر را با نقش‌های واقعیِ CPMS می‌سنجد (پوششِ pure
 * در tests/Unit/WooCommerceAdminAccessOwnProfilePredicateTest.php):
 *  - پروفایلِ خودی برای هر یک از نقش‌های ستادی (پزشک/منشی/حسابدار/مدیر)
 *    از دستِ سیاستِ ووکامرس آزاد می‌شود؛
 *  - پروفایلِ کاربرِ دیگر، user-edit.php، users.php، async-upload.php،
 *    صفحات options و صفحات شخصِ ثالث همچنان ریدایرکت می‌شوند؛
 *  - بیمارِ خالص نه relax می‌شود و نه از redirect خودِ PatientPortalPage
 *    (guardWpAdmin) بیرون می‌آید؛
 *  - تصمیم بر پایهٔ Capability است نه نامِ نقش: کاربرِ دو‌نقشیِ
 *    (ستادی + بیمار) با Capability ارزیابی می‌شود؛
 *  - نقش‌های سفارشیِ Clinic-local هرگز WordPress capability نمی‌سازند (سندِ
 *    مرزِ پشتیبانی)، ولی هر کاربری که واقعاً cpms_* داشته باشد آزاد می‌شود.
 *
 * محدودیتِ اعلام‌شده: انکارِ network/user admin در همان wrapper است (مثلِ
 * پیشیکیتِ صفحاتِ CPMS) و در WP Test Suite قابلِ شبیه‌سازی نیست — ثابتِ
 * WP_NETWORK_ADMIN/WP_USER_ADMIN پس از تعریف در طولِ process حذف نمی‌شود.
 * درخواستِ logged-out و بقیهٔ شاخه‌ها با فیلترِ واقعی پوشش داده شده‌اند.
 */
final class WooCommerceAdminAccessProfileTest extends WP_UnitTestCase
{
    /** @var array<string, mixed> */
    private array $globalsBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['pagenow', '_GET', '_POST', '_REQUEST', '_SERVER'] as $key) {
            $this->globalsBackup[$key] = $GLOBALS[$key] ?? null;
        }
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        unset(
            $GLOBALS['pagenow'],
            $_GET['user_id'],
            $_GET['page'],
            $_POST['user_id'],
            $_REQUEST['user_id'],
            $_REQUEST['page']
        );
        foreach ($this->globalsBackup as $key => $value) {
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
     * شبیه‌سازیِ درخواستِ GET در wp-admin که سیاستِ ووکامرس می‌بیند (هم‌راستا با SAPI:
     * کلیدهای query هم در $_GET و هم در $_REQUEST قرار می‌گیرند).
     *
     * @param mixed $userId
     */
    private function serveAdminRequest(string $screen, $userId = null, ?string $page = null): void
    {
        $GLOBALS['pagenow'] = $screen;
        unset($_POST['user_id']);
        if ($userId !== null) {
            $_GET['user_id']     = (string) $userId;
            $_REQUEST['user_id'] = (string) $userId;
        } else {
            unset($_GET['user_id'], $_REQUEST['user_id']);
        }
        if ($page !== null) {
            $_GET['page']     = $page;
            $_REQUEST['page'] = $page;
        } else {
            unset($_GET['page'], $_REQUEST['page']);
        }
    }

    /** همان نقطهٔ فراخوانیِ ووکامرس: apply_filters('woocommerce_prevent_admin_access', true) */
    private function verdict(): bool
    {
        return (bool) apply_filters(WooCommerceAdminAccess::FILTER, true);
    }

    /**
     * الگوی جاافتادهٔ مخزن برای گرفتنِ wp_safe_redirect + exit بدون کشتنِ PHPUnit.
     */
    private function captureRedirect(callable $fn): ?string
    {
        $location = null;
        $handler  = static function (string $loc) use (&$location): string {
            $location = $loc;
            throw new \RuntimeException('REDIRECT::' . $loc);
        };
        add_filter('wp_redirect', $handler, 1);
        try {
            $fn();
        } catch (\RuntimeException $e) {
            if (!str_starts_with($e->getMessage(), 'REDIRECT::')) {
                throw $e;
            }
        } finally {
            remove_filter('wp_redirect', $handler, 1);
        }

        return $location;
    }

    // ============================ wiring ============================

    public function testOwnProfilePredicateIsWiredOnThePublicWooCommerceFilterOnly(): void
    {
        // Registered by the production bootstrap (App::boot), not by this test.
        $this->assertSame(
            10,
            has_filter(
                WooCommerceAdminAccess::FILTER,
                [WooCommerceAdminAccess::class, 'allow_own_profile_for_cpms_staff']
            ),
            'the own-profile callback must sit on the public WooCommerce filter'
        );
        $this->assertFalse(has_action('admin_init', [WooCommerceAdminAccess::class, 'allow_own_profile_for_cpms_staff']));
        $this->assertFalse(has_action('admin_menu', [WooCommerceAdminAccess::class, 'allow_own_profile_for_cpms_staff']));
        // The admin-bar half of WooCommerce's policy stays untouched.
        $this->assertFalse(has_filter('woocommerce_disable_admin_bar'));
    }

    // ============================ positive ============================

    /**
     * @dataProvider staffRoles
     */
    public function testOwnProfileIsRelaxedForEachCpmsStaffRole(string $role): void
    {
        $user = self::factory()->user->create(['role' => $role]);
        wp_set_current_user($user);
        $this->assertTrue(is_user_logged_in());
        // Precondition: WooCommerce's own access capabilities are absent (the redirect trigger).
        $this->assertFalse(current_user_can('edit_posts'));
        $this->assertFalse(current_user_can('manage_woocommerce'));

        // profile.php without user_id — the user's own profile.
        $this->serveAdminRequest('profile.php');
        $this->assertFalse($this->verdict(), "own profile.php must survive the policy for {$role}");

        // …and with their own user_id — the same own profile, explicitly addressed.
        $this->serveAdminRequest('profile.php', $user);
        $this->assertFalse($this->verdict(), "own profile.php?user_id=self must survive the policy for {$role}");
    }

    /**
     * @return list<array{string}>
     */
    public static function staffRoles(): array
    {
        return [
            [RolesAndCapabilities::ROLE_DOCTOR],
            [RolesAndCapabilities::ROLE_SECRETARY],
            [RolesAndCapabilities::ROLE_ACCOUNTANT],
            [RolesAndCapabilities::ROLE_MANAGER],
        ];
    }

    public function testAUserWhoseAuthorityIsACpmsCapabilityAloneIsRelaxed(): void
    {
        // Capability, never role name: a throwaway non-CPMS-named role holding
        // exactly one closed-set capability. NOTE the boundary: the repo's
        // Clinic-local custom roles (CustomRoleManagementService) never
        // materialize as WordPress capabilities at all — their authority is
        // membership/AuthorizationService-based — so a custom-role-ONLY user
        // holds no cpms_* WP capability and stays under the policy by design.
        if (get_role('cpms_profile_probe') === null) {
            add_role('cpms_profile_probe', 'CPMS own-profile probe', ['read' => true, RolesAndCapabilities::QUEUE_READ => true]);
        }
        $user = self::factory()->user->create(['role' => 'cpms_profile_probe']);
        wp_set_current_user($user);

        $this->serveAdminRequest('profile.php');
        $this->assertFalse($this->verdict(), 'a user actually holding a cpms_* capability is relaxed regardless of role name');
    }

    public function testAStaffPlusPatientUserIsTreatedByCapabilityNotByRoleName(): void
    {
        $user = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_DOCTOR]);
        $wpUser = get_userdata($user);
        $this->assertNotFalse($wpUser);
        $wpUser->add_role(RolesAndCapabilities::ROLE_PATIENT);
        wp_set_current_user($user);

        $roles = (array) wp_get_current_user()->roles;
        $this->assertContains(RolesAndCapabilities::ROLE_PATIENT, $roles);
        $this->assertContains(RolesAndCapabilities::ROLE_DOCTOR, $roles);
        $this->assertFalse(PatientPortalPage::isPatientOnly($wpUser), 'a dual staff+patient user is not patient-only');

        $this->serveAdminRequest('profile.php');
        $this->assertFalse($this->verdict(), 'the dual staff+patient user is relaxed by capability');
    }

    // ============================ negative ============================

    /**
     * @dataProvider staffRoles
     */
    public function testOtherUsersProfileAndEditRoutesStayDeniedForEachCpmsStaffRole(string $role): void
    {
        $staff = self::factory()->user->create(['role' => $role]);
        $other = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_PATIENT]);
        wp_set_current_user($staff);

        foreach (['profile.php', 'user-edit.php'] as $screen) {
            $this->serveAdminRequest($screen, $other);
            $this->assertTrue($this->verdict(), "{$role} cannot use {$screen} to access another user's profile");
        }
    }

    public function testPatientOnlyUserIsNotRelaxedAndStaysRedirectedByGuardWpAdmin(): void
    {
        // Contract: the patient role's capabilities do not intersect the closed CPMS set.
        $patient = get_role(RolesAndCapabilities::ROLE_PATIENT);
        $this->assertNotNull($patient);
        $patientCaps = array_keys($patient->capabilities);
        $this->assertSame(['read'], array_values(array_filter($patientCaps, 'is_string')), 'the patient role holds only read');
        foreach (RolesAndCapabilities::ALL_CAPS as $capability) {
            $this->assertFalse($patient->has_cap($capability), "patient role must not hold {$capability}");
        }

        $user = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_PATIENT]);
        wp_set_current_user($user);
        $this->assertTrue(PatientPortalPage::isPatientOnly((int) $user));

        // Default: a patient-only user cannot reach profile.php, their own or anyone else's.
        foreach ([
            ['profile.php', null],
            ['profile.php', $user],
            ['profile.php', self::factory()->user->create(['role' => 'subscriber'])],
            ['user-edit.php', $user],
            ['user-edit.php', self::factory()->user->create(['role' => 'subscriber'])],
        ] as [$screen, $targetUser]) {
            $this->serveAdminRequest($screen, $targetUser);
            $this->assertTrue(
                $this->verdict(),
                "patient-only access remains denied for {$screen} target " . var_export($targetUser, true)
            );
        }

        // …and still redirected to the frontend portal by the existing patient guard.
        $location = $this->captureRedirect(static function (): void {
            PatientPortalPage::guardWpAdmin();
        });
        $this->assertNotNull($location, 'guardWpAdmin must still redirect a pure patient');
        $this->assertSame(PatientPortalPage::page_url(), $location, 'the redirect target is the existing frontend portal URL, unchanged');
    }

    public function testPatientWithAnotherWordPressRoleIsNotPatientOnlyOrRedirected(): void
    {
        $user = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_PATIENT]);
        $wpUser = get_userdata($user);
        $this->assertNotFalse($wpUser);
        $wpUser->add_role('subscriber');
        wp_set_current_user($user);

        $this->assertFalse(PatientPortalPage::isPatientOnly($wpUser), 'a patient account with another WP role is not patient-only');
        foreach (['profile.php', 'user-edit.php'] as $screen) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $this->serveAdminRequest($screen, $user);
            $redirect = $this->captureRedirect(static function (): void {
                PatientPortalPage::guardWpAdmin();
            });
            $this->assertNull($redirect, "the patient-only guard must not affect a patient+subscriber on {$screen}");
            $this->assertTrue($this->verdict(), 'a non-CPMS role does not gain the CPMS own-profile exception');
        }
    }

    public function testLoggedOutRequestIsDeniedEvenWithSpoofedRequestUserId(): void
    {
        wp_set_current_user(0);
        $this->serveAdminRequest('profile.php', '987654321');

        $this->assertTrue($this->verdict(), 'an unauthenticated request cannot use $_REQUEST[user_id] to obtain profile access');
    }

    public function testAUserHoldingOnlyReadIsDenied(): void
    {
        $user = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user);
        $this->assertTrue(current_user_can('read'));
        $this->assertFalse(current_user_can(RolesAndCapabilities::QUEUE_READ));

        $this->serveAdminRequest('profile.php');
        $this->assertTrue($this->verdict(), 'holding only the generic read capability relaxes nothing');
    }

    public function testEveryExplicitlyDeniedScreenStaysUnderThePolicy(): void
    {
        $doctor = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_DOCTOR]);
        wp_set_current_user($doctor);

        foreach (
            [
                'user-edit.php',
                'users.php',
                'async-upload.php',
                'options-general.php',
                'options-writing.php',
                'index.php',
                'edit.php',
                'plugins.php',
                'tools.php',
            ] as $screen
        ) {
            $this->serveAdminRequest($screen);
            $this->assertTrue($this->verdict(), "{$screen} must keep WooCommerce's verdict for CPMS staff");
        }

        // Third-party pages (admin.php?page=…) stay under the policy too.
        foreach (['wc-admin', 'wc-settings', 'woocommerce'] as $page) {
            $this->serveAdminRequest('admin.php', null, $page);
            $this->assertTrue($this->verdict(), "third-party page {$page} must keep WooCommerce's verdict");
        }
    }

    public function testThePageAllowlistPredicateStillDeniesProfileScreensItDoesNotOwn(): void
    {
        // The first, page-allowlist predicate is untouched: a CPMS page slug on
        // profile.php still grants nothing by itself — the relaxation above comes
        // from the own-profile predicate, not from the page allowlist.
        $doctor = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_DOCTOR]);
        wp_set_current_user($doctor);

        $this->serveAdminRequest('profile.php', null, 'cpms-not-a-page');
        // Own profile: relaxed by the NEW predicate (capability), while the page
        // selector remains irrelevant — see the dedicated allowlist tests in
        // WooCommerceAdminAccessCoexistenceTest for screens the allowlist must deny.
        $this->assertFalse($this->verdict());

        // With another user's id the same CPMS slug relaxes nothing.
        $other = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_PATIENT]);
        $this->serveAdminRequest('profile.php', $other, 'cpms-doctor');
        $this->assertTrue($this->verdict());
    }

    // ============================ F3: GET / POST / REQUEST user_id ============================

    public function testOwnProfileReadsRequestSuperglobalAcrossGetPostAndRequestAndDeniesOtherUserPost(): void
    {
        $staff = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_ACCOUNTANT]);
        $other = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_PATIENT]);
        wp_set_current_user($staff);

        // 1. GET profile.php?user_id=<self> (PHP SAPI populates $_GET + $_REQUEST).
        $GLOBALS['pagenow']  = 'profile.php';
        unset($_POST['user_id']);
        $_GET['user_id']     = (string) $staff;
        $_REQUEST['user_id'] = (string) $staff;
        $this->assertFalse($this->verdict(), 'GET profile.php?user_id=<self> must relax');

        // 2. POST profile.php with user_id=<self> (form submit: $_GET absent, $_POST + $_REQUEST set).
        unset($_GET['user_id']);
        $_POST['user_id']    = (string) $staff;
        $_REQUEST['user_id'] = (string) $staff;
        $this->assertFalse($this->verdict(), 'POST profile.php with own user_id must relax');

        // 3. POST profile.php with user_id=<other> ($_GET absent, $_POST + $_REQUEST carry other user).
        unset($_GET['user_id']);
        $_POST['user_id']    = (string) $other;
        $_REQUEST['user_id'] = (string) $other;
        $this->assertTrue($this->verdict(), 'POST profile.php with another user_id must NOT relax');

        // 4. Direct $_REQUEST variants: absent, own digits, array, padded, float, leading-zero.
        unset($_GET['user_id'], $_POST['user_id'], $_REQUEST['user_id']);
        $this->assertFalse($this->verdict(), 'absent $_REQUEST[user_id] relaxes for CPMS staff');

        $_REQUEST['user_id'] = (string) $staff;
        $this->assertFalse($this->verdict(), 'strict digits $_REQUEST[user_id] equal to self relaxes');

        foreach (
            [
                [(string) $staff],
                ' ' . $staff . ' ',
                '+' . $staff,
                '0' . $staff,
                $staff . '.0',
                '',
                '0',
            ] as $malformed
        ) {
            $_REQUEST['user_id'] = $malformed;
            $this->assertTrue(
                $this->verdict(),
                'malformed $_REQUEST[user_id] must fail closed: ' . var_export($malformed, true)
            );
        }
    }

    // ============================ F4: dual-role staff + patient ============================

    /**
     * @dataProvider staffRoles
     */
    public function testDualRoleStaffPlusPatientIsNeverClassifiedAsPatientOnlyAcrossAllFourStaffRoles(string $staffRole): void
    {
        $user  = self::factory()->user->create(['role' => $staffRole]);
        $other = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_PATIENT]);

        $wpUser = get_userdata($user);
        $this->assertInstanceOf(\WP_User::class, $wpUser);
        $wpUser->add_role(RolesAndCapabilities::ROLE_PATIENT);
        wp_set_current_user($user);

        $roles = (array) wp_get_current_user()->roles;
        $this->assertContains($staffRole, $roles);
        $this->assertContains(RolesAndCapabilities::ROLE_PATIENT, $roles);

        // Must NOT be misclassified as patient-only (both WP_User and int overload).
        $this->assertFalse(
            PatientPortalPage::isPatientOnly($wpUser),
            "{$staffRole}+cpms_patient must not be classified as patient-only"
        );
        $this->assertFalse(
            PatientPortalPage::isPatientOnly((int) $user),
            "{$staffRole}+cpms_patient (by user ID) must not be classified as patient-only"
        );

        // guardWpAdmin() must NOT redirect dual-role staff+patient out of wp-admin on GET.
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->serveAdminRequest('profile.php');
        $redirect = $this->captureRedirect(static function (): void {
            PatientPortalPage::guardWpAdmin();
        });
        $this->assertNull($redirect, "guardWpAdmin must not redirect {$staffRole}+cpms_patient to the patient portal");

        // Own profile.php is relaxed under WooCommerce, while other-user profile and unrelated screens stay blocked.
        $this->assertFalse($this->verdict(), "{$staffRole}+cpms_patient must be relaxed on own profile.php");

        $this->serveAdminRequest('profile.php', $other);
        $this->assertTrue($this->verdict(), "{$staffRole}+cpms_patient must still be denied on another user's profile");

        foreach (['users.php', 'plugins.php', 'options-general.php'] as $deniedScreen) {
            $this->serveAdminRequest($deniedScreen);
            $this->assertTrue($this->verdict(), "{$deniedScreen} remains blocked under WooCommerce for {$staffRole}+cpms_patient");
        }
    }
}
