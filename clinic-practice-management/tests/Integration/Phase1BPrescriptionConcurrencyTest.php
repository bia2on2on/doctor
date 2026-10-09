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

    private int $clinicId = 1;
    private int $locationId = 0;

    /** @var list<int> */
    private array $prescriptionIds = [];

    /** @var list<int> */
    private array $visitIds = [];

    /** @var list<int> */
    private array $patientIds = [];

    /** @var list<int> */
    private array $clinicianIds = [];

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
        $this->locationId = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . $wpdb->prefix . 'cpms_locations WHERE clinic_id = %d AND is_primary = 1 LIMIT 1',
                $this->clinicId
            )
        );
    }

    protected function tearDown(): void
    {
        global $wpdb;

        foreach ($this->prescriptionIds as $rxId) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_prescription_items WHERE prescription_id = %d', $rxId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_prescriptions WHERE id = %d', $rxId));
        }
        foreach ($this->visitIds as $visitId) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_visits WHERE id = %d', $visitId));
        }
        foreach ($this->patientIds as $patientId) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_patients WHERE id = %d', $patientId));
        }
        foreach ($this->clinicianIds as $clinicianId) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_clinicians WHERE id = %d', $clinicianId));
        }
        foreach ($this->userIds as $userId) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'cpms_clinic_memberships WHERE wp_user_id = %d', $userId));
            $wpdb->delete($wpdb->usermeta, ['user_id' => $userId], ['%d']);
            $wpdb->delete($wpdb->users, ['ID' => $userId], ['%d']);
        }
        $wpdb->query('COMMIT');

        $this->prescriptionIds = [];
        $this->visitIds = [];
        $this->patientIds = [];
        $this->clinicianIds = [];
        $this->userIds = [];

        Settings::flushCache();
        ScopeContext::clear();
        App::resetScope();
        wp_set_current_user(0);

        parent::tearDown();
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

        $doctorUserId = $this->makeUser('p1b_conc_doc', RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($doctorUserId, $this->clinicId, RolesAndCapabilities::ROLE_DOCTOR);

        $clinicianId = $this->insertClinician($this->clinicId, $doctorUserId, 'Dr Phase1B Concurrency Lock');
        $patientId = $this->insertPatient($this->clinicId, 'Phase1BConcurrencyLock');
        $visitId = $this->insertVisit($this->clinicId, $this->locationId, $clinicianId, $patientId);
        $prescriptionId = $this->insertRx($this->clinicId, $visitId, $clinicianId);

        // Commit fixture rows so the independent MySQL connection sees them
        $wpdb->query('COMMIT');

        $conn = $this->freshMysqli();
        $conn->query('START TRANSACTION');
        $locked = $conn->query(
            'SELECT id FROM ' . App::db()->table('cpms_clinicians') . ' WHERE id = ' . (int) $clinicianId . ' FOR UPDATE'
        );
        $this->assertNotFalse($locked, 'Independent connection must acquire row lock on cpms_clinicians');

        // Main connection: short lock wait timeout -> finalization must block on clinician row lock
        $wpdb->query('SET SESSION innodb_lock_wait_timeout = 2');

        wp_set_current_user($doctorUserId);
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

        // Restore default timeout
        $wpdb->query('SET SESSION innodb_lock_wait_timeout = DEFAULT');

        // After lock release, finalization completes successfully
        $success = $this->dispatch('POST', self::NS . '/prescriptions/' . $prescriptionId . '/finalize', [], $headers);
        $this->assertSame(200, $success->get_status());
        $this->assertSame('finalized', $this->rxStatus($prescriptionId));
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

        $doctorUserId = $this->makeUser('p1b_rev_doc', RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($doctorUserId, $this->clinicId, RolesAndCapabilities::ROLE_DOCTOR);

        $clinicianId = $this->insertClinician($this->clinicId, $doctorUserId, 'Dr Phase1B Two-Conn Revoke');
        $patientId = $this->insertPatient($this->clinicId, 'Phase1BTwoConnRevoke');
        $visitId = $this->insertVisit($this->clinicId, $this->locationId, $clinicianId, $patientId);
        $prescriptionId = $this->insertRx($this->clinicId, $visitId, $clinicianId);

        // Commit fixture rows so the independent MySQL connection sees them
        $wpdb->query('COMMIT');

        // Independent connection deactivates the clinician profile and commits
        $conn = $this->freshMysqli();
        $conn->query('START TRANSACTION');
        $updated = $conn->query(
            'UPDATE ' . App::db()->table('cpms_clinicians') . ' SET is_active = 0 WHERE id = ' . (int) $clinicianId
        );
        $this->assertNotFalse($updated);
        $conn->query('COMMIT');
        $conn->close();

        // Main connection attempts finalization
        wp_set_current_user($doctorUserId);
        $headers = ['X-CPMS-Clinic-Id' => (string) $this->clinicId];
        $auditBefore = (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs'), []);
        $attempt = $this->dispatch('POST', self::NS . '/prescriptions/' . $prescriptionId . '/finalize', [], $headers);
        $missing = $this->dispatch('POST', self::NS . '/prescriptions/' . $this->missingId('cpms_prescriptions') . '/finalize', [], $headers);

        $this->assertSame('draft', $this->rxStatus($prescriptionId), 'revoked identity must leave the prescription draft');
        $this->assertSame($auditBefore, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs'), []), 'revoked-identity denial must append no audit row');
        $this->assertSame($this->responseErrorIdentity($missing), $this->responseErrorIdentity($attempt), 'revoked-identity denial must stay non-enumerating');
    }

    private function insertClinician(int $clinicId, int $wpUserId, string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (%d, %s, %d, 1, %s, %s)',
                $clinicId,
                $name,
                $wpUserId,
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        $this->clinicianIds[] = $id;

        return $id;
    }

    private function insertPatient(int $clinicId, string $lastName): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $seq = random_int(1000, 999999);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, "active", %s, %s)',
                $clinicId,
                'MR-P1BCONC-' . $seq . '-' . $clinicId,
                'Patient',
                $lastName,
                '0913' . sprintf('%07d', $seq),
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        $this->patientIds[] = $id;

        return $id;
    }

    private function insertVisit(int $clinicId, int $locationId, int $clinicianId, int $patientId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $today = gmdate('Y-m-d');
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_visits
                     (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, waiting_since, called_at, active, created_at, updated_at)
                 VALUES (%d, %d, %d, %d, "walk_in", "waiting", %s, %s, %s, %s, 1, %s, %s)',
                $clinicId,
                $locationId,
                $clinicianId,
                $patientId,
                $today,
                $today . ' 10:00:00.000',
                $today . ' 10:00:00.000',
                $today . ' 10:05:00.000',
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        $this->visitIds[] = $id;

        return $id;
    }

    private function insertRx(int $clinicId, int $visitId, int $clinicianId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $patientId = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT patient_id FROM ' . $wpdb->prefix . 'cpms_visits WHERE id = %d',
                $visitId
            )
        );
        $seq = random_int(100000, 9999999);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_prescriptions
                     (clinic_id, prescription_number, visit_id, patient_id, clinician_id, status, is_patient_visible, created_at, updated_at)
                 VALUES (%d, %s, %d, %d, %d, "draft", 1, %s, %s)',
                $clinicId,
                'RX-P1BCONC-' . $seq . '-' . $clinicId,
                $visitId,
                $patientId,
                $clinicianId,
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        $this->prescriptionIds[] = $id;

        return $id;
    }

    private function rxStatus(int $id): string
    {
        return (string) (App::db()->fetchValue(
            'SELECT status FROM ' . App::db()->table('cpms_prescriptions') . ' WHERE id = %d LIMIT 1',
            [$id]
        ) ?? '');
    }

    private function missingId(string $table): int
    {
        $missing = (int) App::db()->fetchValue(
            'SELECT COALESCE(MAX(id), 0) + 1 FROM ' . App::db()->table($table)
        );
        self::assertGreaterThan(0, $missing, 'precondition: missing ID must be positive');

        return $missing;
    }

    /** @return array{status: int, code: string, message: string, data: mixed} */
    private function responseErrorIdentity(\WP_REST_Response $response): array
    {
        $body = $response->get_data();
        if ($body instanceof \WP_Error) {
            $code = $body->get_error_code();

            return [
                'status' => $response->get_status(),
                'code' => $code,
                'message' => $body->get_error_message($code),
                'data' => $body->get_error_data($code),
            ];
        }
        $body = is_array($body) ? $body : [];

        return [
            'status' => $response->get_status(),
            'code' => (string) ($body['code'] ?? ''),
            'message' => (string) ($body['message'] ?? ''),
            'data' => $body['data'] ?? null,
        ];
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
        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        foreach ($headers as $k => $v) {
            $request->set_header($k, $v);
        }

        return rest_do_request($request);
    }
}
