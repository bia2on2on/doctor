<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * C6‑F (ادامه) — جداسازی tenant در دو مسیرِ باقی‌مانده:
 *  A) فایل بالینی: stream/list/mutation بر اساس Clinic معتبرِ درخواست
 *  B) صف/Today/Feed: حذف فرضِ زمان‌بندی `clinic_id = 1` به نفع Trusted Scope
 *
 * این فایل «مشخصۀ جداسازی» است، نه آینهٔ رفتار فعلی: انتظارها از معماری
 * (مرز C6 + Ownership لایۀ Service) می‌آیند. هیچ تستی اینجا skip نمی‌شود.
 *
 * ⚑ Clinicهای تست **عمداً ۱ نیستند** (A=61001، B=61002، C=61003 در Org دیگر)
 *   تا هیچ تستی با «limit 1» یا «Clinic 1» سبز نشود. تنها استثنای قابل‌توجیه:
 *   خواندن Organization پیش‌فرض از ردیف seed شدهٔ `id = 1` (fixture زیر).
 */
final class ClinicTenantIsolationTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    private const CLINIC_A = 61001;

    private const CLINIC_B = 61002;

    private const CLINIC_C = 61003;

    private string $storagePath = '';


    private int $locA = 0;

    private int $locB = 0;

    private int $orgA = 0;

    private int $orgB = 0;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();

        /*
         * این فایل تعداد Clinicهای نصب را زیاد می‌کند ⇒ Resolution سیستمی (exactly‑one)
         * عمداً Fail‑Closed می‌شود؛ پس هر خواندن/نوشتن Setting باید **زیرِ Scope صریحِ
         * fixture** انجام شود (نه Resolution ضمنی) — الگوی مجاز ScopeContext.
         */
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_A));

        // ذخیره‌سازی تست بیرون از wp-content (الگوی MedicalFilesTest) — cleanup در tearDown.
        $this->storagePath = sys_get_temp_dir() . '/cpms-tenant-files-' . bin2hex(random_bytes(4));

        $this->orgA = $this->defaultOrganization();
        $this->orgB = $this->insertOrganization('iso-org-b');

        $this->insertClinic(self::CLINIC_A, $this->orgA, 'iso-clinic-a');
        $this->insertClinic(self::CLINIC_B, $this->orgA, 'iso-clinic-b');
        $this->insertClinic(self::CLINIC_C, $this->orgB, 'iso-clinic-c');
        $this->locA = $this->insertLocation(self::CLINIC_A, 'iso-loc-a');
        $this->locB = $this->insertLocation(self::CLINIC_B, 'iso-loc-b');

        $this->writeStorageSettings([self::CLINIC_A, self::CLINIC_B, self::CLINIC_C]);
        $this->warmRoutes();
        $this->bindHarnessScope();
    }

    /**
     * fixture در نصب چند‑Clinic همیشه یک Scope صریح نگه می‌دارد: App::settings()
     * و کارخانه‌های service در هر rebuild به Resolution سیستمی می‌افتند و آنجا
     * عمداً Fail‑Closed است. مرز REST برای staff با context خودِ درخواست این را
     * override می‌کند (پس testهای «context مبهم/غلط» همچنان واقعی‌اند).
     */
    private function bindHarnessScope(): void
    {
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_A));
    }

    /** بیدارسازی lazy registry مسیرها زیرِ Scope صریح (بدون PHI؛ endpoint عمومی). */
    private function warmRoutes(): void
    {
        ScopeContext::set(ClinicScope::forClinic(self::CLINIC_A));
        \ClinicCore\Settings\Settings::flushCache();
        try {
            rest_do_request(new WP_REST_Request('GET', self::NS . '/health'));
        } finally {
            ScopeContext::clear();
            \ClinicCore\Settings\Settings::flushCache();
        }
    }

    /**
     * Setting ذخیره‌سازی برای هر Clinicِ تست — زیرِ Scope صریح (بدون Resolution ضمنی).
     *
     * @param list<int> $clinicIds
     */
    private function writeStorageSettings(array $clinicIds): void
    {
        foreach ($clinicIds as $clinicId) {
            App::replaceExplicitScope(ClinicScope::forClinic($clinicId));
            App::settings()->set('files.storage_path', $this->storagePath);
            App::settings()->set('files.max_upload_bytes', 10485760);
        }
        $this->bindHarnessScope();
    }

    protected function tearDown(): void
    {
        // fixture نباید هیچ Scope/Setting instance‌ای را به کلاس بعدی منتقل کند
        ScopeContext::clear();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        \ClinicCore\Application\Scope\SystemClinicResolver::flush();

        /*
         * نشتی‌گیر داده (دفاعی، در هر دو جهت): ردیف‌های Clinic این کلاس شناسهٔ
         * رزرو‌شدهٔ ≥ 61001 دارند. اگر روزی rollback تراکنشِ تست‌سوییٹ مختل شود،
         * «تعداد Clinic ≠ 1» به کلاس‌های بعدی سرایت نمی‌کند. هیچ ادعای product
         * را سست نمی‌کند — فقط fixture را پاک‌سازی می‌کند.
         */
        $this->purgeReserveRows();
        if ($this->storagePath !== '' && is_dir($this->storagePath)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->storagePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->storagePath);
        }
        parent::tearDown();
    }

    // =====================================================================
    // A — فایل بالینی (stream / list / upload / not‑found)
    // =====================================================================

    /** ۱) بیمار به فایل مجازِ خودش دسترسی دارد (regression — نباید با fix بشکند). */
    public function testPatientCanStreamOwnPatientVisibleFile(): void
    {
        [$patientA, $userA] = $this->seedPatientWithUser(self::CLINIC_A, 'PatA');
        $fileA = $this->seedFile(self::CLINIC_A, $patientA, 'patient_visible', 'own-a.pdf');

        wp_set_current_user($userA);
        $res = $this->call('GET', self::NS . '/files/' . $fileA . '/stream');

        $this->assertSame(200, $res->get_status(), 'دسترسی بیمار به فایل خودش باید باز بماند: ' . $this->body($res));
        $this->assertSame($this->pdfContent(), $res->get_data());
    }

    /** ۲) بیمار A نمی‌تواند فایل بیمار B را stream کند (مالکیت Object). */
    public function testPatientCannotStreamOtherPatientsFile(): void
    {
        [$patientA, $userA] = $this->seedPatientWithUser(self::CLINIC_A, 'PatA2');
        $patientB = $this->seedPatientWithUser(self::CLINIC_B, 'PatB2')[0];
        $fileB = $this->seedFile(self::CLINIC_B, $patientB, 'patient_visible', 'other-b.pdf');

        wp_set_current_user($userA);
        $res = $this->call('GET', self::NS . '/files/' . $fileB . '/stream');

        $this->assertSame(404, $res->get_status(), 'فایل بیمار دیگر نباید خوانده شود: ' . $this->body($res));
        $this->assertIsNotPdfBytes($res);
    }

    /** ۳) بیمار A نمی‌تواند فایل‌های بیمار B را فهرست/شمارش کند. */
    public function testPatientCannotEnumerateOtherPatientsFiles(): void
    {
        [, $userA] = $this->seedPatientWithUser(self::CLINIC_A, 'PatA3');
        [$patientB] = $this->seedPatientWithUser(self::CLINIC_B, 'PatB3');
        $this->seedFile(self::CLINIC_B, $patientB, 'patient_visible', 'list-b.pdf');

        wp_set_current_user($userA);
        $res = $this->call('GET', self::NS . '/patients/' . $patientB . '/files');
        $this->assertSame(404, $res->get_status(), 'فهرست فایل بیمار دیگر: ' . $this->body($res));
        $this->assertStringNotContainsString('list-b.pdf', $this->body($res));
    }

    /** ۴) کارکنان در Clinic A به فایل Clinic A دسترسی دارند (Trusted Scope + Cap). */
    public function testStaffInClinicACanStreamFileOfClinicA(): void
    {
        [$patientA] = $this->seedPatientWithUser(self::CLINIC_A, 'PatA4');
        $fileA = $this->seedFile(self::CLINIC_A, $patientA, 'patient_visible', 'a4.pdf');
        $doctor = $this->seedStaff('cpms_doctor', [self::CLINIC_A]);
        wp_set_current_user($doctor);

        $res = $this->call(
            'GET',
            self::NS . '/files/' . $fileA . '/stream',
            [],
            ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]
        );
        $this->assertSame(200, $res->get_status(), 'فایل هم‌Clinic باید خوانده شود: ' . $this->body($res));
        $this->assertSame($this->pdfContent(), $res->get_data());
    }

    /**
     * ۵) کارکنان Clinic A **نمی‌تواند** فایل Clinic B را stream کند.
     * این همان نقصِ مشکوک است: مسیر `/files/{id}/stream` در skip list بود و
     * `MedicalFileService::stream` برای پزشک/منشی فقط Cap+Visibility را می‌سنجید.
     */
    public function testStaffInClinicACannotStreamFileOfClinicB(): void
    {
        [$patientB] = $this->seedPatientWithUser(self::CLINIC_B, 'PatB5');
        $fileB = $this->seedFile(self::CLINIC_B, $patientB, 'patient_visible', 'secret-b5.pdf');
        $doctor = $this->seedStaff('cpms_doctor', [self::CLINIC_A]);
        wp_set_current_user($doctor);

        $res = $this->call(
            'GET',
            self::NS . '/files/' . $fileB . '/stream',
            [],
            ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]
        );

        $this->assertSame(404, $res->get_status(), 'read بین‌Clinic باید رد شود: ' . $this->body($res));
        $this->assertStringNotContainsString('secret-b5.pdf', $this->body($res));
        $this->assertIsNotPdfBytes($res);
    }

    /** ۶) کاربر چند‑Clinic در context A فایل B را نمی‌بیند (membership تنها کافی نیست). */
    public function testMultiMembershipStaffInContextACannotReadFileOfContextB(): void
    {
        [$patientB] = $this->seedPatientWithUser(self::CLINIC_B, 'PatB6');
        $fileB = $this->seedFile(self::CLINIC_B, $patientB, 'patient_visible', 'secret-b6.pdf');
        $doctor = $this->seedStaff('cpms_doctor', [self::CLINIC_A, self::CLINIC_B]);
        wp_set_current_user($doctor);

        $res = $this->call(
            'GET',
            self::NS . '/files/' . $fileB . '/stream',
            [],
            ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]
        );
        $this->assertSame(404, $res->get_status(), 'context A نباید فایل B را بدهد: ' . $this->body($res));
        $this->assertIsNotPdfBytes($res);
    }

    /** ۷) همان کاربر با context B فایل B را می‌خواند (predicate واقعاً Per‑Clinic است). */
    public function testSameStaffInContextBCanReadFileOfB(): void
    {
        [$patientB] = $this->seedPatientWithUser(self::CLINIC_B, 'PatB7');
        $fileB = $this->seedFile(self::CLINIC_B, $patientB, 'patient_visible', 'own-b7.pdf');
        $doctor = $this->seedStaff('cpms_doctor', [self::CLINIC_A, self::CLINIC_B]);
        wp_set_current_user($doctor);

        $res = $this->call(
            'GET',
            self::NS . '/files/' . $fileB . '/stream',
            [],
            ['X-CPMS-Clinic-Id' => (string) self::CLINIC_B]
        );
        $this->assertSame(200, $res->get_status(), 'context B باید فایل B را بدهد: ' . $this->body($res));
        $this->assertSame($this->pdfContent(), $res->get_data());
    }

    /** ۸) Organization دیگر: هیچ فایل بالینی‌ای قابل خواندن نیست. */
    public function testFileAccessDoesNotCrossOrganizationBoundary(): void
    {
        [$patientC] = $this->seedPatientWithUser(self::CLINIC_C, 'PatC8');
        $fileC = $this->seedFile(self::CLINIC_C, $patientC, 'patient_visible', 'org-b-file.pdf');
        $manager = $this->seedStaff('cpms_secretary', [self::CLINIC_A]);
        wp_set_current_user($manager);

        $res = $this->call(
            'GET',
            self::NS . '/files/' . $fileC . '/stream',
            [],
            ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]
        );
        $this->assertSame(404, $res->get_status(), 'فایل Organization دیگر: ' . $this->body($res));
        $this->assertStringNotContainsString('org-b-file.pdf', $this->body($res));
    }

    /** ۹) فایل ناموجود و فایل Clinic دیگر بدنهٔ یکسان دارند (عدم افشای وجود). */
    public function testMissingAndCrossClinicFileAreIndistinguishable(): void
    {
        [$patientB] = $this->seedPatientWithUser(self::CLINIC_B, 'PatB9');
        $fileB = $this->seedFile(self::CLINIC_B, $patientB, 'patient_visible', 'exists-b9.pdf');
        $doctor = $this->seedStaff('cpms_doctor', [self::CLINIC_A]);
        wp_set_current_user($doctor);

        $ghost = $this->call(
            'GET',
            self::NS . '/files/987654321/stream',
            [],
            ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]
        );
        $cross = $this->call(
            'GET',
            self::NS . '/files/' . $fileB . '/stream',
            [],
            ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]
        );
        $this->assertSame(404, $ghost->get_status());
        $this->assertSame(404, $cross->get_status());
        $this->assertSame('CLINIC_NOT_FOUND', $this->errorCode($ghost));
        $this->assertSame('CLINIC_NOT_FOUND', $this->errorCode($cross));
        $this->assertSame($this->body($ghost), $this->body($cross), 'بدنه نباید «Clinic دیگر» را از «ناموجود» متمایز کند');
        $this->assertStringNotContainsString('exists-b9.pdf', $this->body($cross));
    }

    /** ۱۰) رد stream هیچ بایت بالینی برنمی‌گرداند (بدنه نباید content فایل باشد). */
    public function testDeniedStreamReturnsNoClinicalBytes(): void
    {
        [$patientB] = $this->seedPatientWithUser(self::CLINIC_B, 'PatB10');
        $fileB = $this->seedFile(self::CLINIC_B, $patientB, 'patient_visible', 'bytes-b10.pdf');
        $doctor = $this->seedStaff('cpms_doctor', [self::CLINIC_A]);
        wp_set_current_user($doctor);

        $res = $this->call(
            'GET',
            self::NS . '/files/' . $fileB . '/stream',
            [],
            ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]
        );
        $data = $res->get_data();
        $this->assertIsArray($data, 'پاسخ رد باید Envelope خطا باشد، نه body باینری');
        $this->assertSame('CLINIC_NOT_FOUND', (string) $data['code']);
        $this->assertArrayNotHasKey('content', (array) $data, 'هیچ فیلد محتوای فایل در پاسخ رد نیست');
        $this->assertIsNotPdfBytes($res);
    }

    /** ۱۱) سوییچ پیاپی context نباید دسترسی فایل را نشت دهد (A→B→A). */
    public function testSequentialScopeSwitchingDoesNotLeakFileAccess(): void
    {
        [$patientA] = $this->seedPatientWithUser(self::CLINIC_A, 'PatA11');
        [$patientB] = $this->seedPatientWithUser(self::CLINIC_B, 'PatB11');
        $fileA = $this->seedFile(self::CLINIC_A, $patientA, 'patient_visible', 'a11.pdf');
        $fileB = $this->seedFile(self::CLINIC_B, $patientB, 'patient_visible', 'b11.pdf');
        $doctor = $this->seedStaff('cpms_doctor', [self::CLINIC_A, self::CLINIC_B]);
        wp_set_current_user($doctor);

        $inA = $this->call('GET', self::NS . '/files/' . $fileA . '/stream', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]);
        $this->assertSame(200, $inA->get_status(), 'A→A: ' . $this->body($inA));
        $this->assertSame(404, $this->call('GET', self::NS . '/files/' . $fileB . '/stream', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A])->get_status(), 'A نباید B را ببیند');

        $this->assertSame(200, $this->call('GET', self::NS . '/files/' . $fileB . '/stream', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_B])->get_status(), 'B→B');

        $backToA = $this->call('GET', self::NS . '/files/' . $fileB . '/stream', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]);
        $this->assertSame(404, $backToA->get_status(), 'پس از بازگشت به A، دسترسی B نباید باقی بماند');
    }

    /** ۱۲) دست‌کاری شناسۀ فایل/بیمار/ویزیت نباید مالکیت را دور بزند. */
    public function testDirectIdManipulationCannotBypassOwnership(): void
    {
        // (a) کارکنان A با تغییر file id به فایل B ⇒ رد
        [$patientB] = $this->seedPatientWithUser(self::CLINIC_B, 'PatB12');
        $fileB = $this->seedFile(self::CLINIC_B, $patientB, 'patient_visible', 'b12.pdf');
        $doctor = $this->seedStaff('cpms_doctor', [self::CLINIC_A]);
        wp_set_current_user($doctor);
        $res = $this->call('GET', self::NS . '/files/' . $fileB . '/stream', ['id' => $fileB], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]);
        $this->assertSame(404, $res->get_status(), 'شناسهٔ فایلِ Clinic دیگر: ' . $this->body($res));

        // (b) آپلود کارکنان A برای بیمار Clinic B ⇒ رد (relation‌محور بودن clinic فایل)
        $crossWrite = $this->resp($this->dispatchUpload(
            self::NS . '/files',
            ['patient_id' => $patientB, 'category' => 'document', 'visibility' => 'patient_visible', 'name' => 'x.pdf'],
            ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]
        ));
        // بیمار متعلق به Clinic دیگر ⇒ safe not‑found (بدون افشای وجود بیمار).
        $this->assertSame(404, $crossWrite->get_status(), 'نوشتن فایل روی بیمار Clinic دیگر: ' . $this->body($crossWrite));
        $this->assertSame('CLINIC_NOT_FOUND', $this->errorCode($crossWrite));
    }

    /**
     * ۱۲ب) کلیدِ مسیر نباید بایت بیرون از ریشه بدهد (storage invariant — §۶):
     * `storage_path` مخرب ⇒ 404 و بدون محتوای فایل.
     */
    public function testTraversingStoragePathNeverLeaksBytes(): void
    {
        [$patientB] = $this->seedPatientWithUser(self::CLINIC_B, 'PatB12b');
        $foreign = $this->storagePath . '-outside.txt';
        file_put_contents($foreign, 'TOP-SECRET-OUTSIDE-ROOT');
        $fileId = $this->insertAttachmentRow(self::CLINIC_B, $patientB, '../' . basename($foreign), 'leak.pdf');

        wp_set_current_user($this->seedStaff('cpms_doctor', [self::CLINIC_B]));
        $res = $this->call('GET', self::NS . '/files/' . $fileId . '/stream', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_B]);

        $this->assertSame(404, $res->get_status(), 'مسیر بیرون از ریشه نباید خوانده شود: ' . $this->body($res));
        $this->assertStringNotContainsString('TOP-SECRET-OUTSIDE-ROOT', $this->body($res));
        @unlink($foreign);
    }

    /**
     * ۵ب) فهرست فایل‌های ویزیت در «پرونده کامل» (E7 record) هم نباید فایل Clinic
     * دیگر را افشا کند — همان predicate در مسیر فهرست.
     */
    public function testVisitRecordDoesNotListFilesOfAnotherClinic(): void
    {
        [$patientB, , $clinicianB, $locB] = $this->seedPatientWithUser(self::CLINIC_B, 'PatB5b');
        $visitB = $this->seedVisit(self::CLINIC_B, $locB, $clinicianB, $patientB);
        $this->seedFile(self::CLINIC_B, $patientB, 'patient_visible', 'visit-b5b.pdf', $visitB);
        $doctor = $this->seedStaff('cpms_doctor', [self::CLINIC_A]);
        wp_set_current_user($doctor);

        $res = $this->call(
            'GET',
            self::NS . '/visits/' . $visitB . '/record',
            [],
            ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]
        );
        $this->assertSame(404, $res->get_status(), 'پروندهٔ ویزیت Clinic دیگر نباید باز شود: ' . $this->body($res));
        $this->assertStringNotContainsString('visit-b5b.pdf', $this->body($res));
    }

    // =====================================================================
    // B — صف / Today / Feed: حذف فرض clinic_id = 1
    // =====================================================================

    /** ۱۳) Today در context A فقط ویزیت‌های A را می‌دهد. */
    public function testSecretaryTodayReturnsOnlyTrustedClinicQueue(): void
    {
        $visitA = $this->seedQueueRow(self::CLINIC_A, $this->locA);
        $visitB = $this->seedQueueRow(self::CLINIC_B, $this->locB);
        $sec = $this->seedStaff('cpms_secretary', [self::CLINIC_A]);
        wp_set_current_user($sec);

        $res = $this->call('GET', self::NS . '/secretary/today', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]);
        $this->assertSame(200, $res->get_status(), $this->body($res));
        $ids = $this->queueIds($res);
        $this->assertContains($visitA, $ids, 'ردیف Clinic خودی باید در Today باشد');
        $this->assertNotContains($visitB, $ids, 'ردیف Clinic دیگر نباید در Today باشد');
    }

    /** ۱۴) Today در context B فقط ویزیت‌های B را می‌دهد (آینهٔ ۱۳). */
    public function testSecretaryTodayForClinicBReturnsOnlyB(): void
    {
        $visitA = $this->seedQueueRow(self::CLINIC_A, $this->locA);
        $visitB = $this->seedQueueRow(self::CLINIC_B, $this->locB);
        $sec = $this->seedStaff('cpms_secretary', [self::CLINIC_B]);
        wp_set_current_user($sec);

        $res = $this->call('GET', self::NS . '/secretary/today', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_B]);
        $this->assertSame(200, $res->get_status(), $this->body($res));
        $ids = $this->queueIds($res);
        $this->assertContains($visitB, $ids);
        $this->assertNotContains($visitA, $ids);
    }

    /** ۱۵) کاربر چند‑Clinic: هر context فقط دامنهٔ خودش (A→B→A بدون نشت). */
    public function testMultiClinicSecretaryQueueFollowsTrustedContext(): void
    {
        $visitA = $this->seedQueueRow(self::CLINIC_A, $this->locA);
        $visitB = $this->seedQueueRow(self::CLINIC_B, $this->locB);
        $sec = $this->seedStaff('cpms_secretary', [self::CLINIC_A, self::CLINIC_B]);
        wp_set_current_user($sec);

        $inA = $this->queueIds($this->call('GET', self::NS . '/secretary/today', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]));
        $this->assertSame([$visitA], $inA, 'context A دقیقاً دامنهٔ A');

        $inB = $this->queueIds($this->call('GET', self::NS . '/secretary/today', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_B]));
        $this->assertSame([$visitB], $inB, 'context B دقیقاً دامنهٔ B');

        $back = $this->queueIds($this->call('GET', self::NS . '/secretary/today', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]));
        $this->assertSame([$visitA], $back, 'پس از سوییچ برگشتی، دامنه باید دوباره فقط A باشد');
    }

    /** ۱۶) Today هیچ وابستگی به Clinic id=1 ندارد (کلید صریحاً غیر‌۱). */
    public function testQueueDoesNotDependOnClinicIdOne(): void
    {
        $visitA = $this->seedQueueRow(self::CLINIC_A, $this->locA);
        $sec = $this->seedStaff('cpms_secretary', [self::CLINIC_A]);
        wp_set_current_user($sec);

        $res = $this->call('GET', self::NS . '/secretary/today', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]);
        $this->assertSame(200, $res->get_status(), $this->body($res));
        $this->assertContains($visitA, $this->queueIds($res), 'دادهٔ Clinic غیر‌۱ باید دیده شود');
    }

    /** ۱۷) Organization دیگر در Today دیده نمی‌شود. */
    public function testQueueDoesNotCrossOrganizationBoundary(): void
    {
        $locC = $this->insertLocation(self::CLINIC_C, 'iso-loc-c');
        $visitC = $this->seedQueueRow(self::CLINIC_C, $locC);
        $visitA = $this->seedQueueRow(self::CLINIC_A, $this->locA);
        $sec = $this->seedStaff('cpms_secretary', [self::CLINIC_A]);
        wp_set_current_user($sec);

        $ids = $this->queueIds($this->call('GET', self::NS . '/secretary/today', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]));
        $this->assertContains($visitA, $ids);
        $this->assertNotContains($visitC, $ids, 'ردیف Organization دیگر نباید در Today باشد');
    }

    /** ۱۸) نبودِ Trusted Context روی مسیر صف ⇒ fail‑closed (نه «Clinic 1» ضمنی). */
    public function testQueueFollowsSoleMembershipNotHardcodedClinic(): void
    {
        $visitA = $this->seedQueueRow(self::CLINIC_A, $this->locA);
        $visitB = $this->seedQueueRow(self::CLINIC_B, $this->locB);
        $sec = $this->seedStaff('cpms_secretary', [self::CLINIC_B]);
        wp_set_current_user($sec);

        // بدون هدر: تنها Membership فعال = Clinic B ⇒ context موثق B (نه clinic_id=1)
        $res = $this->call('GET', self::NS . '/secretary/today');

        $this->assertSame(200, $res->get_status(), 'today برای منشیِ تک‑عضویت: ' . $this->body($res));
        $this->assertSame([$visitB], $this->queueIds($res), 'صف باید دقیقاًClinic context باشد (نه Clinic 1)');
        $this->assertNotContains($visitA, $this->queueIds($res), 'ردیف Clinic دیگر نباید در صف باشد');
    }

    public function testQueueWithoutTrustedContextFailsClosed(): void
    {
        $this->seedQueueRow(self::CLINIC_A, $this->locA);
        $sec = $this->seedStaff('cpms_secretary', [self::CLINIC_A, self::CLINIC_B]);
        wp_set_current_user($sec);

        // دو Membership فعال و بدون هدر ⇒ context مبهم: مرز باید رد کند (نه Clinic 1)
        $res = $this->call('GET', self::NS . '/secretary/today');
        $this->assertSame(400, $res->get_status(), 'context مبهم باید رد شود، نه به Clinic 1 بیفتد: ' . $this->body($res));
        $this->assertSame('CLINIC_SCOPE_REQUIRED', $this->errorCode($res));
        $this->assertSame([], $this->queueIds($res));
    }

    /** ۱۹) Feed رئال‌تایم (/rt/queue) رویداد Clinic دیگر را نمی‌دهد. */
    public function testRtFeedDoesNotCrossClinic(): void
    {
        $visitA = $this->seedQueueRow(self::CLINIC_A, $this->locA);
        $visitB = $this->seedQueueRow(self::CLINIC_B, $this->locB);
        $this->insertVisitEvent($visitA);
        $this->insertVisitEvent($visitB);
        $sec = $this->seedStaff('cpms_secretary', [self::CLINIC_A]);
        wp_set_current_user($sec);

        $res = $this->call('GET', self::NS . '/rt/queue', ['since' => 0], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]);
        $this->assertSame(200, $res->get_status(), $this->body($res));
        $visitIds = $this->feedVisitIds($res);
        $this->assertContains($visitA, $visitIds, 'رویداد Clinic خودی در Feed');
        $this->assertNotContains($visitB, $visitIds, 'رویداد Clinic دیگر نباید در Feed باشد');

        $watermark = (int) (((array) $res->get_data())['data']['last_event_id'] ?? -1);
        $this->assertGreaterThan(0, $watermark, 'watermark باید از رویدادهای همان Clinic بیاید');
    }

    /** ۲۰) دامنهٔ «پزشکِ متصل» نباید Clinicها را union کند. */
    public function testDoctorQueueScopeNeverUnionsClinics(): void
    {
        $patA = $this->seedPatient(self::CLINIC_A, 'PatA20');
        $patB = $this->seedPatient(self::CLINIC_B, 'PatB20');
        $doctor = $this->seedStaff('cpms_doctor', [self::CLINIC_A, self::CLINIC_B]);
        wp_set_current_user($doctor);
        // **عمداً یک Clinician** (u_clinician_user سالم) و ویزیتی در Clinic دیگر
        // که clinician_id همان پزشک است ⇒ فیلتر clinician تنها آن را رد نمی‌کند.
        $clinicianA = $this->insertClinician(self::CLINIC_A, $doctor, 'Dr Iso A');
        $visitA = $this->seedVisit(self::CLINIC_A, $this->locA, $clinicianA, $patA);
        $visitB = $this->seedVisit(self::CLINIC_B, $this->locB, $clinicianA, $patB);

        $resA = $this->call('GET', self::NS . '/doctor/today', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]);
        $this->assertSame(200, $resA->get_status(), $this->body($resA));
        $this->assertContains($visitA, $this->queueIds($resA));
        $this->assertNotContains($visitB, $this->queueIds($resA), 'ویزیتِ همان پزشک در Clinic دیگر نباید union شود');

        // در context B (با همان یک Clinician) — داده‌های A نباید بیایند
        $resB = $this->call('GET', self::NS . '/doctor/today', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_B]);
        $this->assertSame(200, $resB->get_status(), $this->body($resB));
        $this->assertNotContains($visitA, $this->queueIds($resB));
    }

    // =====================================================================
    // fixtures
    // =====================================================================

    /**
     * پاک‌سازی ردیف‌های fixture با شناسهٔ رزرو‌شده ≥ 61000 — به‌ترتیبِ کلیدهای
     * خارجی. این فقط hygiene تست است (نه سست‌کردن ادعا): اگر روزی rollback
     * تراکنش suite مختل شود، «تعداد Clinic ≠ 1» به کلاس‌های بعدی سرایت نمی‌کند.
     */
    private function purgeReserveRows(): void
    {
        global $wpdb;
        $pure = 'WHERE clinic_id >= 61000';
        $steps = [
            'cpms_visit_status_history' => 'WHERE visit_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_visits WHERE clinic_id >= 61000)',
            'cpms_medical_attachments' => $pure,
            'cpms_visits' => $pure,
            'cpms_appointments' => $pure,
            'cpms_patient_user_links' => $pure,
            'cpms_patients' => $pure,
            'cpms_clinicians' => $pure,
            'cpms_locations' => $pure,
            'cpms_membership_capabilities' => 'WHERE membership_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_clinic_memberships WHERE clinic_id >= 61000)',
            'cpms_membership_locations' => 'WHERE membership_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_clinic_memberships WHERE clinic_id >= 61000)',
            'cpms_clinic_memberships' => $pure,
            'cpms_settings' => $pure,
            'cpms_notifications' => $pure,
            'cpms_idempotency_keys' => $pure,
            'cpms_patient_merges' => $pure,
            'cpms_rate_limits' => 'WHERE clinic_id >= 61000',
            'cpms_audit_logs' => $pure,
            'cpms_clinics' => 'WHERE id >= 61000',
        ];
        foreach ($steps as $table => $clause) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $table . ' ' . $clause); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
        $wpdb->query(
            'DELETE FROM ' . $wpdb->prefix . "cpms_organizations WHERE slug LIKE 'iso\\_org\\_%'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );
    }

    private function defaultOrganization(): int
    {
        global $wpdb;
        $orgId = (int) $wpdb->get_var('SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertGreaterThan(0, $orgId, 'پیش‌شرط: Organization از ردیف seed شدهٔ نصب خوانده شود');

        return $orgId;
    }

    private function insertOrganization(string $slug): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at)
                 VALUES (%s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Org ' . $slug,
                $slug . '-' . bin2hex(random_bytes(2)),
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'پیش‌شرط: درج Organization');

        return $id;
    }

    private function insertClinic(int $id, int $orgId, string $slug): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
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
        self::assertNotEquals(1, $id, '⚑ این فایل نباید به Clinic id=1 تکیه کند');
        App::resetScope();
    }

    private function insertLocation(int $clinicId, string $slug): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, 1, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $slug,
                $slug,
                'Asia/Tehran',
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'پیش‌شرط: درج Location');

        return $id;
    }

    /** @param list<int> $clinicIds */
    /**
     * ساخت کاربر staff + Membership فعال روی Clinicهای داده‌شده.
     * عمداً current user را عوض نمی‌کند (fixtureها بعد از آن هم اجرا می‌شوند)؛
     * هر تست صریحاً wp_set_current_user() صدا می‌زند.
     */
    private function seedStaff(string $role, array $clinicIds): int
    {
        $userId = $this->makeUser('iso_' . str_replace('cpms_', '', $role), $role);
        foreach ($clinicIds as $clinicId) {
            cpms_test_seed_membership($userId, $clinicId, $role);
        }

        return $userId;
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login . bin2hex(random_bytes(3)), 'pass-12345', $login . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }
        self::assertGreaterThan(0, $userId, 'پیش‌شرط: ساخت کاربر');

        return $userId;
    }

    private function seedPatient(int $clinicId, string $lastName): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $seq = random_int(100000, 9999999);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                'MR-ISO-' . $seq . '-' . $clinicId,
                'Patient',
                $lastName,
                '0991' . sprintf('%07d', $seq % 10000000),
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'پیش‌شرط: درج بیمار');

        return $id;
    }

    /** @return array{0:int,1:int,2:int,3:int} [patientId, wpUserId, clinicianId, locationId] */
    private function seedPatientWithUser(int $clinicId, string $lastName): array
    {
        $locationId = $clinicId === self::CLINIC_B ? $this->locB : $this->locA;
        $patientId = $this->seedPatient($clinicId, $lastName);
        $userId = $this->makeUser('iso_pat_' . $lastName, 'cpms_patient');
        $mobile = (string) $this->patientMobile($patientId);

        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patient_user_links
                     (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at)
                 VALUES (%d, %d, %d, %s, 1, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $patientId,
                $userId,
                $mobile,
                App::db()->nowUtcSql()
            )
        );
        self::assertGreaterThan(0, (int) $wpdb->insert_id, 'پیش‌شرط: پیوند کاربر×بیمار');

        // یک Clinician در همان Clinic (برای تست‌های ویزیت/پرونده) — بدون اتصال به کاربر
        $clinicianId = $this->insertClinician($clinicId, 0, 'Clinician ' . $lastName);

        return [$patientId, $userId, $clinicianId, $locationId];
    }

    private function patientMobile(int $patientId): string
    {
        global $wpdb;
        return (string) $wpdb->get_var(
            $wpdb->prepare('SELECT mobile FROM ' . $wpdb->prefix . 'cpms_patients WHERE id = %d', $patientId) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
    }

    private function insertClinician(int $clinicId, int $wpUserId, string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        // u_clinician_user: UNIQUE روی wp_user_id ⇒ هر ردیف بدونِ اتصال، کاربرِ
        // factory متعلق به خودش را می‌گیرد (NULL نگه‌داشتن در این suite نشتی
        // query خطای DB می‌داد؛ صریح‌سازیِ شناسه هم با الزام فاز «id ≠ 1» می‌خواند).
        $linkedUserId = $wpUserId > 0 ? $wpUserId : (int) self::factory()->user->create(['role' => 'subscriber']);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (%d, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $name,
                $linkedUserId,
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'پیش‌شرط: درج Clinician');

        return $id;
    }

    private function linkClinicianUser(int $clinicianId, int $wpUserId): void
    {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . $wpdb->prefix . 'cpms_clinicians SET wp_user_id = %d WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $wpUserId,
                $clinicianId
            )
        );
    }

    /** @return int visitId */
    private function seedVisit(int $clinicId, int $locationId, int $clinicianId, int $patientId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $date = gmdate('Y-m-d');
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_visits
                     (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, waiting_since, called_at, active, created_at, updated_at)
                 VALUES (%d, %d, %d, %d, "walk_in", "waiting", %s, %s, %s, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $locationId,
                $clinicianId,
                $patientId,
                $date,
                $date . ' 10:00:00.000',
                $date . ' 10:00:00.000',
                $date . ' 10:05:00.000',
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'پیش‌شرط: درج Visit');

        return $id;
    }

    /** یک ردیف صف معتبر در همان Clinic. @return int visitId */
    private function seedQueueRow(int $clinicId, int $locationId): int
    {
        $clinicianId = $this->insertClinician($clinicId, 0, 'Dr Queue ' . $clinicId);
        $patientId = $this->seedPatient($clinicId, 'PQ' . $clinicId);

        return $this->seedVisit($clinicId, $locationId, $clinicianId, $patientId);
    }

    /** رویداد صف (برای Feed/watermark) — join به visit همان Clinic را می‌دهد. */
    private function insertVisitEvent(int $visitId): void
    {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_visit_status_history
                     (visit_id, from_status, to_status, actor_wp_user_id, actor_role, note, changed_at)
                 VALUES (%d, "waiting", "called", NULL, "cpms_secretary", "iso-feed", %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $visitId,
                App::db()->nowUtcSql()
            )
        );
        self::assertGreaterThan(0, (int) $wpdb->insert_id, 'پیش‌شرط: رویداد وضعیت ویزیت');
    }

    /**
     * درج فایل بالینی «واقعی» از مسیر Product (upload) — تا fixture جعلی نباشد.
     *
     * @return int fileId
     */
    /**
     * فایل را از **همان endpoint‌ای که خوانده می‌شود** می‌سازیم (upload واقعی):
     * instance ذخیره‌سازیِ controller در boot با Setting زمانِ boot ساخته می‌شود و
     * در نصب چند‑Clinic می‌تواند با instance تست فرق کند؛ ساختِ فایل از مسیر
     * service مستقیم therefore منبع «file missing» کاذب می‌ساخت.
     */
    private function seedFile(
        int $clinicId,
        int $patientId,
        string $visibility,
        string $filename,
        ?int $visitId = null,
        ?int $actorUserId = null
    ): int {
        $original = get_current_user_id();
        $actor = $actorUserId;
        if ($actor === null) {
            $actor = $this->seedStaff('cpms_secretary', [$clinicId]);
        }
        $actorUser = get_userdata($actor);
        $isPatient = $actorUser !== false && in_array(RolesAndCapabilities::ROLE_PATIENT, (array) $actorUser->roles, true);

        $request = new WP_REST_Request($isPatient ? 'POST' : 'POST', $isPatient
            ? self::NS . '/patients/' . $patientId . '/files'
            : self::NS . '/files');
        wp_set_current_user($actor);
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        if (!$isPatient) {
            $request->set_param('patient_id', $patientId);
            $request->set_param('category', 'document');
            $request->set_param('visibility', $visibility);
            if ($visitId !== null) {
                $request->set_param('visit_id', $visitId);
            }
            $request->set_header('X-CPMS-Clinic-Id', (string) $clinicId);
        } else {
            $request->set_param('category', 'document');
        }
        $request->set_file_params(['file' => $this->uploadedFileArgs($filename, $this->pdfContent())]);

        $res = rest_do_request($request);
        $this->bindHarnessScope();
        wp_set_current_user($original);
        if ($res instanceof \WP_Error) {
            $this->fail('پیش‌شرط: آپلود fixture با WP_Error: ' . $res->get_error_code() . ' — ' . $res->get_error_message());
        }

        $this->assertLessThan(400, $res->get_status(), 'پیش‌شرط: آپلود از مسیر Product: ' . $this->body($res));
        $id = $this->dataOf($res)['id'] ?? 0;
        $this->assertGreaterThan(0, (int) $id, 'پیش‌شرط: شناسه فایل آپلودشده');

        return (int) $id;
    }

    /** @return array<string, mixed> */
    private function dataOf(WP_REST_Response $res): array
    {
        $data = (array) $res->get_data();

        return (array) ($data['data'] ?? $data);
    }

    /** shape استاندارد $_FILES برای set_file_params (tmp_name واقعی روی دیسک). */
    private function uploadedFileArgs(string $name, string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cpms-iso-');
        self::assertIsString($tmp);
        file_put_contents($tmp, $content);

        return [
            'name' => $name,
            'tmp_name' => $tmp,
            'size' => (int) filesize($tmp),
            'error' => UPLOAD_ERR_OK,
            'type' => 'application/pdf',
        ];
    }

    /** درج ردیف attachment با storage_path دلخواه (فقط برای تست containment). */
    private function insertAttachmentRow(int $clinicId, int $patientId, string $storagePath, string $filename): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_medical_attachments
                     (clinic_id, patient_id, original_filename, stored_filename, mime_type, file_size, storage_path, visibility, category, created_at)
                 VALUES (%d, %d, %s, %s, "application/pdf", %d, %s, "patient_visible", "document", %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $patientId,
                $filename,
                substr(hash('sha256', $filename), 0, 32) . '.pdf',
                1234,
                $storagePath,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'پیش‌شرط: درج ردیف attachment');

        return $id;
    }

    /** @param array<string, string> $headers */
    /**
     * Dispatch واقعی. نکتهٔ حیاتیِ harness: registry مسیرها در WP به‌صورت lazy با
     * `rest_api_init` ساخته می‌شود و خودِ رجیستری `App::scope()` را صدا می‌زند؛ پس
     * در نصبِ چند‑Clinic این فایل، اگر Scope خالی باشد رجیستری وسطِ راه throw و
     * مسیرها برای کل suite می‌سوزند. warmRoutes() در setUp همان بیدارسازی را زیرِ
     * Scope صریح انجام می‌دهد؛ اینجا فقط بعد از درخواست پاک می‌کنیم تا Scope
     * باقی‌مانده به تست/کلاس بعدی نرسد.
     */
    private function dispatch(string $method, string $route, array $body = [], array $headers = []): WP_REST_Response|\WP_Error
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        foreach ($headers as $name => $value) {
            $request->set_header($name, $value);
        }

        // contextِ موثق باید از خودِ درخواست بیاید، نه از Scopeِ fixture
        ScopeContext::clear();
        \ClinicCore\Settings\Settings::flushCache();

        try {
            return rest_do_request($request);
        } finally {
            $this->bindHarnessScope();
        }
    }

    /** همان dispatch، ولی با فایل multipart (برای آزمون‌های نوشتن). */
    private function dispatchUpload(string $route, array $body = [], array $headers = []): WP_REST_Response|\WP_Error
    {
        $request = new WP_REST_Request('POST', $route);
        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        foreach ($headers as $name => $value) {
            $request->set_header($name, $value);
        }
        $request->set_file_params(['file' => $this->uploadedFileArgs('probe.pdf', $this->pdfContent())]);

        ScopeContext::clear();
        \ClinicCore\Settings\Settings::flushCache();

        try {
            return rest_do_request($request);
        } finally {
            $this->bindHarnessScope();
        }
    }

    /**
     * پاسخ را می‌گیرد و اگر WP_Error باشد (route ثبت‌نشده/خطای مرز) صریح و
     * خوانا شکست می‌خورد — نه با notice «could not be converted to int».
     */
    private function resp(WP_REST_Response|\WP_Error $res): WP_REST_Response
    {
        if ($res instanceof \WP_Error) {
            $this->fail('پاسخ WP_Error: ' . $res->get_error_code() . ' — ' . $res->get_error_message());
        }

        return $res;
    }

    /** dispatch + تضمین نوع پاسخ. */
    private function call(string $method, string $route, array $body = [], array $headers = []): WP_REST_Response
    {
        return $this->resp($this->dispatch($method, $route, $body, $headers));
    }

    private function body(WP_REST_Response $res): string
    {
        return (string) json_encode($res->get_data(), JSON_UNESCAPED_UNICODE);
    }

    private function pdfContent(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n%%EOF\n";
    }

    /** @return array{name:string, tmp_name:string, size:int, error:int, type:string} */
    private function makeUploadedFile(string $name, string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cpms-iso-');
        self::assertIsString($tmp);
        file_put_contents($tmp, $content);

        return [
            'name' => $name,
            'tmp_name' => $tmp,
            'size' => (int) filesize($tmp),
            'error' => 0,
            'type' => 'application/octet-stream',
        ];
    }

    /** @return list<int> شناسه‌های ویزیت در Today/Queue */
    private function queueIds(WP_REST_Response $res): array
    {
        $data = (array) $res->get_data();
        $payload = (array) ($data['data'] ?? []);
        $rows = (array) ($payload['queue'] ?? []);

        return array_map(static fn (array $v): int => (int) $v['id'], $rows);
    }

    /** @return list<int> شناسه‌های ویزیت در Feed رئال‌تایم */
    private function feedVisitIds(WP_REST_Response $res): array
    {
        $data = (array) $res->get_data();
        $payload = (array) ($data['data'] ?? []);
        $rows = (array) ($payload['events'] ?? []);

        return array_map(static fn (array $e): int => (int) $e['visit_id'], $rows);
    }

    private function errorCode(WP_REST_Response $res): string
    {
        $body = $res->get_data();

        return (string) (is_array($body) ? ($body['code'] ?? '') : '');
    }

    /** ادعا: پاسخ هیچ بایت بالینی (PDF) ندارد — نه در body، نه در هدر. */
    private function assertIsNotPdfBytes(WP_REST_Response $res): void
    {
        $data = $res->get_data();
        $flat = is_string($data) ? $data : (string) json_encode($data, JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('%PDF-1.4', $flat, 'هیچ بایت فایل بالینی در پاسخ رد نباید باشد');
        $headers = $res->get_headers();
        self::assertArrayNotHasKey('Content-Disposition', $headers, 'هدر دانلود فایل نباید در پاسخ رد باشد');
    }
}
