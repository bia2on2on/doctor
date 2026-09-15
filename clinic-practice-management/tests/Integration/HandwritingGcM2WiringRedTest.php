<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Handwriting\HandwritingService;
use ClinicCore\Application\Jobs\JobScopeClass;
use ClinicCore\Application\Jobs\JobScopeRegistry;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Bootstrap\App;
use Throwable;
use WP_UnitTestCase;

/**
 * M-2 handwriting.gc — قراردادِ اجرای scope-neutral + سیاستِ per-Clinic + کرانِ کران‌دار.
 *
 * این تست هم‌زمان چند چیز را قفل می‌کند:
 *   ۱) Jobِ `handwriting.gc` (طبقهٔ **W** — installation-wide sweep با semantics
 *      پر-ردیفِ Clinic) باید در context نصب‌گسترده — **بدونِ کاربر، بدونِ
 *      ScopeContext، بدونِ payload clinic_id، و بدونِ هیچ Clinic ثابت/مصنوعی** —
 *      از مسیرِ واقعیِ `App::runTick()` اجرا شود و پاک‌سازی را انجام دهد.
 *      مالکیتِ دائمیِ Clinicِ هر ردیفِ version =
 *      `cpms_handwriting_page_versions.page_id → cpms_handwriting_pages.document_id
 *      → cpms_handwriting_documents.clinic_id`. سیاستِ retention
 *      (`hw.version_keep`/`hw.version_max_age_days`) همچنان **Clinic-owned**
 *      است (ردیف‌های per-Clinic در `cpms_settings`) و ردیف‌های هر Clinic فقط
 *      با سیاستِ **خودِ همان** Clinic پاک‌سازی می‌شوند.
 *   ۲) دو Clinicِ fixture سیاست‌های **عمداً متفاوت** دارند (keep=2 در برابر
 *      keep=5)، به‌طوری که هر اِعمالِ سیاستِ Clinicِ دیگری روی ردیف‌ها نتیجهٔ
 *      متفاوتی می‌سازد و با assertِ دقیقِ «نسخه‌های باقی‌مانده» آشکار می‌شود.
 *   ۳) هر اجرا کرانِ کران‌دارِ `HandwritingService::GC_PAGE_BATCH_SIZE` صفحهٔ
 *      کاندیدای پردازش‌شده دارد (کرانِ واقعی در انتخابِ کاندیدا) و اجرای
 *      بعدیِ همان Job کارِ باقی‌مانده را ادامه می‌دهد (بدونِ OFFSET، بدونِ
 *      cursor دائمی).
 *   ۴) طبقهٔ نهاییِ registry برای این نوع = W (آخرین assert؛ تا در اجرای
 *      پیش‌از‌GREEN، امضای شکست از مسیرِ واقعیِ محصول آید، نه از قراردادِ registry).
 *
 * تاریخچه: نسخهٔ RED این تست پیش از اصلاح در main بازتولید می‌شود —
 *   Job با `last_error = CLINIC_SCOPE_REQUIRED: ...` شکست می‌خورد (امکان
 *   Claim می‌شود و attempts=1؛ یعنی مسیرِ محصول لمس شده است) و هیچ حذفی
 *   انجام نمی‌شود — چون `App::handwritingService() → App::settings() →
 *   App::scope()` در ساختِ Handler در install چندکلینیکیِ بدونِ scope
 *   fail-closed خطا می‌دهد.
 *
 * کش‌هایِ استاتیک / Greenِ کاذب: `SystemClinicResolver::$cached`، کشِ
 * `Settings::$cache` و نمونه‌هایِ memoizedِ App در سطحِ فرآیندِ PHP
 * باقی می‌مانند. در suite مشترک، نمونهٔ memoizedِ `HandwritingService` از
 * یک تستِ تک‌کلینیکیِ قبلی می‌تواند وابستگیِ Scope را پنهان کند. تست
 * خودش کش‌ها را می‌بندد و «ساختِ سرویس» را فقط به‌صورتِ **اطلاعاتی**
 * (در پیامِ assert) probe می‌کند؛ شاهدِ REDِ معتبر همان اجرای **متمرکز در
 * فرآیندِ تازه** است (workflow فقط-شواهدِ موقتِ
 * `m2-hw-gc-red-evidence.yml` — باید از diffِ نهایی حذف شود).
 *
 * Fixture: Clinicها و همهٔ ردیف‌های مادی (clinician/patient/visit/document/
 * page/version/settings) به‌صورت دینامیک ساخته می‌شوند (بدونِ ID ثابت،
 * بدونِ فرضِ «ردیفِ اول») و Job با payloadِ **خالی** enqueue می‌شود —
 * دقیقاً کاری که `scheduleRecurringJobs()` در production انجام می‌دهد.
 */
