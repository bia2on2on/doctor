<?php

declare(strict_types=1);

namespace ClinicCore\Application\Visits;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Domain\Machine\AppointmentMachine;
use ClinicCore\Domain\Machine\InvalidTransitionException;
use ClinicCore\Domain\Machine\VisitMachine;
use ClinicCore\Domain\Visits\VisitException;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
use ClinicCore\Infrastructure\Repository\VisitRepository;
use ClinicCore\Settings\Settings;
use Throwable;

/**
 * سرویس مراجعه/صف (F4) — Check-in/Walk-in + State Machine + Real-time Feed.
 *
 * تضمین‌ها:
 *  - **J-1 (یکتایی V10)**: هر Transition روی Row Lock (FOR UPDATE) داخل
 *    Transaction — دو complete/call هم‌زمان فقط یکی موفق (TP-03b, ADR-0004).
 *  - **J-2 (J-5 در SRS)**: قانون ویزیت Active واحد — Lock رکورد بیمار، سپس
 *    چک تکرار (`CLINIC_DUPLICATE_ACTIVE_VISIT`).
 *  - **J-3**: هر تغییر وضعیت → ردیف append-only در cpms_visit_status_history
 *    (همزمان Feed رئال‌تایم R1).
 *  - **ER-06/FR-5.5**: Check-in دیرهنگام → no_show روی Appointment + Visit
 *    فوری Walk-in-like با ارجاع به همان نوبت (Lazy + Cron Job).
 *  - **T9**: check_out → نوبت مرجع (در صورت وجود) completed می‌شود.
 *  - زمان‌بندی: همه مقادیر UTC در DB (ADR-0013)؛ ترتیب صف J-4 در Repository.
 */
final class VisitService
{
    private const ACTIVE_VISIT_STATUSES = [
        'checked_in', 'waiting', 'called', 'in_consultation',
        'consultation_completed', 'awaiting_payment', 'paid',
    ];

    private const QUEUE_STATUSES = ['waiting', 'called', 'in_consultation'];

    public function __construct(
        private readonly CpmsDb $db,
        private readonly VisitRepository $visits,
        private readonly AppointmentRepository $appointments,
        private readonly Settings $settings,
        private readonly AuditLogger $audit
    ) {
    }

    // ================= V1 — Check-in (D6) =================

    /**
     * Check-in بیمار دارای نوبت — یا نرمال (داخل Grace) یا ER-06 دیرهنگام.
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed> رکورد Visit
     */
    public function checkIn(int $actorUserId, int $patientId, int $appointmentId, array $meta = []): array
    {
        $this->requireSecretary($actorUserId, 'check_in');
        $actorRole = 'secretary';

        return $this->db->transactional(function () use ($actorUserId, $actorRole, $patientId, $appointmentId, $meta): array {
            $this->lockPatient($patientId);

            $appt = $this->appointments->find($appointmentId);
            if ($appt === null) {
                throw VisitException::of('CLINIC_NOT_FOUND', 'نوبت یافت نشد', 404);
            }
            if ((int) $appt['patient_id'] !== $patientId) {
                $this->auditAndThrow(
                    $actorUserId, $actorRole, 'FORBIDDEN_ACCESS_ATTEMPT', 'visit', $appointmentId, $patientId,
                    'نوبت به این بیمار تعلق ندارد', 403, 'CLINIC_PERMISSION_DENIED'
                );
            }

            $this->guardDuplicateActiveVisit($patientId, (int) $appt['clinician_id']);

            // ER-06: نوبت پایان‌یافته/لغوشده قابل Check-in نیست؛
            // دیرهنگام (پس از Grace) → no_show + Visit فوری Walk-in-like (ارجاع حفظ می‌شود).
            $source = 'scheduled';
            $status = (string) $appt['status'];
            $now = $this->db->nowUtcSql();
            $grace = $this->noShowGraceMinutes();
            $slotStart = $this->appointmentStartTime($appt);

            if (in_array($status, ['cancelled_by_patient', 'cancelled_by_staff', 'rescheduled', 'completed'], true)) {
                throw VisitException::of(
                    'CLINIC_INVALID_APPOINTMENT_STATE',
                    'این نوبت ' . $this->appointmentStatusLabel($status) . ' است و قابل Check-in نیست',
                    409,
                    ['appointment_status' => $status]
                );
            }
            if ($status === 'no_show') {
                // بیمار دیر آمد و قبلاً no_show خورده → Visit فوری Walk-in-like (ER-06)
                $source = 'walk_in';
            } elseif ($slotStart !== null && strtotime($slotStart . ' +' . $grace . ' minutes') < strtotime($now)) {
                // Lazy no-show (FR-5.5) — سپس Visit فوری Walk-in-like
                $this->markAppointmentNoShow($appt, $now, $actorUserId);
                $source = 'walk_in';
            } elseif ($status === 'pending') {
                // حضور بیمار = تایید نوبت (T3) — تا Checkout مسیر کامل شود
                $this->confirmAppointment($appt, $now, $actorUserId);
            }

            $visit = $this->createVisit(
                $actorUserId,
                $patientId,
                (int) $appt['clinician_id'],
                $appointmentId,
                $source,
                'check_in',
                isset($meta['note']) ? (string) $meta['note'] : null
            );

            $this->audit('VISIT_CHECKED_IN', $actorUserId, $actorRole, 'visit', (int) $visit['id'], $patientId, null, $visit, [
                'appointment_id' => $appointmentId,
                'source' => $source,
            ]);

            return $visit;
        });
    }

