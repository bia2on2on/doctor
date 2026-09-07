<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\ClinicianAdminPage;
use ClinicCore\Admin\StaffManagementPage;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * Chunk D — Doctor Management + Schedule (workflow منسجم).
 *
 * پوشش:
 *  - «افزودن پزشک + اکانت/نقش» در یک جریان (بدون دو جایگاه جداگانه WP-Users و CPMS):
 *    `StaffManagementPage::upsertUser` شناسهٔ کاربرِ ساخته‌شده را برمی‌گرداند تا پیوند
 *    ۱:۱ با `cpms_doctor` برقرار شود؛ رمز تولیدی/تعیین‌شده هرگز به‌صورت plaintext ذخیره نمی‌شود.
 *  - گزارش تأثیر تغییر برنامه (`ScheduleService::impact`): تعداد اسلات خالیِ آینده که قرار است
 *    بازتولید شود و تعداد اسلات رزرو/Hold که «محافظت» می‌شوند (بدون invalidate بی‌صدا).
 *  - فرم «افزودن پزشک» گزینهٔ ساخت حساب را در همان صفحه نمایش می‌دهد.
 *  - صفحهٔ پزشک، جعبهٔ «تأثیر تغییر برنامه» را رندر می‌کند.
 */
final class DoctorWorkflowTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::settings()->set('booking.max_future_days', 14);
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        unset($_GET['clinician_id']);
        wp_set_current_user(0);
        parent::tearDown();
    }

    protected function makeAdmin(): int
    {
        $id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($id);

        return (int) $id;
    }

    public function testUpsertUserReturnsCreatedUserId(): void
    {
        $adminId = $this->makeAdmin();
        $r = StaffManagementPage::upsertUser([
            'mode' => 'create',
            'username' => 'doc_flow_1',
            'display_name' => 'دکتر جریان',
            'email' => 'docflow1@test.local',
            'role' => RolesAndCapabilities::ROLE_DOCTOR,
            'password' => 'StrongPass123',
        ], $adminId);

        $this->assertSame('', $r['error']);
        $this->assertGreaterThan(0, (int) $r['user_id'], 'upsertUser باید شناسهٔ کاربرِ ساخته‌شده را برگرداند');

        $u = get_userdata((int) $r['user_id']);
        $this->assertNotNull($u);
        $this->assertContains(RolesAndCapabilities::ROLE_DOCTOR, (array) $u->roles);
    }

    public function testCreateDoctorLinkedToNewlyCreatedAccount(): void
    {
        $adminId = $this->makeAdmin();
        $acc = StaffManagementPage::upsertUser([
            'mode' => 'create',
            'username' => 'doc_flow_link',
            'display_name' => 'دکتر پیوند',
            'email' => 'docflowlink@test.local',
            'role' => RolesAndCapabilities::ROLE_DOCTOR,
            'password' => '',
        ], $adminId);
        $this->assertSame('', $acc['error']);
        $this->assertNotSame('', $acc['generated'], 'رمز خالی → تولید خودکار');
        $newUserId = (int) $acc['user_id'];

        // پیوند ۱:۱ با رکورد پزشک (همان چیزی که فلو «افزودن پزشک» انجام می‌دهد).
        $repo = App::clinicianRepository();
        $cid = $repo->create(['full_name' => 'دکتر پیوند', 'wp_user_id' => $newUserId]);
        $this->assertGreaterThan(0, $cid);

        $row = $repo->find($cid);
        $this->assertSame($newUserId, (int) ($row['wp_user_id'] ?? 0));
        $this->assertTrue($repo->isUserLinked($newUserId));
        $this->assertSame(1, (int) $row['is_active'], 'پزشک تازه باید فعال باشد');
    }

    public function testOneToOneGuardDetectsConflictBeforeCreate(): void
    {
        $adminId = $this->makeAdmin();
        $acc = StaffManagementPage::upsertUser([
            'mode' => 'create',
            'username' => 'doc_flow_guard',
            'display_name' => 'دکتر گارد',
            'email' => 'docflowguard@test.local',
            'role' => RolesAndCapabilities::ROLE_DOCTOR,
            'password' => 'StrongPass123',
        ], $adminId);
        $newUserId = (int) $acc['user_id'];
        $repo = App::clinicianRepository();
        $first = $repo->create(['full_name' => 'دکتر اول', 'wp_user_id' => $newUserId]);
        $second = $repo->create(['full_name' => 'دکتر دوم']);

        // همان گارد ۱:۱ که صفحهٔ «افزودن پزشک» پیش از create اجرا می‌کند: کاربرِ
        // پیوندشده به «پزشک غیر از خودش» به‌عنوان تعارض گزارش می‌شود (TRUE).
        $this->assertTrue($repo->isUserLinked($newUserId));
        $this->assertFalse($repo->isUserLinked($newUserId, $first), 'خودِ همان پزشک استثنا است');
        $this->assertTrue($repo->isUserLinked($newUserId, $second));
    }

    public function testScheduleImpactCountsEmptyVsReserved(): void
    {
        wp_set_current_user($this->makeAdmin());
        $repo = App::clinicianRepository();
        $cid = $repo->create(['full_name' => 'دکتر تأثیر']);

        $tomorrow = gmdate('Y-m-d', time() + 86400);
        $dow = $this->iranianDow($tomorrow);
        App::scheduleService()->create(get_current_user_id(), [
            'clinician_id' => $cid,
            'day_of_week' => $dow,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'appointment_duration_min' => 60,
            'slot_capacity' => 1,
        ]);
        $this->runJobs();

        // Slotهای فردا: 09:00, 10:00, 11:00
        $count = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_schedule_slots') .
            ' WHERE clinician_id = %d AND slot_date = %s',
            [$cid, $tomorrow]
        );
        $this->assertSame(3, $count);

        // یک Slot را رزرو می‌کنیم (شبیه‌سازی رزرو موجود).
        App::db()->query(
            'UPDATE ' . App::db()->table('cpms_schedule_slots') .
            ' SET booked_count = 1 WHERE clinician_id = %d AND slot_date = %s AND slot_time = %s',
            [$cid, $tomorrow, '09:00:00']
        );

        $impact = App::scheduleService()->impact($cid);
        $this->assertSame(2, (int) $impact['future_empty_slots'], 'باید دو اسلات خالیِ بازتولیدشونده وجود داشته باشد');
        $this->assertSame(1, (int) $impact['future_reserved_slots'], 'اسلات رزروشده باید «محافظت» شود و در شمارش بیفتد');
    }

    public function testRenderListShowsInlineAccountCreationOption(): void
    {
        $this->makeAdmin();
        unset($_GET['clinician_id']); // لیست افزودن، نه نمای جزئیات
        $html = $this->render();
        $this->assertStringContainsString('cpms-create-account', $html, 'فرم افزودن پزشک گزینهٔ ساخت حساب را نمایش دهد');
        $this->assertStringContainsString('account_username', $html, 'فیلد نام کاربری حساب در فرم باشد');
        $this->assertStringContainsString('account_password', $html, 'فیلد رمز حساب در فرم باشد');
    }

    public function testRenderClinicianShowsScheduleImpactNotice(): void
    {
        wp_set_current_user($this->makeAdmin());
        $repo = App::clinicianRepository();
        $cid = $repo->create(['full_name' => 'دکتر نما']);

        $_GET['clinician_id'] = $cid;
        $html = $this->render();
        $this->assertStringContainsString('تأثیر تغییر برنامه', $html, 'صفحهٔ پزشک جعبهٔ پیش‌نمایش تأثیر را رندر کند');
        $this->assertStringContainsString('حذف و بازتولید', $html);
        $this->assertStringContainsString('هرگز حذف نمی‌شوند', $html);
    }

    // ---------- Helpers ----------

    private function render(): string
    {
        ob_start();
        try {
            ClinicianAdminPage::render();
        } finally {
            $out = (string) ob_get_clean();
        }

        return $out;
    }

    private function runJobs(): void
    {
        App::dispatcher()->tick(10);
    }

    /**
     * تبدیل تاریخ میلادی به روز هفتهٔ ایرانی (0=شنبه..6=جمعه) — همان نگاشت Handler.
     */
    private function iranianDow(string $ymd): int
    {
        $map = [0 => 1, 1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6, 6 => 0];

        return $map[(int) gmdate('w', strtotime($ymd))];
    }
}
