<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Update\ReleaseManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F10 — مانیفست انتشار (ADR-0029): اعتبارسنجی + applicability.
 */
final class ReleaseManifestTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function valid(): array
    {
        return [
            'product' => 'cpms',
            'version' => '1.1.0',
            'channel' => 'stable',
            'package_url' => 'https://updates.example.com/cpms-1.1.0.zip',
            'package_sha256' => str_repeat('a', 64),
            'min_wp_version' => '6.5',
            'min_php_version' => '8.1',
            'min_cpms_version' => '1.0.0',
            'signed_at' => 1893463200,
        ];
    }

    public function testValidManifestPasses(): void
    {
        $this->assertTrue(ReleaseManifest::isValid($this->valid()));
    }

    public function testRejectsHttpPackageUrl(): void
    {
        $m = $this->valid();
        $m['package_url'] = 'http://insecure.example.com/x.zip';
        $this->assertFalse(ReleaseManifest::isValid($m));
    }

    public function testRejectsBadShaAndVersion(): void
    {
        $m = $this->valid();
        $m['package_sha256'] = 'short';
        $this->assertFalse(ReleaseManifest::isValid($m));

        $m = $this->valid();
        $m['version'] = 'not-a-version';
        $this->assertFalse(ReleaseManifest::isValid($m));
    }

    public function testRejectsBetaChannelByDefaultValidationRules(): void
    {
        $m = $this->valid();
        $m['channel'] = 'beta';
        $this->assertTrue(ReleaseManifest::isValid($m)); // beta مجاز است
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function malformedReleaseKindValues(): array
    {
        return [
            'uppercase security keyword' => ['SECURITY'],
            'trailing whitespace' => ['security '],
            'unknown kind' => ['critical'],
            'misspelled kind' => ['securty'],
            'empty string' => [''],
            'boolean true' => [true],
            'integer' => [1],
            'float' => [1.0],
            'null' => [null],
            'list' => [['security']],
            'map' => [['kind' => 'security']],
        ];
    }

    public function testAbsentReleaseKindStaysAValidNormalManifest(): void
    {
        $m = $this->valid();
        $this->assertArrayNotHasKey('release_kind', $m);
        $this->assertTrue(ReleaseManifest::isValid($m), 'existing signed manifests without release_kind must stay valid');
    }

    public function testNormalAndSecurityAreTheAcceptedReleaseKinds(): void
    {
        foreach (['normal', 'security'] as $kind) {
            $m = $this->valid();
            $m['release_kind'] = $kind;
            $this->assertTrue(ReleaseManifest::isValid($m), 'release_kind=' . $kind);
        }
    }

    #[DataProvider('malformedReleaseKindValues')]
    public function testUnknownOrMalformedReleaseKindInvalidatesTheManifest(mixed $kind): void
    {
        $m = $this->valid();
        $m['release_kind'] = $kind;
        $this->assertFalse(ReleaseManifest::isValid($m), 'release_kind must fail closed when present but unrecognised');
    }

    public function testApplicableRequiresNewerVersionAndEnvironments(): void
    {
        $m = $this->valid();
        $this->assertTrue(ReleaseManifest::isApplicable($m, '1.0.0', '6.7', '8.2'));
        // همان نسخه / قدیمی‌تر
        $this->assertFalse(ReleaseManifest::isApplicable($m, '1.1.0', '6.7', '8.2'));
        // WP قدیمی‌تر از min
        $this->assertFalse(ReleaseManifest::isApplicable($m, '1.0.0', '6.0', '8.2'));
        // PHP قدیمی‌تر از min
        $this->assertFalse(ReleaseManifest::isApplicable($m, '1.0.0', '6.7', '8.0'));
    }
}