    // ================= V2 — Walk-in (D7) =================

    /**
     * ثبت مراجعه بدون نوبت (Walk-in) — بیمار در کلینیک حاضر است.
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function walkIn(int $actorUserId, int $patientId, int $clinicianId, array $meta = []): array
    {
        $this->requireSecretary($actorUserId, 'create_walk_in');
        $actorRole = 'secretary';

        return $this->db->transactional(function () use ($actorUserId, $actorRole, $patientId, $clinicianId, $meta): array {
            $this->lockPatient($patientId);
            $this->requireClinician($clinicianId);

            $this->guardDuplicateActiveVisit($patientId, $clinicianId);

            $visit = $this->createVisit(
                $actorUserId,
                $patientId,
                $clinicianId,
                null,
                'walk_in',
                'create_walk_in',
                isset($meta['note']) ? (string) $meta['note'] : null
            );

            $this->audit('VISIT_WALK_IN', $actorUserId, $actorRole, 'visit', (int) $visit['id'], $patientId, null, $visit, []);

            return $visit;
        });
    }

    // ================= V3..V15 — Transitionهای عمومی (D8 / E3..E6) =================

    /**
     * اجرای یک Event ماشین روی ویزیت — با Row Lock (J-1).
     *
     * Events: enqueue|cancel|call|recall|start|skip|complete|reopen|
     *         invoice_ready|waive|check_out
     *
     * @param array<string, mixed> $meta {reason?, room?, note?}
     * @return array<string, mixed> رکورد به‌روزشده
     */
    public function transition(
        int $actorUserId,
        int $visitId,
        string $event,
        array $meta = []
    ): array {
        return $this->db->transactional(function () use ($actorUserId, $visitId, $event, $meta): array {
            $visit = $this->visits->findForUpdate($visitId);
            if ($visit === null) {
                throw VisitException::of('CLINIC_NOT_FOUND', 'مراجعه یافت نشد', 404);
            }

            return $this->applyTransition($actorUserId, $visit, $event, $meta);
        });
    }

    /**
     * اجرای Transition روی Visit از قبل Lock شده — **بدون** Transaction
     * (فراخواننده باید داخل transactional باشد؛ J-1 با Row Lock).
     *
     * @param array<string, mixed> $visit
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function applyTransition(int $actorUserId, array $visit, string $event, array $meta): array
    {
        $visitId = (int) $visit['id'];
        $actorRole = $this->roleForUser($actorUserId) ?? 'secretary';
        $fromStatus = (string) $visit['status'];

        $toStatus = $this->machineCheck($fromStatus, $event, $actorRole);
        $row = $this->patchForEvent($visit, $event, $toStatus, $actorUserId, $meta);
        $this->visits->updateById($visitId, $row);

        $this->visits->insertHistory($visitId, [
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_wp_user_id' => $actorUserId,
            'actor_role' => $actorRole,
            'note' => $this->historyNote($event, $meta),
            'request_id' => null,
        ]);

        $visit = array_merge($visit, $row, ['status' => $toStatus]);

        // T9: خروج نهایی (پرداخت‌شده یا معافیت) → نوبت مرجع completed
        if (in_array($event, ['check_out', 'waive'], true) && !empty($visit['appointment_id'])) {
            $this->completeReferencedAppointment((int) $visit['appointment_id'], $actorUserId);
        }

        $this->audit('VISIT_' . strtoupper($event), $actorUserId, $actorRole, 'visit', $visitId, (int) $visit['patient_id'], null, $visit, [
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
        ]);

        return $this->presentVisit($visit);
    }

    // ================= D1/E1 — داشبورد امروز =================

    /**
     * داشبورد امروز: صف زنده + آمار + پزشکان فعال.
     *
     * @return array<string, mixed>
     */
    public function today(int $actorUserId, ?int $clinicianId = null): array
    {
        $this->requireQueueReader($actorUserId);

        $queue = $this->visits->queueFor(1, $clinicianId, self::QUEUE_STATUSES);
        $stats = $this->visits->statsFor(1);

        return [
            'date' => gmdate('Y-m-d'),
            'stats' => $stats,
            'queue' => array_map([$this, 'presentVisit'], $queue),
            'last_event_id' => $this->visits->lastEventId(1),
        ];
    }

