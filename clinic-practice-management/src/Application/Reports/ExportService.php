<?php

declare(strict_types=1);

namespace ClinicCore\Application\Reports;

use ClinicCore\Application\Notifications\NotificationService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Domain\Notifications\NotificationEvents;
use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Queue\JobQueue;
use ClinicCore\Infrastructure\Repository\NotificationRepository;
use ClinicCore\Infrastructure\Storage\LocalFileStorage;
use ClinicCore\Settings\Settings;
use RuntimeException;
use Throwable;

/**
 * سرویس Export گزارش (F8 — FR-19.3 + G5).
 *
 * جریان (background-jobs.md: `report.export` → «فایل + اعلان»):
 *  1) POST /reports/{type}/export → اعتبارسنجی کامل (report_read + cpms_export
 *     + Capهای نوع + بازه) + Audit `EXPORT` → **فقط enqueue** (async طبق
 *     performance-baseline §18 — «Export» خارج از مسیر REST).
 *  2) Job → اجرای گزارش (بازمجوزدهی + Scope سرور-side) → CSV با BOM و
 *     محافظت Formula-Injection → LocalFileStorage (خارج webroot، نام تصادفی،
 *     بدون URL عمومی) → اعلان Internal «آماده شد» به درخواست‌دهنده.
 *  3) دانلود فقط از Endpoint مجوزیافته: مالک + cpms_export + Audit EXPORT.
 *
 * ردیابی از طریق خود اعلان (cpms_notifications) انجام می‌شود — بدون جدول
 * جدید؛ فایل‌ها بعد از `reports.export_retention_days` روز پاک می‌شوند.
 */
final class ExportService
{
    public function __construct(
        private readonly CpmsDb $db,
        private readonly ReportService $reports,
        private readonly NotificationService $notifications,
        private readonly NotificationRepository $notificationRows,
        private readonly LocalFileStorage $storage,
        private readonly JobQueue $jobs,
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
        private readonly OpLogger $op
    ) {
    }

    // ================= 1) درخواست (فقط Enqueue) =================

    /**
     * درخواست Export — fail-fast روی مجوز/بازه؛ تولید فایل async.
     *
     * @return array<string, mixed>
     */
    public function request(int $actorUserId, string $type, ?string $from, ?string $to): array
    {
        $this->requireReportAccess($actorUserId, $type);
        $this->requireCap($actorUserId, RolesAndCapabilities::EXPORT, 'خروجی گرفتن از گزارش');

        // بازه را همین‌جا اعتبارسنجی می‌کنیم (خطای کاربر نباید به Job برود)
        $range = $this->reports->validateRangeParams($type, $from, $to);
        [$scopeMode] = $this->reports->resolveScope($actorUserId);

        $jobId = $this->jobs->enqueue('report.export', [
            'actor_id' => $actorUserId,
            'type' => $type,
            'from' => $range['from'],
            'to' => $range['to'],
        ], priority: 4, maxAttempts: 3);

        // Audit اکشن EXPORT با filters (audit-strategy)
        $this->audit->log(
            'EXPORT',
            $this->actor($actorUserId),
            'report',
            $jobId,
            null,
            null,
            ['type' => $type, 'job_id' => $jobId],
            ['from' => $range['from'], 'to' => $range['to'], 'scope' => $scopeMode, 'phase' => 'request']
        );

        return [
            'job_id' => $jobId,
            'status' => 'queued',
            'type' => $type,
            'from' => $range['from'],
            'to' => $range['to'],
        ];
    }

    // ================= 2) تولید (Job report.export) =================

    /**
     * تولید فایل CSV — از ReportExportHandler صدا زده می‌شود.
     * مجوز/Scope دوباره بررسی می‌شود (اگر بین درخواست و اجرا Cap گرفته شده
     * باشد، Export انجام نمی‌شود — Secure by default).
     *
     * @param array<string, mixed> $payload
     */
    public function generate(array $payload): void
    {
        $actorUserId = (int) ($payload['actor_id'] ?? 0);
        $type = (string) ($payload['type'] ?? '');
        $from = isset($payload['from']) ? (string) $payload['from'] : null;
        $to = isset($payload['to']) ? (string) $payload['to'] : null;

        $this->requireReportAccess($actorUserId, $type);
        $this->requireCap($actorUserId, RolesAndCapabilities::EXPORT, 'خروجی گرفتن از گزارش');

        $maxRows = (int) $this->settings->get('reports.export_max_rows', 10000);
        $result = $this->reports->run($actorUserId, $type, $from, $to, $maxRows);

        $retentionDays = (int) $this->settings->get('reports.export_retention_days', 7);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($retentionDays * 86400));

        $csv = self::buildCsv($result, $actorUserId);
        $storagePath = $this->storage->store($csv, 1, 'csv');

        // «فایل + اعلان» — payload اعلان مالکیت/مسیر/انقضا را حمل می‌کند
        $notifId = $this->notifications->publishToUser(
            $actorUserId,
            NotificationEvents::REPORT_EXPORT_READY,
            [
                'report_label' => (string) $result['label'],
                'expires_at' => Jalali::formatYmd(substr($expiresAt, 0, 10)),
            ],
            null
        );
        if ($notifId === null) {
            throw new RuntimeException('CLINIC_EXPORT_NOTIFY_FAILED');
        }

