<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Application\Scope\PrimaryLocationResolver;
use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Repository مراجعه/صف — ADR-0021 (فقط Data-Access، بدون منطق دامنه).
 *
 * پرس‌جوهای صف بر اساس ایندکس‌های cpms_visits:
 *   idx_visit_day    (clinic_id, visit_date, status)  → داشبورد امروز / آمار
 *   idx_visit_queue  (clinician_id, status, waiting_since) → صف FIFO
 *   idx_visit_patient(patient_id, visit_date) → قانون J-5 (ویزیت فعال تکراری)
 *
 * ترتیب صف (J-4): نوبت فوری (walk-in express از نوبت مرجع، FR-7.3) اول،
 * سپس waiting_since صعودی (FIFO).
 */
final class VisitRepository
{
    public function __construct(private readonly CpmsDb $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_visits') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /**
     * Row Lock — داخل Transaction (الگوی ADR-0004؛ J-1/V10 یکتایی complete).
     *
     * @return array<string, mixed>|null
     */
    public function findForUpdate(int $id): ?array
    {
        return $this->db->fetchRowForUpdate(
            'SELECT * FROM ' . $this->db->table('cpms_visits') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /**
     * J-5: ویزیت Active همان بیمار×پزشک در همان روز (سخت‌گیرانه روی کل روز).
     *
     * @return array<string, mixed>|null
     */
    public function findActiveByPatientDay(int $patientId, int $clinicianId, string $visitDate): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_visits') .
            ' WHERE patient_id = %d AND clinician_id = %d AND visit_date = %s AND active = 1 LIMIT 1',
            [$patientId, $clinicianId, $visitDate]
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return int id رکورد جدید
     */
    public function insert(int $clinic_id, array $row): int
    {
        $now = $this->db->nowUtc();
        $row += [
            'clinic_id' => $clinic_id,
            'location_id' => null,
            'appointment_id' => null,
            'source' => 'walk_in',
            'status' => 'checked_in',
            'waiting_since' => null,
            'called_at' => null,
            'consultation_started_at' => null,
            'consultation_completed_at' => null,
            'checked_out_at' => null,
            'cancel_reason' => null,
            'skip_reason' => null,
            'cancelled_by_wp_user_id' => null,
            'recall_count' => 0,
            'active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // Phase 2 (AD-15): ویزیت snapshot مکانی می‌گیرد — نوبت مرجع وگرنه
        // Location اصلی Clinic. پس از merge ارزیابی می‌شود تا clinic_id
        // پیش‌فرض هم دیده شود.
        if (empty($row['location_id'])) {
            $row['location_id'] = $this->locationForNewVisit($row);
        }

        $this->db->insert('cpms_visits', $row);

        return $this->db->wpdb_last_insert_id();
    }

    /**
     * @param array<string, mixed> $row
     */
    public function updateById(int $id, array $row): int
    {
        $row['updated_at'] = $this->db->nowUtc();

        return $this->db->update('cpms_visits', $row, ['id' => $id]);
    }

    /**
     * Location ویزیت جدید — از نوبت مرجع (اگر هست) وگرنه Location اصلی Clinic
     * (Phase 2 — AD-15؛ صف per-Location).
     *
     * @param array<string, mixed> $row
     */
    private function locationForNewVisit(array $row): int
    {
        if (!empty($row['appointment_id'])) {
            $apptLocation = $this->db->fetchValue(
                'SELECT location_id FROM ' . $this->db->table('cpms_appointments') . ' WHERE id = %d LIMIT 1',
                [(int) $row['appointment_id']]
            );
            if ($apptLocation !== null && $apptLocation !== '') {
                return (int) $apptLocation;
            }
        }

        return PrimaryLocationResolver::resolve($this->db, (int) ($row['clinic_id'] ?? 0));
    }

    /**
     * تاریخچه append-only (J-3) — هیچ مسیری history را UPDATE/DELETE نمی‌کند.
     *
     * @param array<string, mixed> $row
     * @return int id رخداد (event_id برای R1)
     */
    public function insertHistory(int $visitId, array $row): int
    {
        $row['visit_id'] = $visitId;
        $row['changed_at'] = $this->db->nowUtc();
        $this->db->insert('cpms_visit_status_history', $row);

        return $this->db->wpdb_last_insert_id();
    }

    /**
     * کل تاریخچه یک ویزیت (مرتب بر اساس زمان).
     *
     * @return list<array<string, mixed>>
     */
    public function historyFor(int $visitId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_visit_status_history') .
            ' WHERE visit_id = %d ORDER BY id ASC',
            [$visitId]
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * صف یک پزشک (E2) یا کل کلینیک (D1) — وضعیت‌های زنده صف.
     * نوبت فوری: walk-in با نوبت مرجع express (FR-7.3) — با LEFT JOIN مشخص می‌شود.
     *
     * @param list<string> $statuses
     * @return list<array<string, mixed>>
     */
    public function queueFor(int $clinicId, ?int $clinicianId, array $statuses, ?string $visitDate = null): array
    {
        $statuses = array_values($statuses);
        if ($statuses === []) {
            return [];
        }
        $date = $visitDate ?? gmdate('Y-m-d');

        $where = 'v.clinic_id = %d AND v.visit_date = %s AND v.status IN (' .
            implode(',', array_fill(0, count($statuses), '%s')) . ')';
        $params = [$clinicId, $date, ...$statuses];
        if ($clinicianId !== null) {
            $where .= ' AND v.clinician_id = %d';
            $params[] = $clinicianId;
        }

        $rows = $this->db->fetchAll(
            'SELECT v.*, p.first_name AS patient_first_name, p.last_name AS patient_last_name,' .
            ' c.full_name AS clinician_name,' .
            ' a.is_walkin_express AS express' .
            ' FROM ' . $this->db->table('cpms_visits') . ' v' .
            ' JOIN ' . $this->db->table('cpms_patients') . ' p ON p.id = v.patient_id' .
            ' JOIN ' . $this->db->table('cpms_clinicians') . ' c ON c.id = v.clinician_id' .
            ' LEFT JOIN ' . $this->db->table('cpms_appointments') . ' a ON a.id = v.appointment_id' .
            ' WHERE ' . $where .
            ' ORDER BY (a.is_walkin_express = 1) DESC, v.waiting_since ASC, v.id ASC',
            $params
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * آمار روز (D1/E1) — شمارش بر اساس status.
     *
     * غنی‌سازی داشبورد امروز (§16 دستور F4): علاوه بر شمارش ویزیت‌ها بر اساس
     * status، سه شمارش سبک دیگر:
     *  - appointments_today: کل نوبت‌های schedule شده برای امروز (هر status)
     *  - appointments_no_show: زیرمجموعه با status=no_show
     *  - walk_in_today: ویزیت‌های امروز بدون نوبت (walk-in مستقل)
     *
     * @return array<string, int>
     */
    public function statsFor(int $clinicId, ?string $visitDate = null, ?int $clinicianId = null): array
    {
        $date = $visitDate ?? gmdate('Y-m-d');
        // ADR-0030/Part1: Scope اختیاری پزشک — وقتی Actor «پزشکِ متصل» است،
        // آمار داشبورد فقط ویزیت‌های خودش را می‌شمارد (بدون نشت حتی Aggregate).
        $visitWhere = 'clinic_id = %d AND visit_date = %s';
        $visitParams = [$clinicId, $date];
        if ($clinicianId !== null) {
            $visitWhere .= ' AND clinician_id = %d';
            $visitParams[] = $clinicianId;
        }
        $rows = $this->db->fetchAll(
            'SELECT status, COUNT(*) AS n FROM ' . $this->db->table('cpms_visits') .
            ' WHERE ' . $visitWhere . ' GROUP BY status',
            $visitParams
        );

        $stats = [
            'checked_in' => 0, 'waiting' => 0, 'called' => 0, 'in_consultation' => 0,
            'consultation_completed' => 0, 'awaiting_payment' => 0, 'paid' => 0,
            'checked_out' => 0, 'cancelled' => 0, 'skipped' => 0, 'total' => 0,
        ];
        foreach ((is_array($rows) ? $rows : []) as $r) {
            $key = (string) $r['status'];
            $stats[$key] = (int) $r['n'];
            $stats['total'] += (int) $r['n'];
        }

        $apptWhere = 'clinic_id = %d AND slot_date = %s';
        $apptParams = ['no_show', $clinicId, $date];
        if ($clinicianId !== null) {
            $apptWhere .= ' AND clinician_id = %d';
            $apptParams[] = $clinicianId;
        }
        $appts = $this->db->fetchRow(
            'SELECT COUNT(*) AS total, COALESCE(SUM(status = %s), 0) AS no_show' .
            ' FROM ' . $this->db->table('cpms_appointments') .
            ' WHERE ' . $apptWhere,
            $apptParams
        );
        $stats['appointments_today'] = $appts === null ? 0 : (int) ($appts['total'] ?? 0);
        $stats['appointments_no_show'] = $appts === null ? 0 : (int) ($appts['no_show'] ?? 0);

        $walkInWhere = 'clinic_id = %d AND visit_date = %s AND appointment_id IS NULL';
        $walkInParams = [$clinicId, $date];
        if ($clinicianId !== null) {
            $walkInWhere .= ' AND clinician_id = %d';
            $walkInParams[] = $clinicianId;
        }
        $walkIn = $this->db->fetchRow(
            'SELECT COUNT(*) AS n FROM ' . $this->db->table('cpms_visits') .
            ' WHERE ' . $walkInWhere,
            $walkInParams
        );
        $stats['walk_in_today'] = $walkIn === null ? 0 : (int) ($walkIn['n'] ?? 0);

        return $stats;
    }

    /**
     * Feed رویدادهای Real-time (R1 — ADR-0007): تغییرات صف امروز بعد از event_id.
     * محدود به ویزیت‌های امروز — فید «صف» است نه تاریخچه کامل.
     *
     * @return list<array<string, mixed>>
     */
    public function eventsSince(int $clinicId, int $sinceEventId, int $limit = 200, ?int $clinicianId = null): array
    {
        $where = 'h.id > %d AND v.clinic_id = %d AND v.visit_date = %s';
        $params = [$sinceEventId, $clinicId, gmdate('Y-m-d')];
        // ADR-0030/Part1: پزشکِ متصل فقط رویدادهای ویزیت‌های خودش را در Feed می‌بیند.
        if ($clinicianId !== null) {
            $where .= ' AND v.clinician_id = %d';
            $params[] = $clinicianId;
        }
        $rows = $this->db->fetchAll(
            'SELECT h.id, h.visit_id, h.from_status, h.to_status, h.changed_at,' .
            ' h.actor_wp_user_id, h.actor_role, h.note, v.clinic_id, v.clinician_id,' .
            ' v.patient_id, v.status AS visit_status' .
            ' FROM ' . $this->db->table('cpms_visit_status_history') . ' h' .
            ' JOIN ' . $this->db->table('cpms_visits') . ' v ON v.id = h.visit_id' .
            ' WHERE ' . $where . ' ORDER BY h.id ASC LIMIT %d',
            array_merge($params, [$limit])
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * بیشینه id رویداد امروز کلینیک — ETag ورژن صف (R1).
     */
    public function lastEventId(int $clinicId, ?string $visitDate = null, ?int $clinicianId = null): int
    {
        $where = 'v.clinic_id = %d AND v.visit_date = %s';
        $params = [$clinicId, $visitDate ?? gmdate('Y-m-d')];
        if ($clinicianId !== null) {
            $where .= ' AND v.clinician_id = %d';
            $params[] = $clinicianId;
        }
        $row = $this->db->fetchRow(
            'SELECT MAX(h.id) AS max_id' .
            ' FROM ' . $this->db->table('cpms_visit_status_history') . ' h' .
            ' JOIN ' . $this->db->table('cpms_visits') . ' v ON v.id = h.visit_id' .
            ' WHERE ' . $where,
            $params
        );

        return $row === null ? 0 : (int) ($row['max_id'] ?? 0);
    }

    /**
     * رخدادهای no-show بالقوه (FR-5.5) — legacy wrapper, now just bounded candidate fetch without policy.
     * T2 corrected: Repository is bounded data access only, no eligibility policy.
     * All temporal eligibility is in VisitService (single authoritative).
     *
     * @return list<array<string, mixed>>
     */
    public function appointmentsPastGrace(string $beforeDateTime, int $limit = 100): array
    {
        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this->appointmentsPastGraceCandidates($limit, $nowUtc, null);
    }

    /**
     * T2 corrected: bounded candidate data access only, no policy.
     * Returns tenant-attributed appointments with Location timezone via LEFT JOIN (avoids N+1).
     * No direct Settings reads, no DateTime eligibility decision.
     * Uses deterministic ordering (slot_date, slot_time, id) and optional keyset cursor for progress.
     *
     * Safety: LEFT JOIN preserves malformed rows (missing Location) so Service can fail-closed explicitly.
     * INNER JOIN would silently remove them and hide starvation — LEFT JOIN is safer.
     *
     * @param array{slot_date:string, slot_time:string, id:int}|null $cursor
     * @return list<array<string, mixed>>
     */
    public function appointmentsPastGraceCandidates(int $limit, \DateTimeImmutable $nowUtc, ?array $cursor = null): array
    {
        $upperDate = $nowUtc->add(new \DateInterval('P2D'))->format('Y-m-d');

        $where = "a.status = 'confirmed' AND a.active_visit_id IS NULL AND a.slot_date <= %s";
        $params = [$upperDate];

        if ($cursor !== null && isset($cursor['slot_date'], $cursor['slot_time'], $cursor['id'])) {
            $where .= " AND ((a.slot_date > %s) OR (a.slot_date = %s AND a.slot_time > %s) OR (a.slot_date = %s AND a.slot_time = %s AND a.id > %d))";
            $params[] = $cursor['slot_date'];
            $params[] = $cursor['slot_date'];
            $params[] = $cursor['slot_time'];
            $params[] = $cursor['slot_date'];
            $params[] = $cursor['slot_time'];
            $params[] = (int) $cursor['id'];
        }

        $rows = $this->db->fetchAll(
            'SELECT a.id, a.clinic_id, a.location_id, a.patient_id, a.clinician_id, a.slot_date, a.slot_time, ' .
            'l.clinic_id as loc_clinic_id, l.timezone as loc_timezone ' .
            'FROM ' . $this->db->table('cpms_appointments') . ' a ' .
            'LEFT JOIN ' . $this->db->table('cpms_locations') . ' l ON l.id = a.location_id ' .
            'WHERE ' . $where . ' ' .
            'ORDER BY a.slot_date ASC, a.slot_time ASC, a.id ASC ' .
            'LIMIT %d',
            array_merge($params, [$limit])
        );

        return is_array($rows) ? $rows : [];
    }
}