    // ================= R1 — Feed رئال‌تایم (ADR-0007) =================

    /**
     * رویدادهای صف بعد از since — Light Endpoint برای Polling کنترل‌شده.
     *
     * @return array<string, mixed>
     */
    public function eventsSince(int $actorUserId, int $sinceEventId): array
    {
        $this->requireQueueReader($actorUserId);

        $events = $this->visits->eventsSince(1, max(0, $sinceEventId));
        $lastId = $sinceEventId;
        foreach ($events as $e) {
            $lastId = max($lastId, (int) $e['id']);
        }

        return [
            'events' => array_map(static fn (array $e): array => [
                'event_id' => (int) $e['id'],
                'visit_id' => (int) $e['visit_id'],
                'from_status' => $e['from_status'],
                'to_status' => $e['to_status'],
                'changed_at' => $e['changed_at'],
                'actor_role' => $e['actor_role'],
                'note' => $e['note'],
            ], $events),
            'last_event_id' => $lastId,
        ];
    }

    /**
     * آخرین event_id کلینیک — ETag کلاینت (R1).
     */
    public function lastEventId(int $actorUserId): int
    {
        $this->requireQueueReader($actorUserId);

        return $this->visits->lastEventId(1);
    }

    // ================= D16 — Checkout (T9) =================

    /**
     * خروج از کلینیک:
     *  - paid → check_out (V14 — منشی)
     *  - awaiting_payment + معافیت → waive (V13 — منشی/پزشک)
     *  - بدون پرداخت/معافیت → CLINIC_POLICY_VIOLATION (فاکتور/پرداخت واقعی = F6)
     *
     * @return array<string, mixed>
     */
    public function checkout(int $actorUserId, int $visitId, ?string $waiveReason): array
    {
        return $this->db->transactional(function () use ($actorUserId, $visitId, $waiveReason): array {
            $visit = $this->visits->findForUpdate($visitId);
            if ($visit === null) {
                throw VisitException::of('CLINIC_NOT_FOUND', 'مراجعه یافت نشد', 404);
            }

            $status = (string) $visit['status'];
            if ($status === 'awaiting_payment') {
                if ($waiveReason === null || trim($waiveReason) === '') {
                    throw VisitException::of(
                        'CLINIC_POLICY_VIOLATION',
                        'پرداخت هنوز ثبت نشده است — معافیت (waive) نیاز به دلیل دارد یا ابتدا پرداخت را ثبت کنید',
                        409,
                        ['visit_status' => $status]
                    );
                }

                return $this->applyTransition($actorUserId, $visit, 'waive', ['reason' => $waiveReason]);
            }
            if ($status === 'consultation_completed') {
                throw VisitException::of(
                    'CLINIC_POLICY_VIOLATION',
                    'ابتدا وضعیت مالی ویزیت را مشخص کنید (فاکتور/معافیت)',
                    409,
                    ['visit_status' => $status]
                );
            }

            // paid → check_out (V14)؛ سایر وضعیت‌ها → ماشین خطای transition می‌دهد
            return $this->applyTransition($actorUserId, $visit, 'check_out', []);
        });
    }

    // ================= FR-5.5 — No-show خودکار (Cron) =================

