<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Otp\OtpPolicy;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * owner decision A (2026-11-09, Option A) — OTP possession-only contract.
 *
 * A valid code proves phone possession ONLY. It must NEVER resolve, create,
 * link or authenticate a Patient or a WP account:
 *   - NO Patient lookup by mobile (any Clinic, any organization);
 *   - NO auto-link into cpms_patient_user_links;
 *   - NO mobile-derived WP account creation/reuse ({mobile}@otp.cpms.local
 *     has no authority);
 *   - NO session, therefore no LOGIN_SUCCESS audit;
 *   - the response is the SAME uniform 200 zero-access envelope for every
 *     valid code — existing Patient, non-existing mobile, pre-linked account,
 *     or staff-colliding bridge email are indistinguishable (non-enumeration).
 *
 * Shared mobile is never an ownership/access basis: two Patients in
 * DIFFERENT clinics/organizations sharing one mobile are equally unlinked.
 * (Two Patients in ONE clinic cannot share a mobile at all — schema key
 * u_pat_mobile (clinic_id, mobile) enforces it.)
 *
 * Patient access happens ONLY through a WP account explicitly linked to a
 * Patient by clinic staff — that staff-link flow is Slice 2 and is out of
 * scope here. A pre-existing link established by other means grants NOTHING
 * to OTP (see testVerifyWithExplicitPreExistingLinkStillIssuesNoAccess);
 * it documents the deliberate slice-1 gap.
 *
 * NON-VACUITY (owner decision A): every test in this class FAILS on live
 * main (pre-change verify() created/resolved/linked from the mobile, wrote
 * LOGIN_SUCCESS and could issue a session) and is intended to PASS on this
 * change. PHP is not available in the authoring sandbox, so the first
 * committed round was written by source inspection only — CI round 1
 * (2026-11-09, run 42762572435) then showed 6 of the failures here were
 * slice-1 TEST defects, not production defects: the seeded challenges were
 * stamped clinic_id=NULL (legacy) and a multi-Clinic fixture has no ambient
 * scope, so verify() failed closed with CLINIC_SCOPE_REQUIRED before any
 * setting/policy logic and before the uniform envelope could be produced;
 * the 503 path likewise asserted an absolute OTP_VERIFY_OK count of 0 even
 * though the audit table is shared across tests. Both were corrected in the
 * follow-up commit (clinic-stamped seeds; baseline-delta audit assertion).
 * Do not merge on a red or unrun suite; CI is the executable proof.
 */
final class OtpPossessionOnlyTest extends WP_UnitTestCase
{
    private const MOBILE = '09170000001';
    private const MOBILE_B = '09170000002';
    private const MOBILE_PRE_LINKED = '09170000003';
    private const MOBILE_503 = '09170000004';

    private int $clinicB;

    /** @var callable|null */
    private $settingsReadFault = null;

    private bool $previousSuppression = false;

    private ?string $previousRemoteAddr = null;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
        $this->previousRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;

        global $wpdb;
        $now = App::db()->nowUtcSql();

