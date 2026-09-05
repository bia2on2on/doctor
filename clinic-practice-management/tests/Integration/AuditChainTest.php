<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * TP-11 — Audit: Hash Chain، Masking، تشخیص جعل.
 */
final class AuditChainTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
    }

    public function testLogCreatesVerifiableChain(): void
    {
        $audit = App::audit();

        $audit->log('TEST_EVENT_A', ['wp_user_id' => 1, 'role' => 'system'], 'test_resource', 1, null, null, null);
        $audit->log('TEST_EVENT_B', ['wp_user_id' => 2, 'role' => 'clinic_doctor'], 'test_resource', 2, 7, ['a' => 1], ['a' => 2]);

        $result = $audit->verifyChain(1000);
        $this->assertTrue($result['ok']);
        $this->assertGreaterThanOrEqual(2, $result['checked']);
    }

    public function testForbiddenKeysAreStripped(): void
    {
        $audit = App::audit();
        $audit->log(
            'TEST_EVENT_SECRET',
            ['wp_user_id' => 1, 'role' => 'system'],
            'otp',
            null,
            null,
            ['otp_code' => '123456', 'password' => 'hunter2', 'mobile' => '09121234567', 'note' => 'ok'],
            null
        );

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT before_json FROM ' . $wpdb->prefix . 'cpms_audit_logs WHERE action = %s ORDER BY id DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'TEST_EVENT_SECRET'
            ),
            ARRAY_A
        );
        $json = (string) $row['before_json'];
        $decoded = json_decode($json, true);

        $this->assertArrayNotHasKey('otp_code', $decoded, 'OTP در Audit ممنوع است');
        $this->assertArrayNotHasKey('password', $decoded, 'رمز در Audit ممنوع است');
        $this->assertSame('***4567', $decoded['mobile'], 'موبایل باید Mask شود');
        $this->assertSame('ok', $decoded['note']);
    }

    public function testTamperingBreaksChain(): void
    {
        $audit = App::audit();
        $audit->log('CHAIN_TEST_A', ['wp_user_id' => 1, 'role' => 'system'], 't', 1, null, null, null);
        $audit->log('CHAIN_TEST_B', ['wp_user_id' => 1, 'role' => 'system'], 't', 2, null, null, null);

        $this->assertTrue($audit->verifyChain(1000)['ok']);

        // جعل مستقیم در DB (شبیه‌سازی دسترسی غیرمجاز)
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . $wpdb->prefix . 'cpms_audit_logs SET action = %s WHERE action = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'CHAIN_TEST_B_TAMPERED',
                'CHAIN_TEST_B'
            )
        );

        $result = $audit->verifyChain(1000);
        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['broken_at']);
    }

    public function testChangeSetIsStored(): void
    {
        App::audit()->log(
            'PATIENT_UPDATE',
            ['wp_user_id' => 5, 'role' => 'clinic_secretary'],
            'patient',
            42,
            42,
            ['address' => 'خیابان قدیم'],
            ['address' => 'خیابان جدید']
        );

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT before_json, after_json, resource_id, patient_id FROM ' . $wpdb->prefix . 'cpms_audit_logs WHERE action = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'PATIENT_UPDATE'
            ),
            ARRAY_A
        );
        $this->assertSame(42, (int) $row['resource_id']);
        $this->assertSame('خیابان قدیم', json_decode((string) $row['before_json'], true)['address']);
        $this->assertSame('خیابان جدید', json_decode((string) $row['after_json'], true)['address']);
    }
}
