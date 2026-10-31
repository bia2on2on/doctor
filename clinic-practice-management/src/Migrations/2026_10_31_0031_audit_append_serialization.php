<?php

/**
 * Migration 0031 — Audit append serialization guard (Classic V1 remediation).
 *
 * The original AuditLogger read the tail with `SELECT row_hash ... ORDER BY id DESC LIMIT 1 FOR UPDATE`
 * inside a nested transactional savepoint. That row lock does NOT serialize concurrent appends:
 * under REPEATABLE READ, two transactions can read the same committed tail, compute the
 * same prev_hash, and insert two children with an unexpected genesis linkage and five
 * broken links among 674 rows (installer report on MariaDB 11.4.13 IDs 88,103,140,141,143).
 * The failure is a moving-tail race, not a missing index or a transient deadlock.
 *
 * This migration adds a singleton InnoDB guard row (id=1) that every audit append must
 * lock **inside the same outer transaction** before reading the current tail. The guard
 * is a fixed serialization point; `FOR UPDATE` on it blocks until the previous append
 * commits, so the subsequent `SELECT ... ORDER BY id DESC LIMIT 1 FOR UPDATE` sees the
 * durable predecessor. No historical audit row is touched — the guard's last_audit_id
 * is only bookkeeping for diagnostics.
 *
 * Engine handling: MySQL 8 and MariaDB 11.4.13 both use InnoDB row locks for this guard.
 * The migration seeds the guard from the existing MAX(id) so an upgrade with existing
 * history does not claim an empty chain. Failure to create or seed the guard is fail-closed.
 *
 * Lock ordering: every business boundary that already holds Slot/Location/Capacity locks
 * before calling AuditLogger now holds those locks *before* the guard lock (guard is last).
 * The established booking order Slot→Location→AuditGuard is preserved, so no ABBA cycle
 * is introduced. Mixed old/new writers during a rolling upgrade remain racy — deploy
 * in a quiet window and verify with the independent checker after.
 */

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

return [
    'version'     => '2026_10_31_0031',
    'description' => 'Audit append serialization guard — singleton row locked per append',
    'up'          => static function ( CpmsDb $db ) : void {
        $seq = $db->table( 'cpms_audit_seq' );

        $db->query(
            "CREATE TABLE IF NOT EXISTS {$seq} (
            `id` BIGINT UNSIGNED NOT NULL,
            `last_audit_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Seed from existing audit history without rewriting it.
        $max_id = $db->fetchValue( 'SELECT COALESCE(MAX(id), 0) FROM ' . $db->table( 'cpms_audit_logs' ) );
        $max_id = (int) ( $max_id ?? 0 );
        $now    = $db->nowUtcSql();

        // Idempotent: INSERT IGNORE keeps the first seeding; an existing guard with an older
        // last_audit_id is fast-forwarded below. This handles re-runs and manual repairs.
        $db->query(
            'INSERT IGNORE INTO ' . $seq . ' (id, last_audit_id, created_at, updated_at) VALUES (%d, %d, %s, %s)',
            [ 1, $max_id, $now, $now ]
        );

        // Fast-forward if the guard exists but lags behind real history (e.g. after a
        // manual audit insert or a prior partial seeding). Never roll back.
        $current = $db->fetchValue( 'SELECT last_audit_id FROM ' . $seq . ' WHERE id = %d', [ 1 ] );
        if ( $current !== null && (int) $current < $max_id ) {
            $db->query(
                'UPDATE ' . $seq . ' SET last_audit_id = %d, updated_at = %s WHERE id = %d',
                [ $max_id, $now, 1 ]
            );
        }

        // Fail-closed: guard must be readable after seeding.
        $guard = $db->fetchRow( 'SELECT id, last_audit_id FROM ' . $seq . ' WHERE id = %d', [ 1 ] );
        if ( $guard === null || (int) ( $guard['id'] ?? 0 ) !== 1 ) {
            throw new RuntimeException( 'migration 0031: audit guard row missing after seeding' );
        }
    },
    'down'        => static function ( CpmsDb $db ) : void {
        // Development only — production restores from backup; audit history is never deleted.
        $db->query( 'DROP TABLE IF EXISTS ' . $db->table( 'cpms_audit_seq' ) );
    },
];
