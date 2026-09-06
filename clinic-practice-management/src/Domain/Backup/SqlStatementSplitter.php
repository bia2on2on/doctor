<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Backup;

/**
 * جداسازی Statementهای SQL برای اجرای امن تک‌تک (V1) — خالص.
 *
 * نقطه‌ی جداسازی: «;» خارج از string literal/comment. پشتیبانی:
 *  - '...' و "..." (با escape \' \" \\ و doubled quotes)
 *  - کامنت‌های `-- ` و `#` (تا پایان خط) و /* *​*​/ (چندخطی) — چون dump ما
 *    خودمان تولید می‌شود، این پشتیبانی برای ایمنی و تست است.
 *
 * Restore هرگز چند Statement را یک‌جا execute نمی‌کند (SQL Injection/
 * ابهام) — BackupService هر قطعه را با wpdb->query جدا اجرا می‌کند.
 */
final class SqlStatementSplitter
{
    /**
     * @return list<string>
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $len = strlen($sql);
        $buf = '';
        $i = 0;
        while ($i < $len) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            // string literal
            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $buf .= $ch;
                $i++;
                while ($i < $len) {
                    $c = $sql[$i];
                    $n = $i + 1 < $len ? $sql[$i + 1] : '';
                    if ($c === '\\' && $n !== '') {
                        $buf .= $c . $n;
                        $i += 2;
                        continue;
                    }
                    if ($c === $quote) {
                        // doubled quote = escaped
                        if ($n === $quote) {
                            $buf .= $c . $n;
                            $i += 2;
                            continue;
                        }
                        $buf .= $c;
                        $i++;
                        break;
                    }
                    $buf .= $c;
                    $i++;
                }
                continue;
            }

            // line comment — حذف کامل (سِمیکالن داخل کامنت جداکننده نیست)
            if (($ch === '-' && $next === '-') || $ch === '#') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            // block comment — حذف کامل
            if ($ch === '/' && $next === '*') {
                $i += 2;
                while ($i + 1 < $len && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                    $i++;
                }
                if ($i + 1 < $len) {
                    $i += 2; // رد کردن '*/'
                }
                continue;
            }

            if ($ch === ';') {
                $stmt = trim($buf);
                if ($stmt !== '') {
                    $statements[] = $stmt;
                }
                $buf = '';
                $i++;
                continue;
            }

            $buf .= $ch;
            $i++;
        }
        $tail = trim($buf);
        if ($tail !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }
}
