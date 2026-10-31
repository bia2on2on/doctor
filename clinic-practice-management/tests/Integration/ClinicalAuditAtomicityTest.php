<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Clinical\ClinicalException;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * D6 — ClinicalService mandatory-audit atomicity (real-MySQL fault injection).
 *
 * A real MySQL 1054 injected only into cpms_audit_logs INSERTs proves for each
 * clinical mutation (note create, prescription create/finalize, consultation
 * complete) that the business rows and their mandatory audit share ONE
 * CpmsDb boundary: exception propagation, full rollback, no partially written
 * audit, restored SAVEPOINT depth, and a successful authorized retry whose
 * audit row carries the trusted Clinic id (clinic_id = 1). Role-based
 * authorization is still enforced before any mutation.
 */
final class ClinicalAuditAtomicityTest extends WP_UnitTestCase
{
    private int $secretaryUserId;

    private int $doctorUserId;

    private int $clinicianId;

    private int $patientId;

    /** @var callable|null */
    private $auditFault = null;

    private bool $previousSuppression = false;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        App::replaceExplicitScope(ClinicScope::forClinic(1));
        App::settings()->set('queue.auto_enqueue', true);
        App::settings()->set('clinical.require_chief_complaint', true);

        $this->secretaryUserId = $this->makeUser('ca_secretary', 'cpms_secretary');
        cpms_test_seed_membership($this->secretaryUserId, 1, 'cpms_secretary');
        $this->doctorUserId = $this->makeUser('ca_doctor', 'cpms_doctor');
        cpms_test_seed_membership($this->doctorUserId, 1, 'cpms_doctor');

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (1, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr Atomic Clinical',
                $this->doctorUserId,
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, "Atom", "C", %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-CA-0001',
                '09122220001',
                $now,
                $now
            )
        );
        $this->patientId = (int) $wpdb->insert_id;
    }

    protected function tearDown(): void
    {
        $this->restoreAuditInserts();
        App::resetScope();
        parent::tearDown();
    }

    public function testAddNoteAuditFailureRollsBackNoteAndVersionRows(): void
    {
        $visit_id = $this->makeConsultation();

        $depth_before = $this->savepointDepth();
        $this->failAuditInserts();
        $threw = false;
        try {
            App::clinicalService()->addNote($this->doctorUserId, $visit_id, [
                'category' => 'clinical_note',
                'visibility' => 'patient_visible',
                'content_text' => 'یادداشت در معرض خطای Audit',
            ]);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->restoreAuditInserts();

        self::assertTrue($threw, 'a failed mandatory NOTE_CREATED audit must propagate, never report false success');
        self::assertSame(0, $this->tableCount('cpms_clinical_notes'), 'the note row must roll back with its audit');
        self::assertSame(0, $this->tableCount('cpms_clinical_note_versions'), 'the append-only version row must roll back too');
        self::assertSame(0, $this->auditCount('NOTE_CREATED'), 'no audit evidence may survive the failed boundary');
        self::assertSame($depth_before, $this->savepointDepth(), 'SAVEPOINT depth must be restored after rollback');

        $note = App::clinicalService()->addNote($this->doctorUserId, $visit_id, [
            'category' => 'clinical_note',
            'visibility' => 'patient_visible',
            'content_text' => 'یادداشت پس از رفع خطا',
        ]);
        self::assertSame(1, (int) $note['version']);
        self::assertSame(1, $this->auditCount('NOTE_CREATED'));
        self::assertSame(1, $this->latestAuditClinicId('NOTE_CREATED'), 'tenant attribution must name the trusted Clinic');
    }

    public function testNoteInsertFailureNeverCreatesFalseSuccessOrAudit(): void
    {
        $visit_id = $this->makeConsultation();
        $depth_before = $this->savepointDepth();
        $audits_before = $this->auditCount('NOTE_CREATED');
        $intercepted = false;
        $fault = static function (string $query) use (&$intercepted): string {
            if (preg_match('/\A\s*INSERT\s+INTO\s+(\S+)/i', $query, $match) === 1
                && str_contains($match[1], 'cpms_clinical_notes')) {
                $intercepted = true;
                return 'INSERT INTO ' . $match[1] . ' (`cpms_fault_injection_no_such_column`) VALUES (1)';
            }
            return $query;
        };
        global $wpdb;
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $fault, 5);
        try {
            App::clinicalService()->addNote($this->doctorUserId, $visit_id, [
                'category' => 'clinical_note', 'visibility' => 'patient_visible', 'content_text' => 'آزمایش',
            ]);
            self::fail('an unsuccessful note INSERT must not report success');
        } catch (\RuntimeException $error) {
            self::assertSame('clinical note insert failed', $error->getMessage());
        } finally {
            remove_filter('query', $fault, 5);
            $wpdb->suppress_errors($previous);
        }
        self::assertTrue($intercepted);
        self::assertSame(0, $this->tableCount('cpms_clinical_notes'));
        self::assertSame($audits_before, $this->auditCount('NOTE_CREATED'));
        self::assertSame($depth_before, $this->savepointDepth());
    }

    public function testCreatePrescriptionAuditFailureRollsBackPrescriptionAndItems(): void
    {
        $visit_id = $this->makeConsultation();

        $depth_before = $this->savepointDepth();
        $this->failAuditInserts();
        $threw = false;
        try {
            App::clinicalService()->createPrescription($this->doctorUserId, $visit_id, [
                'items' => [['generic_name' => 'استامینوفن', 'dose' => '1 قرص', 'frequency' => 'هر 8 ساعت']],
            ]);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->restoreAuditInserts();

        self::assertTrue($threw, 'a failed mandatory PRESCRIPTION_CREATED audit must propagate');
        self::assertSame(0, $this->tableCount('cpms_prescriptions'), 'the prescription row must roll back with its audit');
        self::assertSame(0, $this->tableCount('cpms_prescription_items'), 'prescription items must roll back too');
        self::assertSame(0, $this->auditCount('PRESCRIPTION_CREATED'));
        self::assertSame($depth_before, $this->savepointDepth(), 'SAVEPOINT depth must be restored after rollback');

        $rx = App::clinicalService()->createPrescription($this->doctorUserId, $visit_id, [
            'items' => [['generic_name' => 'استامینوفن', 'dose' => '1 قرص', 'frequency' => 'هر 8 ساعت']],
        ]);
        self::assertSame('draft', $rx['status']);
        self::assertSame(1, $this->auditCount('PRESCRIPTION_CREATED'));
        self::assertSame(1, $this->latestAuditClinicId('PRESCRIPTION_CREATED'));
    }

    public function testFinalizePrescriptionAuditFailureKeepsDraftStatus(): void
    {
        $visit_id = $this->makeConsultation();
        $draft = App::clinicalService()->createPrescription($this->doctorUserId, $visit_id, [
            'items' => [['generic_name' => 'دیگوکسین', 'dose' => '0.25mg', 'frequency' => 'روزانه یک بار']],
        ]);

        $depth_before = $this->savepointDepth();
        $this->failAuditInserts();
        $threw = false;
        try {
            App::clinicalService()->finalizePrescription($this->doctorUserId, (int) $draft['id']);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->restoreAuditInserts();

        self::assertTrue($threw, 'a failed mandatory PRESCRIPTION_FINALIZED audit must propagate');
        self::assertSame('draft', $this->prescriptionStatus((int) $draft['id']), 'the draft status must survive the rolled-back finalize');
        self::assertSame(0, $this->auditCount('PRESCRIPTION_FINALIZED'));
        self::assertSame($depth_before, $this->savepointDepth(), 'SAVEPOINT depth must be restored after rollback');

        $final = App::clinicalService()->finalizePrescription($this->doctorUserId, (int) $draft['id']);
        self::assertSame('finalized', $final['status']);
        self::assertSame(1, $this->auditCount('PRESCRIPTION_FINALIZED'));
        self::assertSame(1, $this->latestAuditClinicId('PRESCRIPTION_FINALIZED'));
    }

    public function testCompleteConsultationAuditFailureRevertsVisitTransition(): void
    {
        $visit_id = $this->makeConsultation();
        App::clinicalService()->addNote($this->doctorUserId, $visit_id, [
            'category' => 'chief_complaint',
            'visibility' => 'patient_visible',
            'content_text' => 'سردرد',
        ]);
        $history_before = $this->tableCount('cpms_visit_status_history');

        $depth_before = $this->savepointDepth();
        $this->failAuditInserts();
        $threw = false;
        try {
            App::clinicalService()->completeConsultation($this->doctorUserId, $visit_id);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->restoreAuditInserts();

        self::assertTrue($threw, 'a failed mandatory CONSULTATION_COMPLETED audit must propagate');
        self::assertSame('in_consultation', $this->visitStatus($visit_id), 'the visit transition must roll back');
        self::assertSame($history_before, $this->tableCount('cpms_visit_status_history'), 'no status-history row may survive the rollback');
        self::assertSame(0, $this->auditCount('CONSULTATION_COMPLETED'));
        self::assertSame($depth_before, $this->savepointDepth(), 'SAVEPOINT depth must be restored after rollback');

        $completed = App::clinicalService()->completeConsultation($this->doctorUserId, $visit_id);
        self::assertSame('consultation_completed', (string) ($completed['status'] ?? ''));
        self::assertSame(1, $this->auditCount('CONSULTATION_COMPLETED'));
        self::assertSame(1, $this->latestAuditClinicId('CONSULTATION_COMPLETED'));
    }

    public function testSecretaryCannotAuthorDoctorNoteAndNothingIsWritten(): void
    {
        $visit_id = $this->makeConsultation();

        try {
            App::clinicalService()->addNote($this->secretaryUserId, $visit_id, [
                'category' => 'clinical_note',
                'visibility' => 'patient_visible',
                'content_text' => 'تلاش غیرمجاز',
            ]);
            self::fail('a secretary must not be able to author a clinical note');
        } catch (ClinicalException $e) {
            self::assertSame('CLINIC_PERMISSION_DENIED', $e->errorCode);
            self::assertSame(403, $e->httpStatus);
        }

        self::assertSame(0, $this->tableCount('cpms_clinical_notes'), 'a denied request must never write a note row');
        self::assertSame(0, $this->auditCount('NOTE_CREATED'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeConsultation(): int
    {
        $visit = App::visitService()->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId);
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

    private function tableCount(string $table): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table($table));
    }

    private function prescriptionStatus(int $prescription_id): string
    {
        return (string) App::db()->fetchValue(
            'SELECT status FROM ' . App::db()->table('cpms_prescriptions') . ' WHERE id = %d',
            [$prescription_id]
        );
    }

    private function visitStatus(int $visit_id): string
    {
        return (string) App::db()->fetchValue(
            'SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d',
            [$visit_id]
        );
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