final class HandwritingGcM2WiringRedTest extends WP_UnitTestCase
{
    private int $orgId = 0;
    private int $clinicA = 0;
    private int $clinicB = 0;
    private int $locationA = 0;
    private int $locationB = 0;

    /** @var array{clinician: int, patient: int, visit: int, doc: int, page: int} */
    private array $tenantA = ['clinician' => 0, 'patient' => 0, 'visit' => 0, 'doc' => 0, 'page' => 0];
    /** @var array{clinician: int, patient: int, visit: int, doc: int, page: int} */
    private array $tenantB = ['clinician' => 0, 'patient' => 0, 'visit' => 0, 'doc' => 0, 'page' => 0];

    /** @var list<int> اسنادِ bulk (Clinic A) */
    private array $bulkDocsA = [];
    /** @var list<int> صفحاتِ bulk (Clinic A) */
    private array $bulkPagesA = [];
    /** @var list<int> اسنادِ bulk (Clinic B) */
    private array $bulkDocsB = [];
    /** @var list<int> صفحاتِ bulk (Clinic B) */
    private array $bulkPagesB = [];

    /**
     * صفر کردنِ کش‌هایِ سطحِ فرآیند که reset عمومی ندارند (class props).
     */
    private function resetAppCaches(): void
    {
        $refClass = new \ReflectionClass(App::class);
        foreach ([
            'db', 'op', 'audit', 'jobs', 'rate', 'loginRateLimiter', 'idem',
            'settingsFactory', 'installationSettings', 'migrations', 'dispatcher',
            'providers', 'vault', 'smsService', 'licenseGate', 'visitService',
        ] as $propName) {
            if ($refClass->hasProperty($propName)) {
                $prop = $refClass->getProperty($propName);
                $prop->setAccessible(true);
                $prop->setValue(null, null);
            }
        }
        App::resetScope();
        \ClinicCore\Application\Scope\SystemClinicResolver::flush();
        \ClinicCore\Settings\Settings::flushCache();
        if (method_exists(App::class, 'settingsFactory')) {
            try {
                App::settingsFactory()->reset();
            } catch (Throwable) {
                // reset فقط تلاشِ بهترین-effort است
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->resetAppCaches();
        $this->buildClinics();
        $this->purgeJobs();
        $this->resetAppCaches();
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        $this->purgeJobs();
        $this->purgeFixture();
        parent::tearDown();
    }

    /**
     * دو Clinic مجزا (با سازمانِ اختصاصی) + fixtureِ مادیِ هرکدام:
     * clinician، patient، visit، document، page، سیاستِ retentionِ متفاوت
     * (keep=2 / keep=5) و ۸ نسخهٔ دست‌خط با سن‌های طراحی‌شده.
     */
    private function buildClinics(): void
    {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();

        $orgSlug = 'hwgc-red-org-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_organizations') . ' (name, slug, status, created_at, updated_at) VALUES (%s, %s, "active", %s, %s)',
            'HandwritingGc Red Org',
            $orgSlug,
            $now,
            $now
        ));
        $this->orgId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->orgId, 'organizations insert must succeed');

        $clinicAId = $this->buildClinicRow('A', $this->orgId);
        $clinicBId = $this->buildClinicRow('B', $this->orgId);
        $this->clinicA = $clinicAId;
        $this->clinicB = $clinicBId;

        self::assertGreaterThan(1, $this->clinicA, 'clinic A must get a DB-generated id > 1 (never a fixed id)');
        self::assertGreaterThan(1, $this->clinicB, 'clinic B must get a DB-generated id > 1 (never a fixed id)');
        self::assertNotSame($this->clinicA, $this->clinicB, 'two distinct dynamically created clinics');

        // cpms_visits.location_id NOT NULL (migration 0013) → Location مادیِ هر Clinic لازم است.
        $this->locationA = $this->buildLocationRow('A', $clinicAId);
        $this->locationB = $this->buildLocationRow('B', $clinicBId);

        $this->buildTenant('A', $clinicAId, 2, $this->locationA);
        $this->buildTenant('B', $clinicBId, 5, $this->locationB);
    }

    /**
     * ساختِ یک tenant مادی کامل با سیاستِ retentionِ مشخص.
     *
     * نسخه‌هایِ صفحه (۸ نسخه):
     *   - suffix A (keep=2):  v1..v5 = 40 روز پیش (کهنه)، v6 = 10 روز، v7 = 5 روز، v8 = 1 روز (تازه)
     *       → سیاستِ A (keep=2, max_age=30): حذف v1..v5 → باقی‌مانده {6,7,8}
     *       → سیاستِ B بر روی صفحهٔ A (keep=5): حذف v1..v3 → باقی‌مانده {4..8} (متمایز)
     *   - suffix B (keep=5):  v1..v7 = 40 روز پیش (کهنه)، v8 = 1 روز (تازه)
     *       → سیاستِ B (keep=5, max_age=30): حذف v1..v3 → باقی‌مانده {4..8}
     *       → سیاستِ A بر روی صفحهٔ B (keep=2): حذف v1..v6 → باقی‌مانده {7,8} (متمایز)
     */
    private function buildTenant(string $suffix, int $clinicId, int $keep, int $locationId): void
    {
        $tenantRef = &$this->{$suffix === 'A' ? 'tenantA' : 'tenantB'};

        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_clinicians') . ' (clinic_id, full_name, is_active, created_at, updated_at) VALUES (%d, %s, 1, %s, %s)',
            $clinicId,
            'HandwritingGc Red Clinician ' . $suffix,
            $now,
            $now
        ));
        $tenantRef['clinician'] = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $tenantRef['clinician'], "clinician {$suffix} insert must succeed");

        $seq = bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_patients')
            . ' (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)'
            . ' VALUES (%d, %s, %s, %s, %s, "active", %s, %s)',
            $clinicId,
            'MR-HWGC-' . $seq,
            'Visit' . $suffix,
            'P' . $suffix,
            '0913' . substr($seq, 0, 7),
            $now,
            $now
        ));
        $tenantRef['patient'] = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $tenantRef['patient'], "patient {$suffix} insert must succeed");

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_visits')
            . ' (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, created_at, updated_at)'
            . ' VALUES (%d, %d, %d, %d, "walk_in", "checked_in", %s, %s, %s, %s)',
            $clinicId,
            $locationId,
            $tenantRef['clinician'],
            $tenantRef['patient'],
            gmdate('Y-m-d'),
            $now,
            $now,
            $now
        ));
        $tenantRef['visit'] = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $tenantRef['visit'], "visit {$suffix} insert must succeed");

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_handwriting_documents')
            . ' (clinic_id, visit_id, patient_id, clinician_id, title, page_count, created_at, updated_at)'
            . ' VALUES (%d, %d, %d, %d, NULL, 1, %s, %s)',
            $clinicId,
            $tenantRef['visit'],
            $tenantRef['patient'],
            $tenantRef['clinician'],
            $now,
            $now
        ));
        $tenantRef['doc'] = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $tenantRef['doc'], "document {$suffix} insert must succeed");

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_handwriting_pages')
            . ' (document_id, page_index, width, height, stroke_data, stroke_count, background_template, client_revision, version, updated_at)'
            . ' VALUES (%d, 0, 1240, 1754, "", 0, "lined", 0, 1, %s)',
            $tenantRef['doc'],
            $now
        ));
        $tenantRef['page'] = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $tenantRef['page'], "page {$suffix} insert must succeed");

        // سیاستِ retentionِ per-Clinic (Clinic-owned — ردیفِ cpms_settings).
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_settings') . ' (clinic_id, `key`, value_json, updated_at) VALUES (%d, "hw.version_keep", %s, %s)',
            $clinicId,
            (string) json_encode($keep),
            $now
        ));
        self::assertNotFalse($wpdb->insert_id, "settings row hw.version_keep for clinic {$suffix} must be inserted");

        // ۸ نسخهٔ دست‌خط با سن‌های طراحی‌شده.
        $old40 = $this->tsAgoDays(40);
        if ($suffix === 'A') {
            $ages = [40, 40, 40, 40, 40, 10, 5, 1];
        } else {
            $ages = [40, 40, 40, 40, 40, 40, 40, 1];
        }
        foreach ($ages as $i => $ageDays) {
            $this->insertVersion($tenantRef['page'], $i + 1, $ageDays === 40 ? $old40 : $this->tsAgoDays($ageDays));
        }
        self::assertSame(8, $this->countVersions($tenantRef['page']), "material fixture: exactly 8 versions on page {$suffix}");
    }

    private function buildClinicRow(string $suffix, int $orgId): int
    {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();

        $slug = 'hwgc-red-clinic-' . strtolower($suffix) . '-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_clinics') . ' (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            $orgId,
            'HandwritingGc Red Clinic ' . $suffix,
            $slug,
            'Asia/Tehran',
            $now,
            $now
        ));

        return (int) $wpdb->insert_id;
    }

    /**
     * Location مادیِ (اصلیِ) یک Clinic — ستونِ NOT NULLِ `cpms_visits.location_id`
     * (migration 0013) آن را برایِ fixture لازم می‌کند.
     */
    private function buildLocationRow(string $suffix, int $clinicId): int
    {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();

        $slug = 'hwgc-red-location-' . strtolower($suffix) . '-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_locations')
            . ' (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)'
            . ' VALUES (%d, %s, %s, "Asia/Tehran", 1, 1, %s, %s)',
            $clinicId,
            'HandwritingGc Red Location ' . $suffix,
            $slug,
            $now,
            $now
        ));

        return (int) $wpdb->insert_id;
    }

    private function purgeFixture(): void
    {
        global $wpdb;
        $db = App::db();

        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');

        $allBulkPages = array_merge($this->bulkPagesA, $this->bulkPagesB);
        if ($allBulkPages !== []) {
            $in = implode(',', array_map('intval', $allBulkPages));
            $wpdb->query('DELETE FROM ' . $db->table('cpms_handwriting_page_versions') . " WHERE page_id IN ({$in})"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query('DELETE FROM ' . $db->table('cpms_handwriting_pages') . " WHERE id IN ({$in})"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        $allBulkDocs = array_merge($this->bulkDocsA, $this->bulkDocsB);
        if ($allBulkDocs !== []) {
            $in = implode(',', array_map('intval', $allBulkDocs));
            $wpdb->query('DELETE FROM ' . $db->table('cpms_handwriting_documents') . " WHERE id IN ({$in})"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        foreach ([$this->tenantA, $this->tenantB] as $tenant) {
            if ($tenant['doc'] > 0) {
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_handwriting_documents') . ' WHERE id = %d', $tenant['doc']));
            }
            if ($tenant['visit'] > 0) {
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_visits') . ' WHERE id = %d', $tenant['visit']));
            }
            if ($tenant['patient'] > 0) {
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_patients') . ' WHERE id = %d', $tenant['patient']));
            }
            if ($tenant['clinician'] > 0) {
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinicians') . ' WHERE id = %d', $tenant['clinician']));
            }
        }
        foreach ([$this->locationA, $this->locationB] as $locationId) {
            if ($locationId > 0) {
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_locations') . ' WHERE id = %d', $locationId));
            }
        }
        foreach ([$this->clinicA, $this->clinicB] as $clinicId) {
            if ($clinicId > 0) {
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_settings') . ' WHERE clinic_id = %d', $clinicId));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', $clinicId));
            }
        }
        if ($this->orgId > 0) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_organizations') . ' WHERE id = %d', $this->orgId));
        }
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');

        $this->bulkDocsA = [];
        $this->bulkPagesA = [];
        $this->bulkDocsB = [];
        $this->bulkPagesB = [];
        $this->resetAppCaches();
    }

    private function purgeJobs(): void
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query('DELETE FROM ' . $db->table('cpms_jobs') . ' WHERE type IN (\'visits.no_show\',\'slots.generate\',\'holds.expire\',\'cleanup.otp\',\'cleanup.rate_limits\',\'cleanup.idem\',\'cleanup.oplog\',\'handwriting.gc\',\'notif.dispatch\',\'appt.reminder\',\'fu.reminder\',\'license.refresh\',\'backup.run\',\'report.export\',\'sms.send\')');
    }

    // ------------------------------------------------------------------
    // Helpers — fixture/خوانش
    // ------------------------------------------------------------------

    private function tsAgoDays(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() - $days * 86400) . '.000';
    }

    private function strokeBlob(): string
    {
        $json = json_encode([
            ['id' => 's1', 'tool' => 'pen', 'color' => '#1a1a2e', 'size' => 3.0, 'points' => [[10, 20, 0.5, 1], [40, 60, 0.8, 2]]],
        ], JSON_UNESCAPED_UNICODE);

        return base64_encode(gzencode((string) $json, 6) ?: (string) $json) ?: '[]';
    }

    private function insertVersion(int $pageId, int $version, string $createdAt): int
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_handwriting_page_versions')
            . ' (page_id, version, stroke_data, saved_by, created_at) VALUES (%d, %d, %s, "autosave", %s)',
            $pageId,
            $version,
            $this->strokeBlob(),
            $createdAt
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, "version insert (page {$pageId}, v{$version}) must succeed");

        return $id;
    }

    private function countVersions(int $pageId): int
    {
        global $wpdb;
        $db = App::db();

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $db->table('cpms_handwriting_page_versions') . ' WHERE page_id = %d',
            $pageId
        ));
    }

    /**
     * شمارهٔ نسخه‌های باقی‌ماندهٔ یک صفحه (مرتب).
     *
     * @return list<int>
     */
    private function survivingVersions(int $pageId): array
    {
        global $wpdb;
        $db = App::db();
        $rows = $wpdb->get_col($wpdb->prepare(
            'SELECT version FROM ' . $db->table('cpms_handwriting_page_versions') . ' WHERE page_id = %d ORDER BY version ASC',
            $pageId
        ));

        return array_map('intval', $rows);
    }

    /**
     * تعدادِ صفحاتِ (از میانِ فهرستِ داده‌شده) که دقیقاً n نسخهٔ باقی‌مانده دارند.
     *
     * @param list<int> $pageIds
     */
    private function countPagesWithRemaining(array $pageIds, int $n): int
    {
        if ($pageIds === []) {
            return 0;
        }
        global $wpdb;
        $db = App::db();
        $in = implode(',', array_map('intval', $pageIds));
        $sql = 'SELECT COUNT(*) FROM (SELECT v.page_id FROM ' . $db->table('cpms_handwriting_page_versions')
            . " v WHERE v.page_id IN ({$in}) GROUP BY v.page_id HAVING COUNT(*) = {$n}) t"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return (int) $wpdb->get_var($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /** تعدادِ ردیف‌هایِ «تازه» (کمتر از ۲ روز) روی صفحاتِ bulk. */
    private function countYoungBulkVersions(): int
    {
        $all = array_merge($this->bulkPagesA, $this->bulkPagesB);
        if ($all === []) {
            return 0;
        }
        global $wpdb;
        $db = App::db();
        $in = implode(',', array_map('intval', $all));

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $db->table('cpms_handwriting_page_versions')
            . " WHERE page_id IN ({$in}) AND created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->tsAgoDays(2)
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * @return array<string, mixed>
     */
    private function jobRow(int $jobId): array
    {
        global $wpdb;
        $db = App::db();

        return (array) $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d',
            $jobId
        ), ARRAY_A);
    }

    private function enqueueGcJob(): int
    {
        $queue = App::jobs();
        $nowDt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // دقیقاً همان چیزی که scheduleRecurringJobs() می‌کند: payload خالی + priority ثبت‌شده.
        return $queue->enqueue('handwriting.gc', [], $nowDt, 2, 3);
    }

    /**
     * ساختِ fixtureِ bulk برای کرانِ کارِ هر اجرا:
     * در هر Clinic `pagesPerClinic` صفحهٔ «قابلِ پردازش» (با سیاستِ خودِ همان Clinic) —
     * مجموعِ eligible > کرانِ `GC_PAGE_BATCH_SIZE`.
     *
     * @return array{perClinic: int, total: int}
     */
    private function buildBulkEligiblePages(): array
    {
        $batch = (int) HandwritingService::GC_PAGE_BATCH_SIZE;
        self::assertGreaterThan(0, $batch, 'candidate page bound must be a positive constant');
        $perClinic = intdiv($batch, 2) + 1;

        // Clinic A (keep=2): v1..v5 کهنه (40 روز) + v6 تازه (1 روز)
        //   → قابلِ حذف: v1..v4 (≤ maxVersion-keep=4 و کهنه)؛ پس از پردازش ۲ نسخه باقی می‌ماند.
        foreach (range(1, $perClinic) as $i) {
            $this->buildBulkPage('A', $i, [40, 40, 40, 40, 40, 1]);
        }
        // Clinic B (keep=5): v1..v7 کهنه (40 روز) + v8 تازه (1 روز)
        //   → قابلِ حذف: v1..v3 (≤ maxVersion-keep=3 و کهنه)؛ پس از پردازش ۵ نسخه باقی می‌ماند.
        foreach (range(1, $perClinic) as $i) {
            $this->buildBulkPage('B', $i, [40, 40, 40, 40, 40, 40, 40, 1]);
        }

        return ['perClinic' => $perClinic, 'total' => 2 * $perClinic];
    }

    private function buildBulkPage(string $suffix, int $i, array $ages): void
    {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();
        $tenant = $suffix === 'A' ? $this->tenantA : $this->tenantB;
        $clinicId = $suffix === 'A' ? $this->clinicA : $this->clinicB;

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_handwriting_documents')
            . ' (clinic_id, visit_id, patient_id, clinician_id, title, page_count, created_at, updated_at)'
            . ' VALUES (%d, %d, %d, %d, NULL, 1, %s, %s)',
            $clinicId,
            $tenant['visit'],
            $tenant['patient'],
            $tenant['clinician'],
            $now,
            $now
        ));
        $docId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $docId, "bulk document {$suffix}{$i} insert must succeed");

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_handwriting_pages')
            . ' (document_id, page_index, width, height, stroke_data, stroke_count, background_template, client_revision, version, updated_at)'
            . ' VALUES (%d, 0, 1240, 1754, "", 0, "lined", 0, 1, %s)',
            $docId,
            $now
        ));
        $pageId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $pageId, "bulk page {$suffix}{$i} insert must succeed");

        foreach ($ages as $v => $ageDays) {
            $this->insertVersion($pageId, $v + 1, $this->tsAgoDays($ageDays));
        }

        if ($suffix === 'A') {
            $this->bulkDocsA[] = $docId;
            $this->bulkPagesA[] = $pageId;
        } else {
            $this->bulkDocsB[] = $docId;
            $this->bulkPagesB[] = $pageId;
        }
    }

    // ------------------------------------------------------------------
    // تستِ اصلی
    // ------------------------------------------------------------------

    /**
     * قراردادِ کامل: scope-neutral + سیاستِ per-Clinic + کرانِ کارِ هر اجرا + ادامهٔ تکرارشونده.
     */
    public function testHandwritingGcMustRunScopeNeutralWithPerClinicPolicy(): void
    {
        global $wpdb;
        $db = App::db();

        // ---------------------------------------------------------------
        // ۱) پیش‌شرطِ fixture مادی: دو Clinic واقعی با شناسهٔ تولیدشده
        // ---------------------------------------------------------------
        self::assertGreaterThan(1, $this->clinicA, 'fixture clinic A must exist');
        self::assertGreaterThan(1, $this->clinicB, 'fixture clinic B must exist');
        $clinicCount = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics'));
        self::assertGreaterThan(1, $clinicCount, 'install must hold more than one clinic, found ' . $clinicCount);
        self::assertSame(8, $this->countVersions($this->tenantA['page']), 'fixture: page A has 8 versions');
        self::assertSame(8, $this->countVersions($this->tenantB['page']), 'fixture: page B has 8 versions');
        self::assertGreaterThanOrEqual(1, $this->locationA, 'fixture location A must exist (visits.location_id NOT NULL)');
        self::assertGreaterThanOrEqual(1, $this->locationB, 'fixture location B must exist (visits.location_id NOT NULL)');

        // سیاست‌های متفاوتِ per-Clinic واقعاً در ردیف‌های cpms_settings نشسته‌اند.
        $keepA = (int) json_decode((string) $wpdb->get_var($wpdb->prepare(
            'SELECT value_json FROM ' . $db->table('cpms_settings') . ' WHERE clinic_id = %d AND `key` = "hw.version_keep"',
            $this->clinicA
        )), true);
        $keepB = (int) json_decode((string) $wpdb->get_var($wpdb->prepare(
            'SELECT value_json FROM ' . $db->table('cpms_settings') . ' WHERE clinic_id = %d AND `key` = "hw.version_keep"',
            $this->clinicB
        )), true);
        self::assertSame(2, $keepA, 'fixture: clinic A retention policy is keep=2');
        self::assertSame(5, $keepB, 'fixture: clinic B retention policy is keep=5');

        // ---------------------------------------------------------------
        // ۲) عدمِ کاربر و عدمِ ScopeContext
        // ---------------------------------------------------------------
        wp_set_current_user(0);
        self::assertSame(0, get_current_user_id(), 'no current user');
        $this->resetAppCaches();
        self::assertNull(ScopeContext::tryGet(), 'no explicit ScopeContext must be set');

        // ---------------------------------------------------------------
        // ۳) قراردادِ ثبت‌شده: handwriting.gc نیاز به Clinic context ندارد
        //    (در RED/S و GREEN/W هر دو true — ماسک نمی‌کند)
        // ---------------------------------------------------------------
        self::assertContains(
            'handwriting.gc',
            App::dispatcher()->registeredTypes(),
            'production dispatcher must register handwriting.gc'
        );
        self::assertFalse(
            JobScopeRegistry::requiresClinicContext('handwriting.gc'),
            'handwriting.gc must never require ambient Clinic context'
        );
        self::assertTrue(
            JobScopeRegistry::permitsNullClinic('handwriting.gc'),
            'handwriting.gc must run with no Clinic'
        );

        // ---------------------------------------------------------------
        // ۴) پیش‌شرط: Scope محیطی در چندکلینیک Fail-Closed است (Job نباید به آن تکیه کند)
        //    + probeِ اطلاعاتیِ ساختِ سرویس (برای تشخیصِ آلودگیِ suite در پیامِ شکست)
        // ---------------------------------------------------------------
        try {
            $scope = App::scope();
            self::fail(
                'App::scope() must fail closed in a multi-clinic install without explicit scope, '
                    . 'but returned clinicId=' . $scope->clinicId
            );
        } catch (ScopeRequiredException $e) {
            self::assertSame('CLINIC_SCOPE_REQUIRED', $e->errorCode, 'fail-closed scope error code');
        }

        $constructionProbe = 'constructed';
        try {
            App::handwritingService();
        } catch (ScopeRequiredException $e) {
            $constructionProbe = 'scope_required(' . $e->errorCode . ')';
        } catch (Throwable $e) {
            $constructionProbe = 'error(' . $e->getMessage() . ')';
        }

        // ---------------------------------------------------------------
        // ۵) Enqueue واقعی با payload خالی + اجرا از مسیر App::runTick()
        // ---------------------------------------------------------------
        $jobId = $this->enqueueGcJob();
        self::assertGreaterThan(0, $jobId, 'enqueue of handwriting.gc must succeed');
        self::assertSame('queued', (string) $this->jobRow($jobId)['status'], 'job must start queued');

        $tickOne = App::runTick(20);
        $jobOne = $this->jobRow($jobId);
        self::assertSame(1, (int) $jobOne['attempts'], 'job #1 must be claimed exactly once (product path reached). tickResult=' . var_export($tickOne, true));

        $statusOne = (string) $jobOne['status'];
        self::assertSame(
            'success',
            $statusOne,
            'RED_SIGNATURE=scope_dependency :: handwriting.gc (installation-wide sweep with per-row Clinic semantics) '
            . 'must execute via the real App::runTick() path without any ambient Clinic scope. '
            . 'status=' . $statusOne
            . ' last_error=' . (string) $jobOne['last_error']
            . ' attempts=' . (int) $jobOne['attempts']
            . ' tickResult=' . var_export($tickOne, true)
            . ' clinic_count=' . $clinicCount
            . ' construction_probe=' . $constructionProbe
            . ' pageA_surviving=' . implode(',', $this->survivingVersions($this->tenantA['page']))
            . ' pageB_surviving=' . implode(',', $this->survivingVersions($this->tenantB['page']))
        );

        // ---------------------------------------------------------------
        // ۶) سیاستِ per-Clinic: هر صفحه فقط با سیاستِ Clinicِ مالکش پاک‌سازی شده
        //    (page A تحتِ keep=2 → باقی‌مانده {6,7,8}؛ page B تحتِ keep=5 → باقی‌مانده {4..8})
        // ---------------------------------------------------------------
        $survivingA = $this->survivingVersions($this->tenantA['page']);
        $survivingB = $this->survivingVersions($this->tenantB['page']);
        self::assertSame(
            [6, 7, 8],
            $survivingA,
            'page A must be purged with clinic A policy (keep=2): old v1..v5 deleted; '
            . 'newest keep + young versions survive. surviving=' . implode(',', $survivingA)
        );
        self::assertSame(
            [4, 5, 6, 7, 8],
            $survivingB,
            'page B must be purged with clinic B policy (keep=5): old v1..v3 deleted; '
            . 'old-but-kept v4..v7 and young v8 survive. surviving=' . implode(',', $survivingB)
        );
        // اگر سیاستِ Clinicِ دیگر اِعمال می‌شد، باقی‌مانده‌ها {4..8} (برای A) یا {7,8} (برای B) بود.

        // ---------------------------------------------------------------
        // ۷) طبقهٔ نهاییِ registry = W (آخرین assert — در RED به آن نمی‌رسیم)
        // ---------------------------------------------------------------
        self::assertSame(
            JobScopeClass::SWEEP,
            JobScopeRegistry::classFor('handwriting.gc'),
            'handwriting.gc must be registered as installation-wide sweep (W): '
            . 'per-row Clinic ownership via versions→pages→documents.clinic_id'
        );

        // ---------------------------------------------------------------
        // ۸) کرانِ کارِ هر اجرا + ادامهٔ تکرارشونده (بدونِ OFFSET/cursor)
        // ---------------------------------------------------------------
        $batch = (int) HandwritingService::GC_PAGE_BATCH_SIZE;
        $bulk = $this->buildBulkEligiblePages();
        $perClinic = (int) $bulk['perClinic'];
        $totalBulk = (int) $bulk['total'];
        self::assertGreaterThan($batch, $totalBulk, 'eligible bulk pages must exceed the per-invocation bound');
        // هیچ صفحهٔ bulk هنوز «drained» نیست (همهٔ آن‌ها کامل‌اند: ۶/۸ نسخه).
        self::assertSame(0, $this->countPagesWithRemaining($this->bulkPagesA, 2), 'precondition: no drained A pages yet');
        self::assertSame(0, $this->countPagesWithRemaining($this->bulkPagesB, 5), 'precondition: no drained B pages yet');

        // --- اجرای اول: حداکثر کران، و کارِ هر Clinic فقط با سیاستِ خود ---
        $jobIdTwo = $this->enqueueGcJob();
        self::assertGreaterThan(0, $jobIdTwo, 'second enqueue must succeed');
        $tickTwo = App::runTick(20);
        $jobTwo = $this->jobRow($jobIdTwo);
        self::assertSame(
            'success',
            (string) $jobTwo['status'],
            'bounded invocation #1 must succeed. status=' . (string) $jobTwo['status']
            . ' last_error=' . (string) $jobTwo['last_error']
            . ' tickResult=' . var_export($tickTwo, true)
        );

        $drainedA1 = $this->countPagesWithRemaining($this->bulkPagesA, 2);
        $drainedB1 = $this->countPagesWithRemaining($this->bulkPagesB, 5);
        $processed1 = $drainedA1 + $drainedB1;
        self::assertLessThanOrEqual($batch, $processed1, 'one invocation must process no more than the candidate bound, processed=' . $processed1);
        self::assertSame(
            $batch,
            $processed1,
            'with more eligible pages than the bound, invocation #1 must consume the full bound: '
            . "drainedA={$drainedA1} drainedB={$drainedB1} batch={$batch} perClinic={$perClinic}"
        );
        self::assertSame(
            $perClinic,
            $drainedA1,
            'all eligible pages of clinic A must be processed under policy A (drain marker = 2 remaining), drainedA=' . $drainedA1
        );
        // صفحاتِ «drained» با علامتِ اختصاصیِ هر Clinic اثبات می‌کنند که سیاستِ Clinicِ دیگر اِمال نشده است.
        self::assertSame(
            0,
            $this->countPagesWithRemaining($this->bulkPagesA, 5),
            'no page of clinic A may be purged with clinic B policy (5 remaining = policy B applied)'
        );
        self::assertSame(
            0,
            $this->countPagesWithRemaining($this->bulkPagesB, 2),
            'no page of clinic B may be purged with clinic A policy (2 remaining = policy A applied)'
        );

        // --- اجرای دوم: ادامهٔ کارِ باقی‌مانده (صفحاتِ eligibleِ B) ---
        $remainingEligibleB = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM (SELECT v.page_id FROM ' . $db->table('cpms_handwriting_page_versions')
            . ' v WHERE v.page_id IN (%s) GROUP BY v.page_id HAVING COUNT(*) = 8) t',
            implode(',', array_map('intval', $this->bulkPagesB))
        ));
        self::assertSame(
            $perClinic - $drainedB1,
            $remainingEligibleB,
            'exactly the unprocessed eligible pages of clinic B must remain, remaining=' . $remainingEligibleB
        );

        $jobIdThree = $this->enqueueGcJob();
        self::assertGreaterThan(0, $jobIdThree, 'third enqueue must succeed');
        $tickThree = App::runTick(20);
        $jobThree = $this->jobRow($jobIdThree);
        self::assertSame(
            'success',
            (string) $jobThree['status'],
            'continuation invocation #2 must succeed. status=' . (string) $jobThree['status']
            . ' last_error=' . (string) $jobThree['last_error']
            . ' tickResult=' . var_export($tickThree, true)
        );

        $drainedA2 = $this->countPagesWithRemaining($this->bulkPagesA, 2);
        $drainedB2 = $this->countPagesWithRemaining($this->bulkPagesB, 5);
        self::assertSame($perClinic, $drainedA2, 'clinic A pages must stay drained (no rework, no regression)');
        self::assertSame(
            $perClinic,
            $drainedB2,
            'invocation #2 must continue and drain the remaining eligible pages of clinic B, drainedB=' . $drainedB2
        );
        self::assertSame(
            $perClinic * 7,
            $drainedA2 * 2 + $drainedB2 * 5,
            'final remaining versions across bulk pages must equal A(2 each) + B(5 each); '
            . "remaining=" . ($drainedA2 * 2 + $drainedB2 * 5)
        );
        self::assertSame(
            $totalBulk,
            $this->countYoungBulkVersions(),
            'the young version of every bulk page must survive (never deleted by any policy)'
        );
    }
}
