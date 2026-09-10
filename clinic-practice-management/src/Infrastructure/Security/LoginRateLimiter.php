<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Security;

use ClinicCore\Infrastructure\Logging\OpLogger;

/**
 * محدودسازی نرخ ورود به WordPress — Phase 1A، Item 3.
 *
 * وضعیت پیش از Phase 1: مستندِ خودِ RateLimiter کلید `login:{ip}` را
 * تبلیغ می‌کرد، اما در کل افزونه هیچ فراخوانی‌ای برای آن وجود نداشت و
 * هیچ Hook ای روی `authenticate` / `wp_login_failed` بسته نشده بود؛ یعنی
 * فرم ورود و احراز هویت REST هیچ کنترل Bruteforce ای نداشتند.
 *
 * طراحی:
 *  - دو پنجرهٔ مستقل: بر اساس IP و بر اساس نامِ کاربریِ هدف. اولی حملهٔ
 *    توزیع‌نشده را می‌گیرد، دومی Credential Stuffing روی یک حساب را از
 *    چند IP کند می‌کند.
 *  - فقط تلاش‌های **ناموفق** شمرده می‌شوند، پس ورود عادی هرگز قفل نمی‌شود.
 *  - وقتی سقف پر شود، `authenticate` یک WP_Error برمی‌گرداند و WordPress
 *    هرگز به مقایسهٔ رمز نمی‌رسد.
 *  - پیام خطا عمداً یکسان و بدون افشای وجود/عدم وجود حساب است
 *    (مقاومت در برابر Enumeration).
 *  - IP از ClientIp می‌آید، پس `X-Forwarded-For` فقط با اعلام صریح
 *    Proxy معتمد خوانده می‌شود.
 *
 * ⚠️ عمداً Scope-Independent: هیچ ارجاعی به Organization/Clinic ندارد.
 * سقف‌های per-Clinic به تصمیم باز Q6 (دامنهٔ جدول cpms_rate_limits)
 * وابسته‌اند و در Phase 1B/2 تعیین می‌شوند.
 */
final class LoginRateLimiter
{
    /** حداکثر تلاش ناموفق از یک IP در پنجره. */
    public const MAX_PER_IP = 20;

    /** حداکثر تلاش ناموفق روی یک نام کاربری در پنجره. */
    public const MAX_PER_USERNAME = 10;

    /** طول پنجره (ثانیه). */
    public const WINDOW_SEC = 900;

    public function __construct(
        private readonly RateLimiter $rate,
        private readonly ?OpLogger $log = null
    ) {
    }

    public function register(): void
    {
        // اولویت 5: پیش از wp_authenticate_username_password (20).
        add_filter('authenticate', [$this, 'blockWhenThrottled'], 5, 3);
        add_action('wp_login_failed', [$this, 'recordFailure'], 10, 1);
    }

    /**
     * پیش از هر بررسی رمز، وضعیت محدودیت را کنترل می‌کند.
     *
     * @param \WP_User|\WP_Error|null $user
     *
     * @return \WP_User|\WP_Error|null
     */
    public function blockWhenThrottled($user, string $username = '', string $password = '')
    {
        // درخواست بدون اعتبارنامه (مثلاً بررسی کوکی) را دست نمی‌زنیم.
        if ($username === '' && $password === '') {
            return $user;
        }

        $ip = ClientIp::resolve();
        $blocked = false;

        if ($ip !== null && $this->isOver('login-ip:' . $ip, self::MAX_PER_IP)) {
            $blocked = true;
        }
        if (!$blocked && $username !== '' && $this->isOver('login-user:' . $this->userKey($username), self::MAX_PER_USERNAME)) {
            $blocked = true;
        }

        if (!$blocked) {
            return $user;
        }

        $this->record('LOGIN_THROTTLED', $username, $ip);

        return new \WP_Error(
            'CLINIC_RATE_LIMITED',
            'تلاش‌های ناموفق زیاد بوده است — کمی بعد دوباره تلاش کنید',
            ['status' => 429]
        );
    }

    /**
     * فقط شکست‌ها شمرده می‌شوند.
     */
    public function recordFailure(string $username): void
    {
        $ip = ClientIp::resolve();
        if ($ip !== null) {
            $this->rate->hit('login-ip:' . $ip, self::MAX_PER_IP, self::WINDOW_SEC);
        }
        if ($username !== '') {
            $this->rate->hit('login-user:' . $this->userKey($username), self::MAX_PER_USERNAME, self::WINDOW_SEC);
        }

        $this->record('LOGIN_FAILED', $username, $ip);
    }

    /**
     * آیا شمارندهٔ فعلی از سقف عبور کرده است؟
     *
     * از `hit()` با سقف بسیار بزرگ استفاده نمی‌کنیم تا خودِ بررسی،
     * شمارنده را بالا نبرد؛ `peek()` فقط می‌خواند.
     */
    private function isOver(string $key, int $max): bool
    {
        return $this->rate->peek($key, self::WINDOW_SEC) >= $max;
    }

    /**
     * نام کاربری هرگز خام وارد کلید نمی‌شود: طول کلید محدود است و نام
     * کاربری نباید در جدول Rate Limit ذخیره شود.
     */
    private function userKey(string $username): string
    {
        return substr(hash('sha256', strtolower(trim($username))), 0, 32);
    }

    /**
     * عمداً از AuditLogger استفاده نمی‌شود.
     *
     * امضای `AuditLogger::log()` پارامتر `$clinicId` با مقدار پیش‌فرض 1
     * دارد و ورود، رویدادی پیش از هر Scope کلینیکی است؛ ثبت آن روی
     * `clinic_id = 1` یعنی جاسازی همان فرضی که AD-13 ممنوع کرده است.
     * تا زمانی که Phase 2 مدل Organization را بسازد، این رویدادها فقط در
     * لاگ عملیاتی می‌روند (ثبت‌شده در Deferred Register فاز 1B).
     */
    private function record(string $action, string $username, ?string $ip): void
    {
        $this->log?->warning($action, [
            // نام کاربری خام لاگ نمی‌شود (Enumeration/PII)
            'username_hash' => $this->userKey($username),
            'ip' => $ip,
        ]);
    }
}
