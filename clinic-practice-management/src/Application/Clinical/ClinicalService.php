<?php

declare(strict_types=1);

namespace ClinicCore\Application\Clinical;

use ClinicCore\Application\Visits\VisitService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\ClinicalNoteRepository;
use ClinicCore\Infrastructure\Repository\FollowUpRepository;
use ClinicCore\Infrastructure\Repository\PrescriptionRepository;
use ClinicCore\Infrastructure\Repository\RecommendationRepository;
use ClinicCore\Infrastructure\Repository\VisitRepository;
use ClinicCore\Settings\Settings;

/**
 * سرویس بالینی (F5) — فضای کار ویزیت پزشک + نمای بیمار.
 *
 * Scope (API Contract E7–E15 + C5–C7):
 *  - E7  پرونده کامل ویزیت (cpms_medical_read — فقط پزشک)
 *  - E8/E9 یادداشت بالینی + Correction با نسخه‌بندی append-only (FR-8.5)
 *  - E10/E11 نسخه دارویی (Draft → Finalize) + Void سرویسی
 *  - E12 توصیه‌ها، E13 Follow-up
 *  - E14 پایان ویزیت با Validation (FR-8.7 — پیش‌فرض Chief Complaint)
 *  - E15 Reopen (FR-8.8 — مجوز بالا + دلیل + Audit)
 *  - C5/C6/C7 نمای بیمار: فقط فیلدهای patient_visible
 *
 * تضمین‌های امنیتی:
 *  - **FR-8.4/TP-08:** هر خواندن یادداشت با فیلتر Visibility در سطح Query
 *    (Repository) — doctor_private هرگز به Secretary/Patient برنمی‌گرداند.
 *  - **P-8:** Ownership بیمار علاوه بر Capability (C5–C7 فقط رکوردهای خودش).
 *  - ماتریس 4.3: نسخه‌نویسی فقط روی «ویزیت خودش» (clinician متصل به کاربر).
 *  - Audit برای هر عمل بالینی + مشاهده پرونده (FR-21.1) — بدون متن کامل PHI
 *    در Audit (ارجاع به نسخه‌ها در cpms_clinical_note_versions).
 */
final class ClinicalService
{
    private const NOTE_CATEGORIES = [
        'chief_complaint', 'history', 'examination', 'diagnosis',
        'clinical_note', 'recommendation_text', 'private_note', 'other',
    ];

    private const RX_FORMS = ['tablet', 'capsule', 'syrup', 'injection', 'ointment', 'drops', 'inhaler', 'other'];
    private const RX_ROUTES = ['oral', 'iv', 'im', 'sc', 'topical', 'inhaled', 'other'];
    private const REC_TYPES = ['diet', 'rest', 'activity', 'care', 'lab', 'followup', 'other'];

    public function __construct(
        private readonly CpmsDb $db,
        private readonly VisitRepository $visits,
        private readonly VisitService $visitService,
        private readonly ClinicalNoteRepository $notes,
        private readonly PrescriptionRepository $prescriptions,
        private readonly RecommendationRepository $recommendations,
        private readonly FollowUpRepository $followUps,
        private readonly Settings $settings,
        private readonly AuditLogger $audit
    ) {
    }

    // ================= E7 — پرونده کامل ویزیت (پزشک) =================

    /**
     * @return array<string, mixed>
     */
    public function record(int $actorUserId, int $visitId): array
    {
        $this->requireCap($actorUserId, RolesAndCapabilities::MEDICAL_READ, 'record');

        $visit = $this->requireVisit($visitId);
        $patient = $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_patients') . ' WHERE id = %d LIMIT 1',
            [(int) $visit['patient_id']]
        );
        if ($patient === null) {
            throw ClinicalException::of('CLINIC_NOT_FOUND', 'بیمار یافت نشد', 404);
        }

        $pastVisits = $this->db->fetchAll(
            'SELECT v.id, v.visit_date, v.status, v.source, v.clinician_id, c.full_name AS clinician_name' .
            ' FROM ' . $this->db->table('cpms_visits') . ' v' .
            ' LEFT JOIN ' . $this->db->table('cpms_clinicians') . ' c ON c.id = v.clinician_id' .
            ' WHERE v.patient_id = %d ORDER BY v.visit_date DESC, v.id DESC LIMIT 50',
            [(int) $visit['patient_id']]
        ) ?: [];

        $rxRows = $this->prescriptions->forVisit($visitId);
        $rxList = [];
        foreach ($rxRows as $rx) {
            $rxList[] = $this->presentPrescription($rx, $this->prescriptions->itemsFor((int) $rx['id']));
        }

        $this->audit->log(
            'MEDICAL_RECORD_VIEWED',
            $this->actor($actorUserId, 'doctor'),
            'visit',
            $visitId,
            (int) $visit['patient_id'],
            null,
            null,
            ['via' => 'record_endpoint']
        );

