<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Onboarding\FirstPracticeSetupException;
use ClinicCore\Application\Onboarding\FirstPracticeSetupService;
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
        App::resetScope();
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
        $host = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
        $user = defined('DB_USER') ? DB_USER : 'root';
        $pass = defined('DB_PASSWORD') ? DB_PASSWORD : '';
        $name = defined('DB_NAME') ? DB_NAME : 'wordpress_test';

        $mysqli = @new \mysqli($host, $user, $pass, $name);
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