        $row = $this->notificationRows->find($notifId);
        if ($row === null) {
            throw new RuntimeException('CLINIC_EXPORT_NOTIFY_FAILED');
        }

        // متادیتای فایل را به payload اعلان می‌چسبانیم (payload هرگز PHI ندارد —
        // فقط مسیر تصادفی storage و بازه)
        $payloadJson = json_decode((string) $row['payload_json'], true);
        $payloadJson['export'] = [
            'type' => $type,
            'from' => $result['from'],
            'to' => $result['to'],
            'scope' => $result['scope'],
            'file_path' => $storagePath,
            'file_name' => 'cpms-report-' . $type . '-' . $result['from'] . '_' . $result['to'] . '.csv',
            'expires_at' => $expiresAt,
            'row_count' => is_array($result['rows'] ?? null) ? count($result['rows']) : 0,
        ];
        $this->notificationRows->updateById($notifId, [
            'payload_json' => (string) json_encode($payloadJson, JSON_UNESCAPED_UNICODE),
        ]);

        $this->op->info('report.export_ready', [
            'job_type' => 'report.export',
            'type' => $type,
            'notification_id' => $notifId,
        ]);
    }

    // ================= 3) دانلود محافظت‌شده =================

    /**
     * فهرست Exportهای خود Actor (از طریق اعلان‌های report_export_ready).
     *
     * @return array<string, mixed>
     */
    public function listFor(int $actorUserId): array
    {
        $this->requireCap($actorUserId, RolesAndCapabilities::EXPORT, 'خروجی گرفتن از گزارش');

        $rows = $this->notificationRows->forUser($actorUserId, false, 100, 0, NotificationEvents::REPORT_EXPORT_READY);

        return [
            'exports' => array_map(fn (array $row): array => $this->presentExport($row), $rows),
        ];
    }

    /**
     * دانلود — فقط مالک اعلان + cpms_export + Audit EXPORT (هر دانلود).
     *
     * @return array{file_name: string, content: string}
     */
    public function download(int $actorUserId, int $notificationId): array
    {
        $this->requireCap($actorUserId, RolesAndCapabilities::EXPORT, 'خروجی گرفتن از گزارش');

        $row = $this->notificationRows->find($notificationId);
        if ($row === null
            || (string) $row['template'] !== NotificationEvents::REPORT_EXPORT_READY
            || (int) $row['recipient_wp_user_id'] !== $actorUserId) {
            throw ReportException::of('CLINIC_NOT_FOUND', 'خروجی یافت نشد', 404);
        }

        $payload = json_decode((string) $row['payload_json'], true);
        $export = is_array($payload['export'] ?? null) ? $payload['export'] : null;
        if ($export === null || empty($export['file_path'])) {
            throw ReportException::of('CLINIC_NOT_FOUND', 'خروجی یافت نشد', 404);
        }

        if (!empty($export['expires_at']) && $export['expires_at'] < $this->db->nowUtcSql()) {
            $this->storage->delete((string) $export['file_path']);
            throw ReportException::of('CLINIC_EXPORT_EXPIRED', 'مهلت دانلود این خروجی گذشته است', 410);
        }

        $content = $this->storage->read((string) $export['file_path']);
        if ($content === null) {
            throw ReportException::of('CLINIC_NOT_FOUND', 'فایل خروجی حذف شده است', 404);
        }

        $this->audit->log(
            'EXPORT',
            $this->actor($actorUserId),
            'report',
            $notificationId,
            null,
            null,
            ['type' => (string) ($export['type'] ?? '')],
            ['phase' => 'download', 'from' => $export['from'] ?? null, 'to' => $export['to'] ?? null]
        );

        return [
            'file_name' => (string) ($export['file_name'] ?? 'cpms-report.csv'),
            'content' => $content,
        ];
    }

    // ================= Retention =================

    /**
     * پاک‌سازی فایل‌ها/اعلان‌های Export منقضی — از notif.dispatch صدا زده می‌شود.
     */
    public function purgeExpired(): int
    {
        $retentionDays = (int) $this->settings->get('reports.export_retention_days', 7);
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($retentionDays * 86400));

        $rows = $this->db->fetchAll(
            'SELECT id, payload_json FROM ' . $this->db->table('cpms_notifications') .
            ' WHERE clinic_id = 1 AND template = %s AND created_at < %s LIMIT 200',
            [NotificationEvents::REPORT_EXPORT_READY, $cutoff . '.000']
        );

        $purged = 0;
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload_json'], true);
            $path = (string) ($payload['export']['file_path'] ?? '');
            if ($path !== '') {
                try {
                    $this->storage->delete($path);
                } catch (Throwable) {
                    // فایل از قبل حذف‌شده — ادامه
                }
            }
            $this->db->delete('cpms_notifications', ['id' => (int) $row['id']]);
            $purged++;
        }

        return $purged;
    }

    // ================= CSV Builder (Formula-Injection-safe) =================

    /**
     * ساخت CSV با BOM (Persian/Excel) + محافظت Formula-Injection (FR-21.x):
     * سلول‌های شروع‌شده با `= + - @` یا شامل Tab/CR ابتدایی → پیشوند `'`.
     *
     * @param array<string, mixed> $result
     */
    public static function buildCsv(array $result, int $actorUserId): string
    {
        $user = get_userdata($actorUserId);
        $userName = $user !== false ? $user->display_name : ('user-' . $actorUserId);

        $lines = [];
        $lines[] = '# CPMS Report: ' . (string) ($result['label'] ?? '');
        $lines[] = '# Type: ' . (string) ($result['type'] ?? '') . ' | Scope: ' . (string) ($result['scope'] ?? '');
        $lines[] = '# Range: ' . (string) ($result['from'] ?? '') . ' .. ' . (string) ($result['to'] ?? '')
            . ' (Jalali: ' . (string) ($result['from_jalali'] ?? '') . ' .. ' . (string) ($result['to_jalali'] ?? '') . ')';
        $lines[] = '# Generated: ' . gmdate('Y-m-d H:i:s') . ' UTC by ' . $userName . ' — محرمانه';
        $lines[] = '';

        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
        if ($rows !== []) {
            $columns = array_keys($rows[0]);
            $lines[] = self::csvRow($columns);
            foreach ($rows as $r) {
                $lines[] = self::csvRow(array_map(
                    static fn ($v): string => $v === null ? '' : (is_bool($v) ? ($v ? '1' : '0') : (string) $v),
                    array_map(static fn (string $c) => $r[$c] ?? '', $columns)
                ));
            }
            $lines[] = '';
        }

        $lines[] = self::csvRow(['summary_key', 'value']);
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        foreach ($summary as $k => $v) {
            $lines[] = self::csvRow([$k, is_array($v) ? (string) json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v]);
        }
        if (isset($result['by_method']) && is_array($result['by_method'])) {
            $lines[] = '';
            $lines[] = self::csvRow(['method', 'payments', 'gross', 'refunded', 'net']);
            foreach ($result['by_method'] as $m) {
                $lines[] = self::csvRow([
                    $m['method'] ?? '',
                    (string) ($m['payments'] ?? 0),
                    (string) ($m['gross'] ?? 0),
                    (string) ($m['refunded'] ?? 0),
                    (string) ($m['net'] ?? 0),
                ]);
            }
        }

        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }

    /**
     * @param list<string> $cells
     */
    private static function csvRow(array $cells): string
    {
        return implode(',', array_map(static function ($cell): string {
            $cell = (string) $cell;
            // محافظت Formula-Injection: =,+,-,@ و کنترل‌کاراکترهای ابتدایی
            if (preg_match('/^[=+\-@\t\r]/', $cell) === 1) {
                $cell = "'" . $cell;
            }
            if (preg_match('/[",\r\n]/', $cell) === 1) {
                $cell = '"' . str_replace('"', '""', $cell) . '"';
            }

            return $cell;
        }, $cells));
    }

    // ================= Authz =================

    private function requireReportAccess(int $actorUserId, string $type): void
    {
        if (!$this->reports->isKnownType($type)) {
            throw ReportException::of('CLINIC_NOT_FOUND', 'نوع گزارش ناشناخته است', 404, ['type' => $type]);
        }

        $this->requireCap($actorUserId, RolesAndCapabilities::REPORT_READ, 'گزارش‌ها');

        $user = get_userdata($actorUserId);
        if ($user === false) {
            throw ReportException::of('CLINIC_PERMISSION_DENIED', 'دسترسی لازم را ندارید', 403);
        }

        $missing = [];
        foreach ($this->reports->typeCaps($type) as $cap) {
            if (!$user->has_cap($cap)) {
                $missing[] = $cap;
            }
        }
        if ($missing !== []) {
            throw ReportException::of(
                'CLINIC_PERMISSION_DENIED',
                'برای این گزارش Capability لازم ندارید: ' . implode(', ', $missing),
                403,
                ['type' => $type, 'missing' => $missing]
            );
        }
    }

    private function requireCap(int $actorUserId, string $cap, string $what): void
    {
        $user = get_userdata($actorUserId);
        if ($user === false || !$user->has_cap($cap)) {
            throw ReportException::of(
                'CLINIC_PERMISSION_DENIED',
                "دسترسی لازم برای {$what} را ندارید",
                403,
                ['capability' => $cap]
            );
        }
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

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentExport(array $row): array
    {
        $payload = json_decode((string) $row['payload_json'], true);
        $export = is_array($payload['export'] ?? null) ? $payload['export'] : [];

        return [
            'notification_id' => (int) $row['id'],
            'type' => (string) ($export['type'] ?? ''),
            'from' => $export['from'] ?? null,
            'to' => $export['to'] ?? null,
            'scope' => $export['scope'] ?? null,
            'file_name' => $export['file_name'] ?? null,
            'expires_at' => $export['expires_at'] ?? null,
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
