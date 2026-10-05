<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Update\UpdateRightsBoundary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Phase 16 Slice 6B — محمولِ خالصِ «حقوقِ نسخه» (بدون WP/DB/شبکه/ساعت).
 *
 * قرارداد: مقایسهٔ عددِ صحیحِ امضاشده، سازگاریِ «بدونِ ادعا»، fail-closedِ
 * signed_atِ غیرِقابل‌استفاده، و استثنای `release_kind=security` که فقط و فقط
 * برای انتشارِ بعد از مرز و فقط برای ردهٔ شناخته‌شدهٔ امضاشده اعمال می‌شود.
 */
final class UpdateRightsBoundaryTest extends TestCase
{
    private const BOUNDARY = 1893463200;

    /**
     * @return array<string, mixed>
     */
    private function manifest(int $signedAt): array
    {
        return [
            'product' => 'cpms',
            'version' => '9.9.9',
            'channel' => 'stable',
            'package_url' => 'https://updates.example.com/cpms-9.9.9.zip',
            'package_sha256' => str_repeat('d', 64),
            'signed_at' => $signedAt,
        ];
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function nonBoundaryClaims(): array
    {
        return [
            'absent' => [null],
            'zero' => [0],
            'negative' => [-1],
            'numeric string' => ['1893463200'],
            'float' => [1893463200.5],
            'boolean' => [true],
            'list' => [[]],
        ];
    }

    #[DataProvider('nonBoundaryClaims')]
    public function testAbsentOrUnusableClaimStaysUnbounded(mixed $claim): void
    {
        $boundary = UpdateRightsBoundary::from_claim($claim);

        $this->assertSame('none', $boundary->fingerprint());
        // بدونِ مرز هیچ تصمیمی محدود نمی‌شود — سازگاریِ کاملِ رفتارِ فعلی.
        $this->assertNull($boundary->denial_reason($this->manifest(self::BOUNDARY + 86400)));
        $this->assertNull(UpdateRightsBoundary::unbounded()->denial_reason($this->manifest(self::BOUNDARY)));
    }

    public function testPositiveIntegerClaimBecomesTheBoundaryFingerprint(): void
    {
        $this->assertSame('until-' . self::BOUNDARY, UpdateRightsBoundary::from_claim(self::BOUNDARY)->fingerprint());
        $this->assertSame('until-' . PHP_INT_MAX, UpdateRightsBoundary::from_claim(PHP_INT_MAX)->fingerprint());
    }

    /**
     * @return array<string, array{0: int, 1: string|null}>
     */
    public static function ordinaryPublicationTimes(): array
    {
        return [
            'before the boundary' => [self::BOUNDARY - 1, null],
            'exactly at the boundary' => [self::BOUNDARY, null],
            'one second after the boundary' => [self::BOUNDARY + 1, UpdateRightsBoundary::REASON_RIGHTS_EXPIRED],
            'long after the boundary' => [self::BOUNDARY + 86400, UpdateRightsBoundary::REASON_RIGHTS_EXPIRED],
        ];
    }

    #[DataProvider('ordinaryPublicationTimes')]
    public function testOrdinaryReleaseIsDeniedOnlyStrictlyAfterTheBoundary(int $signedAt, ?string $expected): void
    {
        $manifest = $this->manifest($signedAt);
        $manifest['release_kind'] = 'normal';

        $this->assertSame($expected, UpdateRightsBoundary::from_claim(self::BOUNDARY)->denial_reason($manifest));
    }

    public function testSignedSecurityReleasePassesTheBoundary(): void
    {
        $manifest = $this->manifest(self::BOUNDARY + 1);
        $manifest['release_kind'] = 'security';

        $this->assertNull(
            UpdateRightsBoundary::from_claim(self::BOUNDARY)->denial_reason($manifest),
            'security is the only exception to the ordinary publication boundary'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unrecognisedSecurityShapes(): array
    {
        return [
            'uppercase' => ['SECURITY'],
            'trailing whitespace' => ['security '],
            'misspelled' => ['securty'],
            'unknown' => ['critical'],
        ];
    }

    #[DataProvider('unrecognisedSecurityShapes')]
    public function testUnrecognisedSecurityShapeNeverSelectsTheException(string $kind): void
    {
        $manifest = $this->manifest(self::BOUNDARY + 1);
        $manifest['release_kind'] = $kind;

        $this->assertSame(
            UpdateRightsBoundary::REASON_RIGHTS_EXPIRED,
            UpdateRightsBoundary::from_claim(self::BOUNDARY)->denial_reason($manifest),
            'only the exact signed enum value may use the security exception'
        );
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unusableSignedAtShapes(): array
    {
        return [
            'absent' => [null],
            'numeric string' => ['1893463200'],
            'float' => [1893463200.5],
            'boolean' => [true],
            'list' => [[]],
        ];
    }

    #[DataProvider('unusableSignedAtShapes')]
    public function testBoundedDecisionFailsClosedWithoutARealIntegerSignedAt(mixed $signedAt): void
    {
        $manifest = $this->manifest(self::BOUNDARY + 1);
        if ($signedAt === null) {
            unset($manifest['signed_at']);
        } else {
            $manifest['signed_at'] = $signedAt;
        }
        $manifest['release_kind'] = 'security';

        $this->assertSame(
            UpdateRightsBoundary::REASON_INVALID_MANIFEST,
            UpdateRightsBoundary::from_claim(self::BOUNDARY)->denial_reason($manifest),
            'a bounded decision requires a real integer publication timestamp'
        );
        $this->assertNull(
            UpdateRightsBoundary::unbounded()->denial_reason($manifest),
            'without a claim the manifest shape stays the structural check concern'
        );
    }
}
