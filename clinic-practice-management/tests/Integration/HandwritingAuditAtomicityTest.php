<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Handwriting\HandwritingException;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * D6 — HandwritingService mandatory-audit atomicity (real-MySQL fault
 * injection). A real MySQL 1054 injected only into cpms_audit_logs INSERTs
 * proves that document creation, page addition and page saving share ONE
 * CpmsDb boundary with their mandatory HW_* audits: exception propagation,
 * full rollback of document/page/version rows, no partially written audit,
 * restored SAVEPOINT depth and an authorized retry whose audit row is
 * attributed to the trusted Clinic (clinic_id = 1). Role-based authorization
 * is still enforced before any mutation.
 */
final class HandwritingAuditAtomicityTest extends WP_UnitTestCase
{
    private int $secretaryUserId;

    private int $doctorUserId;

    private int $clinicianId;

    private ?ClinicScope $previousScope = null;

    /** @var callable|null */
    private $auditFault = null;

    private bool $previousSuppression = false;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        $this->previousScope = ScopeContext::tryGet();
        App::replaceExplicitScope(ClinicScope::forClinic(1));

        $this->secretaryUserId = $this->makeUser('ha_secretary', 'cpms_secretary');
        cpms_test_seed_membership($this->secretaryUserId, 1, 'cpms_secretary');
        $this->doctorUserId = $this->makeUser('ha_doctor', 'cpms_doctor');
        cpms_test_seed_membership($this->doctorUserId, 1, 'cpms_doctor');

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (1, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr Hand Atomic',
                $this->doctorUserId,
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;
    }

    protected function tearDown(): void
    {
        $this->restoreAuditInserts();
        App::replaceExplicitScope($this->previousScope);
        parent::tearDown();
    }

