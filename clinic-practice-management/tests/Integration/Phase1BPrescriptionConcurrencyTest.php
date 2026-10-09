<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * Phase 1B Blocker 2 — Two-Connection Concurrency Proofs for E11 Prescription Finalization:
 *
 * 1. Lock Serialization: When an independent DB connection holds an exclusive lock
 *    on `cpms_clinicians` (FOR UPDATE), finalization blocks on lock wait until release.
 * 2. Concurrent Revocation: When an independent DB connection commits deactivation
 *    on `cpms_clinicians`, finalization's locking read observes the committed revocation
 *    and denies with non-enumerating 404, draft status, and zero mutation.
 *
 * Fixtures are COMMITTED (independent connections must see them);
 * cleanup is MANUAL in tearDown() (tearDown ROLLBACK is a no-op after COMMIT).
 */
final class Phase1BPrescriptionConcurrencyTest extends WP_UnitTestCase
{
    private const NS = '/cpms/v1';

    private int $orgId = 0;
    private int $clinicId = 0;
    private int $locationId = 0;
    private int $doctorUserId = 0;
    private int $clinicianId = 0;

    /** @var list<int> */
    private array $prescriptionIds = [];

    /** @var list<int> */
    private array $visitIds = [];

    /** @var list<int> */
    private array $patientIds = [];

    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user(0);

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));

        // 0. Organization
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, "active", %s, %s)',
                'Org P1B Conc ' . $unique,
                'p1b-org-' . $unique,
                $now,
                $now
            )
        );
        $this->orgId = (int) $wpdb->insert_id;

        // 1. Clinic
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, "Asia/Tehran", %s, %s)',
                $this->orgId,
                'Phase1B Concurrency Clinic ' . $unique,
                'p1b-conc-' . $unique,
                $now,
                $now
            )
        );
        $this->clinicId = (int) $wpdb->insert_id;

        // 2. Primary Location
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, "Asia/Tehran", 1, 1, %s, %s)',
                $this->clinicId,
                'Primary Location',
                'p1b-loc-' . $unique,
                $now,
                $now
            )
        );
        $this->locationId = (int) $wpdb->insert_id;

        // 3. Doctor User
        $this->doctorUserId = $this->makeUser('p1b_conc_doc', RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($this->doctorUserId, $this->clinicId, RolesAndCapabilities::ROLE_DOCTOR);

        // 4. Clinician Profile
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, wp_user_id, full_name, is_active, created_at, updated_at) VALUES (%d, %d, %s, 1, %s, %s)',
                $this->clinicId,
                $this->doctorUserId,
                'Dr Phase1B Concurrency',
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;
    }

    protected function tearDown(): void
    {
        $this->purgeFixture();
        Settings::flushCache();
        ScopeContext::clear();
        App::resetScope();
        wp_set_current_user(0);

        parent::tearDown();
    }

    private function purgeFixture(): void
    {
        if ($this->clinicId <= 0) {
            return;
        }

        global $wpdb;
        $c = (int) $this->clinicId;

        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_prescription_items WHERE prescription_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_prescriptions WHERE clinic_id = %d)', $c));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_prescriptions WHERE clinic_id = %d', $c));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_visits WHERE clinic_id = %d', $c));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_patients WHERE clinic_id = %d', $c));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_clinicians WHERE clinic_id = %d', $c));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_clinic_memberships WHERE clinic_id = %d', $c));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_locations WHERE clinic_id = %d', $c));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_audit_logs WHERE clinic_id = %d', $c));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_settings WHERE clinic_id = %d', $c));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = %d', $c));
        if ($this->orgId > 0) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_organizations WHERE id = %d', (int) $this->orgId));
        }
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
        $wpdb->query('COMMIT');

        foreach ($this->userIds as $userId) {
            $wpdb->delete($wpdb->usermeta, ['user_id' => $userId], ['%d']);
            $wpdb->delete($wpdb->users, ['ID' => $userId], ['%d']);
        }

        $this->prescriptionIds = [];
        $this->visitIds = [];
        $this->patientIds = [];
        $this->userIds = [];
    }

    /**
     * ACCEPTANCE BLOCKER 2 (Two-Connection Concurrency Proof): When an independent
     * database session holds an exclusive row lock on `cpms_clinicians`, the E11
     * prescription finalization transaction blocks waiting on the clinician row lock
     * (lock order: prescription row -> clinician identity row), times out under a
     * bounded wait, and upon lock release completes successfully without corruption.
     */
    public function testPhase1BTwoConnectionClinicianLockSerializesPrescriptionFinalization(): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();

        // 1. Patient
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, status, created_at, updated_at) VALUES (%d, %s, "Conc", "Patient", "active", %s, %s)',
                $this->clinicId,
                'MRN-CONC-' . bin2hex(random_bytes(3)),
                $now,
                $now
            )
        );
        $patientId = (int) $wpdb->insert_id;
        $this->patientIds[] = $patientId;

        // 2. Visit
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_visits (clinic_id, clinician_id, patient_id, source, status, visit_date, check_in_at, created_at, updated_at) VALUES (%d, %d, %d, "scheduled", "in_consultation", %s, %s, %s, %s)',
                $this->clinicId,
                $this->clinicianId,
                $patientId,
                gmdate('Y-m-d'),
                $now,
                $now,
                $now
            )
        );
        $visitId = (int) $wpdb->insert_id;
        $this->visitIds[] = $visitId;

        // 3. Prescription
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_prescriptions (clinic_id, prescription_number, visit_id, patient_id, clinician_id, status, is_patient_visible, created_at, updated_at) VALUES (%d, %s, %d, %d, %d, "draft", 1, %s, %s)',
                $this->clinicId,
                'RX-CONC-' . bin2hex(random_bytes(3)),
                $visitId,
                $patientId,
                $this->clinicianId,
                $now,
                $now
            )
        );
        $prescriptionId = (int) $wpdb->insert_id;
        $this->prescriptionIds[] = $prescriptionId;

        // Commit fixture rows so the independent MySQL connection sees them
        $wpdb->query('COMMIT');

        $conn = $this->freshMysqli();
        $conn->query('START TRANSACTION');
        $locked = $conn->query(
            'SELECT id FROM ' . App::db()->table('cpms_clinicians') . ' WHERE id = ' . (int) $this->clinicianId . ' FOR UPDATE'
        );
        $this->assertNotFalse($locked, 'Independent connection must acquire row lock on cpms_clinicians');

        // Main connection: short lock wait timeout -> finalization must block on clinician row lock
        $wpdb->query('SET SESSION innodb_lock_wait_timeout = 2');

        wp_set_current_user($this->doctorUserId);
        $headers = ['X-CPMS-Clinic-Id' => (string) $this->clinicId];
        $blocked = false;
        try {
            $res = $this->dispatch('POST', self::NS . '/prescriptions/' . $prescriptionId . '/finalize', [], $headers);
            if ($res->get_status() !== 200) {
                $blocked = true;
            }
        } catch (\Throwable $e) {
            $blocked = true;
        }
        $this->assertTrue($blocked, 'Finalization must block or fail when clinician row lock is held');
        $this->assertSame('draft', $this->rxStatus($prescriptionId), 'Prescription must remain draft while blocked');

        // Release lock on independent connection
        $conn->query('ROLLBACK');
        $conn->close();

        // After lock release, finalization completes successfully
        $success = $this->dispatch('POST', self::NS . '/prescriptions/' . $prescriptionId . '/finalize', [], $headers);
        $this->assertSame(200, $success->get_status());
        $this->assertSame('finalized', $this->rxStatus($prescriptionId));

        // Restore default timeout
        $wpdb->query('SET SESSION innodb_lock_wait_timeout = DEFAULT');
    }

    /**
     * ACCEPTANCE BLOCKER 2 (Two-Connection Revocation Proof): When an independent
     * database session updates and commits clinician deactivation, the E11
     * prescription finalization's locking read immediately observes the committed
     * revocation and denies with non-enumerating 404, draft status, and zero mutation.
     */
    public function testPhase1BTwoConnectionConcurrentRevocationDeniesFinalization(): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();

        // 1. Patient
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, status, created_at, updated_at) VALUES (%d, %s, "Revoke", "Patient", "active", %s, %s)',
                $this->clinicId,
                'MRN-REV-' . bin2hex(random_bytes(3)),
                $now,
                $now
            )
        );
        $patientId = (int) $wpdb->insert_id;
        $this->patientIds[] = $patientId;

        // 2. Visit
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_visits (clinic_id, clinician_id, patient_id, source, status, visit_date, check_in_at, created_at, updated_at) VALUES (%d, %d, %d, "scheduled", "in_consultation", %s, %s, %s, %s)',
                $this->clinicId,
                $this->clinicianId,
                $patientId,
                gmdate('Y-m-d'),
                $now,
                $now,
                $now
            )
        );
        $visitId = (int) $wpdb->insert_id;
        $this->visitIds[] = $visitId;

        // 3. Prescription
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_prescriptions (clinic_id, prescription_number, visit_id, patient_id, clinician_id, status, is_patient_visible, created_at, updated_at) VALUES (%d, %s, %d, %d, %d, "draft", 1, %s, %s)',
                $this->clinicId,
                'RX-REV-' . bin2hex(random_bytes(3)),
                $visitId,
                $patientId,
                $this->clinicianId,
                $now,
                $now
            )
        );
        $prescriptionId = (int) $wpdb->insert_id;
        $this->prescriptionIds[] = $prescriptionId;

        // Commit fixture rows so the independent MySQL connection sees them
        $wpdb->query('COMMIT');

        // Independent connection deactivates the clinician profile and commits
        $conn = $this->freshMysqli();
        $conn->query('START TRANSACTION');
        $updated = $conn->query(
            'UPDATE ' . App::db()->table('cpms_clinicians') . ' SET is_active = 0 WHERE id = ' . (int) $this->clinicianId
        );
        $this->assertNotFalse($updated);
        $conn->query('COMMIT');
        $conn->close();

        // Main connection attempts finalization
        wp_set_current_user($this->doctorUserId);
        $headers = ['X-CPMS-Clinic-Id' => (string) $this->clinicId];
        $auditBefore = (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs'), []);
        $attempt = $this->dispatch('POST', self::NS . '/prescriptions/' . $prescriptionId . '/finalize', [], $headers);
        $missing = $this->dispatch('POST', self::NS . '/prescriptions/999999999/finalize', [], $headers);

        $this->assertSame('draft', $this->rxStatus($prescriptionId), 'revoked identity must leave the prescription draft');
        $this->assertSame($auditBefore, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs'), []), 'revoked-identity denial must append no audit row');
        $this->assertSame($missing->get_status(), $attempt->get_status(), 'revoked-identity denial must stay non-enumerating');
    }

    private function rxStatus(int $id): string
    {
        return (string) (App::db()->fetchValue(
            'SELECT status FROM ' . App::db()->table('cpms_prescriptions') . ' WHERE id = %d LIMIT 1',
            [$id]
        ) ?? '');
    }

    private function freshMysqli(): \mysqli
    {
        global $wpdb;
        $host = $wpdb->dbhost;
        $user = $wpdb->dbuser;
        $pass = $wpdb->dbpassword;
        $name = $wpdb->dbname;

        $mysqli = @new \mysqli($host, $user, $pass, $name);
        if ($mysqli->connect_errno !== 0) {
            $this->markTestSkipped('Cannot open independent DB connection: ' . $mysqli->connect_error);
        }
        $mysqli->set_charset('utf8mb4');

        return $mysqli;
    }

    private function makeUser(string $login, string $role): int
    {
        $login .= '_' . bin2hex(random_bytes(4));
        $userId = (int) wp_create_user($login, 'pass-12345', $login . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }
        $this->userIds[] = $userId;

        return $userId;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function dispatch(string $method, string $route, array $body = [], array $headers = []): \WP_REST_Response
    {
        $request = new \WP_REST_Request($method, $route);
        if ($method === 'GET') {
            $request->set_query_params($body);
        } else {
            $request->set_body_params($body);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        foreach ($headers as $k => $v) {
            $request->set_header($k, $v);
        }

        return rest_do_request($request);
    }
}
