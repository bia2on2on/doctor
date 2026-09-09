<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Membership\MembershipService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Membership\MembershipException;
use WP_UnitTestCase;

/**
 * Phase 2 — C4: Membership primitives (P2-D1 / ADR-0031).
 *
 * مدل مصوب:
 *   WP User → حداکثر یک Clinician Profile
 *   User ↔ چند Clinic از طریق Membership (رابطهٔ واقعی)
 *   Doctor ↔ چند Location از طریق تخصیص صریح
 *
 * الزامات تست (دستور مالک):
 *  - یک user در دو clinic (دو membership)
 *  - یک clinician profile یکتا
 *  - location assignmentهای متفاوت
 *  - عضویت در A هیچ دسترسی ضمنی به B نمی‌دهد
 *  - رفتار duplicate membership
 *  - semantics عضویت suspend/تعلیق
 *  - fail-closed: تخصیص Location برخلاف scope کلینیک
 */
final class MembershipPrimitivesTest extends WP_UnitTestCase
{
    private MembershipService $service;

    /** Clinic 1 (seed) → L1 (primary seeded) + L1b؛ Clinic 2 → L2 */
    private int $clinicA = 1;
    private int $clinicB = 2;
    private int $locA1;
    private int $locA1b;
    private int $locB1;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();

        $this->service = App::membershipService();

