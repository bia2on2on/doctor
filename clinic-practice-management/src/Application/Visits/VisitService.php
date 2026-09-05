<?php

declare(strict_types=1);

namespace ClinicCore\Application\Visits;

use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Domain\Machine\InvalidTransitionException;
use ClinicCore\Domain\Machine\VisitMachine;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Repository\VisitRepository;
/**
 * سرویس Check-in و صف (F4 — Slice 1).
 *
 * مسئولیت این Slice: ساخت Visit زمان‌بندی‌شده/Walk-in، Enqueue خودکار،
 * Transitionهای صف و ثبت History به‌صورت اتمیک. بالینی و مالی عمداً در F5/F6 باقی می‌مانند.
 */
final class VisitService
{
    private const ACTIVE_STATUSES = [
        'checked_in', 'waiting', 'called', 'in_consultation',
        'consultation_completed', 'awaiting_payment', 'paid',
    ];

    public function __construct(
        private readonly CpmsDb $db,
        private readonly VisitRepository $visits,
        private readonly VisitMachine $machine,
        private readonly AuditLogger $audit,
        private readonly OpLogger $op
    ) {
    }

    /** @return array<string, mixed> */
    public function checkIn(int $actorUserId, int $patientId, int $appointmentId): array
    {
        return $this->db->transactional(function () use ($actorUserId, $patientId, $appointmentId): array {
            $appointment = $this->db->fetchRowForUpdate(
                'SELECT * FROM ' . $this->db->table('cpms_appointments') . ' WHERE id = %d LIMIT 1',
                [$appointmentId]
            );
            if ($appointment === null) {
                throw BookingException::of('CLINIC_NOT_FOUND', 'نوبت یافت نشد', 404);
            }
            if ((int) $appointment['patient_id'] !== $patientId) {
                throw BookingException::of('CLINIC_VALIDATION_FAILED', 'بیمار با نوبت همخوانی ندارد');
            }
            if ((string) $appointment['status'] !== 'confirmed') {
                throw BookingException::of('CLINIC_INVALID_TRANSITION', 'فقط نوبت تأییدشده قابل Check-in است', 409);
            }
            if (!empty($appointment['active_visit_id'])) {
                $existing = $this->visits->find((int) $appointment['active_visit_id']);
                if ($existing !== null) {
                    return $this->view($existing);
                }
            }

            $date = (string) $appointment['slot_date'];
            $clinicianId = (int) $appointment['clinician_id'];
            $this->assertNoActiveVisit($patientId, $clinicianId, $date);
            $now = $this->db->nowUtcSql();
            $visitId = $this->visits->create([
                'clinic_id' => (int) ($appointment['clinic_id'] ?? 1),
                'clinician_id' => $clinicianId,
                'patient_id' => $patientId,
                'appointment_id' => $appointmentId,
                'source' => 'scheduled',
                'status' => 'checked_in',
                'visit_date' => $date,
                'check_in_at' => $now,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->visits->addHistory($visitId, null, 'checked_in', $actorUserId, 'secretary', null);
            $this->visits->linkAppointment($appointmentId, $visitId);

            // تصمیم F4: Enqueue پیش‌فرض بلافاصله پس از Check-in.
            $this->enqueueWithinTransaction($visitId, 'checked_in', $actorUserId, 'secretary');
            $row = $this->visits->find($visitId);
            $this->audit('VISIT_CHECKED_IN', $actorUserId, $visitId, $patientId, ['appointment_id' => $appointmentId]);

            return $this->view($row ?? []);
        });
    }

    /** @return array<string, mixed> */
    public function walkIn(int $actorUserId, int $patientId, int $clinicianId, ?string $date = null): array
    {
        $date ??= gmdate('Y-m-d');
        $this->assertDate($date);

        return $this->db->transactional(function () use ($actorUserId, $patientId, $clinicianId, $date): array {
            $this->assertNoActiveVisit($patientId, $clinicianId, $date);
            $now = $this->db->nowUtcSql();
            $visitId = $this->visits->create([
                'clinic_id' => 1,
                'clinician_id' => $clinicianId,
                'patient_id' => $patientId,
                'appointment_id' => null,
                'source' => 'walk_in',
                'status' => 'checked_in',
                'visit_date' => $date,
                'check_in_at' => $now,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->visits->addHistory($visitId, null, 'checked_in', $actorUserId, 'secretary', null);
            $this->enqueueWithinTransaction($visitId, 'checked_in', $actorUserId, 'secretary');
            $row = $this->visits->find($visitId);
            $this->audit('VISIT_WALK_IN_CREATED', $actorUserId, $visitId, $patientId, ['clinician_id' => $clinicianId]);

            return $this->view($row ?? []);
        });
    }

    /** @return array<string, mixed> */
    public function transition(int $actorUserId, string $actorRole, int $visitId, string $event, ?string $note = null): array
    {
        return $this->db->transactional(function () use ($actorUserId, $actorRole, $visitId, $event, $note): array {
            $visit = $this->visits->findForUpdate($visitId);
            if ($visit === null) {
                throw BookingException::of('CLINIC_NOT_FOUND', 'مراجعه یافت نشد', 404);
            }
            if (in_array($event, ['skip', 'cancel'], true) && trim((string) $note) === '') {
                throw BookingException::of('CLINIC_VALIDATION_FAILED', 'دلیل این اقدام الزامی است');
            }
            if ($event === 'recall' && (int) $visit['recall_count'] >= 3) {
                throw BookingException::of('CLINIC_RECALL_LIMIT', 'تعداد Recall مجاز تمام شده است', 409);
            }

            $to = $this->machine->machine()->assert((string) $visit['status'], $event, $actorRole);
            $fields = ['status' => $to, 'updated_at' => $this->db->nowUtcSql()];
            $now = $fields['updated_at'];
            if ($event === 'enqueue') {
                $fields['waiting_since'] = $now;
            } elseif ($event === 'call') {
                $fields['called_at'] = $now;
            } elseif ($event === 'start') {
                $fields['consultation_started_at'] = $now;
            } elseif ($event === 'complete') {
                $fields['consultation_completed_at'] = $now;
            } elseif ($event === 'check_out') {
                $fields['checked_out_at'] = $now;
                $fields['active'] = 0;
            } elseif ($event === 'recall') {
                $fields['recall_count'] = (int) $visit['recall_count'] + 1;
                $fields['waiting_since'] = $now;
            } elseif ($event === 'skip') {
                $fields['skip_reason'] = trim((string) $note);
                $fields['active'] = 0;
            } elseif ($event === 'cancel') {
                $fields['cancel_reason'] = trim((string) $note);
                $fields['cancelled_by_wp_user_id'] = $actorUserId;
                $fields['active'] = 0;
            }

            $this->visits->update($visitId, $fields);
            $this->visits->addHistory($visitId, (string) $visit['status'], $to, $actorUserId, $actorRole, $note);
            $this->audit('VISIT_STATUS_CHANGED', $actorUserId, $visitId, (int) $visit['patient_id'], [
                'from' => (string) $visit['status'], 'to' => $to, 'event' => $event,
            ]);
            $updated = $this->visits->find($visitId);

            return $this->view($updated ?? []);
        });
    }

    /** @return list<array<string, mixed>> */
    public function queue(int $clinicianId, string $date, ?string $status = null): array
    {
        $this->assertDate($date);

        return array_map(fn (array $row): array => $this->view($row), $this->visits->queue(1, $clinicianId, $date, $status));
    }

    private function enqueueWithinTransaction(int $visitId, string $from, int $actorUserId, string $role): void
    {
        $now = $this->db->nowUtcSql();
        $this->visits->update($visitId, ['status' => 'waiting', 'waiting_since' => $now, 'updated_at' => $now]);
        $this->visits->addHistory($visitId, $from, 'waiting', $actorUserId, $role, null);
    }

    private function assertNoActiveVisit(int $patientId, int $clinicianId, string $date): void
    {
        if ($this->visits->findActiveForPatientDay(1, $patientId, $clinicianId, $date) !== null) {
            throw BookingException::of('CLINIC_DUPLICATE_ACTIVE_VISIT', 'برای این بیمار در این روز مراجعه فعال وجود دارد', 409);
        }
    }

    private function assertDate(string $date): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', 'تاریخ نامعتبر است');
        }
    }

    /** @param array<string, mixed> $row */
    private function view(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'patient_id' => (int) ($row['patient_id'] ?? 0),
            'clinician_id' => (int) ($row['clinician_id'] ?? 0),
            'appointment_id' => ($row['appointment_id'] ?? null) !== null ? (int) $row['appointment_id'] : null,
            'source' => (string) ($row['source'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'visit_date' => (string) ($row['visit_date'] ?? ''),
            'check_in_at' => $row['check_in_at'] ?? null,
            'waiting_since' => $row['waiting_since'] ?? null,
            'called_at' => $row['called_at'] ?? null,
            'recall_count' => (int) ($row['recall_count'] ?? 0),
        ];
    }

    private function audit(string $action, int $actorUserId, int $visitId, int $patientId, array $meta): void
    {
        $this->audit->log($action, ['wp_user_id' => $actorUserId, 'role' => 'secretary'], 'visit', $visitId, $patientId, null, null, $meta);
        $this->op->info(strtolower($action), ['visit_id' => $visitId, 'patient_id' => $patientId]);
    }
}