    public function testCreateDocumentAuditFailureRollsBackDocumentAndDefaultPage(): void
    {
        $visit_id = $this->makeConsultation();

        $depth_before = $this->savepointDepth();
        $this->failAuditInserts();
        $threw = false;
        try {
            App::handwritingService()->createDocument($this->doctorUserId, $visit_id, 'سند آزمون', []);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->restoreAuditInserts();

        self::assertTrue($threw, 'a failed mandatory HW_DOC_CREATE audit must propagate, never report false success');
        self::assertSame(0, $this->tableCount('cpms_handwriting_documents'), 'the document row must roll back with its audit');
        self::assertSame(0, $this->tableCount('cpms_handwriting_pages'), 'the default page row must roll back too');
        self::assertSame(0, $this->auditCount('HW_DOC_CREATE'), 'no audit evidence may survive the failed boundary');
        self::assertSame($depth_before, $this->savepointDepth(), 'SAVEPOINT depth must be restored after rollback');

        $doc = App::handwritingService()->createDocument($this->doctorUserId, $visit_id, 'سند آزمون', []);
        self::assertGreaterThan(0, (int) $doc['id']);
        self::assertSame(1, $this->auditCount('HW_DOC_CREATE'));
        self::assertSame(1, $this->latestAuditClinicId('HW_DOC_CREATE'), 'tenant attribution must name the trusted Clinic');
    }

    public function testAddPageAuditFailureLeavesDocumentWithOriginalPagesOnly(): void
    {
        $visit_id = $this->makeConsultation();
        $doc = App::handwritingService()->createDocument($this->doctorUserId, $visit_id, null, []);
        $pages_before = $this->tableCount('cpms_handwriting_pages');

        $depth_before = $this->savepointDepth();
        $this->failAuditInserts();
        $threw = false;
        try {
            App::handwritingService()->addPage($this->doctorUserId, (int) $doc['id'], ['background_template' => 'graph']);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->restoreAuditInserts();

        self::assertTrue($threw, 'a failed mandatory HW_PAGE_ADD audit must propagate');
        self::assertSame($pages_before, $this->tableCount('cpms_handwriting_pages'), 'no extra page may survive the rolled-back add');
        self::assertSame(0, $this->auditCount('HW_PAGE_ADD'));
        self::assertSame($depth_before, $this->savepointDepth(), 'SAVEPOINT depth must be restored after rollback');

        $page = App::handwritingService()->addPage($this->doctorUserId, (int) $doc['id'], ['background_template' => 'graph']);
        self::assertSame(1, (int) $page['page_index']);
        self::assertSame(1, $this->auditCount('HW_PAGE_ADD'));
        self::assertSame(1, $this->latestAuditClinicId('HW_PAGE_ADD'));
    }

    public function testSavePageAuditFailureRollsBackVersionAndRevisionBump(): void
    {
        $visit_id = $this->makeConsultation();
        $doc = App::handwritingService()->createDocument($this->doctorUserId, $visit_id, null, []);
        $page_id = (int) $doc['pages'][0]['id'];
        $versions_before = $this->tableCount('cpms_handwriting_page_versions');

        $depth_before = $this->savepointDepth();
        $this->failAuditInserts();
        $threw = false;
        try {
            App::handwritingService()->savePage($this->doctorUserId, $page_id, [
                'client_revision' => 1,
                'stroke_data' => $this->strokeData(),
                'width' => 1240,
                'height' => 1754,
                'saved_by' => 'manual',
            ], $this->uuid());
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->restoreAuditInserts();

        self::assertTrue($threw, 'a failed mandatory HW_PAGE_SAVE audit must propagate');
        self::assertSame($versions_before, $this->tableCount('cpms_handwriting_page_versions'), 'no append-only version may survive the rollback');
        self::assertSame(0, $this->auditCount('HW_PAGE_SAVE'));
        self::assertSame($depth_before, $this->savepointDepth(), 'SAVEPOINT depth must be restored after rollback');

        $result = App::handwritingService()->savePage($this->doctorUserId, $page_id, [
            'client_revision' => 1,
            'stroke_data' => $this->strokeData(),
            'width' => 1240,
            'height' => 1754,
            'saved_by' => 'manual',
        ], $this->uuid());
        self::assertSame(200, $result['status']);
        self::assertSame(1, $this->auditCount('HW_PAGE_SAVE'));
        self::assertSame(1, $this->latestAuditClinicId('HW_PAGE_SAVE'));
    }

    public function testSecretaryCannotCreateDocumentAndNothingIsWritten(): void
    {
        $visit_id = $this->makeConsultation();

        try {
            App::handwritingService()->createDocument($this->secretaryUserId, $visit_id, null, []);
            self::fail('a secretary must not create handwriting documents');
        } catch (HandwritingException $e) {
            self::assertSame('CLINIC_PERMISSION_DENIED', $e->errorCode);
            self::assertSame(403, $e->httpStatus);
        }

        self::assertSame(0, $this->tableCount('cpms_handwriting_documents'), 'a denied request must never write a document');
        self::assertSame(0, $this->auditCount('HW_DOC_CREATE'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeConsultation(): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $seq = random_int(1000, 999999);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, %s, %s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-HA-' . $seq,
                'Hand',
                'P' . $seq,
                '0915' . sprintf('%07d', $seq),
                $now,
                $now
            )
        );
        $patient_id = (int) $wpdb->insert_id;

        $visit = App::visitService()->walkIn($this->secretaryUserId, $patient_id, $this->clinicianId);
        $id = (int) $visit['id'];
        App::visitService()->transition($this->doctorUserId, $id, 'call');
        App::visitService()->transition($this->doctorUserId, $id, 'start');

        return $id;
    }

    private function makeUser(string $login, string $role): int
    {
        $user_id = (int) wp_create_user($login . bin2hex(random_bytes(3)), 'pass-12345', $login . '@test.local');
        $user = get_userdata($user_id);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $user_id;
    }

    private function strokeData(): string
    {
        $stroke = [
            'id' => 's1',
            'tool' => 'pen',
            'color' => '#1a1a2e',
            'size' => 4,
            'points' => [[10, 20, 0.5, 1690000000], [40, 60, 0.8, 1690000050]],
        ];

        return base64_encode(gzencode(json_encode([$stroke], JSON_UNESCAPED_UNICODE) ?: '[]') ?: '[]');
    }

    private function uuid(): string
    {
        $d = static fn (int $len): string => bin2hex(random_bytes((int) ceil($len / 2)));

        return sprintf('%s-%s-4%s-%s-%s', $d(8), $d(4), substr($d(3), 0, 3), substr($d(4), 0, 4), $d(12));
    }

    private function tableCount(string $table): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table($table));
    }

    private function auditCount(string $action): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s',
            [$action]
        );
    }

    private function latestAuditClinicId(string $action): int
    {
        return (int) App::db()->fetchValue(
            'SELECT clinic_id FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s ORDER BY id DESC LIMIT 1',
            [$action]
        );
    }

    private function savepointDepth(): int
    {
        return is_array($GLOBALS['__cpms_sp_stack'] ?? null) ? count($GLOBALS['__cpms_sp_stack']) : 0;
    }

    private function failAuditInserts(): void
    {
        global $wpdb;

        self::assertNull($this->auditFault, 'the audit fault must not be installed twice');

        $fault = static function ($query) {
            $sql = (string) $query;
            if (preg_match('/\A\s*INSERT\s+INTO\s+(\S+)/i', $sql, $m) === 1
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
}
