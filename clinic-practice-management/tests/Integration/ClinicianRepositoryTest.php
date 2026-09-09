<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\ClinicianRepository;
use WP_UnitTestCase;

/**
 * ADR-0031 / Part 2 — Repository پزشکان (ممیزی P1: تا قبل از این هیچ CRUD
 * برای cpms_clinicians وجود نداشت و راه‌اندازی فقط با دست‌کاری مستقیم DB ممکن بود).
 */
final class ClinicianRepositoryTest extends WP_UnitTestCase
{
    private ClinicianRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->repo = new ClinicianRepository(App::db());
    }

    private function makeUser(string $login): int
    {
        $userId = (int) wp_create_user($login, 'pass-12345', $login . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role('cpms_doctor');
        }

        return $userId;
    }

    public function testCreateFindUpdateListRoundtrip(): void
    {
        $id = $this->repo->create(1, [
            'full_name' => 'دکتر پارت دو',
            'specialty' => 'داخلی',
            'room' => '۲۰۱',
            'wp_user_id' => null,
        ]);
        $this->assertGreaterThan(0, $id);

        $row = $this->repo->find($id);
        $this->assertNotNull($row);
        $this->assertSame('دکتر پارت دو', (string) $row['full_name']);
        $this->assertSame('داخلی', (string) $row['specialty']);
        $this->assertSame(1, (int) $row['is_active']);

        $this->repo->update($id, ['specialty' => 'قلب', 'room' => null]);
        $row = $this->repo->find($id);
        $this->assertSame('قلب', (string) ($row['specialty'] ?? ''));
        $this->assertNull($row['room']);

        // فهرست شامل غیرفعال‌ها؛ فیلتر فعال‌ها هم کار می‌کند
        $this->repo->update($id, ['is_active' => 0]);
        $all = $this->repo->listAll(1, true);
        $onlyActive = $this->repo->listAll(1, false);
        $allIds = array_map(static fn (array $r): int => (int) $r['id'], $all);
        $activeIds = array_map(static fn (array $r): int => (int) $r['id'], $onlyActive);
        $this->assertContains($id, $allIds);
        $this->assertNotContains($id, $activeIds);
    }

    public function testUserLinkingEnforcesOneToOneWithFriendlyCheck(): void
    {
        $userId = $this->makeUser('p2_doc_link');

        $first = $this->repo->create(1, ['full_name' => 'پزشک اول', 'wp_user_id' => $userId]);
        $second = $this->repo->create(1, ['full_name' => 'پزشک دوم']);

        // پیش از انتساب: تعارض باید قابل تشخیص باشد (صفحه با پیام فارسی رد می‌کند)
        // Semantics: TRUE = کاربر به پزشکی «غیر از» exceptClinicianId متصل است (تعارض)
        $this->assertTrue($this->repo->isUserLinked($userId));
        $this->assertFalse($this->repo->isUserLinked($userId, $first), 'خودِ همان پزشک استثناست');
        $this->assertTrue($this->repo->isUserLinked($userId, $second));

        // انتساب همان کاربر به پزشک دوم در سطح DB توسط UNIQUE (Migration 0007) رد می‌شود
        $this->expectException(\RuntimeException::class);
        $this->repo->update($second, ['wp_user_id' => $userId]);
    }

    public function testListExposesScheduleCountAndUserLogin(): void
    {
        global $wpdb;
        $userId = $this->makeUser('p2_doc_view');
        $cid = $this->repo->create(1, ['full_name' => 'پزشک نمای فهرست', 'wp_user_id' => $userId]);

        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule
                 (clinic_id, location_id, clinician_id, day_of_week, start_time, end_time, is_active, created_at, updated_at)
             VALUES (1, (SELECT id FROM ' . $wpdb->prefix . 'cpms_locations WHERE clinic_id = 1 AND is_primary = 1 LIMIT 1), %d, 0, %s, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $cid,
            '09:00:00',
            '13:00:00',
            $now,
            $now
        ));

        $row = null;
        foreach ($this->repo->listAll(1) as $r) {
            if ((int) $r['id'] === $cid) {
                $row = $r;
                break;
            }
        }
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['schedule_days']);
        $this->assertSame('p2_doc_view', (string) ($row['wp_user_login'] ?? ''));
    }
}
