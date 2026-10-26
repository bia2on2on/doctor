<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Storage;

use ClinicCore\Infrastructure\Backup\ProtectedBackupStore;

/**
 * راه‌اندازیِ صریح و idempotentِ ریشه‌های **فعالِ** ذخیره‌سازی خصوصی.
 *
 * چرا این کلاس وجود دارد (نقصِ واقعیِ پذیرش):
 *  - پیش از این، پوشهٔ ذخیره‌سازی بالینی و گاردهایش فقط **در اولین نوشتنِ
 *    فایل** (`LocalFileStorage::store()` → `ensureGuards()`) ساخته می‌شدند.
 *    یعنی در نصبِ تازه، تا اولین آپلود، هیچ ریشهٔ فعالی وجود نداشت و
 *    `SystemHealthService::storageStatus()` آن را `NOT_CONFIGURED` گزارش
 *    می‌کرد — که در UI به گروهِ «پیکربندیِ اختیاری» می‌رفت. یعنی یک نیازِ
 *    واقعیِ بالینی به‌صورت «اختیاری/سالم» نمایش داده می‌شد.
 *  - شکستِ نوشتنِ گاردها در آن مسیر با `@file_put_contents` بی‌صدا نادیده
 *    گرفته می‌شد.
 *
 * این کلاس:
 *  - **به legacy وابسته نیست**: اگر ریشهٔ قدیمی وجود نداشته باشد، ریشهٔ فعالِ
 *    جدید ساخته می‌شود (مهاجرت یک موضوعِ جداگانه است).
 *  - **بدونِ ClinicScope** کار می‌کند: راه‌اندازی، سطحِ نصب است.
 *  - هر ریشه را با `PrivateStorageLocation::assertOutsideWebRoot()` بررسی
 *    می‌کند (realpath ⇒ symlink و `..` هم دنبال می‌شوند) و در صورت داخل‌بودن
 *    در DocumentRoot، **خطای تایپ‌شده** می‌دهد؛ هیچ fallback بی‌صدایی به مسیر
 *    عمومی و هیچ `chmod 777`ای وجود ندارد.
 *  - پوشه را با 0750 می‌سازد، همهٔ گاردهای لازم را می‌نویسد و **پس از نوشتن
 *    دوباره می‌خواند**؛ شکست به خطای تایپ‌شده تبدیل می‌شود تا UI بتواند خطای
 *    قابل‌اقدام نشان دهد (نه «اختیاری/سالم»).
 *  - به دادهٔ موجود دست نمی‌زند: پوشهٔ موجود بازنویسی/پاک نمی‌شود، گاردِ
 *    موجود بازنویسی نمی‌شود و مسیرِ ریشه هرگز خودکار عوض نمی‌شود.
 *
 * `ensure_initialized()` مسیرِ ارزانِ هر-درخواستی است: یک خواندنِ Option و
 * تمام؛ فقط وقتی «امضا» (نسخهٔ گاردها + مسیرها) عوض شود کارِ واقعی انجام
 * می‌شود. به همین دلیل می‌توان آن را در `admin_init`/`rest_api_init` صدا زد
 * بدون اینکه روی هر درخواست یک `is_dir`/`is_file` اضافه شود.
 */
final class PrivateStorageInitializer
{
    /** Optionِ سطحِ نصب که آخرین نتیجهٔ موفق/ناموفق را نگه می‌دارد (autoload=no). */
    public const STATE_OPTION = 'cpms_private_storage_state';

    /**
     * نسخهٔ قراردادِ گاردها. تغییرِ مجموعهٔ گاردها باید این عدد را بالا ببرد
     * تا نصب‌های موجود یک‌بار دوباره راه‌اندازی شوند.
     */
    public const GUARD_VERSION = 1;

    public const ROOT_FILES  = 'files';
    public const ROOT_BACKUPS = 'backups';

