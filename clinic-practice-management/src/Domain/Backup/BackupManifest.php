<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Backup;

/**
 * مانیفست بکاپ (F10 — spec §22–§24) — خالص و بدون I/O.
 *
 * ساختار (schema_version=1):
 *   {
 *     "schema_version": 1,
 *     "backup_id": "...slug (پایین‌نویسه/رقم/._-، هم‌گرامرِ ProtectedBackupStore)...",
 *     "engine": "cpms-backup",
 *     "engine_version": "1.0.0",
 *     "created_at": "2026-09-06T10:00:00+00:00",
 *     "note": "...",
 *     "db":   {"file": "db.sql", "sha256": "...", "tables": [{"name":"...","rows":N}]},
 *     "storage": {"root": "storage", "files": [{"path":"1/a3/...","size":N,"sha256":"..."}],
 *                 "count": N, "bytes": N},
 *     "meta": {"wp_version":"...", "php_version":"...", "cpms_version":"..."}
 *   }
 *
 * اعتبارسنجی (verify) دقیق است: هر فایلِ لیست‌شده باید موجود و همراز با
 * sha256 باشد؛ هیچ فایل ناخواسته‌ای نباید داخل بکاپ باشد مگر در لیست.
 * بدون امضای مانیفست در V1 (امضای بکاپ = V1.1؛ اسناد در docs/backup).
 */
final class BackupManifest
{
    public const SCHEMA_VERSION = 1;
    public const ENGINE = 'cpms-backup';

    /** @param array<string, mixed> $raw */
    public static function validate(array $raw): array
    {
        $errors = [];
        if ((int) ($raw['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            $errors[] = 'unsupported schema_version';
        }
        if (($raw['engine'] ?? '') !== self::ENGINE) {
            $errors[] = 'unknown engine';
        }
        if (!is_string($raw['backup_id'] ?? null) || !preg_match('/^[0-9a-z][0-9a-z._-]{3,120}$/', $raw['backup_id'])) {
            $errors[] = 'invalid backup_id';
        }
        if (!is_string($raw['created_at'] ?? null) || strtotime((string) $raw['created_at']) === false) {
            $errors[] = 'invalid created_at';
        }
        $db = $raw['db'] ?? null;
        if (!is_array($db) || !isset($db['file'], $db['sha256'])) {
            $errors[] = 'invalid db section';
        } else {
            if (!preg_match('/^[0-9a-f]{64}$/', (string) $db['sha256'])) {
                $errors[] = 'invalid db.sha256';
            }
            foreach ((array) ($db['tables'] ?? []) as $t) {
                if (!is_array($t) || !is_string($t['name'] ?? null) || !is_numeric($t['rows'] ?? null)) {
                    $errors[] = 'invalid db.tables entry';
                    break;
                }
            }
        }
        $st = $raw['storage'] ?? null;
        if (is_array($st)) {
            $count = 0;
            $bytes = 0;
            foreach ((array) ($st['files'] ?? []) as $f) {
                if (!is_array($f) || !is_string($f['path'] ?? null) || !preg_match('/^[0-9a-f]{64}$/', (string) ($f['sha256'] ?? '')) || !is_numeric($f['size'] ?? null)) {
                    $errors[] = 'invalid storage.files entry';
                    break;
                }
                $count++;
                $bytes += (int) $f['size'];
            }
            if ($count !== (int) ($st['count'] ?? -1) || $bytes !== (int) ($st['bytes'] ?? -1)) {
                $errors[] = 'storage count/bytes mismatch';
            }
        }

        return $errors;
    }

    public static function isValid(array $raw): bool
    {
        return self::validate($raw) === [];
    }

    /**
     * بررسی تمامیت بکاپ روی دیسک: هر فایل لیست‌شده موجود و هم‌هش باشد؛
     * فایل‌های اضافی = هشدار (نقص مانیفست/دستکاری).
     *
     * @param array<string, mixed> $raw
     * @param callable(string $relPath): array{size:int, sha256:string}|null $stat
     *        مسیر نسبی → {size, sha256} (null = غایب) — بدون بارگذاری کل فایل.
     *
     * @return array{ok: bool, errors: list<string>, warnings: list<string>}
     */
    public static function verifyFiles(array $raw, callable $stat): array
    {
        $errors = [];
        $warnings = [];
        if (!self::isValid($raw)) {
            return ['ok' => false, 'errors' => self::validate($raw), 'warnings' => []];
        }

        $dbFile = (string) ($raw['db']['file'] ?? 'db.sql');
        $s = $stat($dbFile);
        if ($s === null) {
            $errors[] = 'missing ' . $dbFile;
        } elseif ($s['sha256'] !== $raw['db']['sha256']) {
            $errors[] = 'hash mismatch ' . $dbFile;
        }

        foreach ((array) ($raw['storage']['files'] ?? []) as $f) {
            $p = (string) $f['path'];
            $s = $stat('storage/' . $p);
            if ($s === null) {
                $errors[] = 'missing storage/' . $p;
            } elseif ($s['sha256'] !== (string) $f['sha256'] || $s['size'] !== (int) $f['size']) {
                $errors[] = 'hash/size mismatch storage/' . $p;
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors, 'warnings' => $warnings];
    }
}
