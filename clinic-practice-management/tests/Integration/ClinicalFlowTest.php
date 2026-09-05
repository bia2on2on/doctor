<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Clinical\ClinicalException;
use ClinicCore\Bootstrap\App;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * تست‌های بالینی F5 — E7–E15 + C5/C6/C7 + امنیت.
 *
 * پوشش:
 *  - E8/E9 Notes: ساخت/Correction با نسخه‌بندی append-only (FR-8.5)
 *  - **TP-08:** بیمار/منشی هرگز doctor_private نمی‌بینند (سطح Query + API)
 *  - TP-07/IDOR: بیمار B روی ویزیت بیمار A → 404 + Audit FORBIDDEN
 *  - E10/E11 نسخه دارویی: Draft → Finalize + تکرار → 409 + «ویزیت خودش»
 *  - E12/E13 توصیه + Follow-up (Validation)
 *  - E14 پایان ویزیت با Validation Chief Complaint (FR-8.7) + E15 Reopen (FR-8.8)
 *  - E7 پرونده کامل (فقط پزشک — منشی 403)
 *  - C5/C6/C7 نمای بیمار: فقط patient_visible / غیر-draft
 */
final class ClinicalFlowTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    private int $clinicianId;
    private int $otherClinicianId;
    private int $patientAId;
    private int $patientBId;
    private int $patientAUser;
    private int $patientBUser;
    private int $secretaryUserId;
    private int $doctorUserId;
    private int $otherDoctorUserId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::settings()->set('queue.auto_enqueue', true);
        App::settings()->set('clinical.require_chief_complaint', true);

        global $wpdb;
        $now = App::db()->nowUtcSql();

        $this->secretaryUserId = $this->makeUser('cf_secretary', 'cpms_secretary');
        $this->doctorUserId = $this->makeUser('cf_doctor', 'cpms_doctor');
        $this->otherDoctorUserId = $this->makeUser('cf_doctor2', 'cpms_doctor');

        // پزشک‌ها — با اتصال wp_user_id (الزام «ویزیت خودش» ماتریس 4.3)
        foreach ([['Dr Clinical One', $this->doctorUserId], ['Dr Clinical Two', $this->otherDoctorUserId]] as [$name, $wpId]) {
            $wpdb->query(
                $wpdb->prepare(
                    'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                         (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                     VALUES (1, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $name,
                    $wpId,
                    $now,
                    $now
                )
            );
            if (str_starts_with($name, 'Dr Clinical One')) {
                $this->clinicianId = (int) $wpdb->insert_id;
            } else {
                $this->otherClinicianId = (int) $wpdb->insert_id;
            }
        }

        // بیمارها + کاربران متصل (P-5 Ownership)
        $patients = [
            ['MR-CF-0001', '09125550001', 'A', 'cf_patient_a'],
            ['MR-CF-0002', '09125550002', 'B', 'cf_patient_b'],
        ];
        foreach ($patients as [$mrn, $mobile, $tag, $login]) {
            $wpdb->query(
                $wpdb->prepare(
                    'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                         (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                     VALUES (1, %s, %s, "T", %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $mrn,
                    $tag,
                    $mobile,
                    $now,
                    $now
                )
            );
            $patientId = (int) $wpdb->insert_id;
            $userId = $this->makeUser($login, 'cpms_patient');
            $wpdb->query(
                $wpdb->prepare(
                    'INSERT INTO ' . $wpdb->prefix . 'cpms_patient_user_links
                         (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at)
                     VALUES (1, %d, %d, %s, 1, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $patientId,
                    $userId,
                    $mobile,
                    $now
                )
            );
            if ($tag === 'A') {
                $this->patientAId = $patientId;
                $this->patientAUser = $userId;
            } else {
                $this->patientBId = $patientId;
                $this->patientBUser = $userId;
            }
        }
    }

    // ================= E8/E9 — Notes + نسخه‌بندی =================

    public function testDoctorCreatesVisibleAndPrivateNotes(): void
    {
        $visitId = $this->makeConsultation($this->patientAId);

        $visible = $this->clinical()->addNote($this->doctorUserId, $visitId, [
            'category' => 'chief_complaint',
            'visibility' => 'patient_visible',
            'content_text' => 'سردرد از سه روز پیش',
        ]);
        $this->assertSame(1, $visible['version']);
        $this->assertSame('patient_visible', $visible['visibility']);

        $private = $this->clinical()->addNote($this->doctorUserId, $visitId, [
            'category' => 'private_note',
            'visibility' => 'doctor_private',
            'content_text' => 'مشکوک به اضطراب — پیگیری خانوادگی',
        ]);
        $this->assertSame('doctor_private', $private['visibility']);
    }

    public function testPrivateNoteCategoryCannotBePatientVisible(): void
    {
        $visitId = $this->makeConsultation($this->patientAId);

        try {
            $this->clinical()->addNote($this->doctorUserId, $visitId, [
                'category' => 'private_note',
                'visibility' => 'patient_visible',
                'content_text' => 'x',
            ]);
            $this->fail('Expected CLINIC_VALIDATION_FAILED');
        } catch (ClinicalException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
        }
    }

    public function testNoteCorrectionKeepsPreviousVersionAppendOnly(): void
    {
        $visitId = $this->makeConsultation($this->patientAId);
        $note = $this->clinical()->addNote($this->doctorUserId, $visitId, [
            'category' => 'diagnosis',
            'visibility' => 'patient_visible',
            'content_text' => 'نسخه اول تشخیص',
        ]);

        $updated = $this->clinical()->updateNote($this->doctorUserId, (int) $note['id'], [
            'content_text' => 'تشخیص اصلاح‌شده',
            'change_reason' => 'بازبینی نتایج آزمایش',
        ]);

        $this->assertSame(2, $updated['version']);
        $this->assertSame('تشخیص اصلاح‌شده', $updated['content_text']);
        $this->assertSame(1, $updated['versions'], 'one snapshot row exists');

        // FR-8.5/K-6: نسخه قبلی در جدول append-only حفظ شده — بی‌ silent overwrite
        global $wpdb;
        $snapshots = $wpdb->get_col($wpdb->prepare(
            'SELECT content_snapshot FROM ' . $wpdb->prefix . 'cpms_clinical_note_versions WHERE note_id = %d ORDER BY version',
            $note['id']
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->assertSame(['نسخه اول تشخیص'], $snapshots);
        $this->assertSame('تشخیص اصلاح‌شده', $wpdb->get_var($wpdb->prepare(
            'SELECT content_text FROM ' . $wpdb->prefix . 'cpms_clinical_notes WHERE id = %d',
            $note['id']
        ))); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        // Correction دوم → نسخه ۳ + دومین Snapshot
        $again = $this->clinical()->updateNote($this->doctorUserId, (int) $note['id'], [
            'content_text' => 'تشخیص نهایی',
            'change_reason' => 'اصلاح مجدد',
        ]);
        $this->assertSame(3, $again['version']);
        $this->assertSame(2, $again['versions']);
    }

    public function testNoteUpdateRequiresChangeReason(): void
    {
        $visitId = $this->makeConsultation($this->patientAId);
        $note = $this->clinical()->addNote($this->doctorUserId, $visitId, [
            'category' => 'history',
            'visibility' => 'patient_visible',
            'content_text' => 'سابقه',
        ]);

        try {
            $this->clinical()->updateNote($this->doctorUserId, (int) $note['id'], ['content_text' => 'بدون دلیل']);
            $this->fail('Expected CLINIC_VALIDATION_FAILED (change_reason required)');
        } catch (ClinicalException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
        }
    }

    public function testDoctorCannotUpdateNoteOfAnotherDoctor(): void
    {
        $visitId = $this->makeConsultation($this->patientAId);
        $note = $this->clinical()->addNote($this->doctorUserId, $visitId, [
            'category' => 'clinical_note',
            'visibility' => 'patient_visible',
            'content_text' => 'یادداشت پزشک یک',
        ]);

        try {
            $this->clinical()->updateNote($this->otherDoctorUserId, (int) $note['id'], [
                'content_text' => 'تغییر توسط پزشک دیگر',
                'change_reason' => 'دلیل',
            ]);
            $this->fail('Expected CLINIC_NOT_FOUND (only author can update)');
        } catch (ClinicalException $e) {
            $this->assertSame('CLINIC_NOT_FOUND', $e->errorCode);
            $this->assertSame(404, $e->httpStatus);
        }

        // Audit ثبت شده (FR-21.1)
        $audit = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND resource_type = %s AND resource_id = %d',
            ['FORBIDDEN_ACCESS_ATTEMPT', 'note', (int) $note['id']]
        );
        $this->assertNotNull($audit);
    }

    public function testSecretaryCannotCreateNotes(): void
    {
        $visitId = $this->makeConsultation($this->patientAId);

        try {
            $this->clinical()->addNote($this->secretaryUserId, $visitId, [
                'category' => 'clinical_note',
                'visibility' => 'patient_visible',
                'content_text' => 'یادداشت منشی',
            ]);
            $this->fail('Expected CLINIC_PERMISSION_DENIED');
        } catch (ClinicalException $e) {
            $this->assertSame('CLINIC_PERMISSION_DENIED', $e->errorCode);
        }
    }

    // ================= TP-08 — جداسازی doctor_private =================

    public function testPatientNeverSeesPrivateNotesInVisitDetail(): void
    {
        $visitId = $this->makeConsultation($this->patientAId);
        $this->clinical()->addNote($this->doctorUserId, $visitId, [
            'category' => 'chief_complaint',
            'visibility' => 'patient_visible',
            'content_text' => 'سردرد',
        ]);
        $this->clinical()->addNote($this->doctorUserId, $visitId, [
            'category' => 'private_note',
            'visibility' => 'doctor_private',
            'content_text' => 'SECRET-PRIVATE-MARKER',
        ]);

        // سطح سرویس/Query (FR-8.4)
        $detail = $this->clinical()->patientVisitDetail($this->patientAUser, $visitId);
        $this->assertCount(1, $detail['notes']);
        $this->assertSame('patient_visible', $detail['notes'][0]['visibility']);
        $this->assertStringNotContainsString(
            'SECRET-PRIVATE-MARKER',
            (string) json_encode($detail, JSON_UNESCAPED_UNICODE)
        );

        // سطح API (REST dispatch واقعی)
        wp_set_current_user($this->patientAUser);
        $response = $this->dispatch('GET', self::NS . '/visits/' . $visitId);
        $this->assertSame(200, $response->get_status());
        $body = (string) json_encode($response->get_data(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('SECRET-PRIVATE-MARKER', $body);
        $notes = $response->get_data()['data']['notes'] ?? null;
        $this->assertIsArray($notes);
        $this->assertCount(1, $notes);
    }

    public function testSecretaryCannotAccessClinicalRecordEndpoint(): void
    {
        $visitId = $this->makeConsultation($this->patientAId);

        wp_set_current_user($this->secretaryUserId);
        $response = $this->dispatch('GET', self::NS . '/visits/' . $visitId . '/record');
        $this->assertSame(403, $response->get_status());
        $this->assertSame('CLINIC_PERMISSION_DENIED', $response->get_data()['code'] ?? null);
    }

    public function testPatientEndpointRejectsNonPatientRole(): void
    {
        $visitId = $this->makeConsultation($this->patientAId);

        // منشی حتی نقش بیمار هم ندارد → 403 (لایه نقش) — TP-08
        wp_set_current_user($this->secretaryUserId);
        $response = $this->dispatch('GET', self::NS . '/visits/' . $visitId);
        $this->assertSame(403, $response->get_status());
    }

    // ================= TP-07/IDOR — بیمار روی داده بیمار دیگر =================

    public function testPatientCannotAccessAnotherPatientsClinicalData(): void
    {
        $visitA = $this->makeConsultation($this->patientAId);
        $visitB = $this->makeConsultation($this->patientBId);
        $this->clinical()->addNote($this->doctorUserId, $visitB, [
            'category' => 'chief_complaint',
            'visibility' => 'patient_visible',
            'content_text' => 'داده بالینی بیمار B',
        ]);

        // بیمار A → ویزیت بیمار B (سطح API)
        wp_set_current_user($this->patientAUser);
        $response = $this->dispatch('GET', self::NS . '/visits/' . $visitB);
        $this->assertSame(404, $response->get_status());
        $this->assertSame('CLINIC_NOT_FOUND', $response->get_data()['code'] ?? null);

        // سطح سرویس + Audit FORBIDDEN
        try {
            $this->clinical()->patientVisitDetail($this->patientAUser, $visitB);
            $this->fail('Expected CLINIC_NOT_FOUND');
        } catch (ClinicalException $e) {
            $this->assertSame('CLINIC_NOT_FOUND', $e->errorCode);
        }
        $audit = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_audit_logs') .
            ' WHERE action = %s AND resource_type = %s AND resource_id = %d',
            ['FORBIDDEN_ACCESS_ATTEMPT', 'visit', $visitB]
        );
        $this->assertNotNull($audit);

        // C5 فقط ویزیت‌های خودش را لیست می‌کند
        $list = $this->clinical()->patientVisits($this->patientAUser);
        $ids = array_map(static fn (array $v): int => $v['id'], $list['visits']);
        $this->assertContains($visitA, $ids);
        $this->assertNotContains($visitB, $ids);
    }

    // ================= E18 — جستجوی جامع Role-Aware =================

    public function testDoctorGlobalSearchFindsPatientNoteAndRx(): void
    {
        $visitId = $this->makeConsultation($this->patientAId);
        $this->clinical()->addNote($this->doctorUserId, $visitId, [
            'category' => 'diagnosis',
            'visibility' => 'patient_visible',
            'content_text' => 'تشخیص آزمایشی: کمبود دیگوکسین‌پذیری قلبی',
        ]);
        $rx = $this->clinical()->createPrescription($this->doctorUserId, $visitId, [
            'items' => [['generic_name' => 'دیگوکسین', 'dose' => '0.25mg', 'frequency' => '۱ بار در روز']],
        ]);

        // پزشک: هر سه نوع نتیجه (بیمار با MRN، یادداشت با متن، نسخه با دارو)
        $byDrug = $this->clinical()->globalSearch($this->doctorUserId, 'دیگوکسین', 'note');
        $this->assertSame(1, count($byDrug['results']['notes']));
        $this->assertSame($visitId, $byDrug['results']['notes'][0]['visit_id']);
        $this->assertStringNotContainsString('content_text', (string) json_encode($byDrug), 'نتیجه جستجو Snippet است نه متن کامل');

        $byRx = $this->clinical()->globalSearch($this->doctorUserId, 'دیگوکسین', 'rx');
        $this->assertSame([(int) $rx['id']], array_map(static fn (array $r): int => $r['id'], $byRx['results']['prescriptions']));

        $byPatient = $this->clinical()->globalSearch($this->doctorUserId, 'MR-CF-0001', 'patient');
        $this->assertSame([$this->patientAId], array_map(static fn (array $p): int => $p['id'], $byPatient['results']['patients']));

        // Audit جستجو (FR-21.1)
        $audit = App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . " WHERE action = 'SEARCH_EXECUTED'",
            []
        );
        $this->assertGreaterThanOrEqual(3, (int) $audit);
    }

    public function testSecretarySearchReturnsPatientsOnly(): void
    {
        $visitId = $this->makeConsultation($this->patientAId);
        $this->clinical()->addNote($this->doctorUserId, $visitId, [
            'category' => 'diagnosis',
            'visibility' => 'patient_visible',
            'content_text' => 'تشخیص دیگوکسین',
        ]);
        $this->clinical()->createPrescription($this->doctorUserId, $visitId, [
            'items' => [['generic_name' => 'دیگوکسین', 'dose' => '0.25mg', 'frequency' => 'روزانه']],
        ]);

        // منشی cpms_search دارد اما نتایج بالینی (TP-08 + ماتریس §4) برایش خالی است
        $result = $this->clinical()->globalSearch($this->secretaryUserId, 'دیگوکسین', 'all');
        $this->assertSame([], $result['results']['notes']);
        $this->assertSame([], $result['results']['prescriptions']);
        $this->assertSame([], $result['results']['patients'], 'بیماری با این عبارت مطابقت ندارد');

        // جستجوی بیمار برای منشی کار می‌کند (هم‌ارز D2)
        $byMrn = $this->clinical()->globalSearch($this->secretaryUserId, 'MR-CF-0001', 'patient');
        $this->assertSame([$this->patientAId], array_map(static fn (array $p): int => $p['id'], $byMrn['results']['patients']));
    }

    public function testSearchRejectsShortQueryAndPatientRole(): void
    {
        // Validation: حداقل ۲ کاراکتر
        try {
            $this->clinical()->globalSearch($this->doctorUserId, 'د', 'all');
            $this->fail('Expected CLINIC_VALIDATION_FAILED');
        } catch (ClinicalException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
        }

        // بیمار فاقد cpms_search (ماتریس §4) → 403
        try {
            $this->clinical()->globalSearch($this->patientAUser, 'MR-CF-0001', 'all');
            $this->fail('Expected CLINIC_PERMISSION_DENIED');
        } catch (ClinicalException $e) {
            $this->assertSame('CLINIC_PERMISSION_DENIED', $e->errorCode);
            $this->assertSame(403, $e->httpStatus);
        }

        // REST: بدون Cap → 403 (لایه کنترلر)
        wp_set_current_user($this->patientAUser);
        $response = $this->dispatch('GET', self::NS . '/search', ['q' => 'MR-CF-0001']);
        $this->assertSame(403, $response->get_status());
        $this->assertSame('CLINIC_PERMISSION_DENIED', $response->get_data()['code'] ?? null);
    }

    // ================= E10/E11 — نسخه دارویی =================

    public function testPrescriptionDraftFinalizeFlow(): void
    {
        $visitId = $this->makeConsultation($this->patientAId);

        $draft = $this->clinical()->createPrescription($this->doctorUserId, $visitId, [
            'items' => [
                [
                    'generic_name' => 'استامینوفن',
                    'brand_name' => 'تیلنول',
                    'strength' => '500mg',
                    'form' => 'tablet',
                    'dose' => '1 قرص',
                    'frequency' => 'هر 8 ساعت',
                    'route' => 'oral',
                    'duration_days' => 5,
                    'instructions' => 'بعد از غذا',
                ],
                [
                    'generic_name' => 'دیفن‌هیدرامین',
                    'dose' => '1 آمپول',
                    'frequency' => 'شب‌ها',
                ],
            ],
            'is_patient_visible' => true,
        ]);

        $this->assertSame('draft', $draft['status']);
        $this->assertMatchesRegularExpression('/^RX-\d{6}$/', $draft['prescription_number']);
        $this->assertCount(2, $draft['items']);
        $this->assertSame('استامینوفن', $draft['items'][0]['generic_name']);

        $final = $this->clinical()->finalizePrescription($this->doctorUserId, (int) $draft['id']);
        $this->assertSame('finalized', $final['status']);
        $this->assertNotEmpty($final['finalized_at']);

        // تکرار نهایی‌سازی → 409 (الگوی یکتایی ماشین‌ها)
        try {
            $this->clinical()->finalizePrescription($this->doctorUserId, (int) $draft['id']);
            $this->fail('Expected CLINIC_INVALID_TRANSITION');
        } catch (ClinicalException $e) {
            $this->assertSame('CLINIC_INVALID_TRANSITION', $e->errorCode);
            $this->assertSame(409, $e->httpStatus);
        }
    }

    public function testPrescriptionRequiresOwnVisit(): void
    {
        // ویزیت روی پزشک ۱ — پزشک ۲ (با clinician دیگر) نمی‌تواند نسخه ثبت کند (ماتریس 4.3)
        $visitId = $this->makeConsultation($this->patientAId);

        try {
            $this->clinical()->createPrescription($this->otherDoctorUserId, $visitId, [
                'items' => [['generic_name' => 'آب', 'dose' => '1', 'frequency' => '1']],
            ]);
            $this->fail('Expected CLINIC_NOT_FOUND (own-visit rule)');
        } catch (ClinicalException $e) {
            $this->assertSame('CLINIC_NOT_FOUND', $e->errorCode);
            $this->assertSame(404, $e->httpStatus);
        }
    }

    public function testPrescriptionValidation(): void
    {
        $visitId = $this->makeConsultation($this->patientAId);

        try {
            $this->clinical()->createPrescription($this->doctorUserId, $visitId, ['items' => []]);
            $this->fail('Expected empty items rejection');
        } catch (ClinicalException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
        }

        try {
            $this->clinical()->createPrescription($this->doctorUserId, $visitId, [
                'items' => [['generic_name' => 'دارو', 'frequency' => '1']], // dose ندارد
            ]);
            $this->fail('Expected dose rejection');
        } catch (ClinicalException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
        }
    }

    public function testPatientSeesOnlyVisibleNonDraftPrescriptions(): void
    {
        $visitId = $this->makeConsultation($this->patientAId);

        $hidden = $this->clinical()->createPrescription($this->doctorUserId, $visitId, [
            'items' => [['generic_name' => 'داروی پنهان', 'dose' => '1', 'frequency' => '1']],
            'is_patient_visible' => false,
        ]);
        $draft = $this->clinical()->createPrescription($this->doctorUserId, $visitId, [
            'items' => [['generic_name' => 'داروی پیش‌نویس', 'dose' => '1', 'frequency' => '1']],
            'is_patient_visible' => true,
        ]);
        $visible = $this->clinical()->createPrescription($this->doctorUserId, $visitId, [
            'items' => [['generic_name' => 'استامینوفن', 'dose' => '1', 'frequency' => 'روزانه']],
            'is_patient_visible' => true,
        ]);
        $this->clinical()->finalizePrescription($this->doctorUserId, (int) $visible['id']);

        $mine = $this->clinical()->patientPrescriptions($this->patientAUser);
        $numbers = array_map(static fn (array $rx): string => $rx['prescription_number'], $mine['prescriptions']);

        $this->assertContains($visible['prescription_number'], $numbers);
        $this->assertNotContains($hidden['prescription_number'], $numbers, 'is_patient_visible=0 must be hidden');
        $this->assertNotContains($draft['prescription_number'], $numbers, 'draft must be hidden until finalized');
    }

    // ================= E12/E13 — توصیه/پیگیری =================

    public function testRecommendationsAndFollowUpFlow(): void
    {
        $visitId = $this->makeConsultation($this->patientAId);

        $result = $this->clinical()->addRecommendations($this->doctorUserId, $visitId, [
            'items' => [
                ['type' => 'rest', 'text' => 'استراحت سه روز', 'is_patient_visible' => true],
                ['type' => 'diet', 'text' => 'کاهش نمک', 'is_patient_visible' => true],
                ['type' => 'lab', 'text' => 'آزمایش خون (نتیجه برای پزشک)', 'is_patient_visible' => false],
            ],
        ]);
        $this->assertSame(3, $result['created']);

        // نمای بیمار فقط patient_visible
        $detail = $this->clinical()->patientVisitDetail($this->patientAUser, $visitId);
        $this->assertCount(2, $detail['recommendations']);

        $fu = $this->clinical()->addFollowUp($this->doctorUserId, $visitId, [
            'is_needed' => true,
            'suggested_date' => gmdate('Y-m-d', strtotime('+14 days')),
            'reason' => 'کنترل فشار خون',
        ]);
        $this->assertSame('pending', $fu['status']);
        $this->assertSame(gmdate('Y-m-d', strtotime('+14 days')), $fu['suggested_date']);
    }

    public function testFollowUpRequiresDateOrInterval(): void
    {
        $visitId = $this->makeConsultation($this->patientAId);

        try {
            $this->clinical()->addFollowUp($this->doctorUserId, $visitId, ['is_needed' => true]);
            $this->fail('Expected CLINIC_VALIDATION_FAILED');
        } catch (ClinicalException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
        }
    }

    // ================= E14/E15 — پایان/بازگشایی =================

    public function testCompleteRequiresChiefComplaintThenSucceeds(): void
    {
        $visitId = $this->makeConsultation($this->patientAId);

        // FR-8.7 — بدون Chief Complaint رد
        try {
            $this->clinical()->completeConsultation($this->doctorUserId, $visitId);
            $this->fail('Expected CLINIC_VALIDATION_FAILED');
        } catch (ClinicalException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            $this->assertSame(422, $e->httpStatus);
        }

        $this->clinical()->addNote($this->doctorUserId, $visitId, [
            'category' => 'chief_complaint',
            'visibility' => 'patient_visible',
            'content_text' => 'تب و سردرد',
        ]);
        $result = $this->clinical()->completeConsultation($this->doctorUserId, $visitId);
        $this->assertSame('consultation_completed', $result['status']);

        // V10 یکتایی — تکرار → ماشین 409
        try {
            $this->clinical()->completeConsultation($this->doctorUserId, $visitId);
            $this->fail('Expected CLINIC_INVALID_TRANSITION');
        } catch (\ClinicCore\Domain\Visits\VisitException $e) {
            $this->assertSame('CLINIC_INVALID_TRANSITION', $e->errorCode);
        }
    }

    public function testCompleteViaRestEnforcesValidation(): void
    {
        $visitId = $this->makeConsultation($this->patientAId);

        wp_set_current_user($this->doctorUserId);
        $response = $this->dispatch('POST', self::NS . '/visits/' . $visitId . '/complete');
        $this->assertSame(422, $response->get_status());
        $this->assertSame('CLINIC_VALIDATION_FAILED', $response->get_data()['code'] ?? null);

        $this->clinical()->addNote($this->doctorUserId, $visitId, [
            'category' => 'chief_complaint',
            'visibility' => 'patient_visible',
            'content_text' => 'درد شکم',
        ]);
        $response = $this->dispatch('POST', self::NS . '/visits/' . $visitId . '/complete');
        $this->assertSame(200, $response->get_status());
        $this->assertSame('consultation_completed', $response->get_data()['data']['status'] ?? null);
    }

    public function testReopenRequiresReasonAndDoctor(): void
    {
        $visitId = $this->makeConsultation($this->patientAId);
        $this->clinical()->addNote($this->doctorUserId, $visitId, [
            'category' => 'chief_complaint',
            'visibility' => 'patient_visible',
            'content_text' => 'سرفه',
        ]);
        $this->clinical()->completeConsultation($this->doctorUserId, $visitId);

        // دلیل الزامی (FR-8.8)
        try {
            $this->clinical()->reopenConsultation($this->doctorUserId, $visitId, '  ');
            $this->fail('Expected reason validation');
        } catch (ClinicalException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
        }

        // منشی مجاز نیست (مجوز بالا)
        try {
            $this->clinical()->reopenConsultation($this->secretaryUserId, $visitId, 'دلیل');
            $this->fail('Expected CLINIC_PERMISSION_DENIED');
        } catch (ClinicalException $e) {
            $this->assertSame('CLINIC_PERMISSION_DENIED', $e->errorCode);
        }

        $result = $this->clinical()->reopenConsultation($this->doctorUserId, $visitId, 'ثبت اشتباه تشخیص');
        $this->assertSame('in_consultation', $result['status']);

        // Audit بازگشایی
        $audit = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_audit_logs') .
            ' WHERE action = %s AND resource_type = %s AND resource_id = %d',
            ['CONSULTATION_REOPENED', 'visit', $visitId]
        );
        $this->assertNotNull($audit);
    }

    // ================= E7 — پرونده کامل =================

    public function testRecordAssemblesFullClinicalPicture(): void
    {
        $visitId = $this->makeConsultation($this->patientAId);

        // داده‌های پزشکی پروفایل (FR-8.1 — فیلدهای پزشکی)
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . $wpdb->prefix . 'cpms_patients SET medication_allergies = %s, chronic_conditions = %s, birth_date = %s WHERE id = %d',
            '[{"name":"پنی‌سیلین","note":"کهیر"}]',
            '["دیابت"]',
            '1990-05-15',
            $this->patientAId
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $this->clinical()->addNote($this->doctorUserId, $visitId, [
            'category' => 'chief_complaint',
            'visibility' => 'patient_visible',
            'content_text' => 'تشنگی زیاد',
        ]);
        $rx = $this->clinical()->createPrescription($this->doctorUserId, $visitId, [
            'items' => [['generic_name' => 'متفورمین', 'dose' => '1', 'frequency' => 'روزانه']],
        ]);
        $this->clinical()->finalizePrescription($this->doctorUserId, (int) $rx['id']);

        $record = $this->clinical()->record($this->doctorUserId, $visitId);

        $this->assertNotEmpty($record['patient']['allergies']['medication']);
        $this->assertSame(['دیابت'], $record['patient']['chronic_conditions']);
        $this->assertGreaterThanOrEqual(35, $record['patient']['age']);
        $this->assertCount(1, $record['notes']);
        $this->assertCount(1, $record['prescriptions']);
        $this->assertSame('finalized', $record['prescriptions'][0]['status']);
        $this->assertNotEmpty($record['past_visits']);
    }

    // ================= C5 — لیست ویزیت‌های بیمار =================

    public function testPatientVisitsListViaRest(): void
    {
        $visitA = $this->makeConsultation($this->patientAId);
        $this->makeConsultation($this->patientBId);

        wp_set_current_user($this->patientAUser);
        $response = $this->dispatch('GET', self::NS . '/visits');
        $this->assertSame(200, $response->get_status());
        $visits = $response->get_data()['data']['visits'] ?? [];
        $this->assertCount(1, $visits);
        $this->assertSame($visitA, (int) $visits[0]['id']);
        // فقط فیلدهای patient_visible — بدون recall_count/active و امثالهم
        $this->assertArrayNotHasKey('recall_count', $visits[0]);
    }

    public function testUnlinkedUserGets404OnPatientViews(): void
    {
        $visitId = $this->makeConsultation($this->patientAId);
        $lonely = $this->makeUser('cf_lonely', 'cpms_patient');

        try {
            $this->clinical()->patientVisitDetail($lonely, $visitId);
            $this->fail('Expected CLINIC_NOT_FOUND');
        } catch (ClinicalException $e) {
            $this->assertSame('CLINIC_NOT_FOUND', $e->errorCode);
        }
    }

    // ================= Helpers =================

    private function clinical(): \ClinicCore\Application\Clinical\ClinicalService
    {
        return App::clinicalService();
    }

    /**
     * ویزیت واقعی تا وضعیت in_consultation (مسیر کامل: walk-in → enqueue → call → start).
     */
    private function makeConsultation(int $patientId): int
    {
        $visit = App::visitService()->walkIn($this->secretaryUserId, $patientId, $this->clinicianId);
        $id = (int) $visit['id'];
        App::visitService()->transition($this->doctorUserId, $id, 'call');
        App::visitService()->transition($this->doctorUserId, $id, 'start');

        return $id;
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login . bin2hex(random_bytes(3)), 'pass-12345', $login . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }

    private function dispatch(string $method, string $route, array $body = [], bool $withNonce = true): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }
        if ($withNonce) {
            $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        }

        return rest_do_request($request);
    }
}
