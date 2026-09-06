<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Auth\RolesAndCapabilities;
use WP_UnitTestCase;

/**
 * TP-10 — Matrix Parametrized: تولید خودکار از `docs/permissions/permission-matrix.md`.
 *
 * سند ماتریس منبع حقیقت است؛ این تست آن را parse می‌کند و با کد
 * (`RolesAndCapabilities`) و نقش‌های زنده WordPress تطبیق می‌دهد:
 *
 *  1) فهرست Capabilityهای سند (§2) == ALL_CAPS کد — نه اضافه، نه کم
 *     (قانون کارفرما: Capability اضافی بدون Use Case واقعی ممنوع).
 *  2) ستون منشی/پزشک/Admin جدول §3 == مجموعه‌های SECRETARY_CAPS/DOCTOR_CAPS
 *     و اعطای پیش‌فرض Admin.
 *  3) ستون بیمار هرگز ✅ نیست (P-5 — Patient فقط Ownership).
 *  4) نقش‌های زنده WP دقیقاً همان مجموعه‌ها را دارند (Backend Enforcement).
 *  5) Self-healing: cap اضافه‌شده دستی روی نقش، بعد از register() حذف می‌شود.
 *  6) تفکیک Least-Privilege capهای حساس بالینی (Private Note / Prescription /
 *     Note / Medical / Consult) — فقط پزشک؛ Queue اکشن‌ها مطابق ماتریس.
 *
 * ریشه‌یابی drift قبلی «۴۹/۴۶» (بند §8 راهنما): ۴۹ = ۴۶ ثابتِ Capability +
 * ۳ ثابتِ نقش (ROLE_* که مقدارشان هم با cpms_ شروع می‌شود) — خطای شمارش،
 * نه drift واقعی؛ این تست از تکرارش جلوگیری می‌کند.
 */
final class PermissionMatrixTest extends WP_UnitTestCase
{
    /** @var array<string, string[]> slug → [patient, secretary, doctor, admin] به‌صورت bool‌های ✅/❌ */
    private array $matrixRoles = [];

    /** @var list<string> فهرست slugهای سند §2 */
    private array $matrixSlugs = [];

    private const MARK = '✅';

    protected function setUp(): void
    {
        parent::setUp();

        $doc = realpath(dirname(__DIR__, 3) . '/docs/permissions/permission-matrix.md');
        if ($doc === false) {
            $this->fail('permission-matrix.md not found (repo root/docs/permissions/)');
        }
        $lines = explode("\n", (string) file_get_contents($doc));

        // ---- §2: فهرست slugها (بین «## 2.» و «## 3.»)
        $section = 0;
        foreach ($lines as $line) {
            if (str_starts_with($line, '## 2.')) {
                $section = 2;
                continue;
            }
            if (str_starts_with($line, '## 3.')) {
                $section = 3;
                continue;
            }
            if (str_starts_with($line, '## 4.')) {
                $section = 0;
                continue;
            }

            if ($section === 2 && preg_match_all('/`(cpms_[a-z0-9_]+)`/', $line, $m)) {
                foreach ($m[1] as $slug) {
                    if (!in_array($slug, $this->matrixSlugs, true)) {
                        $this->matrixSlugs[] = $slug;
                    }
                }
            }

            // ---- §3: جدول نقش‌ها — فقط سطرهای «| cpms_... |» (جدول، نه توضیحات)
            if ($section === 3 && preg_match('/^\|\s*cpms_[a-z0-9_]+/', $line)) {
                $cells = array_values(array_filter(array_map('trim', explode('|', $line)), static fn (string $c): bool => $c !== ''));
                if (count($cells) !== 5) {
                    $this->fail('Unexpected §3 row shape: ' . $line);
                }
                [$capCell, $patient, $secretary, $doctor, $admin] = $cells;

                // «cpms_x_read / create / update» → cpms_x_read, cpms_x_create, cpms_x_update
                $parts = array_map('trim', explode('/', $capCell));
                $first = array_shift($parts);
                $prefix = substr($first, 0, (int) strrpos($first, '_') + 1);
                $slugs = [$first];
                foreach ($parts as $action) {
                    $slugs[] = $prefix . $action;
                }

                foreach ($slugs as $slug) {
                    $this->matrixRoles[$slug] = [$patient, $secretary, $doctor, $admin];
                }
            }
        }

        $this->assertNotEmpty($this->matrixSlugs, 'No slugs parsed from §2');
        $this->assertNotEmpty($this->matrixRoles, 'No role rows parsed from §3');
    }