    /**
     * نوبت‌های بدون مراجعه پس از Grace → no_show (اگر Visit فعالی ندارند).
     *
     * @return int تعداد نوبت‌های no_show شده
     */
    public function processNoShows(): int
    {
        $grace = $this->noShowGraceMinutes();
        $before = gmdate('Y-m-d H:i:s', time() - ($grace * 60));
        $count = 0;

        foreach ($this->visits->appointmentsPastGrace($before) as $appt) {
            $count += $this->db->transactional(function () use ($appt): int {
                // دوباره-check داخل Lock — race با Check-in هم‌زمان
                $fresh = $this->appointments->findForUpdate((int) $appt['id']);
                if ($fresh === null || (string) $fresh['status'] !== 'confirmed') {
                    return 0;
                }
                if ($fresh['active_visit_id'] !== null) {
                    return 0;
                }
                $this->markAppointmentNoShow($fresh, $this->db->nowUtc(), null);

                return 1;
            });
        }

        return $count;
    }

    // ================= History (J-3) =================

    /**
     * @return list<array<string, mixed>>
     */
    public function history(int $actorUserId, int $visitId): array
    {
        $this->requireQueueReader($actorUserId);
        $visit = $this->visits->find($visitId);
        if ($visit === null) {
            throw VisitException::of('CLINIC_NOT_FOUND', 'مراجعه یافت نشد', 404);
        }

        return array_map(static fn (array $h): array => [
            'event_id' => (int) $h['id'],
            'from_status' => $h['from_status'],
            'to_status' => $h['to_status'],
            'changed_at' => $h['changed_at'],
            'actor_wp_user_id' => $h['actor_wp_user_id'] !== null ? (int) $h['actor_wp_user_id'] : null,
            'actor_role' => $h['actor_role'],
            'note' => $h['note'],
        ], $this->visits->historyFor($visitId));
    }

    /**
     * خواندن یک ویزیت — کنترل دسترسی در Controller (capability) + اینجا فقط وجود.
     *
     * @return array<string, mixed>
     */
    public function getVisit(int $actorUserId, int $visitId): array
    {
        $this->requireQueueReader($actorUserId);
        $visit = $this->visits->find($visitId);
        if ($visit === null) {
            throw VisitException::of('CLINIC_NOT_FOUND', 'مراجعه یافت نشد', 404);
        }

        return $this->presentVisit($visit);
    }

    // ================= Helpers — ساخت و Transition =================

    /** @var string|null from_status برای audit (داخل transactional transition) */
    private ?string $lastFrom = null;

    /**
     * ساخت Visit + تاریخچه Check-in + Enqueue خودکار (FR-6.1) + active_visit_id.
     *
     * @return array<string, mixed>
     */
    private function createVisit(
        int $actorUserId,
        int $patientId,
        int $clinicianId,
        ?int $appointmentId,
        string $source,
        string $event,
        ?string $note = null
    ): array {
        $now = $this->db->nowUtc();
        $visitId = $this->visits->insert([
            'clinic_id' => 1,
            'clinician_id' => $clinicianId,
            'patient_id' => $patientId,
            'appointment_id' => $appointmentId,
            'source' => $source,
            'status' => 'checked_in',
            'visit_date' => gmdate('Y-m-d'),
            'check_in_at' => $now,
        ]);

        $this->visits->insertHistory($visitId, [
            'from_status' => null,
            'to_status' => 'checked_in',
            'actor_wp_user_id' => $actorUserId,
            'actor_role' => 'secretary',
            'note' => $note ?? ($source === 'scheduled' ? 'Check-in با نوبت' : 'Check-in (Walk-in)'),
            'request_id' => null,
        ]);

        // D-6: رابطه دوطرفه — active_visit_id روی Appointment
        if ($appointmentId !== null) {
            $this->appointments->updateStatus($appointmentId, ['active_visit_id' => $visitId]);
        }

        // FR-6.1: Enqueue خودکار (پیش‌فرض روشن) — actor=system مجاز ماشین V3
        if ((bool) $this->settings->get('queue.auto_enqueue', true)) {
            $this->applyEnqueue($visitId, 'checked_in', $actorUserId, $now);
        }

        return $this->presentVisit($this->visits->find($visitId) ?? ['id' => $visitId]);
    }

