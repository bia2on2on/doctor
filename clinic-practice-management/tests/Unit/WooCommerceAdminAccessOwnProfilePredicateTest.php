<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Admin\Compatibility\WooCommerceAdminAccess;
use ClinicCore\Auth\RolesAndCapabilities;
use PHPUnit\Framework\TestCase;

/**
 * M12 — پیشیکیتِ «پروفایلِ خودی» روی فیلترِ woocommerce_prevent_admin_access.
 *
 * تست‌های این فایل pure هستند (بدونِ وردپرس) و تصمیمِ
 * {@see WooCommerceAdminAccess::decide_own_profile()} را مستقیم می‌سنجند؛
 * مسیرِ واقعیِ فیلتر در tests/Integration/WooCommerceAdminAccessProfileTest.php
 * پوشش داده شده است.
 *
 * یک تستِ وفاداری هم مدلِ دقیقِ گیتِ خودِ WooCommerce 11.2.0 را بازسازی
 * می‌کند (بر پایهٔ سورسِ real — plugins/woocommerce/includes/admin/
 * class-wc-admin.php، WC_Admin::prevent_admin_access) تا شکافِ وفاداریِ
 * مستند شود: WooCommerce خودش admin-post.php و admin-ajax.php را معاف
 * می‌کند ولی profile.php را نه.
 */
final class WooCommerceAdminAccessOwnProfilePredicateTest extends TestCase
{
    /** @var list<string> */
    private array $granted = [];

    /**
     * @param list<string> $granted
     */
    private function granted( array $granted ): callable
    {
        return function ( string $capability ): bool {
            return in_array( $capability, $this->granted, true );
        };
    }

    protected function setUp(): void
    {
        $this->granted = [];
    }

    public function testClosedCpmsSetIsNamespacedAndNeverIncludesReadOrGenericCapabilities(): void
    {
        $closed = RolesAndCapabilities::ALL_CAPS;

        self::assertNotSame( [], $closed, 'the closed CPMS set must not be empty' );
        foreach ( $closed as $capability ) {
            self::assertStringStartsWith( 'cpms_', $capability, 'closed-set member must be namespaced: ' . $capability );
        }
        self::assertNotContains( 'read', $closed, 'the generic WordPress read capability must never be in the closed set' );
        self::assertNotContains( 'edit_posts', $closed );
        self::assertNotContains( 'manage_woocommerce', $closed );
        self::assertNotContains( 'manage_options', $closed );
    }

    public function testOwnProfileWithoutUserIdIsRelaxedForACpmsCapabilityHolder(): void
    {
        $this->granted = [ RolesAndCapabilities::QUEUE_READ ];

        self::assertFalse( WooCommerceAdminAccess::decide_own_profile(
            true,
            'profile.php',
            null,
            7,
            RolesAndCapabilities::ALL_CAPS,
            $this->granted( [] )
        ) );
    }

    public function testOwnProfileWithOwnUserIdIsRelaxed(): void
    {
        $this->granted = [ RolesAndCapabilities::FINANCE_READ ];

        self::assertFalse( WooCommerceAdminAccess::decide_own_profile(
            true,
            'profile.php',
            '7',
            7,
            RolesAndCapabilities::ALL_CAPS,
            $this->granted( [] )
        ) );
    }

    public function testSomeoneElsesProfileIsNeverRelaxed(): void
    {
        $this->granted = [ RolesAndCapabilities::QUEUE_READ ];

        self::assertTrue( WooCommerceAdminAccess::decide_own_profile(
            true,
            'profile.php',
            '8',
            7,
            RolesAndCapabilities::ALL_CAPS,
            $this->granted( [] )
        ) );

        // Malformed selectors fail closed too.
        self::assertTrue( WooCommerceAdminAccess::decide_own_profile(
            true,
            'profile.php',
            'not-a-number',
            7,
            RolesAndCapabilities::ALL_CAPS,
            $this->granted( [] )
        ) );

        self::assertTrue( WooCommerceAdminAccess::decide_own_profile(
            true,
            'profile.php',
            [7],
            7,
            RolesAndCapabilities::ALL_CAPS,
            $this->granted( [] )
        ) );

        // Strict decimal digits only: whitespace, signs, floats, leading zeros, '0', empty string all fail closed.
        foreach ( [ '', '0', ' 7', '7 ', ' 7 ', '+7', '-7', '7.0', '7e0', '07', '007', true ] as $malformed ) {
            self::assertTrue(
                WooCommerceAdminAccess::decide_own_profile(
                    true,
                    'profile.php',
                    $malformed,
                    7,
                    RolesAndCapabilities::ALL_CAPS,
                    $this->granted( [] )
                ),
                'non-strict user_id must not relax WooCommerce access: ' . var_export( $malformed, true )
            );
        }
    }

