<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\CpmsAssets;
use ClinicCore\Admin\PatientPortalPage;
use ClinicCore\Auth\RolesAndCapabilities;
use WP_UnitTestCase;

/**
 * Package «native dialogs» (Defect 9, partial) — gates the replacement of the
 * native browser confirm/prompt/alert calls with the shared in-page dialog
 * (assets/js/cpms-dialog.js, merged in PR #297) on every UN-GATED call site:
 *
 *  1. The four target admin pages (finance / sms / queue / doctor dashboard) and
 *     the patient-portal script emit NO native dialog calls any more — neither
 *     `window.confirm/prompt/alert(` nor a bare `confirm(`/`prompt(`/`alert(`.
 *  2. Each target admin page slug still enqueues the shared dialog handle
 *     `cpms-dialog` (with its `cpms-ui-text` dependency) via CpmsAssets.
 *  3. The patient portal page (PatientPortalPage::enqueue_assets) enqueues the
 *     shared dialog alongside the portal script, the portal handle keeps its
 *     empty dependency list (existing G3 contract), and the cancel button still
 *     carries the `data-cpms-confirm` hook the JS needs.
 *  4. tests/js/pkg-native-dialogs-portal.spec.js passes in Node against the real
 *     shipped assets — run with the EXACT mechanism of PkgP1JalaliAssetsTest:
 *     a missing node binary is a FAIL, never a skip.
 *
 * BLOCKED sites (owned by other packages / browser harnesses) are intentionally
 * NOT scanned here: templates/doctor-portal-shell.php and
 * templates/staff-pricing.php keep their native dialogs.
 */
final class PkgNativeDialogsTest extends WP_UnitTestCase
{
    /** Target admin page slug => page source file (relative to the plugin root). */
    private const TARGET_PAGES = [
        'cpms-finance' => 'src/Admin/SecretaryFinancePage.php',
        'cpms-sms'     => 'src/Admin/SmsSettingsPage.php',
        'cpms-queue'   => 'src/Admin/SecretaryQueuePage.php',
        'cpms-doctor'  => 'src/Admin/DoctorDashboardPage.php',
    ];

    private const PORTAL_JS   = 'assets/js/cpms-patient-portal.js';
    private const PORTAL_PAGE = 'src/Admin/PatientPortalPage.php';
    private const SPEC_JS     = 'js/pkg-native-dialogs-portal.spec.js';

    /**
     * Every script handle CpmsAssets / PatientPortalPage may touch, so this test
     * never leaks a dangling dependency (e.g. cpms-admin → cpms-dialog) into a
     * later test's wp_print_scripts incorrect-usage check.
     */
    private const SCRIPT_HANDLES = [
        'cpms-admin',
        'cpms-dialog',
        'cpms-ui-text',
        'cpms-patient-portal',
        'cpms-catalog-fields',
        'cpms-jalali-date',
    ];

    private const STYLE_HANDLES = ['cpms-admin', 'cpms-jalali-date'];

