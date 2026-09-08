<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * رگرسیون 1f8b36d (طبقه‌بندی A) — پل به‌روزرسانی وردپرس باید Lazy و
 * Fail-Soft باشد:
 *
 *  - App::boot زنجیرهٔ wpUpdateBridge → updateService → settings → scope را
 *    «هنگام ثبت هوک» اجرا نمی‌کند؛ در بوتِ پیش از Migration (WP تازه،
 *    bootstrap تست) هیچ جدولی وجود ندارد و Resolution سیستمی Scope باید
 *    فقط داخل خود هوک انجام شود.
 *  - اگر در زمان اجرای هوک Scope قابل resolve نباشد (صفر یا چند Clinic،
 *    بدون Scope صریح)، هوک مقدار ورودی را دست‌نخورده برمی‌گرداند —
 *    به‌روزرسانی مسیر بحرانی نیست (best-effort؛ ADR-0029).
 *
 * AD-13: هیچ مسیر fallback به literal clinic_id=1 اضافه نمی‌شود.
 */
final class UpdateBridgeLazyTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
    }

    protected function tearDown(): void
    {
        App::resetScope();
        parent::tearDown();
    }

    public function testBridgeIsLazyAndFailSoftWithoutAnyClinic(): void
    {
        // شبیه‌سازی بوتِ نصب تازه: هنوز هیچ Clinicی seeded نشده
        // (جدول هست، ردیف نیست — دقیقاً حالت pre-Migration از نظر Resolver).
        $db = App::db();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->query('DELETE FROM ' . $db->table('cpms_clinics'));
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        $this->assertSame(
            0,
            (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics')),
            'پیش‌شرط تست: صفر Clinic'
        );

        // 1) ساخت Bridge (همان مسیر App::boot) نباید Scope را resolve کند —
        //    پیش از 1f8b36d این خط ScopeRequiredException می‌انداخت.
        $bridge = App::wpUpdateBridge();

        // 2) اجرای هوک در همین حالت: fail-soft، بدون exception، passthrough
        $transient = new \stdClass();
        $transient->response = [];
        $returned = $bridge->injectUpdatePlugins($transient);
        self::assertSame($transient, $returned, 'هوک به‌روزرسانی باید مقدار ورودی را دست‌نخورده برگرداند.');

        // 3) plugins_api هم fail-soft
        $args = new \stdClass();
        $args->slug = 'clinic-practice-management';
        $infoResult = $bridge->injectPluginInfo(null, 'plugin_information', $args);
        self::assertNull($infoResult, 'plugin_information بدون Scope قابل resolve باید null بماند.');
    }

    public function testBridgeResolvesLazilyWhenSingleClinicExists(): void
    {
        // حالت سالم نصب تک‌کلینیکی: Clinic تنها → Scope سیستمی resolve می‌شود
        // و Provider باید UpdateService بسازد بدون خطا (channel فقط خوانده می‌شود).
        $bridge = App::wpUpdateBridge();
        $transient = new \stdClass();
        $transient->response = [];

        // بدون Scope صریح؛ Resolver سیستمی count==1 را برمی‌گرداند.
        $returned = $bridge->injectUpdatePlugins($transient);
        self::assertSame($transient, $returned, 'هوک نباید روی transient سالم خراب کند.');
    }
}
