<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
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

        // ذخیره‌سازی تست بیرون از wp-content (الگوی MedicalFilesTest) — cleanup در tearDown.
        $this->storagePath = sys_get_temp_dir() . '/cpms-tenant-files-' . bin2hex(random_bytes(4));
        App::settings()->set('files.storage_path', $this->storagePath);
        App::settings()->set('files.max_upload_bytes', 10485760);

        $this->orgA = $this->defaultOrganization();
        $this->orgB = $this->insertOrganization('iso-org-b');

        $this->insertClinic(self::CLINIC_A, $this->orgA, 'iso-clinic-a');
        $this->insertClinic(self::CLINIC_B, $this->orgA, 'iso-clinic-b');
        $this->insertClinic(self::CLINIC_C, $this->orgB, 'iso-clinic-c');
        $this->locA = $this->insertLocation(self::CLINIC_A, 'iso-loc-a');
        $this->locB = $this->insertLocation(self::CLINIC_B, 'iso-loc-b');
    }

    protected function tearDown(): void
    {
        App::resetScope();
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
        $res = $this->dispatch('GET', self::NS . '/files/' . $fileA . '/stream');

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
        $res = $this->dispatch('GET', self::NS . '/files/' . $fileB . '/stream');

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
        $res = $this->dispatch('GET', self::NS . '/patients/' . $patientB . '/files');
        $this->assertSame(404, $res->get_status(), 'فهرست فایل بیمار دیگر: ' . $this->body($res));
        $this->assertStringNotContainsString('list-b.pdf', $this->body($res));
    }

    /** ۴) کارکنان در Clinic A به فایل Clinic A دسترسی دارند (Trusted Scope + Cap). */
    public function testStaffInClinicACanStreamFileOfClinicA(): void
    {
        [$patientA] = $this->seedPatientWithUser(self::CLINIC_A, 'PatA4');
        $fileA = $this->seedFile(self::CLINIC_A, $patientA, 'patient_visible', 'a4.pdf');
        $doctor = $this->seedStaff('cpms_doctor', [self::CLINIC_A]);

        $res = $this->dispatch(
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

        $res = $this->dispatch(
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

        $res = $this->dispatch(
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

        $res = $this->dispatch(
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

        $res = $this->dispatch(
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

        $ghost = $this->dispatch(
            'GET',
            self::NS . '/files/987654321/stream',
            [],
            ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]
        );
        $cross = $this->dispatch(
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

        $res = $this->dispatch(
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

        $inA = $this->dispatch('GET', self::NS . '/files/' . $fileA . '/stream', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]);
        $this->assertSame(200, $inA->get_status(), 'A→A: ' . $this->body($inA));
        $this->assertSame(404, $this->dispatch('GET', self::NS . '/files/' . $fileB . '/stream', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A])->get_status(), 'A نباید B را ببیند');

        $this->assertSame(200, $this->dispatch('GET', self::NS . '/files/' . $fileB . '/stream', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_B])->get_status(), 'B→B');

        $backToA = $this->dispatch('GET', self::NS . '/files/' . $fileB . '/stream', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]);
        $this->assertSame(404, $backToA->get_status(), 'پس از بازگشت به A، دسترسی B نباید باقی بماند');
    }

    /** ۱۲) دست‌کاری شناسۀ فایل/بیمار/ویزیت نباید مالکیت را دور بزند. */
    public function testDirectIdManipulationCannotBypassOwnership(): void
    {
        // (a) کارکنان A با تغییر file id به فایل B ⇒ رد
        [$patientB] = $this->seedPatientWithUser(self::CLINIC_B, 'PatB12');
        $fileB = $this->seedFile(self::CLINIC_B, $patientB, 'patient_visible', 'b12.pdf');
        $doctor = $this->seedStaff('cpms_doctor', [self::CLINIC_A]);
        $res = $this->dispatch('GET', self::NS . '/files/' . $fileB . '/stream', ['id' => $fileB], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]);
        $this->assertSame(404, $res->get_status(), 'شناسهٔ فایلِ Clinic دیگر: ' . $this->body($res));

        // (b) آپلود کارکنان A برای بیمار Clinic B ⇒ رد (relation‌محور بودن clinic فایل)
        $crossWrite = $this->dispatch(
            'POST',
            self::NS . '/files',
            ['patient_id' => $patientB, 'category' => 'document', 'visibility' => 'patient_visible'],
            ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]
        );
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
        $res = $this->dispatch('GET', self::NS . '/files/' . $fileId . '/stream', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_B]);

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

        $res = $this->dispatch(
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
        $this->seedStaff('cpms_secretary', [self::CLINIC_A]);

        $res = $this->dispatch('GET', self::NS . '/secretary/today', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]);
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
        $this->seedStaff('cpms_secretary', [self::CLINIC_B]);

        $res = $this->dispatch('GET', self::NS . '/secretary/today', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_B]);
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
        $this->seedStaff('cpms_secretary', [self::CLINIC_A, self::CLINIC_B]);

        $inA = $this->queueIds($this->dispatch('GET', self::NS . '/secretary/today', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]));
        $this->assertSame([$visitA], $inA, 'context A دقیقاً دامنهٔ A');

        $inB = $this->queueIds($this->dispatch('GET', self::NS . '/secretary/today', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_B]));
        $this->assertSame([$visitB], $inB, 'context B دقیقاً دامنهٔ B');

        $back = $this->queueIds($this->dispatch('GET', self::NS . '/secretary/today', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]));
        $this->assertSame([$visitA], $back, 'پس از سوییچ برگشتی، دامنه باید دوباره فقط A باشد');
    }

    /** ۱۶) Today هیچ وابستگی به Clinic id=1 ندارد (کلید صریحاً غیر‌۱). */
    public function testQueueDoesNotDependOnClinicIdOne(): void
    {
        $visitA = $this->seedQueueRow(self::CLINIC_A, $this->locA);
        $this->seedStaff('cpms_secretary', [self::CLINIC_A]);

        $res = $this->dispatch('GET', self::NS . '/secretary/today', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]);
        $this->assertSame(200, $res->get_status(), $this->body($res));
        $this->assertContains($visitA, $this->queueIds($res), 'دادهٔ Clinic غیر‌۱ باید دیده شود');
        $this->assertNull(ScopeContext::tryGet(), 'Scope پس از درخواست restore می‌شود');
    }

    /** ۱۷) Organization دیگر در Today دیده نمی‌شود. */
    public function testQueueDoesNotCrossOrganizationBoundary(): void
    {
        $locC = $this->insertLocation(self::CLINIC_C, 'iso-loc-c');
        $visitC = $this->seedQueueRow(self::CLINIC_C, $locC);
        $visitA = $this->seedQueueRow(self::CLINIC_A, $this->locA);
        $this->seedStaff('cpms_secretary', [self::CLINIC_A]);

        $ids = $this->queueIds($this->dispatch('GET', self::NS . '/secretary/today', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]));
        $this->assertContains($visitA, $ids);
        $this->assertNotContains($visitC, $ids, 'ردیف Organization دیگر نباید در Today باشد');
    }

    /** ۱۸) نبودِ Trusted Context روی مسیر صف ⇒ fail‑closed (نه «Clinic 1» ضمنی). */
    public function testQueueWithoutTrustedContextFailsClosed(): void
    {
        $this->seedQueueRow(self::CLINIC_A, $this->locA);
        $this->seedStaff('cpms_secretary', [self::CLINIC_A, self::CLINIC_B]);

        $res = $this->dispatch('GET', self::NS . '/secretary/today');
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
        $this->seedStaff('cpms_secretary', [self::CLINIC_A]);

        $res = $this->dispatch('GET', self::NS . '/rt/queue', ['since' => 0], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]);
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
        // **عمداً یک Clinician** (u_clinician_user سالم) و ویزیتی در Clinic دیگر
        // که clinician_id همان پزشک است ⇒ فیلتر clinician تنها آن را رد نمی‌کند.
        $clinicianA = $this->insertClinician(self::CLINIC_A, $doctor, 'Dr Iso A');
        $visitA = $this->seedVisit(self::CLINIC_A, $this->locA, $clinicianA, $patA);
        $visitB = $this->seedVisit(self::CLINIC_B, $this->locB, $clinicianA, $patB);

        $resA = $this->dispatch('GET', self::NS . '/doctor/today', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]);
        $this->assertSame(200, $resA->get_status(), $this->body($resA));
        $this->assertContains($visitA, $this->queueIds($resA));
        $this->assertNotContains($visitB, $this->queueIds($resA), 'ویزیتِ همان پزشک در Clinic دیگر نباید union شود');

        // در context B (با همان یک Clinician) — داده‌های A نباید بیایند
        $resB = $this->dispatch('GET', self::NS . '/doctor/today', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_B]);
        $this->assertSame(200, $resB->get_status(), $this->body($resB));
        $this->assertNotContains($visitA, $this->queueIds($resB));
    }

    // =====================================================================
    // fixtures
    // =====================================================================

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
    private function seedStaff(string $role, array $clinicIds): int
    {
        $userId = $this->makeUser('iso_' . str_replace('cpms_', '', $role), $role);
        foreach ($clinicIds as $clinicId) {
            cpms_test_seed_membership($userId, $clinicId, $role);
        }
        wp_set_current_user($userId);

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
                '0912' . sprintf('%07d', $seq % 10000000),
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
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (%d, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $name,
                $wpUserId,
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
    private function seedFile(int $clinicId, int $patientId, string $visibility, string $filename, ?int $visitId = null): int
    {
        $staff = $this->seedStaff('cpms_secretary', [$clinicId]);
        $file = $this->makeUploadedFile($filename, $this->pdfContent());

        $row = App::medicalFileService()->upload($staff, $file, $patientId, $visitId, 'document', $visibility);
        $id = (int) $row['id'];
        self::assertGreaterThan(0, $id, 'پیش‌شرط: آپلود فایل از مسیر Product');

        return $id;
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
    private function dispatch(string $method, string $route, array $body = [], array $headers = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        foreach ($headers as $name => $value) {
            $request->set_header($name, $value);
        }

        return rest_do_request($request);
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
        self::assertSame('', (string) $res->get_header('Content-Disposition'), 'هدر دانلود فایل نباید در پاسخ رد باشد');
    }
}
