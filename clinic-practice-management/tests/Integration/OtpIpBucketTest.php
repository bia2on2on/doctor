<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Auth\OtpException;
use ClinicCore\Application\Auth\OtpService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * Behavioural coverage for the per-IP OTP hourly guard (otp.ip_hourly_max),
 * complementing the unit-level clamp tests with real request() executions
 * through the real RateLimiter rows.
 *
 * DOCUMENTED CURRENT SEMANTICS (recorded, not endorsed — see
 * docs/decisions/2026-11-05-otp-ip-bucket-shared-counter-record.md):
 * the bucket key 'otp-ip:'.$ip is **global per IP**: one shared hits counter
 * row serves every Clinic, while the *threshold* is read from the Settings of
 * the Clinic that owns the current request context. Two Clinics behind a
 * shared NAT therefore interfere through the same counter — a known latent
 * risk pending the open Owner decision Q6 on cpms_rate_limits scoping.
 * These tests pin today's behaviour so any future Q6 change is a deliberate,
 * diff-visible choice, never a silent drift.
 *
 * Blocked attempts keep incrementing the counter (RateLimiter::hit semantics),
 * exactly like all other uses of the shared limiter.
 */
final class OtpIpBucketTest extends WP_UnitTestCase
{
    private const HOUR = 3600;

    // RFC 5737 TEST-NET-3 addresses, one per method: hit-counters persist as
    // rows, so distinct keys keep methods independent within one run.
    private const IP_SHARED  = '203.0.113.11';
    private const IP_BRANCH  = '203.0.113.12';
    private const IP_GARBAGE = '203.0.113.13';

    private int $resolverClinic = 1;
    private int $mobileSeq = 1000;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
        App::settingsFactory()->reset();
        $this->resolverClinic = 1;
        $this->mobileSeq = 1000;
    }

    /**
     * App::otpService() is a process-static singleton bound to the ambient
     * scope; for deterministic resolver/explicit-clinicId contrast a local
     * instance is built from the same shared dependencies with only the
     * clinic-resolver closure replaced.
     */
    private function service(): OtpService
    {
        return new OtpService(
            App::db(),
            App::settingsFactory(),
            fn (): int => $this->resolverClinic,
            App::rate(),
            App::audit(),
            App::op(),
            App::smsService(),
            App::jobs()
        );
    }

    /** A fresh mobile per issuance so per-mobile cooldown stays out of scope. */
    private function uniqueMobile(): string
    {
        $this->mobileSeq++;
        return '0913555' . str_pad((string) $this->mobileSeq, 4, '0', STR_PAD_LEFT);
    }

    private function ipHits(string $ip): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cpms_rate_limits';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table prefix
        $sql = "SELECT hits FROM {$table} WHERE window_key = %s AND window_id = %d";

        return (int) $wpdb->get_var(
            $wpdb->prepare($sql, 'otp-ip:' . $ip, intdiv(time(), self::HOUR))
        );
    }

    private function issue(string $ip, ?int $clinicId = null): array
    {
        return $this->service()->request(
            $this->uniqueMobile(),
            OtpService::PURPOSE_LOGIN,
            null,
            $ip,
            $clinicId
        );
    }

    private function expectRateLimited(callable $action): void
    {
        try {
            $action();
        } catch (OtpException $error) {
            self::assertSame('CLINIC_RATE_LIMITED', $error->apiCode());
            return;
        }

        self::fail('expected CLINIC_RATE_LIMITED');
    }

    /**
     * Setting rows persist inside the cpms tables across tests in one run;
     * restore the documented default so nothing written here can leak.
     */
    protected function tearDown(): void
    {
        App::settingsFactory()->forClinic(1)->set('otp.ip_hourly_max', 10);
        App::settingsFactory()->forClinic(2)->set('otp.ip_hourly_max', 10);
        Settings::flushCache();
        parent::tearDown();
    }

    /**
     * Shared-bucket contract: clinic 1 caps at 2, clinic 2 caps at 5, and the
     * SAME 'otp-ip:' row feeds both decisions — the second clinic inherits the
     * first clinic's spent counter.
     */
    public function testTwoClinicsShareOneIpBucketWithDifferentThresholds(): void
    {
        App::settingsFactory()->forClinic(1)->set('otp.ip_hourly_max', 2);
        App::settingsFactory()->forClinic(2)->set('otp.ip_hourly_max', 5);

        $this->resolverClinic = 1;
        $this->issue(self::IP_SHARED);
        $this->issue(self::IP_SHARED);
        $this->expectRateLimited(fn () => $this->issue(self::IP_SHARED));
        self::assertSame(
            3,
            $this->ipHits(self::IP_SHARED),
            'denial still charges the shared counter'
        );

        // Clinic 2 context reads its own threshold (5) against the SAME row:
        // hits 4 and 5 pass, the 6th is denied by clinic 2's own cap.
        $this->resolverClinic = 2;
        $this->issue(self::IP_SHARED);
        $this->issue(self::IP_SHARED);
        self::assertSame(5, $this->ipHits(self::IP_SHARED));
        $this->expectRateLimited(fn () => $this->issue(self::IP_SHARED));
        self::assertSame(6, $this->ipHits(self::IP_SHARED));
    }

    /**
     * Threshold-source branch: an explicit persisted $clinicId selects that
     * Clinic's Settings; null (and non-positive) falls back to the resolver.
     */
    public function testExplicitClinicIdSelectsThatClinicThresholdOverResolver(): void
    {
        App::settingsFactory()->forClinic(1)->set('otp.ip_hourly_max', 2);
        App::settingsFactory()->forClinic(2)->set('otp.ip_hourly_max', 5);
        $this->resolverClinic = 1;

        $this->issue(self::IP_BRANCH);
        $this->issue(self::IP_BRANCH);
        $this->expectRateLimited(fn () => $this->issue(self::IP_BRANCH));
        self::assertSame(3, $this->ipHits(self::IP_BRANCH));

        // Explicit clinicId=2 switches the threshold source (cap 5) for the
        // same shared bucket…
        $this->issue(self::IP_BRANCH, 2);
        self::assertSame(4, $this->ipHits(self::IP_BRANCH));

        // …while clinicId=0 is ignored ($clinicId > 0 guard) and the resolver
        // branch with cap 2 still denies the already-exhausted bucket.
        $this->expectRateLimited(fn () => $this->issue(self::IP_BRANCH, 0));
        self::assertSame(5, $this->ipHits(self::IP_BRANCH));
    }

    /**
     * The review fix at the real gate: a non-numeric stored value ('abc')
     * behaves as the default 10. With the former (int)-cast clamp the 2nd of
     * these requests would already have died against a silent cap of 1.
     */
    public function testGarbageSettingValueFallsBackToDefaultAtTheRealGate(): void
    {
        App::settingsFactory()->forClinic(1)->set('otp.ip_hourly_max', 'abc');
        $this->resolverClinic = 1;

        $this->issue(self::IP_GARBAGE);
        $this->issue(self::IP_GARBAGE);
        $this->issue(self::IP_GARBAGE);
        self::assertSame(3, $this->ipHits(self::IP_GARBAGE));
    }
}
