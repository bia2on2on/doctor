<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Application\Reports\ExportService;
use ClinicCore\Application\Reports\ReportException;
use ClinicCore\Application\Reports\ReportService;
use ClinicCore\Auth\RolesAndCapabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * G5 (F8 — گزارش‌ها):
 *  - GET  /reports                       — کاتالوگ گزارش‌های مجاز Actor
 *  - GET  /reports/{type}?from&to        — اجرای گزارش (cpms_report_read)
 *  - GET  /reports/{type}/print?from&to  — نسخه چاپی HTML با Watermark (بدون Dependency PDF)
 *  - POST /reports/{type}/export         — درخواست CSV async (cpms_export + Audit EXPORT)
 *  - GET  /reports/exports               — فهرست Exportهای خود Actor
 *  - GET  /reports/exports/{id}/download — دانلود محافظت‌شده (مالک + Audit)
 *
 * مسیر {type} به ۱۲ نوع شناخته‌شده محدود است (بدون برخورد با /exports).
 */
final class ReportsController extends RestBase
{
    private const TYPE_PATTERN = '(appointments_today|appointments_week|cancellations|no_shows|walk_ins|visits|avg_waiting|visit_duration|revenue|payment_methods|open_balances|follow_ups_due)';

    public function __construct(
        private readonly ReportService $reports,
        private readonly ExportService $exports
    ) {
    }

