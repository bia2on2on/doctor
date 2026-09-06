<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Update\ReleaseManifest;
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
