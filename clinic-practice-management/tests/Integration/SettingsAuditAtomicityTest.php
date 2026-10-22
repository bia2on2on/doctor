<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
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