    public function register_routes(): void
    {
        register_rest_route(self::NS, '/reports', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $r) => $this->staff($r, RolesAndCapabilities::REPORT_READ, fn (): array => [
                    'reports' => $this->reports->catalog($this->userId($r)),
                ]),
                'permission_callback' => '__return_true',
            ],
        ]);

        register_rest_route(self::NS, '/reports/(?P<type>' . self::TYPE_PATTERN . ')', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $r) => $this->staff($r, RolesAndCapabilities::REPORT_READ, fn (): array => $this->reports->run(
                    $this->userId($r),
                    (string) $r['type'],
                    $r['from'] ?? null,
                    $r['to'] ?? null
                )),
                'permission_callback' => '__return_true',
                'args' => [
                    // اعتبارسنجی تاریخ در Service (CLINIC_VALIDATION_FAILED/422) —
                    // نه format:date وردپرس (400 rest_invalid_param)
                    'from' => ['required' => false, 'type' => 'string'],
                    'to' => ['required' => false, 'type' => 'string'],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/reports/(?P<type>' . self::TYPE_PATTERN . ')/print', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $r) => $this->printView($r),
                'permission_callback' => '__return_true',
                'args' => [
                    // اعتبارسنجی تاریخ در Service (CLINIC_VALIDATION_FAILED/422) —
                    // نه format:date وردپرس (400 rest_invalid_param)
                    'from' => ['required' => false, 'type' => 'string'],
                    'to' => ['required' => false, 'type' => 'string'],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/reports/(?P<type>' . self::TYPE_PATTERN . ')/export', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->staff($r, RolesAndCapabilities::REPORT_READ, fn (): array => $this->exports->request(
                    $this->userId($r),
                    (string) $r['type'],
                    $r['from'] ?? null,
                    $r['to'] ?? null
                ), 202),
                'permission_callback' => '__return_true',
                'args' => [
                    // اعتبارسنجی تاریخ در Service (CLINIC_VALIDATION_FAILED/422) —
                    // نه format:date وردپرس (400 rest_invalid_param)
                    'from' => ['required' => false, 'type' => 'string'],
                    'to' => ['required' => false, 'type' => 'string'],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/reports/exports', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $r) => $this->staff($r, RolesAndCapabilities::REPORT_READ, fn (): array => $this->exports->listFor($this->userId($r))),
                'permission_callback' => '__return_true',
            ],
        ]);

        register_rest_route(self::NS, '/reports/exports/(?P<id>\d+)/download', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $r) => $this->download($r),
                'permission_callback' => '__return_true',
            ],
        ]);
    }

    // ================= Print View (Watermark) =================

    /**
     * FR-19.3 — Watermark روی خروجی حساس: نسخه چاپی HTML (مرورگر → چاپ/PDF)
     * با Watermark کاربر+زمان+Scope. PDF سمت سرور = Backlog (پیش‌زمینه F6 —
     * بدون Dependency جدید).
     */
    private function printView(WP_REST_Request $r): WP_REST_Response|WP_Error
    {
        $error = $this->requireNonce($r);
        if ($error instanceof WP_Error) {
            return $error;
        }
        $perm = $this->requireCap(RolesAndCapabilities::REPORT_READ);
        if ($perm instanceof WP_Error) {
            return $perm;
        }

        try {
            $result = $this->reports->run(
                $this->userId($r),
                (string) $r['type'],
                $r['from'] ?? null,
                $r['to'] ?? null
            );

            return new WP_REST_Response($this->renderPrintHtml($result, $this->userId($r)), 200, [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'private, max-age=0, no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (ReportException $e) {
            return $this->error($e->errorCode, $e->httpStatus, $e->getMessage(), $e->data);
        } catch (\Throwable $e) {
            error_log('[CPMS][ReportsController] print unexpected: ' . get_class($e) . ': ' . $e->getMessage());

            return $this->error('CLINIC_INTERNAL_ERROR', 500, 'خطای داخلی سرور — لطفاً دوباره تلاش کنید');
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function renderPrintHtml(array $result, int $actorUserId): string
    {
        $user = get_userdata($actorUserId);
        $who = $user !== false ? ($user->display_name !== '' ? $user->display_name : $user->user_login) : ('user-' . $actorUserId);
        $stamp = gmdate('Y-m-d H:i:s') . ' UTC';
        $watermark = htmlspecialchars($who . ' — ' . $stamp . ' — ' . (string) ($result['scope'] ?? ''), ENT_QUOTES);

        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
        $columns = $rows !== [] ? array_keys($rows[0]) : [];

        $thead = '';
        foreach ($columns as $c) {
            $thead .= '<th>' . htmlspecialchars((string) $c, ENT_QUOTES) . '</th>';
        }

        $tbody = '';
        foreach ($rows as $row) {
            $tbody .= '<tr>';
            foreach ($columns as $c) {
                $v = $row[$c] ?? '';
                $tbody .= '<td>' . htmlspecialchars(is_bool($v) ? ($v ? '1' : '0') : (string) $v, ENT_QUOTES) . '</td>';
            }
            $tbody .= '</tr>';
        }

        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        $summaryHtml = '';
        foreach ($summary as $k => $v) {
            $summaryHtml .= '<span class="chip"><b>' . htmlspecialchars((string) $k, ENT_QUOTES) . '</b>: '
                . htmlspecialchars(is_array($v) ? (string) json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v, ENT_QUOTES)
                . '</span>';
        }

        return '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
            . '<title>' . htmlspecialchars((string) ($result['label'] ?? ''), ENT_QUOTES) . ' — CPMS</title>'
            . '<style>'
            . 'body{font-family:Tahoma,Vazirmatn,sans-serif;margin:24px;color:#111;position:relative;}'
            . 'h1{font-size:18px;margin:0 0 4px;}'
            . '.meta{color:#555;font-size:12px;margin-bottom:16px;}'
            . 'table{width:100%;border-collapse:collapse;font-size:12px;}'
            . 'th,td{border:1px solid #bbb;padding:4px 6px;text-align:right;}'
            . 'th{background:#f2f2f2;}'
            . '.chip{display:inline-block;background:#f6f6f6;border:1px solid #ddd;border-radius:4px;'
            . 'padding:2px 8px;margin:0 0 4px 4px;font-size:11px;}'
            . '.watermark{position:fixed;top:42%;left:50%;transform:translate(-50%,-50%) rotate(-24deg);'
            . 'font-size:34px;color:rgba(160,0,0,.13);white-space:nowrap;z-index:5;pointer-events:none;}'
            . '@media print{.watermark{position:absolute;}}'
            . '</style></head><body>'
            . '<div class="watermark">' . $watermark . '</div>'
            . '<h1>' . htmlspecialchars((string) ($result['label'] ?? ''), ENT_QUOTES) . '</h1>'
            . '<div class="meta">بازه: ' . htmlspecialchars((string) ($result['from'] ?? ''), ENT_QUOTES)
            . ' تا ' . htmlspecialchars((string) ($result['to'] ?? ''), ENT_QUOTES)
            . ' (' . htmlspecialchars((string) ($result['from_jalali'] ?? ''), ENT_QUOTES)
            . ' تا ' . htmlspecialchars((string) ($result['to_jalali'] ?? ''), ENT_QUOTES) . ')'
            . ' — Scope: ' . htmlspecialchars((string) ($result['scope'] ?? ''), ENT_QUOTES)
            . ' — چاپ: ' . $watermark . '</div>'
            . '<div>' . $summaryHtml . '</div>'
            . '<table><thead><tr>' . $thead . '</tr></thead><tbody>' . $tbody . '</tbody></table>'
            . '<p style="color:#777;font-size:10px;margin-top:18px">CPMS — خروجی حساس؛ تکثیر/اشتراک‌گذاری تابع سیاست حریم خصوصی مطب است.</p>'
            . '</body></html>';
    }

    // ================= Download (E17-style Stream) =================

    private function download(WP_REST_Request $r): WP_REST_Response|WP_Error
    {
        $error = $this->requireNonce($r);
        if ($error instanceof WP_Error) {
            return $error;
        }

        try {
            $file = $this->exports->download($this->userId($r), (int) $r['id']);
        } catch (ReportException $e) {
            return $this->error($e->errorCode, $e->httpStatus, $e->getMessage(), $e->data);
        } catch (\Throwable $e) {
            error_log('[CPMS][ReportsController] download unexpected: ' . get_class($e) . ': ' . $e->getMessage());

            return $this->error('CLINIC_INTERNAL_ERROR', 500, 'خطای داخلی سرور — لطفاً دوباره تلاش کنید');
        }

        $response = new WP_REST_Response($file['content'], 200);
        $response->header('Content-Type', 'text/csv; charset=utf-8');
        $response->header('Content-Disposition', 'attachment; filename="' . rawurlencode($file['file_name']) . '"');
        $response->header('Cache-Control', 'private, max-age=0, no-cache');
        $response->header('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    // ================= Helpers =================

    /**
     * Endpoint کارمند مطب — Nonce + Capability + Envelope خطای گزارش.
     *
     * @template T
     * @param callable(): T $fn
     */
    private function staff(WP_REST_Request $r, string $cap, callable $fn, ?int $status = null): WP_REST_Response|WP_Error
    {
        $nonce = $this->requireNonce($r);
        if ($nonce instanceof WP_Error) {
            return $nonce;
        }
        $perm = $this->requireCap($cap);
        if ($perm instanceof WP_Error) {
            return $perm;
        }

        try {
            return $this->success($fn(), $status ?? 200);
        } catch (ReportException $e) {
            return $this->error($e->errorCode, $e->httpStatus, $e->getMessage(), $e->data);
        } catch (\Throwable $e) {
            error_log('[CPMS][ReportsController] unexpected: ' . get_class($e) . ': ' . $e->getMessage());

            return $this->error('CLINIC_INTERNAL_ERROR', 500, 'خطای داخلی سرور — لطفاً دوباره تلاش کنید');
        }
    }

    private function userId(WP_REST_Request $r): int
    {
        return (int) (wp_get_current_user()->ID ?: 0);
    }
}
