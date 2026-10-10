<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\CustomRoleRepository;
use WP_UnitTestCase;

/**
 * Transaction-control failure handling of the SHARED database helper
 * (CpmsDb::transactional) — security-acceptance BLOCKER 1.
 *
 * Contract under test:
 *  A. A failed transaction BEGIN prevents callback execution, writes nothing
 *     and reports the failure (never "atomically rolled back" — nothing was
 *     started).
 *  B. A failed COMMIT/RELEASE must never return the callback result as
 *     success. In production the outcome of a failed commit is genuinely
 *     uncertain, so the helper reports failure WITHOUT promising rollback;
 *     the cleanup rollback it attempts is what undoes the writes in this
 *     harness (SAVEPOINT world), and that is asserted as harness behaviour,
 *     not as a durability guarantee.
 *  C. A failing callback triggers a rollback; when the rollback succeeds the
 *     persisted state is unchanged and the ORIGINAL exception propagates
 *     unchanged (same instance).
 *  D. A failed ROLLBACK is never silently swallowed: the original error stays
 *     available, the rollback error is observable, and no false atomicity is
 *     claimed (the write is still visible because the rollback really failed).
 *  E. The existing test SAVEPOINT mechanism stays compatible: successful,
 *     nested and failing transactions behave as before.
 *  F. Custom-role define()/replace_capabilities() can never report success
 *     when transaction control fails; a failed replacement preserves the
 *     previous capability set AND version.
 *
 * Fault injection: the smallest existing deterministic SQL interception
 * mechanism — a WordPress `query` filter. It is registered at priority 5 so
 * that it observes the RAW cpms transaction markers BEFORE the test bootstrap
 * rewriter (priority 10/20) turns them into SAVEPOINT statements, and it
 * rewrites exactly one armed marker into a real transaction-control statement
 * that MySQL rejects (`ROLLBACK TO SAVEPOINT` of a savepoint that does not
 * exist, error 1305). The failure therefore comes from the real server, not
 * from a mocked return value; nothing is stubbed and no persisted-state
 * assertion is derived from a mock.
 */
final class TransactionControlTest extends WP_UnitTestCase
{
    private CpmsDb $db;

    private int $clinicId = 0;

    private string $defs = '';

    /** Armed cpms marker verb, or null when no interception is active. */
    private ?string $armedVerb = null;

    /** How many statements the armed interception actually rewrote. */
    private int $armedHits = 0;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();

        $this->db   = App::db();
        $this->defs = $this->db->table('cpms_custom_role_defs');

