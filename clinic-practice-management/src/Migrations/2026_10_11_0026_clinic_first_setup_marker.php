<?php

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Migration 0026 — Custom Role & Permission Management Slice 3A:
 * first-practice bootstrap consumption marker.
 *
 *  1) `cpms_clinics.first_setup_claimed_at` — nullable DATETIME(3) on the
 *     durable Clinic row. NULL = never administered (first-setup still
 *     available); non-NULL = bootstrap consumed forever (monotonic; no code
 *     path ever resets it). The marker lives on the exact row that the
 *     provisioning transaction locks (SELECT ... FOR UPDATE), so two
 *     concurrent administrators cannot both provision.
 *
 *  2) Conservative backfill: a Clinic that shows any historical evidence of
 *     administration at migration time — ANY membership row (active OR
 *     suspended) or ANY hash-chained audit row referencing it — is marked as
 *     consumed. Absence of an ACTIVE manager is deliberately NOT treated as
 *     evidence of an unclaimed Clinic: previously administered Clinics stay
 *     non-claimable even when managers are suspended or inactive.
 *
 * No new table, no seed, no globally reusable flag, no data destruction.
 * Forward-only; `down()` drops only the added column.
 */
return [
    'version' => '2026_10_11_0026',
    'description' => 'Slice 3A: cpms_clinics.first_setup_claimed_at one-shot bootstrap consumption marker + conservative backfill',
    'up' => function (CpmsDb $db): void {
        $clinics = $db->table('cpms_clinics');

        $col = $db->fetchRow("SHOW COLUMNS FROM {$clinics} LIKE 'first_setup_claimed_at'");
        if ($col === null) {
            $db->query("ALTER TABLE {$clinics} ADD COLUMN `first_setup_claimed_at` DATETIME(3) NULL AFTER `updated_at`");
        }

        // Backfill (idempotent): any durable evidence of prior administration
        // consumes the bootstrap permanently. Membership rows count regardless
        // of status; audit history counts even where memberships were removed.
        $db->query(
            "UPDATE {$clinics} c
                SET c.first_setup_claimed_at = CURRENT_TIMESTAMP(3)
              WHERE c.first_setup_claimed_at IS NULL
                AND (
                     EXISTS (SELECT 1 FROM " . $db->table('cpms_clinic_memberships') . " m WHERE m.clinic_id = c.id)
                  OR EXISTS (SELECT 1 FROM " . $db->table('cpms_audit_logs') . " a WHERE a.clinic_id = c.id)
                )"
        );
    },

    'down' => function (CpmsDb $db): void {
        $clinics = $db->table('cpms_clinics');
        $col = $db->fetchRow("SHOW COLUMNS FROM {$clinics} LIKE 'first_setup_claimed_at'");
        if ($col !== null) {
            $db->query("ALTER TABLE {$clinics} DROP COLUMN `first_setup_claimed_at`");
        }
        $db->query('DELETE FROM ' . $db->table('cpms_schema_migrations') . " WHERE version = '2026_10_11_0026'");
    },
];
