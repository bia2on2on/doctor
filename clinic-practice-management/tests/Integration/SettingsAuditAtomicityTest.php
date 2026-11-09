<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Otp\OtpState;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * D6 — Settings::set() audit atomicity (real-MySQL fault injection).
 *
 * The setting write and its mandatory SETTING_UPDATE audit must commit or roll
 * back together: a forced audit INSERT failure leaves the previous durable
 * value intact (no un-audited setting change) and propagates the failure.
 */
final class SettingsAuditAtomicityTest extends WP_UnitTestCase
{
    /** @var callable|null */
    private $auditFault = null;

    /** @var callable|null */
    private $settingsReadFault = null;

    /** @var callable|null */
    private $settingsWriteFault = null;

    private bool $settingsFaultFired = false;

    private bool $previousSuppression = false;

    private int $adminId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->adminId);
        Settings::flushCache();
    }

    protected function tearDown(): void
    {
        $this->restoreSettingsStatementFault();
        $this->restoreSettingsBulkReads();
        $this->restoreAuditInserts();
        Settings::flushCache();
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function testAuditFailureRollsBackSettingChangeAndRetrySucceeds(): void
    {
        $settings = App::settings();
        $key = 'booking.hold_ttl_sec';

        // Baseline durable write + audit succeeds (700 differs from the 600 default).
        $settings->set($key, 700, $this->adminId);
        self::assertSame(700, (int) App::settings()->get($key));
        $baselineAudits = $this->settingAuditCount($key);
        self::assertGreaterThan(0, $baselineAudits);

        $this->failAuditInserts();
        $threw = false;
        try {
            $settings->set($key, 999, $this->adminId);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->restoreAuditInserts();
        Settings::flushCache();

        self::assertTrue($threw, 'the audit failure must propagate instead of reporting success');
        // Durable value is unchanged — the un-audited write was rolled back.
        self::assertSame(700, (int) App::settings()->get($key));
        self::assertSame($baselineAudits, $this->settingAuditCount($key), 'no audit row may be written for the failed change');

        // Authorized retry after the fault is removed commits and audits.
        $settings->set($key, 999, $this->adminId);
        Settings::flushCache();
        self::assertSame(999, (int) App::settings()->get($key));
        self::assertSame($baselineAudits + 1, $this->settingAuditCount($key));
    }


    public function testSetManyRolledBackByOuterTransactionLeavesNoPhantomCache(): void
    {
        $settings = App::settings();
        $settings->set('booking.duration_default_min', 20, $this->adminId);
        $settings->set('booking.min_lead_hours', 2, $this->adminId);
        $audits_before = $this->settingAuditCount('booking.duration_default_min');

        $threw = false;
        try {
            App::db()->transactional(function () use ($settings): void {
                $settings->set_many([
                    'booking.duration_default_min' => 45,
                    'booking.min_lead_hours'       => 9,
                ], $this->adminId);
                // Inside the transaction the uncommitted values are visible.
                self::assertSame(45, (int) $settings->get('booking.duration_default_min'));
                throw new \RuntimeException('outer transaction rollback (test)');
            });
        } catch (\RuntimeException) {
            $threw = true;
        }

        self::assertTrue($threw, 'the outer rollback must propagate');
        // Same process, deliberately NO flushCache(): values read inside
        // the rolled-back transaction must not survive in the snapshot
        // cache (inner set_many writes repopulate the cache with
        // uncommitted values; the rollback must invalidate them).
        self::assertSame(20, (int) App::settings()->get('booking.duration_default_min'), 'a value rolled back by the outer transaction must not survive in the cache');
        self::assertSame(2, (int) App::settings()->get('booking.min_lead_hours'), 'a value rolled back by the outer transaction must not survive in the cache');
        self::assertSame(20, $this->storedSettingValue('booking.duration_default_min'), 'the durable value must be the pre-transaction one');
        self::assertSame(2, $this->storedSettingValue('booking.min_lead_hours'), 'the durable value must be the pre-transaction one');
        self::assertSame($audits_before, $this->settingAuditCount('booking.duration_default_min'), 'audits written inside the rolled-back transaction must roll back too');
    }

    public function testSetToDefaultValueAuditsAgainstStoredRestrictiveValue(): void
    {
        $settings = App::settings();
        $key      = 'otp.max_attempts'; // documented default is 5

        $settings->set($key, 2, $this->adminId);
        $audits_after_restrictive = $this->settingAuditCount($key);
        Settings::flushCache();

        // The proposed value equals the default but differs from the stored
        // value, so it IS an effective change and must be audited as 2 -> 5.
        $settings->set($key, 5, $this->adminId);

        self::assertSame($audits_after_restrictive + 1, $this->settingAuditCount($key), 'a default-equal value that changes the stored value must still be audited');
        [$before, $after] = $this->latestSettingAuditValues();
        self::assertSame(2, $before, 'the audited before-value must be the stored restrictive value');
        self::assertSame(5, $after);
    }

    public function testSetRefusesWriteWhenBeforeValueCannotBeVerified(): void
    {
        $settings = App::settings();
        $key      = 'otp.max_attempts'; // documented default is 5

        $settings->set($key, 2, $this->adminId);
        $baseline_audits = $this->settingAuditCount($key);

        // Persistently fail the bulk settings read, then propose the
        // default-equal value. Comparing against an unverified default (5)
        // would look like a no-op and silently write 2 -> 5 with no audit.
        $this->failSettingsBulkReads(false);
        Settings::flushCache();
        $threw = false;
        try {
            $settings->set($key, 5, $this->adminId);
        } catch (\RuntimeException) {
            $threw = true;
        } finally {
            $this->restoreSettingsBulkReads();
        }

        self::assertTrue($threw, 'a settings write whose before-value cannot be verified must be refused');
        self::assertSame(2, $this->storedSettingValue($key), 'the refused write must not change the stored value');
        self::assertSame($baseline_audits, $this->settingAuditCount($key), 'the refused write must not create an audit row');

        // After the read recovers, the same write proceeds and audits 2 -> 5.
        Settings::flushCache();
        $settings->set($key, 5, $this->adminId);
        self::assertSame($baseline_audits + 1, $this->settingAuditCount($key));
        [$before, $after] = $this->latestSettingAuditValues();
        self::assertSame(2, $before);
        self::assertSame(5, $after);
    }

    public function testStrictModeSettingsReadFailureStillPropagates(): void
    {
        $this->failSettingsBulkReads(false);
        Settings::flushCache();
        App::db()->setStrict(true);
        $threw = false;
        try {
            App::settings()->get('otp.max_attempts');
        } catch (\RuntimeException) {
            $threw = true;
        } finally {
            App::db()->setStrict(false);
            $this->restoreSettingsBulkReads();
            Settings::flushCache();
        }

        self::assertTrue($threw, 'CpmsDb strict mode must keep propagating settings read failures');
    }

    public function testOtpPolicyFailsClosedOnTransientReadFailureThenUsesRestrictiveValues(): void
    {
        $settings = App::settings();
        $settings->set('otp.ttl_sec', 30, $this->adminId);
        $settings->set('otp.max_attempts', 2, $this->adminId);
        $settings->set('otp.cooldown_sec', 90, $this->adminId);
        $settings->set('otp.daily_max', 1, $this->adminId);
        $settings->set('otp.lockout_sec', 1800, $this->adminId);
        Settings::flushCache();

        $now    = new \DateTimeImmutable('2026-11-09 12:00:00');
        $policy = App::settings()->otpPolicy();
        self::assertTrue($policy->isCodeExpired($now->modify('-31 seconds'), $now), 'control: restrictive TTL 30 applies');
        self::assertSame(2, $policy->remainingAttempts(new OtpState()), 'control: restrictive max attempts 2 applies');

        // One transient bulk-read failure: the policy must fail closed
        // rather than fall back to (weaker) defaults or a mixed snapshot.
        $this->failSettingsBulkReads(true);
        Settings::flushCache();
        $threw = false;
        try {
            App::settings()->otpPolicy();
        } catch (\RuntimeException) {
            $threw = true;
        } finally {
            $this->restoreSettingsBulkReads();
        }
        self::assertTrue($threw, 'an unverifiable OTP policy must fail closed, not use unverified defaults');

        // After the transient failure, the policy is built entirely from
        // the restrictive persisted values — never a default/stored mix.
        $policy = App::settings()->otpPolicy();
        self::assertTrue($policy->isCodeExpired($now->modify('-31 seconds'), $now), 'restrictive TTL 30 must apply after recovery');
        self::assertFalse($policy->isCodeExpired($now->modify('-29 seconds'), $now));
        self::assertSame(2, $policy->remainingAttempts(new OtpState()), 'restrictive max attempts 2 must apply after recovery');
        self::assertSame(90, $policy->remaining_cooldown(new OtpState(lastSentAt: $now), $now), 'restrictive cooldown 90 must apply after recovery');
        self::assertSame('daily_limit', $policy->canSend(new OtpState(sentToday: 1), $now)['reason'], 'restrictive daily max 1 must apply after recovery');
        $state = $policy->registerFailedAttempt(new OtpState(), $now);
        $state = $policy->registerFailedAttempt($state, $now);
        self::assertSame(1800, $policy->remaining_lockout($state, $now), 'restrictive lockout 1800 must apply after recovery');
    }


    public function testSetPointLookupFailureAbortsWithoutWriteOrAudit(): void
    {
        $settings = App::settings();
        $key      = 'otp.max_attempts';

        $settings->set($key, 2, $this->adminId);
        $baseline_audits = $this->settingAuditCount($key);

        // The insert-vs-update point lookup fails: it must not be mistaken
        // for absence (which would attempt an insert and then audit a
        // mutation that never persisted).
        $this->failSettingsStatement('lookup');
        Settings::flushCache();
        $threw = false;
        try {
            $settings->set($key, 5, $this->adminId);
        } catch (\RuntimeException) {
            $threw = true;
        } finally {
            $this->restoreSettingsStatementFault();
        }

        self::assertTrue($threw, 'a failed settings point lookup must abort the write');
        self::assertSame(2, $this->storedSettingValue($key), 'the stored value must be unchanged');
        self::assertSame($baseline_audits, $this->settingAuditCount($key), 'no audit row may be written');
    }

    public function testSetInsertFailureLeavesNoRowAndNoAudit(): void
    {
        $key             = 'otp.cooldown_sec';
        $baseline_audits = $this->settingAuditCount($key);

        $this->failSettingsStatement('insert');
        Settings::flushCache();
        $threw = false;
        try {
            App::settings()->set($key, 45, $this->adminId);
        } catch (\RuntimeException) {
            $threw = true;
        } finally {
            $this->restoreSettingsStatementFault();
        }

        self::assertTrue($threw, 'a failed insert must abort instead of auditing a phantom change');
        self::assertNull($this->storedSettingValue($key), 'no settings row may persist after a failed insert');
        self::assertSame($baseline_audits, $this->settingAuditCount($key), 'no audit row may be written for a failed insert');
    }

    public function testSetUpdateFailureKeepsStoredValueAndAuditCount(): void
    {
        $settings = App::settings();
        $key      = 'otp.max_attempts';

        $settings->set($key, 2, $this->adminId);
        $baseline_audits = $this->settingAuditCount($key);

        $this->failSettingsStatement('update');
        Settings::flushCache();
        $threw = false;
        try {
            $settings->set($key, 5, $this->adminId);
        } catch (\RuntimeException) {
            $threw = true;
        } finally {
            $this->restoreSettingsStatementFault();
        }

        self::assertTrue($threw, 'a failed update must abort instead of auditing a phantom change');
        self::assertSame(2, $this->storedSettingValue($key), 'the stored value must be unchanged');
        self::assertSame($baseline_audits, $this->settingAuditCount($key), 'no audit row may be written for a failed update');
    }

    public function testStrictModeReadAfterSoftFailureStillPropagates(): void
    {
        // Contract ordering: a soft read failure latches defaults for soft
        // consumers, but enabling CpmsDb strict mode afterwards (as
        // MigrationRunner does) must still surface the read failure.
        // This is a contract inconsistency in the previous ordering, fixed
        // by checking strict mode before the latch; no migration failure
        // was observed in production.
        $this->failSettingsBulkReads(false);
        Settings::flushCache();
        self::assertSame(5, (int) App::settings()->get('otp.max_attempts'), 'soft read keeps returning defaults after a soft failure');

        App::db()->setStrict(true);
        $threw = false;
        try {
            App::settings()->get('otp.max_attempts');
        } catch (\RuntimeException) {
            $threw = true;
        } finally {
            App::db()->setStrict(false);
            $this->restoreSettingsBulkReads();
            Settings::flushCache();
        }

        self::assertTrue($threw, 'strict mode must not be masked by an earlier soft-failure latch');
    }

    private function settingAuditCount(string $key): int
    {
        // Each test runs in its own rolled-back data view, so the clinic-scoped
        // SETTING_UPDATE count isolates this test's own setting events.
        unset( $key );
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_audit_logs' )
            . " WHERE action = 'SETTING_UPDATE' AND clinic_id = %d",
            [ App::scope()->clinicId ]
        );
    }


    private function storedSettingValue(string $key): ?int
    {
        $json = App::db()->fetchValue(
            'SELECT value_json FROM ' . App::db()->table('cpms_settings') . ' WHERE clinic_id = %d AND `key` = %s',
            [App::scope()->clinicId, $key]
        );

        return $json === null ? null : (int) json_decode((string) $json, true);
    }

    /** @return array{0: int, 1: int} */
    private function latestSettingAuditValues(): array
    {
        $row = App::db()->fetchRow(
            'SELECT before_json, after_json FROM ' . App::db()->table('cpms_audit_logs')
            . ' WHERE action = %s AND clinic_id = %d ORDER BY id DESC LIMIT 1',
            ['SETTING_UPDATE', App::scope()->clinicId]
        );
        self::assertIsArray($row);
        $before = json_decode((string) $row['before_json'], true);
        $after  = json_decode((string) $row['after_json'], true);

        return [(int) ($before['value'] ?? -1), (int) ($after['value'] ?? -1)];
    }


    private function failSettingsStatement(string $mode): void
    {
        global $wpdb;
        self::assertNull($this->settingsWriteFault, 'the settings write fault must not be installed twice');
        $table = $wpdb->prefix . 'cpms_settings';

        $fault = function ($query) use ($table, $mode) {
            $query = (string) $query;
            $hit   = match ($mode) {
                'lookup' => str_starts_with($query, 'SELECT id FROM ' . $table),
                'insert' => str_contains($query, 'INSERT INTO `' . $table . '`') || str_contains($query, 'INSERT INTO ' . $table),
                'update' => str_starts_with($query, 'UPDATE ' . $table . ' SET value_json'),
                default  => false,
            };

            return $hit ? str_replace($table, $table . '_write_failure', $query) : $query;
        };

        $this->previousSuppression  = (bool) $wpdb->suppress_errors(true);
        $this->settingsWriteFault   = $fault;
        add_filter('query', $fault, 5, 1);
    }

    private function restoreSettingsStatementFault(): void
    {
        if ($this->settingsWriteFault === null) {
            return;
        }
        remove_filter('query', $this->settingsWriteFault, 5);
        $this->settingsWriteFault = null;

        global $wpdb;
        $wpdb->suppress_errors($this->previousSuppression);
    }

    private function failSettingsBulkReads(bool $oneShot): void
    {
        global $wpdb;
        self::assertNull($this->settingsReadFault, 'the settings read fault must not be installed twice');
        $clinic_id      = (int) App::scope()->clinicId;
        $settings_table = $wpdb->prefix . 'cpms_settings';
        $this->settingsFaultFired = false;

        $fault = function ($query) use ($clinic_id, $settings_table, $oneShot) {
            $query = (string) $query;
            if (
                (! $oneShot || ! $this->settingsFaultFired)
                && str_contains($query, 'SELECT `key`, value_json FROM ' . $settings_table)
                && preg_match('/WHERE clinic_id = ' . $clinic_id . '\s*$/', $query) === 1
            ) {
                $this->settingsFaultFired = true;

                return str_replace('FROM ' . $settings_table, 'FROM ' . $settings_table . '_read_failure', $query);
            }

            return $query;
        };

        $this->previousSuppression = (bool) $wpdb->suppress_errors(true);
        $this->settingsReadFault   = $fault;
        add_filter('query', $fault, 5, 1);
    }

    private function restoreSettingsBulkReads(): void
    {
        if ($this->settingsReadFault === null) {
            return;
        }
        remove_filter('query', $this->settingsReadFault, 5);
        $this->settingsReadFault = null;

        global $wpdb;
        $wpdb->suppress_errors($this->previousSuppression);
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
