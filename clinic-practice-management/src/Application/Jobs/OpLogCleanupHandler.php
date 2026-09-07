<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Settings\Settings;

/**
 * پاک‌سازی لاگ‌های عملیاتی قدیمی (Job: cleanup.oplog — F1-5).
 *
 * ریشه: `cpms_operational_logs` جدول hot-path است (Request/Job/Error ردیف
 * می‌نویسد) اما هیچ Retentionای نداشت → رشد بی‌کران (همان کلاس مشکلی که
 * `cleanup.idem` برای Idempotency بست). برخلاف Audit (۱۰ سال — سند حقوقی)،
 * OpLog فنی/غیرحقوقی است — پیش‌فرض ۹۰ روز، قابل تغییر با Setting
 * `retention.oplog_days` (همگام: docs/settings-reference.md).
 */
final class OpLogCleanupHandler
{
    public function __construct(private readonly CpmsDb $db, private readonly Settings $settings)
    {
    }

    public function __invoke(array $payload): int
    {
        $days = max(1, (int) $this->settings->get('retention.oplog_days', 90));
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400) . '.000';

        return $this->db->execute(
            'DELETE FROM ' . $this->db->table('cpms_operational_logs') . ' WHERE created_at < %s',
            [$cutoff]
        );
    }
}
