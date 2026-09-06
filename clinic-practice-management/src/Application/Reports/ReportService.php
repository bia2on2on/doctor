<?php

declare(strict_types=1);

namespace ClinicCore\Application\Reports;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Settings\Settings;

/**
 * سرویس گزارش (F8 — FR-19.2: ۱۲ گزارش + G5).
 *
 * مدل دسترسی (permission-matrix §2/§3/§6 + ADR-0026 D-8/D-15 + قواعد کارفرما):
 *  - همه گزارش‌ها: `cpms_report_read` (پیش‌فرض فقط پزشک؛ منشی ❌).
 *  - Scope سرور-side: کاربرِ متصل به Clinician (cpms_clinicians.wp_user_id)
 *    = OWN — فیلتر clinician_id اجباری؛ دسترسی cross-doctor هرگز.
 *  - Aggregate سطح مطب (بدون فیلتر پزشک) فقط برای دارنده report_read که
 *    Clinician متصل ندارد (اعطای صریح، الگوی «حسابدار» ماتریس §6) —
 *    پزشک متصل درخواست Aggregate کل مطب ندهد (403).
 *  - تفکیک Aggregate⊥Detail (D-8): گزارش مالی = جمعی بدون نام بیمار و
 *    نیاز `cpms_finance_read`؛ گزارش عملیاتی با نام بیمار = نیاز
 *    `cpms_patient_read`؛ follow_ups_due = نیاز `cpms_medical_read`.
 *  - Notes خصوصی (Private Doctor Notes) هرگز کوئری نمی‌شوند — هیچ گزارشی
 *    به جدول‌های clinical_notes/private دست ندارد.
 *
 * Performance (FR-19.4): بازه bounded (`reports.max_range_days`)، کوئری‌های
 * Aggregate تک-عبارتی (بدون N+1)، سقف ردیف لیست‌ها (500) + has_more.
 */
final class ReportService
{
    private const ROW_LIMIT = 500;

    /** @var array<string, array{label: string, caps: list<string>, kind: string, default_days: int}> */
    private const TYPES = [
        // عملیاتی — لیست با نام بیمار (نیاز patient_read)
        'appointments_today' => ['label' => 'نوبت‌های امروز', 'caps' => [RolesAndCapabilities::PATIENT_READ], 'kind' => 'appointments', 'default_days' => 0],
        'appointments_week' => ['label' => 'نوبت‌های هفته', 'caps' => [RolesAndCapabilities::PATIENT_READ], 'kind' => 'appointments', 'default_days' => 7],
        'cancellations' => ['label' => 'لغو نوبت‌ها', 'caps' => [RolesAndCapabilities::PATIENT_READ], 'kind' => 'cancellations', 'default_days' => 30],
        'no_shows' => ['label' => 'عدم حضور (No-Show)', 'caps' => [RolesAndCapabilities::PATIENT_READ], 'kind' => 'no_shows', 'default_days' => 30],
        'walk_ins' => ['label' => 'ویزیت‌های بدون نوبت (Walk-in)', 'caps' => [RolesAndCapabilities::PATIENT_READ], 'kind' => 'walk_ins', 'default_days' => 30],
        'visits' => ['label' => 'ویزیت‌ها', 'caps' => [RolesAndCapabilities::PATIENT_READ], 'kind' => 'visits', 'default_days' => 30],
        // عملیاتی — Aggregate بدون PHI
        'avg_waiting' => ['label' => 'میانگین زمان انتظار', 'caps' => [], 'kind' => 'avg_waiting', 'default_days' => 30],
        'visit_duration' => ['label' => 'میانگین مدت ویزیت', 'caps' => [], 'kind' => 'visit_duration', 'default_days' => 30],
        // مالی — Aggregate⊥Detail (D-8) — بدون نام بیمار
        'revenue' => ['label' => 'درآمد (روز/ماه)', 'caps' => [RolesAndCapabilities::FINANCE_READ], 'kind' => 'revenue', 'default_days' => 30],
        'payment_methods' => ['label' => 'روش‌های پرداخت', 'caps' => [RolesAndCapabilities::FINANCE_READ], 'kind' => 'payment_methods', 'default_days' => 30],
        'open_balances' => ['label' => 'مطالبات باقی‌مانده', 'caps' => [RolesAndCapabilities::FINANCE_READ], 'kind' => 'open_balances', 'default_days' => 0],
        // بالینی — داده پیگیری (بدون reason برای کمینه‌سازی PHI)
        'follow_ups_due' => ['label' => 'پیگیری‌های سررسید', 'caps' => [RolesAndCapabilities::MEDICAL_READ], 'kind' => 'follow_ups', 'default_days' => 30],
    ];