        return [
            'visit' => [
                'id' => (int) $visit['id'],
                'status' => (string) $visit['status'],
                'source' => (string) $visit['source'],
                'visit_date' => (string) $visit['visit_date'],
                'clinician_id' => (int) $visit['clinician_id'],
                'appointment_id' => $visit['appointment_id'] !== null ? (int) $visit['appointment_id'] : null,
                'consultation_started_at' => $visit['consultation_started_at'],
                'consultation_completed_at' => $visit['consultation_completed_at'],
            ],
            'patient' => $this->patientMedicalView($patient),
            'notes' => array_map([$this, 'presentNote'], $this->notes->forVisit($visitId, null)),
            'prescriptions' => $rxList,
            'recommendations' => array_map([$this, 'presentRecommendation'], $this->recommendations->forVisit($visitId)),
            'follow_ups' => array_map([$this, 'presentFollowUp'], $this->followUps->forVisit($visitId)),
            'past_visits' => array_map(static fn (array $v): array => [
                'id' => (int) $v['id'],
                'visit_date' => (string) $v['visit_date'],
                'status' => (string) $v['status'],
                'source' => (string) $v['source'],
                'clinician_name' => $v['clinician_name'],
            ], $pastVisits),
        ];
    }

    // ================= E8/E9 — یادداشت‌ها + نسخه‌بندی =================

    /**
     * E8 — ثبت یادداشت (FR-8.2/FR-8.3).
     *
     * @param array<string, mixed> $input {category, visibility, content_text, change_reason?}
     * @return array<string, mixed>
     */
    public function addNote(int $actorUserId, int $visitId, array $input): array
    {
        $this->requireRole($actorUserId, 'doctor', 'ثبت یادداشت بالینی');
        $visit = $this->requireVisit($visitId);

        $category = (string) ($input['category'] ?? '');
        $visibility = (string) ($input['visibility'] ?? 'patient_visible');
        $content = trim((string) ($input['content_text'] ?? ''));

        if (!in_array($category, self::NOTE_CATEGORIES, true)) {
            throw ClinicalException::of('CLINIC_VALIDATION_FAILED', 'دسته‌بندی یادداشت نامعتبر است', 422, ['category' => $category]);
        }
        if (!in_array($visibility, ['patient_visible', 'doctor_private'], true)) {
            throw ClinicalException::of('CLINIC_VALIDATION_FAILED', 'سطح دسترسی یادداشت نامعتبر است', 422, ['visibility' => $visibility]);
        }
        if ($content === '') {
            throw ClinicalException::of('CLINIC_VALIDATION_FAILED', 'متن یادداشت خالی است', 422);
        }
        if (mb_strlen($content) > 65535) {
            throw ClinicalException::of('CLINIC_VALIDATION_FAILED', 'متن یادداشت بیش از حد مجاز است', 422);
        }

        // P-6/ماتریس: doctor_private فقط با Capability مجزا (PRIVATE_NOTE_CREATE)
        if ($category === 'private_note' && $visibility !== 'doctor_private') {
            throw ClinicalException::of('CLINIC_VALIDATION_FAILED', 'یادداشت خصوصی پزشک باید doctor_private باشد', 422);
        }
        if ($visibility === 'doctor_private') {
            $this->requireCap($actorUserId, RolesAndCapabilities::PRIVATE_NOTE_CREATE, 'private_note');
        } else {
            $this->requireCap($actorUserId, RolesAndCapabilities::NOTE_CREATE, 'note');
        }

        $noteId = $this->notes->insert([
            'visit_id' => $visitId,
            'patient_id' => (int) $visit['patient_id'],
            'clinician_id' => (int) $visit['clinician_id'],
            'category' => $category,
            'visibility' => $visibility,
            'content_text' => $content,
            'version' => 1,
            'created_by_wp_user_id' => $actorUserId,
            'updated_by_wp_user_id' => $actorUserId,
        ]);

        $this->audit->log(
            'NOTE_CREATED',
            $this->actor($actorUserId, 'doctor'),
            'note',
            $noteId,
            (int) $visit['patient_id'],
            null,
            ['version' => 1, 'category' => $category, 'visibility' => $visibility, 'content_length' => mb_strlen($content)],
            ['visit_id' => $visitId]
        );

        return $this->presentNote($this->notes->find($noteId) ?? ['id' => $noteId]);
    }

    /**
     * E9 — Correction یادداشت: نسخه جدید + حفظ Snapshot قبلی (FR-8.5/FR-22.2).
     *
     * هرگز محتوای قبلی overwrite نمی‌شود: قبل از هر تغییر، Snapshot در
     * cpms_clinical_note_versions (append-only، K-6) ثبت می‌شود.
     *
     * @param array<string, mixed> $input {content_text, change_reason}
     * @return array<string, mixed>
     */
    public function updateNote(int $actorUserId, int $noteId, array $input): array
    {
        $this->requireRole($actorUserId, 'doctor', 'ویرایش یادداشت بالینی');

        $content = trim((string) ($input['content_text'] ?? ''));
        $reason = trim((string) ($input['change_reason'] ?? ''));
        if ($content === '') {
            throw ClinicalException::of('CLINIC_VALIDATION_FAILED', 'متن یادداشت خالی است', 422);
        }
        if ($reason === '') {
            // FR-8.5/FR-22.2 — Correction پزشکی: دلیل الزامی
            throw ClinicalException::of('CLINIC_VALIDATION_FAILED', 'دلیل اصلاح (change_reason) الزامی است', 422);
        }

        $note = $this->db->transactional(function () use ($actorUserId, $noteId, $content, $reason): array {
            $note = $this->notes->findForUpdate($noteId);
            if ($note === null) {
                throw ClinicalException::of('CLINIC_NOT_FOUND', 'یادداشت یافت نشد', 404);
            }
            if ((int) $note['is_archived'] === 1) {
                throw ClinicalException::of('CLINIC_POLICY_VIOLATION', 'یادداشت آرشیو شده قابل ویرایش نیست', 409);
            }
            // ماتریس 4.3 — ویرایش یادداشت: «خودش» (پزشک نویسنده)
            if ((int) $note['created_by_wp_user_id'] !== $actorUserId) {
                $this->auditAndThrow(
                    $actorUserId,
                    'note',
                    $noteId,
                    (int) $note['patient_id'],
                    'یادداشت به این پزشک تعلق ندارد (ماتریس 4.3 — ویرایش فقط توسط نویسنده)'
                );
            }

            // Capability متناسب با Visibility (P-6)
            if ((string) $note['visibility'] === 'doctor_private') {
                $this->requireCap($actorUserId, RolesAndCapabilities::PRIVATE_NOTE_UPDATE, 'private_note');
            } else {
                $this->requireCap($actorUserId, RolesAndCapabilities::NOTE_UPDATE, 'note');
            }

            // 1) Snapshot نسخه فعلی — append-only، قبل از هر تغییر
            $this->notes->insertVersion($noteId, [
                'version' => (int) $note['version'],
                'content_snapshot' => (string) $note['content_text'],
                'changed_by_wp_user_id' => $actorUserId,
                'change_reason' => $reason,
            ]);

            // 2) نسخه جدید روی رکورد فعال
            $this->notes->update($noteId, [
                'content_text' => $content,
                'version' => (int) $note['version'] + 1,
                'change_reason' => mb_substr($reason, 0, 255),
                'updated_by_wp_user_id' => $actorUserId,
            ]);

            return $note;
        });

        $updated = $this->notes->find($noteId) ?? $note;
        $this->audit->log(
            'NOTE_UPDATED',
            $this->actor($actorUserId, 'doctor'),
            'note',
            $noteId,
            (int) $note['patient_id'],
            ['version' => (int) $note['version'], 'content_length' => mb_strlen((string) $note['content_text'])],
            ['version' => (int) $updated['version'], 'content_length' => mb_strlen($content)],
            ['visit_id' => (int) $note['visit_id'], 'change_reason' => mb_substr($reason, 0, 255)]
        );

        return $this->presentNote($updated) + ['versions' => count($this->notes->versionsFor($noteId))];
    }

    // ================= E10/E11 — نسخه دارویی =================

    /**
     * E10 — ثبت نسخه Draft (FR-11.1/FR-11.2). ماتریس 4.3: فقط «ویزیت خودش».
     *
     * @param array<string, mixed> $input {items: [...], is_patient_visible, correction_of_prescription_id?}
     * @return array<string, mixed>
     */
    public function createPrescription(int $actorUserId, int $visitId, array $input): array
    {
        $this->requireRole($actorUserId, 'doctor', 'ثبت نسخه');
        $this->requireCap($actorUserId, RolesAndCapabilities::RX_CREATE, 'rx');
        $visit = $this->requireVisit($visitId);
        $this->requireOwnVisit($actorUserId, $visit);

        $items = $input['items'] ?? null;
        if (!is_array($items) || $items === []) {
            throw ClinicalException::of('CLINIC_VALIDATION_FAILED', 'نسخه باید حداقل یک قلم دارو داشته باشد', 422);
        }

        $correctionOf = isset($input['correction_of_prescription_id'])
            ? (int) $input['correction_of_prescription_id']
            : null;
        if ($correctionOf !== null && $this->prescriptions->find($correctionOf) === null) {
            throw ClinicalException::of('CLINIC_NOT_FOUND', 'نسخه اصلاح‌شده (correction_of) یافت نشد', 404);
        }

        $number = $this->prescriptions->nextPrescriptionNumber();
        $rxId = $this->prescriptions->insert([
            'prescription_number' => $number,
            'visit_id' => $visitId,
            'patient_id' => (int) $visit['patient_id'],
            'clinician_id' => (int) $visit['clinician_id'],
            'is_patient_visible' => !empty($input['is_patient_visible']) ? 1 : 0,
            'correction_of_prescription_id' => $correctionOf,
        ]);

        $sort = 0;
        foreach ($items as $item) {
            $this->prescriptions->insertItem($rxId, $this->validateRxItem($item) + ['sort_order' => $sort++]);
        }

        $this->audit->log(
            'PRESCRIPTION_CREATED',
            $this->actor($actorUserId, 'doctor'),
            'prescription',
            $rxId,
            (int) $visit['patient_id'],
            null,
            ['prescription_number' => $number, 'status' => 'draft', 'item_count' => count($items)],
            ['visit_id' => $visitId, 'correction_of' => $correctionOf]
        );

        return $this->presentPrescription(
            $this->prescriptions->find($rxId) ?? ['id' => $rxId],
            $this->prescriptions->itemsFor($rxId)
        );
    }

    /**
     * E11 — نهایی‌سازی نسخه (Draft → Finalized). تکرار → CLINIC_INVALID_TRANSITION.
     *
     * @return array<string, mixed>
     */
    public function finalizePrescription(int $actorUserId, int $prescriptionId): array
    {
        $this->requireRole($actorUserId, 'doctor', 'نهایی‌سازی نسخه');
        $this->requireCap($actorUserId, RolesAndCapabilities::RX_CREATE, 'rx');

        $rx = $this->db->transactional(function () use ($actorUserId, $prescriptionId): array {
            $rx = $this->prescriptions->findForUpdate($prescriptionId);
            if ($rx === null) {
                throw ClinicalException::of('CLINIC_NOT_FOUND', 'نسخه یافت نشد', 404);
            }
            if ((string) $rx['status'] === 'finalized') {
                throw ClinicalException::of('CLINIC_INVALID_TRANSITION', 'این نسخه قبلاً نهایی شده است', 409, ['status' => 'finalized']);
            }
            if ((string) $rx['status'] === 'voided') {
                throw ClinicalException::of('CLINIC_INVALID_TRANSITION', 'نسخه ابطال‌شده قابل نهایی‌سازی نیست', 409, ['status' => 'voided']);
            }

            $this->prescriptions->update($prescriptionId, ['status' => 'finalized', 'finalized_at' => $this->db->nowUtcSql()]);

            return $rx;
        });

        $this->audit->log(
            'PRESCRIPTION_FINALIZED',
            $this->actor($actorUserId, 'doctor'),
            'prescription',
            $prescriptionId,
            (int) $rx['patient_id'],
            ['status' => 'draft'],
            ['status' => 'finalized'],
            ['prescription_number' => (string) $rx['prescription_number'], 'visit_id' => (int) $rx['visit_id']]
        );

        return $this->presentPrescription(
            $this->prescriptions->find($prescriptionId) ?? $rx,
            $this->prescriptions->itemsFor($prescriptionId)
        );
    }

    /**
     * ابطال نسخه (FR-11.5/ماتریس D با دلیل) — سرویس‌level؛ Endpoint در قرارداد
     * فعلی نیست (تصمیم افزودن E-endpoint با کارفرما — report-f5 §باز).
     *
     * @return array<string, mixed>
     */
    public function voidPrescription(int $actorUserId, int $prescriptionId, string $reason): array
    {
        $this->requireRole($actorUserId, 'doctor', 'ابطال نسخه');
        $this->requireCap($actorUserId, RolesAndCapabilities::RX_VOID, 'rx_void');
        $reason = trim($reason);
        if ($reason === '') {
            throw ClinicalException::of('CLINIC_VALIDATION_FAILED', 'دلیل ابطال الزامی است', 422);
        }

        $rx = $this->db->transactional(function () use ($prescriptionId): array {
            $rx = $this->prescriptions->findForUpdate($prescriptionId);
            if ($rx === null) {
                throw ClinicalException::of('CLINIC_NOT_FOUND', 'نسخه یافت نشد', 404);
            }
            if ((string) $rx['status'] === 'voided') {
                throw ClinicalException::of('CLINIC_INVALID_TRANSITION', 'این نسخه قبلاً ابطال شده است', 409, ['status' => 'voided']);
            }

            $this->prescriptions->update($prescriptionId, ['status' => 'voided', 'void_reason' => mb_substr($reason, 0, 255)]);

            return $rx;
        });

        $this->audit->log(
            'PRESCRIPTION_VOIDED',
            $this->actor($actorUserId, 'doctor'),
            'prescription',
            $prescriptionId,
            (int) $rx['patient_id'],
            ['status' => (string) $rx['status']],
            ['status' => 'voided'],
            ['prescription_number' => (string) $rx['prescription_number'], 'reason' => mb_substr($reason, 0, 255)]
        );

        return $this->presentPrescription(
            $this->prescriptions->find($prescriptionId) ?? $rx,
            $this->prescriptions->itemsFor($prescriptionId)
        );
    }

    // ================= E12/E13 — توصیه‌ها و Follow-up =================

    /**
     * E12 — ثبت توصیه‌ها (FR-12.1).
     *
     * @param array<string, mixed> $input {items: [{type, text, is_patient_visible}]}
     * @return array<string, mixed>
     */
    public function addRecommendations(int $actorUserId, int $visitId, array $input): array
    {
        $this->requireRole($actorUserId, 'doctor', 'ثبت توصیه');
        $this->requireCap($actorUserId, RolesAndCapabilities::REC_CREATE, 'rec');
        $visit = $this->requireVisit($visitId);

        $items = $input['items'] ?? null;
        if (!is_array($items) || $items === []) {
            throw ClinicalException::of('CLINIC_VALIDATION_FAILED', 'حداقل یک توصیه ارسال شود', 422);
        }

        $created = [];
        foreach ($items as $item) {
            $type = (string) ($item['type'] ?? 'other');
            $text = trim((string) ($item['text'] ?? ''));
            if (!in_array($type, self::REC_TYPES, true)) {
                throw ClinicalException::of('CLINIC_VALIDATION_FAILED', 'نوع توصیه نامعتبر است', 422, ['type' => $type]);
            }
            if ($text === '' || mb_strlen($text) > 1000) {
                throw ClinicalException::of('CLINIC_VALIDATION_FAILED', 'متن توصیه الزامی است (حداکثر ۱۰۰۰ نویسه)', 422);
            }

            $id = $this->recommendations->insert([
                'visit_id' => $visitId,
                'patient_id' => (int) $visit['patient_id'],
                'clinician_id' => (int) $visit['clinician_id'],
                'type' => $type,
                'text' => $text,
                'is_patient_visible' => !empty($item['is_patient_visible']) ? 1 : 0,
            ]);
            $created[] = $id;
        }

        $this->audit->log(
            'RECOMMENDATIONS_CREATED',
            $this->actor($actorUserId, 'doctor'),
            'visit',
            $visitId,
            (int) $visit['patient_id'],
            null,
            ['count' => count($created)],
            ['types' => array_map(static fn (array $i): string => (string) ($i['type'] ?? 'other'), $items)]
        );

        return ['created' => count($created), 'recommendations' => array_map(
            [$this, 'presentRecommendation'],
            $this->recommendations->forVisit($visitId)
        )];
    }

    /**
     * E13 — ثبت Follow-up (FR-12.2). یادآوری/Recall در F8 (لایه Notification).
     *
     * @param array<string, mixed> $input {is_needed, suggested_date?, interval_days?, reason?}
     * @return array<string, mixed>
     */
    public function addFollowUp(int $actorUserId, int $visitId, array $input): array
    {
        $this->requireRole($actorUserId, 'doctor', 'ثبت پیگیری');
        $this->requireCap($actorUserId, RolesAndCapabilities::REC_CREATE, 'rec');
        $visit = $this->requireVisit($visitId);

        $isNeeded = !empty($input['is_needed']);
        $suggestedDate = isset($input['suggested_date']) ? (string) $input['suggested_date'] : null;
        $intervalDays = isset($input['interval_days']) ? (int) $input['interval_days'] : null;
        $reason = isset($input['reason']) ? trim((string) $input['reason']) : null;

        if ($isNeeded) {
            if ($suggestedDate === null && $intervalDays === null) {
                throw ClinicalException::of('CLINIC_VALIDATION_FAILED', 'برای پیگیری، تاریخ پیشنهادی یا بازه (روز) الزامی است', 422);
            }
        }
        if ($suggestedDate !== null && (!$this->isValidDate($suggestedDate))) {
            throw ClinicalException::of('CLINIC_VALIDATION_FAILED', 'تاریخ پیشنهادی نامعتبر است (YYYY-MM-DD)', 422, ['suggested_date' => $suggestedDate]);
        }
        if ($intervalDays !== null && ($intervalDays < 1 || $intervalDays > 3650)) {
            throw ClinicalException::of('CLINIC_VALIDATION_FAILED', 'بازه پیگیری باید بین ۱ تا ۳۶۵۰ روز باشد', 422);
        }

        $id = $this->followUps->insert([
            'visit_id' => $visitId,
            'patient_id' => (int) $visit['patient_id'],
            'clinician_id' => (int) $visit['clinician_id'],
            'is_needed' => $isNeeded ? 1 : 0,
            'suggested_date' => $isNeeded ? $suggestedDate : null,
            'interval_days' => $isNeeded ? $intervalDays : null,
            'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
        ]);

        $this->audit->log(
            'FOLLOW_UP_CREATED',
            $this->actor($actorUserId, 'doctor'),
            'follow_up',
            $id,
            (int) $visit['patient_id'],
            null,
            ['is_needed' => $isNeeded, 'suggested_date' => $isNeeded ? $suggestedDate : null],
            ['visit_id' => $visitId]
        );

        return $this->presentFollowUp($this->followUps->find($id) ?? ['id' => $id]);
    }

    // ================= E14/E15 — پایان/بازگشایی ویزیت =================

    /**
     * E14 — پایان ویزیت با Validation بالینی (FR-8.7).
     *
     * پیش‌فرض (Setting `clinical.require_chief_complaint`): Chief Complaint
     * ثبت‌شده باشد؛ سپس Transition ماشین (V10 — یکتایی با Row Lock در
     * VisitService).
     *
     * @return array<string, mixed>
     */
    public function completeConsultation(int $actorUserId, int $visitId): array
    {
        $this->requireRole($actorUserId, 'doctor', 'پایان ویزیت');
        $this->requireCap($actorUserId, RolesAndCapabilities::CONSULT_COMPLETE, 'complete');
        $visit = $this->requireVisit($visitId);

        if ((bool) $this->settings->get('clinical.require_chief_complaint', true)
            && !$this->notes->visitHasCategory($visitId, 'chief_complaint')) {
            throw ClinicalException::of(
                'CLINIC_VALIDATION_FAILED',
                'شکایت اصلی بیمار (Chief Complaint) ثبت نشده است — بدون آن ویزیت قابل پایان نیست',
                422,
                ['missing' => 'chief_complaint']
            );
        }

        $result = $this->visitService->transition($actorUserId, $visitId, 'complete');

        $this->audit->log(
            'CONSULTATION_COMPLETED',
            $this->actor($actorUserId, 'doctor'),
            'visit',
            $visitId,
            (int) $visit['patient_id'],
            null,
            ['status' => 'consultation_completed'],
            ['validation' => 'chief_complaint_ok']
        );

        return $result;
    }

    /**
     * E15 — بازگشایی ویزیت اشتباه (FR-8.8): مجوز بالا + دلیل الزامی + Audit.
     *
     * @return array<string, mixed>
     */
    public function reopenConsultation(int $actorUserId, int $visitId, string $reason): array
    {
        $this->requireRole($actorUserId, 'doctor', 'بازگشایی ویزیت');
        $this->requireCap($actorUserId, RolesAndCapabilities::CONSULT_REOPEN, 'reopen');
        $reason = trim($reason);
        if ($reason === '') {
            throw ClinicalException::of('CLINIC_VALIDATION_FAILED', 'دلیل بازگشایی الزامی است', 422);
        }
        $visit = $this->requireVisit($visitId);

        $result = $this->visitService->transition($actorUserId, $visitId, 'reopen', ['reason' => $reason]);

        $this->audit->log(
            'CONSULTATION_REOPENED',
            $this->actor($actorUserId, 'doctor'),
            'visit',
            $visitId,
            (int) $visit['patient_id'],
            ['status' => 'consultation_completed'],
            ['status' => 'in_consultation'],
            ['reason' => mb_substr($reason, 0, 255)]
        );

        return $result;
    }

    // ================= C5/C6/C7 — نمای بیمار (Ownership — P-5/P-8) =================

    /**
     * C5 — تاریخچه ویزیت‌های بیمار (فقط فیلدهای patient_visible).
     *
     * @return array<string, mixed>
     */
    public function patientVisits(int $wpUserId, ?string $from = null, ?string $to = null): array
    {
        $patientId = $this->requireOwnedPatient($wpUserId);
        $from = $from !== null && $this->isValidDate($from) ? $from : gmdate('Y-m-d', strtotime('-1 year'));
        $to = $to !== null && $this->isValidDate($to) ? $to : gmdate('Y-m-d', strtotime('+1 day'));

        $rows = $this->db->fetchAll(
            'SELECT v.*, c.full_name AS clinician_name FROM ' . $this->db->table('cpms_visits') . ' v' .
            ' LEFT JOIN ' . $this->db->table('cpms_clinicians') . ' c ON c.id = v.clinician_id' .
            ' WHERE v.patient_id = %d AND v.visit_date >= %s AND v.visit_date <= %s' .
            ' ORDER BY v.visit_date DESC, v.id DESC LIMIT 100',
            [$patientId, $from, $to]
        ) ?: [];

        return [
            'from' => $from,
            'to' => $to,
            'visits' => array_map(static fn (array $v): array => [
                'id' => (int) $v['id'],
                'visit_date' => (string) $v['visit_date'],
                'status' => (string) $v['status'],
                'source' => (string) $v['source'],
                'clinician_name' => $v['clinician_name'],
            ], $rows),
        ];
    }

    /**
     * C6 — جزئیات ویزیت بیمار: یادداشت‌ها/نسخه/توصیه/پیگیری — فقط مجازها.
     *
     * @return array<string, mixed>
     */
    public function patientVisitDetail(int $wpUserId, int $visitId): array
    {
        $patientId = $this->requireOwnedPatient($wpUserId);
        $visit = $this->requireVisit($visitId);
        if ((int) $visit['patient_id'] !== $patientId) {
            // TP-07/TP-08 — IDOR: منابع دیگران 404 + Audit
            $this->auditAndThrow($wpUserId, 'visit', $visitId, (int) $visit['patient_id'], 'ویزیت به این بیمار تعلق ندارد');
        }

        // فقط نسخه‌های نهایی‌شده/ابطال‌شده برای بیمار (Draft داده پزشکی نهایی نیست)
        $rxList = array_values(array_filter(
            $this->prescriptions->forPatient($patientId, true),
            static fn (array $rx): bool => (int) $rx['visit_id'] === $visitId && (string) $rx['status'] !== 'draft'
        ));

        return [
            'visit' => [
                'id' => (int) $visit['id'],
                'visit_date' => (string) $visit['visit_date'],
                'status' => (string) $visit['status'],
                'source' => (string) $visit['source'],
            ],
            // FR-8.4/TP-08 — فیلتر در سطح Query
            'notes' => array_map([$this, 'presentNote'], $this->notes->forVisit($visitId, ['patient_visible'])),
            'prescriptions' => array_map(
                fn (array $rx): array => $this->presentPrescription($rx, $this->prescriptions->itemsFor((int) $rx['id'])),
                $rxList
            ),
            'recommendations' => array_map(
                [$this, 'presentRecommendation'],
                $this->recommendations->forVisit($visitId, true)
            ),
            'follow_ups' => array_map([$this, 'presentFollowUp'], $this->followUps->forVisit($visitId)),
        ];
    }

    /**
     * C7 — نسخه‌های بیمار (فقط patient_visible و غیر-Draft — FR-11.6).
     *
     * @return array<string, mixed>
     */
    public function patientPrescriptions(int $wpUserId): array
    {
        $patientId = $this->requireOwnedPatient($wpUserId);
        $rows = array_values(array_filter(
            $this->prescriptions->forPatient($patientId, true),
            static fn (array $rx): bool => (string) $rx['status'] !== 'draft'
        ));

        return ['prescriptions' => array_map(
            fn (array $rx): array => $this->presentPrescription($rx, $this->prescriptions->itemsFor((int) $rx['id'])),
            $rows
        )];
    }

    // ================= Helpers — مجوز و داده =================

    /**
     * @return array<string, mixed>
     */
    private function requireVisit(int $visitId): array
    {
        $visit = $this->visits->find($visitId);
        if ($visit === null) {
            throw ClinicalException::of('CLINIC_NOT_FOUND', 'ویزیت یافت نشد', 404);
        }

        return $visit;
    }

    /**
     * ماتریس 4.3 — نسخه‌نویسی فقط روی «ویزیت خودش»: clinician متصل به کاربر.
     */
    private function requireOwnVisit(int $actorUserId, array $visit): void
    {
        $clinicianId = $this->db->fetchValue(
            'SELECT id FROM ' . $this->db->table('cpms_clinicians') . ' WHERE wp_user_id = %d AND is_active = 1 LIMIT 1',
            [$actorUserId]
        );
        if ($clinicianId === null || (int) $clinicianId !== (int) $visit['clinician_id']) {
            $this->auditAndThrow(
                $actorUserId,
                'visit',
                (int) $visit['id'],
                (int) $visit['patient_id'],
                'نسخه فقط برای ویزیت خودِ پزشک قابل ثبت است (ماتریس 4.3 — حساب شما به این پزشک/ویزیت متصل نیست)'
            );
        }
    }

    /**
     * بیمار متصل به کاربر (P-5) — برای نمای بیمار؛ نبود = 404.
     */
    private function requireOwnedPatient(int $wpUserId): int
    {
        $patientId = $this->db->fetchValue(
            'SELECT l.patient_id FROM ' . $this->db->table('cpms_patient_user_links') . ' l' .
            ' JOIN ' . $this->db->table('cpms_patients') . ' p ON p.id = l.patient_id' .
            ' WHERE l.wp_user_id = %d AND p.status = %s' .
            ' ORDER BY l.is_primary DESC, l.id ASC LIMIT 1',
            [$wpUserId, 'active']
        );
        if ($patientId === null) {
            throw ClinicalException::of('CLINIC_NOT_FOUND', 'بیماری به این حساب متصل نیست', 404);
        }

        return (int) $patientId;
    }

    private function requireCap(int $wpUserId, string $cap, string $scope): void
    {
        if (!user_can($wpUserId, $cap)) {
            throw ClinicalException::of('CLINIC_PERMISSION_DENIED', 'دسترسی لازم را ندارید', 403, ['scope' => $scope]);
        }
    }

    private function requireRole(int $wpUserId, string $role, string $action): void
    {
        $user = get_userdata($wpUserId);
        if ($user === false || $user->roles === []) {
            throw ClinicalException::of('CLINIC_PERMISSION_DENIED', "فقط {$role} می‌تواند {$action} انجام دهد", 403);
        }
        $expected = $role === 'doctor' ? RolesAndCapabilities::ROLE_DOCTOR : RolesAndCapabilities::ROLE_SECRETARY;
        if (!in_array($expected, $user->roles, true)) {
            throw ClinicalException::of('CLINIC_PERMISSION_DENIED', "فقط {$role} می‌تواند {$action} انجام دهد", 403);
        }
    }

    /**
     * @return array{wp_user_id: int, role: string}
     */
    private function actor(int $wpUserId, string $role): array
    {
        return ['wp_user_id' => $wpUserId, 'role' => $role];
    }

    /**
     * IDOR/دسترسی غیرمجاز → Audit + 404 (وجود منبع فاش نمی‌شود).
     */
    private function auditAndThrow(int $wpUserId, string $resourceType, int $resourceId, int $patientId, string $message): void
    {
        $user = get_userdata($wpUserId);
        $this->audit->log(
            'FORBIDDEN_ACCESS_ATTEMPT',
            ['wp_user_id' => $wpUserId, 'role' => ($user->roles[0] ?? 'unknown')],
            $resourceType,
            $resourceId,
            $patientId,
            null,
            null,
            ['reason' => $message]
        );
        throw ClinicalException::of('CLINIC_NOT_FOUND', $message, 404);
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function validateRxItem(array $item): array
    {
        $generic = trim((string) ($item['generic_name'] ?? ''));
        $dose = trim((string) ($item['dose'] ?? ''));
        $frequency = trim((string) ($item['frequency'] ?? ''));
        $form = (string) ($item['form'] ?? 'tablet');
        $route = (string) ($item['route'] ?? 'oral');

        if ($generic === '' || mb_strlen($generic) > 190) {
            throw ClinicalException::of('CLINIC_VALIDATION_FAILED', 'نام ژنریک دارو الزامی است (حداکثر ۱۹۰ نویسه)', 422);
        }
        if ($dose === '' || mb_strlen($dose) > 64) {
            throw ClinicalException::of('CLINIC_VALIDATION_FAILED', 'مقدار مصرف (dose) الزامی است', 422, ['generic_name' => $generic]);
        }
        if ($frequency === '' || mb_strlen($frequency) > 64) {
            throw ClinicalException::of('CLINIC_VALIDATION_FAILED', 'تکرار مصرف (frequency) الزامی است', 422, ['generic_name' => $generic]);
        }
        if (!in_array($form, self::RX_FORMS, true)) {
            throw ClinicalException::of('CLINIC_VALIDATION_FAILED', 'فرم دارو نامعتبر است', 422, ['form' => $form]);
        }
        if (!in_array($route, self::RX_ROUTES, true)) {
            throw ClinicalException::of('CLINIC_VALIDATION_FAILED', 'مسیر مصرف نامعتبر است', 422, ['route' => $route]);
        }

        $durationDays = isset($item['duration_days']) ? (int) $item['duration_days'] : null;
        if ($durationDays !== null && ($durationDays < 1 || $durationDays > 3650)) {
            throw ClinicalException::of('CLINIC_VALIDATION_FAILED', 'مدت مصرف باید بین ۱ تا ۳۶۵۰ روز باشد', 422);
        }

        $drugRefId = isset($item['drug_ref_id']) ? (int) $item['drug_ref_id'] : null;
        if ($drugRefId !== null) {
            $exists = $this->db->fetchValue(
                'SELECT id FROM ' . $this->db->table('cpms_drug_reference') . ' WHERE id = %d LIMIT 1',
                [$drugRefId]
            );
            if ($exists === null) {
                throw ClinicalException::of('CLINIC_NOT_FOUND', 'داروی مرجع (drug_ref_id) یافت نشد', 404);
            }
        }

        return [
            'drug_ref_id' => $drugRefId,
            'generic_name' => $generic,
            'brand_name' => isset($item['brand_name']) ? mb_substr(trim((string) $item['brand_name']), 0, 190) : null,
            'strength' => isset($item['strength']) ? mb_substr(trim((string) $item['strength']), 0, 64) : null,
            'form' => $form,
            'dose' => $dose,
            'frequency' => $frequency,
            'route' => $route,
            'duration_days' => $durationDays,
            'instructions' => isset($item['instructions']) ? mb_substr(trim((string) $item['instructions']), 0, 500) : null,
            'source' => 'manual',
        ];
    }

    // ================= Helpers — Presentation =================

    /**
     * @param array<string, mixed> $patient
     *
     * @return array<string, mixed>
     */
    private function patientMedicalView(array $patient): array
    {
        return [
            'id' => (int) $patient['id'],
            'mrn' => (string) $patient['mrn'],
            'full_name' => trim($patient['first_name'] . ' ' . $patient['last_name']),
            'gender' => (string) $patient['gender'],
            'birth_date' => $patient['birth_date'],
            'age' => $patient['birth_date'] !== null ? $this->ageFromBirthDate((string) $patient['birth_date']) : null,
            'blood_group' => $patient['blood_group'],
            'allergies' => [
                'medication' => $this->decodeJson($patient['medication_allergies'] ?? null),
                'other' => $this->decodeJson($patient['other_allergies'] ?? null),
            ],
            'chronic_conditions' => $this->decodeJson($patient['chronic_conditions'] ?? null),
            'current_medications' => $this->decodeJson($patient['current_medications'] ?? null),
            'medical_history' => $patient['medical_history'],
            'surgery_history' => $patient['surgery_history'],
        ];
    }

    /**
     * @param array<string, mixed> $note
     *
     * @return array<string, mixed>
     */
    private function presentNote(array $note): array
    {
        return [
            'id' => (int) $note['id'],
            'visit_id' => (int) $note['visit_id'],
            'category' => (string) $note['category'],
            'visibility' => (string) $note['visibility'],
            'content_text' => (string) $note['content_text'],
            'version' => (int) $note['version'],
            'change_reason' => $note['change_reason'],
            'created_by_wp_user_id' => (int) $note['created_by_wp_user_id'],
            'updated_by_wp_user_id' => $note['updated_by_wp_user_id'] !== null ? (int) $note['updated_by_wp_user_id'] : null,
            'created_at' => (string) $note['created_at'],
            'updated_at' => (string) $note['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed>                $rx
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private function presentPrescription(array $rx, array $items): array
    {
        return [
            'id' => (int) $rx['id'],
            'prescription_number' => (string) $rx['prescription_number'],
            'visit_id' => (int) $rx['visit_id'],
            'status' => (string) $rx['status'],
            'is_patient_visible' => (int) $rx['is_patient_visible'] === 1,
            'void_reason' => $rx['void_reason'] ?? null,
            'correction_of_prescription_id' => $rx['correction_of_prescription_id'] !== null ? (int) $rx['correction_of_prescription_id'] : null,
            'finalized_at' => $rx['finalized_at'] ?? null,
            'created_at' => (string) $rx['created_at'],
            'items' => array_map(static fn (array $i): array => [
                'id' => (int) $i['id'],
                'drug_ref_id' => $i['drug_ref_id'] !== null ? (int) $i['drug_ref_id'] : null,
                'generic_name' => (string) $i['generic_name'],
                'brand_name' => $i['brand_name'],
                'strength' => $i['strength'],
                'form' => (string) $i['form'],
                'dose' => (string) $i['dose'],
                'frequency' => (string) $i['frequency'],
                'route' => (string) $i['route'],
                'duration_days' => $i['duration_days'] !== null ? (int) $i['duration_days'] : null,
                'instructions' => $i['instructions'],
            ], $items),
        ];
    }

    /**
     * @param array<string, mixed> $rec
     *
     * @return array<string, mixed>
     */
    private function presentRecommendation(array $rec): array
    {
        return [
            'id' => (int) $rec['id'],
            'visit_id' => (int) $rec['visit_id'],
            'type' => (string) $rec['type'],
            'text' => (string) $rec['text'],
            'is_patient_visible' => (int) $rec['is_patient_visible'] === 1,
            'created_at' => (string) $rec['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $fu
     *
     * @return array<string, mixed>
     */
    private function presentFollowUp(array $fu): array
    {
        return [
            'id' => (int) $fu['id'],
            'visit_id' => (int) $fu['visit_id'],
            'is_needed' => (int) $fu['is_needed'] === 1,
            'suggested_date' => $fu['suggested_date'],
            'interval_days' => $fu['interval_days'] !== null ? (int) $fu['interval_days'] : null,
            'reason' => $fu['reason'],
            'status' => (string) $fu['status'],
            'created_at' => (string) $fu['created_at'],
        ];
    }

    /**
     * @return list<mixed>|null
     */
    private function decodeJson(?string $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function ageFromBirthDate(string $birthDate): ?int
    {
        $ts = strtotime($birthDate);
        if ($ts === false) {
            return null;
        }

        return max(0, (int) floor((time() - $ts) / (365.2425 * 86400)));
    }

    private function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);

        return $d !== false && $d->format('Y-m-d') === $date;
    }
}
