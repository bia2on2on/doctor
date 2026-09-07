<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\CpmsSetupWizard;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * Chunk B — راه‌اندازی گام‌به‌گام (Setup Wizard).
 *
 * پوشش:
 *  - ثبت زیرمنوی «راه‌اندازی» تحت منوی «مدیریت مطب».
 *  - ذخیرهٔ گام «کلینیک» (Atomic + persist + resumable).
 *  - ذخیرهٔ گام «رزرو» (bounded/sanitized).
 *  - اعتبارسنجی: رد Nonce نامعتبر (CSRF) و رد کاربر بدون Capability (wp_die → WPDieException).
 *  - کامل‌نشدن «شروع عملیات» تا وقتی پیش‌نیازهای الزامی برآورده نشده‌اند.
 *  - تکمیل و تنظیم `setup.completed = true` فقط وقتی آمادهٔ بهره‌برداری است.
 */
final class SetupWizardTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
    }

    protected function tearDown(): void
    {
        Settings::flushCache();
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function testWizardSubmenuRegisteredUnderCpmsMenu(): void
    {
        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];

        CpmsSetupWizard::menu();

        $sub = $GLOBALS['submenu']['cpms-dashboard'] ?? [];
        $slugs = array_map(static fn ($r) => $r[2] ?? '', $sub);

        $this->assertContains(CpmsSetupWizard::PAGE_SLUG, $slugs, 'زیرمنوی «راه‌اندازی» باید تحت «مدیریت مطب» ثبت شود');
    }

    public function testSaveClinicPersists(): void
    {
        $adminId = $this->authorizeConfigUser();
        $err = CpmsSetupWizard::saveClinic(App::settings(), [
            'clinic_name' => 'کلینیک آزمایشی',
            'clinic_address' => 'خیابان آزادی',
            'clinic_phone' => '02112345678',
            'clinic_timezone' => 'Asia/Tehran',
        ], $adminId);

        $this->assertSame('', $err, 'گام معتبر کلینیک نباید خطا بدهد');
        $s = App::settings();
        $this->assertSame('کلینیک آزمایشی', $s->get('setup.clinic.name'));
        $this->assertSame('خیابان آزادی', $s->get('setup.clinic.address'));
        $this->assertSame('02112345678', $s->get('setup.clinic.phone'));
        $this->assertSame('Asia/Tehran', $s->get('setup.clinic.timezone'));
    }

    public function testSaveClinicRejectsEmptyName(): void
    {
        $adminId = $this->authorizeConfigUser();
        $err = CpmsSetupWizard::saveClinic(App::settings(), [
            'clinic_name' => '   ',
        ], $adminId);

        $this->assertStringContainsString('نام کلینیک الزامی است.', $err);
        $this->assertSame('', (string) App::settings()->get('setup.clinic.name', ''), 'نباید مقدار تهی ذخیره شود');
    }

    public function testSaveClinicRejectsOverlongName(): void
    {
        $adminId = $this->authorizeConfigUser();
        $err = CpmsSetupWizard::saveClinic(App::settings(), [
            'clinic_name' => str_repeat('ت', 200),
        ], $adminId);

        $this->assertStringContainsString('حداکثر ۱۹۰ کاراکتر', $err);
    }

    public function testSaveClinicFallsBackTimezone(): void
    {
        $adminId = $this->authorizeConfigUser();
        CpmsSetupWizard::saveClinic(App::settings(), [
            'clinic_name' => 'کلینیک',
            'clinic_timezone' => 'invalid/tz',
        ], $adminId);

        $this->assertSame('Asia/Tehran', App::settings()->get('setup.clinic.timezone'));
    }

    public function testSaveBookingBoundsValues(): void
    {
        $adminId = $this->authorizeConfigUser();
        $err = CpmsSetupWizard::saveBooking(App::settings(), [
            'duration' => 9999, // خارج از بازه → کلمپ به سقف
            'future' => -5,     // خارج از بازه → کلمپ به کف
        ], $adminId);

        $this->assertSame('', $err);
        $s = App::settings();
        $this->assertSame(240, (int) $s->get('booking.duration_default_min'));
        $this->assertSame(1, (int) $s->get('booking.max_future_days'));
    }

    public function testSaveSmsPersistsOnlyWhenProvided(): void
    {
        $adminId = $this->authorizeConfigUser();
        CpmsSetupWizard::saveSms(App::settings(), [
            'sms_provider' => 'generic_api',
            'sms_sender' => 'CLINIC',
        ], $adminId);

        $this->assertSame('generic_api', App::settings()->get('sms.provider'));
        $this->assertSame('CLINIC', App::settings()->get('sms.sender'));
    }

    public function testWizardCompletesWhenPrerequisitesMet(): void
    {
        $adminId = $this->authorizeConfigUser();
        $this->createActiveClinician();

        CpmsSetupWizard::saveClinic(App::settings(), ['clinic_name' => 'کلینیک آماده'], $adminId);
        $err = $this->invokeSaveFinish($adminId);

        $this->assertSame('', $err, 'وقتی پیش‌نیازها برقرار است نباید خطا بدهد');
        $this->assertTrue((bool) App::settings()->get(CpmsSetupWizard::COMPLETE_KEY, false), 'باید «آمادهٔ بهره‌برداری» ثبت شود.');
    }

    public function testWizardDoesNotCompleteWithoutClinician(): void
    {
        $adminId = $this->authorizeConfigUser();
        CpmsSetupWizard::saveClinic(App::settings(), ['clinic_name' => 'کلینیک بدون پزشک'], $adminId);

        $err = $this->invokeSaveFinish($adminId);

        $this->assertStringContainsString('پیش‌نیازهای الزامی', $err);
        $this->assertFalse((bool) App::settings()->get(CpmsSetupWizard::COMPLETE_KEY, false));
    }

    public function testWizardDoesNotCompleteWithoutClinicName(): void
    {
        $adminId = $this->authorizeConfigUser();
        // بدون نام کلینیک، حتی با پزشک فعال → نباید تکمیل شود.
        $this->createActiveClinician();

        $err = $this->invokeSaveFinish($adminId);

        $this->assertStringContainsString('پیش‌نیازهای الزامی', $err);
        $this->assertFalse((bool) App::settings()->get(CpmsSetupWizard::COMPLETE_KEY, false));
    }

    // ================= handlers (capability / CSRF) =================

    public function testSaveRejectsInvalidNonce(): void
    {
        $this->authorizeConfigUser();
        $_POST = [
            '_wpnonce' => 'invalid-nonce-value',
            'action'   => 'cpms_wizard_save',
            'step'     => 'clinic',
            'clinic_name' => 'نام',
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('اعتبارسنجی ناموفق');
        $this->captureWpDie(function (): void {
            CpmsSetupWizard::save();
        });
    }

    public function testSaveRejectsCapabilitylessUser(): void
    {
        $user = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user);

        $_POST = [
            '_wpnonce' => wp_create_nonce('cpms_wizard_save'),
            'action'   => 'cpms_wizard_save',
            'step'     => 'clinic',
            'clinic_name' => 'نام',
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('اعتبارسنجی ناموفق');
        $this->captureWpDie(function (): void {
            CpmsSetupWizard::save();
        });
    }

    public function testSaveRejectsInvalidStep(): void
    {
        $this->authorizeConfigUser();
        $_POST = [
            '_wpnonce' => wp_create_nonce('cpms_wizard_save'),
            'action'   => 'cpms_wizard_save',
            'step'     => 'not-a-step',
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('گام نامعتبر');
        $this->captureWpDie(function (): void {
            CpmsSetupWizard::save();
        });
    }

    public function testSaveAdvancesToNextStepOnSuccess(): void
    {
        $this->authorizeConfigUser();
        $_POST = [
            '_wpnonce' => wp_create_nonce('cpms_wizard_save'),
            'action'   => 'cpms_wizard_save',
            'step'     => 'clinic',
            'clinic_name' => 'کلینیک گام‌به‌گام',
            'clinic_timezone' => 'Asia/Tehran',
        ];

        $location = $this->captureRedirect(function (): void {
            CpmsSetupWizard::save();
        });

        $this->assertStringContainsString('cpms-wizard', $location, 'پس از گام غیرپایانی باید به ویزارد برگردد');
        $this->assertSame('booking', App::settings()->get('setup.current_step'), 'پس از «کلینیک» باید «رزرو» باشد');
    }

    public function testSaveFinishRedirectsToDashboardOnSuccess(): void
    {
        $adminId = $this->authorizeConfigUser();
        $this->createActiveClinician();
        CpmsSetupWizard::saveClinic(App::settings(), ['clinic_name' => 'کلینیک نهایی'], $adminId);

        $_POST = [
            '_wpnonce' => wp_create_nonce('cpms_wizard_save'),
            'action'   => 'cpms_wizard_save',
            'step'     => 'finish',
        ];

        $location = $this->captureRedirect(function (): void {
            CpmsSetupWizard::save();
        });

        $this->assertStringContainsString('cpms-dashboard', $location, 'پس از تکمیل باید به داشبورد برود');
        $this->assertTrue((bool) App::settings()->get(CpmsSetupWizard::COMPLETE_KEY, false));
    }

    // ================= helpers =================

    private function authorizeConfigUser(): int
    {
        $id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($id);
        $role = (new \WP_User($id))->get_role();
        if ($role !== null) {
            // تضمین مجوز در DB تست، حتی اگر نقش پیش‌فرض هنوز map نشده باشد.
            $role->add_cap('cpms_config');
            $role->add_cap('cpms_sms_config');
        }

        return $id;
    }

    private function createActiveClinician(): void
    {
        // الگوی اثبات‌شدهٔ ClinicianRepositoryTest فقط با full_name؛ is_active پیش‌فرض 1 است.
        App::clinicianRepository()->create(['full_name' => 'دکتر آزمایشی']);
    }

    /**
     * چون saveFinish خصوصی است، از طریق Reflector اجرا می‌شود تا «آمادهٔ بهره‌برداری»
     * به‌صورت قطعی و بدون وابستگی به exit بررسی شود.
     */
    private function invokeSaveFinish(int $updatedBy): string
    {
        $method = new \ReflectionMethod(CpmsSetupWizard::class, 'saveFinish');
        $method->setAccessible(true);

        return (string) $method->invoke(null, App::settings(), $updatedBy);
    }

    /**
     * اجرای handler در مسیر `wp_die` (رد CSRF/Capability) با تبدیل die به استثنا،
     * تا تست بدون متوقف‌شدن process و مستقل از رفتار پیش‌فرض test-suite وردپرس بگذرد.
     *
     * @param callable():void $fn
     *
     * @throws \RuntimeException
     */
    private function captureWpDie(callable $fn): void
    {
        // اولویت بالا تا روی هر handler دیگری (framework) غلبه کند.
        add_filter('wp_die_handler', static function (): callable {
            return static function (string $message): void {
                throw new \RuntimeException($message);
            };
        }, PHP_INT_MAX);
        $fn();
    }

    /**
     * اجرای handler با تبدیل wp_safe_redirect به استثنا و گرفتن مقصد، تا از `exit`
     * در مسیر موفق جلوگیری شود. در صورت عدم رسیدن به redirect (مسیر ناموفق)،
     * استثنا اصلی مجدداً پرتاب می‌شود.
     *
     * @param callable():void $fn
     */
    private function captureRedirect(callable $fn): string
    {
        $location = '';
        add_filter('wp_redirect', static function (string $loc) use (&$location): string {
            $location = $loc;
            throw new \RuntimeException('REDIRECT::' . $loc);
        }, 1);

        try {
            $fn();
        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'REDIRECT::')) {
                return $location;
            }
            throw $e;
        }

        return $location;
    }
}
