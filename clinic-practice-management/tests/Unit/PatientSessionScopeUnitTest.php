<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Application\Auth\PatientSessionScope;
use ClinicCore\Application\Scope\ScopeRequiredException;
use PHPUnit\Framework\TestCase;

final class PatientSessionScopeUnitTest extends TestCase
{
    public function testOtpCookieIssueStampsExactUserAndClearsAfterEnd(): void
    {
        $sessions = [
            10 => [
                'tok-otp-a' => ['expiration' => 9999999999],
                'tok-signon' => ['expiration' => 9999999999],
            ],
            20 => [
                'tok-other-user' => ['expiration' => 9999999999],
            ],
        ];
        $clinicsByUser = [
            10 => [101, 202],
            20 => [101, 202],
        ];

        $scope = new PatientSessionScope(
            null,
            static function (int $uid, ?string $token) use (&$sessions): ?array {
                if ($token === null || $token === '') {
                    return null;
                }
                return $sessions[$uid][$token] ?? null;
            },
            static fn (int $uid): array => $clinicsByUser[$uid] ?? [],
            static fn (): bool => false,
            static function (int $uid, string $token, int $clinicId) use (&$sessions): void {
                if (isset($sessions[$uid][$token])) {
                    $sessions[$uid][$token][PatientSessionScope::SESSION_CLINIC_KEY] = $clinicId;
                }
            }
        );

        // Armed for user 10, clinic 202: cookie for user 20 must NOT be stamped.
        $scope->begin_otp_cookie_issue(10, 202);
        $scope->on_set_logged_in_cookie('cookie-20', 0, 0, 20, 'logged_in', 'tok-other-user');
        self::assertArrayNotHasKey(PatientSessionScope::SESSION_CLINIC_KEY, $sessions[20]['tok-other-user']);
        self::assertNull($scope->resolve_clinic_id(20));

        // Cookie for armed user 10 IS stamped with clinic 202.
        $scope->on_set_logged_in_cookie('cookie-10', 0, 0, 10, 'logged_in', 'tok-otp-a');
        $scope->end_otp_cookie_issue();

        self::assertSame(202, $sessions[10]['tok-otp-a'][PatientSessionScope::SESSION_CLINIC_KEY] ?? null);
        self::assertSame(202, $scope->stamped_session_clinic_id(10));
        self::assertSame(202, $scope->resolve_clinic_id(10));
        self::assertSame(202, $scope->require_clinic_for_read(10));
        self::assertSame(202, $scope->require_clinic_for_write(10));
        self::assertSame(202, $scope->require_clinic_matching(10, 202));

        // A subsequent non-OTP wp_signon cookie for user 10 replaces the active token
        // without stamping cpms_clinic_id; >1 linked clinics now fails closed.
        $scope->on_set_logged_in_cookie('cookie-10-signon', 0, 0, 10, 'logged_in', 'tok-signon');
        self::assertArrayNotHasKey(PatientSessionScope::SESSION_CLINIC_KEY, $sessions[10]['tok-signon']);
        self::assertNull($scope->stamped_session_clinic_id(10));
        self::assertNull($scope->resolve_clinic_id(10));

        // Clearing auth cookie resets in-process token state.
        $scope->on_clear_auth_cookie();
        self::assertNull($scope->stamped_session_clinic_id(10));
    }

    public function testApplicationPasswordBypassesSessionMarkerAndUsesFallback(): void
    {
        $isAppPassword = true;
        $scope = new PatientSessionScope(
            null,
            static fn (): array => [PatientSessionScope::SESSION_CLINIC_KEY => 301],
            static fn (int $uid): array => $uid === 1 ? [401] : [301, 401],
            static function () use (&$isAppPassword): bool {
                return $isAppPassword;
            }
        );

        // Single-clinic user under application password falls back to sole clinic 401 (not marker 301).
        self::assertNull($scope->stamped_session_clinic_id(1));
        self::assertSame(401, $scope->resolve_clinic_id(1));

        // Multi-clinic user under application password fails closed.
        self::assertNull($scope->resolve_clinic_id(2));
        try {
            $scope->require_clinic_for_read(2);
            self::fail('Multi-clinic application-password read must fail closed.');
        } catch (ScopeRequiredException $e) {
            self::assertSame('CLINIC_SCOPE_REQUIRED', $e->apiCode());
            self::assertSame(400, $e->httpStatus());
        }
    }

    public function testZeroOneAndMultiClinicFallbackSemanticsAndIdenticalErrorEnvelope(): void
    {
        $clinicsByUser = [
            100 => [],           // 0 links, no marker
            101 => [55],         // 1 distinct clinic, no marker
            102 => [55, 55],     // 2 links in SAME clinic 55, no marker
            103 => [55, 77],     // 2 distinct clinics, no marker
        ];

        $scope = new PatientSessionScope(
            null,
            static fn (): ?array => null,
            static fn (int $uid): array => $clinicsByUser[$uid] ?? [],
            static fn (): bool => false
        );

        // 0 links: read returns null, write & matching throw CLINIC_SCOPE_REQUIRED.
        self::assertNull($scope->resolve_clinic_id(100));
        self::assertNull($scope->require_clinic_for_read(100));

        // 1 clinic (including multiple family patients in same clinic): resolves to 55.
        self::assertSame(55, $scope->resolve_clinic_id(101));
        self::assertSame(55, $scope->require_clinic_for_read(101));
        self::assertSame(55, $scope->require_clinic_for_write(101));
        self::assertSame(55, $scope->require_clinic_matching(101, 55));
        self::assertSame(55, $scope->resolve_clinic_id(102));
        self::assertSame(55, $scope->require_clinic_matching(102, 55));

        // >1 distinct clinics without marker: resolve returns null; read/write/matching all throw identical error.
        self::assertNull($scope->resolve_clinic_id(103));

        $captured = [];
        $failingCalls = [
            'zero_link_write'      => static fn () => $scope->require_clinic_for_write(100),
            'zero_link_matching'   => static fn () => $scope->require_clinic_matching(100, 55),
            'multi_clinic_read'    => static fn () => $scope->require_clinic_for_read(103),
            'multi_clinic_write'   => static fn () => $scope->require_clinic_for_write(103),
            'multi_clinic_match'   => static fn () => $scope->require_clinic_matching(103, 55),
            'clinic_mismatch'      => static fn () => $scope->require_clinic_matching(101, 77),
        ];

        foreach ($failingCalls as $label => $call) {
            try {
                $call();
                self::fail('Expected ScopeRequiredException for ' . $label);
            } catch (ScopeRequiredException $e) {
                self::assertSame('CLINIC_SCOPE_REQUIRED', $e->apiCode(), $label);
                self::assertSame(400, $e->httpStatus(), $label);
                self::assertSame([], $e->getData(), $label);
                self::assertSame(PatientSessionScope::SCOPE_REQUIRED_MESSAGE, $e->getMessage(), $label);
                self::assertStringNotContainsString('55', $e->getMessage(), $label);
                self::assertStringNotContainsString('77', $e->getMessage(), $label);
                $captured[] = [$e->apiCode(), $e->httpStatus(), $e->getMessage(), $e->getData()];
            }
        }

        // Every failure case produces byte-for-byte identical code/status/message/data.
        self::assertCount(6, $captured);
        foreach ($captured as $tuple) {
            self::assertSame($captured[0], $tuple);
        }
    }
}
