<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Finance\FinanceService;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * Finance service catalog — atomic mutation + mandatory audit (real-MySQL fault
 * injection).
 *
 * PROVEN DEFECT this suite pins down: createService()/updateService()/
 * deactivateService() wrote the cpms_services row first (auto-committed) and only
 * then asked AuditLogger for the mandatory audit event. Now that the audit logger
 * is fail-closed, an audit INSERT failure throws AFTER the mutation is already
 * committed: the REST endpoint answers 500 (FinanceController maps an unexpected
 * throwable to CLINIC_INTERNAL_ERROR), yet the service row/column change stays
 * persisted — a mutation without its required audit event.
 *
 * REQUIRED CONTRACT (asserted here):
 *  1. the service-catalog mutation and its audit write share one transaction;
 *  2. an audit persistence failure prevents the mutation from being committed;
 *  3. a failed create leaves no new cpms_services row;
 *  4. a failed update preserves the previous service fields;
 *  5. a failed deactivation preserves the previous active status;
 *  6. no success value is returned;
 *  7. the already-atomic invoice path stays atomic (no partial invoice rows);
 *  8. the hash chain stays correct for successful writes.
 *
 * Fault injection is a real MySQL error: the audit INSERT is rewritten (via the
 * documented wpdb 'query' filter) into one naming a column that does not exist,
 * so wpdb::insert() genuinely fails with "Unknown column". The healthy-path test
 * in this same class proves the surrounding machinery is intact.
 */
final class FinanceServiceCatalogAuditAtomicityTest extends WP_UnitTestCase
{
    private int $managerUserId;
    private int $secretaryUserId;
    private int $doctorUserId;
    private int $clinicianId;

    /** @var callable|null */
    private $auditFault = null;

    /** @var bool */
    private bool $previousSuppression = false;

    /** @var mixed */
    private $previousScope;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::settings()->set('clinical.require_chief_complaint', true);

        global $wpdb;
        $now = App::db()->nowUtcSql();

        $this->managerUserId   = $this->makeUser('fsc_manager', 'administrator');
        $this->secretaryUserId = $this->makeUser('fsc_secretary', 'cpms_secretary');
        $this->doctorUserId    = $this->makeUser('fsc_doctor', 'cpms_doctor');
        cpms_test_seed_membership($this->managerUserId, 1, 'cpms_manager');
        cpms_test_seed_membership($this->secretaryUserId, 1, 'cpms_secretary');
        cpms_test_seed_membership($this->doctorUserId, 1, 'cpms_doctor');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                 (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
             VALUES (1, %s, %d, 1, %s, %s)',
            'Dr Service Catalog',
            $this->doctorUserId,
            $now,
            $now
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $this->clinicianId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->clinicianId, 'clinician fixture must exist');