    /**
     * ریشه‌های پیش‌فرضِ سطحِ نصب.
     *
     * مسیرها **آرگومان** هستند تا این کلاس به Settingهای Clinic-local وابسته
     * نباشد؛ فراخوان (App/SystemPage) تصمیم می‌گیرد کدام مسیرِ فعال است.
     *
     * @return array{ok:bool,roots:array<string,array{path:string,ok:bool,created:bool,guards:list<string>,error_code:string,error:string}>}
     */
    public static function initialize(string $files_root, string $backup_root): array
    {
        $result = [
            'ok'    => true,
            'roots' => [],
        ];

        foreach ([self::ROOT_FILES => $files_root, self::ROOT_BACKUPS => $backup_root] as $key => $path) {
            $path = rtrim(trim($path), '/');
            if ($path === '') {
                $result['roots'][$key] = self::failure(
                    '',
                    'EMPTY_PATH',
                    'مسیر ریشهٔ ذخیره‌سازی خالی است.'
                );
                $result['ok'] = false;
                continue;
            }

            $existed = is_dir($path);
            try {
                if (self::ROOT_FILES === $key) {
                    // سازنده خودش Fail-Closed بررسیِ بیرون‌بودن از webroot را انجام می‌دهد.
                    $storage = new LocalFileStorage($path);
                    $written = $storage->ensureReady();
                } else {
                    $store = ProtectedBackupStore::active($path);
                    $before = array_keys($store->guard_files());
                    $store->ensureGuards();
                    $written = [];
                    foreach ($before as $name) {
                        if (!is_file($path . '/' . $name)) {
                            continue;
                        }
                        $written[] = $name;
                    }
                }
            } catch (StorageConfigurationException $e) {
                $result['roots'][$key] = self::failure($path, $e->errorCode, $e->getMessage());
                $result['ok'] = false;
                continue;
            } catch (\Throwable $e) {
                $result['roots'][$key] = self::failure($path, 'STORAGE_INIT_FAILED', $e->getMessage());
                $result['ok'] = false;
                continue;
            }

            $result['roots'][$key] = [
                'path'        => $path,
                'ok'          => true,
                'created'     => !$existed,
                'guards'      => array_values(array_filter($written, static fn ($n): bool => $n !== '__dir_created__')),
                'error_code'  => '',
                'error'       => '',
            ];
        }

        self::remember($files_root, $backup_root, $result);

        return $result;
    }

    /**
     * مسیرِ ارزانِ هر-درخواستی: اگر امضای مسیرها و نسخهٔ گاردها تغییر نکرده و
     * آخرین تلاش موفق بوده، کاری انجام نمی‌شود (یک خواندنِ Option).
     *
     * وقتی آخرین تلاش **ناموفق** بوده، دوباره تلاش می‌شود؛ چون میزبان ممکن است
     * بعداً قابل‌نوشتن شده باشد و خطای پایدار نباید تا ابد کش شود.
     *
     * @return array{ok:bool,roots:array<string,mixed>}
     */
    public static function ensure_initialized(string $files_root, string $backup_root): array
    {
        $state = self::state();
        if (is_array($state)
            && ($state['guard_version'] ?? null) === self::GUARD_VERSION
            && ($state['roots'][self::ROOT_FILES]['path'] ?? null) === rtrim($files_root, '/')
            && ($state['roots'][self::ROOT_BACKUPS]['path'] ?? null) === rtrim($backup_root, '/')
            && ($state['ok'] ?? false) === true) {
            /** @var array{ok:bool,roots:array<string,mixed>} $state */
            return $state;
        }

        return self::initialize($files_root, $backup_root);
    }

    /**
     * آخرین نتیجهٔ ثبت‌شده (بدونِ نوشتن/تلاش).
     *
     * @return array<string, mixed>|null
     */
    public static function state(): ?array
    {
        if (!function_exists('get_option')) {
            return null;
        }
        $state = get_option(self::STATE_OPTION, null);

        return is_array($state) ? $state : null;
    }

    /**
     * پاک‌کردنِ حالتِ ثبت‌شده — فقط برای تست.
     */
    public static function forget(): void
    {
        if (function_exists('delete_option')) {
            delete_option(self::STATE_OPTION);
        }
    }

    /**
     * @param array{ok:bool,roots:array<string,array<string,mixed>>} $result
     */
    private static function remember(string $files_root, string $backup_root, array $result): void
    {
        if (!function_exists('update_option')) {
            return;
        }
        update_option(
            self::STATE_OPTION,
            [
                'guard_version' => self::GUARD_VERSION,
                'files_root'    => rtrim($files_root, '/'),
                'backup_root'   => rtrim($backup_root, '/'),
                'ok'            => $result['ok'],
                'roots'         => $result['roots'],
                'checked_at'    => time(),
            ],
            false
        );
    }

    /**
     * @return array{path:string,ok:bool,created:bool,guards:list<string>,error_code:string,error:string}
     */
    private static function failure(string $path, string $code, string $message): array
    {
        return [
            'path'       => $path,
            'ok'         => false,
            'created'    => false,
            'guards'     => [],
            'error_code' => $code,
            'error'      => $message,
        ];
    }
}