        // Clinic دوم — سازمان از DB resolve (ERROR 1093: زیرکوئری روی جدول هدف ممنوع)
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $orgId = (int) $wpdb->get_var(
            'SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = 1'
        ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $ok = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at)
                 VALUES (%d, %d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                2,
                $orgId,
                'کلینیک عضویت ب',
                'membership-b',
                'Asia/Tehran',
                $now,
                $now
            )
        );
        self::assertNotFalse($ok, 'کلینیک دوم باید ساخته شود.');

        // Locationهای واقعی (FK) — یکی برای A (دوم) و یکی برای B
        foreach ([['membership-loc-a1', 1], ['membership-loc-a1b', 1], ['membership-loc-b1', 2]] as [$slug, $clinicId]) {
            $wpdb->query(
                $wpdb->prepare(
                    'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)
                     VALUES (%d, %s, %s, %s, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $clinicId,
                    'موقعیت ' . $slug,
                    $slug,
                    'Asia/Tehran',
                    $now,
                    $now
                )
            );
        }
        $this->locA1 = (int) $wpdb->get_var('SELECT id FROM ' . $wpdb->prefix . "cpms_locations WHERE slug = 'membership-loc-a1'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->locA1b = (int) $wpdb->get_var('SELECT id FROM ' . $wpdb->prefix . "cpms_locations WHERE slug = 'membership-loc-a1b'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->locB1 = (int) $wpdb->get_var('SELECT id FROM ' . $wpdb->prefix . "cpms_locations WHERE slug = 'membership-loc-b1'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertGreaterThan(0, $this->locA1 * $this->locA1b * $this->locB1, 'Locationها باید ساخته شوند.');
    }

    protected function tearDown(): void
    {
        App::resetScope();
        parent::tearDown();
    }

    private function newUser(string $login): int
    {
        $id = (int) wp_create_user($login, wp_generate_password(24), $login . '@membership.test');
        self::assertGreaterThan(0, $id);

        return $id;
    }

    private function newClinicianFor(int $wpUserId, int $clinicId, string $name): int
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

        return (int) $wpdb->insert_id;
    }

    public function testOneUserHoldsMembershipsInTwoClinicsIndependently(): void
    {
        $uid = $this->newUser('member_dual');

        $membershipA = $this->service->createMembership($this->clinicA, $uid, 'cpms_doctor');
        $membershipB = $this->service->createMembership($this->clinicB, $uid, 'cpms_manager');

        self::assertNotSame($membershipA, $membershipB, 'دو عضویت مستقل.');

        // عضویت فعال در هر دو کلینیک — با roleهای متفاوت
        $activeA = $this->service->activeMembershipFor($this->clinicA, $uid);
        $activeB = $this->service->activeMembershipFor($this->clinicB, $uid);
        self::assertNotNull($activeA);
        self::assertNotNull($activeB);
        self::assertSame('cpms_doctor', $activeA['role_key']);
        self::assertSame('cpms_manager', $activeB['role_key']);

        // فهرست Clinicهای فعال = هر دو
        self::assertSame([$this->clinicA, $this->clinicB], $this->service->activeClinicIdsForUser($uid));
    }

    public function testClinicianProfileIsUniquePerUserAcrossClinics(): void
    {
        $uid = $this->newUser('member_profile');
        $this->newClinicianFor($uid, $this->clinicA, 'دکتر یکتا');

        // پروفایل دوم برای همان کاربر (حتی در کلینیک دیگر) — u_clinician_user رد می‌کند
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $ok = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (%d, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicB,
                'دکتر تکراری',
                $uid,
                $now,
                $now
            )
        );
        self::assertFalse($ok, 'دو Clinician Profile برای یک WP User ممنوع (u_clinician_user).');
    }

    public function testMembershipInClinicAImplicitlyGrantsNothingInClinicB(): void
    {
        $uid = $this->newUser('member_isolation');

        // فقط عضویت در A
        $this->service->createMembership($this->clinicA, $uid, 'cpms_secretary');

        self::assertNotNull($this->service->activeMembershipFor($this->clinicA, $uid));
        self::assertNull(
            $this->service->activeMembershipFor($this->clinicB, $uid),
            'عضویت در A نباید در B دسترسی ضمنی بسازد.'
        );
        self::assertSame([$this->clinicA], $this->service->activeClinicIdsForUser($uid));

        // کلینیک سوم هم نه
        self::assertNull($this->service->activeMembershipFor(3, $uid));
    }

    public function testDuplicateMembershipIsRejectedExplicitly(): void
    {
        $uid = $this->newUser('member_dup');

        $first = $this->service->createMembership($this->clinicA, $uid, 'cpms_doctor');

        try {
            $this->service->createMembership($this->clinicA, $uid, 'cpms_manager');
            self::fail('عضویت تکراری باید رد شود.');
        } catch (MembershipException $e) {
            self::assertSame('CLINIC_MEMBERSHIP_DUPLICATE', $e->errorCode);
        }

        // عضویت اول دست‌نخورده (نه update بی‌صدا)
        $survivor = $this->service->activeMembershipFor($this->clinicA, $uid);
        self::assertSame((int) $survivor['id'], $first);
        self::assertSame('cpms_doctor', $survivor['role_key'], 'role نباید بی‌صدا تغییر کند.');
    }

    public function testSuspendedMembershipGrantsNothingUntilReactivated(): void
    {
        $uid = $this->newUser('member_suspend');
        $membershipId = $this->service->createMembership($this->clinicA, $uid, 'cpms_accountant');

        // تعلیق: دسترسی فوراً قطع، رکورد باقی، ردیف هنوز هست (membershipFor هر وضعیت)
        $this->service->suspendMembership($membershipId);
        self::assertNull($this->service->activeMembershipFor($this->clinicA, $uid), 'suspended = بدون دسترسی.');
        $row = $this->service->membershipFor($this->clinicA, $uid);
        self::assertNotNull($row, 'ردیف عضویت حفظ می‌شود.');
        self::assertSame('suspended', $row['status']);

        // تعلیق دوباره = انتقال نامعتبر
        try {
            $this->service->suspendMembership($membershipId);
            self::fail('تعلیقِ عضویتِ تعلیق‌شده باید خطای انتقال بدهد.');
        } catch (MembershipException $e) {
            self::assertSame('CLINIC_MEMBERSHIP_INVALID_TRANSITION', $e->errorCode);
        }

        // فعال‌سازی مجدد: دسترسی برمی‌گردد
        $this->service->reactivateMembership($membershipId);
        self::assertNotNull($this->service->activeMembershipFor($this->clinicA, $uid));
        self::assertSame([$this->clinicA], $this->service->activeClinicIdsForUser($uid));
    }

    public function testLocationAssignmentsArePerMembershipAndClinicScoped(): void
    {
        $uid = $this->newUser('member_locations');
        $membershipA = $this->service->createMembership($this->clinicA, $uid, 'cpms_secretary');
        $membershipB = $this->service->createMembership($this->clinicB, $uid, 'cpms_secretary');

        // A: location-scoped به یکی از Locationهای خودش
        $this->service->setScopeMode($membershipA, 'location', [$this->locA1]);
        self::assertSame([$this->locA1], $this->service->membershipLocationIds($membershipA));

        // B: Clinic-wide (بدون Location)
        self::assertSame([], $this->service->membershipLocationIds($membershipB));

        // Location متعلق به B برای عضویت A — رد (fail-closed)
        try {
            $this->service->syncMembershipLocations($membershipA, [$this->locB1]);
            self::fail('Location کلینیک دیگر نباید به عضویت A تخصیص یابد.');
        } catch (MembershipException $e) {
            self::assertSame('CLINIC_MEMBERSHIP_LOCATION_MISMATCH', $e->errorCode);
        }

        // عضویت clinic-scoped نمی‌تواند Location بگیرد (مبهم)
        try {
            $this->service->syncMembershipLocations($membershipB, [$this->locB1]);
            self::fail('عضویت clinic-scoped باید برای تخصیص Location رد شود.');
        } catch (MembershipException $e) {
            self::assertSame('CLINIC_MEMBERSHIP_SCOPE_MODE', $e->errorCode);
        }

        // بازگشت به clinic-wide: Locationها خالی می‌شوند
        $this->service->setScopeMode($membershipA, 'clinic');
        self::assertSame([], $this->service->membershipLocationIds($membershipA));
    }

    public function testOnePrimaryMembershipPerUser(): void
    {
        $uid = $this->newUser('member_primary');
        $membershipA = $this->service->createMembership($this->clinicA, $uid, 'cpms_doctor');
        $membershipB = $this->service->createMembership($this->clinicB, $uid, 'cpms_doctor');

        // پیش‌فرض: هیچ primary نیست
        self::assertSame(0, (int) $this->service->membershipFor($this->clinicA, $uid)['is_primary']);

        $this->service->setPrimaryMembership($this->clinicB, $uid);
        self::assertSame(0, (int) $this->service->membershipFor($this->clinicA, $uid)['is_primary']);
        self::assertSame(1, (int) $this->service->membershipFor($this->clinicB, $uid)['is_primary']);

        // سوییچ primary
        $this->service->setPrimaryMembership($this->clinicA, $uid);
        self::assertSame(1, (int) $this->service->membershipFor($this->clinicA, $uid)['is_primary']);
        self::assertSame(0, (int) $this->service->membershipFor($this->clinicB, $uid)['is_primary']);
        self::assertNotSame($membershipA, $membershipB);
    }

    public function testCapabilityPrimitivesForPhaseThreeMetadata(): void
    {
        $uid = $this->newUser('member_caps');
        $membershipId = $this->service->createMembership($this->clinicA, $uid, 'cpms_secretary');

        $this->service->setCapability($membershipId, 'booking.create', 'grant');
        $this->service->setCapability($membershipId, 'reports.export', 'deny');
        // upsert همان capability
        $this->service->setCapability($membershipId, 'booking.create', 'deny');

        $caps = $this->service->capabilitiesFor($membershipId);
        self::assertSame(
            [
                ['capability' => 'booking.create', 'effect' => 'deny'],
                ['capability' => 'reports.export', 'effect' => 'deny'],
            ],
            array_values($caps)
        );

        $this->service->removeCapability($membershipId, 'booking.create');
        self::assertSame(
            [['capability' => 'reports.export', 'effect' => 'deny']],
            array_values($this->service->capabilitiesFor($membershipId))
        );

        // قالب نامعتبر capability
        try {
            $this->service->setCapability($membershipId, 'Bad-Cap', 'grant');
            self::fail('capability با قالب نامعتبر باید رد شود.');
        } catch (MembershipException $e) {
            self::assertSame('CLINIC_MEMBERSHIP_INVALID_VALUE', $e->errorCode);
        }
    }

    public function testClinicianLocationsFollowActiveMemberships(): void
    {
        $uid = $this->newUser('member_clinician_locations');
        $clinicianId = $this->newClinicianFor($uid, $this->clinicA, 'دکتر چند‌کلینیکی');

        // بدون عضویت فعال: هیچ Locationای قابل تخصیص نیست (fail-closed)
        try {
            $this->service->assignClinicianLocations($clinicianId, [$this->locA1]);
            self::fail('بدون عضویت فعال نباید Location تخصیص یابد.');
        } catch (MembershipException $e) {
            self::assertSame('CLINIC_CLINICIAN_LOCATION_MISMATCH', $e->errorCode);
        }

        // عضویت در A و B → Locationهای هر دو مجاز
        $this->service->createMembership($this->clinicA, $uid, 'cpms_doctor');
        $this->service->createMembership($this->clinicB, $uid, 'cpms_doctor');

        $this->service->assignClinicianLocations($clinicianId, [$this->locA1, $this->locB1], $this->locB1);
        self::assertSame([$this->locB1, $this->locA1], $this->service->clinicianLocationIds($clinicianId));
        self::assertSame($this->locB1, $this->service->primaryClinicianLocationId($clinicianId));

        // تعلیق عضویت B: Locationهای B دیگر مجاز نیستند (تخصیص مجدد فقط با A)
        $membershipB = (int) $this->service->membershipFor($this->clinicB, $uid)['id'];
        $this->service->suspendMembership($membershipB);
        $this->service->assignClinicianLocations($clinicianId, [$this->locA1, $this->locA1b]);
        self::assertSame([$this->locA1, $this->locA1b], $this->service->clinicianLocationIds($clinicianId));

        // ...اما Location کلینیک B حالا رد می‌شود
        try {
            $this->service->assignClinicianLocations($clinicianId, [$this->locB1]);
            self::fail('با تعلیق عضویت B، Locationهای B باید رد شوند.');
        } catch (MembershipException $e) {
            self::assertSame('CLINIC_CLINICIAN_LOCATION_MISMATCH', $e->errorCode);
        }
    }

    public function testInvalidInputsAreRejected(): void
    {
        $uid = $this->newUser('member_invalid');

        // role_key بدقالب
        try {
            $this->service->createMembership($this->clinicA, $uid, 'Bad Role!');
            self::fail('role_key بدقالب باید رد شود.');
        } catch (MembershipException $e) {
            self::assertSame('CLINIC_MEMBERSHIP_INVALID_VALUE', $e->errorCode);
        }

        // کاربر ناموجود
        try {
            $this->service->createMembership($this->clinicA, 9999999, 'cpms_doctor');
            self::fail('کاربر ناموجود باید رد شود.');
        } catch (MembershipException $e) {
            self::assertSame('CLINIC_MEMBERSHIP_NOT_FOUND', $e->errorCode);
        }

        // کلینیک ناموجود
        try {
            $this->service->createMembership(9999999, $uid, 'cpms_doctor');
            self::fail('کلینیک ناموجود باید رد شود.');
        } catch (MembershipException $e) {
            self::assertSame('CLINIC_MEMBERSHIP_NOT_FOUND', $e->errorCode);
        }

        // عضویت ناموجود برای suspend
        try {
            $this->service->suspendMembership(9999999);
            self::fail('عضویت ناموجود باید رد شود.');
        } catch (MembershipException $e) {
            self::assertSame('CLINIC_MEMBERSHIP_NOT_FOUND', $e->errorCode);
        }
    }
}
