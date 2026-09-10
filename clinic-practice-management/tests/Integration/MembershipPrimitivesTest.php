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

        $this->service = App::membership_service();

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
        foreach ([['membership-loc-a1', 1], ['membership-loc-a1b', 1], ['membership-loc-b1', 2]] as [$slug, $clinic_id]) {
            $wpdb->query(
                $wpdb->prepare(
                    'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)
                     VALUES (%d, %s, %s, %s, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $clinic_id,
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

    private function newClinicianFor(int $wp_user_id, int $clinic_id, string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (%d, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinic_id,
                $name,
                $wp_user_id,
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    public function testOneUserHoldsMembershipsInTwoClinicsIndependently(): void
    {
        $uid = $this->newUser('member_dual');

        $membershipA = $this->service->create_membership($this->clinicA, $uid, 'cpms_doctor');
        $membershipB = $this->service->create_membership($this->clinicB, $uid, 'cpms_manager');

        self::assertNotSame($membershipA, $membershipB, 'دو عضویت مستقل.');

        // عضویت فعال در هر دو کلینیک — با roleهای متفاوت
        $activeA = $this->service->active_membership_for($this->clinicA, $uid);
        $activeB = $this->service->active_membership_for($this->clinicB, $uid);
        self::assertNotNull($activeA);
        self::assertNotNull($activeB);
        self::assertSame('cpms_doctor', $activeA['role_key']);
        self::assertSame('cpms_manager', $activeB['role_key']);

        // فهرست Clinicهای فعال = هر دو
        self::assertSame([$this->clinicA, $this->clinicB], $this->service->active_clinic_ids_for_user($uid));
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
        $this->service->create_membership($this->clinicA, $uid, 'cpms_secretary');

        self::assertNotNull($this->service->active_membership_for($this->clinicA, $uid));
        self::assertNull(
            $this->service->active_membership_for($this->clinicB, $uid),
            'عضویت در A نباید در B دسترسی ضمنی بسازد.'
        );
        self::assertSame([$this->clinicA], $this->service->active_clinic_ids_for_user($uid));

        // کلینیک سوم هم نه
        self::assertNull($this->service->active_membership_for(3, $uid));
    }

    public function testDuplicateMembershipIsRejectedExplicitly(): void
    {
        $uid = $this->newUser('member_dup');

        $first = $this->service->create_membership($this->clinicA, $uid, 'cpms_doctor');

        try {
            $this->service->create_membership($this->clinicA, $uid, 'cpms_manager');
            self::fail('عضویت تکراری باید رد شود.');
        } catch (MembershipException $e) {
            self::assertSame('CLINIC_MEMBERSHIP_DUPLICATE', $e->error_code);
        }

        // عضویت اول دست‌نخورده (نه update بی‌صدا)
        $survivor = $this->service->active_membership_for($this->clinicA, $uid);
        self::assertSame((int) $survivor['id'], $first);
        self::assertSame('cpms_doctor', $survivor['role_key'], 'role نباید بی‌صدا تغییر کند.');
    }

    public function testSuspendedMembershipGrantsNothingUntilReactivated(): void
    {
        $uid = $this->newUser('member_suspend');
        $membership_id = $this->service->create_membership($this->clinicA, $uid, 'cpms_accountant');

        // تعلیق: دسترسی فوراً قطع، رکورد باقی، ردیف هنوز هست (membership_for هر وضعیت)
        $this->service->suspend_membership($membership_id);
        self::assertNull($this->service->active_membership_for($this->clinicA, $uid), 'suspended = بدون دسترسی.');
        $row = $this->service->membership_for($this->clinicA, $uid);
        self::assertNotNull($row, 'ردیف عضویت حفظ می‌شود.');
        self::assertSame('suspended', $row['status']);

        // تعلیق دوباره = انتقال نامعتبر
        try {
            $this->service->suspend_membership($membership_id);
            self::fail('تعلیقِ عضویتِ تعلیق‌شده باید خطای انتقال بدهد.');
        } catch (MembershipException $e) {
            self::assertSame('CLINIC_MEMBERSHIP_INVALID_TRANSITION', $e->error_code);
        }

        // فعال‌سازی مجدد: دسترسی برمی‌گردد
        $this->service->reactivate_membership($membership_id);
        self::assertNotNull($this->service->active_membership_for($this->clinicA, $uid));
        self::assertSame([$this->clinicA], $this->service->active_clinic_ids_for_user($uid));
    }

    public function testLocationAssignmentsArePerMembershipAndClinicScoped(): void
    {
        $uid = $this->newUser('member_locations');
        $membershipA = $this->service->create_membership($this->clinicA, $uid, 'cpms_secretary');
        $membershipB = $this->service->create_membership($this->clinicB, $uid, 'cpms_secretary');

        // A: location-scoped به یکی از Locationهای خودش
        $this->service->set_scope_mode($membershipA, 'location', [$this->locA1]);
        self::assertSame([$this->locA1], $this->service->membership_location_ids($membershipA));

        // B: Clinic-wide (بدون Location)
        self::assertSame([], $this->service->membership_location_ids($membershipB));

        // Location متعلق به B برای عضویت A — رد (fail-closed)
        try {
            $this->service->sync_membership_locations($membershipA, [$this->locB1]);
            self::fail('Location کلینیک دیگر نباید به عضویت A تخصیص یابد.');
        } catch (MembershipException $e) {
            self::assertSame('CLINIC_MEMBERSHIP_LOCATION_MISMATCH', $e->error_code);
        }

        // عضویت clinic-scoped نمی‌تواند Location بگیرد (مبهم)
        try {
            $this->service->sync_membership_locations($membershipB, [$this->locB1]);
            self::fail('عضویت clinic-scoped باید برای تخصیص Location رد شود.');
        } catch (MembershipException $e) {
            self::assertSame('CLINIC_MEMBERSHIP_SCOPE_MODE', $e->error_code);
        }

        // بازگشت به clinic-wide: Locationها خالی می‌شوند
        $this->service->set_scope_mode($membershipA, 'clinic');
        self::assertSame([], $this->service->membership_location_ids($membershipA));
    }

    public function testOnePrimaryMembershipPerUser(): void
    {
        $uid = $this->newUser('member_primary');
        $membershipA = $this->service->create_membership($this->clinicA, $uid, 'cpms_doctor');
        $membershipB = $this->service->create_membership($this->clinicB, $uid, 'cpms_doctor');

        // پیش‌فرض: هیچ primary نیست
        self::assertSame(0, (int) $this->service->membership_for($this->clinicA, $uid)['is_primary']);

        $this->service->set_primary_membership($this->clinicB, $uid);
        self::assertSame(0, (int) $this->service->membership_for($this->clinicA, $uid)['is_primary']);
        self::assertSame(1, (int) $this->service->membership_for($this->clinicB, $uid)['is_primary']);

        // سوییچ primary
        $this->service->set_primary_membership($this->clinicA, $uid);
        self::assertSame(1, (int) $this->service->membership_for($this->clinicA, $uid)['is_primary']);
        self::assertSame(0, (int) $this->service->membership_for($this->clinicB, $uid)['is_primary']);
        self::assertNotSame($membershipA, $membershipB);
    }

    public function testCapabilityPrimitivesForPhaseThreeMetadata(): void
    {
        $uid = $this->newUser('member_caps');
        $membership_id = $this->service->create_membership($this->clinicA, $uid, 'cpms_secretary');

        $this->service->set_capability($membership_id, 'booking.create', 'grant');
        $this->service->set_capability($membership_id, 'reports.export', 'deny');
        // upsert همان capability
        $this->service->set_capability($membership_id, 'booking.create', 'deny');

        $caps = $this->service->capabilities_for($membership_id);
        self::assertSame(
            [
                ['capability' => 'booking.create', 'effect' => 'deny'],
                ['capability' => 'reports.export', 'effect' => 'deny'],
            ],
            array_values($caps)
        );

        $this->service->remove_capability($membership_id, 'booking.create');
        self::assertSame(
            [['capability' => 'reports.export', 'effect' => 'deny']],
            array_values($this->service->capabilities_for($membership_id))
        );

        // قالب نامعتبر capability
        try {
            $this->service->set_capability($membership_id, 'Bad-Cap', 'grant');
            self::fail('capability با قالب نامعتبر باید رد شود.');
        } catch (MembershipException $e) {
            self::assertSame('CLINIC_MEMBERSHIP_INVALID_VALUE', $e->error_code);
        }
    }

    public function testClinicianLocationsFollowActiveMemberships(): void
    {
        $uid = $this->newUser('member_clinician_locations');
        $clinician_id = $this->newClinicianFor($uid, $this->clinicA, 'دکتر چند‌کلینیکی');

        // بدون عضویت فعال: هیچ Locationای قابل تخصیص نیست (fail-closed)
        try {
            $this->service->assign_clinician_locations($clinician_id, [$this->locA1]);
            self::fail('بدون عضویت فعال نباید Location تخصیص یابد.');
        } catch (MembershipException $e) {
            self::assertSame('CLINIC_CLINICIAN_LOCATION_MISMATCH', $e->error_code);
        }

        // عضویت در A و B → Locationهای هر دو مجاز
        $this->service->create_membership($this->clinicA, $uid, 'cpms_doctor');
        $this->service->create_membership($this->clinicB, $uid, 'cpms_doctor');

        $this->service->assign_clinician_locations($clinician_id, [$this->locA1, $this->locB1], $this->locB1);
        self::assertSame([$this->locB1, $this->locA1], $this->service->clinician_location_ids($clinician_id));
        self::assertSame($this->locB1, $this->service->primary_clinician_location_id($clinician_id));

        // تعلیق عضویت B: Locationهای B دیگر مجاز نیستند (تخصیص مجدد فقط با A)
        $membershipB = (int) $this->service->membership_for($this->clinicB, $uid)['id'];
        $this->service->suspend_membership($membershipB);
        $this->service->assign_clinician_locations($clinician_id, [$this->locA1, $this->locA1b]);
        self::assertSame([$this->locA1, $this->locA1b], $this->service->clinician_location_ids($clinician_id));

        // ...اما Location کلینیک B حالا رد می‌شود
        try {
            $this->service->assign_clinician_locations($clinician_id, [$this->locB1]);
            self::fail('با تعلیق عضویت B، Locationهای B باید رد شوند.');
        } catch (MembershipException $e) {
            self::assertSame('CLINIC_CLINICIAN_LOCATION_MISMATCH', $e->error_code);
        }
    }

    public function testInvalidInputsAreRejected(): void
    {
        $uid = $this->newUser('member_invalid');

        // role_key بدقالب
        try {
            $this->service->create_membership($this->clinicA, $uid, 'Bad Role!');
            self::fail('role_key بدقالب باید رد شود.');
        } catch (MembershipException $e) {
            self::assertSame('CLINIC_MEMBERSHIP_INVALID_VALUE', $e->error_code);
        }

        // کاربر ناموجود
        try {
            $this->service->create_membership($this->clinicA, 9999999, 'cpms_doctor');
            self::fail('کاربر ناموجود باید رد شود.');
        } catch (MembershipException $e) {
            self::assertSame('CLINIC_MEMBERSHIP_NOT_FOUND', $e->error_code);
        }

        // کلینیک ناموجود
        try {
            $this->service->create_membership(9999999, $uid, 'cpms_doctor');
            self::fail('کلینیک ناموجود باید رد شود.');
        } catch (MembershipException $e) {
            self::assertSame('CLINIC_MEMBERSHIP_NOT_FOUND', $e->error_code);
        }

        // عضویت ناموجود برای suspend
        try {
            $this->service->suspend_membership(9999999);
            self::fail('عضویت ناموجود باید رد شود.');
        } catch (MembershipException $e) {
            self::assertSame('CLINIC_MEMBERSHIP_NOT_FOUND', $e->error_code);
        }
    }
}