        $this->previousScope = ScopeContext::tryGet();
        App::replaceExplicitScope(ClinicScope::forClinic(1));
    }

    protected function tearDown(): void
    {
        $this->restoreAuditInserts();
        App::replaceExplicitScope($this->previousScope);
        $this->previousScope = null;
        parent::tearDown();
    }

    private function finance(): FinanceService
    {
        return App::financeService();
    }

    // ------------------------------------------------------------------
    // 1 — failed audit during CREATE.
    // ------------------------------------------------------------------

    public function testAuditFailureDuringServiceCreateLeavesNoServiceRow(): void
    {
        global $wpdb;
        $services = App::db()->table('cpms_services');
        $code     = 'fsc_create_' . bin2hex(random_bytes(3));

        $this->failAuditInserts();
        $thrown = null;
        try {
            $this->finance()->createService($this->managerUserId, [
                'code' => $code, 'name' => 'Atomic service', 'price' => 100000,
            ]);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'a failed audit persistence must not be silent — the call must fail');
        self::assertStringContainsString(
            'audit',
            strtolower($thrown->getMessage()),
            'the failure must be reported as an audit-persistence failure, not misreported'
        );
        self::assertSame(
            0,
            (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$services} WHERE clinic_id = 1 AND code = %s", $code)), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'a failed create must not commit a service row'
        );
    }

    // ------------------------------------------------------------------
    // 2 — failed audit during UPDATE.
    // ------------------------------------------------------------------

    public function testAuditFailureDuringServiceUpdatePreservesPreviousFields(): void
    {
        global $wpdb;
        $services = App::db()->table('cpms_services');
        $code     = 'fsc_update_' . bin2hex(random_bytes(3));

        $created = $this->finance()->createService($this->managerUserId, [
            'code' => $code, 'name' => 'Original name', 'price' => 100000,
        ]);
        $id = (int) $created['id'];

        $this->failAuditInserts();
        $thrown    = null;
        $afterFail = null;
        try {
            $this->finance()->updateService($this->managerUserId, $id, [
                'code' => $code, 'name' => 'Changed name', 'price' => 999000,
            ]);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        } finally {
            $afterFail = $wpdb->get_row($wpdb->prepare("SELECT name, price FROM {$services} WHERE id = %d", $id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->restoreAuditInserts();
        }

        self::assertNotNull($thrown, 'a failed audit persistence during an update must fail the call');
        self::assertStringContainsString('audit', strtolower($thrown->getMessage()));
        self::assertNotNull($afterFail);
        self::assertSame('Original name', (string) $afterFail['name'], 'the previous name must be preserved');
        self::assertSame(100000, (int) $afterFail['price'], 'the previous price must be preserved');

        // A later healthy update must still work (no poisoned transaction state and
        // no false rollback guarantee).
        $this->finance()->updateService($this->managerUserId, $id, [
            'code' => $code, 'name' => 'Changed name', 'price' => 999000,
        ]);
        $afterOk = $wpdb->get_row($wpdb->prepare("SELECT name, price FROM {$services} WHERE id = %d", $id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertSame('Changed name', (string) $afterOk['name']);
        self::assertSame(999000, (int) $afterOk['price']);
    }

    // ------------------------------------------------------------------
    // 3 — failed audit during DEACTIVATION.
    // ------------------------------------------------------------------

    public function testAuditFailureDuringServiceDeactivationPreservesActiveStatus(): void
    {
        global $wpdb;
        $services = App::db()->table('cpms_services');
        $code     = 'fsc_deact_' . bin2hex(random_bytes(3));

        $created = $this->finance()->createService($this->managerUserId, [
            'code' => $code, 'name' => 'Deactivation candidate', 'price' => 50000,
        ]);
        $id = (int) $created['id'];

        $this->failAuditInserts();
        $thrown = null;
        try {
            $this->finance()->deactivateService($this->managerUserId, $id);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        } finally {
            $this->restoreAuditInserts();
        }

        self::assertNotNull($thrown, 'a failed audit persistence during deactivation must fail the call');
        self::assertStringContainsString('audit', strtolower($thrown->getMessage()));
        self::assertSame(
            1,
            (int) $wpdb->get_var($wpdb->prepare("SELECT is_active FROM {$services} WHERE id = %d", $id)), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'a failed deactivation must leave the service active'
        );

        // The healthy deactivation still applies afterwards.
        $this->finance()->deactivateService($this->managerUserId, $id);
        self::assertSame(
            0,
            (int) $wpdb->get_var($wpdb->prepare("SELECT is_active FROM {$services} WHERE id = %d", $id)), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'the healthy deactivation must still be applied'
        );
    }

    // ------------------------------------------------------------------
    // 4 — success path: one audit event per mutation, chain valid.
    // ------------------------------------------------------------------

    public function testSuccessfulServiceMutationsPersistAuditEventsAndKeepTheChainValid(): void
    {
        global $wpdb;
        $logs = App::db()->table('cpms_audit_logs');
        $code = 'fsc_ok_' . bin2hex(random_bytes(3));

        $created = $this->finance()->createService($this->managerUserId, [
            'code' => $code, 'name' => 'Healthy service', 'price' => 100000,
        ]);
        $id = (int) $created['id'];
        $this->finance()->updateService($this->managerUserId, $id, [
            'code' => $code, 'name' => 'Healthy service v2', 'price' => 120000,
        ]);
        $this->finance()->deactivateService($this->managerUserId, $id);

        $ops = array_map(
            'strval',
            (array) $wpdb->get_col($wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                "SELECT JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.op')) FROM {$logs} WHERE resource_type = 'service' AND resource_id = %d ORDER BY id ASC",
                $id
            ))
        );
        self::assertSame(
            ['service_create', 'service_update', 'service_deactivate'],
            $ops,
            'each successful service-catalog mutation must persist exactly one audit event, in order'
        );

        $chain = App::audit()->verifyChain(500);
        self::assertTrue($chain['ok'], 'the audit hash chain must remain valid');
        self::assertGreaterThanOrEqual(3, $chain['checked']);
    }

    // ------------------------------------------------------------------
    // 5 — the already-atomic invoice path is unaffected by the audit fault.
    // ------------------------------------------------------------------

    public function testInvoiceIssuanceUnderAuditFaultRollsBackCompletely(): void
    {
        global $wpdb;
        $invoices = App::db()->table('cpms_invoices');
        $items    = App::db()->table('cpms_invoice_items');

        $visitId = $this->makeCompletedVisit();

        $this->failAuditInserts();
        $thrown = null;
        try {
            $this->finance()->issueInvoice($this->secretaryUserId, [
                'visit_id' => $visitId,
                'items'    => [['description' => 'ویزیت', 'unit_price' => 100000]],
            ]);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        } finally {
            $this->restoreAuditInserts();
        }

        self::assertNotNull($thrown, 'an audit failure inside the invoice transaction must fail the request');
        self::assertStringContainsString('audit', strtolower($thrown->getMessage()));
        self::assertSame(
            0,
            (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$invoices} WHERE clinic_id = 1 AND visit_id = %d", $visitId)), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'no invoice row may survive a failed audit persistence'
        );
        // No placeholder in this query, so wpdb::prepare() must not be used
        // (WP 6.7 raises a _doing_it_wrong notice for placeholder-less prepares).
        $orphan_items = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$items} i LEFT JOIN {$invoices} v ON v.id = i.invoice_id WHERE v.id IS NULL" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );
        self::assertSame(0, (int) $orphan_items, 'no orphaned invoice item rows may survive');

        // The same invoice still issues cleanly once the audit path is healthy.
        $issued = $this->finance()->issueInvoice($this->secretaryUserId, [
            'visit_id' => $visitId,
            'items'    => [['description' => 'ویزیت', 'unit_price' => 100000]],
        ]);
        self::assertGreaterThan(0, (int) $issued['id']);
    }

    // ================= fault injection =================

    /**
     * Rewrites every INSERT into the audit table into a statement naming a column
     * that does not exist (real MySQL 1054), so wpdb::insert() genuinely fails.
     * Error output is suppressed; the ONLY observable signal is the product's own
     * behaviour (wpdb still records last_error).
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

    // ================= fixtures =================

    /** Visit driven to consultation_completed on a fresh patient. */
    private function makeCompletedVisit(): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $seq = random_int(100000, 999999);
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                 (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
             VALUES (1, %s, %s, %s, %s, "active", %s, %s)',
            'MR-FSC-' . $seq,
            'ServiceCatalog',
            'P' . $seq,
            '0912' . sprintf('%07d', $seq),
            $now,
            $now
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $patientId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $patientId);

        $visit   = App::visitService()->walkIn($this->secretaryUserId, $patientId, $this->clinicianId);
        $visitId = (int) $visit['id'];
        App::visitService()->transition($this->doctorUserId, $visitId, 'call');
        App::visitService()->transition($this->doctorUserId, $visitId, 'start');
        App::clinicalService()->addNote($this->doctorUserId, $visitId, [
            'category'      => 'chief_complaint',
            'visibility'    => 'patient_visible',
            'content_text'  => 'درد و تهوع',
        ]);
        App::clinicalService()->completeConsultation($this->doctorUserId, $visitId);

        return $visitId;
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login . bin2hex(random_bytes(3)), 'pass-12345', $login . '@fsc.test');
        $user   = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }
}
