<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Licensing\EntitlementRegistry;
use PHPUnit\Framework\TestCase;

/**
 * F10 / ADR-0023 §4 (spec §17):
 *  - fail-closed برای کلیدهای ناشناخته/غایب
 *  - سقف‌ها و ساخت جدید
 *  - بدون کرش در برابر ساختار غیرمنتظره
 */
final class EntitlementRegistryTest extends TestCase
{
    public function testKnownFeatureEnabled(): void
    {
        $reg = new EntitlementRegistry(['features' => ['handwriting' => true, 'ocr' => false]]);
        $this->assertTrue($reg->hasFeature('handwriting'));
        $this->assertFalse($reg->hasFeature('ocr'));
    }

    public function testUnknownFeatureFailsClosed(): void
    {
        $reg = new EntitlementRegistry(['features' => ['handwriting' => true]]);
        // کلید آینده/ناشناخته → false، بدون استثنا/کرش
        $this->assertFalse($reg->hasFeature('ai_charting_future'));
        $this->assertFalse($reg->hasFeature(''));
    }

    public function testMissingEntitlementsBlockEverything(): void
    {
        $reg = new EntitlementRegistry();
        $this->assertFalse($reg->hasFeature('handwriting'));
        $this->assertNull($reg->limitOf('doctors'));
    }

    public function testLimitsAndCreationBound(): void
    {
        $reg = new EntitlementRegistry(['limits' => ['doctors' => 5]]);
        $this->assertSame(5, $reg->limitOf('doctors'));
        $this->assertTrue($reg->allowsCreation('doctors', 4));
        $this->assertFalse($reg->allowsCreation('doctors', 5));
        $this->assertTrue($reg->allowsCreation('unknown_limit', 999));
    }

    public function testMalformedRawStructureDoesNotCrash(): void
    {
        $reg = new EntitlementRegistry(['features' => 'not-an-array', 'limits' => 42]);
        $this->assertFalse($reg->hasFeature('handwriting'));
        $this->assertNull($reg->limitOf('doctors'));
    }
}
