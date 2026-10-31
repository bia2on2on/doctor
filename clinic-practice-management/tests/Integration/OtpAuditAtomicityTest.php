<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Auth\OtpException;
use ClinicCore\Application\Auth\OtpService;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Otp\OtpPolicy;
use ClinicCore\Tests\Support\ObjectCacheDeleteRecorder;
use WP_UnitTestCase;

/**
 * D6 — OtpService mandatory-audit atomicity (real-MySQL fault injection).
 *
 * A real MySQL 1054 injected only into cpms_audit_logs INSERTs proves that:
 *  - request(): the challenge token row and its mandatory OTP_REQUEST audit
 *    share one boundary — a failed audit leaves no un-audited token and the
 *    SMS side effect is never reached;
 *  - verify(): single-use consumption, identity provisioning (wp_users /
 *    patient links) and the mandatory OTP_VERIFY_OK / LOGIN_SUCCESS audits
 *    share one boundary — a failed audit rolls the consume back, so the same
 *    code remains valid for the authorized retry;
 *  - a failed-attempt counter update and its mandatory audit also roll back
 *    together when the audit insert is fault-injected.
 * Also asserts: no false success, zero partial audit rows, restored SAVEPOINT
 * depth, trusted-Clinic attribution (clinic_id = 1) and pre-mutation input
 * validation (invalid mobile is rejected before any write).
 */
final class OtpAuditAtomicityTest extends WP_UnitTestCase
{
    private const MOBILE = '09127776655';

    private ?ClinicScope $previousScope = null;

    /** @var callable|null */
    private $auditFault = null;

    private bool $previousSuppression = false;