    /**
     * Enqueue (V3) — تغییر وضعیت + مهر waiting_since + تاریخچه.
     */
    private function applyEnqueue(int $visitId, string $fromStatus, int $actorUserId, string $now): void
    {
        $toStatus = $this->machineCheck($fromStatus, 'enqueue', 'system');
        $this->visits->updateById($visitId, ['status' => $toStatus, 'waiting_since' => $now]);
        $this->visits->insertHistory($visitId, [
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_wp_user_id' => $actorUserId,
            'actor_role' => 'system',
            'note' => 'افزودن خودکار به صف',
            'request_id' => null,
        ]);
    }

    /**
     * آماده‌سازی patch ستون‌ها بر اساس Event.
     *
     * @param array<string, mixed> $visit
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function patchForEvent(array $visit, string $event, string $toStatus, int $actorUserId, array $meta): array
    {
        $now = $this->db->nowUtc();
        $row = ['status' => $toStatus];

        switch ($event) {
            case 'enqueue':
                $row['waiting_since'] = $visit['waiting_since'] ?? $now;
                break;
            case 'call':
                $row['called_at'] = $now;
                break;
            case 'recall':
                // J-6: سقف Recall از Settings
                $recallCount = (int) $visit['recall_count'];
                $max = (int) $this->settings->get('queue.max_recalls', 3);
                if ($recallCount >= $max) {
                    throw VisitException::of(
                        'CLINIC_RECALL_LIMIT_REACHED',
                        'سقف فراخوان مجدد (' . $max . ') پر شده است',
                        409,
                        ['recall_count' => $recallCount, 'max_recalls' => $max]
                    );
                }
                $row['recall_count'] = $recallCount + 1;
                $row['called_at'] = null;
                $row['waiting_since'] = $now; // FIFO جدید — انتهای صف
                break;
            case 'start':
                $row['consultation_started_at'] = $now;
                break;
            case 'complete':
                $row['consultation_completed_at'] = $now;
                break;
            case 'check_out':
                $row['checked_out_at'] = $now;
                $row['active'] = 0;
                break;
            case 'cancel':
                $reason = trim((string) ($meta['reason'] ?? ''));
                if ($reason === '') {
                    throw VisitException::of('CLINIC_VALIDATION_FAILED', 'دلیل لغو الزامی است', 422);
                }
                $row['cancel_reason'] = mb_substr($reason, 0, 255);
                $row['cancelled_by_wp_user_id'] = $actorUserId;
                $row['active'] = 0;
                break;
            case 'skip':
                $reason = trim((string) ($meta['reason'] ?? ''));
                if ($reason === '') {
                    throw VisitException::of('CLINIC_VALIDATION_FAILED', 'دلیل رد شدن از صف الزامی است', 422);
                }
                $row['skip_reason'] = mb_substr($reason, 0, 255);
                $row['active'] = 0;
                break;
            case 'waive':
                $row['checked_out_at'] = $now;
                $row['active'] = 0;
                break;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function historyNote(string $event, array $meta): ?string
    {
        $note = trim((string) ($meta['reason'] ?? $meta['note'] ?? ''));
        if ($note !== '') {
            return mb_substr($note, 0, 255);
        }

        return match ($event) {
            'call' => !empty($meta['room']) ? 'فراخوان — اتاق ' . (string) $meta['room'] : 'فراخوان بیمار',
            'recall' => 'بازگشت به صف (فراخوان مجدد)',
            'start' => 'شروع ویزیت',
            'complete' => 'پایان ویزیت',
            'check_out' => 'خروج از کلینیک',
            default => null,
        };
    }

    /**
     * V10 یکتایی با Row Lock تضمین شده؛ اینجا فقط نگاشت خطای ماشین.
     */
    private function machineCheck(string $from, string $event, string $actor): string
    {
        try {
            return VisitMachine::create()->machine()->assert($from, $event, $actor);
        } catch (InvalidTransitionException $e) {
            throw VisitException::of(
                'CLINIC_INVALID_TRANSITION',
                $e->getMessage(),
                409,
                ['from' => $from, 'event' => $event]
            );
        }
    }

    // ================= Helpers — Guardها و داده =================

