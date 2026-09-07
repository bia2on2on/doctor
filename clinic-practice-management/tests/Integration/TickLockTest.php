<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * F1-7 — قفل یکپارچهٔ Tick (رگرسیون).
 *
 * پیش‌تر GET_LOCK فقط در مسیر CLI بود و WP-Cron بدون قفل Tick می‌کرد →
 * Runnerهای دو SAPI می‌توانستند هم‌زمان اجرا شوند. اکنون قفل داخل
 * `App::runTick()` است؛ این تست با «اتصال دوم» (الگوی تست ۱۰۰-راهی)
 * رفتار Skip/آزادسازی را روی مسیر واقعی MySQL ثابت می‌کند.
 */
final class TickLockTest extends WP_UnitTestCase
{
    /** @var \wpdb اتصال دوم — شبیه‌سازی Runner در SAPI/فرآیند دیگر */
    private \wpdb $other;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        global $wpdb;
        $this->other->set_prefix($wpdb->prefix);
    }

    protected function tearDown(): void
    {
        // اطمینان: قفل آزاد باشد (تست بعدی را نگيرد)
        $this->other->query('SELECT RELEASE_LOCK(\'' . App::TICK_LOCK . '\')');
        parent::tearDown();
    }

    public function testRunTickSkipsWhenAnotherConnectionHoldsLock(): void
    {
        $jobId = (int) App::jobs()->enqueue('cleanup.otp'); // Handler واقعیِ ثبت‌شده

        $got = $this->other->get_var('SELECT GET_LOCK(\'' . App::TICK_LOCK . '\', 0)');
        $this->assertSame('1', (string) $got, 'اتصال دوم باید بتواند قفل را بگیرد');

        $this->assertSame(-1, App::runTick(5), 'Tick باید Skip شود (Runner دیگر فعال است)');
        $this->assertSame('queued', $this->statusOf($jobId), 'Job نباید توسط Tickِ Skip‌شده پردازش شود');
        $this->assertTrue(App::isTickLocked(), 'قفل بیرونی باید دیده شود');
    }

    public function testRunTickReleasesLockAfterProcessing(): void
    {
        $n = App::runTick(5);

        $this->assertNotSame(-1, $n, 'بدون قفل بیرونی، Tick باید اجرا شود');
        $this->assertGreaterThanOrEqual(0, $n);

        $free = $this->other->get_var('SELECT IS_FREE_LOCK(\'' . App::TICK_LOCK . '\')');
        $this->assertSame('1', (string) $free, 'قفل باید در finally آزاد شده باشد (از دید اتصال دوم)');
    }

    public function testIsTickLockedReflectsExternalLock(): void
    {
        $this->assertFalse(App::isTickLocked(), 'بدون Runner دیگر، قفل آزاد است');

        $this->other->query('SELECT GET_LOCK(\'' . App::TICK_LOCK . '\', 0)');
        $this->assertTrue(App::isTickLocked());

        $this->other->query('SELECT RELEASE_LOCK(\'' . App::TICK_LOCK . '\')');
        $this->assertFalse(App::isTickLocked());
    }

    private function statusOf(int $jobId): string
    {
        global $wpdb;

        return (string) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT status FROM ' . $wpdb->prefix . 'cpms_jobs WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $jobId
            )
        );
    }
}
