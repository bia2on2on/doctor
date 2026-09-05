# ساخت SMS Provider Adapter — Developer Contract

نسخه 1.0 | 2026-09-05 | مرتبط: ADR-0025

هدف: افزودن Provider جدید (مثلاً کاسپین/پارس‌پیام/کدپیام) **بدون تغییر Clinic Core** — فقط یک کلاس Adapter + ثبت در Registry.

## 1. Contract (Interface)

```php
namespace ClinicCore\Infrastructure\Sms;

interface SmsProviderInterface
{
    /** شناسه یکتای Provider — 'kaspian', 'parspeyamak', ... */
    public function id(): string;

    /** نام نمایشی فارسی برای UI */
    public function label(): string;

    /**
     * قابلیت‌ها — UI بر اساس این خروجی ساخته می‌شود:
     * [
     *   'text' => bool,            // sendText
     *   'template' => bool,        // Template/Pattern/Verify
     *   'otp_template' => bool,    // OTP به‌صورت Template پیش‌ثبت‌شده در پنل
     *   'delivery_status' => bool, // Sync وضعیت ارسال/تحویل
     *   'sender_list' => bool,     // دریافت خطوط فعال
     *   'balance' => bool,         // نمایش اعتبار
     *   'bulk' => bool,            // ارسال انبوه
     * ]
     */
    public function capabilities(): array;

    /** روش‌های Authentication پشتیبانی‌شده: 'api_key' | 'bearer' | 'username_password' (مجموعه) */
    public function authMethods(): array;

    /**
     * فیلدهای Authentication — UI فقط فیلدهای روش انتخابی را نشان می‌دهد:
     * [
     *   'api_key' => ['label' => 'API Key', 'secret' => true, 'required' => true],
     *   'username' => ['label' => 'نام کاربری', 'secret' => false, 'required' => false],
     * ]
     */
    public function authFields(): array;

    /**
     * تست اتصال (Auth/Reachability/Account Status).
     * @return array{ok: bool, message: string, technical?: string}
     *  - message: Human-Friendly فارسی (در UI نمایش داده می‌شود)
     *  - technical: در Op Log ثبت می‌شود (بدون Secret)
     */
    public function testConnection(array $creds): array;

    /**
     * ارسال متن عادی.
     * @param array<string,string> $creds مقادیر plain (توسط SmsService از Vault decrypt شده)
     * @param array $opts ['sender' => string|null, 'timeout_sec' => int]
     * @return array{provider_ref: string|null}
     * @throws SmsSendException
     */
    public function sendText(array $creds, string $mobile, string $message, array $opts = []): array;

    /**
     * ارسال Template. $variables = متغیرهای داخلی ({{otp_code}} و…) —
     * **تبدیل نام‌ها به فرمت Provider وظیفه همین Adapter است.**
     * @return array{provider_ref: string|null}
     * @throws SmsSendException
     */
    public function sendTemplate(array $creds, string $mobile, string $templateId, array $variables, array $opts = []): array;

    /**
     * تبدیل Status مخصوص Provider به Status داخلی:
     * 'SENT' | 'DELIVERED' | 'FAILED' | 'QUEUED'
     */
    public function mapStatus(string $providerStatus): string;

    /** اختیاری: دریافت خطوط فعال (اگر capability فعال باشد) @return list<array{sender: string, label: string}>|null */
    public function fetchSenders(array $creds): ?array;

    /** اختیاری: اعتبار حساب @return array{balance: int|string, currency: string}|null */
    public function fetchBalance(array $creds): ?array;
}
```

## 2. قواعد خطای Adapter (سازگار با Retry Policy — الزام §20)

هر شکست با `SmsSendException` انداخده می‌شود:

```php
throw new SmsSendException(
    'API key نامعتبر است',     // پیام Human-Friendly
    retryable: false,           // false → بدون Blind Retry
    errorCode: 'SMS_AUTH_INVALID'
);
```

| خطا | retryable | نمونه errorCode |
|---|---|---|
| Timeout / Unreachable | **true** | `CLINIC_SMS_PROVIDER_UNREACHABLE` |
| HTTP 5xx / 429 | **true** | `CLINIC_SMS_PROVIDER_ERROR` / `CLINIC_SMS_RATE_LIMITED` |
| Invalid Mobile | **false** | `CLINIC_SMS_INVALID_MOBILE` |
| Invalid Template / Pattern | **false** | `CLINIC_SMS_TEMPLATE_INVALID` |
| Invalid Credentials / Balance Zero | **false** | `SMS_AUTH_INVALID` / `CLINIC_SMS_NO_CREDIT` |

## 3. Variable Mapping (الزام §13)

متغیرهای **داخلی** (یکسان برای همه Providerها):

`otp_code`, `patient_name`, `doctor_name`, `appointment_date`, `appointment_time`, `clinic_name`

هر Adapter خود تصمیم می‌گیرد این‌ها را به چه پارامترهایی تبدیل کند:

```php
// مثال — Provider A (نام پارامترها: name / time)
$providerVars = [
    'name' => $variables['patient_name'] ?? '',
    'time' => $variables['appointment_time'] ?? '',
];

// مثال — Provider B (توکنی: token / token2)
$providerVars = [
    'token'  => $variables['otp_code'] ?? '',
    'token2' => $variables['patient_name'] ?? '',
];
```

**این Mapping هرگز وارد Business Logic نمی‌شود** — SmsService فقط متغیرهای داخلی را Validate و می‌فرستد.

## 4. ثبت Provider

**داخل پلاگین** (ادیت `App::providers()`):
```php
$registry->register(new KaspianProvider());
```

**به‌صورت پلاگین جدا / Drop-in** (توصیه‌شده):
```php
add_action('cpms_sms_provider', function (SmsProviderRegistry $registry): void {
    $registry->register(new KaspianProvider());
});
```

## 5. Security Rules (اجباری)

1. **هیچ Secret در Code/Commit/Log/HTML/REST نمی‌رود.** Adapter `$creds` را فقط برای Request می‌گیرد (SmsService decrypt می‌کند).
2. اگر Endpoint دلخواه دارید: **SSRF Guard** (`SsrfGuard::assertSafe`) را صدا بزنید — IP خصوصی/Loopback مسدودند.
3. **Arbitrary PHP / eval / Execution ممنوع** — فقط String Substitution برای Mapping.
4. Timeout اجباری (از `$opts['timeout_sec']`).
5. `testConnection` نباید پیامک واقعی ارسال کند.
6. هر Status/خطای Provider به Mapping استاندارد §2/§3 این سند تبدیل شود.

## 6. Test Checklist (اجرا قبل از تأیید Provider)

- [ ] Unit: Variable Mapping برای هر Template (خصوصاً OTP)
- [ ] Unit: Error Mapping (هر کد خطای پنل → retryable/permanent درست)
- [ ] Integration: testConnection (موفق + Auth غلط)
- [ ] Integration: sendText → `provider_msg_id` ذخیره می‌شود
- [ ] Integration: sendTemplate (OTP) → ارسال واقعی به شماره تست
- [ ] Integration: Retryable Failure (مثلاً 500 مصنوعی) → Status RETRYING → Retry
- [ ] Integration: Permanent Failure (Invalid Mobile) → FAILED بدون Retry
- [ ] بدون Secret در Log/Audit/Diagnostic