    // ================= 1) فهرست Capability: سند ↔ کد =================

    public function testAllCapsExactlyMatchMatrix(): void
    {
        $code = RolesAndCapabilities::ALL_CAPS;
        sort($code);
        $doc = $this->matrixSlugs;
        sort($doc);

        $this->assertSame(count(array_unique($doc)), count($doc), 'Duplicate slug in matrix §2');
        $this->assertSame($doc, $code, 'ALL_CAPS must exactly equal permission-matrix §2 (no extra, no missing)');
        $this->assertCount(46, $code, 'Matrix total is 46 capabilities');
    }

    // ================= 2) نقش‌ها: سند ↔ کد =================

    public function testSecretaryCapsExactlyMatchMatrix(): void
    {
        $actual = RolesAndCapabilities::SECRETARY_CAPS;
        sort($actual);
        $this->assertSame($this->expectedForRole(1), $actual);
    }

    public function testDoctorCapsExactlyMatchMatrix(): void
    {
        $actual = RolesAndCapabilities::DOCTOR_CAPS;
        sort($actual);
        $this->assertSame($this->expectedForRole(2), $actual);
    }

    public function testAdminDefaultCapsAreTechnicalOnly(): void
    {
        $admin = $this->expectedForRole(3);
        $this->assertSame(
            ['cpms_config', 'cpms_sms_config'],
            $admin,
            'WP Admin default must be technical-only (P-3): cpms_config + cpms_sms_config'
        );
    }

    // ================= 3) Patient: بدون Capability (P-5) =================

    public function testPatientColumnNeverGrantsCapability(): void
    {
        foreach ($this->matrixRoles as $slug => $cols) {
            $this->assertStringNotContainsString(
                self::MARK,
                $cols[0],
                "Matrix grants capability to patient (P-5 violation): {$slug}"
            );
        }
    }

    public function testPatientRoleHasNoCpmsCaps(): void
    {
        $role = get_role(RolesAndCapabilities::ROLE_PATIENT);
        $this->assertNotFalse($role, 'cpms_patient role missing');
        $cpms = array_filter(array_keys($role->capabilities), static fn (string $c): bool => str_starts_with($c, 'cpms_'));
        $this->assertSame([], array_values($cpms), 'Patient role must not hold any cpms_* capability');
    }

    // ================= 4) نقش‌های زنده WP مطابق ماتریس =================

    public function testLiveWpRolesMatchMatrix(): void
    {
        RolesAndCapabilities::register();

        $this->assertSame(
            $this->expectedForRole(1),
            $this->liveCpmsCaps(RolesAndCapabilities::ROLE_SECRETARY)
        );
        $this->assertSame(
            $this->expectedForRole(2),
            $this->liveCpmsCaps(RolesAndCapabilities::ROLE_DOCTOR)
        );

        $admin = get_role('administrator');
        $this->assertNotFalse($admin);
        $adminCpms = array_values(array_filter(
            array_keys($admin->capabilities),
            static fn (string $c): bool => str_starts_with($c, 'cpms_')
        ));
        sort($adminCpms);
        $this->assertSame(['cpms_config', 'cpms_sms_config'], $adminCpms);
    }

    // ================= 5) Self-healing نقش‌ها (sync در register) =================

    public function testRegisterRemovesStrayCapabilityFromRole(): void
    {
        $secretary = get_role(RolesAndCapabilities::ROLE_SECRETARY);
        $this->assertNotFalse($secretary);
        $secretary->add_cap('cpms_drift_probe');

        RolesAndCapabilities::register();

        $this->assertSame(
            $this->expectedForRole(1),
            $this->liveCpmsCaps(RolesAndCapabilities::ROLE_SECRETARY),
            'register() must remove capabilities not in the matrix set (drift self-healing)'
        );
    }

