<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Clinical\ClinicalException;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * D6 — MedicalFileService mandatory-audit atomicity (real-MySQL fault
 * injection). A real MySQL 1054 injected only into cpms_audit_logs INSERTs
 * proves that attachment metadata writes (upload / soft delete) share ONE
 * CpmsDb boundary with their mandatory FILE_* audits: exception propagation,
 * full metadata rollback, no partially written audit, restored SAVEPOINT
 * depth and an authorized retry whose audit row is attributed to the trusted
 * Clinic (clinic_id = 1). Cross-patient authorization stays enforced before
 * any protected read.
 *
 * Note (documented boundary in MedicalFileService::store): the physical object
 * is written to LocalFileStorage BEFORE the database boundary, so a rolled-back
 * metadata row can leave an unreferenced storage object — the same orphan class
 * as any disk-write-then-DB-failure path. The security invariant guarded here is
 * that no metadata row can exist without its audit; physical garbage collection
 * is a separate operational concern.
 */
final class MedicalFileAuditAtomicityTest extends WP_UnitTestCase
{
    private string $storagePath;

    private int $secretaryUserId;

    private int $doctorUserId;

    private int $patientAId;

    private int $patientAUser;

    private int $patientBId;

    private int $patientBUser;

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

        $this->storagePath = sys_get_temp_dir() . '/cpms-files-atomic-' . bin2hex(random_bytes(4));
        App::settings()->set('files.storage_path', $this->storagePath);
        App::settings()->set('files.max_upload_bytes', 10485760);