    private function resetAssetState(): void
    {
        foreach (self::SCRIPT_HANDLES as $handle) {
            wp_dequeue_script($handle);
            wp_deregister_script($handle);
        }
        foreach (self::STYLE_HANDLES as $handle) {
            wp_dequeue_style($handle);
            wp_deregister_style($handle);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetAssetState();
    }

    protected function tearDown(): void
    {
        $this->resetAssetState();
        wp_set_current_user(0);
        $_GET = [];
        parent::tearDown();
    }

    /**
     * The UN-GATED call-site files must not contain any native dialog call.
     */
    public function testTargetPagesEmitNoNativeDialogCalls(): void
    {
        $files = self::TARGET_PAGES;
        $files['cpms-patient-portal.js'] = self::PORTAL_JS;

        foreach ($files as $label => $rel) {
            $path = dirname(__DIR__, 2) . '/' . $rel;
            self::assertFileExists($path);
            $src = (string) file_get_contents($path);

            foreach (['window.confirm(', 'window.prompt(', 'window.alert('] as $token) {
                self::assertStringNotContainsString(
                    $token,
                    $src,
                    "{$label} must not call {$token} — use the shared in-page dialog (cpms-dialog.js)."
                );
            }

            $bare = [];
            preg_match_all('/(?<![A-Za-z0-9_$.])(?:confirm|prompt|alert)\s*\(/', $src, $m);
            $bare = $m[0];
            self::assertSame(
                [],
                $bare,
                "{$label} must not contain bare native dialog calls: " . implode(', ', $bare)
            );
        }
    }

    /**
     * Every target admin page slug enqueues the shared dialog (head) via CpmsAssets.
     */
    public function testTargetAdminPagesEnqueueSharedDialog(): void
    {
        foreach (self::TARGET_PAGES as $slug => $rel) {
            $this->resetAssetState();
            $_GET['page'] = $slug;

            CpmsAssets::enqueue();

            self::assertTrue(
                wp_script_is('cpms-dialog', 'enqueued'),
                "cpms-dialog must be enqueued on the {$slug} admin page."
            );
            self::assertTrue(
                wp_script_is('cpms-ui-text', 'enqueued'),
                "cpms-ui-text (dependency of cpms-dialog) must be enqueued on {$slug}."
            );
            $registered = wp_scripts()->registered['cpms-dialog'] ?? null;
            self::assertNotNull($registered, "cpms-dialog must be registered on {$slug}.");
            self::assertStringContainsString('/assets/js/cpms-dialog.js', (string) $registered->src);
            self::assertContains('cpms-ui-text', (array) $registered->deps);
        }
        $_GET = [];
    }

    /**
     * The patient portal page loads the shared dialog next to the portal script,
     * keeps the portal handle dependency-free (existing G3 contract), and the
     * cancel button still carries data-cpms-confirm.
     */
    public function testPatientPortalEnqueuesSharedDialogAndKeepsConfirmHook(): void
    {
        $userId = (int) wp_create_user(
            'pkgnd_patient_' . uniqid('', false),
            'pass-not-used-123',
            uniqid('pkgnd_', true) . '@test.local'
        );
        self::assertGreaterThan(0, $userId);
        $user = get_userdata($userId);
        self::assertNotFalse($user);
        $user->set_role(RolesAndCapabilities::ROLE_PATIENT);
        wp_set_current_user($userId);
        self::assertTrue(PatientPortalPage::isPatientOnly(wp_get_current_user()));

        $this->resetAssetState();

        PatientPortalPage::enqueue_assets('toplevel_page_cpms-patient');

        self::assertTrue(wp_script_is('cpms-patient-portal', 'enqueued'), 'portal script must be enqueued.');
        self::assertTrue(
            wp_script_is('cpms-dialog', 'enqueued'),
            'the shared in-page dialog must be enqueued on the patient portal page.'
        );
        self::assertTrue(wp_script_is('cpms-ui-text', 'enqueued'), 'cpms-ui-text must be enqueued as well.');

        $portal = wp_scripts()->registered['cpms-patient-portal'] ?? null;
        self::assertNotNull($portal);
        self::assertSame(
            [],
            (array) $portal->deps,
            'the portal handle keeps its empty dependency list (existing G3 contract); the dialog loads alongside it.'
        );
        self::assertSame(1, (int) wp_scripts()->get_data('cpms-patient-portal', 'group'), 'portal script stays in the footer.');
        self::assertSame(0, (int) wp_scripts()->get_data('cpms-dialog', 'group'), 'the dialog loads in the head, before the footer portal script.');

        // The cancel button markup still carries the data hook the JS needs.
        $method = new \ReflectionMethod(PatientPortalPage::class, 'cancel_button');
        $method->setAccessible(true);
        $html = (string) $method->invoke(null, 7, '1404/08/12', '10:30');
        self::assertStringContainsString('data-role="cancel-appointment"', $html);
        self::assertStringContainsString('data-appointment-id="7"', $html);
        self::assertStringContainsString('data-cpms-confirm=', $html);

        // Any other admin hook enqueues nothing (existing gate stays intact).
        $this->resetAssetState();
        PatientPortalPage::enqueue_assets('toplevel_page_cpms-clinicians');
        self::assertFalse(wp_script_is('cpms-patient-portal', 'enqueued'));
        self::assertFalse(wp_script_is('cpms-dialog', 'enqueued'));
    }

    /**
     * Node gate for the portal behaviour spec — EXACT PkgP1JalaliAssetsTest
     * mechanism: missing node = FAIL (assertNotSame), never a skip.
     */
    public function testPatientPortalCancelJsSpecPassesWithNode(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        self::assertNotSame('', $node, 'node binary must be available in CI.');

        $spec = dirname(__DIR__) . '/' . self::SPEC_JS;
        self::assertFileExists($spec);

        $output   = [];
        $exitCode = 0;
        exec($node . ' ' . escapeshellarg($spec) . ' 2>&1', $output, $exitCode);

        self::assertSame(
            0,
            $exitCode,
            "pkg-native-dialogs-portal.spec.js failed:\n" . implode("\n", $output)
        );
    }

    /**
     * Guard against a silent pass: the spec must exist and must actually load the
     * shipped portal asset (an empty/stub spec could not fail).
     */
    public function testSpecIsWiredToTheShippedPortalAsset(): void
    {
        $spec = dirname(__DIR__) . '/' . self::SPEC_JS;
        self::assertFileExists($spec);
        $src = (string) file_get_contents($spec);
        self::assertStringContainsString("assets/js/cpms-patient-portal.js", $src);
        self::assertStringContainsString("assets/js/cpms-dialog.js", $src);

        $portalJs = dirname(__DIR__, 2) . '/' . self::PORTAL_JS;
        self::assertFileExists($portalJs);
        $pageSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::PORTAL_PAGE);
        self::assertStringContainsString('data-cpms-confirm', $pageSrc, 'the portal page must render the confirm hook.');
    }
}
