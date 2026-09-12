<?php

declare(strict_types=1);

namespace ClinicCore\Application\Notifications;

use ClinicCore\Domain\Sms\RetryClassifier;
use ClinicCore\Domain\Sms\SmsEvents;
use ClinicCore\Domain\Sms\SmsMessageStatus;
use ClinicCore\Domain\Sms\SmsTemplateException;
use ClinicCore\Domain\Sms\SmsTemplateRenderer;
use ClinicCore\Domain\Validators\MobileValidator;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Queue\JobQueue;
use ClinicCore\Infrastructure\Sms\CredentialVault;
use ClinicCore\Infrastructure\Sms\SmsProviderInterface;
use ClinicCore\Infrastructure\Sms\SmsProviderRegistry;
use ClinicCore\Infrastructure\Sms\SmsSendException;
use ClinicCore\Infrastructure\Sms\SsrfGuard;
use ClinicCore\Settings\Settings;
use ClinicCore\Settings\SettingsFactory;
use DomainException;

/**
 * سرویس پیامک — لایه میانی بین Business Logic و Providerها (ADR-0025).
 *
 * Business Logic فقط sendEvent() را صدا می‌زند:
 *   Template Resolution + Variable Validation + Record + Dedupe + Queue + Retry + Status.
 *
 * هیچ Provider خاصی اینجا نام‌برده نمی‌شود (Provider-Agnostic).
 *
 * ────────────────────────────────────────────────────────────────────────────
 * Phase 2 — تفکیک پیکربندیِ per-Clinic (§5-D).
 *
 * پیش از این، این سرویس **یک** نمونهٔ `Settings` را در لحظهٔ ساخت می‌گرفت و
 * همان را برای هر پیامی به کار می‌برد. نتیجه: پیامِ Clinic B با sender /
 * timeout / **credentialِ sealedِ Clinic A** به Provider می‌رفت، و چون کلیدِ
 * Vault سطحِ نصب است رمزگشاییِ credentialِ Clinicِ اشتباه **بی‌صدا موفق
 * می‌شود** (§5-D-2) — یعنی رمزنگاری مرزِ tenant نیست.
 *
 * اکنون دو مسیرِ کاملاً جدا وجود دارد و هیچ‌کدام به state سطحِ process تکیه
 * نمی‌کند (§5-D-1):
 *   ۱) `currentSettings()` — Clinicِ فعالِ عملیاتِ جاری (مسیر admin/REST/inline)؛
 *   ۲) `settingsForClinic()` — Clinicِ **مالکِ durable** عملیات:
 *      `dispatchMessage()` از `cpms_sms_messages.clinic_id` و `sendEvent()` از
 *      `clinic_id` صریحِ caller.
 *
 * به همین دلیل این سرویس **scope-neutral** است: singletonِ سطح process بودنش
 * دیگر Clinicِ bootstrap را میخ نمی‌کند و `A → B → A` هر بار پیکربندیِ خودش
 * را می‌گیرد (RT-6).
 * ────────────────────────────────────────────────────────────────────────────
 */
final class SmsService
{
    /** رویداد داخلی برای پیامک آزمایشی (خارج از رویدادهای بیزنس). */
    public const EVENT_TEST = 'test';

    private const UPDATE_COLUMNS = ['status', 'attempts', 'failure_code', 'provider_msg_id', 'provider'];

    /**
     * @param \Closure(): int $currentClinicResolver Clinicِ فعالِ عملیاتِ جاری
     *        (از scope درخواست). به‌صورت Closure تزریق می‌شود تا این سرویس به
     *        bootstrap گره نخورد و ساختنش به هیچ Clinic‌ای نیاز نداشته باشد —
     *        یعنی `App::smsService()` دیگر مرزِ tick را با `CLINIC_SCOPE_REQUIRED`
     *        نمی‌اندازد (RT-4).
     */
    public function __construct(
        private readonly CpmsDb $db,
        private readonly SettingsFactory $settingsFactory,
        private readonly SmsProviderRegistry $providers,
        private readonly CredentialVault $vault,
        private readonly AuditLogger $audit,
        private readonly OpLogger $op,
        private readonly JobQueue $jobs,
        private readonly \Closure $currentClinicResolver
    ) {
    }

    // =========================================================
    // Resolution پیکربندیِ per-Clinic (§5-D)
    // =========================================================

    /**
     * پیکربندیِ Clinicِ **فعالِ عملیاتِ جاری** — مسیر admin/REST/inline.
     *
     * در هر فراخوانی حل می‌شود (نه در لحظهٔ ساخت)، پس تغییرِ scope در همان
     * فرآیند فوراً اثر می‌کند و state گامِ قبلی نشت نمی‌کند (RT-6). کشِ
     * نمونه‌ها در `SettingsFactory` و کلیدش `clinicId` است (§5-D-2).
     */
    private function currentSettings(): Settings
    {
        return $this->settingsFactory->forClinic((int) ($this->currentClinicResolver)());
    }

