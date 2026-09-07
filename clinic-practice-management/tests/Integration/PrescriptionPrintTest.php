<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Clinical\ClinicalException;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * ADR-0031 / Part 2 — نمای چاپ نسخه (ممیزی P12).
 *
 *  - مسیر مجوز: cpms_rx_read + requireOwnVisit (ماتریس 4.3) — چاپ فقط برای
 *    پزشکِ همان ویزیت؛ پزشک دیگر → 404 (پاسخ ثابت auditAndThrow)؛ منشی → 403.
 *  - خروجی کامل: نسخه + اقلام + بیمار + پزشک + کلینیک + تاریخ جلالی + شکایت اصلی.
 *  - هر چاپ → Audit `PRESCRIPTION_PRINTED`.
 */
final class PrescriptionPrintTest extends WP_UnitTestCase
{
    private int $clinicianId;
    private int $otherClinicianId;
    private int $patientId;
    private int $secretaryUserId;
    private int $doctorUserId;
    private int $otherDoctorUserId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::settings()->set('queue.auto_enqueue', true);

        global $wpdb;
        $now = App::db()->nowUtcSql();

        $this->secretaryUserId = $this->makeUser('pp2_secretary', 'cpms_secretary');
        $this->doctorUserId = $this->makeUser('pp2_doctor', 'cpms_doctor');
        $this->otherDoctorUserId = $this->makeUser('pp2_doctor2', 'cpms_doctor');

        foreach ([['Dr Print One', $this->doctorUserId], ['Dr Print Two', $this->otherDoctorUserId]] as [$name, $wpId]) {
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, wp_user_id, specialty, is_active, created_at, updated_at)
                 VALUES (1, %s, %d, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $name,
                $wpId,
                'طب داخلی',
                $now,
                $now
            ));
            if ($name === 'Dr Print One') {
                $this->clinicianId = (int) $wpdb->insert_id;
            } else {
                $this->otherClinicianId = (int) $wpdb->insert_id;
            }
        }

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                 (clinic_id, mrn, first_name, last_name, mobile, birth_date, status, created_at, updated_at)
             VALUES (1, %s, "چاپ", "بیمار", %s, "1990-05-10", "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'MR-PP2-0001',
            '09126660001',
            $now,
            $now
        ));
        $this->patientId = (int) $wpdb->insert_id;
    }

    private function makeConsultation(): int
    {
        $visit = App::visitService()->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId);
        $id = (int) $visit['id'];
        App::visitService()->transition($this->doctorUserId, $id, 'call');
        App::visitService()->transition($this->doctorUserId, $id, 'start');

        return $id;
    }

    public function testOwnerDoctorGetsCompletePrintViewWithJalaliAndAudit(): void
    {
        $visitId = $this->makeConsultation();
        $clinical = App::clinicalService();
        $clinical->addNote($this->doctorUserId, $visitId, [
            'category' => 'chief_complaint',
            'visibility' => 'patient_visible',
            'content_text' => 'سردرد یک هفته‌ای',
        ]);
        $rx = $clinical->createPrescription($this->doctorUserId, $visitId, [
            'items' => [
                ['generic_name' => 'آمی‌تریپتیلین', 'strength' => '10mg', 'dose' => '1qHS', 'frequency' => 'شب‌ها'],
                ['generic_name' => 'استامینوفن', 'dose' => '500mg', 'frequency' => 'هر ۸ ساعت'],
            ],
        ]);
        $clinical->finalizePrescription($this->doctorUserId, (int) $rx['id']);

        $view = $clinical->prescriptionForPrint($this->doctorUserId, $visitId, (int) $rx['id']);

        $this->assertSame((int) $rx['id'], $view['prescription']['id']);
        $this->assertSame('finalized', $view['prescription']['status']);
        $this->assertCount(2, $view['prescription']['items']);
        $this->assertSame('آمی‌تریپتیلین', $view['prescription']['items'][0]['generic_name']);
        $this->assertSame('MR-PP2-0001', $view['patient']['mrn']);
        $this->assertSame('چاپ بیمار', $view['patient']['full_name']);
        $this->assertNotNull($view['patient']['age'], 'سن از birth_date محاسبه می‌شود');
        $this->assertSame('Dr Print One', $view['doctor']['name']);
        $this->assertSame('طب داخلی', $view['doctor']['specialty']);
        $this->assertSame('سردرد یک هفته‌ای', $view['chief_complaint']);
        $this->assertNotEmpty($view['visit_jalali'], 'تاریخ جلالی برای نسخه چاپی الزامی است');
        $this->assertNotSame($view['visit_jalali'], $view['visit_date']);
        $this->assertNotEmpty($view['printed_at_utc']);

        // Audit چاپ
        $printed = App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . " WHERE action = 'PRESCRIPTION_PRINTED'",
            []
        );
        $this->assertSame(1, (int) $printed);
    }

    public function testPrintWithoutExplicitRxUsesLatestNonVoided(): void
    {
        $visitId = $this->makeConsultation();
        $clinical = App::clinicalService();
        $first = $clinical->createPrescription($this->doctorUserId, $visitId, [
            'items' => [['generic_name' => 'دارو یک', 'dose' => '1', 'frequency' => 'روزانه']],
        ]);
        $clinical->voidPrescription($this->doctorUserId, (int) $first['id'], 'اشتباه دارویی');
        $second = $clinical->createPrescription($this->doctorUserId, $visitId, [
            'items' => [['generic_name' => 'دارو دو', 'dose' => '2', 'frequency' => 'روزانه']],
        ]);

        $view = $clinical->prescriptionForPrint($this->doctorUserId, $visitId);
        $this->assertSame((int) $second['id'], $view['prescription']['id'], 'نسخه ابطال‌شده برای چاپ انتخاب نمی‌شود');
    }

    public function testRxFromAnotherVisitIsRejected(): void
    {
        $visitId = $this->makeConsultation();
        $clinical = App::clinicalService();
        $rx = $clinical->createPrescription($this->doctorUserId, $visitId, [
            'items' => [['generic_name' => 'دارو', 'dose' => '1', 'frequency' => 'روزانه']],
        ]);

        $this->expectException(ClinicalException::class);
        $clinical->prescriptionForPrint($this->doctorUserId, $visitId + 999999, (int) $rx['id']);
    }

    public function testOtherDoctorIsDeniedOwnership(): void
    {
        $visitId = $this->makeConsultation();
        App::clinicalService()->createPrescription($this->doctorUserId, $visitId, [
            'items' => [['generic_name' => 'دارو', 'dose' => '1', 'frequency' => 'روزانه']],
        ]);

        $this->expectException(ClinicalException::class);
        // پاسخ ثابت 404 (نه افشای وجود) — الگوی requireOwnVisit
        App::clinicalService()->prescriptionForPrint($this->otherDoctorUserId, $visitId);
    }

    public function testSecretaryWithoutRxReadCapIsDenied(): void
    {
        $visitId = $this->makeConsultation();
        App::clinicalService()->createPrescription($this->doctorUserId, $visitId, [
            'items' => [['generic_name' => 'دارو', 'dose' => '1', 'frequency' => 'روزانه']],
        ]);

        try {
            App::clinicalService()->prescriptionForPrint($this->secretaryUserId, $visitId);
            $this->fail('منشی cpms_rx_read ندارد — باید 403 شود');
        } catch (ClinicalException $e) {
            $this->assertSame('CLINIC_PERMISSION_DENIED', $e->errorCode);
            $this->assertSame(403, $e->httpStatus);
        }
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login, 'pass-12345', $login . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }
}
