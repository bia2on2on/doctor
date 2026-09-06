<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Infrastructure\Update\WpUpdateBridge;
use PHPUnit\Framework\TestCase;

/**
 * F10 — سازنده‌های خالص WpUpdateBridge (ADR-0029): ورودی transient وردپرس و
 * شیء plugins_api از نتیجهٔ UpdateService (بدون WP/I/O — فقط شکل داده).
 */
final class WpUpdateBridgeTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function available(): array
    {
        return [
            'available' => true,
            'version' => '1.1.0',
            'package_url' => 'https://updates.cpms.example/packages/cpms-1.1.0.zip',
            'package_sha256' => str_repeat('a', 64),
            'channel' => 'stable',
            'release_notes' => 'نسخهٔ امن',
            'checked_at' => 1_800_000_000,
        ];
    }

    public function testUpdateEntryHasWordPressShape(): void
    {
        $e = WpUpdateBridge::updateEntry($this->available(), 'clinic-practice-management/clinic-practice-management.php');

        $this->assertSame('clinic-practice-management', $e->slug);
        $this->assertSame('clinic-practice-management/clinic-practice-management.php', $e->plugin);
        $this->assertSame('1.1.0', $e->new_version);
        $this->assertSame('https://updates.cpms.example/packages/cpms-1.1.0.zip', $e->package);
        $this->assertSame('نسخهٔ امن', $e->url);
        $this->assertNotEmpty($e->requires);
        $this->assertNotEmpty($e->requires_php);
    }

    public function testPluginApiObjectHasDetailsShape(): void
    {
        $o = WpUpdateBridge::apiObject($this->available(), WpUpdateBridge::PLUGIN_SLUG);

        $this->assertSame('Clinic Practice Management (CPMS)', $o->name);
        $this->assertSame(WpUpdateBridge::PLUGIN_SLUG, $o->slug);
        $this->assertSame('1.1.0', $o->version);
        $this->assertSame('https://updates.cpms.example/packages/cpms-1.1.0.zip', $o->download_link);
        $this->assertSame('نسخهٔ امن', $o->sections['description']);
        $this->assertArrayHasKey('last_updated', (array) $o);
    }
}