    /**
     * پیکربندیِ یک Clinic **صریحاً مالکِ عملیات** — مسیرِ صف/Job.
     *
     * Clinic از منبعِ durable/authoritative می‌آید (مالکیت ردیف پیام یا
     * `clinic_id` صریحِ caller)، **نه** از کاربرِ جاری، نه از REST context، نه
     * از Job قبلی، و نه از نمونهٔ `Settings` متعلق به Clinic دیگر (§5-D-1).
     * شناسهٔ نامعتبر ⇒ Fail-Closed (بدون Clinic پیش‌فرض/حدسی).
     *
     * @throws \ClinicCore\Application\Scope\ScopeRequiredException
     */
    private function settingsForClinic(int $clinicId): Settings
    {
        return $this->settingsFactory->forClinic($clinicId);
    }

    /**
     * بستنِ scope به Clinicِ مالک، فقط برای مدتِ همان عملیات.
     *
     * چرا لازم است: هر وابستگیِ پایین‌دستی که خودش scope را حل می‌کند — از جمله
     * `GenericApiSmsProvider` که `sms.generic` را به‌صورت lazy و per-Clinic
     * می‌خواند — باید همان Clinicِ مالکِ پیام را ببیند، نه Clinicِ bootstrap.
     * الگو، همان الگوی کاریِ موجودِ `ExportService::bindJobClinic()` است.
     *
     * @return \Closure(): void بازگردانیِ scope قبلی (همیشه در `finally`)
     */
    private function bindClinicScope(int $clinicId): \Closure
    {
        $previous = ScopeContext::tryGet();
        ScopeContext::set(ClinicScope::forClinic($clinicId));

        return static function () use ($previous): void {
            if ($previous instanceof ClinicScope) {
                ScopeContext::set($previous);
            } else {
                ScopeContext::clear();
            }
        };
    }


    // =========================================================
    // ارسال (دریافتی برای Business Logic)
    // =========================================================

    /**
     * ارسال یک رویداد SMS (الزام §8/§19).
     *
     * @param array<string, string> $vars        متغیرهای داخلی
     * @param string|null           $overrideText متن دلخواه (فقط test — بدون Template)
     *
     * @return array{message_id: int, status: string}
     *
     * @throws DomainException | SmsTemplateException
     */
    public function sendEvent(
        int $clinic_id,
        string $event,
        string $mobile,
        array $vars = [],
        ?string $contextType = null,
        ?int $contextId = null,
        bool $inline = false,
        int $priority = 5,
        ?string $overrideText = null
    ): array {
        $isTest = $event === self::EVENT_TEST;
        $info = $isTest ? null : SmsEvents::info($event);
        if (!$isTest && $info === null) {
            throw new DomainException('CLINIC_SMS_EVENT_UNKNOWN: ' . $event);
        }
        $normalized = MobileValidator::normalize($mobile);
        if ($normalized === null) {
            throw new DomainException('CLINIC_MOBILE_INVALID');
        }

        // Phase 2 (§5-D): پیکربندی برای **همان** Clinicِ صریحِ caller حل می‌شود —
        // نه برای Clinicِ process. مرزِ اعتماد تغییر نکرده: `clinic_id` همچنان
        // از callerِ داخلیِ موردِ اعتماد می‌آید و قواعدِ scopeِ سمتِ سرور
        // (RestClinicContext) دست‌نخورده است؛ تفاوت این است که اکنون این شناسه
        // **کلیدِ حلِ پیکربندی** است، به‌جای اینکه برای پیکربندی نادیده گرفته
        // شود و پیکربندیِ Clinicِ دیگری به کار رود.
        $settings = $this->settingsForClinic($clinic_id);

        // Template/Text Resolution
        $provider = $this->activeProvider($settings);
        $templateId = '';
        $useTemplate = false;
        if ($isTest) {
            $text = (string) $overrideText;
        } else {
            $templateId = $this->templateConfig($settings, $event)['template_id'];
            $useTemplate = $templateId !== '' && $provider !== null && !empty($provider->capabilities()['template']);
            $text = $useTemplate
                ? '[Template Provider: ' . ($info['label'] ?? $event) . ']'
                : SmsTemplateRenderer::render($info['default_text'], $vars, $info['variables'], $info['required']);
        }
        if (mb_strlen($text) > 1000) {
            throw new SmsTemplateException('CLINIC_SMS_MESSAGE_INVALID', 'متن پیام بیش از حد مجاز است');
        }

        // ایمنی OTP (§8 Baseline: «OTP خام ذخیره نشود»):
        //  - متن ذخیره‌شده Mask (کد به‌جای مقدار: ***)
        //  - متغیرها ذخیره نمی‌شوند (کد فقط در لحظه Dispatch Live است)
        $isOtp = $event === SmsEvents::OTP;
        if ($isOtp && !$useTemplate) {
            $text = str_replace((string) ($vars['otp_code'] ?? ''), '***', $text);
        }
        $storedVars = ($isOtp || $isTest) ? null : (string) json_encode($vars, JSON_UNESCAPED_UNICODE);

        // Dedupe (الزام §20): رویدادهای وابسته به Context — بدون ارسال تکراری
        $dedupeKey = null;
        if ($contextType !== null && $contextId !== null) {
            // C6‑F: هویت Clinic داخل dedupe_key — وگرنه uq_dedupe (UNIQUE سراسری روی
            // dedupe_key) یک رویداد/Context/روزِ یکسان را **بین** دو Clinic سرکوب می‌کند.
            $dedupeKey = hash('sha256', $clinic_id . '|' . $event . '|' . $contextType . '|' . $contextId . '|' . gmdate('Y-m-d'));
            $existing = $this->db->fetchRow(
                'SELECT id, status FROM ' . $this->db->table('cpms_sms_messages') . ' WHERE dedupe_key = %s AND clinic_id = %d LIMIT 1',
                [$dedupeKey, $clinic_id]
            );
            if ($existing !== null) {
                $status = (string) $existing['status'];
                if (SmsMessageStatus::isInFlight($status)
                    || in_array($status, [SmsMessageStatus::SENT, SmsMessageStatus::DELIVERED], true)) {
                    return ['message_id' => (int) $existing['id'], 'status' => $status];
                }
                // رکورد قبلی FAILED است → Re-send: نسل جدید (جلوگیری از برخورد UNIQUE)
                $dedupeKey = $dedupeKey . '-' . (int) $existing['id'];
            }
        }

        $advanced = (array) $settings->get('sms.advanced', []);
        $maxAttempts = (int) ($advanced['retry_count'] ?? 3);

        $this->db->insert('cpms_sms_messages', [
            'clinic_id' => $clinic_id,
            'event' => $event,
            'recipient' => $normalized,
            'message' => $text,
            'vars_json' => $storedVars,
            'provider' => $provider?->id(),
            'template_id' => $useTemplate ? $templateId : null,
            'status' => $inline ? SmsMessageStatus::SENDING : SmsMessageStatus::QUEUED,
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'dedupe_key' => $dedupeKey,
            'context_type' => $contextType,
            'context_id' => $contextId,
            'created_at' => $this->db->nowUtcSql(),
        ]);
        $messageId = $this->db->wpdb_last_insert_id();

        // OTP: همیشه Inline + بدون Queue-Retry (کد ذخیره نشده — Retry = ارسال کد جدید توسط کاربر)
        if ($inline || $isOtp) {
            $this->dispatchMessage($messageId, $isOtp ? $vars : null);
        } else {
            $this->jobs->enqueue('sms.send', ['message_id' => $messageId], priority: $priority, maxAttempts: $maxAttempts);
        }

        $row = $this->fetchMessage($messageId);

        return [
            'message_id' => $messageId,
            'status' => (string) ($row['status'] ?? ($inline ? SmsMessageStatus::FAILED : SmsMessageStatus::QUEUED)),
        ];
    }

