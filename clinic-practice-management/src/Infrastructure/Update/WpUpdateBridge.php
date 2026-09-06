<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Update;

use ClinicCore\Application\Update\UpdateService;
use WP_Error;

/**
 * پل اتصال به سیستم به‌روزرسانی استاندارد وردپرس (F10 / ADR-0029 §"installer local").
 *
 * فقط سه هوکِ کم‌عرضه دارد:
 *  1. `pre_set_site_transient_update_plugins` — CPMS را فقط وقتی UpdateService
 *     بگوید نسخهٔ امضاشده موجود است وارد فهرست به‌روزرسانی وردپرس می‌کند
 *     (فقط slug خودمان؛ کش‌شده؛ بدون شبکه در صفحات عادی کلینیک).
 *  2. `plugins_api` — جزئیات نسخه برای صفحهٔ "View details".
 *  3. `upgrader_pre_download` — پیش از آن‌که WP Installer بسته را لمس کند،
 *     خودِ ما بسته را دانلود و sha256 آن را با مانیفست امضاشده مقایسه می‌کنیم؛
 *     عدم تطابق = توقف (فایلِ محلیِ تأییدشده به WP داده می‌شود).
 *
 * هیچ `eval`/کد از راه دور اجرا نمی‌شود؛ نصب همیشه توسط وردپرس (User-initiated
 * یا WP-CLI) انجام می‌شود — اینجا فقط authorize + integrity-verify است.
 */
final class WpUpdateBridge
{
    public const PLUGIN_SLUG = 'clinic-practice-management';

    public function __construct(private readonly UpdateService $updates)
    {
    }

    // ================= transient به‌روزرسانی =================

    /**
     * فیلتر `pre_set_site_transient_update_plugins`.
     *
     * @param mixed $transient
     *
     * @return mixed
     */
    public function injectUpdatePlugins(mixed $transient): mixed
    {
        if (!is_object($transient) || !function_exists('plugin_basename')) {
            return $transient;
        }
        $basename = $this->pluginBasename();
        $result = $this->cachedCheck();
        if (($result['available'] ?? false) !== true || $basename === '') {
            return $transient;
        }

        $entry = self::updateEntry($result, $basename);
        $transient->response[$basename] = $entry;

        return $transient;
    }

    /**
     * فیلتر `plugins_api` — جزئیات نسخه برای CPMS فقط.
     *
     * @param mixed $result
     * @param string $action
     * @param mixed $args
     *
     * @return mixed
     */
    public function injectPluginInfo(mixed $result, string $action, mixed $args): mixed
    {
        if ($action !== 'plugin_information' || !is_object($args)) {
            return $result;
        }
        $slug = (string) ($args->slug ?? '');
        if ($slug !== self::PLUGIN_SLUG) {
            return $result;
        }
        $info = $this->cachedCheck();
        if (($info['available'] ?? false) !== true) {
            return $result;
        }

        return self::apiObject($info, $slug);
    }

    // ================= صحت بسته پیش از نصب =================

    /**
     * فیلتر `upgrader_pre_download` — فقط برای بستهٔ خودِ CPMS.
     *
     * @param mixed $reply
     * @param string $package
     * @param mixed $upgrader
     * @param array<string, mixed> $hookExtra
     *
     * @return mixed
     */
    public function verifyPackageBeforeInstall(mixed $reply, string $package, mixed $upgrader, array $hookExtra): mixed
    {
        $plugin = (string) ($hookExtra['plugin'] ?? '');
        if ($plugin !== $this->pluginBasename()) {
            return $reply;
        }
        // مانیفست تازه بگیر (user-initiated update → یک درخواست شبکه مجاز است)
        $channel = $this->channel();
        $info = $this->updates->checkForUpdates(true, $channel);
        if (($info['available'] ?? false) !== true) {
            return new WP_Error('CLINIC_UPDATE_UNAVAILABLE', 'به‌روزرسانی CPMS دیگر در دسترس نیست — صفحه را تازه کنید.');
        }
        $expected = (string) ($info['package_url'] ?? '');
        $expectedSha = strtolower((string) ($info['package_sha256'] ?? ''));
        if ($expected === '' || $expectedSha === '' || $package !== $expected) {
            return new WP_Error('CLINIC_UPDATE_SOURCE_MISMATCH', 'منبع به‌روزرسانی با مانیفست امضاشده هم‌خوان نیست.');
        }
        if (!function_exists('download_url') || !function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('download_url')) {
            return new WP_Error('CLINIC_UPDATE_IO', 'دانلود بسته ممکن نیست (download_url در دسترس نیست).');
        }

        $tmp = download_url($expected, 300);
        if (is_wp_error($tmp)) {
            return new WP_Error('CLINIC_UPDATE_DOWNLOAD_FAILED', 'دانلود بسته ناموفق بود: ' . $tmp->get_error_message());
        }
        $actualSha = strtolower(hash_file('sha256', $tmp) ?: '');
        if ($actualSha !== $expectedSha) {
            @unlink($tmp);

            return new WP_Error('CLINIC_UPDATE_INTEGRITY', 'sha256 بسته با مانیفست امضاشده تطابق ندارد — نصب متوقف شد.');
        }
        // فایلِ محلیِ تأییدشده به WP_Upgrader داده می‌شود تا «قبل از نصب» همه‌چیز
        // تأیید شده باشد؛ پاک‌سازی در پایان فرایند PHP.
        register_shutdown_function(static function () use ($tmp): void {
            @unlink($tmp);
        });

        return $tmp;
    }

    // ================= helpers =================

    private function cachedCheck(): array
    {
        return $this->updates->checkForUpdates(false, $this->channel());
    }

    private function channel(): string
    {
        return $this->updates->channel();
    }

    private function pluginBasename(): string
    {
        if (!defined('CPMS_PLUGIN_FILE')) {
            return '';
        }

        return (string) plugin_basename(CPMS_PLUGIN_FILE);
    }

    /**
     * ساخت ورودی transient (خالص — قابل Unit Test).
     *
     * @param array<string, mixed> $result خروجی UpdateService (available=true)
     * @param string $basename
     */
    public static function updateEntry(array $result, string $basename): object
    {
        $entry = new \stdClass();
        $entry->slug = self::PLUGIN_SLUG;
        $entry->plugin = $basename;
        $entry->new_version = (string) ($result['version'] ?? '');
        $entry->url = (string) ($result['release_notes'] ?? '');
        $entry->package = (string) ($result['package_url'] ?? '');
        $entry->requires = '6.4';
        $entry->requires_php = '8.1';

        return $entry;
    }

    /**
     * ساخت شیء plugins_api (خالص — قابل Unit Test).
     *
     * @param array<string, mixed> $result
     * @param string $slug
     */
    public static function apiObject(array $result, string $slug): object
    {
        $o = new \stdClass();
        $o->name = 'Clinic Practice Management (CPMS)';
        $o->slug = $slug;
        $o->version = (string) ($result['version'] ?? '');
        $o->author = 'CPMS';
        $o->download_link = (string) ($result['package_url'] ?? '');
        $o->requires = '6.4';
        $o->requires_php = '8.1';
        $o->last_updated = gmdate('Y-m-d H:i:s', (int) ($result['checked_at'] ?? time()));
        $o->sections = [
            'description' => (string) ($result['release_notes'] ?? 'به‌روزرسانی امن CPMS.'),
        ];

        return $o;
    }
}
