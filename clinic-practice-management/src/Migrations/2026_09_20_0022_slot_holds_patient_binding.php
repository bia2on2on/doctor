<?php

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Migration 0022 — Phase 8 Slice 3 (linked-Patient booking-subject selection):
 *
 * Hold is the durable booking-continuation object. Subject must be frozen at B1.
 *
 *   cpms_slot_holds.patient_id
 *     - BIGINT UNSIGNED;
 *     - NULL (NO DEFAULT) — historical rows remain NULL-compatible;
 *     - FK -> cpms_patients(id) ON DELETE RESTRICT;
 *     - no backfill;
 *     - no explicit index unless objectively required (InnoDB creates index for FK);
 *     - down drops FK before column.
 *
 * Authority: linked-only via cpms_patient_user_links (wp_user_id + clinic_id = trusted booking Clinic + patient_id active).
 * Same mobile alone is NOT authority.
 *
 * Idempotent: SHOW COLUMNS / information_schema.TABLE_CONSTRAINTS — safe re-run.
 * Preflight: orphan patient_id must reference existing patient; otherwise abort.
 */
return [
    'version' => '2026_09_20_0022',
    'description' => 'Phase 8 Slice 3: durably bind Hold to selected Patient — cpms_slot_holds.patient_id (BIGINT UNSIGNED NULL, FK -> patients(id) RESTRICT, no backfill)',
    'up' => function (CpmsDb $db): void {
        $t = $db->table('cpms_slot_holds');
        $fkName = 'fk_slot_holds_patient';
        $colName = 'patient_id';

        // ---------- 1) column (idempotent) ----------
        $col = $db->fetchRow("SHOW COLUMNS FROM {$t} LIKE '{$colName}'");
        if ($col === null) {
            $db->query("ALTER TABLE {$t} ADD COLUMN `{$colName}` BIGINT UNSIGNED NULL AFTER `holder_mobile`");
        } else {
            $type = strtolower((string) ($col['Type'] ?? ''));
            $nullable = strtoupper((string) ($col['Null'] ?? ''));
            // Must be BIGINT UNSIGNED NULL, no default (default NULL is implicit for NULL column).
            if (!str_contains($type, 'bigint') || !str_contains($type, 'unsigned') || $nullable !== 'YES') {
                throw new RuntimeException(
                    'Migration 0022 aborted: cpms_slot_holds.patient_id already exists with an incompatible ' .
                    "definition (type={$type}, nullable={$nullable}) — resolve manually, then re-run. No data was changed."
                );
            }
            // Check default is NULL (or empty) — historical compatibility.
            $default = $col['Default'] ?? null;
            if ($default !== null) {
                throw new RuntimeException(
                    'Migration 0022 aborted: cpms_slot_holds.patient_id already exists with non-NULL default ' .
                    "({$default}) — resolve manually, then re-run. No data was changed."
                );
            }
        }

        // ---------- 2) FK (idempotent) ----------
        $fk = $db->fetchRow(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s
               AND CONSTRAINT_NAME = %s AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [$t, $fkName]
        );
        if ((int) ($fk['n'] ?? 0) === 0) {
            // Preflight: no orphan patient_id.
            $orphans = (int) $db->fetchValue(
                "SELECT COUNT(*) FROM {$t} WHERE {$colName} IS NOT NULL AND {$colName} NOT IN (SELECT id FROM " . $db->table('cpms_patients') . ')'
            );
            if ($orphans > 0) {
                throw new RuntimeException(
                    'Migration 0022 aborted: orphan patient_id rows found in cpms_slot_holds (' . $orphans .
                    ' rows) — resolve manually, then re-run. No data was changed.'
                );
            }

            $db->query(
                "ALTER TABLE {$t} ADD CONSTRAINT `{$fkName}` FOREIGN KEY (`{$colName}`) REFERENCES " . $db->table('cpms_patients') . ' (`id`) ON DELETE RESTRICT'
            );
        }
    },
    'down' => function (CpmsDb $db): void {
        $t = $db->table('cpms_slot_holds');
        $fkName = 'fk_slot_holds_patient';
        $colName = 'patient_id';

        $fk = $db->fetchRow(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s
               AND CONSTRAINT_NAME = %s AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [$t, $fkName]
        );
        if ((int) ($fk['n'] ?? 0) > 0) {
            $db->query("ALTER TABLE {$t} DROP FOREIGN KEY `{$fkName}`");
        }

        $col = $db->fetchRow("SHOW COLUMNS FROM {$t} LIKE '{$colName}'");
        if ($col !== null) {
            $db->query("ALTER TABLE {$t} DROP COLUMN `{$colName}`");
        }
    },
];