    /**
     * تلاش برای ارسال یک Message (idempotent — برای Job + Fast-path).
     * شکست Retryable با Attempts باقی‌مانده → RETRYING (Job Queue تکرار می‌کند).
     *
     * @param array<string, string>|null $liveVars متغیرهای زنده (OTP — کد هرگز ذخیره نمی‌شود)
     */
    public function dispatchMessage(int $messageId, ?array $liveVars = null): void
    {
        $row = $this->fetchMessage($messageId);
        if ($row === [] || SmsMessageStatus::isTerminal((string) $row['status'])) {
            return; // Idempotency — اجرای تکراری Job بی‌اثر است
        }

        $maxAttempts = (int) ($row['max_attempts'] ?? 3);
        $attempts = (int) $row['attempts'] + 1;
        if ($attempts > $maxAttempts) {
            $this->updateMessage($messageId, ['status' => SmsMessageStatus::FAILED, 'attempts' => $attempts, 'failure_code' => 'CLINIC_SMS_MAX_ATTEMPTS']);

            return;
        }

        // Phase 2 (RT-3 / §5-D-1..D-3) — مهم‌ترین اصلاحِ امنیتیِ این slice:
        // Clinicِ مالکِ پیام از **خودِ ردیف** خوانده می‌شود (منبعِ durable و
        // authoritative؛ همان `clinic_id` که در `sendEvent()` نوشته شده) و همهٔ
        // اجزای حساسِ tenant — provider، template، sender، advanced و
        // **credentialِ sealedِ `sms.auth`** — از پیکربندیِ **همان** Clinic حل
        // می‌شوند. هیچ‌کدام از اینها دیگر به کاربرِ جاری، REST context، Job
        // قبلی، یا نمونهٔ `Settings` متعلق به Clinicِ دیگر وابسته نیست.
        // شناسهٔ نامعتبر ⇒ Fail-Closed، بدون fallback به Clinic دیگر.
        $settings = $this->settingsForClinic((int) ($row['clinic_id'] ?? 0));
        $restoreScope = $this->bindClinicScope($settings->clinicId());

        $provider = $this->providers->get((string) ($row['provider'] ?? 'log'));
        $creds = $this->plaintextCredentials($settings);
        $opts = [
            'sender' => (string) $settings->get('sms.sender', ''),
            'timeout_sec' => (int) (($settings->get('sms.advanced', [])['timeout_sec'] ?? 5)),
        ];

        $this->updateMessage($messageId, ['status' => SmsMessageStatus::SENDING, 'attempts' => $attempts]);

        try {
            if ($provider === null) {
                throw new SmsSendException('Provider پیامک تنظیم نشده است', false, 'CLINIC_SMS_NOT_CONFIGURED');
            }

            $variables = $liveVars ?? $this->templateVariables($row['vars_json']);
            if ($row['template_id'] !== null && !empty($provider->capabilities()['template'])) {
                $result = $provider->sendTemplate(
                    $creds,
                    (string) $row['recipient'],
                    (string) $row['template_id'],
                    $variables,
                    $opts
                );
            } else {
                $result = $provider->sendText($creds, (string) $row['recipient'], (string) $row['message'], $opts);
            }

            $this->updateMessage($messageId, [
                'status' => SmsMessageStatus::SENT,
                'provider_msg_id' => $result['provider_ref'] ?? null,
                'failure_code' => null,
            ]);
            $this->op->info('SMS_SENT', [
                'message_id' => $messageId,
                'event' => $row['event'],
                'ref' => $result['provider_ref'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $verdict = RetryClassifier::classify($e);
            $code = method_exists($e, 'apiCode') ? (string) $e->apiCode() : 'CLINIC_SMS_PROVIDER_ERROR';

            // OTP بدون متغیرهای ذخیره‌شده Retry نمی‌شود (کد خام ذخیره نیست — §8)
            if ($verdict === RetryClassifier::RETRYABLE && (string) $row['event'] === SmsEvents::OTP && $liveVars === null) {
                $code = 'CLINIC_SMS_OTP_NO_RETRY';
                $verdict = RetryClassifier::PERMANENT;
            }

            if ($verdict === RetryClassifier::RETRYABLE) {
                $this->updateMessage($messageId, ['status' => SmsMessageStatus::RETRYING, 'failure_code' => $code]);
                $this->op->warning('SMS_RETRYING', ['message_id' => $messageId, 'code' => $code, 'error' => $e->getMessage()]);
            } else {
                $this->updateMessage($messageId, ['status' => SmsMessageStatus::FAILED, 'failure_code' => $code]);
                $this->op->error('SMS_FAILED', ['message_id' => $messageId, 'code' => $code, 'error' => $e->getMessage()]);
            }
        } finally {
            // هیچ scope‌ای نباید از این عملیات به عملیاتِ بعدیِ همان فرآیند نشت
            // کند (§5-D-1) — چه موفق، چه شکست، چه استثنا.
            $restoreScope();
        }
    }

    // =========================================================
    // تنظیمات / وضعیت / تست‌ها (REST Admin)
    // =========================================================

    /**
     * وضعیت Configuration (الزام §28) — بدون هیچ Secret.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $settings = $this->currentSettings();
        $providerId = (string) $settings->get('sms.provider', '') ?: 'log';
        $auth = $this->storedAuth($settings);
        $lastTest = (array) $settings->get('sms.last_test', []);
        $provider = $this->providers->get($providerId);
        $complete = $this->credentialsComplete($provider, (string) ($auth['method'] ?? ''), array_keys($auth['fields']));

        $state = match (true) {
            $provider === null, !$complete => 'NOT_CONFIGURED',
            ($lastTest['status'] ?? '') === 'failed' => 'ERROR',
            ($lastTest['status'] ?? '') === 'ok' => 'VERIFIED',
            default => 'CONFIGURED',
        };

        $masked = [];
        foreach ((array) ($auth['fields'] ?? []) as $field => $sealed) {
            if (is_array($sealed) && isset($sealed['last4'])) {
                $masked[$field] = '••••••••' . (string) $sealed['last4'];
            }
        }

        return [
            'state' => $state,
            'provider' => $providerId,
            'auth_method' => (string) ($auth['method'] ?? ''),
            'credentials' => $masked,
            'sender' => (string) $settings->get('sms.sender', ''),
            'last_test' => $lastTest,
            'advanced' => (array) $settings->get('sms.advanced', []),
        ];
    }

    /**
     * ذخیره تنظیمات (الزام §1/§27).
     * Credential خالی = حفظ قبلی؛ '__CLEAR__' = حذف صریح؛ مقدار جدید = replace.
     *
     * @param array<string, mixed> $in
     * @return array{ok: bool, state: string}
     */
    public function saveSettings(array $in, int $userId): array
    {
        $settings = $this->currentSettings();
        $prevProvider = (string) $settings->get('sms.provider', '');

        $providerId = trim((string) ($in['provider'] ?? ''));
        $provider = $providerId === '' ? $this->providers->get('log') : $this->providers->get($providerId);
        if ($provider === null) {
            throw new SmsTemplateException('CLINIC_SMS_PROVIDER_UNKNOWN', 'Provider انتخابی یافت نشد');
        }

        $current = $this->storedAuth($settings);
        $method = trim((string) ($in['auth_method'] ?? ($current['method'] ?? '')));
        if ($provider->authMethods() !== [] && $method === '') {
            $method = (string) $provider->authMethods()[0];
        }
        if ($method !== '' && $provider->authMethods() !== [] && !in_array($method, $provider->authMethods(), true)) {
            throw new SmsTemplateException('CLINIC_SMS_AUTH_METHOD_INVALID', 'روش اتصال انتخابی برای این Provider پشتیبانی نمی‌شود');
        }

        // Credentials (الزام §4/§27)
        $fields = is_array($in['credentials'] ?? null) ? $in['credentials'] : [];
        $newFields = $current['fields'];
        $changedCreds = false;
        foreach ($provider->authFields() as $field => $spec) {
            $input = (string) ($fields[$field] ?? '');
            if ($input === '__CLEAR__') {
                unset($newFields[$field]);
                $changedCreds = true;
                continue;
            }
            if ($input !== '') {
                $newFields[$field] = [
                    'sealed' => $this->vault->encrypt($input),
                    'last4' => CredentialVault::last4($input),
                ];
                $changedCreds = true;
            }
        }
        // فیلدهای مربوط به روش قبلی (دیگر لازم نیست) حذف می‌شوند
        $needed = $this->fieldsForMethod($provider, $method);
        foreach (array_keys($newFields) as $f) {
            if (!in_array($f, $needed, true)) {
                unset($newFields[$f]);
                $changedCreds = true;
            }
        }

        if ($providerId !== $prevProvider || $method !== (string) ($current['method'] ?? '')) {
            $settings->set('sms.last_test', [], $userId);
        }

        $settings->set('sms.provider', $providerId, $userId);
        $settings->set('sms.auth', ['method' => $method, 'fields' => $newFields, 'updated_at' => gmdate('c')], $userId);
        $settings->set('sms.sender', $this->sanitizeSender((string) ($in['sender'] ?? '')), $userId);

        if (is_array($in['advanced'] ?? null)) {
            $advanced = (array) $settings->get('sms.advanced', []);
            $advanced['timeout_sec'] = max(1, min(30, (int) ($in['advanced']['timeout_sec'] ?? $advanced['timeout_sec'] ?? 5)));
            $advanced['retry_count'] = max(1, min(10, (int) ($in['advanced']['retry_count'] ?? $advanced['retry_count'] ?? 3)));
            $settings->set('sms.advanced', $advanced, $userId);
        }

        if (is_array($in['generic'] ?? null)) {
            $this->saveGenericConfig($in['generic'], $userId);
        }

        if ($providerId !== $prevProvider) {
            $this->auditSms('SMS_PROVIDER_CHANGED', $userId, ['provider' => $providerId]);
        }
        if ($changedCreds) {
            $this->auditSms('SMS_CREDENTIAL_UPDATED', $userId, ['method' => $method, 'fields' => array_keys($newFields)]);
        }

        return ['ok' => true, 'state' => (string) $this->status()['state']];
    }

    /**
     * تست اتصال (الزام §5).
     *
     * @param array<string, mixed> $in
     * @return array{ok: bool, message: string}
     */
    public function testConnection(array $in, int $userId): array
    {
        $settings = $this->currentSettings();
        $providerId = trim((string) ($in['provider'] ?? $settings->get('sms.provider', '')));
        $provider = $providerId === '' ? $this->providers->get('log') : $this->providers->get($providerId);
        if ($provider === null) {
            return ['ok' => false, 'message' => '✗ Provider یافت نشد.'];
        }

        $creds = $this->mergedCredentials($provider, (array) ($in['credentials'] ?? []), $settings);
        $result = $provider->testConnection($creds);

        $settings->set('sms.last_test', [
            'status' => $result['ok'] ? 'ok' : 'failed',
            'at' => time(),
            'provider' => $providerId,
            'message' => (string) $result['message'],
        ], $userId);
        $this->auditSms('SMS_CONNECTION_TESTED', $userId, ['provider' => $providerId, 'ok' => (bool) $result['ok']]);

        return ['ok' => (bool) $result['ok'], 'message' => (string) $result['message']];
    }

    /**
     * ارسال پیامک آزمایشی (الزام §6).
     *
     * @return array{status: string, provider_msg_id: string|null, message: string}
     *
     * @throws SmsTemplateException
     */
    public function testSend(string $mobile, string $message, int $userId): array
    {
        $settings = $this->currentSettings();
        $normalized = MobileValidator::normalize($mobile);
        if ($normalized === null) {
            throw new SmsTemplateException('CLINIC_MOBILE_INVALID', 'شماره موبایل معتبر نیست');
        }
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 350) {
            throw new SmsTemplateException('CLINIC_SMS_MESSAGE_INVALID', 'متن پیام باید ۱ تا ۳۵۰ نویسه باشد');
        }

        $result = $this->sendEvent($settings->clinicId(), self::EVENT_TEST, $normalized, [], null, null, inline: true, priority: 5, overrideText: $message);
        $row = $this->fetchMessage((int) $result['message_id']);

        $this->auditSms('SMS_TEST_SENT', $userId, ['event' => self::EVENT_TEST, 'status' => (string) $result['status']]);

        return [
            'status' => (string) $result['status'],
            'provider_msg_id' => $row['provider_msg_id'] ?? null,
            'message' => match ((string) $result['status']) {
                SmsMessageStatus::SENT => '✓ پیامک آزمایشی ارسال شد.',
                SmsMessageStatus::RETRYING => '⏳ ارسال در صف تکرار است (خطای موقت).',
                default => '✗ ارسال ناموفق بود — جزئیات در Log.',
            },
        ];
    }

    /**
     * Preview متن یک الگو (بدون ارسال) — برای UI (الزام §14).
     *
     * @param array<string, string> $vars
     * @return array{event: string, preview: string, variables: list<string>, uses_provider_template: bool}
     *
     * @throws SmsTemplateException
     */
    public function preview(string $event, array $vars): array
    {
        $settings = $this->currentSettings();
        $info = SmsEvents::info($event);
        if ($info === null) {
            throw new SmsTemplateException('CLINIC_SMS_EVENT_UNKNOWN', 'رویداد شناخته‌شده نیست');
        }
        $templateId = $this->templateConfig($settings, $event)['template_id'];
        $provider = $this->activeProvider($settings);
        $useTemplate = $templateId !== '' && $provider !== null && !empty($provider->capabilities()['template']);

        $preview = $useTemplate
            ? '[Template Provider: ' . $info['label'] . '] (متن در پنل Provider render می‌شود)'
            : SmsTemplateRenderer::render($info['default_text'], $vars, $info['variables'], $info['required']);

        return [
            'event' => $event,
            'preview' => $preview,
            'variables' => $info['variables'],
            'uses_provider_template' => $useTemplate,
        ];
    }

    /**
     * تست الگو (الزام §14) — Preview + ارسال واقعی به شماره تست.
     *
     * @param array<string, string> $vars
     * @return array{status: string, preview: string, provider_msg_id: string|null}
     *
     * @throws SmsTemplateException
     */
    public function testTemplate(string $event, string $mobile, array $vars, int $userId): array
    {
        $settings = $this->currentSettings();
        $preview = $this->preview($event, $vars);
        $normalized = MobileValidator::normalize($mobile);
        if ($normalized === null) {
            throw new SmsTemplateException('CLINIC_MOBILE_INVALID', 'شماره موبایل معتبر نیست');
        }

        $result = $this->sendEvent($settings->clinicId(), $event, $normalized, $vars, null, null, inline: true, priority: 6);
        $row = $this->fetchMessage((int) $result['message_id']);

        $this->auditSms('SMS_TEST_SENT', $userId, ['event' => $event, 'status' => (string) $result['status']]);

        return [
            'status' => (string) $result['status'],
            'preview' => (string) $preview['preview'],
            'provider_msg_id' => $row['provider_msg_id'] ?? null,
        ];
    }

    /**
     * لیست الگوها + وضعیت Templateها (الزام §11/§16).
     *
     * @return array<string, mixed>
     */
    public function templates(): array
    {
        $settings = $this->currentSettings();
        $stored = (array) $settings->get('sms.templates', []);
        $provider = $this->activeProvider($settings);
        $supportsTemplate = $provider !== null && !empty($provider->capabilities()['template']);

        $events = [];
        foreach (SmsEvents::all() as $id => $info) {
            $templateId = (string) ($stored[$id]['template_id'] ?? '');
            $events[$id] = [
                'label' => $info['label'],
                'variables' => $info['variables'],
                'required' => $info['required'],
                'variable_labels' => array_intersect_key(SmsEvents::VARIABLES, array_flip($info['variables'])),
                'default_text' => $info['default_text'],
                'template_id' => $templateId,
                'uses_provider_template' => $supportsTemplate && $templateId !== '',
                'validation' => $this->validateTemplate($settings, $templateId, $supportsTemplate),
            ];
        }

        return ['events' => $events, 'provider_supports_template' => $supportsTemplate];
    }

    /**
     * ذخیره Template یک رویداد (الزام §11/§15).
     *
     * @return array{ok: bool}
     *
     * @throws SmsTemplateException
     */
    public function saveTemplate(string $event, string $templateId, int $userId): array
    {
        $settings = $this->currentSettings();
        $info = SmsEvents::info($event);
        if ($info === null) {
            throw new SmsTemplateException('CLINIC_SMS_EVENT_UNKNOWN', 'رویداد شناخته‌شده نیست');
        }
        $templateId = trim($templateId);
        if (mb_strlen($templateId) > 80) {
            throw new SmsTemplateException('CLINIC_SMS_TEMPLATE_INVALID', 'Template ID بسیار طولانی است');
        }

        $provider = $this->activeProvider($settings);
        $supportsTemplate = $provider !== null && !empty($provider->capabilities()['template']);
        if ($templateId !== '' && !$supportsTemplate) {
            throw new SmsTemplateException('CLINIC_SMS_TEMPLATE_NOT_SUPPORTED', 'Provider فعلی از Template/Pattern پشتیبانی نمی‌کند');
        }

        $stored = (array) $settings->get('sms.templates', []);
        $stored[$event] = ['template_id' => $templateId, 'updated_at' => gmdate('c')];
        $settings->set('sms.templates', $stored, $userId);

        $this->auditSms('SMS_TEMPLATE_CHANGED', $userId, ['event' => $event, 'has_template' => $templateId !== '']);

        return ['ok' => true];
    }

    /**
     * Log عملیاتی (الزام §22) — موبایل Mask، بدون Secret/OTP خام، با Pagination.
     *
     * لاگ پیامک — C6‑F (Class A repair): لاگ **Clinic‑owned** است و predicate
     * tenant داخل خودِ SQL می‌نشیند (نه post‑filter در PHP پس از بارگذاری همهٔ
     * ردیف‌ها)؛ هم COUNT و هم SELECT با `clinic_id = %d` فیلتر می‌شوند.
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function logs(int $clinicId, ?string $status, int $page, int $perPage): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $table = $this->db->table('cpms_sms_messages');

        $where = ' WHERE clinic_id = %d';
        $params = [$clinicId];
        if ($status !== null && $status !== '' && SmsMessageStatus::isValid($status)) {
            $where .= ' AND status = %s';
            $params[] = $status;
        }

        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM {$table} {$where}", $params);
        $rows = $this->db->fetchAll(
            "SELECT id, event, recipient, message, provider, status, attempts, provider_msg_id, failure_code, created_at
             FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
            array_merge($params, [$perPage, ($page - 1) * $perPage])
        );

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id' => (int) $r['id'],
                'event' => (string) $r['event'],
                'recipient' => MobileValidator::mask((string) $r['recipient']),
                'message' => mb_substr((string) $r['message'], 0, 120),
                'provider' => (string) ($r['provider'] ?? ''),
                'status' => (string) $r['status'],
                'attempts' => (int) $r['attempts'],
                'provider_msg_id' => $r['provider_msg_id'],
                'failure_code' => $r['failure_code'],
                'created_at' => (string) $r['created_at'],
            ];
        }

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * اعتبار حساب (الزام §23) — Optional.
     *
     * @return array{balance: int|string, currency: string}|null
     */
    public function balance(): ?array
    {
        $settings = $this->currentSettings();
        $provider = $this->activeProvider($settings);
        if ($provider === null || empty($provider->capabilities()['balance'])) {
            return null;
        }

        return $provider->fetchBalance($this->plaintextCredentials($settings));
    }

    /**
     * خطوط فعال (الزام §7) — Optional.
     *
     * @return list<array{sender: string, label: string}>|null
     */
    public function senders(): ?array
    {
        $settings = $this->currentSettings();
        $provider = $this->activeProvider($settings);
        if ($provider === null || empty($provider->capabilities()['sender_list'])) {
            return null;
        }

        return $provider->fetchSenders($this->plaintextCredentials($settings));
    }

    // =========================================================
    // زیرساخت‌ها
    // =========================================================

    /**
     * Provider فعالِ **یک Clinic مشخص** — نه Provider فعالِ process.
     */
    private function activeProvider(Settings $settings): ?SmsProviderInterface
    {
        $id = (string) $settings->get('sms.provider', '');

        return $this->providers->get($id === '' ? 'log' : $id);
    }

    /**
     * @return array{method: string, fields: array<string, array{sealed: array<string, string>, last4: string}>}
     */
    private function storedAuth(Settings $settings): array
    {
        $raw = (array) $settings->get('sms.auth', []);
        $fields = [];
        foreach ((array) ($raw['fields'] ?? []) as $field => $sealed) {
            if (is_array($sealed) && isset($sealed['sealed']) && is_array($sealed['sealed'])) {
                $fields[(string) $field] = [
                    'sealed' => (array) $sealed['sealed'],
                    'last4' => (string) ($sealed['last4'] ?? ''),
                ];
            }
        }

        return ['method' => (string) ($raw['method'] ?? ''), 'fields' => $fields];
    }

    /**
     * Credentials به‌صورت plain — فقط در لحظه Call به Provider (هرگز در Log/Response/Audit).
     *
     * Phase 2 (§5-D-2): کلیدِ Vault **سطحِ نصب** است، پس رمزگشاییِ credentialِ
     * Clinicِ اشتباه بی‌صدا موفق می‌شود. سدِ ایزولاسیون، **همین پارامترِ
     * `$settings`** است: caller باید Clinicِ اعتبارسنجی‌شدهٔ همان عملیات را
     * داده باشد. هیچ مسیرِ رمزگشاییِ «پیش‌فرض» یا بدونِ Clinic وجود ندارد.
     *
     * @return array<string, string>
     */
    private function plaintextCredentials(Settings $settings): array
    {
        $stored = $this->storedAuth($settings);
        $out = [];
        foreach ($stored['fields'] as $field => $data) {
            $plain = $this->vault->decrypt($data['sealed']);
            if ($plain !== null) {
                $out[$field] = $plain;
            }
        }

        return $out;
    }

    /**
     * ترکیب Credentials ذخیره‌شده + مقادیر ورودی (تست‌ها) — ورودی خالی = حفظ ذخیره.
     *
     * @param array<string, mixed> $inputFields
     * @return array<string, string>
     */
    private function mergedCredentials(SmsProviderInterface $provider, array $inputFields, Settings $settings): array
    {
        $stored = $this->plaintextCredentials($settings);
        foreach ($provider->authFields() as $field => $spec) {
            $input = (string) ($inputFields[$field] ?? '');
            if ($input !== '') {
                $stored[$field] = $input;
            }
        }

        return $stored;
    }

    /**
     * @return list<string>
     */
    private function fieldsForMethod(SmsProviderInterface $provider, string $method): array
    {
        $all = array_keys($provider->authFields());
        if ($method === '' || $provider->authMethods() === []) {
            return [];
        }

        return match ($method) {
            'api_key' => array_values(array_intersect($all, ['api_key'])),
            'bearer' => array_values(array_intersect($all, ['bearer_token'])),
            'username_password' => array_values(array_intersect($all, ['username', 'password'])),
            default => [],
        };
    }

    private function credentialsComplete(?SmsProviderInterface $provider, string $method, array $storedFields): bool
    {
        if ($provider === null) {
            return false;
        }
        $needed = $this->fieldsForMethod($provider, $method);
        if ($needed === []) {
            return true; // بدون نیاز به اعتبار (log)
        }
        foreach ($needed as $field) {
            if (!in_array($field, $storedFields, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{template_id: string}
     */
    private function templateConfig(Settings $settings, string $event): array
    {
        $stored = (array) $settings->get('sms.templates', []);

        return ['template_id' => (string) ($stored[$event]['template_id'] ?? '')];
    }

    /**
     * اعتبارسنجی Template قبل از فعال شدن (الزام §15).
     *
     * @return array{ok: bool, message: string}
     */
    private function validateTemplate(Settings $settings, string $templateId, bool $providerSupportsTemplate): array
    {
        if ($templateId === '') {
            return ['ok' => true, 'message' => 'متن پیش‌فرض داخلی استفاده می‌شود.'];
        }
        if (!$providerSupportsTemplate) {
            return ['ok' => false, 'message' => 'Provider فعلی از Template/Pattern پشتیبانی نمی‌کند.'];
        }
        if ($this->activeProvider($settings) === null) {
            return ['ok' => false, 'message' => 'Provider تنظیم نشده است.'];
        }

        return ['ok' => true, 'message' => 'Template در پنل Provider مرجع است.'];
    }

    /**
     * @param array<string, mixed> $in
     *
     * @throws SmsTemplateException
     */
    private function saveGenericConfig(array $in, int $userId): void
    {
        $settings = $this->currentSettings();
        $current = (array) $settings->get('sms.generic', []);
        $next = $current;

        if (isset($in['endpoint'])) {
            $endpoint = trim((string) $in['endpoint']);
            if ($endpoint !== '') {
                SsrfGuard::assertSafe($endpoint);
            }
            $next['endpoint'] = $endpoint;
        }
        if (isset($in['http_method'])) {
            $m = strtoupper((string) $in['http_method']);
            if (!in_array($m, ['GET', 'POST'], true)) {
                throw new SmsTemplateException('CLINIC_SMS_MAPPING_INVALID', 'HTTP Method فقط GET یا POST مجاز است');
            }
            $next['http_method'] = $m;
        }
        if (isset($in['auth_header'])) {
            $next['auth_header'] = trim((string) $in['auth_header']);
        }
        if (isset($in['auth_format'])) {
            $format = (string) $in['auth_format'];
            if (!str_contains($format, '{key}')) {
                throw new SmsTemplateException('CLINIC_SMS_MAPPING_INVALID', 'auth_format باید حاوی {key} باشد');
            }
            $next['auth_format'] = $format;
        }
        if (isset($in['request_json'])) {
            $template = (string) $in['request_json'];
            if (preg_match('/<\?|eval\s*\(|function\s*\(/i', $template)) {
                throw new SmsTemplateException('CLINIC_SMS_MAPPING_INVALID', 'Request Mapping بدون Code — محتوای ارسالی مجاز نیست');
            }
            $next['request_json'] = $template;
        }
        if (isset($in['response']) && is_array($in['response'])) {
            $response = (array) ($current['response'] ?? []);
            foreach (['success_field', 'id_field', 'error_field', 'status_field'] as $k) {
                if (isset($in['response'][$k])) {
                    $response[$k] = trim((string) $in['response'][$k]);
                }
            }
            if (isset($in['response']['success_values'])) {
                $values = array_map('strval', (array) $in['response']['success_values']);
                $response['success_values'] = array_values(array_filter($values, static fn (string $v): bool => $v !== ''));
            }
            $next['response'] = $response;
        }

        $settings->set('sms.generic', $next, $userId);
        $this->auditSms('SMS_PROVIDER_CHANGED', $userId, ['provider' => 'generic_api', 'scope' => 'config']);
    }

    private function sanitizeSender(string $sender): string
    {
        $sender = trim($sender);
        if (mb_strlen($sender) > 20) {
            return '';
        }

        return preg_match('/^[0-9+A-Za-z.\-]*$/', $sender) === 1 ? $sender : '';
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function updateMessage(int $id, array $fields): void
    {
        $sets = [];
        $params = [];
        foreach ($fields as $column => $value) {
            if (!in_array($column, self::UPDATE_COLUMNS, true)) {
                continue; // Whitelist — فقط ستون‌های شناخته‌شده
            }
            $sets[] = "`{$column}` = %s";
            $params[] = $value;
        }
        if ($sets === []) {
            return;
        }
        $params[] = $this->db->nowUtcSql();
        $params[] = $id;

        $this->db->query(
            'UPDATE ' . $this->db->table('cpms_sms_messages') .
            ' SET ' . implode(', ', $sets) . ", updated_at = %s WHERE id = %d",
            $params
        );
    }

    /**
     * @param mixed $varsJson
     * @return array<string, string>
     */
    private function templateVariables($varsJson): array
    {
        if (!is_string($varsJson) || $varsJson === '') {
            return [];
        }
        $decoded = json_decode($varsJson, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_map('strval', $decoded);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchMessage(int $id): array
    {
        $row = $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_sms_messages') . ' WHERE id = %d',
            [$id]
        );

        return is_array($row) ? $row : [];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function auditSms(string $action, int $userId, array $meta): void
    {
        $this->audit->log(
            $action,
            ['wp_user_id' => $userId, 'role' => 'admin'],
            'sms_settings',
            null,
            null,
            null,
            null,
            $meta
        );
    }
}
