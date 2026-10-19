<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\CpmsAssets;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * Phase 4 — Persian (Jalali) date UX asset wiring.
 *
 * The companion display is local-only (no CDN/network dependency) and
 * scoped exactly like every other CPMS admin asset: allowlisted CPMS pages
 * only, never a global wp-admin asset.
 */
final class CpmsJalaliDateAssetsTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        unset($_GET['page']);
        wp_dequeue_script('cpms-jalali-date');
        wp_dequeue_style('cpms-jalali-date');
        wp_set_current_user(0);
        App::resetScope();
        parent::tearDown();
    }

    public function testJalaliDateAssetsExistLocallyWithoutAnyNetworkUrl(): void
    {
        $jsPath  = \dirname(__DIR__, 2) . '/assets/js/cpms-jalali-date.js';
        $cssPath = \dirname(__DIR__, 2) . '/assets/css/cpms-jalali-date.css';
        self::assertFileExists($jsPath);
        self::assertFileExists($cssPath);

        $js = (string) file_get_contents($jsPath);
        foreach (['http://', 'https://', '//cdn', 'googleapis'] as $networkMarker) {
            self::assertStringNotContainsString($networkMarker, $js, 'the Jalali date script must have zero network dependency: ' . $networkMarker);
        }
    }

    public function testJalaliDateAssetsEnqueueOnlyOnAllowlistedCpmsPages(): void
    {
        foreach (['cpms-wizard', 'cpms-clinicians', 'cpms-staff'] as $slug) {
            wp_dequeue_script('cpms-jalali-date');
            wp_dequeue_style('cpms-jalali-date');
            $_GET['page'] = $slug;

            CpmsAssets::enqueue();
            self::assertTrue(wp_script_is('cpms-jalali-date', 'enqueued'), "cpms-jalali-date.js must be enqueued on {$slug}.");
            self::assertTrue(wp_style_is('cpms-jalali-date', 'enqueued'), "cpms-jalali-date.css must be enqueued on {$slug}.");
        }

        wp_dequeue_script('cpms-jalali-date');
        wp_dequeue_style('cpms-jalali-date');
        $_GET['page'] = 'cpms-not-a-page';
        CpmsAssets::enqueue();
        self::assertFalse(wp_script_is('cpms-jalali-date', 'enqueued'), 'an unrelated admin page must never load the Jalali date asset.');
        self::assertFalse(wp_style_is('cpms-jalali-date', 'enqueued'));
    }
}