        global $wpdb;
        $now   = $this->db->nowUtcSql();
        $orgId = (int) $wpdb->get_var('SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics LIMIT 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertGreaterThan(0, $orgId, 'seeded organization must exist');

        $slug = 'ctxn-' . bin2hex(random_bytes(4));
        $ok   = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $orgId,
                'TxnControl Clinic ' . $slug,
                $slug,
                'Asia/Tehran',
                $now,
                $now
            )
        );
        self::assertNotFalse($ok, 'clinic fixture must be created');
        $this->clinicId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->clinicId);
    }

    protected function tearDown(): void
    {
        $this->disarmTxControlFailure();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Deterministic interception of exactly one cpms transaction marker.
    // ------------------------------------------------------------------

    /**
     * @param 'START TRANSACTION'|'COMMIT'|'ROLLBACK' $verb
     */
    private function armTxControlFailure(string $verb): void
    {
        $this->armedVerb = $verb;
        $this->armedHits = 0;
        // Priority 5: before the bootstrap SAVEPOINT rewriter, so the raw
        // marker is replaced and the bootstrap stack stays balanced.
        add_filter('query', [$this, 'failTxControlQuery'], 5);
    }

    private function disarmTxControlFailure(): void
    {
        if ($this->armedVerb !== null) {
            remove_filter('query', [$this, 'failTxControlQuery'], 5);
            $this->armedVerb = null;
        }
    }

    /**
     * @param mixed $query
     * @return mixed
     */
    public function failTxControlQuery($query)
    {
        if ($this->armedVerb === null || !is_string($query)) {
            return $query;
        }
        if (trim($query) !== '/*cpms*/ ' . $this->armedVerb) {
            return $query;
        }

        ++$this->armedHits;

        // A real MySQL transaction-control error (1305: savepoint does not
        // exist) — produced by the server, not by a stubbed return value.
        return 'ROLLBACK TO SAVEPOINT cpms_probe_savepoint_that_never_existed';
    }

    /**
     * Persisted proof: how many definition rows exist for one probe key.
     */
    private function countDefs(string $roleKey): int
    {
        global $wpdb;

        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->prepare("SELECT COUNT(*) FROM {$this->defs} WHERE clinic_id = %d AND role_key = %s", $this->clinicId, $roleKey) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
    }

    /**
     * Insert one definition row through the real write path (no policy layer).
     */
    private function writeDef(string $roleKey): void
    {
        global $wpdb;
        $now = $this->db->nowUtcSql();
        $ok  = $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->prepare(
                "INSERT INTO {$this->defs} (clinic_id, role_key, status, version, created_at, updated_at) VALUES (%d, %s, 'active', 1, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                $roleKey,
                $now,
                $now
            )
        );
        self::assertNotFalse($ok, 'definition write must succeed: ' . $wpdb->last_error);
    }

    // ------------------------------------------------------------------
    // A — failed BEGIN.
    // ------------------------------------------------------------------

    public function testFailedTransactionBeginPreventsCallbackAndReportsFailure(): void
    {
        $this->armTxControlFailure('START TRANSACTION');

        $executed = false;
        $thrown   = false;
        $message  = '';
        $returned = null;

        try {
            $returned = $this->db->transactional(function () use (&$executed): string {
                $executed = true;
                $this->writeDef('cpms_txn_begin_probe');

                return 'must-not-be-reported-as-success';
            });
        } catch (\Throwable $e) {
            $thrown  = true;
            $message = $e->getMessage();
        } finally {
            $hits = $this->armedHits;
            $this->disarmTxControlFailure();
        }

        self::assertSame(1, $hits, 'the armed BEGIN statement must have been issued exactly once');
        self::assertTrue($thrown, 'a failed transaction begin must report the failure, not continue');
        self::assertFalse($executed, 'the callback must NOT execute when the transaction could not be started');
        self::assertNull($returned, 'no callback result may be returned when the begin failed');
        self::assertStringContainsString('transaction', strtolower($message), 'the failure must name the transaction control that failed');
        self::assertSame(0, $this->countDefs('cpms_txn_begin_probe'), 'a failed begin must write nothing');
    }

    // ------------------------------------------------------------------
    // B — failed COMMIT/RELEASE.
    // ------------------------------------------------------------------

    public function testFailedCommitNeverReturnsSuccessAndReportsUncertainOutcome(): void
    {
        $this->armTxControlFailure('COMMIT');

        $executed = false;
        $thrown   = false;
        $message  = '';
        $returned = 'not-called';

        try {
            $returned = $this->db->transactional(function () use (&$executed): string {
                $executed = true;
                $this->writeDef('cpms_txn_commit_probe');

                return 'committed-success';
            });
        } catch (\Throwable $e) {
            $thrown  = true;
            $message = $e->getMessage();
        } finally {
            $hits = $this->armedHits;
            $this->disarmTxControlFailure();
        }

        self::assertSame(1, $hits, 'the armed COMMIT statement must have been issued exactly once');
        self::assertTrue($executed, 'the callback runs before the commit is attempted');
        self::assertTrue($thrown, 'a failed commit must never return the callback result as success');
        self::assertSame('not-called', $returned, 'no success value may escape a failed commit');
        self::assertStringContainsString('commit', strtolower($message), 'the failure must name the commit');

        // Honest scope: in PRODUCTION a failed commit leaves an uncertain
        // outcome and the helper only guarantees that failure is reported.
        // In THIS harness the cleanup rollback (ROLLBACK TO SAVEPOINT) really
        // succeeds, so the write is undone — asserted as harness behaviour.
        self::assertSame(0, $this->countDefs('cpms_txn_commit_probe'), 'the cleanup rollback after the failed commit must undo the write in this harness');
    }

    // ------------------------------------------------------------------
    // C — failing callback, successful rollback.
    // ------------------------------------------------------------------

    public function testCallbackFailureRollsBackAndPreservesTheOriginalException(): void
    {
        $sentinel = new \LogicException('callback failure sentinel');
        $thrown   = false;
        $caught   = null;

        try {
            $this->db->transactional(function () use ($sentinel): void {
                $this->writeDef('cpms_txn_callback_probe');
                self::assertSame(1, $this->countDefs('cpms_txn_callback_probe'), 'the write must be visible inside the open transaction');
                throw $sentinel;
            });
        } catch (\Throwable $e) {
            $thrown = true;
            $caught = $e;
        }

        self::assertTrue($thrown, 'the callback failure must propagate');
        self::assertSame($sentinel, $caught, 'the ORIGINAL exception instance must propagate unchanged when the rollback succeeds');
        self::assertSame(0, $this->countDefs('cpms_txn_callback_probe'), 'a successful rollback must leave the persisted state unchanged');
    }

    // ------------------------------------------------------------------
    // D — failed ROLLBACK.
    // ------------------------------------------------------------------

    public function testFailedRollbackIsObservableAndPreservesTheOriginalError(): void
    {
        $this->armTxControlFailure('ROLLBACK');

        $sentinel = new \LogicException('original error must survive a failed cleanup');
        $thrown   = false;
        $caught   = null;

        try {
            $this->db->transactional(function () use ($sentinel): void {
                $this->writeDef('cpms_txn_rollback_probe');
                throw $sentinel;
            });
        } catch (\Throwable $e) {
            $thrown = true;
            $caught = $e;
        } finally {
            $hits = $this->armedHits;
            $this->disarmTxControlFailure();

            // The intercepted ROLLBACK never reached the bootstrap rewriter,
            // so its savepoint name is still on the harness stack: pop it to
            // keep the stack balanced for the rest of the suite.
            if ($hits > 0 && isset($GLOBALS['__cpms_sp_stack']) && is_array($GLOBALS['__cpms_sp_stack']) && $GLOBALS['__cpms_sp_stack'] !== []) {
                array_pop($GLOBALS['__cpms_sp_stack']);
            }
        }

        self::assertSame(1, $hits, 'the armed ROLLBACK statement must have been issued exactly once');
        self::assertTrue($thrown, 'a failed rollback must never be silently swallowed');
        self::assertNotSame($sentinel, $caught, 'the cleanup failure must be reported as its own observable error');
        self::assertSame($sentinel, $caught instanceof \Throwable ? $caught->getPrevious() : null, 'the ORIGINAL error must remain available (previous)');
        self::assertStringContainsString('rollback', strtolower((string) ($caught instanceof \Throwable ? $caught->getMessage() : '')), 'the reported error must name the failed rollback');

        // No false atomicity claim: the rollback really failed, so the write is
        // still visible in this session (it disappears with the WP test-suite
        // transaction at tear_down).
        self::assertSame(1, $this->countDefs('cpms_txn_rollback_probe'), 'a failed rollback must NOT be reported as an atomic undo');
    }

    // ------------------------------------------------------------------
    // E — SAVEPOINT mechanism compatibility (unchanged behaviour).
    // ------------------------------------------------------------------

    public function testSavepointSemanticsOfSuccessfulNestedAndFailingTransactionsArePreserved(): void
    {
        // Successful transaction: writes survive the commit/release.
        $this->db->transactional(function (): void {
            $this->writeDef('cpms_txn_ok_outer');
        });
        self::assertSame(1, $this->countDefs('cpms_txn_ok_outer'), 'a committed transaction must keep its writes');

        // Nested transaction: an inner failure must undo only the inner writes.
        $innerMessage = '';
        $this->db->transactional(function () use (&$innerMessage): void {
            $this->writeDef('cpms_txn_nest_outer');
            try {
                $this->db->transactional(function (): void {
                    $this->writeDef('cpms_txn_nest_inner');
                    throw new \LogicException('inner rollback probe');
                });
            } catch (\LogicException $e) {
                $innerMessage = $e->getMessage();
            }

            self::assertSame('inner rollback probe', $innerMessage, 'the inner failure must propagate to the outer callback');
            self::assertSame(0, $this->countDefs('cpms_txn_nest_inner'), 'the inner rollback must undo the inner write only');
            self::assertSame(1, $this->countDefs('cpms_txn_nest_outer'), 'the outer write must survive the inner rollback (savepoint scope)');
        });

        self::assertSame(1, $this->countDefs('cpms_txn_nest_outer'), 'the outer transaction must commit its own write');
        self::assertSame(0, $this->countDefs('cpms_txn_nest_inner'), 'the inner write must stay rolled back after the outer commit');
    }

    // ------------------------------------------------------------------
    // F — custom-role write paths under transaction-control failure.
    // ------------------------------------------------------------------

    public function testCustomRoleDefineCannotReportSuccessWhenCommitFails(): void
    {
        $repo = new CustomRoleRepository($this->db);

        $this->armTxControlFailure('COMMIT');

        $thrown  = false;
        $message = '';
        $newId   = 0;

        try {
            $newId = $repo->define($this->clinicId, 'cpms_txn_define_probe', ['cpms_patient_read']);
        } catch (\Throwable $e) {
            $thrown  = true;
            $message = $e->getMessage();
        } finally {
            $this->disarmTxControlFailure();
        }

        self::assertTrue($thrown, 'define() must not report success when transaction control fails');
        self::assertSame(0, $newId, 'no definition id may be returned after a failed commit');
        self::assertStringContainsString('commit', strtolower($message));
        self::assertSame(0, $this->countDefs('cpms_txn_define_probe'), 'no partial custom role may persist');
    }

    public function testCustomRoleReplacementCannotReportSuccessWhenCommitFails(): void
    {
        $repo   = new CustomRoleRepository($this->db);
        $defId  = $repo->define($this->clinicId, 'cpms_txn_replace_probe', ['cpms_patient_read']);
        $caps   = $this->db->table('cpms_custom_role_capabilities');

        global $wpdb;
        $before = $wpdb->get_col( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->prepare("SELECT capability FROM {$caps} WHERE role_def_id = %d ORDER BY capability", $defId) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        $versionBefore = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->prepare("SELECT version FROM {$this->defs} WHERE id = %d", $defId) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertSame(['cpms_patient_read'], array_map('strval', (array) $before), 'seed capability set');
        self::assertSame(1, $versionBefore, 'seed version');

        $this->armTxControlFailure('COMMIT');

        $thrown  = false;
        $message = '';
        $ok      = false;

        try {
            $ok = $repo->replace_capabilities($this->clinicId, 'cpms_txn_replace_probe', ['cpms_appt_read', 'cpms_visit_read']);
        } catch (\Throwable $e) {
            $thrown  = true;
            $message = $e->getMessage();
        } finally {
            $this->disarmTxControlFailure();
        }

        self::assertTrue($thrown, 'replace_capabilities() must not report success when transaction control fails');
        self::assertFalse($ok, 'no success flag may be returned after a failed commit');
        self::assertStringContainsString('commit', strtolower($message));

        $after = array_map('strval', (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->prepare("SELECT capability FROM {$caps} WHERE role_def_id = %d ORDER BY capability", $defId) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        ));
        $versionAfter = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->prepare("SELECT version FROM {$this->defs} WHERE id = %d", $defId) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );

        self::assertSame(['cpms_patient_read'], $after, 'the previous capability set must be preserved');
        self::assertSame($versionBefore, $versionAfter, 'the version must not advance when the commit failed');
    }
}
