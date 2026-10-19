<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\CpmsActivationRedirect;
use ClinicCore\Admin\CpmsSetupWizard;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * Phase 3 — safe, one-shot, state-aware post-activation redirect.
 *
 * Positive and negative coverage for CpmsActivationRedirect, exercised
 * against the real WordPress admin_init/transient APIs (no code
 * inspection substitute).
 *
 * Scoping to $pagenow === 'plugins.php' is deliberately covered here: an
 * earlier revision fired on ANY admin page and was reverted after real
 * browser CI caught it silently suppressing CpmsAdminMenu::onboardingNotice()
 * on the dashboard landing (admin_init runs before admin_notices). The
 * plugins.php scope is safe precisely because onboardingNotice() already
 * never renders on that screen (`$screen->id === 'plugins'` guard in
 * CpmsAdminMenu), so the two features can never compete for the same page.
 */
final class CpmsActivationRedirectTest extends WP_UnitTestCase
{
    private ?string $pagenowBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        delete_transient('cpms_activation_redirect_uid');
        $this->pagenowBackup = $GLOBALS['pagenow'] ?? null;
    }

    protected function tearDown(): void
    {
        delete_transient('cpms_activation_redirect_uid');
        Settings::flushCache();
        ScopeContext::clear();
        App::resetScope();
        wp_set_current_user(0);
        if ($this->pagenowBackup === null) {
            unset($GLOBALS['pagenow']);
        } else {
            $GLOBALS['pagenow'] = $this->pagenowBackup;
        }
        parent::tearDown();
    }

    /** Mirrors the established repo pattern for asserting a wp_safe_redirect() + exit without killing PHPUnit. */
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

    private function onPluginsPage(): void
    {
        $GLOBALS['pagenow'] = 'plugins.php';
    }

    private function onDashboardPage(): void
    {
        $GLOBALS['pagenow'] = 'index.php';
    }

    private function freshEligibleAdmin(): int
    {
        // The WP test harness seeds exactly ONE unclaimed Clinic with zero
        // memberships; an administrator who never had a Clinic-local
        // membership seeded is a genuinely eligible first-run admin —
        // CpmsSetupWizard::onboarding_panel_applies() must be true here.
        $id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($id);

        return $id;
    }

    /** Same pattern used throughout the wizard test suite: an already-operating, authorized admin. */
    private function authorizedOperatingAdmin(): int
    {
        $id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($id);
        $clinicId = 1;
        try {
            $clinicId = App::scope()->clinicId;
        } catch (\Throwable) {
        }
        if ($clinicId > 0) {
            cpms_test_seed_membership($id, $clinicId, 'cpms_manager');
        }
        try {
            App::replaceExplicitScope(App::scope());
        } catch (\Throwable) {
        }

        return $id;
    }

    public function testEligibleFreshAdminIsRedirectedOnceToWizardOnThePluginsPageLanding(): void
    {
        $uid = $this->freshEligibleAdmin();
        self::assertTrue(CpmsSetupWizard::onboarding_panel_applies(), 'precondition: fresh install must be eligible');

        CpmsActivationRedirect::flag_activation(false);
        self::assertNotFalse(get_transient('cpms_activation_redirect_uid'), 'activation must arm the one-shot flag for this admin');

        $this->onPluginsPage();
        $location = $this->captureRedirect(static function (): void {
            CpmsActivationRedirect::maybe_redirect();
        });
        self::assertNotNull($location, 'the eligible admin must be redirected on the post-activation plugins.php landing');
        self::assertStringContainsString('page=' . CpmsSetupWizard::PAGE_SLUG, (string) $location);

        // One-shot: the flag is consumed and a second admin_init never redirects again (no loop).
        self::assertFalse(get_transient('cpms_activation_redirect_uid'), 'the flag must be consumed after one observation');
        $second = $this->captureRedirect(static function (): void {
            CpmsActivationRedirect::maybe_redirect();
        });
        self::assertNull($second, 'the one-shot redirect must never fire twice');
        unset($uid);
    }

    public function testNonPluginsPageLoadNeverRedirectsAndLeavesTheFlagArmedForTheRealLanding(): void
    {
        // This is the regression test for the reverted broad implementation:
        // a dashboard (or any other) admin page load must NEVER be
        // intercepted, so CpmsAdminMenu::onboardingNotice() keeps rendering
        // there exactly as before. The one-shot flag must survive this
        // non-plugins.php observation untouched, since this scoped
        // implementation only ever inspects the flag on plugins.php.
        $this->freshEligibleAdmin();
        CpmsActivationRedirect::flag_activation(false);
        self::assertNotFalse(get_transient('cpms_activation_redirect_uid'));

        $this->onDashboardPage();
        $location = $this->captureRedirect(static function (): void {
            CpmsActivationRedirect::maybe_redirect();
        });
        self::assertNull($location, 'a non-plugins.php admin page load must never be redirected');
        self::assertNotFalse(get_transient('cpms_activation_redirect_uid'), 'the flag must remain armed for the real plugins.php landing');

        // The real post-activation landing still gets its one chance.
        $this->onPluginsPage();
        $second = $this->captureRedirect(static function (): void {
            CpmsActivationRedirect::maybe_redirect();
        });
        self::assertNotNull($second, 'the plugins.php landing must still redirect after an unrelated page load');
    }

    public function testNetworkWideActivationNeverArmsTheFlag(): void
    {
        $this->freshEligibleAdmin();
        CpmsActivationRedirect::flag_activation(true);
        self::assertFalse(get_transient('cpms_activation_redirect_uid'), 'network-wide activation must never redirect a single site admin');
        $this->onPluginsPage();
        $location = $this->captureRedirect(static function (): void {
            CpmsActivationRedirect::maybe_redirect();
        });
        self::assertNull($location);
    }

    public function testBulkActivationRequestNeverArmsTheFlag(): void
    {
        $this->freshEligibleAdmin();
        $prevGet                = $_GET;
        $_GET['activate-multi'] = '1';
        try {
            CpmsActivationRedirect::flag_activation(false);
        } finally {
            $_GET = $prevGet;
        }
        self::assertFalse(get_transient('cpms_activation_redirect_uid'), 'WordPress bulk plugin activation must never redirect');
    }

    public function testAlreadyConfiguredInstallationIsNeverForceRedirected(): void
    {
        // Simulates a deactivate -> reactivate of an EXISTING, already
        // operating installation: activation fires again, but the
        // installation no longer needs first-run attention.
        $this->authorizedOperatingAdmin();
        self::assertFalse(CpmsSetupWizard::onboarding_panel_applies(), 'precondition: an already-configured install is not onboarding-eligible');

        CpmsActivationRedirect::flag_activation(false);
        self::assertNotFalse(get_transient('cpms_activation_redirect_uid'));

        $this->onPluginsPage();
        $location = $this->captureRedirect(static function (): void {
            CpmsActivationRedirect::maybe_redirect();
        });
        self::assertNull($location, 'an existing, already-configured installation must never be force-redirected');
        self::assertFalse(get_transient('cpms_activation_redirect_uid'), 'the flag is still consumed even when no redirect happens');
    }

    public function testDifferentConcurrentAdministratorIsNeverRedirected(): void
    {
        $activatingAdmin = $this->freshEligibleAdmin();
        CpmsActivationRedirect::flag_activation(false);

        $otherAdmin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($otherAdmin);
        self::assertNotSame($activatingAdmin, $otherAdmin);

        $this->onPluginsPage();
        $location = $this->captureRedirect(static function (): void {
            CpmsActivationRedirect::maybe_redirect();
        });
        self::assertNull($location, 'only the exact activating administrator may be redirected');
    }

    public function testNonAdministratorHoldingTheFlaggedIdIsNeverRedirected(): void
    {
        // Directly exercises the capability gate independent of the uid-match
        // gate: a non-administrator user id is armed, then impersonated.
        $secretary = self::factory()->user->create(['role' => 'subscriber']);
        set_transient('cpms_activation_redirect_uid', $secretary, 60);
        wp_set_current_user($secretary);

        $this->onPluginsPage();
        $location = $this->captureRedirect(static function (): void {
            CpmsActivationRedirect::maybe_redirect();
        });
        self::assertNull($location, 'a non-Administrator must never be redirected even if it holds the flagged id');
    }

    public function testAjaxContextNeverRedirects(): void
    {
        $this->freshEligibleAdmin();
        CpmsActivationRedirect::flag_activation(false);

        $this->onPluginsPage();
        $filter = static fn (): bool => true;
        add_filter('wp_doing_ajax', $filter);
        try {
            $location = $this->captureRedirect(static function (): void {
                CpmsActivationRedirect::maybe_redirect();
            });
            self::assertNull($location, 'AJAX requests must never be redirected');
        } finally {
            remove_filter('wp_doing_ajax', $filter);
        }
        // An AJAX-context observation is not a genuine normal page load, so
        // it must NOT consume the one-shot flag: the next real admin page
        // load still gets its (single) chance to redirect.
        self::assertNotFalse(get_transient('cpms_activation_redirect_uid'), 'an AJAX-context bail must not burn the one-shot flag');
    }

    public function testCronContextNeverRedirects(): void
    {
        $this->freshEligibleAdmin();
        CpmsActivationRedirect::flag_activation(false);

        $this->onPluginsPage();
        $filter = static fn (): bool => true;
        add_filter('wp_doing_cron', $filter);
        try {
            $location = $this->captureRedirect(static function (): void {
                CpmsActivationRedirect::maybe_redirect();
            });
            self::assertNull($location, 'cron requests must never be redirected');
        } finally {
            remove_filter('wp_doing_cron', $filter);
        }
    }

    public function testNoFlagMeansNoRedirectForAnyRequest(): void
    {
        $this->freshEligibleAdmin();
        // No flag_activation() call at all — ordinary unrelated admin page load.
        $this->onPluginsPage();
        $location = $this->captureRedirect(static function (): void {
            CpmsActivationRedirect::maybe_redirect();
        });
        self::assertNull($location, 'without the one-shot flag, admin_init must never redirect');
    }
}
