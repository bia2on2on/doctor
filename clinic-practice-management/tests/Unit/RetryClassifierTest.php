<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Sms\RetryClassifier;
use ClinicCore\Domain\Sms\SmsTemplateException;
use ClinicCore\Infrastructure\Sms\SmsSendException;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0025 §20 — Retry Policy: فقط خطاهای موقت Retry می‌شوند (بدون Blind Retry).
 */
final class RetryClassifierTest extends TestCase
{
    public function testRetryableProviderErrors(): void
    {
        $this->assertSame(
            RetryClassifier::RETRYABLE,
            RetryClassifier::classify(new SmsSendException('timeout', true, 'CLINIC_SMS_PROVIDER_UNREACHABLE'))
        );
        $this->assertSame(
            RetryClassifier::RETRYABLE,
            RetryClassifier::classify(new SmsSendException('503', true, 'CLINIC_SMS_PROVIDER_ERROR'))
        );
        $this->assertSame(
            RetryClassifier::RETRYABLE,
            RetryClassifier::classify(new SmsSendException('429', true, 'CLINIC_SMS_RATE_LIMITED'))
        );
    }

    public function testPermanentErrorsNeverRetry(): void
    {
        $this->assertSame(
            RetryClassifier::PERMANENT,
            RetryClassifier::classify(new SmsSendException('invalid mobile', false, 'CLINIC_SMS_INVALID_MOBILE'))
        );
        $this->assertSame(
            RetryClassifier::PERMANENT,
            RetryClassifier::classify(new SmsSendException('bad template', false, 'CLINIC_SMS_TEMPLATE_INVALID'))
        );
        $this->assertSame(
            RetryClassifier::PERMANENT,
            RetryClassifier::classify(new SmsSendException('bad key', false, 'SMS_AUTH_INVALID'))
        );
        $this->assertSame(
            RetryClassifier::PERMANENT,
            RetryClassifier::classify(new SmsSendException('no credit', false, 'CLINIC_SMS_NO_CREDIT'))
        );
    }

    public function testTemplateErrorsArePermanent(): void
    {
        $this->assertSame(
            RetryClassifier::PERMANENT,
            RetryClassifier::classify(new SmsTemplateException('CLINIC_SMS_MISSING_VARIABLE', 'متغیر لازم نیست'))
        );
    }

    public function testUnknownExceptionDefaultsRetryable(): void
    {
        $this->assertSame(
            RetryClassifier::RETRYABLE,
            RetryClassifier::classify(new \RuntimeException('boom'))
        );
    }
}
