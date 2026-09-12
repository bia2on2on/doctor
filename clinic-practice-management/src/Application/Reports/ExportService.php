<?php

declare(strict_types=1);

namespace ClinicCore\Application\Reports;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Notifications\NotificationEvents;
use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Queue\JobQueue;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\NotificationRepository;
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
 *
 * **Phase 2 (Slice 1B.1) — Scope-Neutral:** این سرویس **بدونِ Clinic قابلِ
 * ساخت است**. هیچ‌یک از وابستگی‌های Clinic-دار (`ReportService`،
 * `NotificationService`، `LocalFileStorage`، `Settings`) در سازنده تزریق
 * نمی‌شوند؛ همه از `ExportClinicDeps` و فقط برای یک Clinicِ **از‌پیش‌تعیین‌شده
 * توسط مرزِ قابلِ اعتماد** حل می‌شوند. نتیجه: ساختِ سرویس هیچ خواندنِ
 * Settings/فایل/پیکربندیِ tenant-دار انجام نمی‌دهد و `App::dispatcher()` دیگر
 * نیازی ندارد `clinic_id` خامِ payload را به `ScopeContext` bind کند.
 *
 * **مرزِ اعتماد (context ≠ authorization):** در مسیر Job، شناسهٔ payload تنها
 * یک «انتخابِ عملیات» است، نه مجوز. پیش از هر دسترسیِ tenant-دار،
 * `requireClinicMembership()` عضویتِ **فعال** actor در همان Clinic را با
 * `MembershipRepository::find_active()` می‌سنجد و در غیر این صورت fail-closed
 * است. Capability سراسریِ WordPress به‌تنهایی هرگز مجوزِ عبور از مرز Clinic
 * نیست. (این یک گارْدِ محدودِ Phase 2 است، نه `AuthorizationService` فاز ۳.)
 */
final class ExportService
{
    /**
     * بستهٔ وابستگی‌های Clinic-دار، memo شده برای هر Clinic در طولِ عمرِ همین
     * نمونه. `App::exportService()` نمونه را memo نمی‌کند، پس این کش به یک
     * درخواست/یک job محدود است و بین Clinicها نشت نمی‌کند.
     *
     * @var array<int, ExportClinicDeps>
     */
    private array $depsByClinic = [];

    /**
     * @param \Closure(int): ExportClinicDeps $clinicDeps کارخانهٔ بستهٔ Clinic —
     *        **فقط** با شناسهٔ Clinic‌ای صدا زده می‌شود که یک مرزِ قابلِ اعتماد
     *        تعیین کرده است. سازندهٔ خودِ ExportService هیچ Clinic‌ای نمی‌خواهد.
     */
    public function __construct(
        private readonly CpmsDb $db,
        private readonly NotificationRepository $notificationRows,
        private readonly MembershipRepository $memberships,
        private readonly JobQueue $jobs,
        private readonly \Closure $clinicDeps,
        private readonly AuditLogger $audit,
        private readonly OpLogger $op
    ) {
    }

    /**
     * حلِ بستهٔ وابستگی برای یک Clinicِ **از‌پیش‌مجوزگرفته**.
     *
     * Memo به‌ازای هر Clinic؛ چون `App::exportService()` نمونه را memo نمی‌کند،
     * این کش به یک درخواست/یک job محدود است و بین Clinicها نشت نمی‌کند. در
     * `purgeExpired()` هم به‌جای N+1، به‌ازای هر Clinicِ متمایز یک‌بار حل می‌شود.
     */
    private function depsFor(int $clinicId): ExportClinicDeps
    {
        if (!isset($this->depsByClinic[$clinicId])) {
            $deps = ($this->clinicDeps)($clinicId);
            if (!$deps instanceof ExportClinicDeps) {
                // قراردادِ کارخانه نقض شده — fail-closed، بدون fallback و بدون حدس.
                throw new RuntimeException('CLINIC_EXPORT_DEPS_FACTORY_INVALID');
            }
            $this->depsByClinic[$clinicId] = $deps;
        }

        return $this->depsByClinic[$clinicId];
    }

