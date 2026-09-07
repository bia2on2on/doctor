<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * ADR-0030 / Part 1 — Scope صف و Real-time Feed برای «پزشکِ متصل» (ممیزی P8).
 *
 * اصل Master Context §8: «Doctor نباید به صورت implicit داده Doctor دیگر را ببیند.»
 * رفتار قبلی: /queue و rt/queue و آمار داشبورد برای نقش doctor کل کلینیک را
 * برمی‌گرداند (فقط QUEUE_READ چک می‌شد). اکنون:
 *  - پزشک متصل به Clinician → فقط ویزیت‌ها/رویدادها/آمار همان Clinician؛
 *  - پزشک بدون اتصال → هیچ (صفر نتیجه — نه کل کلینیک)؛
 *  - منشی → دامنه مطب (بدون تغییر — V1 تک-کلینیک ADR-0003).
 */
final class DoctorQueueScopeTest extends WP_UnitTestCase
{
    private int $clinicianAId;
    private int $clinicianBId;
    private int $patientAId;
    private int $patientBId;
    private int $secretaryUserId;
    private int $doctorUserId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::settings()->set('queue.auto_enqueue', true);

        global $wpdb;
        $now = App::db()->nowUtcSql();

        $this->clinicianAId = $this->insertClinician('Dr Scope A', $now);
        $this->clinicianBId = $this->insertClinician('Dr Scope B', $now);
        $this->patientAId = $this->insertPatient('MR-SCOPE-A', '09124440001', $now);
        $this->patientBId = $this->insertPatient('MR-SCOPE-B', '09124440002', $now);

        $this->secretaryUserId = $this->makeUser('scope_secretary', 'cpms_secretary');
        $this->doctorUserId = $this->makeUser('scope_doctor', 'cpms_doctor');

        // پزشک فقط به Clinician A متصل است (B بدون کاربر)
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . $wpdb->prefix . 'cpms_clinicians SET wp_user_id = %d WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->doctorUserId,
            $this->clinicianAId
        ));
    }

    public function testDoctorSeesOnlyOwnQueueAndScopedStats(): void
    {
        $visitA = App::visitService()->walkIn($this->secretaryUserId, $this->patientAId, $this->clinicianAId);
        App::visitService()->walkIn($this->secretaryUserId, $this->patientBId, $this->clinicianBId);

        wp_set_current_user($this->doctorUserId);
        $today = App::visitService()->today($this->doctorUserId);

        $this->assertCount(1, $today['queue'], 'پزشک فقط ویزیت‌های خودش را می‌بیند');
        $this->assertSame($this->clinicianAId, $today['queue'][0]['clinician_id']);
        $this->assertSame((int) $visitA['id'], (int) $today['queue'][0]['id']);
        $this->assertSame(1, $today['stats']['total'], 'آمار هم باید Scope خود پزشک باشد');
        $this->assertSame(1, $today['stats']['waiting']);
        $this->assertSame(1, $today['stats']['walk_in_today']);
        $this->assertSame(0, $today['stats']['appointments_today']);

        // منشی: دامنه مطب — هر دو ویزیت
        wp_set_current_user($this->secretaryUserId);
        $secretaryToday = App::visitService()->today($this->secretaryUserId);
        $this->assertCount(2, $secretaryToday['queue']);
        $this->assertSame(2, $secretaryToday['stats']['total']);
    }

    public function testDoctorRealtimeFeedExcludesOtherCliniciansVisits(): void
    {
        $visitA = App::visitService()->walkIn($this->secretaryUserId, $this->patientAId, $this->clinicianAId);
        $visitB = App::visitService()->walkIn($this->secretaryUserId, $this->patientBId, $this->clinicianBId);

        wp_set_current_user($this->doctorUserId);
        $doctorFeed = App::visitService()->eventsSince($this->doctorUserId, 0);
        foreach ($doctorFeed['events'] as $event) {
            $this->assertSame((int) $visitA['id'], (int) $event['visit_id'], 'فید پزشک نباید رویداد ویزیت پزشک دیگر را داشته باشد');
        }

        wp_set_current_user($this->secretaryUserId);
        $secretaryFeed = App::visitService()->eventsSince($this->secretaryUserId, 0);
        $visitIds = array_unique(array_map(static fn (array $e): int => (int) $e['visit_id'], $secretaryFeed['events']));
        $this->assertContains((int) $visitA['id'], $visitIds);
        $this->assertContains((int) $visitB['id'], $visitIds);

        // ETag/last_event_id پزشک فقط رویدادهای خودش — کمتر از کل مطب
        $this->assertLessThan(
            App::visitService()->lastEventId($this->secretaryUserId),
            App::visitService()->lastEventId($this->doctorUserId)
        );
    }

    public function testUnlinkedDoctorSeesNothingInsteadOfWholeClinic(): void
    {
        App::visitService()->walkIn($this->secretaryUserId, $this->patientAId, $this->clinicianAId);
        App::visitService()->walkIn($this->secretaryUserId, $this->patientBId, $this->clinicianBId);

        $orphanDoctor = $this->makeUser('scope_orphan_doctor', 'cpms_doctor');
        wp_set_current_user($orphanDoctor);

        $today = App::visitService()->today($orphanDoctor);
        $this->assertSame([], $today['queue'], 'پزشک بدون اتصال Clinician — هیچ، نه کل کلینیک');
        $this->assertSame(0, $today['stats']['total']);
    }

    // ================= Fixtures =================

    private function insertClinician(string $name, string $now): int
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, is_active, created_at, updated_at)
             VALUES (1, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $name,
            $now,
            $now
        ));

        return (int) $wpdb->insert_id;
    }

    private function insertPatient(string $mrn, string $mobile, string $now): int
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
             VALUES (1, %s, "Scope", "Patient", %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $mrn,
            $mobile,
            $now,
            $now
        ));

        return (int) $wpdb->insert_id;
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login, 'pass-12345', $login . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }
}
