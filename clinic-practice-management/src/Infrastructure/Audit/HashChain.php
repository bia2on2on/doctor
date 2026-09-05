<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Audit;

/**
 * زنجیر هش Audit (ADR-0008) — خالص و قابل تست.
 *
 * row_hash = SHA-256(prev_hash + canonical(fields))
 * canonical: JSON با کلید مرتب‌شده.
 */
final class HashChain
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * @param array<string, scalar|null> $fields
     */
    public static function computeRowHash(string $prevHash, array $fields): string
    {
        ksort($fields);
        $canonical = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

        return hash('sha256', $prevHash . $canonical);
    }

    /**
     * فیلدهای ورودیِ هش (سفیدفهرست — before/after/meta در زنجیر نمی‌روند تا Chain سبک بماند
     * و PHI در Hash قابل استخراج نباشد؛ آن‌ها خودشان در رکورد ذخیره می‌شوند).
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, scalar|null>
     */
    public static function fieldsFor(array $row): array
    {
        return [
            'clinic_id' => $row['clinic_id'] ?? null,
            'actor_wp_user_id' => $row['actor_wp_user_id'] ?? null,
            'actor_role' => $row['actor_role'] ?? null,
            'action' => $row['action'] ?? null,
            'resource_type' => $row['resource_type'] ?? null,
            'resource_id' => $row['resource_id'] ?? null,
            'patient_id' => $row['patient_id'] ?? null,
            'ip_hash' => $row['ip_hash'] ?? null,
            'session_id' => $row['session_id'] ?? null,
            'request_id' => $row['request_id'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }

    /**
     * صحت‌سنجی زنجیر (ترتیب صعودی id).
     *
     * @param list<array<string, mixed>> $rows هر ردیف: prev_hash, row_hash + فیلدهای fieldsFor
     *
     * @return array{ok: bool, checked: int, broken_at: int|null}
     */
    public static function verify(array $rows): array
    {
        $prev = self::GENESIS;
        foreach ($rows as $i => $row) {
            $expected = self::computeRowHash($prev, self::fieldsFor($row));
            $actual = (string) ($row['row_hash'] ?? '');
            if (!hash_equals($expected, $actual)) {
                return ['ok' => false, 'checked' => $i + 1, 'broken_at' => $i + 1];
            }
            $prev = $actual;
        }

        return ['ok' => true, 'checked' => count($rows), 'broken_at' => null];
    }
}
