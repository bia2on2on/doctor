<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Visits\VisitException;
use ClinicCore\Infrastructure\Db\CpmsDb;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * F9 — Hardening (بخش Authorization/Integrity):
 *
 *  - ADR-0027 Minor #3: گارد مالکیت ویزیت — پزشک فقط روی ویزیت خودش
 *    Transition می‌زند (Resource Authorization سرور-side؛ سناریوهای
 *    Cross-doctor و IDOR سرویس + REST).
 *  - ADR-0027 Minor #12: یکپارچگی 1:1 Clinician ↔ WP User (UNIQUE).
 *  - بدهی F7 §9 (u_idem_key): بازپخش پاسخِ ذخیره‌شده واقعی (نه fallback
 *    دامنه)، حفاظت in-flight، و دامنه چندستونی.
 */
final class SecurityHardeningTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    private int $doctorAUserId = 0;
    private int $doctorBUserId = 0;
    private int $secretaryUserId = 0;
    private int $clinicianAId = 0;
    private int $clinicianBId = 0;
    private int $patientId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::settings()->set('queue.auto_enqueue', true);

        global $wpdb;
        $now = App::db()->nowUtcSql();

        $this->doctorAUserId = $this->makeUser('sh_doc_a', 'cpms_doctor');
        $this->doctorBUserId = $this->makeUser('sh_doc_b', 'cpms_doctor');
        $this->secretaryUserId = $this->makeUser('sh_sec', 'cpms_secretary');

        // دو پزشک متصل (مدل چندپزشکی ADR-0027) + یک پزشک سوم بدون Link
        foreach ([['Dr Own A', $this->doctorAUserId, &$this->clinicianAId], ['Dr Own B', $this->doctorBUserId, &$this->clinicianBId]] as $c) {
            $wpdb->query(
                $wpdb->prepare(
                    'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                         (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                     VALUES (1, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $c[0],
                    $c[1],
                    $now,
                    $now
                )
            );
            $c[2] = (int) $wpdb->insert_id;
        }

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, %s, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-SH-0001',
                'Hardening',
                'Patient',
                '09123990001',
                'active',
                $now,
                $now
            )
        );
        $this->patientId = (int) $wpdb->insert_id;
    }

    // ================= ADR-0027 Minor #3 — Cross-doctor / IDOR =================

    public function testDoctorCannotTransitionAnotherDoctorsVisit(): void
    {
        $visit = App::visitService()->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianAId);
        $visitId = (int) $visit['id'];

        // پزشک B (متصل به clinician B) روی ویزیت پزشک A → 403 + Audit
        try {
            App::visitService()->transition($this->doctorBUserId, $visitId, 'call', []);
            $this->fail('Transition بین‌پزشکی باید رد شود');
        } catch (VisitException $e) {
            $this->assertSame('CLINIC_PERMISSION_DENIED', $e->errorCode);
            $this->assertSame(403, $e->httpStatus);
        }

        $this->assertAuditCount('FORBIDDEN_ACCESS_ATTEMPT', 1, 'visit', $visitId);

        // پزشک A (مالک) → مجاز؛ وضعیت تغییر کرده
        $own = App::visitService()->transition($this->doctorAUserId, $visitId, 'call', []);
        $this->assertSame('called', (string) $own['status']);
    }

    public function testDoctorWithoutClinicianLinkCannotTransition(): void
    {
        // پزشک بدون Link (بدون Clinician متصل) → حتی ویزیتِ clinician بدون Link هم نه
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, is_active, created_at, updated_at)
                 VALUES (1, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr Unlinked',
                $now,
                $now
            )
        );
        $unlinkedClinicianId = (int) $wpdb->insert_id;

        $visit = App::visitService()->walkIn($this->secretaryUserId, $this->patientId, $unlinkedClinicianId);
        $orphanDoctor = $this->makeUser('sh_doc_orphan', 'cpms_doctor');

        $this->expectException(VisitException::class);
        App::visitService()->transition($orphanDoctor, (int) $visit['id'], 'call', []);
    }

    public function testCrossDoctorTransitionRejectedOverRestIdor(): void
    {
        $visit = App::visitService()->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianAId);

        wp_set_current_user($this->doctorBUserId);
        $response = $this->dispatch('POST', self::NS . '/visits/' . $visit['id'] . '/call', []);

        $this->assertSame(403, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('CLINIC_PERMISSION_DENIED', $data['code']);

        // مالک (پزشک A) از همان مسیر REST مجاز است — deny فقط برای غیرمالک
        wp_set_current_user($this->doctorAUserId);
        $ok = $this->dispatch('POST', self::NS . '/visits/' . $visit['id'] . '/call', []);
        $this->assertSame(200, $ok->get_status());
    }

    public function testSystemForceRoleBypassesOwnershipGuard(): void
    {
        // V11/V12 (F6): عمل مالی سیستمی روی ویزیت هر پزشکی — گارد فقط نقش doctor را می‌گیرد
        $visit = App::visitService()->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianAId);
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d LIMIT 1',
            [(int) $visit['id']]
        );
        $this->assertNotNull($row);

        // سریع به consultation_completed می‌رسانیم (منشی مجاز — دامنه مطب)
        $row['status'] = 'consultation_completed';

        App::db()->transactional(function () use ($row): void {
            App::visitService()->applyTransition(
                $this->doctorBUserId,
                $row,
                'invoice_ready',
                [],
                'system'
            );
        });

        $status = App::db()->fetchValue(
            'SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d',
            [(int) $visit['id']]
        );
        $this->assertSame('awaiting_payment', (string) $status);
    }

    // ================= ADR-0027 Minor #12 — Clinician ↔ User 1:1 =================

    public function testSecondClinicianWithSameWpUserIsRejectedByDb(): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();

        // پیوند تکراری → UNIQUE رد می‌کند (سکتیو، بدون exception — wpdb false)
        $inserted = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (1, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr Duplicate Link',
                $this->doctorAUserId,
                $now,
                $now
            )
        );
        $this->assertFalse((bool) $inserted, 'wp_user_id تکراری باید توسط u_clinician_user رد شود');

        // چند Clinician بدون Link (NULL) مجاز است — NULL semantics
        foreach (['Dr NoLogin 1', 'Dr NoLogin 2'] as $i => $name) {
            $ok = $wpdb->query(
                $wpdb->prepare(
                    'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                         (clinic_id, full_name, is_active, created_at, updated_at)
                     VALUES (1, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $name,
                    $now,
                    $now
                )
            );
            $this->assertNotFalse((bool) $ok, "کلینسین بدون Link #$i باید مجاز باشد");
        }
    }

    // ================= بدهی F7 §9 — Idempotency Integrity =================

    public function testIdempotencyReplayReturnsStoredResponseNotDomainFallback(): void
    {
        // ریشه‌یابی F9: `<=> %d` با NULL هرگز ردیف context_id=NULL را پیدا نمی‌کرد؛
        // بازپخش عملاً از fallback دامنه (hold converted) می‌آمد. حالا پاسخ ذخیره‌شده.
        $idem = new \ClinicCore\Infrastructure\Security\Idempotency(App::db());
        $key = 'sh-idem-' . bin2hex(random_bytes(8));

        $view = ['status' => 'confirmed', 'reference_code' => 'AP-F9-001'];
        $idem->check($key, 'booking/confirm', 42, null);
        $idem->complete($key, 'booking/confirm', 42, null, 200, $view);

        // ردیف باید DONE + پاسخ ذخیره‌شده باشد (قبلاً UPDATE با <=> هیچ ردیفی را علامت نمی‌زد)
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT status, response_code, response_json, context_id FROM ' . $wpdb->prefix . 'cpms_idempotency_keys WHERE `key` = %s',
                $key
            ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
        $this->assertSame(1, (int) $row['status'], 'complete() باید ردیف را DONE کند (باگ قدیمی: UPDATE نمی‌دید)');
        $this->assertSame(200, (int) $row['response_code']);
        $this->assertSame(0, (int) $row['context_id'], 'NULL باید به 0 نرمال شود');
        $this->assertStringContainsString('AP-F9-001', (string) $row['response_json']);

        // بازپخش = همان پاسخ ذخیره‌شده
        $replay = $idem->check($key, 'booking/confirm', 42, null);
        $this->assertTrue($replay['is_replay']);
        $this->assertSame(200, $replay['response_code']);
        $this->assertSame('AP-F9-001', $replay['response']['reference_code']);
    }

    public function testIdempotencyInFlightReturnsConflict(): void
    {
        $idem = new \ClinicCore\Infrastructure\Security\Idempotency(App::db());
        $key = 'sh-idem-' . bin2hex(random_bytes(8));

        // شبیه‌سازی Request موازی: Claim اول PENDING است
        $first = $idem->check($key, 'booking/confirm', 42, null);
        $this->assertFalse($first['is_replay']);

        $second = $idem->check($key, 'booking/confirm', 42, null);
        $this->assertTrue($second['is_replay']);
        $this->assertSame(409, $second['response_code']);
        $this->assertSame('CLINIC_DUPLICATE_IN_FLIGHT', $second['response']['error']);

        // release → تلاش مجدد ممکن
        $idem->release($key, 'booking/confirm', 42, null);
        $third = $idem->check($key, 'booking/confirm', 42, null);
        $this->assertFalse($third['is_replay']);
    }

    public function testIdempotencySameKeyDifferentScopeAllowed(): void
    {
        // دامنه = (key, endpoint, user, context) — همان کلید در زمینه دیگر مجاز است
        $idem = new \ClinicCore\Infrastructure\Security\Idempotency(App::db());
        $key = 'sh-idem-' . bin2hex(random_bytes(8));

        $a = $idem->check($key, 'booking/confirm', 42, null);
        $b = $idem->check($key, 'handwriting/page', 42, 77);
        $c = $idem->check($key, 'booking/confirm', 43, null);

        $this->assertFalse($a['is_replay']);
        $this->assertFalse($b['is_replay'], 'endpoint متفاوت = دامنه متفاوت (با u_idem_key قدیمی برخورد می‌کرد)');
        $this->assertFalse($c['is_replay'], 'کاربر متفاوت = دامنه متفاوت');
    }

    // ================= F9 — Retention/Cleanup Jobs (پیش‌تر مرده بودند) =================

    public function testCleanupJobsAreScheduledAndPurge(): void
    {
        App::scheduleRecurringJobs();

        // هر سه Job پاک‌سازی دقیقاً یک QUEUED (قبلاً Handlerها بدون زمان‌بندی بودند)
        foreach (['cleanup.otp', 'cleanup.rate_limits', 'cleanup.idem'] as $type) {
            $count = (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_jobs') . ' WHERE type = %s AND status = %s',
                [$type, \ClinicCore\Infrastructure\Queue\JobQueue::QUEUED]
            );
            $this->assertSame(1, $count, "job {$type} باید زمان‌بندی شود");
        }

        // cleanup.idem واقعاً پاک می‌کند: قدیمی (>90d) حذف، تازه می‌ماند
        global $wpdb;
        $t = $wpdb->prefix . 'cpms_idempotency_keys';
        $old = gmdate('Y-m-d H:i:s', time() - 91 * 86400) . '.000';
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$t} (`key`, clinic_id, wp_user_id, endpoint, context_id, status, created_at) VALUES ('sh-old-key', 1, 9, 'booking/confirm', 0, 1, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $old
        ));
        $fresh = new \ClinicCore\Infrastructure\Security\Idempotency(App::db());
        $fresh->check('sh-fresh-key', 'booking/confirm', 9, null);

        $deleted = (new \ClinicCore\Application\Jobs\IdemCleanupHandler(App::idem()))([]);
        $this->assertGreaterThanOrEqual(1, $deleted);

        $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE `key` IN ('sh-old-key', 'sh-fresh-key')"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->assertSame(1, $remaining, 'فقط کلید تازه باید باقی بماند');
    }

    // ================= Helpers =================

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login . bin2hex(random_bytes(3)), 'pass-12345', $login . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }

    private function dispatch(string $method, string $route, array $body = []): \WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

        return rest_do_request($request);
    }

    private function assertAuditCount(string $action, int $expected, string $resourceType, int $resourceId): void
    {
        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_audit_logs WHERE action = %s AND resource_type = %s AND resource_id = %d',
                $action,
                $resourceType,
                $resourceId
            ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        $this->assertSame($expected, $count, "audit {$action} on {$resourceType}#{$resourceId}");
    }
}
