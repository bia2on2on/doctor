<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Settings\InstallationSettings;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * قرارداد RED — تنظیم‌های سطحِ نصبِ `notif.archive_days` و
 * `retention.oplog_days` (Phase 2 / M-2).
 *
 * تصمیم مصوب: purge سراسری اعلان‌ها یک sweep سطح نصب است، پس
 * `notif.archive_days` پیکربندی سطح نصب است — نه per-Clinic.
 *
 * این تست‌ها بدون وردپرس اجرا می‌شوند (Unit خالص): ذخیره‌سازی Option از
 * طریق تزریق صریح (`?callable` — همان الگوی `SystemHealthService::$now`)
 * شبیه‌سازی می‌شود و رفتار واقعی `autoload=no` روی وردپرس واقعی در تست
 * Integration متناظر اثبات می‌شود.
 */
final class InstallationSettingsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $store = [];

    /** @var list<string> */
    private array $readKeys = [];

    /** @var list<array{key: string, value: mixed}> */
    private array $writes = [];

    private function settings(): InstallationSettings
    {
        return new InstallationSettings(
            function (string $key, mixed $default): mixed {
                $this->readKeys[] = $key;

                return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
            },
            function (string $key, mixed $value): void {
                $this->writes[] = ['key' => $key, 'value' => $value];
                $this->store[$key] = $value;
            }
        );
    }

    public function testReadRequiresNoClinicScopeOrUser(): void
    {
        // بدون هیچ Clinic/ScopeContext/کاربری — حتی بدون تزریق ذخیره‌سازی.
        $settings = new InstallationSettings();

        $this->assertSame(
            InstallationSettings::DEFAULT_NOTIF_ARCHIVE_DAYS,
            $settings->getNotifArchiveDays(),
            'خواندن نباید به Clinic یا Scope یا کاربر نیاز داشته باشد.'
        );
    }

    public function testApiSurfaceHasNoClinicParameter(): void
    {
        $reflection = new ReflectionClass(InstallationSettings::class);

        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);
        foreach ($constructor->getParameters() as $parameter) {
            $name = strtolower((string) $parameter->getName());
            $this->assertStringNotContainsString('clinic', $name, 'سازنده نباید پارامتر Clinic بگیرد.');
            $this->assertStringNotContainsString('scope', $name, 'سازنده نباید پارامتر Scope بگیرد.');
            $this->assertTrue($parameter->isOptional(), 'سازنده باید بدون آرگومان قابل ساخت باشد.');
        }

        $getter = $reflection->getMethod('getNotifArchiveDays');
        $this->assertSame(0, $getter->getNumberOfParameters(), 'خواندن نباید هیچ ورودی بگیرد.');

        $oplogGetter = $reflection->getMethod('getOplogRetentionDays');
        $this->assertSame(0, $oplogGetter->getNumberOfParameters(), 'خواندنِ oplog هم نباید هیچ ورودی بگیرد.');
        $oplogSetter = $reflection->getMethod('setOplogRetentionDays');
        $this->assertSame(1, $oplogSetter->getNumberOfParameters(), 'نوشتنِ oplog فقط تعداد روز می‌گیرد.');
        $this->assertStringNotContainsStringIgnoringCase(
            'clinic',
            strtolower((string) $oplogSetter->getParameters()[0]->getName()),
            'نوشتنِ oplog نباید پارامتر Clinic بگیرد.'
        );
    }

    public function testDefaultPreservesExistingEffectiveBehaviorWhenOptionAbsent(): void
    {
        $this->assertSame([], $this->store, 'پیش‌شرط: Option ذخیره نشده است.');

        $days = $this->settings()->getNotifArchiveDays();

        $this->assertSame(90, $days, 'پیش‌فرض مؤثر فعلی (Settings::DEFAULTS) باید حفظ شود.');
        $this->assertSame(
            [InstallationSettings::OPTION_NOTIF_ARCHIVE_DAYS],
            $this->readKeys,
            'خواندن باید دقیقاً از کلید Option سطح نصب انجام شود.'
        );
    }

    public function testStoredValueIsInstallationWide(): void
    {
        $this->store[InstallationSettings::OPTION_NOTIF_ARCHIVE_DAYS] = 45;
        $settings = $this->settings();

        // همان نمونه، خواندن‌های پیاپی — هیچ Clinic فعالی در کار نیست.
        $asFirstClinic = $settings->getNotifArchiveDays();
        $asSecondClinic = $settings->getNotifArchiveDays();

        $this->assertSame(45, $asFirstClinic);
        $this->assertSame($asFirstClinic, $asSecondClinic, 'تعویض Clinic نباید مقدار سطح نصب را عوض کند.');
    }

    public function testNumericStringFromWpOptionsIsAccepted(): void
    {
        // ستون option_value متنی است؛ وردپرس اسکالر را رشته برمی‌گرداند.
        $this->store[InstallationSettings::OPTION_NOTIF_ARCHIVE_DAYS] = '30';

        $this->assertSame(30, $this->settings()->getNotifArchiveDays());
    }

    public function testMinimumBoundaryIsAccepted(): void
    {
        $this->store[InstallationSettings::OPTION_NOTIF_ARCHIVE_DAYS] = 1;

        $this->assertSame(1, $this->settings()->getNotifArchiveDays(), 'کف ۱ روز (هم‌تراز max(1,·) در purge) معتبر است.');
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function malformedProvider(): array
    {
        return [
            'non-numeric string' => ['abc'],
            'empty string' => [''],
            'null' => [null],
            'array' => [[]],
            'numeric prefix garbage' => ['10x'],
            'zero' => [0],
            'zero string' => ['0'],
            'negative' => [-5],
            'negative string' => ['-3'],
            'boolean' => [false],
        ];
    }

    #[DataProvider('malformedProvider')]
    public function testMalformedValuesFailSafeToDefault(mixed $raw): void
    {
        $this->store[InstallationSettings::OPTION_NOTIF_ARCHIVE_DAYS] = $raw;

        $this->assertSame(
            90,
            $this->settings()->getNotifArchiveDays(),
            'مقدار خرابِ ذخیره‌شده باید امن به پیش‌فرض برگردد (نه purge تهاجمی).'
        );
    }

    public function testSetPersistsThroughWriterAndReadsBack(): void
    {
        $settings = $this->settings();

        $settings->setNotifArchiveDays(45);

        $this->assertSame(
            [['key' => InstallationSettings::OPTION_NOTIF_ARCHIVE_DAYS, 'value' => 45]],
            $this->writes,
            'نوشتن باید دقیقاً یک‌بار با کلید سطح نصب و مقدار صحیح انجام شود.'
        );
        $this->assertSame(45, $settings->getNotifArchiveDays(), 'خواندن بعد از نوشتن باید مقدار جدید را بدهد.');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function nonPositiveProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-7],
        ];
    }

    #[DataProvider('nonPositiveProvider')]
    public function testSetRejectsNonPositiveDays(int $days): void
    {
        $settings = $this->settings();

        try {
            $settings->setNotifArchiveDays($days);
            $this->fail('نوشتن مقدار نامثبت باید رد شود.');
        } catch (InvalidArgumentException) {
            $this->assertSame([], $this->writes, 'نوشتنِ ردشده نباید به ذخیره‌سازی برسد.');
        }
    }

    public function testNoClinicIdRowIsEverCreatedOrUsed(): void
    {
        $settings = $this->settings();
        $settings->getNotifArchiveDays();
        $settings->setNotifArchiveDays(60);
        $settings->getNotifArchiveDays();
        $settings->getOplogRetentionDays();
        $settings->setOplogRetentionDays(30);
        $settings->getOplogRetentionDays();

        $touched = $this->readKeys;
        foreach ($this->writes as $write) {
            $touched[] = $write['key'];
        }

        $this->assertNotSame([], $touched, 'پیش‌شرط: عملیاتی روی ذخیره‌سازی انجام شده است.');
        foreach ($touched as $key) {
            $this->assertStringNotContainsStringIgnoringCase('clinic', $key, 'هیچ کلیدی نباید Clinic را لمس کند.');
            $this->assertContains(
                $key,
                [
                    InstallationSettings::OPTION_NOTIF_ARCHIVE_DAYS,
                    InstallationSettings::OPTION_OPLOG_RETENTION_DAYS,
                ],
                'تنها کلیدهای مجاز، Optionهای سطح نصب هستند.'
            );
        }
    }

    // -----------------------------------------------------------------
    // M-2 — `retention.oplog_days` (سطح نصب، کلید مستقل از notif)
    // -----------------------------------------------------------------

    public function testOplogDefaultPreservesExistingEffectiveBehaviorWhenOptionAbsent(): void
    {
        $this->assertSame([], $this->store, 'پیش‌شرط: Option ذخیره نشده است.');

        $days = $this->settings()->getOplogRetentionDays();

        $this->assertSame(90, $days, 'پیش‌فرض مؤثر فعلی (۹۰ روز) باید حفظ شود.');
        $this->assertSame(
            [InstallationSettings::OPTION_OPLOG_RETENTION_DAYS],
            $this->readKeys,
            'خواندن باید دقیقاً از کلید Option سطح نصبِ oplog انجام شود.'
        );
    }

    public function testOplogNumericScalarsFromWpOptionsAreAccepted(): void
    {
        $this->store[InstallationSettings::OPTION_OPLOG_RETENTION_DAYS] = 45;
        $this->assertSame(45, $this->settings()->getOplogRetentionDays());

        $this->store[InstallationSettings::OPTION_OPLOG_RETENTION_DAYS] = '30';
        $this->assertSame(30, $this->settings()->getOplogRetentionDays(), 'ستون Option متنی است.');

        $this->store[InstallationSettings::OPTION_OPLOG_RETENTION_DAYS] = 1;
        $this->assertSame(1, $this->settings()->getOplogRetentionDays(), 'کف ۱ روز معتبر است.');
    }

    public function testOplogIsStableWithoutAnyClinic(): void
    {
        $this->store[InstallationSettings::OPTION_OPLOG_RETENTION_DAYS] = 45;
        $settings = $this->settings();

        $this->assertSame(45, $settings->getOplogRetentionDays());
        $this->assertSame(45, $settings->getOplogRetentionDays(), 'هیچ Clinic فعالی در کار نیست.');
    }

    #[DataProvider('malformedProvider')]
    public function testOplogMalformedValuesFailSafeToDefault(mixed $raw): void
    {
        $this->store[InstallationSettings::OPTION_OPLOG_RETENTION_DAYS] = $raw;

        $this->assertSame(
            90,
            $this->settings()->getOplogRetentionDays(),
            'مقدار خرابِ ذخیره‌شده باید امن به پیش‌فرض برگردد (نه حذف تهاجمی‌تر).'
        );
    }

    public function testOplogSetPersistsThroughWriterAndReadsBack(): void
    {
        $settings = $this->settings();

        $settings->setOplogRetentionDays(120);

        $this->assertSame(
            [['key' => InstallationSettings::OPTION_OPLOG_RETENTION_DAYS, 'value' => 120]],
            $this->writes,
            'نوشتن باید دقیقاً یک‌بار با کلید سطح نصبِ oplog انجام شود.'
        );
        $this->assertSame(120, $settings->getOplogRetentionDays());
    }

    #[DataProvider('nonPositiveProvider')]
    public function testOplogSetRejectsNonPositiveDays(int $days): void
    {
        $settings = $this->settings();

        try {
            $settings->setOplogRetentionDays($days);
            $this->fail('نوشتن مقدار نامثبت باید رد شود.');
        } catch (InvalidArgumentException) {
            $this->assertSame([], $this->writes, 'نوشتنِ ردشده نباید به ذخیره‌سازی برسد.');
        }
    }

    public function testOplogKeyIsIndependentFromNotifKey(): void
    {
        $this->store[InstallationSettings::OPTION_NOTIF_ARCHIVE_DAYS] = 30;

        $settings = $this->settings();

        $this->assertSame(30, $settings->getNotifArchiveDays(), 'قرارداد notif نباید تغییر کند.');
        $this->assertSame(90, $settings->getOplogRetentionDays(), 'کلید oplog مستقل است.');
    }
}