    /**
     * آیتم‌های صریحِ «رد»: user-edit.php، users.php، async-upload.php،
     * صفحات options، صفحات شخص ثالث و هر صفحهٔ دیگرِ wp-admin.
     */
    public function testEveryOtherScreenStaysUnderTheThirdPartyVerdict(): void
    {
        $this->granted = [ RolesAndCapabilities::QUEUE_READ ];

        foreach (
            [
                'user-edit.php',
                'users.php',
                'async-upload.php',
                'options-general.php',
                'options-writing.php',
                'admin.php',
                'index.php',
                'edit.php',
                'plugins.php',
                'tools.php',
                'admin-post.php',
                'admin-ajax.php',
            ] as $screen
        ) {
            self::assertTrue(
                WooCommerceAdminAccess::decide_own_profile(
                    true,
                    $screen,
                    null,
                    7,
                    RolesAndCapabilities::ALL_CAPS,
                    $this->granted( [] )
                ),
                "{$screen} must keep WooCommerce's verdict"
            );
        }
    }

    public function testLoggedOutRequestIsNeverRelaxed(): void
    {
        $this->granted = [ RolesAndCapabilities::QUEUE_READ ];

        self::assertTrue( WooCommerceAdminAccess::decide_own_profile(
            true,
            'profile.php',
            null,
            0,
            RolesAndCapabilities::ALL_CAPS,
            $this->granted( [] )
        ) );
    }

    public function testAUserHoldingOnlyReadIsNotRelaxed(): void
    {
        // `read` is not in the closed set — granting it must relax nothing.
        $probe = static fn ( string $capability ): bool => $capability === 'read';

        self::assertTrue( WooCommerceAdminAccess::decide_own_profile(
            true,
            'profile.php',
            null,
            7,
            RolesAndCapabilities::ALL_CAPS,
            $probe
        ) );
    }

    public function testAPatientOnlyUserHoldingNoCpmsCapabilityIsNotRelaxed(): void
    {
        // The patient role's WordPress capabilities are only `read` — they do not
        // intersect the closed CPMS set at all (the integration test proves this
        // against the real registered role).
        $probe = static fn ( string $capability ): bool => str_starts_with( $capability, 'patient_only_' );

        self::assertTrue( WooCommerceAdminAccess::decide_own_profile(
            true,
            'profile.php',
            null,
            9,
            RolesAndCapabilities::ALL_CAPS,
            $probe
        ) );
    }

    public function testAWooCommerceVerdictThatAlreadyAllowsStaysFalse(): void
    {
        $this->granted = [ RolesAndCapabilities::QUEUE_READ ];

        self::assertFalse( WooCommerceAdminAccess::decide_own_profile(
            false,
            'profile.php',
            null,
            7,
            RolesAndCapabilities::ALL_CAPS,
            $this->granted( [] )
        ) );
    }

    public function testAnEmptyClosedSetFailsClosed(): void
    {
        $always = static fn (): bool => true;

        self::assertTrue( WooCommerceAdminAccess::decide_own_profile(
            true,
            'profile.php',
            null,
            7,
            [],
            $always
        ) );
    }

