<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Roles\CustomRoleManagementService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\CustomRoleRepository;
use WP_UnitTestCase;

/**
 * Custom Role & Permission Management — Slice 2, atomic mutation + audit
 * (real-MySQL fault injection).
 *
 * PROVEN DEFECT this suite pins down: role mutations committed in their own
 * transaction and the audit event was written in a SECOND, independent
 * transaction. `AuditLogger::log()` ignored the wpdb insert result, so a failed
 * audit INSERT was silently swallowed and the service still returned success —
 * a persisted role mutation with no audit trail, and a false success response.
 *
 * REQUIRED CONTRACT (asserted here):
 *  1. role mutation + its required audit event share ONE transaction boundary;
 *  2. an audit persistence failure is observable (never silent);
 *  3. a failed audit persistence rolls the role mutation back;
 *  4. version and capability set stay exactly as they were;
 *  5. no partial definition row and no orphaned capability row survive;
 *  6. no success response is returned;
 *  7. the hash chain stays correct for successful writes;
 *  8. cross-Clinic authority is unchanged.
 *
 * Fault injection is a real MySQL error: the audit INSERT is rewritten (via the
 * documented wpdb 'query' filter) into a statement naming a column that does not
 * exist, so wpdb::insert() genuinely fails with "Unknown column". This is not a
 * fixture/bootstrap failure — the surrounding writes are untouched and the
 * successful-path test in this same class proves the machinery is healthy.
 */
final class CustomRoleAuditAtomicityTest extends WP_UnitTestCase
{
    private int $clinicA;
    private int $clinicB;
    private int $managerUserId;
    private int $otherClinicManagerUserId;

    /** @var callable|null فعالِ تزریق خطا (برای برداشتن تمیز در finally) */
    private $auditFault = null;

    /** @var bool وضعیت قبلی suppress_errors برای بازگردانی دقیق */
    private bool $previousSuppression = false;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();

        global $wpdb;
        $orgId = (int) $wpdb->get_var('SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics LIMIT 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertGreaterThan(0, $orgId, 'organization must exist');

        $this->clinicA = $this->makeClinic($orgId, 'cratA');
        $this->clinicB = $this->makeClinic($orgId, 'cratB');

        $this->managerUserId              = $this->makeUser('crat_mgr_' . bin2hex(random_bytes(2)));
        $this->otherClinicManagerUserId   = $this->makeUser('crat_mgr_b_' . bin2hex(random_bytes(2)));

        // Durable, Clinic-local management authority (membership preset cpms_manager).
        App::membership_service()->create_membership($this->clinicA, $this->managerUserId, RolesAndCapabilities::ROLE_MANAGER);
        App::membership_service()->create_membership($this->clinicB, $this->otherClinicManagerUserId, RolesAndCapabilities::ROLE_MANAGER);
    }

    protected function tearDown(): void
    {
        $this->restoreAuditInserts();
        App::resetScope();
        parent::tearDown();
    }

    private function service(): CustomRoleManagementService
    {
        return App::custom_role_management_service();
    }

    // ------------------------------------------------------------------
    // 1 — failed audit insert during CREATE.
    // ------------------------------------------------------------------

