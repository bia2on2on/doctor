<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\InstallationSettings;
use WP_UnitTestCase;

/**
 * قرارداد RED — تنظیمِ سطحِ نصبِ `notif.archive_days` روی وردپرس واقعی.
 *
 * اثبات‌هایی که فقط با وردپرس واقعی معنا دارند:
 *  - خواندن/نوشتن بدون هیچ Scope/کاربری کار می‌کند؛
 *  - مقدار با تعویض Clinic واقعی عوض نمی‌شود؛
 *  - Option با `autoload=no` ذخیره می‌شود؛
 *  - هیچ ردیف `clinic_id` در `cpms_settings` ساخته/لمس نمی‌شود.
 *
 * پاک‌سازی: Option در tearDown حذف و حذف‌شدنش assert می‌شود.
 */
final class InstallationSettingsTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        App::resetScope();
        delete_option(InstallationSettings::OPTION_NOTIF_ARCHIVE_DAYS);
    }

    protected function tearDown(): void
    {
        delete_option(InstallationSettings::OPTION_NOTIF_ARCHIVE_DAYS);
        $this->assertFalse(
            get_option(InstallationSettings::OPTION_NOTIF_ARCHIVE_DAYS, false),
            'پاک‌سازی: Option سطح نصب نباید بعد از تست بماند.'
        );
        App::resetScope();
        parent::tearDown();
    }

    public function testDefaultReadWorksWithoutAnyScopeOrUser(): void
    {
        App::resetScope();
        $this->assertNull(ScopeContext::tryGet(), 'پیش‌شرط: هیچ Scope صریحی نباید فعال باشد.');

        $days = (new InstallationSettings())->getNotifArchiveDays();

        $this->assertSame(90, $days, 'پیش‌فرض مؤثر فعلی باید بدون Scope هم خوانده شود.');
    }

    public function testSetGetRoundTripStoresAutoloadNoAndTouchesNoClinicRow(): void
    {
        global $wpdb;
        $settingsTable = $wpdb->prefix . 'cpms_settings';
        $before = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            "SELECT COUNT(*) FROM {$settingsTable} WHERE `key` = 'notif.archive_days'"
        );

        (new InstallationSettings())->setNotifArchiveDays(30);

        $this->assertSame(30, (new InstallationSettings())->getNotifArchiveDays(), 'خواندن بعد از نوشتن.');
        $this->assertEquals(
            30,
            get_option(InstallationSettings::OPTION_NOTIF_ARCHIVE_DAYS, false),
            'مقدار باید در wp_options واقعی ذخیره شده باشد.'
        );
        $autoload = $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
            InstallationSettings::OPTION_NOTIF_ARCHIVE_DAYS
        ));
        // وردپرس 6.6 به بعد 'no' را به 'off' نرمال می‌کند — هر دو یعنی «autoload نشود».
        $this->assertContains(
            $autoload,
            ['no', 'off'],
            'Option سطح نصب نباید autoload شود.'
        );
        $after = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            "SELECT COUNT(*) FROM {$settingsTable} WHERE `key` = 'notif.archive_days'"
        );
        $this->assertSame($before, $after, 'هیچ ردیف clinic_id نباید ساخته شده باشد.');
    }

    public function testValueIsStableAcrossRealClinicScopes(): void
    {
        global $wpdb;
        (new InstallationSettings())->setNotifArchiveDays(45);

        App::resetScope();
        $this->assertSame(45, (new InstallationSettings())->getNotifArchiveDays(), 'خواندن بدون Scope.');

        // شناسه‌ها فقط از دیتابیس واقعی خوانده می‌شوند — بدون شناسهٔ ثابت/ساختگی.
        $ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            "SELECT id FROM {$wpdb->prefix}cpms_clinics ORDER BY id ASC LIMIT 5"
        );
        $this->assertNotEmpty($ids, 'پیش‌شرط: دست‌کم یک Clinic واقعی (seed مهاجرت) باید باشد.');
        foreach ($ids as $id) {
            ScopeContext::set(ClinicScope::forClinic((int) $id));
            $this->assertSame(
                45,
                (new InstallationSettings())->getNotifArchiveDays(),
                'تعویض Clinic واقعی نباید مقدار سطح نصب را عوض کند.'
            );
        }
    }

    public function testMalformedPersistedValueFailsSafeToDefault(): void
    {
        update_option(InstallationSettings::OPTION_NOTIF_ARCHIVE_DAYS, 'abc', false);

        $this->assertSame(
            90,
            (new InstallationSettings())->getNotifArchiveDays(),
            'مقدار خرابِ ذخیره‌شده باید امن به پیش‌فرض برگردد.'
        );
    }
}
