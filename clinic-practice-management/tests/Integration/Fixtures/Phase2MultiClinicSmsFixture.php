<?php

/**
 * Phase 2 — RED foundation fixture (test-only).
 *
 * Minimum reusable multi-Clinic fixture for the job-scope / SMS-isolation RED
 * foundation (design: `docs/architecture/phase2-tenant-context-remediation-design.md`,
 * RT-3 / RT-4 / RT-6 / RT-12 / RT-14).
 *
 * What it builds, all as REAL rows in the REAL test database:
 *   - one Organization;
 *   - Clinic A and Clinic B under it (explicit reserved IDs, captured — never
 *     `clinic_id = 1`, never "the first row");
 *   - one active Location per Clinic;
 *   - a DIFFERENT SMS configuration per Clinic: provider, sender, advanced
 *     (timeout/retry) and a SYNTHETIC sealed credential.
 *
 * Deliberate non-assumptions (per task scope):
 *   - no dependency on the current WP user;
 *   - no dependency on global mutable test order (reserved ID block + explicit
 *     precondition witness + deterministic purge);
 *   - no Tehran identity assumption — both Clinics use distinct non-default
 *     IANA zones so no test in this slice can pass "because Asia/Tehran";
 *   - credentials are synthetic constants that are obviously not real
 *     (SYNTHETIC-CPMS-...); nothing is decrypted from any real store.
 *
 * This file is loaded with an explicit `require_once` from the test class
 * rather than relying on the autoloader, so it works identically with the
 * composer PSR-4 map (`ClinicCore\Tests\` => tests/) and with the
 * no-vendor fallback autoloader in `tests/bootstrap.php`.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration\Fixtures;

use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;

trait Phase2MultiClinicSmsFixture
{
    /** Reserved ID block for this fixture — disjoint from 60xxx/61xxx used by other suites. */
    protected const FX_CLINIC_A_ID = 62101;
    protected const FX_CLINIC_B_ID = 62102;
    protected const FX_ID_FLOOR = 62100;

    /** Distinct, non-default IANA zones — deliberately NOT Asia/Tehran. */
    protected const FX_TZ_A = 'Europe/Berlin';
    protected const FX_TZ_B = 'Asia/Tokyo';

    /** Synthetic panel identities. Distinct senders make the leak observable. */
    protected const FX_SENDER_A = 'CPMS-RT-SENDER-A';
    protected const FX_SENDER_B = 'CPMS-RT-SENDER-B';

    /**
     * SYNTHETIC credentials — test fixtures only, never real panel secrets.
     * Distinct last4 makes credential identity assertable without ever
     * exposing a secret in an assertion message.
     */
    protected const FX_CRED_A = 'SYNTHETIC-CPMS-RT-CLINIC-A-0001';
    protected const FX_CRED_B = 'SYNTHETIC-CPMS-RT-CLINIC-B-0002';

    /** Distinct advanced config so `sms.advanced` bleed is observable. */
    protected const FX_TIMEOUT_A = 7;
    protected const FX_TIMEOUT_B = 9;

    protected int $fxOrg = 0;
    protected int $fxClinicA = 0;
    protected int $fxClinicB = 0;
    protected int $fxLocationA = 0;
    protected int $fxLocationB = 0;

    // =================================================================
    // Build
    // =================================================================

    /**
     * Builds Organization + Clinic A + Clinic B + one Location each + per-Clinic
     * SMS configuration. Returns nothing; IDs are captured on the instance.
     */
    protected function buildMultiClinicSmsFixture(): void
    {
        $this->fxAssertNoLeftoverFixtureRows();

        $this->fxOrg = $this->fxInsertOrganization('rt-org');
        $this->fxClinicA = $this->fxInsertClinic(self::FX_CLINIC_A_ID, $this->fxOrg, 'rt-clinic-a', self::FX_TZ_A);
        $this->fxClinicB = $this->fxInsertClinic(self::FX_CLINIC_B_ID, $this->fxOrg, 'rt-clinic-b', self::FX_TZ_B);

        $this->fxLocationA = $this->fxInsertLocation($this->fxClinicA, 'rt-loc-a');
        $this->fxLocationB = $this->fxInsertLocation($this->fxClinicB, 'rt-loc-b');

        // Distinct SMS configuration per Clinic — written through the real
        // per-Clinic Settings instance (explicit clinicId, the documented API).
        $this->fxSeedSmsConfig(
            $this->fxClinicA,
            self::FX_SENDER_A,
            self::FX_CRED_A,
            self::FX_TIMEOUT_A
        );
        $this->fxSeedSmsConfig(
            $this->fxClinicB,
            self::FX_SENDER_B,
            self::FX_CRED_B,
            self::FX_TIMEOUT_B
        );

        Settings::flushCache();
        App::resetScope();
    }

    /**
     * A Settings instance bound to one explicit Clinic — used ONLY to seed
     * fixture rows. It is never the object under test.
     */
    protected function fxSettingsFor(int $clinicId): Settings
    {
        return new Settings(App::db(), $clinicId, App::audit());
    }

    protected function fxInsertOrganization(string $slug): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at)
             VALUES (%s, %s, "active", %s, %s)',
            'RT Org ' . $slug,
            $slug . '-' . bin2hex(random_bytes(3)),
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'fixture precondition: organization row created');

        return $id;
    }

    protected function fxInsertClinic(int $id, int $orgId, string $slug, string $timezone): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at)
             VALUES (%d, %d, %s, %s, %s, %s, %s)',
            $id,
            $orgId,
            'RT Clinic ' . $slug,
            $slug,
            $timezone,
            $now,
            $now
        ));
        self::assertNotSame(1, $id, 'fixture must never use clinic_id = 1 (AD-13)');
        $created = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE slug = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $slug
        ));
        self::assertSame($id, $created, 'fixture precondition: clinic row created with the requested id');
        App::resetScope();

        return $created;
    }

    protected function fxInsertLocation(int $clinicId, string $slug): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)
             VALUES (%d, %s, %s, %s, 1, 1, %s, %s)',
            $clinicId,
            'RT Loc ' . $slug,
            $slug,
            $clinicId === self::FX_CLINIC_A_ID ? self::FX_TZ_A : self::FX_TZ_B,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'fixture precondition: location row created');

        return $id;
    }

    /**
     * Writes `sms.provider` / `sms.sender` / `sms.advanced` / sealed `sms.auth`
     * for ONE Clinic. The credential is sealed with the real installation-level
     * CredentialVault, exactly as `SmsService::saveSettings()` does — so the
     * code under test performs a genuine decryption, on genuinely sealed data.
     */
    protected function fxSeedSmsConfig(int $clinicId, string $sender, string $syntheticCred, int $timeoutSec): void
    {
        $settings = $this->fxSettingsFor($clinicId);
        $sealed = App::vault()->encrypt($syntheticCred);

        $settings->set('sms.provider', RecordingSmsProvider::ID);
        $settings->set('sms.sender', $sender);
        $settings->set('sms.advanced', ['timeout_sec' => $timeoutSec, 'retry_count' => 3]);
        $settings->set('sms.auth', [
            'method' => 'api_key',
            'fields' => [
                'api_key' => [
                    'sealed' => $sealed,
                    'last4' => \ClinicCore\Infrastructure\Sms\CredentialVault::last4($syntheticCred),
                ],
            ],
            'updated_at' => gmdate('c'),
        ]);
    }

    // =================================================================
    // Message rows
    // =================================================================

    /**
     * A QUEUED `cpms_sms_messages` row owned by $clinicId, addressed to the
     * recording provider, with no template (so the production path calls
     * `sendText()` and the observable surface is creds + opts).
     */
    protected function fxSeedQueuedMessage(int $clinicId, string $text): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_sms_messages
                 (clinic_id, event, recipient, message, vars_json, provider, template_id, status, attempts, max_attempts, created_at, updated_at)
             VALUES (%d, %s, %s, %s, NULL, %s, NULL, %s, 0, 1, %s, %s)',
            $clinicId,
            'test',
            '09120000' . sprintf('%03d', $clinicId % 1000),
            $text,
            RecordingSmsProvider::ID,
            \ClinicCore\Domain\Sms\SmsMessageStatus::QUEUED,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'fixture precondition: sms message row created');

        return $id;
    }

    /**
     * @return array<string,mixed>
     */
    protected function fxMessageRow(int $messageId): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $wpdb->prefix . 'cpms_sms_messages WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $messageId
        ), ARRAY_A);

        return is_array($row) ? $row : [];
    }

    // =================================================================
    // Deterministic cleanup
    // =================================================================

    protected function fxAssertNoLeftoverFixtureRows(): void
    {
        global $wpdb;
        $leftover = (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id >= ' . self::FX_ID_FLOOR // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertSame(0, $leftover, 'CPMS_RT_WITNESS: leftover fixture rows from a previous test');
    }

    /**
     * FK-safe purge of everything this fixture (and any handler the tick
     * touched on its behalf) can have written. Deterministic and idempotent.
     */
    protected function fxPurge(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $range = ' WHERE clinic_id >= ' . self::FX_ID_FLOOR;

        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $steps = [
            'cpms_sms_messages' => $range,
            'cpms_notifications' => $range,
            'cpms_appointments' => $range,
            'cpms_schedule_slots' => $range,
            'cpms_clinicians' => $range,
            'cpms_locations' => $range,
            'cpms_settings' => $range,
            'cpms_audit_logs' => $range,
            'cpms_clinic_memberships' => $range,
            'cpms_clinics' => ' WHERE id >= ' . self::FX_ID_FLOOR,
        ];
        foreach ($steps as $table => $clause) {
            $wpdb->query('DELETE FROM ' . $p . $table . $clause); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
        // Queue rows are tenant-less today (cpms_jobs has no tenant column) —
        // purge the whole table so no queued/failed row leaks into another test.
        $wpdb->query('DELETE FROM ' . $p . 'cpms_jobs'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        // OTP rows seeded for the per-job isolation test are tenant-less too.
        $wpdb->query('DELETE FROM ' . $p . "cpms_otp_tokens WHERE mobile LIKE '091299%'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('DELETE FROM ' . $p . "cpms_organizations WHERE slug LIKE 'rt\\\\_org\\\\_%'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * An expired OTP row — the observable side effect for the "unrelated valid
     * job" in the per-job failure isolation test (`cleanup.otp`).
     */
    protected function fxSeedExpiredOtpToken(): int
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_otp_tokens (mobile, purpose, code_hash, expires_at, attempts, created_at)
             VALUES (%s, "login", %s, %s, 0, %s)',
            '091299' . sprintf('%05d', random_int(0, 99999)),
            hash('sha256', 'rt-fixture-code'),
            gmdate('Y-m-d H:i:s', time() - 3 * 86400) . '.000',
            gmdate('Y-m-d H:i:s', time() - 3 * 86400) . '.000'
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'fixture precondition: expired otp row created');

        return $id;
    }

    protected function fxJobStatus(int $jobId): string
    {
        global $wpdb;

        return (string) $wpdb->get_var($wpdb->prepare(
            'SELECT status FROM ' . $wpdb->prefix . 'cpms_jobs WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $jobId
        ));
    }
}