    public function __construct(
        private readonly CpmsDb $db,
        private readonly Settings $settings,
        private readonly AuditLogger $audit
    ) {
    }

    // ================= Catalog =================

    /**
     * فهرست گزارش‌های مجاز برای Actor (بر اساس Capهای واقعاً اعطاشده).
     *
     * @return list<array<string, mixed>>
     */
    public function catalog(int $actorUserId): array
    {
        $this->requireCap($actorUserId, RolesAndCapabilities::REPORT_READ);
        [$scopeMode] = $this->resolveScope($actorUserId);

        $out = [];
        foreach (self::TYPES as $id => $meta) {
            $missing = $this->missingCaps($actorUserId, $meta['caps']);
            $out[] = [
                'type' => $id,
                'label' => $meta['label'],
                'requires' => array_values(array_unique(array_merge(
                    [RolesAndCapabilities::REPORT_READ],
                    $meta['caps']
                ))),
                'available' => $missing === [],
                'missing' => $missing,
                'scope' => $scopeMode,
            ];
        }

        return $out;
    }

    public function isKnownType(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public function typeLabel(string $type): string
    {
        return self::TYPES[$type]['label'] ?? $type;
    }

    /**
     * Capهای نوع گزارش (به‌جز report_read پایه).
     *
     * @return list<string>
     */
    public function typeCaps(string $type): array
    {
        return self::TYPES[$type]['caps'] ?? [];
    }

    // ================= Run (G5) =================

    /**
     * اجرای گزارش — مجوز + Scope + بازه bounded + Audit خواندن.
     *
     * @return array<string, mixed>
     */
    public function run(int $actorUserId, string $type, ?string $from, ?string $to, ?int $rowLimit = null): array
    {
        $this->requireCap($actorUserId, RolesAndCapabilities::REPORT_READ);
        $meta = self::TYPES[$type] ?? null;
        if ($meta === null) {
            throw ReportException::of('CLINIC_NOT_FOUND', 'نوع گزارش ناشناخته است', 404, ['type' => $type]);
        }

        $missing = $this->missingCaps($actorUserId, $meta['caps']);
        if ($missing !== []) {
            throw ReportException::of(
                'CLINIC_PERMISSION_DENIED',
                'برای این گزارش Capability لازم ندارید: ' . implode(', ', $missing),
                403,
                ['type' => $type, 'missing' => $missing]
            );
        }

        [$scopeMode, $clinicianId] = $this->resolveScope($actorUserId);
        $range = $this->resolveRange($type, $from, $to);
        $limit = max(1, min(10000, $rowLimit ?? self::ROW_LIMIT));

        $result = match ($meta['kind']) {
            'appointments' => $this->reportAppointments($scopeMode, $clinicianId, $range, $this->appointmentStatusesFor($type), $limit),
            'cancellations' => $this->reportCancellations($scopeMode, $clinicianId, $range, $limit),
            'no_shows' => $this->reportNoShows($scopeMode, $clinicianId, $range, $limit),
            'walk_ins' => $this->reportWalkIns($scopeMode, $clinicianId, $range, $limit),
            'visits' => $this->reportVisits($scopeMode, $clinicianId, $range, $limit),
            'avg_waiting' => $this->reportAvgWaiting($scopeMode, $clinicianId, $range),
            'visit_duration' => $this->reportVisitDuration($scopeMode, $clinicianId, $range),
            'revenue' => $this->reportRevenue($scopeMode, $clinicianId, $range),
            'payment_methods' => $this->reportPaymentMethods($scopeMode, $clinicianId, $range),
            'open_balances' => $this->reportOpenBalances($scopeMode, $clinicianId, $range, $limit),
            'follow_ups' => $this->reportFollowUps($scopeMode, $clinicianId, $range, $limit),
            default => throw ReportException::of('CLINIC_INTERNAL_ERROR', 'نوع گزارش پیاده نشده', 500),
        };

        $result['type'] = $type;
        $result['label'] = $meta['label'];
        $result['from'] = $range['from'];
        $result['to'] = $range['to'];
        $result['from_jalali'] = Jalali::formatYmd($range['from']);
        $result['to_jalali'] = Jalali::formatYmd($range['to']);
        $result['scope'] = $scopeMode === 'own' ? 'own' : 'clinic';

        // Audit گزارش حساس (کارفرما: «Audit گزارش‌ها/Exportهای حساس»)
        $this->audit->log(
            'REPORT_READ',
            $this->actor($actorUserId),
            'report',
            null,
            null,
            null,
            ['type' => $type],
            ['from' => $range['from'], 'to' => $range['to'], 'scope' => $scopeMode]
        );

        return $result;
    }

    // ================= Report Builders =================

    /**
     * نوبت‌ها در بازه (امروز/هفته) — با تفکیک وضعیت.
     *
     * @param array<string> $statuses
     * @param array<string, mixed> $range
     * @return array<string, mixed>
     */
    private function reportAppointments(string $scopeMode, ?int $clinicianId, array $range, array $statuses, int $limit = self::ROW_LIMIT): array
    {
        $sql = 'SELECT a.id, a.reference_code, a.slot_date, a.slot_time, a.status, a.is_walkin_express,
                       a.patient_id, p.first_name, p.last_name, p.mrn, c.full_name AS clinician_name
                FROM ' . $this->db->table('cpms_appointments') . ' a
                JOIN ' . $this->db->table('cpms_patients') . ' p ON p.id = a.patient_id
                LEFT JOIN ' . $this->db->table('cpms_clinicians') . ' c ON c.id = a.clinician_id
                WHERE a.clinic_id = 1 AND a.slot_date BETWEEN %s AND %s AND a.status IN (' .
                implode(',', array_fill(0, count($statuses), '%s')) . ')';
        $params = array_merge([$range['from'], $range['to']], $statuses);
        [$sql, $params] = $this->applyScope($sql, $params, 'a.clinician_id', $scopeMode, $clinicianId);
        $sql .= ' ORDER BY a.slot_date ASC, a.slot_time ASC LIMIT %d';
        $params[] = $limit + 1;

        $rows = $this->db->fetchAll($sql, $params);

        $visible = array_slice($rows, 0, $limit);

        return [
            'summary' => [
                'count' => count($visible),
                'by_status' => $this->countBy($visible, 'status'),
            ],
            'rows' => $this->presentAppointmentRows($visible),
            'has_more' => count($rows) > $limit,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportCancellations(string $scopeMode, ?int $clinicianId, array $range, int $limit = self::ROW_LIMIT): array
    {
        $sql = 'SELECT a.id, a.reference_code, a.slot_date, a.slot_time, a.status, a.cancel_reason,
                       a.cancelled_at, a.patient_id, p.first_name, p.last_name, p.mrn,
                       c.full_name AS clinician_name
                FROM ' . $this->db->table('cpms_appointments') . ' a
                JOIN ' . $this->db->table('cpms_patients') . ' p ON p.id = a.patient_id
                LEFT JOIN ' . $this->db->table('cpms_clinicians') . ' c ON c.id = a.clinician_id
                WHERE a.clinic_id = 1 AND a.slot_date BETWEEN %s AND %s
                  AND a.status IN (%s, %s)';
        $params = [$range['from'], $range['to'], 'cancelled_by_patient', 'cancelled_by_staff'];
        [$sql, $params] = $this->applyScope($sql, $params, 'a.clinician_id', $scopeMode, $clinicianId);
        $sql .= ' ORDER BY a.cancelled_at DESC LIMIT %d';
        $params[] = $limit + 1;

        $rows = $this->db->fetchAll($sql, $params);

        $visible = array_slice($rows, 0, $limit);

        return [
            'summary' => [
                'count' => count($visible),
                'by_status' => $this->countBy($visible, 'status'),
            ],
            'rows' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'reference_code' => (string) $r['reference_code'],
                'date' => (string) $r['slot_date'],
                'date_jalali' => Jalali::formatYmd((string) $r['slot_date']),
                'time' => substr((string) $r['slot_time'], 0, 5),
                'patient_name' => trim((string) $r['first_name'] . ' ' . (string) $r['last_name']),
                'mrn' => (string) $r['mrn'],
                'clinician_name' => (string) $r['clinician_name'],
                'cancelled_by' => (string) $r['status'] === 'cancelled_by_patient' ? 'بیمار' : 'مطب',
                'reason' => (string) ($r['cancel_reason'] ?? ''),
            ], $visible),
            'has_more' => count($rows) > $limit,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportNoShows(string $scopeMode, ?int $clinicianId, array $range, int $limit = self::ROW_LIMIT): array
    {
        $sql = 'SELECT a.id, a.reference_code, a.slot_date, a.slot_time, a.no_show_at,
                       a.patient_id, p.first_name, p.last_name, p.mrn, c.full_name AS clinician_name
                FROM ' . $this->db->table('cpms_appointments') . ' a
                JOIN ' . $this->db->table('cpms_patients') . ' p ON p.id = a.patient_id
                LEFT JOIN ' . $this->db->table('cpms_clinicians') . ' c ON c.id = a.clinician_id
                WHERE a.clinic_id = 1 AND a.slot_date BETWEEN %s AND %s AND a.status = %s';
        $params = [$range['from'], $range['to'], 'no_show'];
        [$sql, $params] = $this->applyScope($sql, $params, 'a.clinician_id', $scopeMode, $clinicianId);
        $sql .= ' ORDER BY a.slot_date DESC, a.slot_time DESC LIMIT %d';
        $params[] = $limit + 1;

        $rows = $this->db->fetchAll($sql, $params);

        $visible = array_slice($rows, 0, $limit);

        return [
            'summary' => ['count' => count($visible)],
            'rows' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'reference_code' => (string) $r['reference_code'],
                'date' => (string) $r['slot_date'],
                'date_jalali' => Jalali::formatYmd((string) $r['slot_date']),
                'time' => substr((string) $r['slot_time'], 0, 5),
                'patient_name' => trim((string) $r['first_name'] . ' ' . (string) $r['last_name']),
                'mrn' => (string) $r['mrn'],
                'clinician_name' => (string) $r['clinician_name'],
                'no_show_at' => $r['no_show_at'] !== null ? (string) $r['no_show_at'] : null,
            ], $visible),
            'has_more' => count($rows) > $limit,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportWalkIns(string $scopeMode, ?int $clinicianId, array $range, int $limit = self::ROW_LIMIT): array
    {
        return $this->visitListReport($scopeMode, $clinicianId, $range, "v.source = 'walk_in'", $limit);
    }

    /**
     * @return array<string, mixed>
     */
    private function reportVisits(string $scopeMode, ?int $clinicianId, array $range, int $limit = self::ROW_LIMIT): array
    {
        return $this->visitListReport($scopeMode, $clinicianId, $range, '1=1', $limit);
    }

    /**
     * @param array<string, mixed> $range
     * @return array<string, mixed>
     */
    private function visitListReport(string $scopeMode, ?int $clinicianId, array $range, string $condition, int $limit = self::ROW_LIMIT): array
    {
        $sql = 'SELECT v.id, v.visit_date, v.status, v.source, v.check_in_at, v.checked_out_at,
                       v.patient_id, p.first_name, p.last_name, p.mrn, c.full_name AS clinician_name
                FROM ' . $this->db->table('cpms_visits') . ' v
                JOIN ' . $this->db->table('cpms_patients') . ' p ON p.id = v.patient_id
                LEFT JOIN ' . $this->db->table('cpms_clinicians') . ' c ON c.id = v.clinician_id
                WHERE v.clinic_id = 1 AND v.visit_date BETWEEN %s AND %s AND ' . $condition;
        $params = [$range['from'], $range['to']];
        [$sql, $params] = $this->applyScope($sql, $params, 'v.clinician_id', $scopeMode, $clinicianId);
        $sql .= ' ORDER BY v.visit_date DESC, v.id DESC LIMIT %d';
        $params[] = $limit + 1;

        $rows = $this->db->fetchAll($sql, $params);

        $visible = array_slice($rows, 0, $limit);

        return [
            'summary' => [
                'count' => count($visible),
                'by_status' => $this->countBy($visible, 'status'),
                'by_source' => $this->countBy($visible, 'source'),
            ],
            'rows' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'date' => (string) $r['visit_date'],
                'date_jalali' => Jalali::formatYmd((string) $r['visit_date']),
                'patient_name' => trim((string) $r['first_name'] . ' ' . (string) $r['last_name']),
                'mrn' => (string) $r['mrn'],
                'clinician_name' => (string) $r['clinician_name'],
                'status' => (string) $r['status'],
                'source' => (string) $r['source'],
                'check_in_at' => $r['check_in_at'] !== null ? (string) $r['check_in_at'] : null,
                'checked_out_at' => $r['checked_out_at'] !== null ? (string) $r['checked_out_at'] : null,
            ], $visible),
            'has_more' => count($rows) > $limit,
        ];
    }

    /**
     * میانگین انتظار: waiting_since → called_at (Aggregate — بدون PHI).
     *
     * @return array<string, mixed>
     */
    private function reportAvgWaiting(string $scopeMode, ?int $clinicianId, array $range): array
    {
        $sql = 'SELECT v.visit_date,
                       COUNT(*) AS visits,
                       ROUND(AVG(TIMESTAMPDIFF(SECOND, v.waiting_since, v.called_at))) AS avg_wait_sec
                FROM ' . $this->db->table('cpms_visits') . ' v
                WHERE v.clinic_id = 1 AND v.visit_date BETWEEN %s AND %s
                  AND v.waiting_since IS NOT NULL AND v.called_at IS NOT NULL';
        $params = [$range['from'], $range['to']];
        [$sql, $params] = $this->applyScope($sql, $params, 'v.clinician_id', $scopeMode, $clinicianId);
        $sql .= ' GROUP BY v.visit_date ORDER BY v.visit_date ASC LIMIT 400';

        $rows = $this->db->fetchAll($sql, $params);

        return [
            'summary' => $this->avgSummary($rows),
            'rows' => array_map(static fn (array $r): array => [
                'date' => (string) $r['visit_date'],
                'date_jalali' => Jalali::formatYmd((string) $r['visit_date']),
                'visits' => (int) $r['visits'],
                'avg_wait_sec' => (int) $r['avg_wait_sec'],
                'avg_wait_min' => (int) round(((int) $r['avg_wait_sec']) / 60),
            ], $rows),
            'has_more' => false,
        ];
    }

    /**
     * میانگین مدت ویزیت: consultation_started_at → consultation_completed_at.
     *
     * @return array<string, mixed>
     */
    private function reportVisitDuration(string $scopeMode, ?int $clinicianId, array $range): array
    {
        $sql = 'SELECT v.visit_date,
                       COUNT(*) AS visits,
                       ROUND(AVG(TIMESTAMPDIFF(SECOND, v.consultation_started_at, v.consultation_completed_at))) AS avg_duration_sec
                FROM ' . $this->db->table('cpms_visits') . ' v
                WHERE v.clinic_id = 1 AND v.visit_date BETWEEN %s AND %s
                  AND v.consultation_started_at IS NOT NULL AND v.consultation_completed_at IS NOT NULL';
        $params = [$range['from'], $range['to']];
        [$sql, $params] = $this->applyScope($sql, $params, 'v.clinician_id', $scopeMode, $clinicianId);
        $sql .= ' GROUP BY v.visit_date ORDER BY v.visit_date ASC LIMIT 400';

        $rows = $this->db->fetchAll($sql, $params);

        return [
            'summary' => $this->avgSummary($rows, 'avg_duration_sec'),
            'rows' => array_map(static fn (array $r): array => [
                'date' => (string) $r['visit_date'],
                'date_jalali' => Jalali::formatYmd((string) $r['visit_date']),
                'visits' => (int) $r['visits'],
                'avg_duration_sec' => (int) $r['avg_duration_sec'],
                'avg_duration_min' => (int) round(((int) $r['avg_duration_sec']) / 60),
            ], $rows),
            'has_more' => false,
        ];
    }

    /**
     * درآمد بازه — پرداخت‌های captured منهای refundها، روزانه/روش/خدمات.
     * Aggregate (D-8): بدون نام بیمار.
     *
     * @return array<string, mixed>
     */
    private function reportRevenue(string $scopeMode, ?int $clinicianId, array $range): array
    {
        $join = ' FROM ' . $this->db->table('cpms_payments') . ' pay
                  JOIN ' . $this->db->table('cpms_invoices') . ' inv ON inv.id = pay.invoice_id
                  LEFT JOIN ' . $this->db->table('cpms_visits') . ' v ON v.id = inv.visit_id';
        $where = ' WHERE pay.clinic_id = 1 AND pay.paid_at IS NOT NULL
                     AND pay.paid_at >= %s AND pay.paid_at < %s';
        $params = [$range['from'] . ' 00:00:00.000', $range['to'] . ' 23:59:59.999'];

        // Scope: پرداخت از طریق فاکتور→ویزیت→پزشک (فیلتر سرور-side)
        [$j, $w, $p] = [$join, $where, $params];
        if ($scopeMode === 'own') {
            $w .= ' AND v.clinician_id = %d';
            $p[] = (int) $clinicianId;
        }

        $daily = $this->db->fetchAll(
            'SELECT DATE(pay.paid_at) AS day,
                    SUM(CASE WHEN pay.status = %s THEN pay.amount ELSE 0 END) AS gross,
                    SUM(CASE WHEN pay.status = %s THEN pay.refunded_amount ELSE 0 END) AS refunded,
                    SUM(CASE WHEN pay.status = %s THEN 1 ELSE 0 END) AS payments' . $j . $w .
            ' GROUP BY DATE(pay.paid_at) ORDER BY day ASC LIMIT 400',
            array_merge(['captured', 'refunded', 'captured'], $p)
        );

        $byMethod = $this->db->fetchAll(
            'SELECT pay.method, COUNT(*) AS payments,
                    SUM(CASE WHEN pay.status = %s THEN pay.amount ELSE 0 END) AS gross,
                    SUM(CASE WHEN pay.status = %s THEN pay.refunded_amount ELSE 0 END) AS refunded' . $j . $w .
            ' GROUP BY pay.method ORDER BY gross DESC',
            array_merge(['captured', 'refunded'], $p)
        );

        $net = 0;
        foreach ($daily as $d) {
            $net += (float) $d['gross'] - (float) $d['refunded'];
        }

        return [
            'summary' => [
                'gross' => $this->money($this->sumColumn($daily, 'gross')),
                'refunded' => $this->money($this->sumColumn($daily, 'refunded')),
                'net' => $this->money($net),
                'payment_count' => (int) $this->sumColumn($daily, 'payments'),
            ],
            'rows' => array_map(static fn (array $d): array => [
                'date' => (string) $d['day'],
                'date_jalali' => Jalali::formatYmd((string) $d['day']),
                'gross' => (float) $d['gross'],
                'refunded' => (float) $d['refunded'],
                'net' => (float) $d['gross'] - (float) $d['refunded'],
                'payments' => (int) $d['payments'],
            ], $daily),
            'by_method' => array_map(static fn (array $m): array => [
                'method' => (string) $m['method'],
                'payments' => (int) $m['payments'],
                'gross' => (float) $m['gross'],
                'refunded' => (float) $m['refunded'],
                'net' => (float) $m['gross'] - (float) $m['refunded'],
            ], $byMethod),
            'has_more' => false,
        ];
    }

    /**
     * روش‌های پرداخت — تفکیک روش/وضعیت (Aggregate).
     *
     * @return array<string, mixed>
     */
    private function reportPaymentMethods(string $scopeMode, ?int $clinicianId, array $range): array
    {
        $sql = 'SELECT pay.method, pay.status, COUNT(*) AS payments, SUM(pay.amount) AS amount
                FROM ' . $this->db->table('cpms_payments') . ' pay
                JOIN ' . $this->db->table('cpms_invoices') . ' inv ON inv.id = pay.invoice_id
                LEFT JOIN ' . $this->db->table('cpms_visits') . ' v ON v.id = inv.visit_id
                WHERE pay.clinic_id = 1 AND pay.paid_at IS NOT NULL
                  AND pay.paid_at >= %s AND pay.paid_at < %s';
        $params = [$range['from'] . ' 00:00:00.000', $range['to'] . ' 23:59:59.999'];
        [$sql, $params] = $this->applyScope($sql, $params, 'v.clinician_id', $scopeMode, $clinicianId);
        $sql .= ' GROUP BY pay.method, pay.status ORDER BY pay.method ASC, pay.status ASC LIMIT 100';

        $rows = $this->db->fetchAll($sql, $params);

        return [
            'summary' => [
                'payments' => (int) $this->sumColumn($rows, 'payments'),
                'gross' => $this->money($this->sumColumn($rows, 'amount')),
            ],
            'rows' => array_map(static fn (array $r): array => [
                'method' => (string) $r['method'],
                'status' => (string) $r['status'],
                'payments' => (int) $r['payments'],
                'amount' => (float) $r['amount'],
            ], $rows),
            'has_more' => false,
        ];
    }

    /**
     * مطالبات باقی‌مانده — فاکتورهای open/partial (Aggregate + شماره فاکتور؛
     * بدون نام بیمار — D-8؛ نام بیمار از مسیر Finance جزئی می‌آید نه گزارش).
     *
     * @return array<string, mixed>
     */
    private function reportOpenBalances(string $scopeMode, ?int $clinicianId, array $range, int $limit = self::ROW_LIMIT): array
    {
        $sql = 'SELECT inv.id, inv.invoice_number, inv.status, inv.total, inv.paid_amount, inv.balance,
                       inv.created_at, c.full_name AS clinician_name
                FROM ' . $this->db->table('cpms_invoices') . ' inv
                LEFT JOIN ' . $this->db->table('cpms_visits') . ' v ON v.id = inv.visit_id
                LEFT JOIN ' . $this->db->table('cpms_clinicians') . ' c ON c.id = v.clinician_id
                WHERE inv.clinic_id = 1 AND inv.status IN (%s, %s) AND inv.created_at <= %s';
        $params = ['open', 'partial', $range['to'] . ' 23:59:59.999'];
        [$sql, $params] = $this->applyScope($sql, $params, 'v.clinician_id', $scopeMode, $clinicianId);
        $sql .= ' ORDER BY inv.created_at ASC LIMIT %d';
        $params[] = $limit + 1;

        $rows = $this->db->fetchAll($sql, $params);

        $totalBalance = 0.0;
        $aging = ['current' => 0, 'd30' => 0, 'd60' => 0, 'd90plus' => 0];
        $cutoff = strtotime($range['to'] . ' 23:59:59');
        foreach ($rows as $r) {
            $balance = (float) $r['balance'];
            if ($balance <= 0) {
                continue;
            }
            $totalBalance += $balance;
            $age = (int) floor(($cutoff - strtotime((string) $r['created_at'])) / 86400);
            if ($age <= 30) {
                $aging['current']++;
            } elseif ($age <= 60) {
                $aging['d30']++;
            } elseif ($age <= 90) {
                $aging['d60']++;
            } else {
                $aging['d90plus']++;
            }
        }

        $visible = array_slice($rows, 0, $limit);

        return [
            'summary' => [
                'invoice_count' => count($visible),
                'total_balance' => $this->money($totalBalance),
                'aging' => $aging,
            ],
            'rows' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'invoice_number' => (string) $r['invoice_number'],
                'status' => (string) $r['status'],
                'clinician_name' => (string) $r['clinician_name'],
                'total' => (float) $r['total'],
                'paid_amount' => (float) $r['paid_amount'],
                'balance' => (float) $r['balance'],
                'created_at' => (string) $r['created_at'],
            ], $visible),
            'has_more' => count($rows) > $limit,
        ];
    }