        // Clinic B in a SEPARATE organization (cross-org, cross-clinic).
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, created_at, updated_at)
                 VALUES (%s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'سازمان تصمیم مالک',
                'possession-org-b',
                $now,
                $now
            )
        );
        $orgB = (int) $wpdb->insert_id;
        $this->clinicB = 2;
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at)
                 VALUES (%d, %d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicB,
                $orgB,
                'کلینیک تصمیم مالک',
                'possession-b1',
                'Asia/Tehran',
                $now,
                $now
            )
        );

        // Same-mobile Patients in BOTH clinics (the exact shared-mobile case).
        $this->insertPatient(1, self::MOBILE, 'MR-POS-A', 'PossessionA', 'One');
        $this->insertPatient($this->clinicB, self::MOBILE, 'MR-POS-B', 'PossessionB', 'Two');
    }

    protected function tearDown(): void
    {
        $this->restoreSettingsBulkReads();
        Settings::flushCache();
        if ($this->previousRemoteAddr === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->previousRemoteAddr;
        }
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function testValidCodeForSharedMobileAcrossOrganizationsYieldsZeroAccess(): void
    {
        $this->issueKnownCode(self::MOBILE, '424242');

        $usersBefore = $this->userCount();
        $linksBefore = $this->linkCount();
        // Baseline-by-row-id (owner-facing audit-count defect, fixed 2026-11-10):
        // AuditLogger::sanitize() re-masks `mobile` to '***NNNN', so a LIKE on
        // after_json can never match what MobileValidator::mask produced.
        // Capture the high-water mark per event type and count rows after it.
        $loginAuditBefore = $this->auditMaxIdForAction('LOGIN_SUCCESS');
        $verifyOkBefore = $this->auditMaxIdForAction('OTP_VERIFY_OK');

        $result = App::otpService()->verify(self::MOBILE, '424242', \ClinicCore\Application\Auth\OtpService::PURPOSE_LOGIN);

        self::assertSame(0, (int) $result['user_id'], 'owner decision A: no mobile-derived account resolution.');
        self::assertSame([], (array) $result['patient_links'], 'owner decision A: no Patient resolution from mobile.');
        self::assertFalse((bool) $result['is_new_user']);
        self::assertFalse((bool) $result['session_issued'], 'owner decision A: no session is issued.');
        self::assertSame(0, get_current_user_id(), 'no account may be authenticated.');

        self::assertSame($usersBefore, $this->userCount(), 'no wp_users row may be created.');
        self::assertSame($linksBefore, $this->linkCount(), 'no Patient link may be created in ANY clinic.');
        self::assertSame(0, $this->linksForPatientMobile(self::MOBILE), 'no link may exist for any same-mobile Patient.');
        self::assertSame(0, $this->auditCountActionSince('LOGIN_SUCCESS', $loginAuditBefore), 'owner decision A: no session means no LOGIN_SUCCESS is written.');
        self::assertSame(1, $this->auditCountActionSince('OTP_VERIFY_OK', $verifyOkBefore), 'the mandatory possession audit is still written exactly once.');
    }

    public function testValidCodeForSharedMobileAcrossClinicsOfOneOrganizationYieldsZeroAccess(): void
    {
        // Same mobile, two clinics inside ONE organization (Org of clinic 1).
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $orgA = (int) $wpdb->get_var(
            'SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = 1'
        ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $clinicA2 = 3;
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at)
                 VALUES (%d, %d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicA2,
                $orgA,
                'کلینیک تصمیم مالک آ۲',
                'possession-a2',
                'Asia/Tehran',
                $now,
                $now
            )
        );
        $patientA2 = $this->insertPatient($clinicA2, self::MOBILE_B, 'MR-POS-A2', 'PossessionA2', 'OrgShared');
        $patientA1 = $this->insertPatient(1, self::MOBILE_B, 'MR-POS-A1B', 'PossessionA1B', 'OrgShared');

        $this->issueKnownCode(self::MOBILE_B, '313131');
        $result = App::otpService()->verify(self::MOBILE_B, '313131', \ClinicCore\Application\Auth\OtpService::PURPOSE_LOGIN);

        self::assertSame(0, (int) $result['user_id']);
        self::assertSame([], (array) $result['patient_links']);
        self::assertFalse((bool) $result['session_issued']);
        self::assertSame(0, $this->linksForPatient($patientA1), 'the same-organization booking-Clinic Patient must not be linked.');
        self::assertSame(0, $this->linksForPatient($patientA2), 'the same-organization sibling-Clinic Patient must not be linked.');
    }

    public function testNonExistingMobileYieldsTheIdenticalEnvelope(): void
    {
        $ghost = '09179999999'; // هیچ Patientی با این شماره نیست

        $this->issueKnownCode($ghost, '555555');
        $result = App::otpService()->verify($ghost, '555555', \ClinicCore\Application\Auth\OtpService::PURPOSE_LOGIN);

        self::assertSame(0, (int) $result['user_id']);
        self::assertSame([], (array) $result['patient_links']);
        self::assertFalse((bool) $result['is_new_user']);
        self::assertFalse((bool) $result['session_issued']);
        self::assertSame(0, get_current_user_id());
        self::assertSame(0, $this->usersWithEmail($ghost . '@otp.cpms.local'), 'no mobile-derived account may be provisioned.');
    }

    public function testRestEnvelopeIsIdenticalForExistingAndNonExistingNumbers(): void
    {
        $existingBody = $this->restVerifyWithFreshToken(self::MOBILE, '111111');
        $ghostBody = $this->restVerifyWithFreshToken('09178887777', '222222');

        self::assertIsArray($existingBody);
        self::assertIsArray($ghostBody);
        self::assertSame(array_keys($existingBody), array_keys($ghostBody), 'envelope keys must match.');
        self::assertSame($existingBody, $ghostBody, 'the 200 verification envelope must be byte-for-byte identical for existing and non-existing numbers.');
    }

    public function testVerifyWithExplicitPreExistingLinkStillIssuesNoAccess(): void
    {
        // لینکِ صریحِ ازپیش‌موجود (سبکی که Slice 2 توسط staff می‌سازد) — اینجا
        // با fixtureِ مستقیمِ DB شبیه‌سازی می‌شود. OTP نباید حتی این لینک را
        // «ببیند» یا از آن Session صادر کند.
        $patientId = $this->insertPatient(1, self::MOBILE_PRE_LINKED, 'MR-POS-LINK', 'Linked', 'Pre');
        $userId = (int) self::factory()->user->create(['role' => 'cpms_patient']);
        App::db()->insert('cpms_patient_user_links', [
            'clinic_id' => 1,
            'patient_id' => $patientId,
            'wp_user_id' => $userId,
            'mobile_at_link' => self::MOBILE_PRE_LINKED,
            'is_primary' => 1,
            'linked_at' => App::db()->nowUtcSql(),
        ]);

        $linksBefore = $this->linkCount();
        $usersBefore = $this->userCount();

        $this->issueKnownCode(self::MOBILE_PRE_LINKED, '909090');
        $result = App::otpService()->verify(self::MOBILE_PRE_LINKED, '909090', \ClinicCore\Application\Auth\OtpService::PURPOSE_LOGIN);

        self::assertSame(0, (int) $result['user_id'], 'owner decision A: OTP never resolves even an explicitly linked account.');
        self::assertSame([], (array) $result['patient_links'], 'OTP returns no Patient data — staff-linked access is a Slice 2 concern.');
        self::assertFalse((bool) $result['session_issued'], 'slice 1: even a pre-existing explicit link is NOT an OTP access basis.');
        self::assertSame(0, get_current_user_id());
        self::assertSame($linksBefore, $this->linkCount(), 'the pre-existing link must remain untouched.');
        self::assertSame($usersBefore, $this->userCount(), 'no additional account may be created.');
    }

    public function testVerifyRouteFailsClosedWith503WhenOtpSettingsUnreadable(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.99';
        $tokenId = $this->seedToken(self::MOBILE_503, '123456');
        $verifyOkBefore = $this->auditMaxIdForAction('OTP_VERIFY_OK');

        Settings::flushCache();
        $this->failSettingsBulkReads();
        $response = $this->dispatch('/clinic/v1/otp/verify', [
            'mobile' => self::MOBILE_503,
            'code' => '123456',
            'purpose' => 'login',
        ]);
        $this->restoreSettingsBulkReads();
        Settings::flushCache();

        self::assertInstanceOf(\WP_REST_Response::class, $response);
        self::assertSame(503, $response->get_status(), 'unreadable OTP settings must fail closed with the neutral 503 envelope.');
        $data = $response->get_data();
        self::assertSame('CLINIC_SERVICE_UNAVAILABLE', (string) ($data['code'] ?? ''));

        self::assertNull(
            $this->tokenConsumedAt($tokenId),
            'the challenge must NOT be consumed while settings are unreadable.'
        );
        self::assertSame(
            0,
            $this->auditCountActionSince('OTP_VERIFY_OK', $verifyOkBefore),
            'no OTP_VERIFY_OK audit may be written while settings are unreadable.'
        );
    }

    // ---------------------------------------------------------------

    private function insertPatient(int $clinicId, string $mobile, string $mrn, string $lastName, string $firstName): int
    {
        App::db()->insert('cpms_patients', [
            'clinic_id' => $clinicId,
            'mrn' => $mrn,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'mobile' => $mobile,
            'status' => 'active',
            'created_at' => App::db()->nowUtcSql(),
            'updated_at' => App::db()->nowUtcSql(),
        ]);

        return (int) App::db()->wpdb_last_insert_id();
    }

    private function linksForPatient(int $patientId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_patient_user_links') . ' WHERE patient_id = %d',
            [$patientId]
        );
    }

    private function linksForPatientMobile(string $mobile): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_patient_user_links l
                 INNER JOIN ' . $wpdb->prefix . 'cpms_patients p ON p.id = l.patient_id
                 WHERE p.mobile = %s',
                $mobile
            )
        ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private function linkCount(): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_patient_user_links')
        );
    }

    private function usersWithEmail(string $email): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM ' . $wpdb->users . ' WHERE user_email = %s', $email)
        ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private function userCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->users); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Highest audit-row id recorded for $action (0 when none). Combined with
     * auditCountActionSince() this gives a strictly-correct before/after
     * delta: the audit table is shared and append-only across the suite, and
     * AuditLogger::sanitize() re-masks the `mobile` change-set to '***NNNN',
     * so a value-scoped LIKE can never match reliably. A row-id baseline is
     * exact and cannot be polluted by other tests' rows.
     */
    private function auditMaxIdForAction(string $action): int
    {
        $row = App::db()->fetchRow(
            'SELECT MAX(id) AS max_id FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s',
            [$action]
        );

        return (int) ($row['max_id'] ?? 0);
    }

    /**
     * Number of audit rows for $action with id strictly greater than $baseline.
     * Non-vacuous by construction: if the OTP path writes (or fails to write)
     * the event, this delta flips from 0 to at least 1 (or stays 0), failing the
     * caller's expectation accordingly.
     */
    private function auditCountActionSince(string $action, int $baseline): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND id > %d',
            [$action, $baseline]
        );
    }

    /**
     * Seed a login-purpose challenge stamped to the default Clinic (id=1).
     *
     * owner decision A (2026-11-09): a challenge stamped to a Clinic resolves
     * the OTP policy from that Clinic alone — no ambient scope and no client
     * authority involved. A LEGACY NULL-clinic challenge in a multi-Clinic
     * fixture without ambient scope fails closed with CLINIC_SCOPE_REQUIRED
     * by design (see OtpService::verify()); this test suite therefore stamps
     * clinic_id=1 explicitly instead of relying on implicit resolution.
     */
    private function issueKnownCode(string $mobile, string $code, int $ttlSec = 120): void
    {
        $pepper = defined('CPMS_PEPPER') && (string) CPMS_PEPPER !== ''
            ? (string) CPMS_PEPPER
            : (string) get_option('cpms_otp_pepper', '');
        if ($pepper === '') {
            $pepper = bin2hex(random_bytes(32));
            update_option('cpms_otp_pepper', $pepper, 'no');
        }
        App::db()->insert('cpms_otp_tokens', [
            'clinic_id' => 1,
            'mobile' => $mobile,
            'purpose' => 'login',
            'code_hash' => OtpPolicy::hashCode($code, $pepper),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttlSec) . '.000',
            'attempts' => 0,
            'created_at' => App::db()->nowUtcSql(),
        ]);
    }

    private function seedToken(string $mobile, string $code): int
    {
        $this->issueKnownCode($mobile, $code, 300);

        return (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_otp_tokens') . ' WHERE mobile = %s ORDER BY id DESC LIMIT 1',
            [$mobile]
        );
    }

    private function tokenConsumedAt(int $tokenId): ?string
    {
        $value = App::db()->fetchValue(
            'SELECT consumed_at FROM ' . App::db()->table('cpms_otp_tokens') . ' WHERE id = %d',
            [$tokenId]
        );

        return $value === null ? null : (string) $value;
    }

    private function restVerifyWithFreshToken(string $mobile, string $code): array
    {
        // Seed a clinic-stamped challenge directly. The public A2 route in a
        // multi-Clinic fixture without an explicit bookable selection fails
        // closed with CLINIC_SCOPE_REQUIRED by design (no client-supplied
        // Clinic authority); the envelope property under test does not depend
        // on A2 mechanics, so both sides of the comparison use the same
        // seeding as the rest of this class.
        $this->issueKnownCode($mobile, $code, 120);
        $response = $this->dispatch('/clinic/v1/otp/verify', [
            'mobile' => $mobile,
            'code' => $code,
            'purpose' => 'login',
        ]);
        self::assertSame(200, $response->get_status(), 'a valid code is a possession proof: uniform 200.');

        return (array) $response->get_data();
    }

    private function dispatch(string $route, array $params): mixed
    {
        $request = new \WP_REST_Request('POST', $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return rest_get_server()->dispatch($request);
    }

    private function failSettingsBulkReads(): void
    {
        global $wpdb;
        self::assertNull($this->settingsReadFault, 'the settings read fault must not be installed twice');
        $clinic_id      = 1;
        $settings_table = $wpdb->prefix . 'cpms_settings';

        $fault = static function ($query) use ($clinic_id, $settings_table) {
            $query = (string) $query;
            if (
                str_contains($query, 'SELECT `key`, value_json FROM ' . $settings_table)
                && preg_match('/WHERE clinic_id = ' . $clinic_id . '\\s*$/', $query) === 1
            ) {
                return str_replace('FROM ' . $settings_table, 'FROM ' . $settings_table . '_read_failure', $query);
            }

            return $query;
        };

        $this->previousSuppression = (bool) $wpdb->suppress_errors(true);
        $this->settingsReadFault   = $fault;
        add_filter('query', $fault, 5, 1);
    }

    private function restoreSettingsBulkReads(): void
    {
        if ($this->settingsReadFault === null) {
            return;
        }
        remove_filter('query', $this->settingsReadFault, 5);
        $this->settingsReadFault = null;

        global $wpdb;
        $wpdb->suppress_errors($this->previousSuppression);
    }
}