    // ================= 1) درخواست (فقط Enqueue) =================

    /**
     * درخواست Export — fail-fast روی مجوز/بازه؛ تولید فایل async.
     *
     * @return array<string, mixed>
     */
    public function request(int $actorUserId, string $type, ?string $from, ?string $to): array
    {
        // Clinic معتبر همین درخواست — مرزِ قابلِ اعتماد (`TrustedClinicEstablisher`
        // از عضویتِ تأییدشده). **پیش از** هر دسترسیِ Clinic-دار حل می‌شود تا
        // بستهٔ وابستگی فقط برای همین Clinic ساخته شود.
        $clinicId = $this->trustedClinicId();
        $deps = $this->depsFor($clinicId);

        $this->requireReportAccess($deps->reports, $actorUserId, $type);
        $this->requireCap($actorUserId, RolesAndCapabilities::EXPORT, 'خروجی گرفتن از گزارش');

        // بازه را همین‌جا اعتبارسنجی می‌کنیم (خطای کاربر نباید به Job برود)
        $range = $deps->reports->validateRangeParams($type, $from, $to);
        [$scopeMode] = $deps->reports->resolveScope($actorUserId);

        $jobId = $this->jobs->enqueue('report.export', [
            'actor_id' => $actorUserId,
            'clinic_id' => $clinicId,
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
            ['type' => $type, 'job_id' => $jobId, 'clinic_id' => $clinicId],
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

        // (۱) اعتبارِ عددیِ شناسه — این **مجوز نیست**، فقط تعیینِ عملیات.
        $clinicId = $this->clinicIdFromJobPayload($payload);

        // (۲) مجوزِ tenant — **پیش از** هر دسترسیِ Clinic-دار. payload می‌تواند
        //     دستکاری‌شده باشد؛ capability سراسریِ WordPress به‌تنهایی اجازهٔ
        //     عبور از مرز Clinic را نمی‌دهد (Fail-Closed، بدون fallback).
        $this->requireClinicMembership($actorUserId, $clinicId);

        // (۳) حالا — و فقط حالا — وابستگی‌های همان Clinic حل می‌شوند.
        $deps = $this->depsFor($clinicId);

        $restore = $this->bindJobClinic($clinicId);
        try {
            $this->generateInClinic($deps, $actorUserId, $clinicId, $type, $from, $to);
        } finally {
            $restore();
        }
    }

    /**
     * @param ExportClinicDeps $deps بستهٔ همان `$clinicId` — از `generate()`
     */
    private function generateInClinic(ExportClinicDeps $deps, int $actorUserId, int $clinicId, string $type, ?string $from, ?string $to): void
    {
        $this->requireReportAccess($deps->reports, $actorUserId, $type);
        $this->requireCap($actorUserId, RolesAndCapabilities::EXPORT, 'خروجی گرفتن از گزارش');

        $settings = $deps->settings;
        $maxRows = (int) $settings->get('reports.export_max_rows', 10000);
        $result = $deps->reports->run($actorUserId, $type, $from, $to, $maxRows);

        $retentionDays = (int) $settings->get('reports.export_retention_days', 7);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($retentionDays * 86400));

        $csv = self::buildCsv($result, $actorUserId);
        $storagePath = $deps->storage->store($csv, $clinicId, 'csv');

        // «فایل + اعلان» — payload اعلان مالکیت/مسیر/انقضا را حمل می‌کند
        $notifId = $deps->notifications->publishToUser(
            $clinicId,
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
            'clinic_id' => $clinicId,
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

        $rows = $this->notificationRows->forUser($this->trustedClinicId(), $actorUserId, false, 100, 0, NotificationEvents::REPORT_EXPORT_READY);

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
        // Clinic از مرزِ قابلِ اعتمادِ درخواست — storageِ همان Clinic استفاده
        // می‌شود، پس پیش از لمسِ فایل حل می‌شود.
        $clinicId = $this->trustedClinicId();
        $deps = $this->depsFor($clinicId);

        $this->requireCap($actorUserId, RolesAndCapabilities::EXPORT, 'خروجی گرفتن از گزارش');

        $row = $this->notificationRows->find($notificationId);
        if ($row === null
            || (string) $row['template'] !== NotificationEvents::REPORT_EXPORT_READY
            || (int) $row['recipient_wp_user_id'] !== $actorUserId
            || (int) $row['clinic_id'] !== $clinicId) {
            throw ReportException::of('CLINIC_NOT_FOUND', 'خروجی یافت نشد', 404);
        }

        $payload = json_decode((string) $row['payload_json'], true);
        $export = is_array($payload['export'] ?? null) ? $payload['export'] : null;
        if ($export === null || empty($export['file_path'])) {
            throw ReportException::of('CLINIC_NOT_FOUND', 'خروجی یافت نشد', 404);
        }

        if (!empty($export['expires_at']) && $export['expires_at'] < $this->db->nowUtcSql()) {
            $deps->storage->delete((string) $export['file_path']);
            throw ReportException::of('CLINIC_EXPORT_EXPIRED', 'مهلت دانلود این خروجی گذشته است', 410);
        }

        $content = $deps->storage->read((string) $export['file_path']);
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
     *
     * Job سیستمی است: بدون predicate کلینیک ثابت و بدون کاربر جاری.
     * هر ردیف Clinic خودش را حمل می‌کند (retention همان Clinic).
     */
    public function purgeExpired(): int
    {
        $rows = $this->db->fetchAll(
            'SELECT id, clinic_id, payload_json, created_at FROM ' . $this->db->table('cpms_notifications') .
            ' WHERE template = %s LIMIT 200',
            [NotificationEvents::REPORT_EXPORT_READY]
        );

        $purged = 0;
        $cutoffByClinic = [];
        foreach ($rows as $row) {
            $clinicId = (int) $row['clinic_id'];
            if ($clinicId <= 0) {
                continue;
            }
            // هر ردیف Clinic خودش را حمل می‌کند — retention **و** ریشهٔ storage
            // هم از پیکربندیِ همان Clinic می‌آید، نه از Clinicِ bootstrap.
            // `depsFor()` memo است، پس به‌ازای هر Clinic فقط یک‌بار حل می‌شود
            // (بدونِ N+1 در حلقه).
            $deps = $this->depsFor($clinicId);
            if (!isset($cutoffByClinic[$clinicId])) {
                $days = max(1, (int) $deps->settings->get('reports.export_retention_days', 7));
                $cutoffByClinic[$clinicId] = gmdate('Y-m-d H:i:s', time() - ($days * 86400)) . '.000';
            }
            if ((string) $row['created_at'] >= $cutoffByClinic[$clinicId]) {
                continue;
            }

            $payload = json_decode((string) $row['payload_json'], true);
            $path = (string) ($payload['export']['file_path'] ?? '');
            if ($path !== '') {
                try {
                    $deps->storage->delete($path);
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

    // ================= Tenant (HTTP vs Job) =================

    /**
     * Clinic معتبر مسیر درخواست (REST/staff) — کلاینت به‌تنهایی منبع اعتماد نیست.
     */
    private function trustedClinicId(): int
    {
        return App::scope()->clinicId;
    }

    /**
     * Clinic Job از payload است، نه کاربر جاری و نه Scope باقی‌ماندهٔ HTTP.
     *
     * @param array<string, mixed> $payload
     */
    private function clinicIdFromJobPayload(array $payload): int
    {
        $clinicId = (int) ($payload['clinic_id'] ?? 0);
        if ($clinicId <= 0) {
            throw new ScopeRequiredException(
                'CLINIC_SCOPE_REQUIRED',
                'خروجی گزارش Clinic معتبر در payload ندارد. Clinic از Job است، نه از کاربر جاری.',
                ['clinic_id' => $clinicId]
            );
        }

        return $clinicId;
    }

    /**
     * @return callable(): void بازگردانی Scope قبلی
     */
    private function bindJobClinic(int $clinicId): callable
    {
        $previous = ScopeContext::tryGet();
        App::resetScope();
        ScopeContext::set(ClinicScope::forClinic($clinicId));

        return static function () use ($previous): void {
            App::resetScope();
            if ($previous !== null) {
                ScopeContext::set($previous);
            }
        };
    }

    // ================= Authz =================

    /**
     * **گارْدِ محدودِ Phase 2 برای عبور از مرز Clinic** (Slice 1B.1).
     *
     * چرا لازم است: `clinic_id` یک Job payload یک «انتخابِ عملیات» است، نه یک
     * ادعایِ مجوز. تا پیش از این، `generate()` فقط capability‌های **سراسریِ**
     * WordPress را می‌سنجید (`cpms_report_read` + `cpms_export` + cap نوع
     * گزارش) و سپس دادهٔ **همان** Clinicِ payload را صادر می‌کرد. یعنی یک
     * payload دستکاری‌شده می‌توانست با همان capability سراسری، خروجیِ Clinic
     * دیگری بگیرد (confused deputy).
     *
     * این گارْد عمداً **محدود** است:
     *  - فقط از primitive موجودِ عضویت استفاده می‌کند
     *    (`MembershipRepository::find_active()` = `cpms_clinic_memberships`
     *    با `status = 'active'`)؛
     *  - هیچ نقش/سیاستِ فاز ۳ را اختراع نمی‌کند؛
     *  - در نبودِ عضویتِ فعال **fail-closed** است: بدون fallback، بدون انتخابِ
     *    Clinic پیش‌فرض، بدونِ صدورِ خروجی.
     *
     * دامنهٔ کاربرد: فقط مسیرهایی که شناسهٔ Clinic از payload می‌آید. مسیر
     * REST نیازی به این گارْد ندارد چون Clinic آنجا از `TrustedClinicEstablisher`
     * (همان عضویتِ تأییدشده) می‌آید؛ افزودنِ دوبارهٔ آن در `request()` می‌توانست
     * نصبِ تک‌کلینیکی را که scope‌اش از `SystemClinicResolver` می‌آید بشکند.
     *
     * @throws ReportException با کدِ پایدارِ `CLINIC_EXPORT_CLINIC_NOT_AUTHORIZED`
     */
    private function requireClinicMembership(int $actorUserId, int $clinicId): void
    {
        $denied = ReportException::of(
            'CLINIC_EXPORT_CLINIC_NOT_AUTHORIZED',
            'عضویت فعال در این Clinic برای خروجی گرفتن لازم است.',
            403,
            ['clinic_id' => $clinicId]
        );

        if ($actorUserId <= 0 || get_userdata($actorUserId) === false) {
            throw $denied;
        }

        if ($this->memberships->find_active($clinicId, $actorUserId) === null) {
            throw $denied;
        }
    }

    private function requireReportAccess(ReportService $reports, int $actorUserId, string $type): void
    {
        if (!$reports->isKnownType($type)) {
            throw ReportException::of('CLINIC_NOT_FOUND', 'نوع گزارش ناشناخته است', 404, ['type' => $type]);
        }

        $this->requireCap($actorUserId, RolesAndCapabilities::REPORT_READ, 'گزارش‌ها');

        $user = get_userdata($actorUserId);
        if ($user === false) {
            throw ReportException::of('CLINIC_PERMISSION_DENIED', 'دسترسی لازم را ندارید', 403);
        }

        $missing = [];
        foreach ($reports->typeCaps($type) as $cap) {
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
