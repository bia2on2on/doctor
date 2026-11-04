<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Application\Clinical\FileStorageFailure;
use ClinicCore\Infrastructure\Storage\StorageConfigurationException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Item 2 — Medical-file storage failures must be traceable and actionable.
 *
 * A storage failure (e.g. CLINIC_STORAGE_NOT_WRITABLE from the private-storage
 * initializer family) previously surfaced as an untraceable generic 500 with
 * no operational log row. The mapping now produces:
 *  - one scalar op-log context (root + failure code + trimmed reason), and
 *  - one client error reusing the REGISTERED CLINIC_INTERNAL_ERROR/500 pair
 *    with an actionable Persian message, never leaking filesystem paths.
 */
final class FileStorageFailureTest extends TestCase
{
    public function testOpContextCarriesTypedStorageErrorCode(): void
    {
        $e = new StorageConfigurationException(
            'CLINIC_STORAGE_NOT_WRITABLE',
            'ساخت پوشهٔ ذخیره‌سازی بالینی ممکن نشد: /srv/private/clinic-files',
            '/srv/private/clinic-files'
        );

        $context = FileStorageFailure::op_context('files', $e);

        self::assertSame('files', $context['root']);
        self::assertSame('CLINIC_STORAGE_NOT_WRITABLE', $context['failure']);
        self::assertIsString($context['reason']);
        self::assertLessThanOrEqual(160, mb_strlen($context['reason']));
        foreach ($context as $value) {
            self::assertTrue(is_scalar($value), 'op-log context must stay scalar');
        }
    }

    public function testOpContextForUntypedThrowableUsesExceptionClass(): void
    {
        $context = FileStorageFailure::op_context('files', new RuntimeException('disk full'));

        self::assertSame(RuntimeException::class, $context['failure']);
        self::assertSame('disk full', $context['reason']);
    }

    public function testClientExceptionReusesRegisteredCodeAndNeverLeaksPaths(): void
    {
        $upload = FileStorageFailure::client_exception('ثبت فایل انجام نشد');
        $read   = FileStorageFailure::client_exception('باز کردن فایل انجام نشد');

        // Both code and HTTP status are already registered in
        // docs/api/error-codes.md (CLINIC_INTERNAL_ERROR → 500); no new code.
        self::assertSame('CLINIC_INTERNAL_ERROR', $upload->errorCode);
        self::assertSame(500, $upload->httpStatus);
        self::assertSame('CLINIC_INTERNAL_ERROR', $read->errorCode);
        self::assertSame(500, $read->httpStatus);

        // Actionable for a staff/patient user: contact the clinic admin and
        // consult the system health page.
        self::assertStringContainsString('مدیر کلینیک', $upload->getMessage());
        self::assertStringContainsString('سلامت سیستم', $upload->getMessage());
        self::assertStringStartsWith('ثبت فایل انجام نشد', $upload->getMessage());
        self::assertStringStartsWith('باز کردن فایل انجام نشد', $read->getMessage());

        // No filesystem detail ever reaches the REST client.
        self::assertStringNotContainsString('/', $upload->getMessage());
        self::assertStringNotContainsString('storage', $upload->getMessage());
    }
}
