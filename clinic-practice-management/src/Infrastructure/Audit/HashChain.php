<?php

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName,WordPress.NamingConventions.ValidFunctionName,Squiz.Functions.FunctionDeclarationArgumentSpacing,PEAR.Functions.FunctionCallSignature,WordPress.WhiteSpace.ControlStructureSpacing,Generic.WhiteSpace,Generic.Functions.OpeningFunctionBraceKernighanRitchie -- hash chain uses camelCase prevHash/storedPrev and compact declaration style; security sniffs remain.

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
        // نرمال‌سازی تایپ: در زمان نوشتن، idها از PHP با int می‌آیند اما در
        // زمان خواندن (verify) wpdb همه‌چیز را string برمی‌گرداند — بدون cast،
        // canonical JSON دو مسیر متفاوت می‌شد و زنجیره همیشه شکسته دیده می‌شد.
        $intOrNull = static function ($value): ?int {
            return ($value === null || $value === '') ? null : (int) $value;
        };
        $stringOrNull = static function ($value): ?string {
            return ($value === null || $value === '') ? null : (string) $value;
        };

        return [
            'clinic_id' => $intOrNull($row['clinic_id'] ?? null),
            'actor_wp_user_id' => $intOrNull($row['actor_wp_user_id'] ?? null),
            'actor_role' => $stringOrNull($row['actor_role'] ?? null),
            'action' => $stringOrNull($row['action'] ?? null),
            'resource_type' => $stringOrNull($row['resource_type'] ?? null),
            'resource_id' => $intOrNull($row['resource_id'] ?? null),
            'patient_id' => $intOrNull($row['patient_id'] ?? null),
            'ip_hash' => $stringOrNull($row['ip_hash'] ?? null),
            'session_id' => $stringOrNull($row['session_id'] ?? null),
            'request_id' => $stringOrNull($row['request_id'] ?? null),
            'created_at' => $stringOrNull($row['created_at'] ?? null),
        ];
    }

    /**
     * صحت‌سنجی زنجیر (ترتیب صعودی id).
     *
     * هر گره هم `prev_hash` ذخیره‌شده و هم `row_hash` را تأیید می‌کند: `prev_hash` باید
     * دقیقاً برابر `row_hash` گرهٔ قبلی باشد و `row_hash` باید از `prev_hash` ذخیره‌شده
     * + فیلدهای کانونی بازمحاسبه شود. این همان منطق `tools/audit-chain/verify.py` است.
     *
     * @param list<array<string, mixed>> $rows هر ردیف: prev_hash, row_hash + فیلدهای fieldsFor
     * @param string|null $anchor هشِ واقعیِ پیش از نخستین ردیف؛ null = GENESIS برای زنجیر کامل.
     *
     * @return array{ok: bool, checked: int, broken_at: int|null, broken_id: int|null}
     */
    public static function verify(array $rows, ?string $anchor = null): array
    {
        $prev = $anchor ?? self::GENESIS;
        foreach ($rows as $i => $row) {
            $stored_prev = (string) ($row['prev_hash'] ?? '');
            $actual = (string) ($row['row_hash'] ?? '');
            // ۱) پیوند زنجیر: مقدار ذخیره‌شدهٔ prev_hash باید برابر هشِ واقعیِ گرهٔ پیشین باشد.
            if (!hash_equals($prev, $stored_prev)) {
                return ['ok' => false, 'checked' => $i + 1, 'broken_at' => $i + 1, 'broken_id' => isset($row['id']) ? (int) $row['id'] : null];
            }
            // ۲) صحت گره: row_hash باید از همین prev_hash ذخیره‌شده بازمحاسبه شود.
            $expected = self::computeRowHash($stored_prev, self::fieldsFor($row));
            if (!hash_equals($expected, $actual)) {
                return ['ok' => false, 'checked' => $i + 1, 'broken_at' => $i + 1, 'broken_id' => isset($row['id']) ? (int) $row['id'] : null];
            }
            $prev = $actual;
        }

        return ['ok' => true, 'checked' => count($rows), 'broken_at' => null, 'broken_id' => null];
    }

    /**
     * صحت‌سنجی کامل از GENESIS (برای تصدیق مستقل کل تاریخچه).
     *
     * @param list<array<string, mixed>> $rows ترتیب صعودی id، بدون جاافتادگی.
     *
     * @return array{ok: bool, checked: int, broken_at: int|null, broken_id: int|null}
     */
    public static function verify_full_chain(array $rows): array
    {
        return self::verify($rows, self::GENESIS);
    }

    /**
     * صحت‌سنجی پسوند (suffix): نخستین ردیف به لنگرِ واقعی (سلفِ durable آن) بسته می‌شود،
     * نه به GENESIS. برای `verifyChain(limit)` که فقط N ردیف آخر را می‌خواند.
     *
     * @param list<array<string, mixed>> $rows ترتیب صعودی id
     * @param string $anchor row_hash سلفِ نخستین ردیف یا GENESIS اگر سلفی نیست.
     *
     * @return array{ok: bool, checked: int, broken_at: int|null, broken_id: int|null}
     */
    public static function verify_suffix(array $rows, string $anchor): array
    {
        return self::verify($rows, $anchor);
    }
}
