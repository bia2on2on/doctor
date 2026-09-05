<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Visits\VisitException;
use WP_UnitTestCase;

/**
 * TP-03b — Concurrency ویزیت (F4): دو «پایان ویزیت» (V10) هم‌زمان →
 * دقیقاً یکی موفق، دیگری CLINIC_INVALID_TRANSITION.
 *
 * دو لایه اثبات (الگوی ConcurrencyTest F3):
 *  1. DB-level: دو پرداز موازی واقعی (pcntl_fork + اتصال mysqli مستقل) Conditional
 *     UPDATE `status='in_consultation'` → فقط یکی affected_rows=1.
 *  2. Service-level: تکرار complete از خود VisitService → دومی
 *     CLINIC_INVALID_TRANSITION (ماشین + Row Lock).
 *
 * نکته: Fixtureها COMMIT می‌شوند تا پردازهای مستقل ببینند (tearDown ROLLBACK
 * بعد از commit بی‌اثر است → Cleanup دستی).
 */
final class VisitConcurrencyTest extends WP_UnitTestCase
{
    private int $clinicianId = 0;
    private int $secretaryUserId = 0;
    private int $doctorUserId = 0;
    private int $patientId = 0;

    /** @var list<int> */
    private array $visitIds = [];

    /** @var list<int> */
    private array $patientIds = [];

    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::settings()->set('queue.auto_enqueue', true);

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, is_active, created_at, updated_at)
                 VALUES (1, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr Visit Concurrency',
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;

        $this->secretaryUserId = $this->makeUser('vc_secretary', 'cpms_secretary');
        $this->doctorUserId = $this->makeUser('vc_doctor', 'cpms_doctor');
    }

    protected function tearDown(): void
    {
        // Cleanup دستی — Fixtureهای commit شده با ROLLBACK پاک نمی‌شوند
        foreach ($this->visitIds as $visitId) {
            App::db()->query('DELETE FROM ' . App::db()->table('cpms_visit_status_history') . ' WHERE visit_id = %d', [$visitId]);
            App::db()->query('DELETE FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [$visitId]);
        }
        foreach ($this->patientIds as $patientId) {
            App::db()->query('DELETE FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d', [$patientId]);
        }
        if ($this->clinicianId > 0) {
            App::db()->query('DELETE FROM ' . App::db()->table('cpms_clinicians') . ' WHERE id = %d', [$this->clinicianId]);
        }
        // usersها با COMMIT واقعی تست leak شده‌اند → حذف دستی (ایجاد دوباره در تست
        // بعدی همین کلاس با همان login → WP_Error)
        global $wpdb;
        foreach ($this->userIds as $userId) {
            $wpdb->delete($wpdb->usermeta, ['user_id' => $userId], ['%d']);
            $wpdb->delete($wpdb->users, ['ID' => $userId], ['%d']);
        }
        parent::tearDown();
    }

    /**
     * لایه 1 — DB-level: دو پرداز موازی، Conditional UPDATE وضعیت.
     * دقیقاً یکی باید موفق شود (J-1 یکتایی V10 در سطح پایگاه داده).
     */
    public function testParallelCompleteExactlyOneSucceeds(): void
    {
        $visitId = $this->committedVisitInConsultation();

        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available (CI Linux has it)');
        }

        $sql = 'UPDATE ' . App::db()->table('cpms_visits') .
            ' SET status = "consultation_completed", consultation_completed_at = ? WHERE id = ? AND status = "in_consultation"';

        $successes = $this->forkWorkers(2, $sql, $visitId);

        $this->assertSame(1, $successes, 'از دو complete هم‌زمان دقیقاً یکی باید موفق شود');

        $status = (string) App::db()->fetchValue(
            'SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d',
            [$visitId]
        );
        $this->assertSame('consultation_completed', $status);
    }

    /**
     * لایه 2 — Service-level: complete دوم از طریق VisitService → CLINIC_INVALID_TRANSITION.
     */
    public function testSecondCompleteViaServiceIsInvalidTransition(): void
    {
        $visitId = $this->committedVisitInConsultation();

        // اولی — از طریق خود Service (وضعیت in_consultation از Fixture)
        $visit = App::visitService()->transition($this->doctorUserId, $visitId, 'complete', []);
        $this->assertSame('consultation_completed', $visit['status']);

        try {
            App::visitService()->transition($this->doctorUserId, $visitId, 'complete', []);
            $this->fail('Expected CLINIC_INVALID_TRANSITION on second complete');
        } catch (VisitException $e) {
            $this->assertSame('CLINIC_INVALID_TRANSITION', $e->errorCode);
            $this->assertSame(409, $e->httpStatus);
        }
    }

    /**
     * لایه 3 — Lock واقعی Row: اتصال مستقل FOR UPDATE می‌گیرد؛ سرویس والد باید
     * قفل را دریافت نکند تا اولی تمام شود (serialization اثبات می‌شود).
     */
    public function testRowLockSerializesConcurrentTransition(): void
    {
        $visitId = $this->committedVisitInConsultation();

        $conn = $this->freshMysqli();
        $conn->query('START TRANSACTION');
        $locked = $conn->query(
            'SELECT id FROM ' . App::db()->table('cpms_visits') . ' WHERE id = ' . (int) $visitId . ' FOR UPDATE'
        );
        $this->assertNotFalse($locked);

        // والد: lock wait کوتاه → transition باید منتظر بماند و بعد از timeout خطای DB بدهد
        global $wpdb;
        $wpdb->query('SET SESSION innodb_lock_wait_timeout = 2'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $blocked = false;
        try {
            App::visitService()->transition($this->doctorUserId, $visitId, 'complete', []);
        } catch (\Throwable $e) {
            // Lock wait timeout → خطای DB (serialization اثبات شد)
            $blocked = true;
        }
        $this->assertTrue($blocked, 'transition باید تا آزاد شدن قفل بلاک/خطا شود');

        $conn->query('ROLLBACK');
        $conn->close();

        // بعد از آزادسازی → همان عملیات موفق
        $visit = App::visitService()->transition($this->doctorUserId, $visitId, 'complete', []);
        $this->assertSame('consultation_completed', $visit['status']);

        // بازگردانی timeout پیش‌فرض session مشترک برای تست‌های بعدی
        $wpdb->query('SET SESSION innodb_lock_wait_timeout = DEFAULT'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    // ================= Fixture (committed) =================

    /**
     * ساخت Visit در وضعیت in_consultation + COMMIT برای دیدن توسط پردازهای مستقل.
     */
    private function committedVisitInConsultation(): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, "C", "Patient", %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-VC-' . bin2hex(random_bytes(4)),
                '0912555' . sprintf('%04d', random_int(0, 9999)),
                $now,
                $now
            )
        );
        $patientId = (int) $wpdb->insert_id;
        $this->patientIds[] = $patientId;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_visits
                     (clinic_id, clinician_id, patient_id, source, status, visit_date, check_in_at,
                      waiting_since, called_at, consultation_started_at, active, created_at, updated_at)
                 VALUES (1, %d, %d, "walk_in", "in_consultation", %s, %s, %s, %s, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicianId,
                $patientId,
                gmdate('Y-m-d'),
                $now,
                $now,
                $now,
                $now,
                $now,
                $now
            )
        );
        $visitId = (int) $wpdb->insert_id;
        $this->visitIds[] = $visitId;

        // COMMIT تا اتصال‌های مستقل ببینند
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return $visitId;
    }

    // ================= Fork orchestration (الگوی ConcurrencyTest) =================

    private function forkWorkers(int $count, string $sql, int $visitId): int
    {
        $pids = [];
        for ($i = 0; $i < $count; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork failed');
            }
            if ($pid === 0) {
                /*
                 * Child: اتصال مستقل + یک Conditional UPDATE.
                 * هر خطا → exit(1): اگر استثنا فرار کند PHPUnitِ کپی‌شده ادامه می‌دهد.
                 */
                $exitCode = 1;
                $mysqli = null;
                try {
                    $mysqli = $this->freshMysqli();
                    $stmt = $mysqli->prepare($sql);
                    if ($stmt !== false) {
                        $ts = gmdate('Y-m-d H:i:s') . '.000';
                        if ($stmt->bind_param('si', $ts, $visitId)) {
                            $stmt->execute();
                            $exitCode = $stmt->affected_rows === 1 ? 0 : 1;
                            $stmt->close();
                        }
                    }
                } catch (\Throwable $e) {
                    $exitCode = 1;
                }
                if ($mysqli instanceof \mysqli) {
                    @$mysqli->close();
                }
                exit($exitCode);
            }
            $pids[] = $pid;
        }

        $successes = 0;
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            if (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0) {
                $successes++;
            }
        }

        return $successes;
    }

    private function freshMysqli(): \mysqli
    {
        global $wpdb;
        $host = $wpdb->dbhost;
        $user = $wpdb->dbuser;
        $pass = $wpdb->dbpassword;
        $name = $wpdb->dbname;

        $mysqli = @new \mysqli($host, $user, $pass, $name);
        if ($mysqli->connect_errno !== 0) {
            $this->markTestSkipped('Cannot open independent DB connection: ' . $mysqli->connect_error);
        }
        $mysqli->set_charset('utf8mb4');

        return $mysqli;
    }

    private function makeUser(string $login, string $role): int
    {
        // نام یکتا در هر تست — این کلاس COMMIT واقعی می‌زند و users والد
        // transaction تست را نشت می‌دهد؛ تکرار login در تست بعدی WP_Error می‌دهد.
        $login .= '_' . bin2hex(random_bytes(4));
        $userId = (int) wp_create_user($login, 'pass-12345', $login . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }
        $this->userIds[] = $userId;

        return $userId;
    }
}
