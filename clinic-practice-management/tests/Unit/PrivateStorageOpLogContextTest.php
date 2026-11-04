<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Infrastructure\Storage\PrivateStorageInitializer;
use PHPUnit\Framework\TestCase;

/**
 * Item 1 — CPMS_PRIVATE_STORAGE_INIT_FAILED must keep its detail.
 *
 * Before the fix the warning was logged with context ['roots' => array], and
 * OpLogger::sanitize() degraded every non-scalar to gettype(), so the row read
 * {"roots":"array"} and the real error code was lost. The storage path now
 * flattens one scalar keyset per root via PrivateStorageInitializer::
 * op_log_context() — without touching OpLogger itself.
 */
final class PrivateStorageOpLogContextTest extends TestCase
{
    /**
     * OpLogger::sanitize() masks any key containing one of these substrings;
     * key names must never match or the flattened values would be masked again.
     */
    private const OPLOGGER_MASKED_KEY_PATTERN =
        '/(mobile|phone|national_id|otp|code|password|token|secret|card|content|text)/i';

    public function testContextIsFlatAndCarriesTheRealErrorCodes(): void
    {
        $roots = [
            'files'   => [
                'path'       => '/var/sites/example/private/clinic-files',
                'ok'         => false,
                'created'    => false,
                'guards'     => [],
                'error_code' => 'CLINIC_STORAGE_NOT_WRITABLE',
                'error'      => str_repeat('اشکال در ساخت پوشه. ', 30),
            ],
            'backups' => [
                'path'       => '/var/sites/example/private/backups',
                'ok'         => true,
                'created'    => true,
                'guards'     => ['.htaccess'],
                'error_code' => '',
                'error'      => '',
            ],
        ];

        $context = PrivateStorageInitializer::op_log_context($roots);

        foreach ($context as $value) {
            self::assertTrue(
                is_scalar($value),
                'every context value must be scalar (else sanitize() degrades it)'
            );
        }

        // The real machine error codes must appear in the logged row.
        self::assertFalse($context['files_ok']);
        self::assertSame('CLINIC_STORAGE_NOT_WRITABLE', $context['files_failure']);
        self::assertSame('/var/sites/example/private/clinic-files', $context['files_path']);
        self::assertTrue($context['backups_ok']);
        self::assertSame('', $context['backups_failure']);

        // Error text survives trimmed to a safe length.
        self::assertSame(160, mb_strlen($context['files_reason']));
        self::assertSame('', $context['backups_reason']);
    }

    public function testKeyNamesStayOutsideTheOpLoggerMaskList(): void
    {
        $context = PrivateStorageInitializer::op_log_context([
            'files'   => [],
            'backups' => [],
            'Odd Root' => [],
        ]);

        self::assertNotSame([], $context);
        foreach (array_keys($context) as $key) {
            self::assertDoesNotMatchRegularExpression(
                self::OPLOGGER_MASKED_KEY_PATTERN,
                (string) $key,
                'key must never collide with OpLogger::sanitize() masking'
            );
        }
    }

    public function testNonArrayRootRowsAreFlattenedDefensively(): void
    {
        $context = PrivateStorageInitializer::op_log_context(['files' => 'broken']);

        self::assertFalse($context['files_ok']);
        self::assertSame('', $context['files_failure']);
        self::assertSame('', $context['files_reason']);
        self::assertSame('', $context['files_path']);
    }
}