    /**
     * تستِ وفاداریِ M12 — مدلِ گیتِ خودِ WooCommerce 11.2.0.
     *
     * سورسِ real (بررسی‌شده در 11.2.0): plugins/woocommerce/includes/admin/
     * class-wc-admin.php — WC_Admin::prevent_admin_access():
     *   $exempted_paths = array( 'admin-post.php', 'admin-ajax.php' );
     *   if ( apply_filters('woocommerce_disable_admin_bar', true )
     *        && isset($_SERVER['SCRIPT_FILENAME'])
     *        && ! in_array( basename(...), $exempted_paths, true ) ) {
     *       … caps: edit_posts / manage_woocommerce / view_admin_dashboard …
     *   }
     *   if ( apply_filters('woocommerce_prevent_admin_access', $prevent_access ) ) { redirect; }
     *
     * این مدل ثابت می‌کند:
     *  - admin-post.php و admin-ajax.php از سمتِ خودِ WooCommerce معاف‌اند
     *    (پیش از فیلتر) — یعنی CPMS هرگز لازم نیست آنها را مدیریت کند؛
     *  - profile.php برای کاربرِ ستادیِ CPMS (بدونِ caps ووکامرس) ریدایرکت
     *    می‌شود — شکافِ وفاداری که پیشیکیتِ جدید می‌بندد؛
     *  - profile.php برای بیمارِ خالص همچنان ریدایرکت می‌ماند.
     */
    public function testWooCommerceExemptsAdminPostAndAdminAjaxItselfButNotProfile(): void
    {
        $woocommerce_base_verdict = function ( string $script_basename, array $user_caps ): bool {
            // Model of WC 11.2.0: exempted paths never reach the capability check.
            $exempted_paths = [ 'admin-post.php', 'admin-ajax.php' ];
            $prevent        = false;

            if ( ! in_array( $script_basename, $exempted_paths, true ) ) {
                $has_cap = false;
                foreach ( [ 'edit_posts', 'manage_woocommerce', 'view_admin_dashboard' ] as $access_cap ) {
                    if ( in_array( $access_cap, $user_caps, true ) ) {
                        $has_cap = true;
                        break;
                    }
                }
                if ( ! $has_cap ) {
                    $prevent = true;
                }
            }

            return $prevent;
        };

        // Model of apply_filters(): both registered CPMS callbacks chained in order.
        $apply_cpms_callbacks = function ( bool $prevent, string $screen, ?int $user_id, int $current_user_id, array $cpms_caps ): bool {
            $after_page_predicate = WooCommerceAdminAccess::decide(
                $prevent,
                $screen,
                null,
                $current_user_id > 0,
                WooCommerceAdminAccess::PAGES,
                static fn ( string $capability ): bool => in_array( $capability, $cpms_caps, true ),
                static fn (): bool => false // profile.php is not a registered CPMS page
            );

            return WooCommerceAdminAccess::decide_own_profile(
                $after_page_predicate,
                $screen,
                $user_id === null ? null : (string) $user_id,
                $current_user_id,
                RolesAndCapabilities::ALL_CAPS,
                static fn ( string $capability ): bool => in_array( $capability, $cpms_caps, true )
            );
        };

        $doctor_caps   = [ RolesAndCapabilities::QUEUE_READ ];
        $patient_caps  = [ 'read' ];

        // WooCommerce itself exempts admin-post.php and admin-ajax.php — BEFORE
        // the filter: the base verdict is already false, so no CPMS predicate is
        // ever needed for them (and our callbacks must keep it false).
        foreach ( [ 'admin-post.php', 'admin-ajax.php' ] as $exempted ) {
            $base = $woocommerce_base_verdict( $exempted, $doctor_caps );
            self::assertFalse( $base, "WooCommerce 11.2.0 must exempt {$exempted} by itself" );
            self::assertFalse( $apply_cpms_callbacks( $base, $exempted, null, 7, $doctor_caps ) );
        }

        // profile.php is NOT in WooCommerce's own exemption set — the fidelity gap.
        $base_profile = $woocommerce_base_verdict( 'profile.php', $doctor_caps );
        self::assertTrue( $base_profile, 'WooCommerce 11.2.0 does not exempt profile.php (verified at source)' );

        // …which is exactly why the own-profile predicate exists: a CPMS staff
        // member's own profile must survive the policy now.
        self::assertFalse( $apply_cpms_callbacks( $base_profile, 'profile.php', null, 7, $doctor_caps ) );
        self::assertFalse( $apply_cpms_callbacks( $base_profile, 'profile.php', 7, 7, $doctor_caps ) );

        // Someone else's profile keeps the redirect.
        self::assertTrue( $apply_cpms_callbacks( $base_profile, 'profile.php', 8, 7, $doctor_caps ) );

        // A patient-only user keeps the redirect on their profile too.
        $base_patient = $woocommerce_base_verdict( 'profile.php', $patient_caps );
        self::assertTrue( $base_patient );
        self::assertTrue( $apply_cpms_callbacks( $base_patient, 'profile.php', null, 9, $patient_caps ) );
    }
}