    /**
     * پیگیری‌های سررسید — pending با suggested_date تا پایان بازه.
     * کمینه‌سازی PHI: بدون reason (فقط نام/تاریخ/وضعیت).
     *
     * @return array<string, mixed>
     */
    private function reportFollowUps(string $scopeMode, ?int $clinicianId, array $range, int $limit = self::ROW_LIMIT): array
    {
        $sql = 'SELECT f.id, f.suggested_date, f.status, f.linked_appointment_id,
                       p.first_name, p.last_name, p.mrn, c.full_name AS clinician_name
                FROM ' . $this->db->table('cpms_follow_ups') . ' f
                JOIN ' . $this->db->table('cpms_patients') . ' p ON p.id = f.patient_id
                LEFT JOIN ' . $this->db->table('cpms_clinicians') . ' c ON c.id = f.clinician_id
                WHERE f.clinic_id = 1 AND f.status = %s AND f.suggested_date BETWEEN %s AND %s';
        $params = ['pending', $range['from'], $range['to']];
        [$sql, $params] = $this->applyScope($sql, $params, 'f.clinician_id', $scopeMode, $clinicianId);
        $sql .= ' ORDER BY f.suggested_date ASC LIMIT %d';
        $params[] = $limit + 1;

        $rows = $this->db->fetchAll($sql, $params);

        $visible = array_slice($rows, 0, $limit);

        return [
            'summary' => ['count' => count($visible)],
            'rows' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'suggested_date' => (string) $r['suggested_date'],
                'suggested_date_jalali' => Jalali::formatYmd((string) $r['suggested_date']),
                'patient_name' => trim((string) $r['first_name'] . ' ' . (string) $r['last_name']),
                'mrn' => (string) $r['mrn'],
                'clinician_name' => (string) $r['clinician_name'],
                'status' => (string) $r['status'],
            ], $visible),
            'has_more' => count($rows) > $limit,
        ];
    }

    // ================= Scope / Authz =================

    /**
     * Scope سرور-side (ADR-0026 + قواعد کارفرما):
     *  - کاربر متصل به Clinician → ['own', clinicianId] (فیلتر اجباری)
     *  - کاربر بدون Clinician-Link (اعطای صریح report_read) → ['clinic', null]
     *
     * @return array{0: string, 1: int|null}
     */
    public function resolveScope(int $actorUserId): array
    {
        $clinicianId = $this->db->fetchValue(
            'SELECT id FROM ' . $this->db->table('cpms_clinicians') . ' WHERE wp_user_id = %d AND is_active = 1 LIMIT 1',
            [$actorUserId]
        );

        if ($clinicianId !== null) {
            return ['own', (int) $clinicianId];
        }

        return ['clinic', null];
    }

    /**
     * @return array{0: string, 1: array<int, string|int|float|null>}
     */
    private function applyScope(string $sql, array $params, string $column, string $scopeMode, ?int $clinicianId): array
    {
        if ($scopeMode === 'own') {
            $sql .= ' AND ' . $column . ' = %d';
            $params[] = (int) $clinicianId;
        }

        return [$sql, $params];
    }

    /**
     * @param list<string> $caps
     * @return list<string>
     */
    private function missingCaps(int $actorUserId, array $caps): array
    {
        $user = get_userdata($actorUserId);
        if ($user === false) {
            return $caps; // بدون کاربر → همه مفقود (403)
        }

        $missing = [];
        foreach ($caps as $cap) {
            if (!$user->has_cap($cap)) {
                $missing[] = $cap;
            }
        }

        return $missing;
    }

    private function requireCap(int $actorUserId, string $cap): void
    {
        $user = get_userdata($actorUserId);
        if ($user === false || !$user->has_cap($cap)) {
            throw ReportException::of(
                'CLINIC_PERMISSION_DENIED',
                'دسترسی لازم برای گزارش‌ها را ندارید',
                403,
                ['capability' => $cap]
            );
        }
    }

    // ================= Range / Helpers =================

    /**
     * @return array<string, mixed>
     */
    private function resolveRange(string $type, ?string $from, ?string $to): array
    {
        $defaultDays = (int) (self::TYPES[$type]['default_days'] ?? 30);
        $from = $from ?? gmdate('Y-m-d', strtotime('-' . $defaultDays . ' days'));
        $to = $to ?? gmdate('Y-m-d');

        foreach (['from' => $from, 'to' => $to] as $label => $date) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
            if ($dt === false || $dt->format('Y-m-d') !== $date) {
                throw ReportException::of('CLINIC_VALIDATION_FAILED', "پارامتر {$label} باید تاریخ YYYY-MM-DD باشد", 422);
            }
        }

        if ($from > $to) {
            throw ReportException::of('CLINIC_VALIDATION_FAILED', 'from نباید بعد از to باشد', 422);
        }

        $maxDays = (int) $this->settings->get('reports.max_range_days', 366);
        $days = (int) ((strtotime($to . ' 12:00') - strtotime($from . ' 12:00')) / 86400) + 1;
        if ($days > $maxDays) {
            throw ReportException::of(
                'CLINIC_VALIDATION_FAILED',
                "بازه گزارش حداکثر {$maxDays} روز است",
                422,
                ['max_range_days' => $maxDays]
            );
        }

        return ['from' => $from, 'to' => $to, 'days' => $days];
    }

    /**
     * اعتبارسنجی بازه برای مسیر Export (fail-fast قبل از enqueue).
     *
     * @return array<string, mixed>
     */
    public function validateRangeParams(string $type, ?string $from, ?string $to): array
    {
        return $this->resolveRange($type, $from, $to);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function presentAppointmentRows(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'reference_code' => (string) $r['reference_code'],
            'date' => (string) $r['slot_date'],
            'date_jalali' => Jalali::formatYmd((string) $r['slot_date']),
            'time' => substr((string) $r['slot_time'], 0, 5),
            'patient_name' => trim((string) $r['first_name'] . ' ' . (string) $r['last_name']),
            'mrn' => (string) $r['mrn'],
            'clinician_name' => (string) $r['clinician_name'],
            'status' => (string) $r['status'],
            'is_walkin_express' => (int) $r['is_walkin_express'] === 1,
        ], $rows);
    }

    /**
     * @return list<string>
     */
    private function appointmentStatusesFor(string $type): array
    {
        if ($type === 'appointments_today') {
            return ['pending', 'confirmed', 'completed', 'no_show'];
        }

        return ['pending', 'confirmed', 'completed', 'no_show', 'cancelled_by_patient', 'cancelled_by_staff', 'rescheduled'];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function countBy(array $rows, string $key): array
    {
        $counts = [];
        foreach ($rows as $r) {
            $k = (string) $r[$key];
            $counts[$k] = ($counts[$k] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function avgSummary(array $rows, string $column = 'avg_wait_sec'): array
    {
        $visits = 0;
        $weighted = 0.0;
        foreach ($rows as $r) {
            $visits += (int) $r['visits'];
            $weighted += (int) $r[$column] * (int) $r['visits'];
        }
        $avg = $visits > 0 ? (int) round($weighted / $visits) : 0;

        return [
            'visits' => $visits,
            'avg_sec' => $avg,
            'avg_min' => (int) round($avg / 60),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function sumColumn(array $rows, string $column): float
    {
        $sum = 0.0;
        foreach ($rows as $r) {
            $sum += (float) $r[$column];
        }

        return $sum;
    }

    private function money(float $value): int
    {
        // IRR بدون اعشار (الگوی FinanceService)
        return (int) round($value);
    }

    /**
     * @return array{wp_user_id: int|null, role: string}
     */
    private function actor(int $actorUserId): array
    {
        $user = get_userdata($actorUserId);
        if ($user === false) {
            return ['wp_user_id' => null, 'role' => 'system'];
        }

        return ['wp_user_id' => $actorUserId, 'role' => $user->roles[0] ?? 'unknown'];
    }
}
