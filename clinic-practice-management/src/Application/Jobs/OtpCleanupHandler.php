<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * پاک‌سازی OTPهای قدیمی (Job: cleanup.otp — روزانه؛ رکوردهای >24 ساعت).
 */
final class OtpCleanupHandler
{
    public function __construct(private readonly CpmsDb $db)
    {
    }

    public function __invoke(array $payload): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - 86400) . '.000';

        return $this->db->query(
            'DELETE FROM ' . $this->db->table('cpms_otp_tokens') . ' WHERE created_at < %s',
            [$cutoff]
        );
    }
}
