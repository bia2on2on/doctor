<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\CpmsAdminMenu;
use ClinicCore\Admin\CpmsAssets;
use ClinicCore\Admin\CpmsDashboard;
use ClinicCore\Admin\CpmsSetupWizard;
use ClinicCore\Admin\FirstPracticeSetupPage;
use ClinicCore\Admin\StaffManagementPage;
use ClinicCore\Application\Onboarding\FirstPracticeSetupException;
use ClinicCore\Application\Onboarding\FirstPracticeSetupService;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * Custom Role & Permission Management — Slice 3A: Simple and Secure
 * First-Clinic Onboarding (real-MySQL integration contract).
 *
 * Covered contract points:
 *  - eligible fresh single-Clinic setup;
 *  - explicit Clinic confirmation (missing/zero/forged selector denied);
 *  - successful manager membership (built-in cpms_manager preset only);
 *  - WordPress administrator role unchanged by provisioning;
 *  - no clinical write access by default;
 *  - replay denied (one-shot, durable, monotonic);
 *  - second administrator denied;
 *  - previously administered Clinic denied (membership evidence, marker NULL);
 *  - suspended/removed manager cannot reopen bootstrap;
 *  - existing secretary/doctor cannot self-promote;
 *  - multi-Clinic takeover denied (no first-row fallback);
 *  - two-connection concurrency (row lock + guarded consumption);
 *  - failed audit rolls back provisioning (no partial membership).
 */
final class FirstPracticeSetupTest extends WP_UnitTestCase
{
    /** @var array<string, mixed> */
    private array $seed_baseline;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();

