<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * TP-14 — Patient Profile (F3): D4 Create (MRN خودکار) + D5 Update (balini)
 * + C1/C2 (بیمار — Whitelist) + Data-Access.
 */
final class PatientFlowTest extends WP_UnitTestCase
{
    private int $secretaryUserId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();

        $this->secretaryUserId = (int) wp_create_user(
            'secretary_1',
            'pass-12345',
            'sec1@test.local',
            ['role' => 'cpms_secretary']
        );
    }

    private function service()
    {
        return App::patientService();
    }

    public function testCreateWithMrnAndNormalization(): void
    {
        $created = $this->service()->create([
            'first_name' => 'رضا',
            'last_name' => 'تست',
            'mobile' => '+989121112233',
            'gender' => 'male',
            'birth_date' => '1985-04-01',
        ], $this->secretaryUserId);

        $this->assertMatchesRegularExpression('/^MR-\d{6}-[A-Z0-9]{5}$/', $created['mrn']);
        $this->assertSame('09121112233', $created['mobile'], 'Mobile باید Normalized شود');
        $this->assertSame('active', $created['status']);
    }

    public function testCreateValidation(): void
    {
        // Mobile نامعتبر
        $this->expectException(BookingException::class);
        try {
            $this->service()->create([
                'first_name' => 'تست',
                'last_name' => 'تست',
                'mobile' => '12345',
            ], $this->secretaryUserId);
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            throw $e;
        }
    }

    public function testCreateDuplicateMobileRejected(): void
    {
        $this->service()->create([
            'first_name' => 'اول',
            'last_name' => 'بیمار',
            'mobile' => '09122223344',
        ], $this->secretaryUserId);

        $this->expectException(BookingException::class);
        try {
            $this->service()->create([
                'first_name' => 'دوم',
                'last_name' => 'بیمار',
                'mobile' => '09122223344',
            ], $this->secretaryUserId);
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            throw $e;
        }
    }

    public function testStaffUpdateAllowsClinicalFields(): void
    {
        $created = $this->service()->create([
            'first_name' => 'مریم',
            'last_name' => 'آزمایشی',
            'mobile' => '09125556677',
        ], $this->secretaryUserId);

        $updated = $this->service()->update((int) $created['id'], [
            'medical_history' => 'دیابت نوع ۲ — تحت پیگیری',
            'medication_allergies' => ['پنی‌سیلین'],
            'blood_group' => 'A+',
        ], $this->secretaryUserId);

        $this->assertSame('دیابت نوع ۲ — تحت پیگیری', $updated['medical_history']);
        $this->assertSame(['پنی‌سیلین'], $updated['medication_allergies']);
        $this->assertSame('A+', $updated['blood_group']);
    }

    public function testUpdateRejectsInvalidNationalId(): void
    {
        $created = $this->service()->create([
            'first_name' => 'علی',
            'last_name' => 'تست',
            'mobile' => '09127778899',
        ], $this->secretaryUserId);

        $this->expectException(BookingException::class);
        try {
            $this->service()->update((int) $created['id'], [
                'national_id' => '1234567890', // Control Digit نامعتبر
            ], $this->secretaryUserId);
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            throw $e;
        }
    }

    public function testSelfServiceWhitelistBlocksClinicalFields(): void
    {
        // بیمار (خودکار) — فقط فیلدهای ME_EDITABLE
        $wpUserId = (int) wp_create_user('patient_me', 'pass-12345', 'pme@test.local', ['role' => 'cpms_patient']);
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, "سارا", "خود", %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-SELF-0001',
                '09123334455',
                $now,
                $now
            )
        );
        $patientId = (int) $wpdb->insert_id;
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patient_user_links
                     (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at)
                 VALUES (1, %d, %d, %s, 1, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $patientId,
                $wpUserId,
                '09123334455',
                $now
            )
        );

        $result = $this->service()->updateMe($wpUserId, [
            'first_name' => 'سارا',
            'medical_history' => 'حرفه‌ای تزریق فیلد بالینی نیست!', // باید نادیده گرفته شود
        ]);

        $this->assertSame('سارا', $result['first_name']);
        $this->assertArrayNotHasKey('medical_history', $result, 'C2 View فیلد بالینی ندارد');

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT medical_history FROM ' . $wpdb->prefix . 'cpms_patients WHERE id = %d', $patientId), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
        $this->assertNull($row['medical_history'], 'فیلد خارج Whitelist نباید ذخیره شود');
    }

    public function testSearchAndGet(): void
    {
        $created = $this->service()->create([
            'first_name' => 'کاوه',
            'last_name' => 'جستجوگر',
            'mobile' => '09129991122',
        ], $this->secretaryUserId);

        $results = $this->service()->search('کاوه');
        $this->assertCount(1, $results);
        $this->assertSame($created['id'], $results[0]['id']);

        $full = $this->service()->get($created['id']);
        $this->assertSame('کاوه', $full['first_name']);
        $this->assertSame('09129991122', $full['mobile']);
    }

    public function testGetUnknownPatient404(): void
    {
        $this->expectException(BookingException::class);
        try {
            $this->service()->get(999999);
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_NOT_FOUND', $e->errorCode);
            $this->assertSame(404, $e->httpStatus);
            throw $e;
        }
    }
}
