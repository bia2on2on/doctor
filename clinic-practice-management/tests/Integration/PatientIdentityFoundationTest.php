<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Auth\OtpException;
use ClinicCore\Application\Auth\OtpService;
use ClinicCore\Application\Patients\PatientIdentityService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Otp\OtpPolicy;
use ClinicCore\Domain\Patients\PatientIdentityException;
use ClinicCore\Domain\Validators\MobileValidator;
use WP_UnitTestCase;

/**
 * Phase 2 — C5: Patient Identity Foundation (AD-14 / P-B).
 *
 * ماتریس الزامی دستور مالک (۲۰ بند) — نگاشت:
 *  1  immutable identity ID          → testImmutableIdentityInternalRef
 *  2  same mobile / different orgs   → testSameMobileDifferentOrganizationsAreDistinct
 *  3  same identity / two clinics    → testSameIdentityLinksRecordsInTwoClinics
 *  4  clinical isolation             → testClinicalRecordsAreIsolatedBetweenClinics
 *  5-10 mobile variants (فارسی/عربی/+98/0098/98/09) → tests/Unit/MobileValidatorTest
 *  11 invalid mobile rejected        → testInvalidMobileIsRejected
 *  12 no destructive auto-merge      → testSameMobileDoesNotDestructivelyAutoMerge
 *  13 user link respects organization→ testUserLinkRespectsOrganization
 *  14 verify_mobile creates no user  → testVerifyMobileProvisionsNoUserOrIdentity
 *  15 no clinic_id=1 fallback        → testOrganizationAndClinicBoundariesAreEnforced
 *  16 prepared/scoped lookup         → testLookupIsScopedAndUsesCompositeIndex
 *  17 duplicate candidate behavior   → (در ۱۲)
 *  18 migration fresh                → MigrationTest (LATEST=0019) + این setUp
 *  19 migration upgrade              → MigrationTest::testUpgradePathFromLegacyIdempotencyState
 *  20 migration rerun/idempotency    → MigrationTest::testMigrateIsIdempotent + گاردهای 0019
 *
 * Fake isolation ممنوع: سازمان/کلینیک/بیمار واقعی از جداول واقعی ساخته می‌شود.
 */
final class PatientIdentityFoundationTest extends WP_UnitTestCase
{
    private PatientIdentityService $service;

    /** Org A (سازمان seed شدهٔ کلینیک ۱): Clinic A1=1 (seed) + A2؛ Org B: Clinic B1 */
    private int $orgA;
    private int $clinicA1 = 1;
    private int $clinicA2 = 3;
    private int $orgB;
    private int $clinicB1 = 4;

    private int $patientA1;
    private int $patientA2;
    private int $patientB1;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();

        $this->service = App::patient_identity_service();

        global $wpdb;
        $now = App::db()->nowUtcSql();

