<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Finance\FinanceException;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Rest\RestClinicContext;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * C9 — translation-readiness of the two bounded C7-introduced REST human messages.
 *
 * دامنهٔ محدودشده (دقیقاً دو یافتهٔ A/Low):
 *  1. `ScheduleService::requireClinicianForTrustedClinic()` — C7-S5، کامیت `04a7a79e`
 *     ⇒ منتشر از `ScheduleController::wrap()`
 *  2. `FinanceService::requireTrustedClinicId()` — C7-S3، کامیت `c4cf9024`
 *     ⇒ منتشر از `FinanceController::staff()`
 *
 * این تست‌ها فقط **رفتار بیرونیِ قابل مشاهده** را می‌سنجند: پاکت خطای REST
 * (`code` top-level، ‏`message`، ‏`data`، HTTP status) به‌همراه اثرِ سمت DB.
 * هیچ تستی اینجا assert نمی‌کند که gettext داخل ScheduleService/FinanceService
 * اجرا می‌شود، و هیچ literal سرویسی تغییر نمی‌کند.
 *
 * ── چگونه به شرطِ محافظت‌شده می‌رسیم ─────────────────────────────────────────
 * در تولید، مرز C6 ‏(`RestClinicContext` روی `rest_request_before_callbacks` با
 * priority 10) ‏`ClinicScope` مورد اعتماد را **پیش از** اجرای callback می‌بندد؛
 * پس گارد fail-closed سطحِ سرویس نقش «لایهٔ دوم دفاعی» را دارد. برای سنجیدنِ
 * همان مرز ارائه در برابر این گارد، یک probe با priority 11 (دقیقاً همان
 * تکنیکِ `RestTrustedClinicContextTest` — نمونهٔ `$spy`/`$bomber`) scopeِ
 * بسته‌شده را **پس از** برقراریِ مشروعِ مرز پاک می‌کند. هیچ فیلتر تولیدی
 * حذف/ضعیف نمی‌شود، هیچ fixture شل نمی‌شود، و همهٔ خواصِ امنیتی
 * (کد/وضعیت fail-closed و «هیچ ردیفی نوشته نشد») سنجیده می‌شوند نه فرض.
 */