        $this->seed_baseline = $this->seedClinicState();
        self::assertSame(
            1,
            (int) $this->seed_baseline['clinic_count'],
            'precondition: first-setup tests run against the single-Clinic installation state'
        );
        self::assertNull($this->seed_baseline['claimed_at'], 'precondition: seed Clinic is unclaimed');
    }

    protected function tearDown(): void
    {
        delete_transient('cpms_onboarding_seen');
        delete_transient('cpms_wizard_notice');
        unset($_GET['page'], $_GET['step'], $_GET['cpms_first_setup']);
        $_POST = [];
        ScopeContext::clear();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        wp_set_current_user(0);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 1 — eligible fresh single-Clinic setup.
    // ------------------------------------------------------------------

    public function testEligibleFreshSingleClinicSetupProvisionsManagerAndAudits(): void
    {
        $actor = $this->makeUser('fp_ok_', 'administrator');
        $clinicId = (int) $this->seed_baseline['clinic_id'];

        $result = App::first_practice_setup_service()->claim(
            $actor,
            $clinicId,
            "مطب\u{200C}دکتر نمونه", // ZWNJ inside the name must survive
            '021-12345678',
            'تهران، خیابان آزادی'
        );

        self::assertSame($clinicId, $result['clinic_id']);
        self::assertGreaterThan(0, $result['membership_id']);
        self::assertGreaterThan(0, $result['audit_id']);

        // Marker consumed durably on the locked Clinic row.
        $claimedAt = App::db()->fetchValue(
            'SELECT first_setup_claimed_at FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d',
            [$clinicId]
        );
        self::assertNotNull($claimedAt);

        // Essential practice information persisted (Unicode round-trip).
        $name = (string) App::db()->fetchValue(
            'SELECT name FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d',
            [$clinicId]
        );
        self::assertSame("مطب\u{200C}دکتر نمونه", $name);

        // Manager membership via the existing membership engine.
        $membership = App::membership_service()->membership_for($clinicId, $actor);
        self::assertIsArray($membership);
        self::assertSame(RolesAndCapabilities::ROLE_MANAGER, (string) $membership['role_key']);
        self::assertSame('active', (string) $membership['status']);

        // Mandatory audit row in the same transaction.
        $auditCount = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND clinic_id = %d',
            [FirstPracticeSetupService::AUDIT_ACTION, $clinicId]
        );
        self::assertSame(1, $auditCount);
    }

    // ------------------------------------------------------------------
    // 2 + 11 — explicit Clinic confirmation; forged/missing/zero denied.
    // ------------------------------------------------------------------

    public function testMissingZeroAndForgedClinicSelectorAreDenied(): void
    {
        $actor = $this->makeUser('fp_sel_', 'administrator');
        $clinicId = (int) $this->seed_baseline['clinic_id'];

        foreach ([0, -1, $clinicId + 999999] as $badId) {
            try {
                App::first_practice_setup_service()->claim($actor, (int) $badId, 'X Clinic');
                self::fail('selector ' . $badId . ' must be denied');
            } catch (FirstPracticeSetupException $e) {
                self::assertSame(FirstPracticeSetupException::CODE_INVALID_CLINIC, $e->reason_code());
            }
        }

        // Nothing may be provisioned by a denied selector.
        self::assertSame(0, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships')
        ));
        self::assertNull(App::db()->fetchValue(
            'SELECT first_setup_claimed_at FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d',
            [$clinicId]
        ));
    }

    // ------------------------------------------------------------------
    // 3 — successful manager membership uses the built-in preset only.
    // ------------------------------------------------------------------

    public function testProvisioningUsesBuiltinManagerPresetWithoutCreatingRoles(): void
    {
        $actor = $this->makeUser('fp_preset_', 'administrator');
        $clinicId = (int) $this->seed_baseline['clinic_id'];

        $before_roles = wp_roles()->role_objects;
        App::first_practice_setup_service()->claim($actor, $clinicId, 'Preset Clinic');
        $after_roles = wp_roles()->role_objects;

        // No WordPress-global role registration happened.
        self::assertSame(array_keys($before_roles), array_keys($after_roles));

        $membership = App::membership_service()->membership_for($clinicId, $actor);
        self::assertSame(RolesAndCapabilities::ROLE_MANAGER, (string) $membership['role_key']);

        // No doctor professional identity was auto-created.
        self::assertSame(0, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinicians') . ' WHERE wp_user_id = %d',
            [$actor]
        ));
    }

    // ------------------------------------------------------------------
    // 4 — WP administrator role unchanged.
    // ------------------------------------------------------------------

    public function testWordPressAdministratorRoleIsUnchangedByProvisioning(): void
    {
        $actor = $this->makeUser('fp_role_', 'administrator');
        $clinicId = (int) $this->seed_baseline['clinic_id'];

        App::first_practice_setup_service()->claim($actor, $clinicId, 'Role Clinic');

        $user = get_userdata($actor);
        self::assertNotFalse($user);
        self::assertContains('administrator', $user->roles);
        self::assertTrue(user_can($user, 'manage_options'));
    }

    // ------------------------------------------------------------------
    // 5 — no clinical write access by default.
    // ------------------------------------------------------------------

    public function testManagerMembershipGrantsNoClinicalWriteAccessByDefault(): void
    {
        $actor = $this->makeUser('fp_clin_', 'administrator');
        $clinicId = (int) $this->seed_baseline['clinic_id'];

        App::first_practice_setup_service()->claim($actor, $clinicId, 'Clinical Clinic');
        $authorization = App::authorization_service();

        // Positive controls: the established manager preset is effective.
        self::assertTrue($authorization->can($actor, $clinicId, RolesAndCapabilities::CONFIG));
        self::assertTrue($authorization->can($actor, $clinicId, RolesAndCapabilities::PATIENT_READ));

        // Every clinical WRITE capability of the catalogue stays denied.
        foreach ([
            RolesAndCapabilities::PATIENT_CREATE,
            RolesAndCapabilities::PATIENT_UPDATE,
            RolesAndCapabilities::NOTE_CREATE,
            RolesAndCapabilities::NOTE_UPDATE,
            RolesAndCapabilities::PRIVATE_NOTE_CREATE,
            RolesAndCapabilities::PRIVATE_NOTE_READ,
            RolesAndCapabilities::REC_CREATE,
            RolesAndCapabilities::RX_CREATE,
            RolesAndCapabilities::RX_VOID,
            RolesAndCapabilities::FILE_UPLOAD,
            RolesAndCapabilities::CONSULT_START,
            RolesAndCapabilities::QUEUE_CHECKIN,
            RolesAndCapabilities::INVOICE_CREATE,
            RolesAndCapabilities::PAYMENT_CREATE,
            RolesAndCapabilities::MEDICAL_READ,
        ] as $cap) {
            self::assertFalse($authorization->can($actor, $clinicId, $cap), $cap . ' must stay denied');
        }
    }

    // ------------------------------------------------------------------
    // 6 — replay denied.
    // ------------------------------------------------------------------

    public function testReplayIsDeniedAndProvisionsNothingFurther(): void
    {
        $actor = $this->makeUser('fp_replay_', 'administrator');
        $clinicId = (int) $this->seed_baseline['clinic_id'];

        App::first_practice_setup_service()->claim($actor, $clinicId, 'Replay Clinic');
        $membershipsAfterFirst = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships')
        );

        try {
            App::first_practice_setup_service()->claim($actor, $clinicId, 'Replay Clinic');
            self::fail('replay must be denied');
        } catch (FirstPracticeSetupException $e) {
            self::assertSame(FirstPracticeSetupException::CODE_ALREADY_CLAIMED, $e->reason_code());
        }

        self::assertSame($membershipsAfterFirst, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships')
        ));
    }

    // ------------------------------------------------------------------
    // 7 — second administrator denied.
    // ------------------------------------------------------------------

    public function testSecondAdministratorIsDeniedAfterFirstClaim(): void
    {
        $first = $this->makeUser('fp_adm1_', 'administrator');
        $second = $this->makeUser('fp_adm2_', 'administrator');
        $clinicId = (int) $this->seed_baseline['clinic_id'];

        App::first_practice_setup_service()->claim($first, $clinicId, 'Two Admins Clinic');

        try {
            App::first_practice_setup_service()->claim($second, $clinicId, 'Two Admins Clinic');
            self::fail('second administrator must be denied');
        } catch (FirstPracticeSetupException $e) {
            self::assertSame(FirstPracticeSetupException::CODE_ALREADY_CLAIMED, $e->reason_code());
        }

        // The second administrator holds no membership at all.
        self::assertNull(App::membership_service()->membership_for($clinicId, $second));
    }

    // ------------------------------------------------------------------
    // 8 — previously administered Clinic denied (even with marker NULL).
    // ------------------------------------------------------------------

    public function testPreviouslyAdministeredClinicIsNeverClaimable(): void
    {
        $actor = $this->makeUser('fp_prev_', 'administrator');
        $clinicId = (int) $this->seed_baseline['clinic_id'];

        // Simulate an installation where administration evidence exists but the
        // marker column is somehow still NULL (pre-migration history shape).
        $doctor = $this->makeUser('fp_prev_doc_', 'cpms_doctor');
        App::membership_service()->create_membership($clinicId, $doctor, RolesAndCapabilities::ROLE_DOCTOR);

        try {
            App::first_practice_setup_service()->claim($actor, $clinicId, 'Taken Clinic');
            self::fail('previously administered Clinic must be denied');
        } catch (FirstPracticeSetupException $e) {
            self::assertSame(FirstPracticeSetupException::CODE_NOT_AVAILABLE, $e->reason_code());
        }

        // The attacker gains no membership and consumes nothing.
        self::assertNull(App::membership_service()->membership_for($clinicId, $actor));
    }

    // ------------------------------------------------------------------
    // 9 — suspended/removed manager cannot reopen bootstrap.
    // ------------------------------------------------------------------

    public function testSuspendedManagerCannotReopenBootstrap(): void
    {
        $first = $this->makeUser('fp_sus1_', 'administrator');
        $second = $this->makeUser('fp_sus2_', 'administrator');
        $clinicId = (int) $this->seed_baseline['clinic_id'];

        $membershipId = App::first_practice_setup_service()->claim($first, $clinicId, 'Suspended Clinic')['membership_id'];
        App::membership_service()->suspend_membership($membershipId);

        try {
            App::first_practice_setup_service()->claim($second, $clinicId, 'Suspended Clinic');
            self::fail('bootstrap must stay closed after manager suspension');
        } catch (FirstPracticeSetupException $e) {
            self::assertSame(FirstPracticeSetupException::CODE_ALREADY_CLAIMED, $e->reason_code());
        }

        // A pre-existing but SUSPENDED manager membership (no marker yet) is
        // also insufficient evidence of an unclaimed Clinic.
        $other = $this->makeUser('fp_sus3_', 'administrator');
        $this->resetMarkerAndMemberships($clinicId);
        $doc = $this->makeUser('fp_sus_doc_', 'cpms_doctor');
        $docMembership = App::membership_service()->create_membership($clinicId, $doc, RolesAndCapabilities::ROLE_MANAGER);
        App::membership_service()->suspend_membership($docMembership);

        try {
            App::first_practice_setup_service()->claim($other, $clinicId, 'Suspended Clinic');
            self::fail('suspended-manager-only state must stay closed');
        } catch (FirstPracticeSetupException $e) {
            self::assertSame(FirstPracticeSetupException::CODE_NOT_AVAILABLE, $e->reason_code());
        }
    }

    // ------------------------------------------------------------------
    // 10 — existing secretary/doctor cannot self-promote.
    // ------------------------------------------------------------------

    public function testExistingSecretaryOrDoctorCannotSelfPromote(): void
    {
        $clinicId = (int) $this->seed_baseline['clinic_id'];

        // A staff member WITH a durable membership but without WP administrator.
        $secretary = $this->makeUser('fp_sec_', 'cpms_secretary');
        App::membership_service()->create_membership($clinicId, $secretary, RolesAndCapabilities::ROLE_SECRETARY);

        try {
            App::first_practice_setup_service()->claim($secretary, $clinicId, 'Self Promote Clinic');
            self::fail('secretary must not self-promote');
        } catch (FirstPracticeSetupException $e) {
            self::assertSame(FirstPracticeSetupException::CODE_NOT_ADMINISTRATOR, $e->reason_code());
        }

        $membership = App::membership_service()->membership_for($clinicId, $secretary);
        self::assertSame(RolesAndCapabilities::ROLE_SECRETARY, (string) $membership['role_key']);

        // A WP administrator who is already a doctor still cannot bootstrap an
        // administered Clinic into a new manager membership.
        $this->resetMarkerAndMemberships($clinicId);
        $adminDoctor = $this->makeUser('fp_adm_doc_', 'administrator');
        $doc = $this->makeUser('fp_doc2_', 'cpms_doctor');
        App::membership_service()->create_membership($clinicId, $doc, RolesAndCapabilities::ROLE_DOCTOR);

        try {
            App::first_practice_setup_service()->claim($adminDoctor, $clinicId, 'Self Promote Clinic');
            self::fail('administered Clinic must not be claimable');
        } catch (FirstPracticeSetupException $e) {
            self::assertSame(FirstPracticeSetupException::CODE_NOT_AVAILABLE, $e->reason_code());
        }
    }

    // ------------------------------------------------------------------
    // 12 — multi-Clinic takeover denied (no first-row fallback).
    // ------------------------------------------------------------------

    public function testMultiClinicInstallationIsNeverBootstrapClaimable(): void
    {
        $actor = $this->makeUser('fp_multi_', 'administrator');
        $clinicId = (int) $this->seed_baseline['clinic_id'];
        $second = $this->makeSecondClinic('fp-second');

        foreach ([$clinicId, $second] as $target) {
            try {
                App::first_practice_setup_service()->claim($actor, $target, 'Multi Clinic');
                self::fail('multi-Clinic bootstrap must be denied for clinic ' . $target);
            } catch (FirstPracticeSetupException $e) {
                self::assertSame(FirstPracticeSetupException::CODE_NOT_AVAILABLE, $e->reason_code());
            }
        }

        self::assertSame(0, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships')
        ));

        // Display candidate must not silently resolve to a first row either.
        self::assertNull(App::first_practice_setup_service()->first_setup_candidate());
    }

    // ------------------------------------------------------------------
    // 13 — two-connection concurrency.
    // ------------------------------------------------------------------

    public function testTwoConnectionsCannotBothConsumeTheBootstrap(): void
    {
        $clinicId = (int) $this->seed_baseline['clinic_id'];

        /*
         * Green two-connection protocol (same as ConcurrencyTest /
         * VisitConcurrencyTest): the WP test suite runs every test inside an
         * open parent transaction whose InnoDB row locks conflict with — and
         * block — independent connections. Commit it first so the seed Clinic
         * row is in committed state for connA/connB; cleanup below is therefore
         * manual (tearDown's ROLLBACK can no longer undo committed rows).
         */
        global $wpdb;
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $connA = $this->freshMysqli();
        $connB = $this->freshMysqli();

        try {
            // Connection A holds the durable Clinic row lock — the exact lock the
            // claim transaction takes first.
            self::assertTrue($connA->query('START TRANSACTION'));
            $resA = $connA->query(
                'SELECT first_setup_claimed_at FROM ' . $this->table('cpms_clinics') .
                ' WHERE id = ' . $clinicId . ' FOR UPDATE'
            );
            self::assertNotFalse($resA, 'connA clinic-row lock acquisition failed: ' . $connA->error);

            // Connection B must not enter the critical section while A holds it.
            // PHP 8.1+ mysqli throws mysqli_sql_exception on errors (it does not
            // return false), so the lock-wait timeout is caught explicitly.
            self::assertTrue($connB->query('SET SESSION innodb_lock_wait_timeout = 2'));
            self::assertTrue($connB->query('START TRANSACTION'));
            $blocked = false;
            $errB    = '';
            try {
                $resB = $connB->query(
                    'SELECT first_setup_claimed_at FROM ' . $this->table('cpms_clinics') .
                    ' WHERE id = ' . $clinicId . ' FOR UPDATE'
                );
                if ($resB instanceof \mysqli_result) {
                    $resB->free();
                }
                $errB    = (string) $connB->error;
                $blocked = ($resB === false);
            } catch (\mysqli_sql_exception $e) {
                $blocked = true;
                $errB    = $e->getMessage();
            }
            self::assertTrue($blocked, 'second connection must block on the Clinic row lock');
            self::assertStringContainsString('Lock wait timeout', $errB);
            $connB->query('ROLLBACK');

            // A consumes the one-shot marker (guarded update — same guard as the
            // service) and commits.
            $now = App::db()->nowUtcSql();
            $okA = $connA->query(
                'UPDATE ' . $this->table('cpms_clinics') .
                " SET first_setup_claimed_at = '" . $connA->real_escape_string($now) . "'" .
                ' WHERE id = ' . $clinicId . ' AND first_setup_claimed_at IS NULL'
            );
            // mysqli::query() returns bool for UPDATE — the affected-row count is
            // the separate affected_rows property (exactly 1 row consumed).
            self::assertTrue((bool) $okA);
            self::assertSame(1, (int) $connA->affected_rows);
            self::assertTrue($connA->query('COMMIT'));

            // B retries after A commits: the marker is consumed and B's guarded
            // consumption matches zero rows — double provisioning is impossible.
            self::assertTrue($connB->query('START TRANSACTION'));
            $resB2 = $connB->query(
                'SELECT first_setup_claimed_at FROM ' . $this->table('cpms_clinics') .
                ' WHERE id = ' . $clinicId . ' FOR UPDATE'
            );
            self::assertNotFalse($resB2, 'connB post-commit read failed: ' . $connB->error);
            $rowB = $resB2->fetch_assoc();
            self::assertNotEmpty($rowB['first_setup_claimed_at']);
            $okB = $connB->query(
                'UPDATE ' . $this->table('cpms_clinics') .
                " SET first_setup_claimed_at = '" . $connB->real_escape_string($now) . "'" .
                ' WHERE id = ' . $clinicId . ' AND first_setup_claimed_at IS NULL'
            );
            self::assertTrue((bool) $okB);
            self::assertSame(0, (int) $connB->affected_rows, 'second connection must consume zero rows');
            $connB->query('ROLLBACK');
        } finally {
            /*
             * Manual committed-fixture cleanup: drop any open connA transaction
             * first (the success path already COMMITs the consumed marker; a
             * failure path may still hold one), then reset the marker in
             * autocommit mode so the reset actually persists for later tests.
             */
            $connA->query('ROLLBACK');
            $connA->query(
                'UPDATE ' . $this->table('cpms_clinics') .
                ' SET first_setup_claimed_at = NULL WHERE id = ' . $clinicId
            );
            $connA->close();
            $connB->close();
        }
    }

    // ------------------------------------------------------------------
    // 14 + 15 — failed audit rolls back provisioning; no partial membership.
    // ------------------------------------------------------------------

    public function testFailedAuditRollsBackProvisioningWithoutPartialMembership(): void
    {
        $actor = $this->makeUser('fp_audit_', 'administrator');
        $clinicId = (int) $this->seed_baseline['clinic_id'];
        $originalName = (string) $this->seed_baseline['clinic_name'];

        $fail_audit = static function (string $query): string {
            if (stripos($query, 'cpms_audit_logs') !== false && stripos($query, 'INSERT') !== false) {
                return 'INSERT INTO `cpms_missing_audit_probe` (`x`) VALUES (1)';
            }

            return $query;
        };
        add_filter('query', $fail_audit);

        $threw = false;
        try {
            App::first_practice_setup_service()->claim($actor, $clinicId, 'Audit Fail Clinic');
        } catch (\Throwable) {
            $threw = true;
        } finally {
            remove_filter('query', $fail_audit);
        }

        self::assertTrue($threw, 'a failed mandatory audit insert must abort the claim');

        // No partial writes of any kind.
        self::assertSame(0, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships')
        ));
        self::assertNull(App::db()->fetchValue(
            'SELECT first_setup_claimed_at FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d',
            [$clinicId]
        ));
        self::assertSame($originalName, (string) App::db()->fetchValue(
            'SELECT name FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d',
            [$clinicId]
        ));

        // The bootstrap stays legitimately open afterwards (retry state).
        $result = App::first_practice_setup_service()->claim($actor, $clinicId, 'Audit Fail Clinic');
        self::assertGreaterThan(0, $result['membership_id']);
    }

    // ------------------------------------------------------------------
    // 16 — route-level discovery: fresh eligible installation routes WP
    //      administrator to dedicated first-practice setup across all
    //      normal onboarding entry points.
    // ------------------------------------------------------------------

    public function testFreshEligibleInstallationRoutesAdministratorThroughDedicatedFirstPracticeOnboarding(): void
    {
        $actor = $this->makeUser('fp_nav_adm_', 'administrator');
        wp_set_current_user($actor);
        $clinicId = (int) $this->seed_baseline['clinic_id'];

        // 1. Real authenticated WP administrator with zero Clinic memberships.
        self::assertTrue(current_user_can('manage_options'));
        self::assertTrue(current_user_can(RolesAndCapabilities::CONFIG));
        self::assertSame(0, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships')
        ));

        // 2. One genuinely eligible initial Clinic.
        $candidate = App::first_practice_setup_service()->first_setup_candidate();
        self::assertIsArray($candidate);
        self::assertSame($clinicId, $candidate['clinic_id']);

        App::settings()->set('setup.completed', false);

        // 3a. Plugin action links point to cpms-first-practice-setup, not cpms-wizard.
        $links = CpmsAdminMenu::actionLinks(['<a href="#">Activate</a>']);
        $joinedLinks = implode(' ', $links);
        self::assertStringContainsString('page=' . CpmsSetupWizard::PAGE_SLUG, $joinedLinks);
        self::assertStringNotContainsString('page=' . FirstPracticeSetupPage::PAGE_SLUG, $joinedLinks);

        // 3b. Initial onboarding notice points to cpms-first-practice-setup, not cpms-wizard.
        delete_transient('cpms_onboarding_seen');
        $noticeHtml = $this->renderOutput(static function (): void {
            CpmsAdminMenu::onboardingNotice();
        });
        self::assertStringContainsString('page=' . CpmsSetupWizard::PAGE_SLUG, $noticeHtml);
        self::assertStringNotContainsString('page=' . FirstPracticeSetupPage::PAGE_SLUG, $noticeHtml);

        // 3c. Dashboard setup banner and setup status card point to cpms-first-practice-setup.
        $dashboardHtml = $this->renderOutput(static function (): void {
            CpmsDashboard::render();
        });
        self::assertStringContainsString('page=' . CpmsSetupWizard::PAGE_SLUG, $dashboardHtml);
        self::assertStringNotContainsString('page=' . FirstPracticeSetupPage::PAGE_SLUG, $dashboardHtml);

        // 5. Dedicated page renders correctly and is registered in CPMS assets.
        $_GET['page'] = FirstPracticeSetupPage::PAGE_SLUG;
        self::assertTrue(CpmsAssets::isCpmsPage());
        $pageHtml = $this->renderOutput(static function (): void {
            FirstPracticeSetupPage::render();
        });
        self::assertStringContainsString('راه‌اندازی مطب من', $pageHtml);
        self::assertStringContainsString($candidate['organization_name'], $pageHtml);
        self::assertStringContainsString($candidate['clinic_name'], $pageHtml);
        self::assertStringContainsString('name="action" value="cpms_first_practice_setup"', $pageHtml);
        self::assertStringContainsString('name="clinic_id" value="' . $clinicId . '"', $pageHtml);
        self::assertStringContainsString('name="confirm_identity" value="1"', $pageHtml);
        self::assertStringContainsString('name="practice_name"', $pageHtml);
        self::assertStringContainsString('name="_wpnonce"', $pageHtml);

        // Visiting/rendering pages never auto-claims the Clinic or creates memberships.
        self::assertNull(App::db()->fetchValue(
            'SELECT first_setup_claimed_at FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d',
            [$clinicId]
        ));
        self::assertSame(0, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships')
        ));
    }

    // ------------------------------------------------------------------
    // 17 — generic wizard does not create a confusing authorization dead
    //      end for the eligible first-run administrator.
    // ------------------------------------------------------------------

    public function testGenericWizardDoesNotCreateAuthorizationDeadEndForEligibleFirstRunAdministrator(): void
    {
        $actor = $this->makeUser('fp_wiz_adm_', 'administrator');
        wp_set_current_user($actor);
        $clinicId = (int) $this->seed_baseline['clinic_id'];

        // Direct GET on generic wizard (welcome + clinic + finish) surfaces the
        // dedicated first-practice bootstrap link and does not expose an
        // un-bootstrapped clinic save form that would fail with CLINIC_PERMISSION_DENIED.
        $_GET['step'] = 'welcome';
        $welcomeHtml = $this->renderOutput(static function (): void {
            CpmsSetupWizard::render();
        });
        self::assertStringContainsString('name="action" value="cpms_first_practice_setup"', $welcomeHtml);

        $_GET['step'] = 'clinic';
        $clinicStepHtml = $this->renderOutput(static function (): void {
            CpmsSetupWizard::render();
        });
        self::assertStringContainsString('name="action" value="cpms_first_practice_setup"', $clinicStepHtml);
        self::assertStringNotContainsString('name="clinic_name"', $clinicStepHtml);

        // Submitting cpms_wizard_save as the eligible first-run administrator
        // routes to cpms-first-practice-setup without "دسترسی لازم را ندارید"
        // and without auto-claiming the Clinic.
        foreach (['welcome', 'clinic'] as $step) {
            delete_transient('cpms_wizard_notice');
            $_POST = [
                '_wpnonce'       => wp_create_nonce('cpms_wizard_save'),
                'action'         => 'cpms_wizard_save',
                'step'           => $step,
                'clinic_name'    => 'مطب تست پیش از بوت‌استرپ',
                'clinic_address' => 'تهران',
                'clinic_phone'   => '02111111111',
            ];
            $redirectUrl = $this->captureRedirect(static function (): void {
                CpmsSetupWizard::save();
            });
            self::assertStringContainsString('page=' . CpmsSetupWizard::PAGE_SLUG, $redirectUrl);
            $wizardNotice = (string) (get_transient('cpms_wizard_notice') ?: '');
            self::assertStringNotContainsString('دسترسی لازم را ندارید', $wizardNotice);
        }

        // Still unclaimed and zero memberships after wizard navigation/submission.
        self::assertNull(App::db()->fetchValue(
            'SELECT first_setup_claimed_at FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d',
            [$clinicId]
        ));
        self::assertSame(0, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships')
        ));
    }

    // ------------------------------------------------------------------
    // 18 — route-level end-to-end bootstrap via FirstPracticeSetupPage
    //      followed by normal authorized Clinic management in CpmsSetupWizard.
    // ------------------------------------------------------------------

    public function testRouteLevelBootstrapEndToEndThenNormalClinicManagementFlow(): void
    {
        $actor = $this->makeUser('fp_e2e_adm_', 'administrator');
        wp_set_current_user($actor);
        $clinicId = (int) $this->seed_baseline['clinic_id'];
        App::settings()->set('setup.completed', false);

        $rolesBefore = array_keys(wp_roles()->role_objects);
        $userBefore = get_userdata($actor);
        self::assertNotFalse($userBefore);
        $userRolesBefore = $userBefore->roles;
        $userCapsBefore = $userBefore->caps;

        // Render dedicated page and extract real nonce + clinic_id from the form.
        $pageHtml = $this->renderOutput(static function (): void {
            FirstPracticeSetupPage::render();
        });
        self::assertSame(1, preg_match('/name="_wpnonce"\s+value="([^"]+)"/', $pageHtml, $nonceMatch));
        self::assertSame(1, preg_match('/name="clinic_id"\s+value="(\d+)"/', $pageHtml, $clinicMatch));
        $nonce = $nonceMatch[1];
        $postedClinicId = (int) $clinicMatch[1];
        self::assertSame($clinicId, $postedClinicId);

        // Submit valid nonce + explicit confirmation to the real admin-post handler.
        $practiceName = "مطب\u{200C}دکتر نمونه اول";
        $_POST = [
            '_wpnonce'         => $nonce,
            'action'           => 'cpms_first_practice_setup',
            'clinic_id'        => (string) $postedClinicId,
            'confirm_identity' => '1',
            'practice_name'    => $practiceName,
            'phone'            => '021-88776655',
            'address'          => 'تهران، خیابان ولیعصر',
        ];
        $redirectAfterBootstrap = $this->captureRedirect(static function (): void {
            FirstPracticeSetupPage::handle();
        });
        self::assertStringContainsString('page=' . StaffManagementPage::PAGE_SLUG, $redirectAfterBootstrap);
        self::assertStringContainsString('cpms_first_setup=done', $redirectAfterBootstrap);

        // Active cpms_manager membership created atomically with marker, profile, and audit.
        $membership = App::membership_service()->membership_for($clinicId, $actor);
        self::assertIsArray($membership);
        self::assertSame(RolesAndCapabilities::ROLE_MANAGER, (string) $membership['role_key']);
        self::assertSame('active', (string) $membership['status']);
        self::assertSame('clinic', (string) $membership['scope_mode']);
        self::assertSame(1, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships')
        ));

        $clinicRow = App::clinicRepository()->find($clinicId);
        self::assertIsArray($clinicRow);
        self::assertNotEmpty($clinicRow['first_setup_claimed_at']);
        self::assertSame($practiceName, (string) $clinicRow['name']);
        self::assertSame('021-88776655', (string) $clinicRow['phone']);
        self::assertSame('تهران، خیابان ولیعصر', (string) $clinicRow['address']);

        $auditCount = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND clinic_id = %d',
            [FirstPracticeSetupService::AUDIT_ACTION, $clinicId]
        );
        self::assertSame(1, $auditCount);

        // No WordPress-global role mutation; no clinician identity; no clinical-record authority.
        self::assertSame($rolesBefore, array_keys(wp_roles()->role_objects));
        $userAfter = get_userdata($actor);
        self::assertNotFalse($userAfter);
        self::assertSame($userRolesBefore, $userAfter->roles);
        self::assertSame($userCapsBefore, $userAfter->caps);
        self::assertSame(0, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinicians') . ' WHERE wp_user_id = %d',
            [$actor]
        ));
        self::assertTrue(App::authorization_service()->can($actor, $clinicId, RolesAndCapabilities::CONFIG));
        self::assertFalse(App::authorization_service()->can($actor, $clinicId, RolesAndCapabilities::MEDICAL_READ));
        self::assertFalse(App::authorization_service()->can($actor, $clinicId, RolesAndCapabilities::NOTE_CREATE));
        self::assertFalse(App::authorization_service()->can($actor, $clinicId, RolesAndCapabilities::PRIVATE_NOTE_READ));
        self::assertFalse(App::authorization_service()->can($actor, $clinicId, RolesAndCapabilities::RX_CREATE));

        // After bootstrap, normal onboarding discovery routes to the established generic wizard.
        $linksAfter = implode(' ', CpmsAdminMenu::actionLinks(['<a href="#">Activate</a>']));
        self::assertStringContainsString('page=' . CpmsSetupWizard::PAGE_SLUG, $linksAfter);
        self::assertStringNotContainsString('page=' . FirstPracticeSetupPage::PAGE_SLUG, $linksAfter);

        delete_transient('cpms_onboarding_seen');
        $noticeAfter = $this->renderOutput(static function (): void {
            CpmsAdminMenu::onboardingNotice();
        });
        self::assertStringContainsString('page=' . CpmsSetupWizard::PAGE_SLUG, $noticeAfter);
        self::assertStringNotContainsString('page=' . FirstPracticeSetupPage::PAGE_SLUG, $noticeAfter);

        $dashboardAfter = $this->renderOutput(static function (): void {
            CpmsDashboard::render();
        });
        self::assertStringContainsString('page=' . CpmsSetupWizard::PAGE_SLUG, $dashboardAfter);
        self::assertStringNotContainsString('page=' . FirstPracticeSetupPage::PAGE_SLUG, $dashboardAfter);

        // And the generic wizard's clinic step now renders and saves cleanly without permission denial.
        $_GET['step'] = 'clinic';
        $wizardClinicHtml = $this->renderOutput(static function (): void {
            CpmsSetupWizard::render();
        });
        self::assertStringContainsString('name="clinic_name"', $wizardClinicHtml);
        self::assertStringContainsString($practiceName, $wizardClinicHtml);

        $_POST = [
            '_wpnonce'       => wp_create_nonce('cpms_wizard_save'),
            'action'         => 'cpms_wizard_save',
            'step'           => 'clinic',
            'clinic_name'    => 'مطب دکتر نمونه — گام ویزارد',
            'clinic_address' => 'تهران، میدان ونک',
            'clinic_phone'   => '021-44332211',
        ];
        $wizardSaveRedirect = $this->captureRedirect(static function (): void {
            CpmsSetupWizard::save();
        });
        self::assertStringContainsString('page=' . CpmsSetupWizard::PAGE_SLUG, $wizardSaveRedirect);
        self::assertSame('booking', App::settings()->get('setup.current_step'));
        $updatedClinic = App::clinicRepository()->find($clinicId);
        self::assertSame('مطب دکتر نمونه — گام ویزارد', (string) ($updatedClinic['name'] ?? ''));
        self::assertSame('تهران، میدان ونک', (string) ($updatedClinic['address'] ?? ''));
        self::assertSame('021-44332211', (string) ($updatedClinic['phone'] ?? ''));
    }

    // ------------------------------------------------------------------
    // 19 — route-level validation: invalid nonce and missing confirmation
    //      cannot mutate state.
    // ------------------------------------------------------------------

    public function testRouteLevelBootstrapRequiresExplicitConfirmationAndValidNonce(): void
    {
        $actor = $this->makeUser('fp_nonce_adm_', 'administrator');
        wp_set_current_user($actor);
        $clinicId = (int) $this->seed_baseline['clinic_id'];
        $originalName = (string) $this->seed_baseline['clinic_name'];

        // 16. Invalid nonce cannot mutate state.
        $_POST = [
            '_wpnonce'         => 'invalid-nonce-value',
            'action'           => 'cpms_first_practice_setup',
            'clinic_id'        => (string) $clinicId,
            'confirm_identity' => '1',
            'practice_name'    => 'مطب با نانس نامعتبر',
        ];
        $redirectBadNonce = $this->captureRedirect(static function (): void {
            FirstPracticeSetupPage::handle();
        });
        self::assertStringContainsString('page=' . CpmsSetupWizard::PAGE_SLUG, $redirectBadNonce);
        $htmlAfterBadNonce = $this->renderOutput(static function (): void {
            FirstPracticeSetupPage::render();
        });
        self::assertStringContainsString('درخواست نامعتبر است', $htmlAfterBadNonce);

        // 6. Missing explicit confirmation cannot mutate state even with a valid nonce.
        $_POST = [
            '_wpnonce'      => wp_create_nonce('cpms_first_practice_setup'),
            'action'        => 'cpms_first_practice_setup',
            'clinic_id'     => (string) $clinicId,
            'practice_name' => 'مطب بدون تأیید هویت',
        ];
        $redirectUnconfirmed = $this->captureRedirect(static function (): void {
            FirstPracticeSetupPage::handle();
        });
        self::assertStringContainsString('page=' . CpmsSetupWizard::PAGE_SLUG, $redirectUnconfirmed);
        $htmlAfterUnconfirmed = $this->renderOutput(static function (): void {
            FirstPracticeSetupPage::render();
        });
        self::assertStringContainsString('تأیید هویت مطب الزامی است', $htmlAfterUnconfirmed);

        // Zero state mutation across both rejected requests.
        self::assertNull(App::db()->fetchValue(
            'SELECT first_setup_claimed_at FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d',
            [$clinicId]
        ));
        self::assertSame($originalName, (string) App::db()->fetchValue(
            'SELECT name FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d',
            [$clinicId]
        ));
        self::assertSame(0, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships')
        ));
    }

    // ------------------------------------------------------------------
    // 20 — route-level replay, existing/suspended memberships, multi-Clinic
    //      (including suspended second org), and previously initialized
    //      installations.
    // ------------------------------------------------------------------

    public function testRouteLevelReplayAndExistingOrSuspendedMembershipsAndMultiClinicAreDenied(): void
    {
        $firstAdmin = $this->makeUser('fp_r1_', 'administrator');
        $secondAdmin = $this->makeUser('fp_r2_', 'administrator');
        $clinicId = (int) $this->seed_baseline['clinic_id'];
        App::settings()->set('setup.completed', false);

        // Initial route-level claim succeeds.
        wp_set_current_user($firstAdmin);
        $_POST = [
            '_wpnonce'         => wp_create_nonce('cpms_first_practice_setup'),
            'action'           => 'cpms_first_practice_setup',
            'clinic_id'        => (string) $clinicId,
            'confirm_identity' => '1',
            'practice_name'    => 'مطب اولیه',
        ];
        $this->captureRedirect(static function (): void {
            FirstPracticeSetupPage::handle();
        });

        // 12. Replay via route handler by second admin is denied and mutates nothing.
        wp_set_current_user($secondAdmin);
        $_POST = [
            '_wpnonce'         => wp_create_nonce('cpms_first_practice_setup'),
            'action'           => 'cpms_first_practice_setup',
            'clinic_id'        => (string) $clinicId,
            'confirm_identity' => '1',
            'practice_name'    => 'مطب مهاجم دوم',
        ];
        $replayRedirect = $this->captureRedirect(static function (): void {
            FirstPracticeSetupPage::handle();
        });
        self::assertStringContainsString('page=' . CpmsSetupWizard::PAGE_SLUG, $replayRedirect);
        $replayHtml = $this->renderOutput(static function (): void {
            FirstPracticeSetupPage::render();
        });
        self::assertStringContainsString('قبلاً راه‌اندازی شده است', $replayHtml);
        self::assertStringNotContainsString('name="confirm_identity"', $replayHtml);
        self::assertSame('مطب اولیه', (string) App::db()->fetchValue(
            'SELECT name FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d',
            [$clinicId]
        ));
        self::assertNull(App::membership_service()->membership_for($clinicId, $secondAdmin));

        // 17. On this initialized installation, an admin without membership still gets
        //     CLINIC_PERMISSION_DENIED ("دسترسی لازم را ندارید") on Clinic profile save.
        $saveErr = CpmsSetupWizard::saveClinic(App::settings(), [
            'clinic_name' => 'تغییر غیرمجاز',
        ], $secondAdmin);
        self::assertSame('دسترسی لازم را ندارید', $saveErr);

        // 13. Suspended membership (even with marker NULL) prevents fresh bootstrap and discovery.
        $this->resetMarkerAndMemberships($clinicId);
        $staffUser = $this->makeUser('fp_sus_mem_', 'cpms_manager');
        $memId = App::membership_service()->create_membership($clinicId, $staffUser, RolesAndCapabilities::ROLE_MANAGER);
        App::membership_service()->suspend_membership($memId);

        $linksWithSuspended = implode(' ', CpmsAdminMenu::actionLinks(['<a href="#">Activate</a>']));
        self::assertStringContainsString('page=' . CpmsSetupWizard::PAGE_SLUG, $linksWithSuspended);
        self::assertStringNotContainsString('page=' . FirstPracticeSetupPage::PAGE_SLUG, $linksWithSuspended);

        $_POST = [
            '_wpnonce'         => wp_create_nonce('cpms_first_practice_setup'),
            'action'           => 'cpms_first_practice_setup',
            'clinic_id'        => (string) $clinicId,
            'confirm_identity' => '1',
            'practice_name'    => 'مطب با عضویت معلق',
        ];
        $this->captureRedirect(static function (): void {
            FirstPracticeSetupPage::handle();
        });
        self::assertNull(App::membership_service()->membership_for($clinicId, $secondAdmin));

        // 14. Multi-Clinic (even when second Clinic belongs to a suspended Organization)
        //     prevents discovery and bootstrap.
        $this->resetMarkerAndMemberships($clinicId);
        $secondClinicId = $this->makeSecondClinicUnderSuspendedOrg('fp-susp-org');
        self::assertNull(App::first_practice_setup_service()->first_setup_candidate());
        $linksMulti = implode(' ', CpmsAdminMenu::actionLinks(['<a href="#">Activate</a>']));
        self::assertStringNotContainsString('page=' . FirstPracticeSetupPage::PAGE_SLUG, $linksMulti);

        foreach ([$clinicId, $secondClinicId] as $targetId) {
            $_POST = [
                '_wpnonce'         => wp_create_nonce('cpms_first_practice_setup'),
                'action'           => 'cpms_first_practice_setup',
                'clinic_id'        => (string) $targetId,
                'confirm_identity' => '1',
                'practice_name'    => 'مطب چندکلینیکی',
            ];
            $this->captureRedirect(static function (): void {
                FirstPracticeSetupPage::handle();
            });
        }
        self::assertSame(0, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships')
        ));
    }

    // ------------------------------------------------------------------
    // 21 — unauthorized actors cannot use navigation or direct URLs to
    //      render or submit the first-practice bootstrap.
    // ------------------------------------------------------------------

    public function testUnauthorizedActorCannotUseNavigationOrDirectUrlsToBootstrap(): void
    {
        $clinicId = (int) $this->seed_baseline['clinic_id'];
        $subscriber = $this->makeUser('fp_unauth_sub_', 'subscriber');
        $secretary = $this->makeUser('fp_unauth_sec_', RolesAndCapabilities::ROLE_SECRETARY);
        $doctor = $this->makeUser('fp_unauth_doc_', RolesAndCapabilities::ROLE_DOCTOR);
        $managerOnly = $this->makeUser('fp_unauth_mgr_', RolesAndCapabilities::ROLE_MANAGER);

        foreach ([0, $subscriber, $secretary, $doctor, $managerOnly] as $actorId) {
            wp_set_current_user($actorId);
            self::assertFalse(current_user_can('manage_options'));

            $links = implode(' ', CpmsAdminMenu::actionLinks(['<a href="#">Activate</a>']));
            self::assertStringNotContainsString('page=' . FirstPracticeSetupPage::PAGE_SLUG, $links);

            $dieRender = $this->captureWpDie(static function (): void {
                FirstPracticeSetupPage::render();
            });
            self::assertStringContainsString('403', $dieRender);
            self::assertStringContainsString('دسترسی ندارید', $dieRender);

            $_POST = [
                '_wpnonce'         => wp_create_nonce('cpms_first_practice_setup'),
                'action'           => 'cpms_first_practice_setup',
                'clinic_id'        => (string) $clinicId,
                'confirm_identity' => '1',
                'practice_name'    => 'مطب غیرمجاز',
            ];
            $dieHandle = $this->captureWpDie(static function (): void {
                FirstPracticeSetupPage::handle();
            });
            self::assertStringContainsString('403', $dieHandle);
            self::assertStringContainsString('دسترسی ندارید', $dieHandle);
        }

        self::assertNull(App::db()->fetchValue(
            'SELECT first_setup_claimed_at FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d',
            [$clinicId]
        ));
        self::assertSame(0, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships')
        ));
    }

    // ------------------------------------------------------------------
    // 22 — multi-Clinic wp-admin chrome must not throw. A crash is not
    //      fail-closed authorization (WordPress recovery screen).
    // ------------------------------------------------------------------

    public function testTwoClinicsWithNoMembershipsDoNotCrashAdminChrome(): void
    {
        $actor = $this->makeUser('fp_mc0_', 'administrator');
        $clinicId = (int) $this->seed_baseline['clinic_id'];
        $this->makeSecondClinic('fp-mc0');
        App::resetScope();

        $this->assertAdminChromeDoesNotThrow($actor, 'two-clinics-no-memberships');
        self::assertSame(0, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships')
        ));
        self::assertNull(App::db()->fetchValue(
            'SELECT first_setup_claimed_at FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d',
            [$clinicId]
        ));
    }

    public function testTwoClinicsWithMembershipInOneDoNotCrashAdminChrome(): void
    {
        $actor = $this->makeUser('fp_mc1_', 'administrator');
        $clinicId = (int) $this->seed_baseline['clinic_id'];
        $secondId = $this->makeSecondClinic('fp-mc1');
        App::membership_service()->create_membership(
            $clinicId,
            $actor,
            RolesAndCapabilities::ROLE_MANAGER
        );
        App::resetScope();

        $this->assertAdminChromeDoesNotThrow($actor, 'two-clinics-membership-in-one');
        self::assertNotNull(App::membership_service()->membership_for($clinicId, $actor));
        self::assertNull(App::membership_service()->membership_for($secondId, $actor));
        self::assertSame(1, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships')
        ));
    }

    public function testTwoClinicsWithMembershipsInBothDoNotCrashAdminChrome(): void
    {
        $actor = $this->makeUser('fp_mc2_', 'administrator');
        $clinicId = (int) $this->seed_baseline['clinic_id'];
        $secondId = $this->makeSecondClinic('fp-mc2');
        App::membership_service()->create_membership(
            $clinicId,
            $actor,
            RolesAndCapabilities::ROLE_MANAGER
        );
        App::membership_service()->create_membership(
            $secondId,
            $actor,
            RolesAndCapabilities::ROLE_MANAGER
        );
        App::resetScope();

        $this->assertAdminChromeDoesNotThrow($actor, 'two-clinics-memberships-in-both');
        self::assertSame(2, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships')
        ));
        self::assertNull(App::db()->fetchValue(
            'SELECT first_setup_claimed_at FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d',
            [$clinicId]
        ));
    }

    // ------------------------------------------------------------------
    // Helpers.
    // ------------------------------------------------------------------

    private function makeUser(string $prefix, string $role): int
    {
        $login = $prefix . bin2hex(random_bytes(3));
        $userId = (int) wp_create_user($login, 'pass-12345', $login . '@test.local');
        $user = get_userdata($userId);
        self::assertNotFalse($user);
        $user->set_role($role);

        return $userId;
    }

    /** @return array{clinic_id:int, clinic_name:string, clinic_count:int, claimed_at:mixed} */
    private function seedClinicState(): array
    {
        $row = App::db()->fetchRow(
            'SELECT id, name, first_setup_claimed_at FROM ' . App::db()->table('cpms_clinics') . ' ORDER BY id ASC LIMIT 1'
        );
        self::assertIsArray($row);
        $count = (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinics'));

        return [
            'clinic_id'    => (int) $row['id'],
            'clinic_name'  => (string) $row['name'],
            'clinic_count' => $count,
            'claimed_at'   => $row['first_setup_claimed_at'],
        ];
    }

    private function makeSecondClinic(string $slug): int
    {
        global $wpdb;
        $orgId = (int) App::db()->fetchValue(
            'SELECT organization_id FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d',
            [(int) $this->seed_baseline['clinic_id']]
        );
        self::assertGreaterThan(0, $orgId);
        $now = App::db()->nowUtcSql();
        $ok = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $orgId,
            'FP Second ' . $slug,
            $slug . '-' . bin2hex(random_bytes(2)),
            'Asia/Tehran',
            $now,
            $now
        ));
        self::assertNotFalse($ok);

        return (int) $wpdb->insert_id;
    }

    private function makeSecondClinicUnderSuspendedOrg(string $slug): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $orgSlug = $slug . '-org-' . bin2hex(random_bytes(2));
        $okOrg = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at)
             VALUES (%s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'FP Suspended Org ' . $slug,
            $orgSlug,
            'suspended',
            $now,
            $now
        ));
        self::assertNotFalse($okOrg);
        $orgId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $orgId);

        $okClinic = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $orgId,
            'FP Second ' . $slug,
            $slug . '-' . bin2hex(random_bytes(2)),
            'Asia/Tehran',
            $now,
            $now
        ));
        self::assertNotFalse($okClinic);

        return (int) $wpdb->insert_id;
    }

    private function renderOutput(callable $fn): string
    {
        $level = ob_get_level();
        ob_start();
        try {
            $fn();

            return (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    /**
     * Ordinary wp-admin surfaces must render without an uncaught exception.
     * A crash (WordPress recovery) is not fail-closed authorization.
     */
    private function assertAdminChromeDoesNotThrow(int $actor, string $scenario): void
    {
        wp_set_current_user($actor);
        delete_transient('cpms_onboarding_seen');
        App::resetScope();

        $noticeHtml = $this->renderAdminSurface(
            $scenario . '.onboardingNotice',
            static function (): void {
                CpmsAdminMenu::onboardingNotice();
            }
        );
        $links = null;
        try {
            $links = CpmsAdminMenu::actionLinks(['<a href="#">Activate</a>']);
        } catch (\Throwable $e) {
            self::fail($scenario . '.actionLinks threw ' . $e::class . ': ' . $e->getMessage());
        }
        self::assertIsArray($links);
        $joinedLinks = implode(' ', $links);
        self::assertStringNotContainsString('page=' . FirstPracticeSetupPage::PAGE_SLUG, $joinedLinks);

        $dashboardHtml = $this->renderAdminSurface(
            $scenario . '.dashboard',
            static function (): void {
                CpmsDashboard::render();
            }
        );
        $_GET['step'] = 'clinic';
        $wizardHtml = $this->renderAdminSurface(
            $scenario . '.wizard',
            static function (): void {
                CpmsSetupWizard::render();
            }
        );
        $setupHtml = $this->renderAdminSurface(
            $scenario . '.firstPractice',
            static function (): void {
                FirstPracticeSetupPage::render();
            }
        );

        foreach (
            [
                'notice'    => $noticeHtml,
                'dashboard' => $dashboardHtml,
                'wizard'    => $wizardHtml,
                'setup'     => $setupHtml,
            ] as $label => $html
        ) {
            self::assertStringNotContainsString(
                'There has been a critical error',
                $html,
                $scenario . '.' . $label . ' must not render a WordPress recovery screen'
            );
            self::assertStringNotContainsString('name="confirm_identity"', $html);
            self::assertStringNotContainsString('name="clinic_name"', $html);
        }
        self::assertTrue(
            str_contains($setupHtml, 'راه‌اندازی اولیه در دسترس نیست')
            || str_contains($setupHtml, 'data-cpms-scope-required="1"')
            || str_contains($dashboardHtml, 'data-cpms-scope-required="1"')
            || str_contains($wizardHtml, 'data-cpms-scope-required="1"')
            || str_contains($noticeHtml, 'data-cpms-scope-required="1"'),
            $scenario . ' must present actionable authorization feedback rather than crash'
        );
    }

    private function renderAdminSurface(string $label, callable $fn): string
    {
        try {
            return $this->renderOutput($fn);
        } catch (\Throwable $e) {
            self::fail($label . ' threw ' . $e::class . ': ' . $e->getMessage());
        }

        return '';
    }

    private function captureRedirect(callable $fn): string
    {
        $location = '';
        $handler = static function (string $loc) use (&$location): string {
            $location = $loc;
            throw new \RuntimeException('REDIRECT::' . $loc);
        };
        add_filter('wp_redirect', $handler, 1);

        try {
            $fn();
        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'REDIRECT::')) {
                return $location;
            }
            throw $e;
        } finally {
            remove_filter('wp_redirect', $handler, 1);
        }

        return $location;
    }

    private function captureWpDie(callable $fn): string
    {
        $handler = static function (): callable {
            return static function ($message, $title = '', $args = []): void {
                $code = is_array($args) && isset($args['response']) ? (int) $args['response'] : (is_int($title) ? $title : 0);
                throw new \RuntimeException('WPDIE::' . $code . '::' . (string) $message);
            };
        };
        add_filter('wp_die_handler', $handler, PHP_INT_MAX);

        try {
            $fn();
        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'WPDIE::')) {
                return $e->getMessage();
            }
            throw $e;
        } finally {
            remove_filter('wp_die_handler', $handler, PHP_INT_MAX);
        }

        self::fail('Expected wp_die to be called');
    }

    /**
     * Test-local helper: clear membership rows + marker so a single-Clinic
     * "unclaimed" shape can be rebuilt for the next scenario. Only reachable
     * from inside the per-test transaction (no committed side effects).
     */
    private function resetMarkerAndMemberships(int $clinicId): void
    {
        App::db()->delete('cpms_clinic_memberships', ['clinic_id' => $clinicId]);
        App::db()->wpdb()->query(
            App::db()->wpdb()->prepare(
                'UPDATE ' . App::db()->table('cpms_clinics') . ' SET first_setup_claimed_at = NULL WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId
            )
        );
    }

    private function freshMysqli(): \mysqli
    {
        $host = defined('DB_HOST') ? (string) DB_HOST : '127.0.0.1';
        $port = null;
        if (str_contains($host, ':')) {
            [$host, $portPart] = explode(':', $host, 2);
            if (ctype_digit($portPart)) {
                $port = (int) $portPart;
            }
        }
        $user = defined('DB_USER') ? (string) DB_USER : 'root';
        $pass = defined('DB_PASSWORD') ? (string) DB_PASSWORD : '';
        $name = defined('DB_NAME') ? (string) DB_NAME : 'wordpress_test';

        $mysqli = $port !== null
            ? @new \mysqli($host, $user, $pass, $name, $port)
            : @new \mysqli($host, $user, $pass, $name);
        if ($mysqli->connect_errno !== 0) {
            $this->markTestSkipped('Cannot open independent DB connection: ' . $mysqli->connect_error);
        }
        $mysqli->set_charset('utf8mb4');

        return $mysqli;
    }

    private function table(string $short): string
    {
        global $table_prefix;

        return $table_prefix . $short;
    }
}