    // ================= 6) تفکیک Least-Privilege بالینی (دستور F5 کارفرما) =================

    public function testSensitiveClinicalCapsAreDoctorOnly(): void
    {
        $doctor = RolesAndCapabilities::DOCTOR_CAPS;
        $secretary = RolesAndCapabilities::SECRETARY_CAPS;

        // Clinical record + Private notes + Prescription + Consultation — فقط پزشک
        $doctorOnly = array_merge(
            [
                RolesAndCapabilities::MEDICAL_READ,
                RolesAndCapabilities::NOTE_CREATE,
                RolesAndCapabilities::NOTE_UPDATE,
                RolesAndCapabilities::REC_CREATE,
            ],
            [
                RolesAndCapabilities::PRIVATE_NOTE_READ,
                RolesAndCapabilities::PRIVATE_NOTE_CREATE,
                RolesAndCapabilities::PRIVATE_NOTE_UPDATE,
            ],
            [
                RolesAndCapabilities::RX_READ,
                RolesAndCapabilities::RX_CREATE,
                RolesAndCapabilities::RX_VOID,
            ],
            [
                RolesAndCapabilities::CONSULT_START,
                RolesAndCapabilities::CONSULT_COMPLETE,
                RolesAndCapabilities::CONSULT_REOPEN,
            ]
        );
        foreach ($doctorOnly as $cap) {
            $this->assertContains($cap, $doctor, "{$cap} must belong to doctor");
            $this->assertNotContains($cap, $secretary, "{$cap} must NOT leak to secretary (Least Privilege)");
        }
    }

    public function testQueueActionCapsAreSplitBetweenRoles(): void
    {
        // منشی: check-in/advance/checkout — پزشک: call (فراخوان)
        foreach ([RolesAndCapabilities::QUEUE_CHECKIN, RolesAndCapabilities::QUEUE_ADVANCE, RolesAndCapabilities::QUEUE_CHECKOUT] as $cap) {
            $this->assertContains($cap, RolesAndCapabilities::SECRETARY_CAPS);
            $this->assertNotContains($cap, RolesAndCapabilities::DOCTOR_CAPS);
        }
        $this->assertContains(RolesAndCapabilities::QUEUE_CALL, RolesAndCapabilities::DOCTOR_CAPS);
        $this->assertNotContains(RolesAndCapabilities::QUEUE_CALL, RolesAndCapabilities::SECRETARY_CAPS);

        // فایل‌های پزشکی: آپلود/خواندن برای هر دو نقش کارکنان (ماتریس §3)
        foreach ([RolesAndCapabilities::FILE_UPLOAD, RolesAndCapabilities::FILE_READ] as $cap) {
            $this->assertContains($cap, RolesAndCapabilities::SECRETARY_CAPS);
            $this->assertContains($cap, RolesAndCapabilities::DOCTOR_CAPS);
        }
    }

    // ================= Helpers =================

    /**
     * ستون نقش (0=بیمار 1=منشی 2=پزشک 3=Admin) از ماتریس §3 → فهرست slugهای ✅ (مرتب).
     *
     * @return list<string>
     */
    private function expectedForRole(int $col): array
    {
        $caps = [];
        foreach ($this->matrixRoles as $slug => $cols) {
            if (str_contains($cols[$col], self::MARK)) {
                $caps[] = $slug;
            }
        }
        sort($caps);

        return $caps;
    }

    /**
     * Capهای cpms_* نقش زنده WP (مرتب).
     *
     * @return list<string>
     */
    private function liveCpmsCaps(string $roleSlug): array
    {
        $role = get_role($roleSlug);
        if ($role === false || $role === null) {
            $this->fail("Role {$roleSlug} not registered");
        }
        $caps = array_values(array_filter(
            array_keys($role->capabilities),
            static fn (string $c): bool => str_starts_with($c, 'cpms_')
        ));
        sort($caps);

        return $caps;
    }
}