    private function guardDuplicateActiveVisit(int $patientId, int $clinicianId): void
    {
        $existing = $this->visits->findActiveByPatientDay($patientId, $clinicianId, gmdate('Y-m-d'));
        if ($existing !== null && in_array((string) $existing['status'], self::ACTIVE_VISIT_STATUSES, true)) {
            throw VisitException::of(
                'CLINIC_DUPLICATE_ACTIVE_VISIT',
                'این بیمار امروز ویزیت فعال (در جریان) دارد',
                409,
                ['visit_id' => (int) $existing['id'], 'visit_status' => (string) $existing['status']]
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function lockPatient(int $patientId): array
    {
        // J-5: Serialize per-patient — دو Check-in/Walk-in هم‌زمان همان بیمار
        $patient = $this->db->fetchRowForUpdate(
            'SELECT * FROM ' . $this->db->table('cpms_patients') . ' WHERE id = %d LIMIT 1',
            [$patientId]
        );
        if ($patient === null) {
            throw VisitException::of('CLINIC_NOT_FOUND', 'بیمار یافت نشد', 404);
        }

        return $patient;
    }

    private function requireClinician(int $clinicianId): void
    {
        // همان Guard الگوی BookingService
        $row = $this->db->fetchRow(
            'SELECT id, is_active FROM ' . $this->db->table('cpms_clinicians') . ' WHERE id = %d LIMIT 1',
            [$clinicianId]
        );
        if ($row === null || (int) $row['is_active'] !== 1) {
            throw VisitException::of('CLINIC_NOT_FOUND', 'پزشک یافت نشد یا غیرفعال است', 404);
        }
    }

    /**
     * نقش منطقی برای ماشین — از WP Role (نه فقط Capability).
     */
    private function roleForUser(int $wpUserId): ?string
    {
        $user = get_userdata($wpUserId);
        if ($user === false || $user->roles === []) {
            return null;
        }
        if (in_array(RolesAndCapabilities::ROLE_DOCTOR, $user->roles, true)) {
            return 'doctor';
        }
        if (in_array(RolesAndCapabilities::ROLE_SECRETARY, $user->roles, true)) {
            return 'secretary';
        }

        return null;
    }

    private function requireSecretary(int $wpUserId, string $event): void
    {
        $role = $this->roleForUser($wpUserId);
        if ($role !== 'secretary') {
            // ماشین: V1/V2 فقط secretary — نقش دیگر → خطای transition
            throw VisitException::of(
                'CLINIC_PERMISSION_DENIED',
                'فقط منشی می‌تواند ' . ($event === 'check_in' ? 'Check-in' : 'Walk-in') . ' ثبت کند',
                403
            );
        }
    }

    private function requireQueueReader(int $wpUserId): void
    {
        if (!user_can($wpUserId, RolesAndCapabilities::QUEUE_READ)) {
            throw VisitException::of('CLINIC_PERMISSION_DENIED', 'دسترسی به صف ندارید', 403);
        }
    }

    private function noShowGraceMinutes(): int
    {
        return max(0, (int) $this->settings->get('queue.no_show_grace_minutes', 30));
    }

    /**
     * @param array<string, mixed> $appt
     */
    private function appointmentStartTime(array $appt): ?string
    {
        if (empty($appt['slot_date']) || empty($appt['slot_time'])) {
            return null;
        }

        return $appt['slot_date'] . ' ' . $appt['slot_time'];
    }

    /**
     * @param array<string, mixed> $appt
     */
    private function confirmAppointment(array $appt, string $now, int $actorUserId): void
    {
        $toStatus = AppointmentMachine::create()->machine()->assert((string) $appt['status'], 'confirm', 'secretary');
        $this->appointments->updateStatus((int) $appt['id'], ['status' => $toStatus, 'confirmed_at' => $now]);
        $this->audit('APPOINTMENT_CONFIRMED', $actorUserId, 'secretary', 'appointment', (int) $appt['id'], (int) $appt['patient_id'], ['status' => $appt['status']], ['status' => $toStatus], [
            'via' => 'visit_check_in',
        ]);
    }

    /**
     * @param array<string, mixed> $appt
     */
    private function markAppointmentNoShow(array $appt, string $now, ?int $actorUserId): void
    {
        // ماشین Appointment فقط CONFIRMED → no_show را مجاز می‌کند (T8)؛
        // نوبت‌های PENDING مسیر انقضای خودشان (Hold/Cron) را دارند.
        $toStatus = AppointmentMachine::create()->machine()->assert((string) $appt['status'], 'no_show', 'system');
        $this->appointments->updateStatus((int) $appt['id'], ['status' => $toStatus, 'no_show_at' => $now]);
        $this->audit('APPOINTMENT_NO_SHOW', $actorUserId, $actorUserId === null ? 'system' : 'secretary', 'appointment', (int) $appt['id'], (int) $appt['patient_id'], ['status' => $appt['status']], ['status' => $toStatus], [
            'slot' => ($appt['slot_date'] ?? '') . ' ' . ($appt['slot_time'] ?? ''),
        ]);
    }

    private function completeReferencedAppointment(int $appointmentId, int $actorUserId): void
    {
        $appt = $this->appointments->findForUpdate($appointmentId);
        if ($appt === null || (string) $appt['status'] === 'completed') {
            return;
        }
        // T9: خروج بیمار → نوبت مرجع completed (system event ماشین).
        // نوبت‌های Terminal دیگر (no_show/cancelled) فقط رابطه را آزاد می‌کنند —
        // ER-06: وضعیت نوبت دیرهنگام حفظ می‌شود (تاریخچه).
        try {
            $toStatus = AppointmentMachine::create()->machine()->assert((string) $appt['status'], 'visit_checked_out', 'system');
        } catch (InvalidTransitionException $e) {
            $this->appointments->updateStatus($appointmentId, ['active_visit_id' => null]);

            return;
        }
        $this->appointments->updateStatus($appointmentId, ['status' => $toStatus, 'active_visit_id' => null]);
        $this->audit('APPOINTMENT_COMPLETED', $actorUserId, 'system', 'appointment', $appointmentId, (int) $appt['patient_id'], ['status' => $appt['status']], ['status' => $toStatus], [
            'via' => 'visit_check_out',
        ]);
    }

    /**
     * @param array<string, mixed> $visit
     * @return array<string, mixed>
     */
    private function presentVisit(array $visit): array
    {
        return [
            'id' => (int) $visit['id'],
            'patient_id' => (int) $visit['patient_id'],
            'patient_name' => trim(($visit['patient_first_name'] ?? '') . ' ' . ($visit['patient_last_name'] ?? '')),
            'clinician_id' => (int) $visit['clinician_id'],
            'clinician_name' => $visit['clinician_name'] ?? null,
            'appointment_id' => $visit['appointment_id'] !== null ? (int) $visit['appointment_id'] : null,
            'source' => (string) $visit['source'],
            'status' => (string) $visit['status'],
            'express' => !empty($visit['express']) && (int) $visit['express'] === 1,
            'check_in_at' => $visit['check_in_at'],
            'waiting_since' => $visit['waiting_since'],
            'called_at' => $visit['called_at'],
            'consultation_started_at' => $visit['consultation_started_at'],
            'consultation_completed_at' => $visit['consultation_completed_at'],
            'checked_out_at' => $visit['checked_out_at'],
            'recall_count' => (int) $visit['recall_count'],
            'active' => (int) ($visit['active'] ?? 1) === 1,
            'skip_reason' => $visit['skip_reason'] ?? null,
            'cancel_reason' => $visit['cancel_reason'] ?? null,
        ];
    }

    private function appointmentStatusLabel(string $status): string
    {
        return match ($status) {
            'cancelled_by_patient' => 'توسط بیمار لغو',
            'cancelled_by_staff' => 'توسط کلینیک لغو',
            'rescheduled' => 'جابه‌جا شده',
            'completed' => 'تکمیل شده',
            default => $status,
        };
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function auditAndThrow(
        int $wpUserId,
        string $role,
        string $action,
        string $resourceType,
        int $resourceId,
        int $patientId,
        string $message,
        int $http,
        string $code
    ): never {
        $this->audit($action, $wpUserId, $role, $resourceType, $resourceId, $patientId, null, null, []);
        throw VisitException::of($code, $message, $http);
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<string, mixed> $meta
     */
    private function audit(
        string $action,
        ?int $wpUserId,
        string $role,
        string $resourceType,
        ?int $resourceId,
        ?int $patientId,
        ?array $before,
        ?array $after,
        array $meta
    ): void {
        try {
            $this->audit->log(
                $action,
                ['wp_user_id' => $wpUserId, 'role' => $role],
                $resourceType,
                $resourceId,
                $patientId,
                $before,
                $after,
                $meta
            );
        } catch (Throwable $e) {
            // Audit نباید جریان عملیات بالینی را قطع کند (تطبیق الگوی BookingService)
        }
    }
}