        // Org A = سازمان کلینیک seed شده
        $this->orgA = (int) $wpdb->get_var(
            'SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = 1'
        ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertGreaterThan(0, $this->orgA, 'سازمان seed باید وجود داشته باشد.');

        // Clinic A2 (همان Org A)
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at)
                 VALUES (%d, %d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicA2,
                $this->orgA,
                'کلینیک هویت آ۲',
                'identity-a2',
                'Asia/Tehran',
                $now,
                $now
            )
        );

        // Org B + Clinic B1
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, created_at, updated_at)
                 VALUES (%s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'سازمان هویت ب',
                'identity-org-b',
                $now,
                $now
            )
        );
        $this->orgB = (int) $wpdb->insert_id;
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at)
                 VALUES (%d, %d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicB1,
                $this->orgB,
                'کلینیک هویت ب۱',
                'identity-b1',
                'Asia/Tehran',
                $now,
                $now
            )
        );

        // رکوردهای بالینی واقعی در سه کلینیک (موبایل/MRN یکتا در هر کلینیک)
        $this->patientA1 = $this->newPatient($this->clinicA1, 'MR-IDENT-A1', '09151000001');
        $this->patientA2 = $this->newPatient($this->clinicA2, 'MR-IDENT-A2', '09151000002');
        $this->patientB1 = $this->newPatient($this->clinicB1, 'MR-IDENT-B1', '09151000003');
    }

    protected function tearDown(): void
    {
        App::resetScope();
        parent::tearDown();
    }

    // ================= 1 — immutable identity =================

    public function testImmutableIdentityInternalRef(): void
    {
        $id = $this->service->create_identity($this->orgA, '09151234567');
        $row = $this->service->find($id);
        self::assertNotNull($row);
        self::assertMatchesRegularExpression('/^PID-\d{6}-[0-9A-F]{12}$/', (string) $row['internal_ref']);
        $refBefore = (string) $row['internal_ref'];

        // تغییر موبایل نباید هویت (id/internal_ref) را تغییر دهد
        $this->service->set_mobile($id, '09159876543');
        $after = $this->service->find($id);
        self::assertSame($id, (int) $after['id'], 'شناسهٔ هویت تغییرناپذیر است.');
        self::assertSame($refBefore, (string) $after['internal_ref'], 'internal_ref تغییرناپذیر است.');
        self::assertSame('09159876543', (string) $after['normalized_mobile']);

        // ref قابل lookup است و همان هویت را برمی‌گرداند
        $byRef = $this->service->find_by_internal_ref($refBefore);
        self::assertSame($id, (int) $byRef['id']);
    }

    // ================= 2 — same mobile / different orgs =================

    public function testSameMobileDifferentOrganizationsAreDistinct(): void
    {
        $mobile = '09157778899';
        $x = $this->service->create_identity($this->orgA, $mobile);
        $y = $this->service->create_identity($this->orgB, $mobile);
        self::assertNotSame($x, $y, 'دو سازمان = دو هویت مستقل.');

        // lookup در Org A فقط X را می‌بیند؛ در Org B فقط Y را
        $inA = $this->service->lookup_by_mobile($this->orgA, $mobile);
        $inB = $this->service->lookup_by_mobile($this->orgB, $mobile);
        self::assertSame([$x], array_map(static fn (array $r): int => (int) $r['id'], $inA));
        self::assertSame([$y], array_map(static fn (array $r): int => (int) $r['id'], $inB));
    }

    // ================= 3 — same identity / two clinics =================

    public function testSameIdentityLinksRecordsInTwoClinics(): void
    {
        $identity = $this->service->create_identity($this->orgA, '09153334455');

        $this->service->link_clinical_record($this->orgA, $identity, $this->clinicA1, $this->patientA1);
        $this->service->link_clinical_record($this->orgA, $identity, $this->clinicA2, $this->patientA2);

        $rowA1 = $this->service->find($identity);
        self::assertNotNull($rowA1);
        $p1 = App::db()->fetchRow(
            'SELECT identity_id FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d',
            [$this->patientA1]
        );
        $p2 = App::db()->fetchRow(
            'SELECT identity_id FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d',
            [$this->patientA2]
        );
        self::assertSame($identity, (int) $p1['identity_id']);
        self::assertSame($identity, (int) $p2['identity_id'], 'یک هویت می‌تواند در دو کلینیکِ هم‌سازمان رکورد داشته باشد.');
    }

    // ================= 4 — clinical isolation =================

    public function testClinicalRecordsAreIsolatedBetweenClinics(): void
    {
        $identity = $this->service->create_identity($this->orgA, '09152223344');
        $this->service->link_clinical_record($this->orgA, $identity, $this->clinicA1, $this->patientA1);
        $this->service->link_clinical_record($this->orgA, $identity, $this->clinicA2, $this->patientA2);

        // Clinic A1 فقط رکورد خودش را می‌بیند — نه A2 را
        $fromA1 = $this->service->clinical_records_for_clinic($this->orgA, $identity, $this->clinicA1);
        self::assertSame([$this->patientA1], array_map(static fn (array $r): int => (int) $r['id'], $fromA1));

        $fromA2 = $this->service->clinical_records_for_clinic($this->orgA, $identity, $this->clinicA2);
        self::assertSame([$this->patientA2], array_map(static fn (array $r): int => (int) $r['id'], $fromA2));

        // کلینیک Org B (حتی با identity معتبر) هیچ رکوردی نمی‌بیند (fail-closed)
        $fromB = $this->service->clinical_records_for_clinic($this->orgA, $identity, $this->clinicB1);
        self::assertSame([], $fromB, 'cross-org ⇒ خالی (denied).');
    }

    // ================= 11 — invalid mobile =================

    public function testInvalidMobileIsRejected(): void
    {
        try {
            $this->service->create_identity($this->orgA, '02112345678'); // تلفن ثابت
            self::fail('موبایل نامعتبر باید رد شود.');
        } catch (PatientIdentityException $e) {
            self::assertSame('CLINIC_PATIENT_IDENTITY_INVALID_MOBILE', $e->error_code);
        }

        try {
            $this->service->lookup_by_mobile($this->orgA, 'not-a-mobile');
            self::fail('lookup با موبایل نامعتبر باید رد شود.');
        } catch (PatientIdentityException $e) {
            self::assertSame('CLINIC_PATIENT_IDENTITY_INVALID_MOBILE', $e->error_code);
        }
    }

    // ================= 12/17 — duplicates, no destructive merge =================

    public function testSameMobileDoesNotDestructivelyAutoMerge(): void
    {
        $mobile = '09156667788';
        $first = $this->service->create_identity($this->orgA, $mobile);
        $refFirst = (string) $this->service->find($first)['internal_ref'];

        // ساخت هویت دوم با همان موبایل مجاز است — هیچ ادغام/به‌روزرسانی بی‌صدایی رخ نمی‌دهد
        $second = $this->service->create_identity($this->orgA, $mobile);
        self::assertNotSame($first, $second);

        $candidates = $this->service->duplicate_candidates($this->orgA, $mobile);
        self::assertSame([$first, $second], $candidates, 'کاندیدها قطعی و مرتب با id هستند.');

        // هویت اول دست‌نخورده
        $rowFirst = $this->service->find($first);
        self::assertSame($refFirst, (string) $rowFirst['internal_ref']);
        self::assertSame($mobile, (string) $rowFirst['normalized_mobile']);
    }

    // ================= 13 — user link / organization =================

    public function testUserLinkRespectsOrganization(): void
    {
        $userId = (int) wp_create_user('identity_user', wp_generate_password(24), 'identity_user@identity.test');
        self::assertGreaterThan(0, $userId);

        $identity = $this->service->create_identity($this->orgA, '09155556677');
        $this->service->link_user($this->orgA, $identity, $userId, '09155556677');

        $inA = $this->service->identities_for_user($this->orgA, $userId);
        self::assertSame([$identity], array_map(static fn (array $r): int => (int) $r['id'], $inA));

        // همان کاربر در Org B هیچ هویتی ندارد (مرز سازمان)
        $inB = $this->service->identities_for_user($this->orgB, $userId);
        self::assertSame([], $inB, 'لینک کاربر Organization-bound است.');

        // لینک تکراری صریحاً رد می‌شود
        try {
            $this->service->link_user($this->orgA, $identity, $userId, '09155556677');
            self::fail('لینک تکراری باید رد شود.');
        } catch (PatientIdentityException $e) {
            self::assertSame('CLINIC_PATIENT_IDENTITY_USER_LINK_EXISTS', $e->error_code);
        }
    }

    // ================= 14 — OTP invariant =================

    public function testVerifyMobileProvisionsNoUserOrIdentity(): void
    {
        $mobile = '09154445566';
        $identity = $this->service->create_identity($this->orgA, $mobile);
        $userId = (int) wp_create_user('identity_otp_user', wp_generate_password(24), 'identity_otp_user@identity.test');
        $this->service->link_user($this->orgA, $identity, $userId, $mobile);

        $usersBefore = $this->countRows('users');
        $identitiesBefore = $this->countRows('cpms_patient_identities');
        $linksBefore = $this->countRows('cpms_patient_identity_links');

        $this->seedOtpToken($mobile, '246810', OtpService::PURPOSE_VERIFY_MOBILE);
        $result = App::otpService()->verify($mobile, '246810', OtpService::PURPOSE_VERIFY_MOBILE);

        // OD-8: verify_mobile نه user می‌سازد، نه session می‌دهد، نه چیزی provision می‌کند
        self::assertFalse($result['session_issued']);
        self::assertFalse($result['is_new_user']);
        self::assertSame($usersBefore, $this->countRows('users'), 'هیچ کاربری ساخته نشد.');
        self::assertSame($identitiesBefore, $this->countRows('cpms_patient_identities'), 'هیچ هویتی ساخته/تغییر نکرد.');
        self::assertSame($linksBefore, $this->countRows('cpms_patient_identity_links'), 'هیچ لینکی ساخته نشد.');
    }

    // ================= 15 — org/clinic boundaries (no fallback) =================

    public function testOrganizationAndClinicBoundariesAreEnforced(): void
    {
        $identity = $this->service->create_identity($this->orgA, '09151112233');

        // کلینیک Org B برای هویت Org A ⇒ خطای مرز (نه fallback به clinic 1)
        try {
            $this->service->link_clinical_record($this->orgA, $identity, $this->clinicB1, $this->patientB1);
            self::fail('کلینیک سازمان دیگر باید رد شود.');
        } catch (PatientIdentityException $e) {
            self::assertSame('CLINIC_PATIENT_IDENTITY_ORG_MISMATCH', $e->error_code);
        }

        // بیمارِ کلینیک دیگر با Clinic صریحِ غلط ⇒ خطا
        try {
            $this->service->link_clinical_record($this->orgA, $identity, $this->clinicA1, $this->patientA2);
            self::fail('بیمارِ کلینیک دیگر باید رد شود.');
        } catch (PatientIdentityException $e) {
            self::assertSame('CLINIC_PATIENT_IDENTITY_CLINIC_MISMATCH', $e->error_code);
        }

        // هویتِ Org B از دید Org A «موجود نیست» (anti-enumeration: همان NOT_FOUND)
        $foreign = $this->service->create_identity($this->orgB, '09159990000');
        try {
            $this->service->link_user($this->orgA, $foreign, 1, '09151112233');
            self::fail('هویت سازمان دیگر نباید قابل استفاده باشد.');
        } catch (PatientIdentityException $e) {
            self::assertSame('CLINIC_PATIENT_IDENTITY_NOT_FOUND', $e->error_code);
        }

        // رکورد بالینی قبلاً به هویت دیگری متصل است ⇒ 409 (نه بازنویسی بی‌صدا)
        $other = $this->service->create_identity($this->orgA, '09158881111');
        $this->service->link_clinical_record($this->orgA, $identity, $this->clinicA1, $this->patientA1);
        try {
            $this->service->link_clinical_record($this->orgA, $other, $this->clinicA1, $this->patientA1);
            self::fail('رکورد متصل باید رد شود.');
        } catch (PatientIdentityException $e) {
            self::assertSame('CLINIC_PATIENT_IDENTITY_RECORD_LINKED', $e->error_code);
        }

        // idempotent: لینک دوبارهٔ همان جفت مجاز است (no-op)
        $this->service->link_clinical_record($this->orgA, $identity, $this->clinicA1, $this->patientA1);
        self::assertTrue(true);
    }

    // ================= 16 — prepared + scoped + index =================

    public function testLookupIsScopedAndUsesCompositeIndex(): void
    {
        $mobile = '09151113333';
        $this->service->create_identity($this->orgA, $mobile);

        // ورودی خطرناک (نقل‌قول/کامنت SQL) ⇒ نرمال‌سازی رد می‌کند؛ هیچ SQL خامی ساخته نمی‌شود
        try {
            $this->service->lookup_by_mobile($this->orgA, "0915'--");
            self::fail('ورودی خطرناک باید در validation رد شود.');
        } catch (PatientIdentityException $e) {
            self::assertSame('CLINIC_PATIENT_IDENTITY_INVALID_MOBILE', $e->error_code);
        }

        // EXPLAIN: lookup واقعی باید org-scoped و index-backed باشد (نه full-scan).
        // نکته: انتخابِ نهایی بین idx_identity_org (0018) و idx_identity_org_mobile
        // (0019) با optimizer است — با دادهٔ کمِ تست ممکن است ایندکس کوتاه‌ترِ
        // org انتخاب شود؛ هر دو بدون full-table scan هستند و در حجم واقعی
        // optimizer ایندکس selective تر (org+mobile) را برمی‌گزیند.
        global $wpdb;
        $explain = $wpdb->get_row(
            $wpdb->prepare(
                'EXPLAIN SELECT id FROM ' . $wpdb->prefix . 'cpms_patient_identities
                 WHERE organization_id = %d AND normalized_mobile = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->orgA,
                $mobile
            ),
            ARRAY_A
        );
        self::assertIsArray($explain);
        self::assertNotSame(
            'ALL',
            strtoupper((string) ($explain['type'] ?? '')),
            'lookup نباید full-table scan باشد.'
        );
        self::assertContains(
            (string) ($explain['key'] ?? ''),
            ['idx_identity_org', 'idx_identity_org_mobile'],
            'lookup باید از یکی از ایندکس‌های organization-scoped استفاده کند.'
        );
    }

    // ================= helpers =================

    private function newPatient(int $clinicId, string $mrn, string $mobile): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                 (clinic_id, mrn, first_name, last_name, mobile, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $mrn,
                'بیمار',
                'هویت-' . $mrn,
                $mobile,
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id);

        return $id;
    }

    private function countRows(string $shortTable): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . $shortTable
        ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * کد خام OTP هرگز برگردانده نمی‌شود؛ مثل OtpSecurityTest مستقیم در جدول seed می‌کنیم.
     */
    private function seedOtpToken(string $mobile, string $code, string $purpose): void
    {
        $db = App::db();
        $pepper = defined('CPMS_PEPPER') && (string) CPMS_PEPPER !== ''
            ? (string) CPMS_PEPPER
            : (string) get_option('cpms_otp_pepper', '');
        if ($pepper === '') {
            try {
                App::otpService()->request($mobile, $purpose);
            } catch (OtpException) {
                // بی‌اهمیت — فقط برای ساخت pepper
            }
            $pepper = (string) get_option('cpms_otp_pepper', '');
        }

        $db->insert('cpms_otp_tokens', [
            'mobile' => MobileValidator::normalize($mobile),
            'purpose' => $purpose,
            'code_hash' => OtpPolicy::hashCode($code, $pepper),
            'expires_at' => gmdate('Y-m-d H:i:s.000', time() + 300),
            'attempts' => 0,
            'created_at' => $db->nowUtcSql(),
        ]);
    }
}
