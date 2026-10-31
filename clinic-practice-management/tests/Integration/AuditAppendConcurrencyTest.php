<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Audit\HashChain;
use WP_UnitTestCase;

/**
 * Synthetic concurrent SQL checks, not production booking acceptance.
 *
 * Workers use independent mysqli connections and hand-written audit inserts or
 * slot-counter updates. There is no simultaneous-launch barrier, no OTP, hold
 * record, confirmation, idempotency, clinical, finance or SMS service call, and
 * no independent verifier or lock-wait/deadlock/latency measurement. The
 * production verifier is checked against the resulting synthetic rows. A pass
 * here must not be described as the requested end-to-end concurrency proof.
 */
final class AuditAppendConcurrencyTest extends WP_UnitTestCase
{
    private int $organizationId = 0;
    private int $clinicId = 0;
    private int $locationId = 0;
    private int $clinicianId = 0;
    private int $clinicianUserId = 0;
    private int $slotId = 0;
    private int $auditBaselineId = 0;
    private int $auditBaselineCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        // Ensure guard exists (migration 0031).
        $guard = App::db()->fetchRow('SELECT id FROM ' . App::db()->table('cpms_audit_seq') . ' WHERE id = 1');
        if ($guard === null) {
            $this->fail('audit guard row missing — migration 0031 not applied');
        }
        $this->auditBaselineId = (int) App::db()->fetchValue(
            'SELECT COALESCE(MAX(id), 0) FROM ' . App::db()->table('cpms_audit_logs')
        );
        $this->auditBaselineCount = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs')
        );
        self::assertTrue(App::audit()->verifyFullChain()['ok'], 'precondition: global audit chain is valid before this test');
    }

    protected function tearDown(): void
    {
        global $wpdb;
        // Independent worker connections required committed fixtures and wrote
        // committed suffix rows. Clean up only this test's isolated tenant and
        // verified suffix, then COMMIT the removal so later suites continue to
        // see the untouched seed Clinic and prefix chain.
        try {
            if ($this->clinicId > 0) {
                $auditTable = App::db()->table('cpms_audit_logs');
                $suffix = App::db()->fetchAll(
                    'SELECT id, clinic_id FROM ' . $auditTable . ' WHERE id > %d ORDER BY id ASC FOR UPDATE',
                    [$this->auditBaselineId]
                );
                if ($suffix !== []) {
                    $globalMax = (int) App::db()->fetchValue('SELECT COALESCE(MAX(id), 0) FROM ' . $auditTable . ' FOR UPDATE');
                    self::assertSame($globalMax, (int) $suffix[count($suffix) - 1]['id'], 'cleanup precondition: test audit rows are the global suffix');
                    foreach ($suffix as $row) {
                        self::assertSame($this->clinicId, (int) $row['clinic_id'], 'cleanup precondition: every audit suffix row belongs to this fixture Clinic');
                    }
                    self::assertTrue(App::audit()->verifyFullChain()['ok'], 'cleanup precondition: append-only audit chain verifies before suffix cleanup');
                    App::db()->query('DELETE FROM ' . $auditTable . ' WHERE id > %d', [$this->auditBaselineId]);
                    $newTail = (int) App::db()->fetchValue('SELECT COALESCE(MAX(id), 0) FROM ' . $auditTable);
                    App::db()->query(
                        'UPDATE ' . App::db()->table('cpms_audit_seq') . ' SET last_audit_id = %d, updated_at = %s WHERE id = 1',
                        [$newTail, App::db()->nowUtcSql()]
                    );
                }
                $remaining = App::audit()->verifyFullChain();
                self::assertTrue($remaining['ok'], 'cleanup: preserved global audit prefix remains valid');
                self::assertSame($this->auditBaselineCount, $remaining['checked'], 'cleanup: only the pre-existing audit prefix remains');
            }
            if ($this->slotId > 0) {
                App::db()->query('DELETE FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d', [$this->slotId]);
            }
            if ($this->clinicianId > 0) {
                App::db()->query('DELETE FROM ' . App::db()->table('cpms_clinician_locations') . ' WHERE clinician_id = %d', [$this->clinicianId]);
                App::db()->query('DELETE FROM ' . App::db()->table('cpms_clinicians') . ' WHERE id = %d', [$this->clinicianId]);
            }
            if ($this->clinicId > 0) {
                App::db()->query('DELETE FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d', [$this->clinicId]);
                App::db()->query('DELETE FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = %d', [$this->clinicId]);
                App::db()->query('DELETE FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d', [$this->clinicId]);
            }
            if ($this->organizationId > 0) {
                App::db()->query('DELETE FROM ' . App::db()->table('cpms_organizations') . ' WHERE id = %d', [$this->organizationId]);
            }
            if ($this->clinicianUserId > 0) {
                $wpdb->delete($wpdb->usermeta, ['user_id' => $this->clinicianUserId], ['%d']);
                $wpdb->delete($wpdb->users, ['ID' => $this->clinicianUserId], ['%d']);
            }
            $wpdb->query('COMMIT');
        } finally {
            App::resetScope();
            parent::tearDown();
        }
    }

    public function testGuardSerializesConcurrentAuditAppendsAndChainRemainsIntact(): void
    {
        $this->prepareSlot(capacity: 50);
        $initialCount = $this->lockedAuditCount();

        // 10-way
        $this->assertConcurrentAuditInsertsPreserveChain(10, $initialCount);
        $after10 = $this->lockedAuditCount();
        $this->assertSame($initialCount + 10, $after10, '10-way: exactly 10 audit rows must be added');

        // 25-way
        $this->assertConcurrentAuditInsertsPreserveChain(25, $after10);
        $after25 = $this->lockedAuditCount();
        $this->assertSame($initialCount + 35, $after25, '25-way: exactly 25 more rows');

        // 50-way
        $this->assertConcurrentAuditInsertsPreserveChain(50, $after25);
        $after50 = $this->lockedAuditCount();
        $this->assertSame($initialCount + 85, $after50, '50-way: total 85 concurrent rows added');
    }

    public function testConcurrentSqlCapacityUpdatesLeaveProductionVerifierIntact(): void
    {
        // This is only a direct conditional slot-counter UPDATE: no hold row,
        // production BookingService, audit append or deadlock observation.
        $this->prepareSlot(capacity: 1);

        $successes = $this->forkBookingHolds(25, $this->slotId);
        $this->assertSame(1, $successes, 'capacity 1 with 25-way hold: exactly one must succeed');
        App::db()->wpdb()->query('COMMIT');

        $after = $this->lockedAuditCount();
        $this->assertIsInt($after);
        $chain = App::audit()->verifyFullChain();
        $this->assertTrue($chain['ok'], 'audit chain must be intact after 25-way booking race: ' . wp_json_encode($chain));
        // No unexpected genesis: only the first row in the entire history may have GENESIS.
        if ($after > 0) {
            $this->assertNoUnexpectedGenesis();
        }
        // Suffix verification (last 10) must also pass via predecessor anchor.
        $suffix = App::audit()->verifyChain(10);
        $this->assertTrue($suffix['ok'], 'suffix verification must pass: ' . wp_json_encode($suffix));
    }

    public function testSyntheticAuditRowsLeaveProductionVerifierIntact(): void
    {
        $this->prepareTenantOnly();
        $initial = $this->lockedAuditCount();
        // Five hand-written audit rows; this does not invoke any clinical,
        // financial or SMS production writer.
        $this->assertConcurrentAuditInsertsPreserveChain(5, $initial);

        // Check these synthetic rows with the production verifier only.
        $chain = App::audit()->verifyFullChain();
        $this->assertTrue($chain['ok'], 'mixed operations must not break chain');
    }

    // ------------------------------------------------------------------
    // Helpers: isolated committed tenant + slot fixture
    // ------------------------------------------------------------------

    private function prepareTenantOnly(): void
    {
        global $wpdb;
        if ($this->clinicId > 0) {
            return;
        }
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));
        App::db()->insert('cpms_organizations', [
            'name' => 'Audit Race Org ' . $unique,
            'slug' => 'audit-race-org-' . $unique,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->organizationId = (int) App::db()->wpdb_last_insert_id();
        self::assertGreaterThan(0, $this->organizationId);

        App::db()->insert('cpms_clinics', [
            'organization_id' => $this->organizationId,
            'name' => 'Audit Race Clinic ' . $unique,
            'slug' => 'audit-race-clinic-' . $unique,
            'timezone' => 'Asia/Tehran',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->clinicId = (int) App::db()->wpdb_last_insert_id();
        self::assertGreaterThan(0, $this->clinicId);
        // Commit so independent mysqli workers can reference clinic_id via FK.
        $wpdb->query('COMMIT');
    }

    private function prepareSlot(int $capacity): void
    {
        global $wpdb;
        $this->prepareTenantOnly();
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));

        App::db()->insert('cpms_locations', [
            'clinic_id' => $this->clinicId,
            'name' => 'Audit Test Location',
            'slug' => 'audit-test-loc-' . $unique,
            'is_active' => 1,
            'is_primary' => 1,
            'timezone' => 'Asia/Tehran',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->locationId = (int) App::db()->wpdb_last_insert_id();

        $userId = wp_create_user('audit_clin_' . $unique, wp_generate_password(12), 'audit_' . $unique . '@example.com');
        $this->assertNotWPError($userId);
        $this->clinicianUserId = (int) $userId;

        App::db()->insert('cpms_clinicians', [
            'clinic_id' => $this->clinicId,
            'wp_user_id' => $this->clinicianUserId,
            'full_name' => 'Dr Audit Concurrency',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->clinicianId = (int) App::db()->wpdb_last_insert_id();

        App::db()->insert('cpms_clinician_locations', [
            'clinician_id' => $this->clinicianId,
            'location_id' => $this->locationId,
            'is_primary' => 1,
            'created_at' => $now,
        ]);
        App::db()->insert('cpms_clinic_memberships', [
            'clinic_id' => $this->clinicId,
            'wp_user_id' => $this->clinicianUserId,
            'role_key' => 'cpms_doctor',
            'status' => 'active',
            'scope_mode' => 'clinic',
            'is_primary' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Create a future slot with given capacity.
        $date = gmdate('Y-m-d', time() + 86400 * 2);
        $time = '09:00:00';
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, 20, %d, 0, 0, 1, %s, %s)',
                $this->clinicId,
                $this->locationId,
                $this->clinicianId,
                $date,
                $time,
                $capacity,
                $now,
                $now
            )
        );
        $this->slotId = (int) $wpdb->insert_id;
        // Commit fixture so independent mysqli workers can see it.
        $wpdb->query('COMMIT');
    }

    private function lockedAuditCount(): int
    {
        return count(App::db()->fetchAll('SELECT 1 AS hit FROM ' . App::db()->table('cpms_audit_logs') . ' FOR UPDATE'));
    }

    // ------------------------------------------------------------------
    // Concurrent audit inserts via independent mysqli (real InnoDB locks)
    // ------------------------------------------------------------------

    /**
     * Fork $count workers each appending one audit row through the guard.
     * Each worker uses its own mysqli connection and replicates the AuditLogger
     * logic (guard FOR UPDATE → tail FOR UPDATE → compute hash → insert → update guard).
     *
     * @return void
     */
    private function assertConcurrentAuditInsertsPreserveChain(int $count, int $beforeCount): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $pids = [];
        $workerErrors = [];

        for ($i = 0; $i < $count; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork failed');
            }
            if ($pid === 0) {
                // Child — independent mysqli, single transactional audit append.
                $exit = 1;
                $mysqli = null;
                try {
                    $mysqli = $this->freshMysqli();
                    $mysqli->begin_transaction();
                    // 1) Guard lock
                    $guard = $mysqli->query('SELECT id FROM ' . $this->table('cpms_audit_seq') . ' WHERE id = 1 FOR UPDATE');
                    if ($guard === false || $guard->num_rows !== 1) {
                        throw new \RuntimeException('guard lock failed');
                    }
                    $guard->free();
                    // 2) Tail current read
                    $tail = $mysqli->query('SELECT row_hash FROM ' . $this->table('cpms_audit_logs') . ' ORDER BY id DESC LIMIT 1 FOR UPDATE');
                    $prevHash = HashChain::GENESIS;
                    if ($tail !== false && $tail->num_rows === 1) {
                        $row = $tail->fetch_assoc();
                        $prevHash = (string) ($row['row_hash'] ?? HashChain::GENESIS);
                    }
                    if ($tail instanceof \mysqli_result) {
                        $tail->free();
                    }

                    // 3) Build canonical fields and hash (mirrors HashChain::fieldsFor)
                    $now = gmdate('Y-m-d H:i:s') . '.000';
                    $fields = [
                        'action' => 'CONCURRENT_TEST_' . $i,
                        'actor_role' => 'system',
                        'actor_wp_user_id' => null,
                        'clinic_id' => $this->clinicId,
                        'created_at' => $now,
                        'ip_hash' => null,
                        'patient_id' => null,
                        'request_id' => 'test-req-' . getmypid() . '-' . $i,
                        'resource_id' => $i,
                        'resource_type' => 'test',
                        'session_id' => null,
                    ];
                    ksort($fields);
                    $canonical = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
                    $rowHash = hash('sha256', $prevHash . $canonical);

                    $stmt = $mysqli->prepare(
                        'INSERT INTO ' . $this->table('cpms_audit_logs') .
                        ' (clinic_id, actor_wp_user_id, actor_role, action, resource_type, resource_id, patient_id, ip_hash, session_id, request_id, prev_hash, row_hash, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    if ($stmt === false) {
                        throw new \RuntimeException('prepare failed: ' . $mysqli->error);
                    }
                    $clinicId = $this->clinicId;
                    $actorWpUserId = null;
                    $actorRole = 'system';
                    $action = 'CONCURRENT_TEST_' . $i;
                    $resourceType = 'test';
                    $resourceId = $i;
                    $patientId = null;
                    $ipHash = null;
                    $sessionId = null;
                    $requestId = 'test-req-' . getmypid() . '-' . $i;
                    // mysqli bind requires variables; use null for nullable ints via string binding then set null handling
                    // Instead use query with escaped values for simplicity (test-only).
                    $stmt->close();
                    $sql = sprintf(
                        "INSERT INTO %s (clinic_id, actor_wp_user_id, actor_role, action, resource_type, resource_id, patient_id, ip_hash, session_id, request_id, prev_hash, row_hash, created_at) VALUES (%d, NULL, '%s', '%s', '%s', %d, NULL, NULL, NULL, '%s', '%s', '%s', '%s')",
                        $this->table('cpms_audit_logs'),
                        $clinicId,
                        $mysqli->real_escape_string($actorRole),
                        $mysqli->real_escape_string($action),
                        $mysqli->real_escape_string($resourceType),
                        $resourceId,
                        $mysqli->real_escape_string($requestId),
                        $mysqli->real_escape_string($prevHash),
                        $mysqli->real_escape_string($rowHash),
                        $mysqli->real_escape_string($now)
                    );
                    if ($mysqli->query($sql) === false) {
                        throw new \RuntimeException('insert failed: ' . $mysqli->error);
                    }
                    $auditId = $mysqli->insert_id;
                    // Update guard bookkeeping (part of same tx)
                    $mysqli->query(sprintf("UPDATE %s SET last_audit_id = %d, updated_at = '%s' WHERE id = 1", $this->table('cpms_audit_seq'), (int) $auditId, $mysqli->real_escape_string($now)));
                    $mysqli->commit();
                    $exit = 0;
                } catch (\Throwable $e) {
                    if ($mysqli instanceof \mysqli) {
                        try {
                            $mysqli->rollback();
                        } catch (\Throwable) {
                        }
                    }
                    $exit = 2;
                    // Child must not run PHPUnit again — exit immediately.
                    error_log('audit child failed: ' . $e->getMessage());
                }
                if ($mysqli instanceof \mysqli) {
                    @$mysqli->close();
                }
                exit($exit);
            }
            $pids[] = $pid;
        }

        $success = 0;
        $failed = 0;
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            if (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0) {
                $success++;
            } else {
                $failed++;
            }
        }

        $this->assertSame($count, $success, $count . '-way concurrent audit inserts must all succeed (failed=' . $failed . ')');
        $this->assertSame(0, $failed);
        // End any open REPEATABLE READ snapshot before non-locking verifier reads
        // so rows committed by forked child connections are visible here.
        App::db()->wpdb()->query('COMMIT');

        // Verify chain integrity after this batch.
        $chain = App::audit()->verifyFullChain();
        $this->assertTrue($chain['ok'], 'full chain must be intact after ' . $count . '-way: ' . wp_json_encode($chain));
        $this->assertSame($beforeCount + $count, $chain['checked'], 'checked count must match');
        $this->assertNoUnexpectedGenesis();
        // Also verify suffix (last 10) via predecessor anchor.
        $suffix = App::audit()->verifyChain(10);
        $this->assertTrue($suffix['ok'], 'suffix must be intact: ' . wp_json_encode($suffix));
    }

    private function forkBookingHolds(int $count, int $slotId): int
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }
        $pids = [];
        for ($i = 0; $i < $count; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork failed');
            }
            if ($pid === 0) {
                $exit = 1;
                $mysqli = null;
                try {
                    $mysqli = $this->freshMysqli();
                    // Direct conditional UPDATE only; neither BookingService nor AuditLogger runs.
                    $mysqli->begin_transaction();
                    $now = gmdate('Y-m-d H:i:s') . '.000';
                    $stmt = $mysqli->prepare('UPDATE ' . $this->table('cpms_schedule_slots') . ' SET held_count = held_count + 1, updated_at = ? WHERE id = ? AND is_open = 1 AND capacity - booked_count - held_count > 0');
                    if ($stmt !== false) {
                        $stmt->bind_param('si', $now, $slotId);
                        $stmt->execute();
                        $exit = $stmt->affected_rows === 1 ? 0 : 1;
                        $stmt->close();
                    }
                    if ($exit === 0) {
                        $mysqli->commit();
                    } else {
                        $mysqli->rollback();
                    }
                } catch (\Throwable) {
                    if ($mysqli instanceof \mysqli) {
                        try { $mysqli->rollback(); } catch (\Throwable) {}
                    }
                    $exit = 1;
                }
                if ($mysqli instanceof \mysqli) {
                    @$mysqli->close();
                }
                exit($exit);
            }
            $pids[] = $pid;
        }
        $successes = 0;
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            if (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0) {
                $successes++;
            }
        }
        return $successes;
    }

    private function assertNoUnexpectedGenesis(): void
    {
        $rows = App::db()->fetchAll('SELECT id, prev_hash FROM ' . App::db()->table('cpms_audit_logs') . ' ORDER BY id ASC');
        $genesis = HashChain::GENESIS;
        $genesisCount = 0;
        $unexpected = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if ((string) ($row['prev_hash'] ?? '') === $genesis) {
                $genesisCount++;
                if ((int) ($row['id'] ?? 0) !== 1 && $genesisCount > 1) {
                    $unexpected[] = (int) $row['id'];
                }
            }
        }
        $this->assertSame(1, $genesisCount, 'only the first audit row may have GENESIS prev_hash; unexpected genesis at IDs: ' . implode(',', $unexpected));
        $this->assertSame([], $unexpected);
    }

    private function freshMysqli(): \mysqli
    {
        global $wpdb;
        $host = defined('DB_HOST') ? (string) DB_HOST : '127.0.0.1';
        // WP_TESTS config may include port: "127.0.0.1:3307" for MariaDB.
        $port = 3306;
        if (str_contains($host, ':')) {
            [$host, $portStr] = explode(':', $host, 2);
            $port = (int) $portStr;
        }
        $user = defined('DB_USER') ? (string) DB_USER : 'root';
        $pass = defined('DB_PASSWORD') ? (string) DB_PASSWORD : 'root';
        $name = defined('DB_NAME') ? (string) DB_NAME : ($wpdb->dbname ?? 'wordpress_test');
        $mysqli = new \mysqli($host, $user, $pass, $name, $port);
        if ($mysqli->connect_error) {
            throw new \RuntimeException('mysqli connect failed: ' . $mysqli->connect_error);
        }
        return $mysqli;
    }

    private function table(string $short): string
    {
        global $wpdb;
        $prefix = $wpdb->prefix ?? 'wp_';
        $short = preg_replace('/^cpms_/', '', $short) ?? $short;
        return $prefix . 'cpms_' . $short;
    }
}