    private ?ObjectCacheDeleteRecorder $recorder = null;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        $this->previousScope = ScopeContext::tryGet();
        App::replaceExplicitScope(ClinicScope::forClinic(1));
    }

    protected function tearDown(): void
    {
        $this->restoreAuditInserts();
        $this->recorder?->restore();
        App::replaceExplicitScope($this->previousScope);
        parent::tearDown();
    }

    public function testRequestAuditFailureRollsBackChallengeTokenAndBlocksDispatch(): void
    {
        $depth_before = $this->savepointDepth();

        $this->failAuditInserts();
        $threw = false;
        try {
            // The trusted Clinic is passed through the explicit parameter — the
            // same channel a persisted clinic selection uses. OtpService never
            // trusts an ambient request scope on the public OTP path, so an
            // implicit/null clinic would (correctly) be persisted as NULL.
            App::otpService()->request(self::MOBILE, OtpService::PURPOSE_LOGIN, null, null, 1);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->restoreAuditInserts();

        self::assertTrue($threw, 'a failed mandatory OTP_REQUEST audit must propagate, never report false success');
        self::assertSame(0, $this->tableCount('cpms_otp_tokens'), 'no challenge token may survive the rolled-back request');
        self::assertSame(0, $this->auditCount('OTP_REQUEST'), 'the failed audit must never be partially written');
        self::assertSame(0, $this->auditCount('OTP_SENT_OK') + $this->auditCount('OTP_SENT_FAIL'), 'SMS dispatch must never be reached on the failed path');
        self::assertSame($depth_before, $this->savepointDepth(), 'SAVEPOINT depth must be restored after rollback');

        // Authorized retry after the fault is removed: token + audit commit together.
        $result = App::otpService()->request(self::MOBILE, OtpService::PURPOSE_LOGIN, null, null, 1);
        self::assertIsArray($result);
        self::assertSame(1, $this->tableCount('cpms_otp_tokens'));
        self::assertSame(1, $this->auditCount('OTP_REQUEST'));
        self::assertSame(1, $this->latestAuditClinicId('OTP_REQUEST'), 'tenant attribution must name the trusted Clinic');
    }

    public function testVerifyAuditFailureRollsBackConsumeAndProvisioning(): void
    {
        $this->issueKnownCode('424242');
        $email = self::MOBILE . '@otp.cpms.local';
        self::assertSame(0, $this->usersWithEmail($email), 'fixture: no provisioned user before verify');

        $depth_before = $this->savepointDepth();

        // Record production cache eviction during the rollback (delegates to
        // the real cache; nothing about production behavior is altered).
        $recorder = ObjectCacheDeleteRecorder::install();
        $this->recorder = $recorder;

        $this->failAuditInserts();
        $threw = false;
        try {
            App::otpService()->verify(self::MOBILE, '424242');
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->restoreAuditInserts();

        // D7 — production cache rollback evidence (no test-side flush, which
        // the independent auditor rejected as it masks stale ghost identities):
        // UserCacheRollback must evict exactly the WP-owned user cache keys
        // after the confirmed ROLLBACK and must never global-flush the cache.
        $loginKeys = $recorder->deletedKeysForGroup('userlogins');
        $emailKeys = $recorder->deletedKeysForGroup('useremail');
        self::assertSame(0, $recorder->flushCount(), 'production rollback must never call wp_cache_flush()');
        self::assertNotEmpty($loginKeys, 'the provisioned login slug key must be evicted after rollback');
        self::assertContains($email, $emailKeys, 'the provisioned email key must be evicted after rollback');
        $userCacheIds = $recorder->deletedKeysForGroup('users');
        self::assertNotEmpty($userCacheIds, 'the users group entry for the ghost identity must be evicted');
        foreach ($userCacheIds as $evictedId) {
            self::assertTrue($recorder->deleted($evictedId, 'user_meta'), 'the matching user_meta key must be evicted too');
        }
        // End-to-end evidence WITHOUT any flush: the ghost is unresolvable
        // through WordPress' normal cache-backed lookup paths.
        self::assertFalse(get_user_by('email', $email), 'ghost identity must not resolve by email after rollback');
        self::assertFalse(get_user_by('login', $loginKeys[0]), 'ghost identity must not resolve by login after rollback');
        $recorder->restore();

        self::assertTrue($threw, 'a failed mandatory OTP_VERIFY_OK audit must propagate, never report false success');
        self::assertNull($this->tokenConsumedAt(), 'the single-use consume must roll back — the code stays valid');
        self::assertSame(0, $this->usersWithEmail($email), 'provisioned wp_users row must roll back with its audit');
        self::assertSame(0, $this->tableCount('cpms_patient_user_links'), 'no patient link may survive the rolled-back verify');
        self::assertSame(0, $this->auditCount('OTP_VERIFY_OK'), 'no audit evidence may survive the failed boundary');
        self::assertSame(0, $this->auditCount('LOGIN_SUCCESS'));
        self::assertSame($depth_before, $this->savepointDepth(), 'SAVEPOINT depth must be restored after rollback');

        // Authorized retry with the SAME code now succeeds (consume was rolled back).
        $result = App::otpService()->verify(self::MOBILE, '424242');
        self::assertGreaterThan(0, $result['user_id']);
        self::assertTrue($result['is_new_user']);
        self::assertTrue($result['session_issued']);
        self::assertNotNull($this->tokenConsumedAt(), 'the retry must durably consume the token');
        self::assertSame(1, $this->auditCount('OTP_VERIFY_OK'));
        self::assertSame(1, $this->auditCount('LOGIN_SUCCESS'));
        self::assertSame(1, $this->latestAuditClinicId('OTP_VERIFY_OK'), 'tenant attribution must name the trusted Clinic');
    }

    public function testFailedAttemptAuditFailureRollsBackCounter(): void
    {
        $this->issueKnownCode('424242');
        $depthBefore = $this->savepointDepth();
        $this->failAuditInserts();
        $threw = false;
        try {
            App::otpService()->verify(self::MOBILE, '000000');
        } catch (\Throwable $e) {
            $threw = true;
        } finally {
            $this->restoreAuditInserts();
        }
        self::assertTrue($threw, 'a failed mandatory failure audit must propagate');
        self::assertSame(0, $this->tokenAttempts(), 'the counter must roll back with the failed audit');
        self::assertSame(0, $this->auditCount('OTP_VERIFY_FAIL'));
        self::assertNull($this->tokenConsumedAt());
        self::assertSame($depthBefore, $this->savepointDepth());

        try {
            App::otpService()->verify(self::MOBILE, '000000');
            self::fail('the wrong code must be rejected');
        } catch (OtpException $e) {
            self::assertSame('CLINIC_OTP_INVALID', $e->apiCode());
            self::assertSame(4, $e->getData()['remaining']);
        }
        self::assertSame(1, $this->tokenAttempts());
        self::assertSame(1, $this->auditCount('OTP_VERIFY_FAIL'));
    }

    public function testInvalidMobileRejectedBeforeAnyTokenOrAuditWrite(): void
    {
        try {
            App::otpService()->request('08121234567');
            self::fail('an invalid mobile must be rejected before any mutation');
        } catch (OtpException $e) {
            self::assertSame('CLINIC_MOBILE_INVALID', $e->apiCode());
        }

        self::assertSame(0, $this->tableCount('cpms_otp_tokens'), 'validation must fail closed before the token write');
        self::assertSame(0, $this->auditCount('OTP_REQUEST'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function issueKnownCode(string $code, int $ttl_sec = 120): void
    {
        $pepper = defined('CPMS_PEPPER') && (string) CPMS_PEPPER !== ''
            ? (string) CPMS_PEPPER
            : (string) get_option('cpms_otp_pepper', '');
        if ($pepper === '') {
            $pepper = bin2hex(random_bytes(32));
            update_option('cpms_otp_pepper', $pepper, 'no');
        }
        App::db()->insert('cpms_otp_tokens', [
            'mobile' => self::MOBILE,
            'purpose' => 'login',
            'code_hash' => OtpPolicy::hashCode($code, $pepper),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttl_sec) . '.000',
            'attempts' => 0,
            'created_at' => App::db()->nowUtcSql(),
        ]);
    }

    private function tokenAttempts(): int
    {
        return (int) App::db()->fetchValue(
            'SELECT attempts FROM ' . App::db()->table('cpms_otp_tokens') . ' WHERE mobile = %s ORDER BY id DESC LIMIT 1',
            [self::MOBILE]
        );
    }

    private function tokenConsumedAt(): ?string
    {
        $value = App::db()->fetchValue(
            'SELECT consumed_at FROM ' . App::db()->table('cpms_otp_tokens') . ' WHERE mobile = %s ORDER BY id DESC LIMIT 1',
            [self::MOBILE]
        );

        return $value === null ? null : (string) $value;
    }

    private function usersWithEmail(string $email): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM ' . $wpdb->users . ' WHERE user_email = %s', $email) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
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
