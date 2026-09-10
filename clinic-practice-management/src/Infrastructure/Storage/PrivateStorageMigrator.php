<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Storage;

/**
 * OD-7 — مهاجرت idempotent از ریشهٔ قدیمیِ داخل DocumentRoot به ریشهٔ خصوصی.
 *
 * ## قرارداد ایمنی
 *
 * - **فایل مبدأ تا زمانی که مقصد تأیید نشده حذف نمی‌شود.** ترتیب همیشه
 *   «کپی → تأیید sha256 و اندازه → حذف مبدأ» است، هرگز «انتقال و امید».
 * - **idempotent** — اجرای دوباره روی وضعیت مهاجرت‌شده هیچ کاری نمی‌کند. اگر
 *   مقصد از قبل موجود و هم‌هش باشد، فقط مبدأ (در صورت وجود) پاک می‌شود.
 * - **شکست جزئی امن است** — هر فایل مستقل پردازش می‌شود. یک فایل خراب فقط
 *   خودش را ناموفق می‌کند، در مبدأ دست‌نخورده می‌ماند و در گزارش ثبت می‌شود.
 *   اجرای بعدی دوباره تلاش می‌کند.
 * - **بدون بازنویسی داده‌های ناهمخوان** — اگر مقصد موجود باشد ولی هشش فرق
 *   کند، فایل به‌عنوان `conflict` گزارش می‌شود و **هیچ‌کدام** از دو طرف تغییر
 *   نمی‌کنند. تصمیم با اپراتور است.
 * - **بدون حذف پوشهٔ مبدأ** — طبق قید «هیچ فایلی حذف نشود»، فقط فایل‌های
 *   مهاجرت‌شدهٔ تأییدشده پاک می‌شوند و خودِ پوشه باقی می‌ماند.
 *
 * ## Rollback
 *
 * چون مبدأ فقط پس از تأیید بایت‌به‌بایت مقصد حذف می‌شود، در هر لحظه دست‌کم یک
 * نسخهٔ سالم وجود دارد. برای برگشت کافی است `files.storage_path` (یا
 * `backup.storage_path`) به مسیر قدیمی برگردانده شود و همین کلاس در جهت
 * معکوس اجرا شود؛ فایل‌هایی که قبلاً منتقل شده‌اند در مقصد جدید سالم‌اند.
 *
 * این کلاس **قابلیت بکاپ فاز ۱۵ نیست** — نه زمان‌بندی دارد، نه رمزنگاری، نه
 * نگه‌داشت نسخه. فقط یک جابه‌جایی تأییدشدهٔ یک‌باره است.
 */
final class PrivateStorageMigrator
{
    /** فایل‌های گاردِ خودِ پوشه — منتقل نمی‌شوند؛ مقصد گاردهای خودش را می‌سازد. */
    private const GUARD_FILES = ['.htaccess', 'index.php', 'web.config', 'README-SECURITY.txt'];

    /**
     * @return array{moved:int,already:int,conflict:int,failed:int,errors:list<string>,skipped_reason:?string}
     */
    public function migrate(string $legacyRoot, string $privateRoot): array
    {
        $result = [
            'moved' => 0,
            'already' => 0,
            'conflict' => 0,
            'failed' => 0,
            'errors' => [],
            'skipped_reason' => null,
        ];

        $legacyRoot = rtrim(str_replace('\\', '/', $legacyRoot), '/');
        $privateRoot = rtrim(str_replace('\\', '/', $privateRoot), '/');

        if ($legacyRoot === '' || $privateRoot === '' || $legacyRoot === $privateRoot) {
            $result['skipped_reason'] = 'same_or_empty_path';

            return $result;
        }
        if (!is_dir($legacyRoot)) {
            $result['skipped_reason'] = 'no_legacy_dir';

            return $result;
        }
        if (!is_dir($privateRoot) && !@mkdir($privateRoot, 0750, true) && !is_dir($privateRoot)) {
            $result['skipped_reason'] = 'private_root_unwritable';
            $result['errors'][] = 'cannot create ' . $privateRoot;

            return $result;
        }

        foreach ($this->files($legacyRoot) as $absolute) {
            $relative = ltrim(substr($absolute, strlen($legacyRoot)), '/');
            if ($relative === '' || in_array(basename($relative), self::GUARD_FILES, true)) {
                continue;
            }
            $this->migrateOne($absolute, $privateRoot . '/' . $relative, $result);
        }

        return $result;
    }

    /**
     * @param array{moved:int,already:int,conflict:int,failed:int,errors:list<string>,skipped_reason:?string} $result
     */
    private function migrateOne(string $source, string $destination, array &$result): void
    {
        $sourceHash = @hash_file('sha256', $source);
        $sourceSize = @filesize($source);
        if (!is_string($sourceHash) || $sourceSize === false) {
            $result['failed']++;
            $result['errors'][] = 'unreadable source: ' . $source;

            return;
        }

        // از قبل مهاجرت شده؟ مقصد را تأیید کن و تنها آن‌گاه مبدأ را پاک کن.
        if (is_file($destination)) {
            if (@hash_file('sha256', $destination) === $sourceHash) {
                if (@unlink($source)) {
                    $result['already']++;
                } else {
                    $result['failed']++;
                    $result['errors'][] = 'verified but source not removable: ' . $source;
                }
            } else {
                // هرگز داده‌ی ناهمخوان را بازنویسی نکن.
                $result['conflict']++;
                $result['errors'][] = 'destination exists with different content: ' . $destination;
            }

            return;
        }

        $dir = dirname($destination);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            $result['failed']++;
            $result['errors'][] = 'cannot create ' . $dir;

            return;
        }

        // کپی به یک فایل موقت، سپس تأیید، سپس rename اتمیک. اگر فرایند وسط کار
        // بمیرد، فقط یک `.part` باقی می‌ماند و مبدأ دست‌نخورده است.
        $temp = $destination . '.part';
        if (!@copy($source, $temp)) {
            $result['failed']++;
            $result['errors'][] = 'copy failed: ' . $source;
            @unlink($temp);

            return;
        }

        if (@filesize($temp) !== $sourceSize || @hash_file('sha256', $temp) !== $sourceHash) {
            @unlink($temp);
            $result['failed']++;
            $result['errors'][] = 'verification failed after copy: ' . $source;

            return;
        }

        if (!@rename($temp, $destination)) {
            @unlink($temp);
            $result['failed']++;
            $result['errors'][] = 'rename failed: ' . $destination;

            return;
        }

        // تأیید نهایی روی مقصدِ نهایی — تنها پس از این، مبدأ حذف می‌شود.
        if (@hash_file('sha256', $destination) !== $sourceHash) {
            $result['failed']++;
            $result['errors'][] = 'post-rename verification failed: ' . $destination;

            return;
        }

        @chmod($destination, 0640);
        if (!@unlink($source)) {
            // مقصد سالم است؛ فقط مبدأ باقی مانده. اجرای بعدی آن را جمع می‌کند.
            $result['failed']++;
            $result['errors'][] = 'copied and verified but source not removable: ' . $source;

            return;
        }

        $result['moved']++;
    }

    /**
     * @return list<string>
     */
    private function files(string $root): array
    {
        $out = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        /** @var \SplFileInfo $info */
        foreach ($iterator as $info) {
            if ($info->isFile()) {
                $out[] = str_replace('\\', '/', $info->getPathname());
            }
        }
        sort($out);

        return $out;
    }
}
