<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * TP-02 (لایه Idempotency) — Double Submit / Replay.
 */
final class IdempotencyTest extends WP_UnitTestCase
{
    private const KEY = '550e8400-e29b-41d4-a716-446655440000';

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
    }

    public function testFirstCallClaimsKey(): void
    {
        $r = App::idem()->check(self::KEY, '/invoices/1/payments', 10, 1);
        $this->assertFalse($r['is_replay']);
        $this->assertNull($r['response']);
    }

    public function testReplayReturnsStoredResponseWithoutDuplicate(): void
    {
        $idem = App::idem();
        $idem->check(self::KEY, '/invoices/1/payments', 10, 1);
        $idem->complete(self::KEY, '/invoices/1/payments', 10, 1, 201, ['payment_id' => 99, 'number' => 'PAY-1']);

        $r = $idem->check(self::KEY, '/invoices/1/payments', 10, 1);
        $this->assertTrue($r['is_replay']);
        $this->assertSame(201, $r['response_code']);
        $this->assertSame(99, $r['response']['payment_id']);

        global $wpdb;
        $count = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_idempotency_keys WHERE `key` = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::KEY
            )
        );
        $this->assertSame(1, (int) $count, 'Replay نباید رکورد جدید بسازد');
    }

    public function testReleaseAllowsRetry(): void
    {
        $idem = App::idem();
        $idem->check(self::KEY, '/invoices/1/payments', 10, 1);
        $idem->release(self::KEY, '/invoices/1/payments', 10, 1);

        $r = $idem->check(self::KEY, '/invoices/1/payments', 10, 1);
        $this->assertFalse($r['is_replay'], 'بعد از release، تلاش مجدد باید Claim تازه بگیرد');
    }

    public function testDifferentContextsAreIndependent(): void
    {
        $idem = App::idem();
        $idem->check(self::KEY, '/invoices/1/payments', 10, 1);
        $r = $idem->check(self::KEY, '/invoices/2/payments', 10, 2);
        $this->assertFalse($r['is_replay'], 'کلید در Context مختلف نباید Replay باشد');
    }

    public function testInFlightReturns409(): void
    {
        $idem = App::idem();
        $idem->check(self::KEY, '/invoices/1/payments', 10, 1); // pending
        $r = $idem->check(self::KEY, '/invoices/1/payments', 10, 1);

        $this->assertTrue($r['is_replay']);
        $this->assertSame(409, $r['response_code'], 'Request موازی → 409');
    }
}