final class C9RestMessageI18nTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    private const CODE = 'CLINIC_SCOPE_REQUIRED';

    /**
     * خروجی فارسیِ **جاری** — بایت‌به‌بایت از `ScheduleService.php:389`
     * (C7-S5، ‏`04a7a79e`). فقط برای قفل‌کردنِ رفتار پیش‌فرض و به‌عنوان msgid
     * پروبِ ترجمه؛ این تست هرگز آن را تغییر نمی‌دهد.
     */
    private const SCHEDULE_MSG = 'عملیات برنامهٔ هفتگی بدون زمینهٔ کلینیک معتبر مجاز نیست';

    /**
     * خروجی فارسیِ **جاری** — بایت‌به‌بایت از `FinanceService.php:1111`
     * (C7-S3، ‏`c4cf9024`). حاوی ZWNJ ‏(U+200C) در «نمی‌شود» است.
     */
    private const FINANCE_MSG = 'عملیات حساس مالی بدون زمینهٔ کلینیک معتبر مجاز نیست — Clinic از شیء هدف استخراج نمی‌شود.';

    /**
     * پاکت ۴۰۴ غیرافشایِ موجود (parity) — `ScheduleService` برای پزشکِ
     * Clinic خارجی. باید بدون تغییر بماند.
     */
    private const NOT_FOUND_MSG = 'پزشک یافت نشد';

    /** نشانگرِ واریانتِ **پویای** از قبل موجودِ `SystemClinicResolver` (Phase-2 P2-B، ‏`1f8b36d`). */
    private const RESOLVER_MARKER = 'تعداد Clinicهای نصب';

    private const SCHEDULE_EN = 'Weekly schedule operation requires a valid clinic context.';

    private const FINANCE_EN = 'Sensitive finance operation requires a valid clinic context.';

    private int $clinicA = 1;

    private int $clinicB = 2;

    private int $adminId;

    private int $secretaryId;

    private int $clinicianId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();

        global $wpdb;
        $this->assertGreaterThan(
            0,
            (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = 1'), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'پیش‌شرط هارنس: Clinic شمارهٔ ۱ (seed شدهٔ خودِ هارنس) باید موجود باشد'
        );

        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, is_active, created_at, updated_at)
                 VALUES (1, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr C9 i18n',
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;
        $this->assertGreaterThan(0, $this->clinicianId, 'پیش‌شرط: ردیف پزشکِ Clinic A ساخته شد');

        /*
         * administrator ‏`cpms_config` را از `RolesAndCapabilities::register()` دارد
         * و برای مرز C6 هم «staff» است — همان شکلِ actorِ ‏`RestScheduleTest`.
         */
        $this->adminId = $this->makeUser('c9_admin', 'administrator');
        cpms_test_seed_membership($this->adminId, $this->clinicA, 'cpms_manager');

        // cpms_secretary ‏`cpms_invoice_create` و `cpms_invoice_read` را دارد (SECRETARY_CAPS).
        $this->secretaryId = $this->makeUser('c9_secretary', 'cpms_secretary');
        cpms_test_seed_membership($this->secretaryId, $this->clinicA, 'cpms_secretary');

        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        remove_all_filters('gettext_cpms');
        RestClinicContext::restoreAllPending();
        App::resetScope();
        wp_set_current_user(0);
        parent::tearDown();
    }

    // ==================================================================
    // A — رفتار پیش‌فرض: code/status/data/پیام فارسی بدون تغییر
    // ==================================================================

    /**
     * A/1 — مسیر واجد شرایطِ Schedule: پاکت خطا باید **دقیقاً** همان بماند.
     *
     * علاوه بر بایت‌های فارسی، این تست یک خاصیت بیرونیِ مهم‌تر را قفل می‌کند:
     * `message` پاکت REST باید برابر `getMessage()` همان استثنایی باشد که
     * سرویسِ واقعی در همان شرایط تولید می‌کند. چون پس از C9 مرز REST برای این
     * واریانت msgid خودش را منتشر می‌کند (نه `$e->getMessage()`)، **هیچ** تستِ
     * رفتارِ بیرونیِ دیگری نمی‌تواند واگراییِ سرویس/کنترلر را بگیرد — پس این
     * assertion عمدی است (توجیه در گزارش C9).
     */
    public function testScheduleScopeRequiredEnvelopeIsUnchangedByDefault(): void
    {
        wp_set_current_user($this->adminId);

        $env = $this->envelope($this->dispatchScheduleCreateWithoutScope());

        $this->assertSame(400, $env['status'], 'HTTP status باید ۴۰۰ بماند');
        $this->assertSame(self::CODE, $env['code'], 'کد ماشین‌خوان پایدار باید بدون تغییر بماند');
        $this->assertSame(['status' => 400], $env['data'], 'دادهٔ ساخت‌یافته باید دقیقاً همان بماند (فقط status)');
        $this->assertSame(self::SCHEDULE_MSG, $env['message'], 'پیام فارسی پیش‌فرض باید بایت‌به‌بایت بدون تغییر بماند');
        $this->assertSame(
            $this->scheduleServiceScopeMessage(),
            $env['message'],
            'مرز REST باید همان پیام انسانیِ سرویس واقعی را منتشر کند (ضدِ واگرایی)'
        );

        $this->assertSame(
            0,
            $this->scheduleRowCount($this->clinicianId, 2),
            'fail-closed: هیچ ردیف برنامه‌ای نباید درج شود'
        );
    }

    /** A/2 — مسیر واجد شرایطِ Finance: پاکت خطا باید **دقیقاً** همان بماند. */
    public function testFinanceScopeRequiredEnvelopeIsUnchangedByDefault(): void
    {
        wp_set_current_user($this->secretaryId);

        $env = $this->envelope($this->dispatchIssueInvoiceWithoutScope());

        $this->assertSame(400, $env['status'], 'HTTP status باید ۴۰۰ بماند');
        $this->assertSame(self::CODE, $env['code'], 'کد ماشین‌خوان پایدار باید بدون تغییر بماند');
        $this->assertSame(['status' => 400], $env['data'], 'دادهٔ ساخت‌یافته باید دقیقاً همان بماند (فقط status)');
        $this->assertSame(self::FINANCE_MSG, $env['message'], 'پیام فارسی پیش‌فرض (با ZWNJ) باید بایت‌به‌بایت بدون تغییر بماند');
        $this->assertSame(
            $this->financeServiceScopeMessage(),
            $env['message'],
            'مرز REST باید همان پیام انسانیِ سرویس واقعی را منتشر کند (ضدِ واگرایی)'
        );
    }

    // ==================================================================
    // B — ترجمه: فقط `message` عوض می‌شود؛ code/status/data یکسان
    // ==================================================================

    /**
     * B/1 — پیام انسانیِ Schedule باید در مرز REST **قابل ترجمه** باشد.
     *
     * مکانیزم کنترل‌شده: فیلتر core ‏`gettext_cpms` (دامنه‌اختصاصی، از WP 5.5).
     * هیچ فایل `.mo` و هیچ `load_plugin_textdomain()` لازم نیست — افزونه
     * کاتالوگ `cpms` بارگذاری نمی‌کند، پس بدون فیلتر همان msgid عیناً برمی‌گردد.
     */
    public function testScheduleScopeRequiredMessageIsTranslatableAtRestBoundary(): void
    {
        wp_set_current_user($this->adminId);
        $this->addTranslation(self::SCHEDULE_MSG, self::SCHEDULE_EN);

        $env = $this->envelope($this->dispatchScheduleCreateWithoutScope());

        $this->assertSame(self::SCHEDULE_EN, $env['message'], 'C9: پیام انسانی باید در مرز REST ترجمه‌پذیر باشد');
        $this->assertSame(400, $env['status'], 'ترجمه نباید HTTP status را تغییر دهد');
        $this->assertSame(self::CODE, $env['code'], 'ترجمه نباید کد ماشین‌خوان را تغییر دهد');
        $this->assertSame(['status' => 400], $env['data'], 'ترجمه نباید دادهٔ ساخت‌یافته را تغییر دهد');
        $this->assertSame(
            0,
            $this->scheduleRowCount($this->clinicianId, 2),
            'fail-closed با ترجمهٔ فعال هم باید بدون اثرِ نوشتن بماند'
        );
    }

    /** B/2 — پیام انسانیِ Finance باید در مرز REST **قابل ترجمه** باشد. */
    public function testFinanceScopeRequiredMessageIsTranslatableAtRestBoundary(): void
    {
        wp_set_current_user($this->secretaryId);
        $this->addTranslation(self::FINANCE_MSG, self::FINANCE_EN);

        $env = $this->envelope($this->dispatchIssueInvoiceWithoutScope());

        $this->assertSame(self::FINANCE_EN, $env['message'], 'C9: پیام انسانی باید در مرز REST ترجمه‌پذیر باشد');
        $this->assertSame(400, $env['status'], 'ترجمه نباید HTTP status را تغییر دهد');
        $this->assertSame(self::CODE, $env['code'], 'ترجمه نباید کد ماشین‌خوان را تغییر دهد');
        $this->assertSame(['status' => 400], $env['data'], 'ترجمه نباید دادهٔ ساخت‌یافته را تغییر دهد');
    }

    // ==================================================================
    // C — تفکیک‌کنندهٔ منفیِ Finance: واریانت resolver دست‌نخورده می‌ماند
    // ==================================================================

    /**
     * C/1 — واریانت `CLINIC_SCOPE_REQUIRED` متعلق به `SystemClinicResolver`
     * (که `clinic_count` حمل می‌کند و پیامش **پویا** است) باید بدون تغییر عبور
     * کند؛ حتی وقتی همان پروبِ ترجمهٔ واریانتِ C7 فعال است.
     *
     * این تست تفکیک‌کنندهٔ مرز را قفل می‌کند: اگر پیاده‌سازی فقط بر پایهٔ
     * `errorCode` نگاشت کند (بدون شرط `data === []`)، پیام پویای resolver با
     * literalِ C7 جایگزین می‌شود و این تست قرمز می‌شود.
     */
    public function testFinanceResolverVariantWithClinicCountIsNotRemapped(): void
    {
        $this->insertClinic($this->clinicB, 'c9-neg');
        wp_set_current_user($this->secretaryId);
        $this->addTranslation(self::FINANCE_MSG, self::FINANCE_EN);

        // `GET /config/services` ⇒ `FinanceService::listServices()` ⇒ `trustedClinicId()`
        // ⇒ `App::scope()` ⇒ بدون Scope صریح، `SystemClinicResolver` (تعداد ≠ ۱) ⇒ fail-closed.
        $env = $this->envelope($this->dispatchWithoutScope('GET', self::NS . '/config/services'));

        $this->assertSame(400, $env['status'], 'واریانت resolver هم ۴۰۰ است');
        $this->assertSame(self::CODE, $env['code'], 'کد همان CLINIC_SCOPE_REQUIRED است');
        $this->assertArrayHasKey('clinic_count', $env['data'], 'واریانت resolver باید `clinic_count` را حمل کند');
        $this->assertGreaterThanOrEqual(
            2,
            (int) ($env['data']['clinic_count'] ?? 0),
            'پیش‌شرط: بیش از یک Clinic ⇒ resolution سیستمی ناموفق'
        );
        $this->assertStringContainsString(
            self::RESOLVER_MARKER,
            $env['message'],
            'واریانت **پویای** resolver باید دست‌نخورده عبور کند'
        );
        $this->assertNotSame(self::FINANCE_MSG, $env['message'], 'literalِ C7 نباید جای پیام resolver بنشیند');
        $this->assertNotSame(self::FINANCE_EN, $env['message'], 'واریانت resolver نباید ترجمهٔ C7 را بگیرد');
    }

    // ==================================================================
    // D — امنیت: non-enumeration / fail-closed با پروبِ ترجمهٔ فعال
    // ==================================================================

    /**
     * D/1 — پاکت ۴۰۴ غیرافشا برای پزشکِ Clinic خارجی باید **بایت‌به‌بایت**
     * حفظ شود، حتی وقتی پروبِ ترجمهٔ واریانتِ C7 فعال است.
     *
     * اینجا dispatch معمولی است (بدون probe): مرز C6 ‏Clinic A را می‌بندد، پس
     * این «گارد مالکیت شیء» است که باید پاسخ دهد، نه گارد Scope.
     */
    public function testForeignClinicianNotFoundParitySurvivesTranslationProbe(): void
    {
        $this->insertClinic($this->clinicB, 'c9-parity');
        $foreignClinician = $this->insertClinician($this->clinicB, 'Dr Foreign C9');
        wp_set_current_user($this->adminId);
        $this->addTranslation(self::SCHEDULE_MSG, self::SCHEDULE_EN);

        $env = $this->envelope($this->dispatch('POST', self::NS . '/config/schedules', [
            'clinician_id' => $foreignClinician,
            'day_of_week' => 3,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ]));

        $this->assertSame(404, $env['status'], 'پاکت ۴۰۴ غیرافشا باید حفظ شود');
        $this->assertSame('CLINIC_NOT_FOUND', $env['code'], 'کد parity باید حفظ شود');
        $this->assertSame(self::NOT_FOUND_MSG, $env['message'], 'parity بایت‌به‌بایتِ «پزشک یافت نشد»');
        $this->assertSame(
            0,
            $this->scheduleRowCount($foreignClinician, 3),
            'عدم افشای وجود شیء: هیچ ردیفی برای پزشک خارجی درج نمی‌شود'
        );
    }

    // ================= Helpers =================

    /**
     * Dispatch واقعی REST در شرایط «بدون Scope معتبر در زمانِ سرویس».
     *
     * probe با priority 11 **پس از** مرز C6 (priority 10) اجرا می‌شود و فقط
     * `ScopeContext` را پاک می‌کند؛ هیچ فیلتر تولیدی حذف نمی‌شود و هیچ مجوزی
     * شل نمی‌شود (permission_callback و nonce دست‌نخورده اجرا می‌شوند).
     */
    private function dispatchWithoutScope(string $method, string $route, array $body = []): WP_REST_Response
    {
        $probe = static function ($response, $handler, $req) use ($route) {
            if ($req instanceof WP_REST_Request && $req->get_route() === $route) {
                ScopeContext::clear();
            }

            return $response;
        };
        add_filter('rest_request_before_callbacks', $probe, 11, 3);

        try {
            $res = $this->dispatch($method, $route, $body);
        } finally {
            remove_filter('rest_request_before_callbacks', $probe, 11);
        }

        $this->assertNull(ScopeContext::tryGet(), 'پیش‌شرط probe: هیچ Scope صریحی در زمانِ callback باقی نمانده است');

        return $res;
    }

    private function dispatchScheduleCreateWithoutScope(): WP_REST_Response
    {
        return $this->dispatchWithoutScope('POST', self::NS . '/config/schedules', [
            'clinician_id' => $this->clinicianId,
            'day_of_week' => 2,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ]);
    }

    private function dispatchIssueInvoiceWithoutScope(): WP_REST_Response
    {
        // `requireTrustedClinicId()` **پیش از** اعتبارسنجی visit_id/items اجرا می‌شود.
        return $this->dispatchWithoutScope('POST', self::NS . '/invoices', [
            'visit_id' => 999999,
            'items' => [],
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function dispatch(string $method, string $route, array $body = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

        return rest_do_request($request);
    }

    /**
     * پاکت خطای REST — همان شکلی که `RestBase::error()` + ‏`WP_REST_Server`
     * به کلاینت می‌دهند. `message` از مقدار **decode‌شده** خوانده می‌شود
     * (`wp_json_encode` پرچم `JSON_UNESCAPED_UNICODE` ندارد).
     *
     * @return array{status: int, code: string, message: string, data: array<string, mixed>}
     */
    private function envelope(WP_REST_Response $res): array
    {
        $body = $res->get_data();
        if ($body instanceof WP_Error) {
            $data = $body->get_error_data();

            return [
                'status' => (int) $res->get_status(),
                'code' => (string) $body->get_error_code(),
                'message' => (string) $body->get_error_message(),
                'data' => is_array($data) ? $data : [],
            ];
        }

        $this->assertIsArray($body, 'پاکت خطای REST باید آرایه یا WP_Error باشد');
        $data = $body['data'] ?? null;

        return [
            'status' => (int) $res->get_status(),
            'code' => (string) ($body['code'] ?? ''),
            'message' => (string) ($body['message'] ?? ''),
            'data' => is_array($data) ? $data : [],
        ];
    }

    /**
     * پیامِ واقعیِ همان سرویس در همان شرایط (فراخوان مستقیم، بدون Scope) —
     * الگوی موجود در `C7PreIntegrationBoundaryTest`. هیچ gettextی در سرویس
     * assert نمی‌شود؛ فقط `getMessage()` خوانده می‌شود.
     */
    private function scheduleServiceScopeMessage(): string
    {
        App::resetScope();
        $this->assertNull(ScopeContext::tryGet(), 'پیش‌شرط: هیچ Scope صریحی برقرار نیست');

        try {
            App::scheduleService()->create($this->adminId, [
                'clinician_id' => $this->clinicianId,
                'day_of_week' => 2,
                'start_time' => '09:00',
                'end_time' => '12:00',
            ]);
        } catch (BookingException $e) {
            if ($e->errorCode === self::CODE) {
                return $e->getMessage();
            }
        }

        $this->fail('پیش‌شرط: سرویس باید در نبودِ Scope، ' . self::CODE . ' بدهد');
    }

    private function financeServiceScopeMessage(): string
    {
        App::resetScope();
        $this->assertNull(ScopeContext::tryGet(), 'پیش‌شرط: هیچ Scope صریحی برقرار نیست');

        try {
            App::financeService()->issueInvoice($this->secretaryId, ['visit_id' => 999999, 'items' => []]);
        } catch (FinanceException $e) {
            if ($e->errorCode === self::CODE) {
                return $e->getMessage();
            }
        }

        $this->fail('پیش‌شرط: سرویس باید در نبودِ Scope، ' . self::CODE . ' بدهد');
    }

    /**
     * پروب ترجمهٔ کنترل‌شده روی دامنهٔ `cpms` — مکانیزم خودِ WordPress
     * (`apply_filters("gettext_{$domain}", …)` از WP 5.5). کلید روی msgid است،
     * پس هیچ رشتهٔ دیگری را لمس نمی‌کند.
     */
    private function addTranslation(string $msgid, string $translated): void
    {
        add_filter(
            'gettext_cpms',
            static function (string $translation, string $text, string $domain) use ($msgid, $translated): string {
                return $text === $msgid ? $translated : $translation;
            },
            10,
            3
        );
    }

    private function makeUser(string $login, string $role): int
    {
        $suffix = bin2hex(random_bytes(3));
        $userId = (int) wp_create_user($login . $suffix, 'pass-12345', $login . $suffix . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }
        $this->assertGreaterThan(0, $userId, 'پیش‌شرط: کاربر تست ساخته شد');

        return $userId;
    }

    private function insertClinic(int $id, string $slug): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $orgId = (int) $wpdb->get_var('SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $ok = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at)
                 VALUES (%d, %d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $id,
                $orgId,
                'Clinic ' . $slug,
                $slug,
                'Asia/Tehran',
                $now,
                $now
            )
        );
        $this->assertNotFalse($ok, 'پیش‌شرط: Clinic دوم درج شد');
        App::resetScope();
    }

    private function insertClinician(int $homeClinicId, string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, is_active, created_at, updated_at)
                 VALUES (%d, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $homeClinicId,
                $name,
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan(0, $id, 'پیش‌شرط: ردیف پزشک درج شد');

        return $id;
    }

    private function scheduleRowCount(int $clinicianId, int $dayOfWeek): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_schedule WHERE clinician_id = %d AND day_of_week = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicianId,
                $dayOfWeek
            )
        );
    }
}