        global $wpdb;
        $now = App::db()->nowUtcSql();
        foreach ([['MR-MA-0001', '09123330001', 'A'], ['MR-MA-0002', '09123330002', 'B']] as [$mrn, $mobile, $tag]) {
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, %s, "F", %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $mrn,
                $tag,
                $mobile,
                $now,
                $now
            ));
            $patient_id = (int) $wpdb->insert_id;
            $user_id = (int) wp_create_user('ma_patient_' . strtolower($tag) . bin2hex(random_bytes(2)), 'pass-12345', 'ma' . $tag . '@test.local', ['role' => 'cpms_patient']);
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patient_user_links
                     (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at)
                 VALUES (1, %d, %d, %s, 1, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $patient_id,
                $user_id,
                $mobile,
                $now
            ));
            if ($tag === 'A') {
                $this->patientAId = $patient_id;
                $this->patientAUser = $user_id;
            } else {
                $this->patientBId = $patient_id;
                $this->patientBUser = $user_id;
            }
        }

        $this->secretaryUserId = (int) wp_create_user('ma_secretary' . bin2hex(random_bytes(3)), 'pass-12345', 'mas@test.local');
        $secretary = get_userdata($this->secretaryUserId);
        if ($secretary !== false) {
            $secretary->set_role('cpms_secretary');
        }
        cpms_test_seed_membership($this->secretaryUserId, 1, 'cpms_secretary');

        $this->doctorUserId = (int) wp_create_user('ma_doctor' . bin2hex(random_bytes(3)), 'pass-12345', 'mad@test.local');
        $doctor = get_userdata($this->doctorUserId);
        if ($doctor !== false) {
            $doctor->set_role('cpms_doctor');
        }
        cpms_test_seed_membership($this->doctorUserId, 1, 'cpms_doctor');
    }

    protected function tearDown(): void
    {
        $this->restoreAuditInserts();
        App::resetScope();
        if (is_dir($this->storagePath)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->storagePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->storagePath);
        }
        parent::tearDown();
    }

    public function testUploadAuditFailureRollsBackAttachmentMetadata(): void
    {
        $file = $this->makeUploadedFile('atomic.pdf', $this->pdfContent());

        $depth_before = $this->savepointDepth();
        $this->failAuditInserts();
        $threw = false;
        try {
            App::medicalFileService()->upload(
                $this->secretaryUserId,
                $file,
                $this->patientAId,
                null,
                'document',
                'patient_visible'
            );
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->restoreAuditInserts();

        self::assertTrue($threw, 'a failed mandatory FILE_UPLOADED audit must propagate, never report false success');
        self::assertSame(0, $this->tableCount('cpms_medical_attachments'), 'no attachment metadata may survive the rolled-back upload');
        self::assertSame(0, $this->auditCount('FILE_UPLOADED'), 'no audit evidence may survive the failed boundary');
        self::assertSame($depth_before, $this->savepointDepth(), 'SAVEPOINT depth must be restored after rollback');

        $row = App::medicalFileService()->upload(
            $this->secretaryUserId,
            $this->makeUploadedFile('atomic-retry.pdf', $this->pdfContent()),
            $this->patientAId,
            null,
            'document',
            'patient_visible'
        );
        self::assertGreaterThan(0, (int) $row['id']);
        self::assertSame(1, $this->auditCount('FILE_UPLOADED'));
        self::assertSame(1, $this->latestAuditClinicId('FILE_UPLOADED'), 'tenant attribution must name the trusted Clinic');
    }

    public function testSoftDeleteAuditFailureKeepsAttachmentActive(): void
    {
        $row = App::medicalFileService()->upload(
            $this->secretaryUserId,
            $this->makeUploadedFile('delete-me.pdf', $this->pdfContent()),
            $this->patientAId,
            null,
            'document',
            'patient_visible'
        );
        $file_id = (int) $row['id'];

        $depth_before = $this->savepointDepth();
        $this->failAuditInserts();
        $threw = false;
        try {
            App::medicalFileService()->softDelete($this->doctorUserId, $file_id);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->restoreAuditInserts();

        self::assertTrue($threw, 'a failed mandatory FILE_SOFT_DELETED audit must propagate');
        self::assertNull($this->attachmentDeletedAt($file_id), 'the soft-delete marker must roll back');
        self::assertSame(0, $this->auditCount('FILE_SOFT_DELETED'));
        self::assertSame($depth_before, $this->savepointDepth(), 'SAVEPOINT depth must be restored after rollback');

        App::medicalFileService()->softDelete($this->doctorUserId, $file_id);
        self::assertNotNull($this->attachmentDeletedAt($file_id), 'the authorized retry must persist the soft-delete marker');
        self::assertSame(1, $this->auditCount('FILE_SOFT_DELETED'));
        self::assertSame(1, $this->latestAuditClinicId('FILE_SOFT_DELETED'));
    }

    public function testCrossPatientStreamIsDeniedBeforeAnyProtectedRead(): void
    {
        $row = App::medicalFileService()->upload(
            $this->secretaryUserId,
            $this->makeUploadedFile('idor.pdf', $this->pdfContent()),
            $this->patientBId,
            null,
            'document',
            'patient_visible'
        );

        try {
            App::medicalFileService()->stream($this->patientAUser, (int) $row['id']);
            self::fail('a patient must not stream another patient\'s file');
        } catch (ClinicalException $e) {
            self::assertSame('CLINIC_NOT_FOUND', $e->errorCode);
            self::assertSame(404, $e->httpStatus);
        }

        $denial = App::db()->fetchRow(
            'SELECT id FROM ' . App::db()->table('cpms_audit_logs') .
            ' WHERE action = %s AND resource_type = %s AND resource_id = %d',
            ['FORBIDDEN_ACCESS_ATTEMPT', 'file', (int) $row['id']]
        );
        self::assertIsArray($denial, 'the cross-patient denial must leave an audit trail');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return array{name: string, tmp_name: string, size: int, error: int, type: string}
     */
    private function makeUploadedFile(string $name, string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cpms_up_');
        self::assertIsString($tmp);
        file_put_contents($tmp, $content);

        return [
            'name' => $name,
            'tmp_name' => $tmp,
            'size' => (int) filesize($tmp),
            'error' => 0,
            'type' => 'application/octet-stream',
        ];
    }

    private function pdfContent(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n%%EOF\n";
    }

    private function attachmentDeletedAt(int $file_id): ?string
    {
        $value = App::db()->fetchValue(
            'SELECT deleted_at FROM ' . App::db()->table('cpms_medical_attachments') . ' WHERE id = %d',
            [$file_id]
        );

        return $value === null ? null : (string) $value;
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
