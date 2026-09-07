<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Application\Clinical\ClinicalException;
use ClinicCore\Bootstrap\App;

/**
 * صفحه «چاپ نسخه» — مخفی (بدون آیتم منو)؛ URL: admin.php?page=cpms-prescription-print&visit_id=…
 * (Part 2 / ADR-0031 — ممیزی P12: در V1 نسخه چاپ نداشت.)
 *
 * مجوز سه‌لایه: `cpms_rx_read` (منو/رندر) + مالکیت ویزیت در سرویس
 * (`prescriptionForPrint` → requireOwnVisit) + Audit `PRESCRIPTION_PRINTED`.
 * خروجی: A4 چاپ‌آمیز فارسی/RTL با Watermark «پیش‌نویس/ابطال‌شده» + خط چاپ.
 */
final class PrescriptionPrintPage
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
    }

    /** صفحه مخفی — بدون نمایش در منو؛ دسترسی از دکمه «🖨️ چاپ» داشبورد پزشک. */
    public static function menu(): void
    {
        add_submenu_page(
            null,
            'چاپ نسخه',
            'چاپ نسخه',
            RolesAndCapabilities::RX_READ,
            'cpms-prescription-print',
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (!current_user_can(RolesAndCapabilities::RX_READ)) {
            wp_die('دسترسی ندارید', 403);
        }

        $visitId = isset($_GET['visit_id']) ? absint($_GET['visit_id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET فقط-خواندن؛ مجوز/مالکیت سمت سرویس؛ تغییرات اینجا نیست
        $rxId = isset($_GET['rx']) ? absint($_GET['rx']) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($visitId <= 0) {
            wp_die('شناسه ویزیت نامعتبر است.', 400);
        }

        try {
            $data = App::clinicalService()->prescriptionForPrint(get_current_user_id(), $visitId, $rxId);
        } catch (ClinicalException $e) {
            wp_die(esc_html($e->getMessage()), $e->httpStatus);
        }

        $rx = $data['prescription'];
        $status = (string) $rx['status'];
        ?>
<html dir="rtl" lang="fa">
<head>
    <meta charset="utf-8">
    <title>نسخه <?php echo esc_html((string) $rx['prescription_number']); ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, "Segoe UI", Tahoma, "Iranian Sans", Tahoma, sans-serif; color: #111; margin: 24px auto; max-width: 800px; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: 10px; }
        .clinic h1 { font-size: 21px; margin: 0 0 4px; }
        .clinic small { color: #444; }
        .rx-mark { font-size: 34px; font-weight: 700; direction: ltr; }
        .info { width: 100%; border-collapse: collapse; margin: 14px 0; }
        .info td { padding: 3px 6px; font-size: 14px; }
        .complaint { background: #f5f5f5; padding: 8px 10px; border-radius: 4px; font-size: 14px; margin: 10px 0; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.items th, table.items td { border: 1px solid #999; padding: 6px 8px; font-size: 14px; text-align: right; }
        table.items th { background: #eee; }
        .sig { margin-top: 48px; display: flex; justify-content: space-between; font-size: 14px; }
        .sig .line { border-top: 1px solid #111; padding-top: 4px; width: 200px; text-align: center; }
        .watermark { position: fixed; top: 42%; left: 50%; transform: translate(-50%, -50%) rotate(-18deg); font-size: 72px; font-weight: 800; color: rgba(179, 45, 46, .25); pointer-events: none; }
        .footer { margin-top: 28px; font-size: 11px; color: #666; border-top: 1px solid #ccc; padding-top: 6px; }
        .toolbar { margin: 0 auto 14px; max-width: 800px; text-align: left; }
        @media print { .toolbar, .no-print { display: none !important; } body { margin: 0; } }
    </style>
</head>
<body>
<div class="toolbar no-print">
    <button class="button button-primary" onclick="window.print()">🖨️ چاپ</button>
    <a class="button" href="javascript:history.back()">بازگشت</a>
    <?php if ($status === 'draft') : ?>
        <span style="color:#b32d2e">این نسخه «پیش‌نویس» است — پس از نهایی‌سازی روی نسخه بیمار داروخانه‌ای معتبر است.</span>
    <?php endif; ?>
</div>

<?php if ($status === 'voided') : ?>
    <div class="watermark">ابطال‌شده</div>
<?php elseif ($status === 'draft') : ?>
    <div class="watermark" style="color: rgba(240,150,0,.3)">پیش‌نویس</div>
<?php endif; ?>

<div class="head">
    <div class="clinic">
        <h1><?php echo esc_html((string) ($data['clinic']['name'] !== '' ? $data['clinic']['name'] : 'مطب')); ?></h1>
        <small>
            <?php echo esc_html((string) ($data['clinic']['address'] ?? '')); ?>
            <?php if (!empty($data['clinic']['phone'])) : ?>
                — تلفن: <span dir="ltr"><?php echo esc_html((string) $data['clinic']['phone']); ?></span>
            <?php endif; ?>
        </small>
    </div>
    <div class="rx-mark">℞</div>
    <div class="doctor-info" style="text-align:left">
        <b>دکتر <?php echo esc_html((string) $data['doctor']['name']); ?></b><br>
        <small><?php echo esc_html((string) ($data['doctor']['specialty'] ?? '')); ?></small>
    </div>
</div>

<table class="info">
    <tr>
        <td><b>بیمار:</b> <?php echo esc_html((string) $data['patient']['full_name']); ?></td>
        <td><b>شماره پرونده:</b> <span dir="ltr"><?php echo esc_html((string) $data['patient']['mrn']); ?></span></td>
        <td><b>سن:</b> <?php echo $data['patient']['age'] !== null ? esc_html((string) $data['patient']['age']) : '—'; ?></td>
    </tr>
    <tr>
        <td><b>تاریخ ویزیت:</b> <?php echo esc_html((string) $data['visit_jalali']); ?></td>
        <td><b>شماره نسخه:</b> <span dir="ltr"><?php echo esc_html((string) $rx['prescription_number']); ?></span></td>
        <td><b>اتاق:</b> <?php echo esc_html((string) ($data['doctor']['room'] ?? '—')); ?></td>
    </tr>
</table>

<?php if (!empty($data['chief_complaint'])) : ?>
    <div class="complaint"><b>شکایت اصلی:</b> <?php echo esc_html((string) $data['chief_complaint']); ?></div>
<?php endif; ?>

<table class="items">
    <thead>
        <tr>
            <th style="width:28px">#</th>
            <th>دارو</th>
            <th>قدرت</th>
            <th>دوز</th>
            <th>تواتر مصرف</th>
            <th>مسیر</th>
            <th>مدت (روز)</th>
            <th>توضیح</th>
        </tr>
    </thead>
    <tbody>
    <?php if ($rx['items'] === []) : ?>
        <tr><td colspan="8">قلمی ثبت نشده است.</td></tr>
    <?php endif; ?>
    <?php foreach ($rx['items'] as $i => $it) : ?>
        <tr>
            <td><?php echo $i + 1; ?></td>
            <td><b><?php echo esc_html((string) $it['generic_name']); ?></b><?php echo $it['brand_name'] !== null ? ' <small>(' . esc_html((string) $it['brand_name']) . ')</small>' : ''; ?></td>
            <td><?php echo esc_html((string) ($it['strength'] ?? '—')); ?></td>
            <td><?php echo esc_html((string) $it['dose']); ?></td>
            <td><?php echo esc_html((string) $it['frequency']); ?></td>
            <td><?php echo esc_html((string) $it['route']); ?></td>
            <td><?php echo $it['duration_days'] !== null ? esc_html((string) $it['duration_days']) : '—'; ?></td>
            <td><?php echo esc_html((string) ($it['instructions'] ?? '')); ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<div class="sig">
    <div class="line">امضای پزشک</div>
    <div class="line" dir="ltr"></div>
</div>

<div class="footer">
    چاپ‌شده در <?php echo esc_html((string) $data['printed_at_utc']); ?> UTC — سیستم مدیریت مطب (CPMS) — این سند در Audit ثبت شده است.
</div>
</body>
</html>
        <?php
    }
}
