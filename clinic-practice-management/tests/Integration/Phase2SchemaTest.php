<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * Phase 2 — Schema Foundation (Migrations 0010–0018، ADR-0031/P2-D1/P2-D2):
 *
 *  - fresh-install state (bootstrap) + final version
 *  - upgrade path 0009 → 0018 (rollback + re-migrate؛ الگوی MigrationTest)
 *  - idempotency (re-run = no-op)
 *  - FK integrity (clinic/location)
 *  - UNIQUE های Location-scoped (u_sched_slot / u_slot)
 *  - P2-D2: timezone precedence + گزارش واگرایی
 *  - B-11: audit_logs.clinic_id NULL-able
 *  - حذف DEFAULT tenant=1
 *  - Seed عضویت/clinician_locations (M-13)
 *  - AD-14: patients.identity_id
 */
final class Phase2SchemaTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
    }

    protected function tearDown(): void
    {
        App::resetScope();
        parent::tearDown();
    }

    private function db(): \ClinicCore\Infrastructure\Db\CpmsDb
    {
        return App::db();
    }

    private function primaryLocationId(): int
    {
        return (int) $this->db()->fetchValue(
            'SELECT id FROM ' . $this->db()->table('cpms_locations') .
            ' WHERE clinic_id = 1 AND is_primary = 1 LIMIT 1'
        );
    }

    // ================= Fresh install =================

    public function testFreshInstallReachesPhaseTwoSchema(): void
    {
        self::assertSame('2026_09_09_0018', App::migrations()->currentVersion());

        foreach ([
            'cpms_organizations', 'cpms_locations', 'cpms_clinic_memberships',
            'cpms_membership_capabilities', 'cpms_membership_locations',
            'cpms_clinician_locations', 'cpms_patient_identities',
        ] as $table) {
            $exists = $this->db()->fetchValue(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
                [$this->db()->table($table)]
            );
            self::assertSame(1, (int) $exists, $table . ' باید ساخته شده باشد.');
        }
    }

    public function testClinicsBelongToOrganizationWithFk(): void
    {
        $orgId = $this->db()->fetchValue(
            'SELECT organization_id FROM ' . $this->db()->table('cpms_clinics') . ' LIMIT 1'
        );
        self::assertNotNull($orgId, 'Clinic باید organization_id داشته باشد (NOT NULL — AD-02).');

        $org = $this->db()->fetchRow(
            'SELECT id, slug FROM ' . $this->db()->table('cpms_organizations') . ' WHERE id = %d',
            [(int) $orgId]
        );
        self::assertNotNull($org, 'سازمان پیش‌فرض باید seeded شده باشد.');

        $rejected = $this->db()->wpdb()->query(
            'UPDATE ' . $this->db()->wpdb()->prefix . 'cpms_clinics SET organization_id = 999999 WHERE id = 1' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertFalse((bool) $rejected, 'FK سازمان باید مقدار ناموجود را رد کند.');
    }

    public function testEveryClinicHasPrimaryLocationWithTimezone(): void
    {
        $clinics = $this->db()->fetchAll('SELECT id FROM ' . $this->db()->table('cpms_clinics'));
        self::assertNotEmpty($clinics);

        foreach ($clinics as $clinic) {
            $loc = $this->db()->fetchRow(
                'SELECT id, timezone FROM ' . $this->db()->table('cpms_locations') .
                ' WHERE clinic_id = %d AND is_primary = 1',
                [(int) $clinic['id']]
            );
            self::assertNotNull($loc, 'هر Clinic حداقل یک Location اصلی دارد (AD-15).');
            self::assertNotSame('', (string) $loc['timezone'], 'Location.timezone NOT NULL (P2-D2).');
            self::assertContains((string) $loc['timezone'], \DateTimeZone::listIdentifiers(), 'TZ باید IANA-valid باشد.');
        }
    }

    // ================= Upgrade path =================

    public function testUpgradePathFromSchema0009ToPhaseTwo(): void
    {
        // دادهٔ پیش از فاز ۲ (باقی می‌ماند؟)
        $now = $this->db()->nowUtcSql();
        $this->db()->insert('cpms_patients', [
            'clinic_id' => 1,
            'mrn' => 'P-UPG-2',
            'first_name' => 'بیمار',
            'last_name' => 'ارتقا',
            'mobile' => '09120001122',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $patientId = (int) $this->db()->wpdb_last_insert_id();

        // بازگشت به 0009
        $versions = [];
        while (App::migrations()->currentVersion() !== '2026_09_07_0009') {
            $v = App::migrations()->rollbackOne();
            self::assertNotNull($v, 'باید بتوان تا 0009 برگشت.');
            $versions[] = $v;
        }
        self::assertSame(
            ['2026_09_09_0018', '2026_09_09_0017', '2026_09_09_0016', '2026_09_09_0015', '2026_09_09_0014', '2026_09_09_0013', '2026_09_09_0012', '2026_09_09_0011', '2026_09_09_0010'],
            $versions
        );

        // پیش از فاز ۲: ستون‌ها نیستند
        $col = $this->db()->fetchRow('SHOW COLUMNS FROM ' . $this->db()->table('cpms_clinics') . " LIKE 'organization_id'");
        self::assertNull($col);

        // ارتقا
        $applied = App::migrations()->migrate();
        self::assertContains('2026_09_09_0010', $applied);
        self::assertSame('2026_09_09_0018', App::migrations()->currentVersion());

        // داده دست‌نخورده
        $survived = $this->db()->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db()->table('cpms_patients') . ' WHERE id = %d AND mrn = %s',
            [$patientId, 'P-UPG-2']
        );
        self::assertSame(1, (int) $survived, 'دادهٔ موجود باید از ارتقا جان سالم به در ببرد (AD-12).');

        // idempotent — اجرای دوباره = no-op
        self::assertSame([], App::migrations()->migrate());
    }

    // ================= FK integrity =================

    public function testClinicAndLocationForeignKeysRejectOrphans(): void
    {
        global $wpdb;
        $now = $this->db()->nowUtcSql();

        // clinic_id ناموجود (sms_messages — FK تصحیح 0017)
        // ابتدا positive-control: همان INSERT با Clinic واقعی باید موفق شود
        // تا ردِ ردیف دوم قطعاً به‌خاطر FK باشد نه ستون‌های NOT NULL.
        $valid = $wpdb->query(
            'INSERT INTO ' . $wpdb->prefix . "cpms_sms_messages
             (clinic_id, event, recipient, message, status, created_at)
             VALUES (1, 'test', '09120000000', 'x', 'QUEUED', '" . $now . "')" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertTrue((bool) $valid, 'positive-control: INSERT معتبر sms باید موفق شود.');
        $rejected = $wpdb->query(
            'INSERT INTO ' . $wpdb->prefix . "cpms_sms_messages
             (clinic_id, event, recipient, message, status, created_at)
             VALUES (999999, 'test', '09120000001', 'x', 'QUEUED', '" . $now . "')" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertFalse((bool) $rejected, 'FK جدید clinic_id باید یتیمی را رد کند.');

        // location_id ناموجود (schedule_slots — 0013؛ Clinician واقعی، Location غیرواقعی)
        $this->db()->insert('cpms_clinicians', [
            'clinic_id' => 1,
            'full_name' => 'دکتر FK',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $clinicianId = (int) $this->db()->wpdb_last_insert_id();

        $rejected = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, is_open, created_at, updated_at)
                 VALUES (1, 999999, %d, %s, %s, 20, 1, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicianId,
                '2026-09-10',
                '10:00:00',
                $now,
                $now
            )
        );
        self::assertFalse((bool) $rejected, 'FK location_id باید مقدار ناموجود را رد کند.');
    }

    // ================= UNIQUE های Location-scoped =================

    public function testScheduleUniqueAllowsMultiShiftAndMultiLocation(): void
    {
        global $wpdb;
        $now = $this->db()->nowUtcSql();
        $loc = $this->primaryLocationId();

        $this->db()->insert('cpms_clinicians', [
            'clinic_id' => 1,
            'full_name' => 'دکتر برنامه',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $clinicianId = (int) $this->db()->wpdb_last_insert_id();

        $insert = static function (int $locationId, string $start) use ($wpdb, $now, $clinicianId): bool {
            return (bool) $wpdb->query(
                $wpdb->prepare(
                    'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule
                         (clinic_id, location_id, clinician_id, day_of_week, start_time, end_time, appointment_duration_min, slot_capacity, is_active, created_at, updated_at)
                     VALUES (1, %d, %d, 0, %s, %s, 20, 1, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $locationId,
                    $clinicianId,
                    $start,
                    '18:00:00',
                    $now,
                    $now
                )
            );
        };

        // دو شیفت در یک روز/مکان — قبلاً ناممکن (u_sched_day فقط یک ردیف در روز می‌داد)
        self::assertTrue($insert($loc, '09:00:00'));
        self::assertTrue($insert($loc, '14:00:00'), 'شیفت دوم همان روز باید مجاز باشد.');

        // تکرار دقیق ⇒ رد
        self::assertFalse($insert($loc, '09:00:00'), 'تکرار (clinic,location,clinician,dow,start) باید رد شود.');
    }

    public function testSlotUniqueAllowsSameSlotTimeInTwoLocations(): void
    {
        global $wpdb;
        $now = $this->db()->nowUtcSql();
        $loc = $this->primaryLocationId();

        $this->db()->insert('cpms_clinicians', [
            'clinic_id' => 1,
            'full_name' => 'دکتر اسلات',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $clinicianId = (int) $this->db()->wpdb_last_insert_id();

        // Location دوم در همان Clinic
        $wpdb->query(
            'INSERT INTO ' . $wpdb->prefix . "cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)
             VALUES (1, 'شعبه دوم', 'second', 'Asia/Tehran', 0, 1, '" . $now . "', '" . $now . "')" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        $loc2 = (int) $wpdb->insert_id;

        $insert = static function (int $locationId) use ($wpdb, $now, $clinicianId): bool {
            return (bool) $wpdb->query(
                $wpdb->prepare(
                    'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                         (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, is_open, created_at, updated_at)
                     VALUES (1, %d, %d, %s, %s, 20, 1, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $locationId,
                    $clinicianId,
                    '2026-09-10',
                    '10:00:00',
                    $now,
                    $now
                )
            );
        };

        self::assertTrue($insert($loc));
        self::assertTrue($insert($loc2), 'همان زمان برای همان پزشک در Location دیگر باید مجاز باشد.');
        self::assertFalse($insert($loc), 'تکرار (location,clinician,date,time) باید رد شود.');
    }

    // ================= P2-D2 — Timezone precedence =================

    private function runLocationsMigrationUp(): void
    {
        $migration = require dirname(__DIR__) . '/../src/Migrations/2026_09_09_0011_locations.php';
        ($migration['up'])($this->db());
    }

    public function testTimezoneSeedPrefersWizardSettingAndReportsDivergence(): void
    {
        global $wpdb;

        // دادهٔ واگرا: setting معتبرِ متفاوت با ستون
        // (پاک‌سازی لاگ واگرایی قبلی — مقاوم به leftover از تست‌های قبل از DDL)
        $this->db()->query(
            'DELETE FROM ' . $this->db()->table('cpms_operational_logs') .
            " WHERE message LIKE 'PHASE2_TZ_DIVERGENCE%'"
        );
        $this->db()->query('DELETE FROM ' . $this->db()->table('cpms_locations'));
        $wpdb->query(
            'UPDATE ' . $wpdb->prefix . "cpms_clinics SET timezone = 'Asia/Tehran'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        $this->db()->query(
            'INSERT INTO ' . $this->db()->table('cpms_settings') . ' (clinic_id, `key`, value_json, updated_at)
             VALUES (1, %s, %s, %s)
             ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), updated_at = VALUES(updated_at)',
            ['setup.clinic.timezone', '"Asia/Kabul"', $this->db()->nowUtcSql()]
        );

        $this->runLocationsMigrationUp();

        $tz = $this->db()->fetchValue(
            'SELECT timezone FROM ' . $this->db()->table('cpms_locations') . ' WHERE clinic_id = 1 AND is_primary = 1 LIMIT 1'
        );
        self::assertSame('Asia/Kabul', (string) $tz, 'قاعدهٔ P2-D2: setting معتبر مقدم است.');

        // واگرایی گزارش شده — نه sync خاموش
        $divergence = $this->db()->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db()->table('cpms_operational_logs') .
            " WHERE level = 'warning' AND message LIKE 'PHASE2_TZ_DIVERGENCE%'"
        );
        self::assertSame(1, (int) $divergence, 'واگرایی setting/ستون باید گزارش شود.');
        $columnTz = $this->db()->fetchValue('SELECT timezone FROM ' . $this->db()->table('cpms_clinics') . ' WHERE id = 1');
        self::assertSame('Asia/Tehran', (string) $columnTz, 'هیچ سمتی sync نمی‌شود.');
    }

    public function testTimezoneSeedFallsBackToColumnWhenSettingInvalid(): void
    {
        global $wpdb;

        $this->db()->query(
            'DELETE FROM ' . $this->db()->table('cpms_operational_logs') .
            " WHERE message LIKE 'PHASE2_TZ_DIVERGENCE%'"
        );
        $this->db()->query('DELETE FROM ' . $this->db()->table('cpms_locations'));
        $wpdb->query(
            'UPDATE ' . $wpdb->prefix . "cpms_clinics SET timezone = 'Asia/Baghdad'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        $this->db()->query(
            'INSERT INTO ' . $this->db()->table('cpms_settings') . ' (clinic_id, `key`, value_json, updated_at)
             VALUES (1, %s, %s, %s)
             ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), updated_at = VALUES(updated_at)',
            ['setup.clinic.timezone', '"Mars/Olympus_Mons"', $this->db()->nowUtcSql()]
        );

        $this->runLocationsMigrationUp();

        $tz = $this->db()->fetchValue(
            'SELECT timezone FROM ' . $this->db()->table('cpms_locations') . ' WHERE clinic_id = 1 AND is_primary = 1 LIMIT 1'
        );
        self::assertSame('Asia/Baghdad', (string) $tz, 'setting نامعتبر ⇒ ستون معتبر.');

        $divergence = $this->db()->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db()->table('cpms_operational_logs') .
            " WHERE message LIKE 'PHASE2_TZ_DIVERGENCE%'"
        );
        self::assertSame(0, (int) $divergence, 'بدون دو منبع معتبر، واگرایی گزارش نمی‌شود.');
    }

    // ================= B-11 + tenant hygiene =================

    public function testAuditLogAcceptsNullClinicForPreScopeEvents(): void
    {
        $this->db()->insert('cpms_audit_logs', [
            'clinic_id' => null,
            'actor_wp_user_id' => null,
            'actor_role' => 'patient',
            'action' => 'LOGIN_FAILED',
            'resource_type' => 'auth',
            'resource_id' => null,
            'patient_id' => null,
            'prev_hash' => str_repeat('0', 64),
            'row_hash' => hash('sha256', 'phase2-test-null-clinic'),
            'meta_json' => '{}',
            'created_at' => $this->db()->nowUtcSql(),
        ]);
        $count = $this->db()->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db()->table('cpms_audit_logs') . ' WHERE clinic_id IS NULL AND action = %s',
            ['LOGIN_FAILED']
        );
        self::assertSame(1, (int) $count, 'B-11: رویداد پیش از Scope باید بدون Clinic ثبت شود (NULL، نه 1).');
    }

    public function testTenantDefaultOneRemovedFromSchema(): void
    {
        foreach (['cpms_drug_reference', 'cpms_idempotency_keys', 'cpms_sms_messages'] as $table) {
            $col = $this->db()->fetchRow('SHOW COLUMNS FROM ' . $this->db()->table($table) . " LIKE 'clinic_id'");
            self::assertNotNull($col, $table);
            self::assertNull($col['Default'], $table . '.clinic_id نباید DEFAULT 1 داشته باشد (AD-13 در سطح schema).');
        }

        // sms هم‌نوع با clinics.id
        $sms = $this->db()->fetchRow('SHOW COLUMNS FROM ' . $this->db()->table('cpms_sms_messages') . " LIKE 'clinic_id'");
        self::assertStringContainsStringIgnoringCase('bigint', (string) $sms['Type']);
    }

    // ================= Membership/clinician-location seed =================

    public function testMembershipSeedFromExistingWpRoles(): void
    {
        // کاربر دارای نقش cpms_doctor (DML — در پایان تست rollback می‌شود)
        $created = wp_insert_user([
            'user_login' => 'docmember',
            'user_email' => 'docmember@t.cpms.local',
            'user_pass' => 'x',
        ]);
        self::assertNotInstanceOf(\WP_Error::class, $created, 'wp_insert_user نباید خطا دهد.');
        $userId = (int) $created;
        $doctor = get_role('cpms_doctor');
        self::assertNotNull($doctor);
        $this->db()->wpdb()->query(
            'INSERT INTO ' . $this->db()->wpdb()->prefix . "usermeta (user_id, meta_key, meta_value)
             VALUES (" . $userId . ", '" . $this->db()->wpdb()->prefix . "capabilities', 'a:1:{s:11:\"cpms_doctor\";b:1;}')" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );

        // Seed: migration عضویت idempotent است — بازاجرای مستقیم با کاربرِ
        // جدیدِ دارای نقش، عضویت او را می‌سازد (همان منطق M-13).
        $migration = require dirname(__DIR__) . '/../src/Migrations/2026_09_09_0012_memberships.php';
        ($migration['up'])($this->db());

        $membership = $this->db()->fetchRow(
            'SELECT * FROM ' . $this->db()->table('cpms_clinic_memberships') . ' WHERE wp_user_id = %d LIMIT 1',
            [$userId]
        );
        self::assertNotNull($membership, 'کاربر دارای نقش cpms_* باید Membership seeded شود.');
        self::assertSame('cpms_doctor', (string) $membership['role_key']);
        self::assertSame('active', (string) $membership['status']);
        self::assertSame(1, (int) $membership['is_primary']);
    }

    public function testClinicianLocationSeedLinksHomeClinicPrimaryLocation(): void
    {
        $now = $this->db()->nowUtcSql();
        $this->db()->insert('cpms_clinicians', [
            'clinic_id' => 1,
            'full_name' => 'دکتر مکان',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $clinicianId = (int) $this->db()->wpdb_last_insert_id();

        $migration = require dirname(__DIR__) . '/../src/Migrations/2026_09_09_0012_memberships.php';
        ($migration['up'])($this->db());

        $link = $this->db()->fetchRow(
            'SELECT location_id, is_primary FROM ' . $this->db()->table('cpms_clinician_locations') .
            ' WHERE clinician_id = %d LIMIT 1',
            [$clinicianId]
        );
        self::assertNotNull($link, 'Clinician باید به Location اصلیِ کلینیکِ خانه لینک شود (compatibility).');
        self::assertSame($this->primaryLocationId(), (int) $link['location_id']);
        self::assertSame(1, (int) $link['is_primary']);
    }

    // ================= AD-14 — Patient identity =================

    public function testPatientIdentityIsOrganizationLevelAndDetachable(): void
    {
        $now = $this->db()->nowUtcSql();
        $orgId = (int) $this->db()->fetchValue('SELECT organization_id FROM ' . $this->db()->table('cpms_clinics') . ' WHERE id = 1');

        $this->db()->insert('cpms_patient_identities', [
            'organization_id' => $orgId,
            'internal_ref' => 'PI-TEST-0001',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $identityId = (int) $this->db()->wpdb_last_insert_id();

        $this->db()->insert('cpms_patients', [
            'clinic_id' => 1,
            'identity_id' => $identityId,
            'mrn' => 'P-IDN-1',
            'first_name' => 'بیمار',
            'last_name' => 'هویت',
            'mobile' => '09120003344',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $patientId = (int) $this->db()->wpdb_last_insert_id();

        // حذف Identity ⇒ رکورد بالینی می‌ماند و identity_id NULL می‌شود (SET NULL)
        $this->db()->query('DELETE FROM ' . $this->db()->table('cpms_patient_identities') . ' WHERE id = %d', [$identityId]);
        $after = $this->db()->fetchValue(
            'SELECT identity_id FROM ' . $this->db()->table('cpms_patients') . ' WHERE id = %d',
            [$patientId]
        );
        self::assertNull($after, 'حذف Identity نباید رکورد بالینی Clinic-scoped را حذف کند (AD-14).');
    }
}
