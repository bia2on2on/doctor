<?php

/**
 * Phase 12 read-only evidence helper (TEST-ONLY — no product code).
 *
 * The delivered Phase 12 Staff Portal Finance GET surfaces (Slices 1-5) claim
 * to be strictly read-only. Their existing acceptance evidence compares ROW
 * COUNTS of the finance/visit/side-effect tables before and after the GET,
 * which proves INSERT/DELETE side effects only: an in-place UPDATE — a
 * silently rewritten amount, status, timestamp, hash or audit field — leaves
 * every count unchanged and therefore passes unnoticed.
 *
 * This trait supplies the missing half of that proof: the FULL persisted rows
 * directly related to the tested GET and its fixture, compared by VALUE before
 * and after the read. It is deliberately small and concrete, matching the
 * existing `tests/Integration/Fixtures` helper idiom (a plain trait used by
 * the test classes):
 *   - no abstraction layer, no base class, no new product/runtime code;
 *   - bounded by construction: only rows reachable from the given Visit /
 *     invoice ids of ONE Clinic are read — never a whole-database snapshot;
 *   - strictly read-only itself: SELECTs only, no write of any kind (a write
 *     here would let the read-only assertion pass by construction).
 *
 * The existing count snapshots are NOT replaced — they stay as the
 * INSERT/DELETE signal, and `cpms_idempotency_keys` / `cpms_jobs` /
 * `cpms_notifications` keep their count-level guard because a Phase 12 GET has
 * no materially relevant row of its own in those tables.
 *
 * Like the other `tests/Integration/Fixtures` helpers, this file is loaded with
 * an explicit `require_once` from each test class rather than relying on the
 * autoloader, so it works identically with the composer PSR-4 map
 * (`ClinicCore\Tests\` => tests/) and with the no-vendor fallback autoloader in
 * `tests/bootstrap.php`.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration\Fixtures;

use ClinicCore\Bootstrap\App;

trait Phase12ReadOnlyRowEvidence
{
    /**
     * The full persisted rows one Phase 12 finance GET is directly related to.
     *
     * Compared by value before/after the read, this detects in-place UPDATEs
     * as well as INSERT/DELETE side effects — which a count-level snapshot by
     * construction cannot.
     *
     * @param int       $clinicId   the single trusted Clinic the fixture built
     *                              (0 = no audit scoping, e.g. a rejected-scope
     *                              read where no Clinic is trusted yet)
     * @param list<int> $visitIds   the Visits the tested GET reads or rejects
     * @param list<int> $invoiceIds extra invoices to include; invoices linked to
     *                              $visitIds are resolved automatically
     *
     * @return array<string, mixed>
     */
    protected function persistedFinanceRows(int $clinicId, array $visitIds, array $invoiceIds = []): array
    {
        $db = App::db();
        $visitIds = self::evidenceIdList($visitIds);

        // Invoices reachable from the tested Visits are always included: a GET
        // that re-writes (or re-links) an invoice belonging to a Visit it only
        // read cannot escape the value comparison.
        $linked = [];
        if ($visitIds !== []) {
            foreach (
                $db->fetchAll(
                    'SELECT id FROM ' . $db->table('cpms_invoices')
                    . ' WHERE visit_id IN (' . self::evidencePlaceholders($visitIds) . ')'
                    . ' ORDER BY id ASC',
                    $visitIds
                ) as $row
            ) {
                $linked[] = (int) $row['id'];
            }
        }
        $invoiceIds = self::evidenceIdList(array_merge($invoiceIds, $linked));

        return [
            // The tested Visit rows themselves (status, active, check-in,
            // updated_at, ...).
            'visits' => self::evidenceRowsById('cpms_visits', $visitIds),
            // Side-effect / history / audit rows that a finance read could
            // materially mutate.
            'visit_history' => self::evidenceRowsByForeignKey('cpms_visit_status_history', 'visit_id', $visitIds),
            'invoices' => self::evidenceRowsById('cpms_invoices', $invoiceIds),
            'invoice_items' => self::evidenceRowsByForeignKey('cpms_invoice_items', 'invoice_id', $invoiceIds),
            'payments' => self::evidenceRowsByForeignKey('cpms_payments', 'invoice_id', $invoiceIds),
            'payment_adjustments' => self::evidenceRowsByForeignKey('cpms_payment_adjustments', 'invoice_id', $invoiceIds),
            // Clinic-scoped audit rows: a read that secretly writes — or
            // rewrites — audit evidence changes these values, and the audit
            // hash chain makes any in-place UPDATE materially detectable.
            'audits' => $clinicId > 0
                ? $db->fetchAll(
                    'SELECT * FROM ' . $db->table('cpms_audit_logs') . ' WHERE clinic_id = %d ORDER BY id ASC',
                    [$clinicId]
                )
                : [],
        ];
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private static function evidenceIdList(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }
        sort($clean);

        return $clean;
    }

    /**
     * @param list<int> $ids
     */
    private static function evidencePlaceholders(array $ids): string
    {
        return implode(', ', array_fill(0, max(1, count($ids)), '%d'));
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    private static function evidenceRowsById(string $table, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return App::db()->fetchAll(
            'SELECT * FROM ' . App::db()->table($table)
            . ' WHERE id IN (' . self::evidencePlaceholders($ids) . ')'
            . ' ORDER BY id ASC',
            $ids
        );
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    private static function evidenceRowsByForeignKey(string $table, string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return App::db()->fetchAll(
            'SELECT * FROM ' . App::db()->table($table)
            . ' WHERE ' . $column . ' IN (' . self::evidencePlaceholders($ids) . ')'
            . ' ORDER BY id ASC',
            $ids
        );
    }
}