    public function testAuditFailureDuringCreateRollsBackAndReportsFailure(): void
    {
        global $wpdb;
        $caps = App::db()->table('cpms_custom_role_capabilities');
        $defs = App::db()->table('cpms_custom_role_defs');

        $this->failAuditInserts();

        $thrown = null;
        try {
            $this->service()->create(
                $this->managerUserId,
                $this->clinicA,
                'crat_create',
                'Atomic create',
                ['cpms_patient_read', 'cpms_search']
            );
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'a failed audit persistence must not be silent — the call must fail');
        self::assertStringContainsString(
            'audit',
            strtolower($thrown->getMessage()),
            'the failure must be reported as an audit-persistence failure, not misreported'
        );

        self::assertNull(
            (new CustomRoleRepository(App::db()))->definition_for($this->clinicA, 'crat_create'),
            'no partial definition row may survive a failed audit persistence'
        );
        self::assertSame(
            0,
            (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$defs} WHERE clinic_id = %d", $this->clinicA)), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'the definition must not be committed'
        );
        self::assertSame(
            0,
            (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                "SELECT COUNT(*) FROM {$caps} c JOIN {$defs} d ON d.id = c.role_def_id WHERE d.clinic_id = %d",
                $this->clinicA
            )),
            'no orphaned capability rows may survive'
        );
    }

    // ------------------------------------------------------------------
    // 2 — failed audit insert during capability replacement.
    // ------------------------------------------------------------------

    public function testAuditFailureDuringCapabilityReplacementPreservesPreviousSetAndVersion(): void
    {
        global $wpdb;
        $caps = App::db()->table('cpms_custom_role_capabilities');
        $defs = App::db()->table('cpms_custom_role_defs');

        $created = $this->service()->create(
            $this->managerUserId,
            $this->clinicA,
            'crat_caps',
            'Atomic caps',
            ['cpms_patient_read']
        );

        $before_caps = $this->capability_rows($created['id']);
        self::assertSame(['cpms_patient_read'], $before_caps);

        $this->failAuditInserts();
        $thrown = null;
        try {
            // Both capabilities are inside the manager preset AND the V1-editable
            // catalogue, so the write really proceeds and the injected audit
            // failure is the ONLY thing that can stop it. (An earlier revision of
            // this test used a non-delegable capability, which made the service
            // refuse the request before any write — a false green that proved
            // nothing; the message assertion below makes that impossible now.)
            $this->service()->replace_capabilities($this->managerUserId, $this->clinicA, 'crat_caps', ['cpms_report_read', 'cpms_search']);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        } finally {
            $this->restoreAuditInserts();
        }

        self::assertNotNull($thrown, 'a failed audit persistence during capability replacement must fail the call');
        self::assertStringContainsString(
            'audit',
            strtolower($thrown->getMessage()),
            'the call must fail because the audit event could not be persisted — not because of a validation refusal'
        );

        $stored = (new CustomRoleRepository(App::db()))->definition_for($this->clinicA, 'crat_caps');
        self::assertNotNull($stored);
        self::assertSame(1, (int) $stored['version'], 'the durable version must not advance when the audit event cannot be persisted');
        self::assertSame($before_caps, $this->capability_rows($created['id']), 'the previous capability set must be exactly preserved');
        self::assertSame($before_caps, $stored['capabilities']);

        // A second, later mutation with a healthy audit path must still work and
        // must still be the version-2 change — proving the failed attempt left no
        // trace behind (no false rollback guarantee).
        $updated = $this->service()->replace_capabilities($this->managerUserId, $this->clinicA, 'crat_caps', ['cpms_search']);
        self::assertSame(2, $updated['version']);
        self::assertSame(['cpms_search'], $this->capability_rows($created['id']));

        self::assertSame(
            0,
            (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$defs} WHERE clinic_id = %d AND role_key = %s AND version <> 2", $this->clinicA, 'crat_caps')), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'only the successful replacement may be persisted'
        );
        self::assertSame(
            1,
            (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$caps} WHERE role_def_id = %d", $created['id'])), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'no duplicate/orphan capability rows after the failed attempt'
        );
        self::assertTrue(App::audit()->verifyChain(500)['ok'], 'the audit chain must stay intact');
    }

    // ------------------------------------------------------------------
    // 3 — failed audit insert during display-name update.
    // ------------------------------------------------------------------

    public function testAuditFailureDuringRenamePreservesPreviousDisplayNameAndVersion(): void
    {
        $created = $this->service()->create(
            $this->managerUserId,
            $this->clinicA,
            'crat_name',
            'Atomic name',
            ['cpms_patient_read']
        );

        $this->failAuditInserts();
        $thrown = null;
        try {
            $this->service()->rename($this->managerUserId, $this->clinicA, 'crat_name', 'Renamed atomically');
        } catch (\RuntimeException $e) {
            $thrown = $e;
        } finally {
            $this->restoreAuditInserts();
        }

        self::assertNotNull($thrown, 'a failed audit persistence during a rename must fail the call');

        $stored = (new CustomRoleRepository(App::db()))->definition_for($this->clinicA, 'crat_name');
        self::assertNotNull($stored);
        self::assertSame('Atomic name', $stored['display_name'], 'the previous display name must be preserved');
        self::assertSame(1, (int) $stored['version'], 'the durable version must not advance');
        self::assertSame(['cpms_patient_read'], $stored['capabilities'], 'a rename must never touch capabilities');

        // And the immutable technical key is still the same object.
        self::assertSame($created['id'], (int) $stored['id']);
    }

    // ------------------------------------------------------------------
    // 4 — successful path: audit persisted, chain valid, ordering correct.
    // ------------------------------------------------------------------

    public function testSuccessfulMutationsPersistAuditedEventsInOneBoundaryAndKeepTheChainValid(): void
    {
        global $wpdb;
        $logs = App::db()->table('cpms_audit_logs');

        $created = $this->service()->create(
            $this->managerUserId,
            $this->clinicA,
            'crat_ok',
            'Healthy path',
            ['cpms_patient_read']
        );
        $renamed = $this->service()->rename($this->managerUserId, $this->clinicA, 'crat_ok', 'Healthy path v2');
        $replaced = $this->service()->replace_capabilities($this->managerUserId, $this->clinicA, 'crat_ok', ['cpms_search']);

        self::assertSame(1, $created['version']);
        self::assertSame(2, $renamed['version']);
        self::assertSame(3, $replaced['version']);

        $actions = array_map(
            'strval',
            (array) $wpdb->get_col($wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                "SELECT action FROM {$logs} WHERE resource_type = 'custom_role_definition' AND resource_id = %d ORDER BY id ASC",
                $created['id']
            ))
        );
        self::assertSame(
            [
                CustomRoleManagementService::AUDIT_DEFINED,
                CustomRoleManagementService::AUDIT_DISPLAY_NAME_CHANGED,
                CustomRoleManagementService::AUDIT_CAPABILITIES_CHANGED,
            ],
            $actions,
            'every successful mutation must have exactly one persisted audit event, in order'
        );

        $chain = App::audit()->verifyChain(500);
        self::assertTrue($chain['ok'], 'the hash chain must remain valid');
        self::assertGreaterThanOrEqual(3, $chain['checked']);

        // No PHI / no credentials in the stored payloads.
        $json = implode(' ', array_map('strval', (array) $wpdb->get_col($wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            "SELECT CONCAT(COALESCE(before_json,''),' ',COALESCE(after_json,''),' ',COALESCE(meta_json,'')) FROM {$logs} WHERE resource_type = 'custom_role_definition' AND resource_id = %d",
            $created['id']
        ))));
        foreach (['password', 'national_id', 'mobile', 'otp'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, strtolower($json), 'audit payloads must not contain sensitive values');
        }
    }

    // ------------------------------------------------------------------
    // 5 — cross-Clinic authority unchanged while the fault machinery is active.
    // ------------------------------------------------------------------

    public function testFailedAuditPersistenceNeverWidensCrossClinicAuthority(): void
    {
        $this->failAuditInserts();
        try {
            $denied = null;
            try {
                // Clinic B's manager acting on Clinic A: must be denied BEFORE any
                // write, and the injected audit fault must not change that.
                $this->service()->create(
                    $this->otherClinicManagerUserId,
                    $this->clinicA,
                    'crat_cross',
                    'Cross clinic',
                    ['cpms_patient_read']
                );
            } catch (\RuntimeException $e) {
                $denied = $e;
            }
            self::assertNotNull($denied, 'a non-member of Clinic A must stay denied');
            self::assertInstanceOf(
                \ClinicCore\Application\Scope\ScopeRequiredException::class,
                $denied,
                'the denial must come from trusted-scope establishment, not from a capability re-check'
            );
        } finally {
            $this->restoreAuditInserts();
        }

        self::assertNull(
            (new CustomRoleRepository(App::db()))->definition_for($this->clinicA, 'crat_cross'),
            'a denied actor must never produce a definition, with or without an audit fault'
        );
        self::assertNull(
            (new CustomRoleRepository(App::db()))->definition_for($this->clinicB, 'crat_cross'),
            'the definition must not have leaked into the actor\'s own Clinic either'
        );
    }

    // ================= fault injection =================

    /**
     * Rewrites every INSERT into the audit table into a statement naming a column
     * that does not exist (real MySQL 1054), so wpdb::insert() genuinely fails.
     * Error output is suppressed so the ONLY observable signal is the product's
     * own behaviour (last_error is still set by wpdb).
     */
    private function failAuditInserts(): void
    {
        global $wpdb;

        self::assertNull($this->auditFault, 'the audit fault must not be installed twice');

        $fault = static function ($query) {
            $sql = (string) $query;
            if (preg_match('/\\A\\s*INSERT\\s+INTO\\s+(\\S+)/i', $sql, $m) === 1
                && str_contains($m[1], 'cpms_audit_logs')) {
                return 'INSERT INTO ' . $m[1] . ' (`cpms_fault_injection_no_such_column`) VALUES (1)';
            }

            return $query;
        };

        $this->previousSuppression = (bool) $wpdb->suppress_errors(true);
        add_filter('query', $fault, 5);
        $this->auditFault = $fault;
    }

    private function restoreAuditInserts(): void
    {
        if ($this->auditFault === null) {
            return;
        }

        remove_filter('query', $this->auditFault, 5);
        $this->auditFault = null;

        global $wpdb;
        $wpdb->suppress_errors($this->previousSuppression);
    }

    /**
     * @return list<string>
     */
    private function capability_rows(int $definitionId): array
    {
        global $wpdb;
        $table = App::db()->table('cpms_custom_role_capabilities');

        return array_map(
            'strval',
            (array) $wpdb->get_col($wpdb->prepare("SELECT capability FROM {$table} WHERE role_def_id = %d ORDER BY capability ASC", $definitionId)) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
    }

    // ================= fixtures =================

    private function makeClinic(int $orgId, string $prefix): int
    {
        global $wpdb;
        $slug = $prefix . '-' . bin2hex(random_bytes(4));
        $now  = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $orgId,
            'CustomRole Atomicity ' . $slug,
            $slug,
            'Asia/Tehran',
            $now,
            $now
        ));

        return (int) $wpdb->insert_id;
    }

    private function makeUser(string $login, string $role = 'subscriber'): int
    {
        $userId = (int) wp_create_user($login, wp_generate_password(24), $login . '@crat.test');
        self::assertGreaterThan(0, $userId, "user {$login} created");
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }
}
