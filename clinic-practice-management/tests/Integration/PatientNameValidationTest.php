<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * M4 — Patient name entrances must reject empty, invisible-only and
 * bidi-control names, and must preserve valid Persian names with ZWNJ.
 *
 * M4 + owner option B (update paths only) — Through-service and
 * REST-dispatch coverage of the shared first_name/last_name validator
 * (PatientService::validate_for_update -> cleanName): the secretary create
 * (D4) path, staff record edits (D5, PUT /patients/{id}) and self-service
 * edits (C2, PUT /patient/me). Option B (approved 2026-11-07, update paths
 * only): an explicitly submitted empty / null / whitespace- or
 * invisible-only name is a hard validation error (400
 * CLINIC_VALIDATION_FAILED) which never partially persists — the whole
 * request is rejected. Omitting the key preserves the stored value while
 * other edits apply normally. Create (D4/new-patient booking) is UNCHANGED.
 */
final class PatientNameValidationTest extends WP_UnitTestCase
{
    private int $secretaryUserId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();

        // Note (rounds-29/32): wp_create_user accepts only
        // (username, password, email) — a fourth array argument is silently
        // ignored and does NOT grant cpms_secretary. Service-level tests
        // below need no WordPress role (authorization for D4/D5/C2 at the
        // service layer lives in membership/capability checks and the
        // patient↔user link respectively). The REST-dispatch fixtures at
        // the bottom DO set the actual roles and seed clinic-scoped
        // MembershipService rows via makeRestStaff()/makeRestLinkedSelfFixture().
        $this->secretaryUserId = (int) wp_create_user('secretary_name_tests', 'pass-12345', 'secname@test.local');
    }

    protected function tearDown(): void
    {
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    private function service()
    {
        return App::patientService();
    }

    private function baseFields(): array
    {
        static $n = 0;
        $n++;
        return [
            'first_name' => 'علی',
            'last_name' => 'تست',
            'mobile' => '0912' . str_pad((string) (1000000 + $n), 7, '0', STR_PAD_LEFT),
        ];
    }

    private function createPatient(array $overrides): array
    {
        return $this->service()->create(array_merge($this->baseFields(), $overrides), $this->secretaryUserId);
    }


    /**
     * Clinic id of the seeded default clinic (slug 'default', created by the
     * initial migration), so fixture rows can reference a durable Clinic.
     */
    private function defaultClinicId(): int
    {
        global $wpdb;
        $id = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE slug = %s LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'default'
            )
        );
        if ($id <= 0) {
            $this->fail('the default seeded clinic was not found');
        }

        return $id;
    }

    /**
     * Deterministic linked-patient fixture for the C2 (self-update) boundary,
     * built through the PRODUCTION-authorized path — the same shape the OTP
     * confirm + patient self-enrollment flow produces in production:
     *
     *   1. a fresh WP user with the `cpms_patient` role;
     *   2. an Organization Patient Identity for that user, explicitly linked
     *      (link_user) — the counterpart of OTP-verified identity in prod;
     *   3. `enroll_current_patient()` executed as that user, which mints the
     *      clinical row BOUND to the same identity (create_record reuses the
     *      verified identity and writes the patient↔user link + audit atomically).
     *
     * This satisfies `PatientRepository::clinical_access_identity_predicate()`
     * authentically (pil row with `pil.wp_user_id = l.wp_user_id`) instead of
     * bypassing it — an unbound, non-NULL `identity_id` patient can never pass
     * the predicate (that was the round-33 integration failure).
     *
     * @return array{patient_id: int, user_id: int, link_id: int, clinic_id: int, mobile: string}
     */
    private function makeLinkedSelfFixture(string $mobile, bool $withPortalRole = true): array
    {
        $clinicId = $this->defaultClinicId();
        global $wpdb;

        $orgId = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = %d LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId
            )
        );
        if ($orgId <= 0) {
            $this->fail('the default clinic has no organization');
        }

        $userId = (int) wp_create_user(
            'self_' . str_replace(' ', '_', $mobile),
            'pass-12345',
            $mobile . '@self.test.local'
        );
        if ($userId <= 0) {
            $this->fail('self fixture user was not created');
        }
        if ($withPortalRole) {
            $user = get_userdata($userId);
            if ($user === false) {
                $this->fail('fresh self fixture user not found');
            }
            $user->set_role('cpms_patient');
        }

        $identityService = App::patient_identity_service();
        $identityId = (int) $identityService->create_identity($orgId, $mobile);
        $this->assertGreaterThan(0, $identityId, 'identity mint must succeed');
        $identityService->link_user($orgId, $identityId, $userId, $mobile);

        // The authorized self-enrollment boundary: creates the patient BOUND to
        // the same identity and writes the patient↔user link + audit atomically.
        wp_set_current_user($userId);
        try {
            $enrolled = $this->service()->enroll_current_patient($clinicId, [
                'first_name' => 'خودی',
                'last_name' => 'تستی',
                'mobile' => $mobile,
            ]);
        } finally {
            wp_set_current_user(0);
        }
        $patientId = (int) $enrolled['patient_id'];

        $linkId = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . $wpdb->prefix . 'cpms_patient_user_links WHERE patient_id = %d AND wp_user_id = %d LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $patientId,
                $userId
            )
        );
        if ($linkId <= 0) {
            $this->fail('the authorized enrollment did not write the patient↔user link');
        }

        // Defense-in-depth: the identity edge the predicate requires exists.
        $edge = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . "cpms_patient_identity_links pil
                   INNER JOIN " . $wpdb->prefix . "cpms_patient_identities pi
                      ON pi.id = pil.identity_id AND pi.organization_id = pil.organization_id
                  WHERE pil.identity_id = %d AND pil.wp_user_id = %d AND pil.organization_id = %d",
                $identityId,
                $userId,
                $orgId
            )
        );
        $this->assertGreaterThan(0, $edge, 'the patient identity must be bound to the self user');

        return [
            'patient_id' => $patientId,
            'user_id' => $userId,
            'link_id' => $linkId,
            'clinic_id' => $clinicId,
            'mobile' => $mobile,
        ];
    }

    public function testCreateStoresValidPersianZwnjNameExactly(): void
    {
        $created = $this->createPatient([
            'first_name' => "علی\u{200C}رضا", // ZWNJ inside a Persian word — valid
            'last_name' => "طاهری",
        ]);
        $stored = $this->service()->get((int) $created['id']);
        $this->assertSame("علی\u{200C}رضا", (string) $stored['first_name']);
        $this->assertSame('طاهری', (string) $stored['last_name']);
    }

    public function testCreateStoresTrimmedValidatedNameNotRawInput(): void
    {
        $created = $this->createPatient([ 'first_name' => "  علی  " ]);
        $stored = $this->service()->get((int) $created['id']);
        $this->assertSame('علی', (string) $stored['first_name']);
    }

    public function testCreateRejectsEmptyFirstName(): void
    {
        $this->expectException(BookingException::class);
        try {
            $this->createPatient([ 'first_name' => '' ]);
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            $this->assertSame('نام و نام خانوادگی الزامی است', $e->getMessage());
            throw $e;
        }
    }

    public function testCreateRejectsWhitespaceOnlyFirstName(): void
    {
        $this->expectException(BookingException::class);
        try {
            $this->createPatient([ 'first_name' => "   \t  " ]);
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            throw $e;
        }
    }

    public function testCreateRejectsInvisibleOnlyFirstName(): void
    {
        $this->expectException(BookingException::class);
        try {
            $this->createPatient([ 'first_name' => "\u{200B}\u{200C}\u{2060}" ]);
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            $this->assertSame(
                'نام نمی‌تواند فقط از نویسه‌های نامرئی یا فاصله تشکیل شده باشد',
                $e->getMessage()
            );
            throw $e;
        }
    }

    public function testCreateRejectsBidiControlInFirstName(): void
    {
        $this->expectException(BookingException::class);
        try {
            $this->createPatient([ 'first_name' => "علی\u{202E}رضا" ]); // RLO
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            $this->assertSame('نام حاوی نویسه‌های کنترلِ جهت نوشتار است', $e->getMessage());
            throw $e;
        }
    }

    public function testCreateRejectsBidiControlInLastName(): void
    {
        $this->expectException(BookingException::class);
        try {
            $this->createPatient([ 'last_name' => "تست\u{2066}" ]); // LRI
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            $this->assertSame('نام حاوی نویسه‌های کنترلِ جهت نوشتار است', $e->getMessage());
            throw $e;
        }
    }

    public function testStaffUpdateRejectsBidiControlAndPreservesStoredRow(): void
    {
        $created = $this->createPatient([]);
        $before = $this->service()->get((int) $created['id']);

        try {
            $this->service()->update((int) $created['id'], [
                'first_name' => "تست\u{202E}",
            ], $this->secretaryUserId);
            $this->fail('a bidi-control name must be rejected on the update path');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            $this->assertSame(
                'نام حاوی نویسه‌های کنترلِ جهت نوشتار است',
                $e->getMessage()
            );
        }

        $after = $this->service()->get((int) $created['id']);
        $this->assertSame((string) $before['first_name'], (string) $after['first_name'],
            'a rejected update must not alter the stored name');
    }

    public function testStaffUpdatePersistsValidZwnjNameExactly(): void
    {
        $created = $this->createPatient([]);
        $this->service()->update((int) $created['id'], [
            'first_name' => "نیما\u{200C}طاهری",
            'last_name' => "زکی\u{200C}زاده",
        ], $this->secretaryUserId);
        $stored = $this->service()->get((int) $created['id']);
        $this->assertSame("نیما\u{200C}طاهری", (string) $stored['first_name']);
        $this->assertSame("زکی\u{200C}زاده", (string) $stored['last_name']);
    }

    public function testStaffUpdateRejectsWhitespaceOnlyName(): void
    {
        $created = $this->createPatient([]);
        $this->expectException(BookingException::class);
        try {
            $this->service()->update((int) $created['id'], [
                'first_name' => '   ',
            ], $this->secretaryUserId);
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            throw $e;
        }
    }
    public function testStaffUpdateRejectsExplicitEmptyOrNullNames(): void
    {
        $created = $this->createPatient([]);
        $id = (int) $created['id'];

        $cases = [
            [ ['first_name' => ''],   'نام نمی‌تواند خالی باشد' ],
            [ ['first_name' => null], 'نام نمی‌تواند خالی باشد' ],
            [ ['first_name' => '   '],'نام نمی‌تواند خالی باشد' ],
            [ ['last_name' => ''],    'نام خانوادگی نمی‌تواند خالی باشد' ],
            [ ['last_name' => null],  'نام خانوادگی نمی‌تواند خالی باشد' ],
            [ ['last_name' => '   '], 'نام خانوادگی نمی‌تواند خالی باشد' ],
        ];
        foreach ( $cases as [ $payload, $expectedMessage ] ) {
            $before = $this->service()->get( $id );
            try {
                $this->service()->update( $id, $payload, $this->secretaryUserId );
                $this->fail( 'a submitted empty/null name must be rejected on the staff update path' );
            } catch ( BookingException $e ) {
                $this->assertSame( 'CLINIC_VALIDATION_FAILED', $e->errorCode );
                $this->assertSame( $expectedMessage, $e->getMessage() );
            }
            $after = $this->service()->get( $id );
            $this->assertSame( (string) $before['first_name'], (string) $after['first_name'] );
            $this->assertSame( (string) $before['last_name'], (string) $after['last_name'] );
        }
    }

    public function testStaffUpdateInvalidNameWithOtherChangesAppliesNothing(): void
    {
        $created = $this->createPatient([ 'address' => 'کهن' ]);
        $id = (int) $created['id'];
        $before = $this->service()->get( $id );

        try {
            $this->service()->update( $id, [
                'first_name' => "تست\u{202E}", // RLO
                'address' => 'تهران',
            ], $this->secretaryUserId );
            $this->fail( 'a bidi-control name must block the whole staff update atomically' );
        } catch ( BookingException $e ) {
            $this->assertSame( 'CLINIC_VALIDATION_FAILED', $e->errorCode );
        }

        $after = $this->service()->get( $id );
        $this->assertSame( (string) $before['first_name'], (string) $after['first_name'] );
        $this->assertSame( (string) $before['address'], (string) $after['address'] );
    }

    public function testStaffUpdateOmittedNameFieldPreservesNameAndAppliesOther(): void
    {
        $created = $this->createPatient([]);
        $id = (int) $created['id'];

        $this->service()->update( $id, [
            'address' => 'رشت',
        ], $this->secretaryUserId );

        $stored = $this->service()->get( $id );
        $this->assertSame( 'رشت', (string) $stored['address'] );
        $this->assertSame( (string) $created['first_name'], (string) $stored['first_name'] );
        $this->assertSame( (string) $created['last_name'], (string) $stored['last_name'] );
    }

    public function testStaffUpdateRejectsNonScalarNameSafely(): void
    {
        $created = $this->createPatient([]);
        $id = (int) $created['id'];

        // دفاع fail-safe — ارسال آرایه در جای نام هرگز نباید به ذخیرهٔ رشته‌ای «Array» بینجامد
        try {
            $this->service()->update( $id, [
                'first_name' => [ 'نام' ],
            ], $this->secretaryUserId );
            $this->fail( 'a non-scalar name must be rejected' );
        } catch ( BookingException $e ) {
            $this->assertSame( 'CLINIC_VALIDATION_FAILED', $e->errorCode );
            $this->assertSame( 'نام نامعتبر است', $e->getMessage() );
        }

        $stored = $this->service()->get( $id );
        $this->assertSame( (string) $created['first_name'], (string) $stored['first_name'] );
    }

    // ============ C2 — خودشناسی بیمار (self-update) — همان validatorها؛ مرجع مجوز = لینک بیمار↔کاربر ============

    public function testSelfUpdateStoresPersianZwnjNameExactly(): void
    {
        $fx = $this->makeLinkedSelfFixture('09120001111');

        $this->service()->updateMe($fx['user_id'], [
            'first_name' => "علی\u{200C}رضا",
            'last_name' => "طاهری\u{200C}زاده",
        ], $fx['link_id']);

        $stored = $this->service()->get($fx['patient_id']);
        $this->assertSame("علی\u{200C}رضا", (string) $stored['first_name']);
        $this->assertSame("طاهری\u{200C}زاده", (string) $stored['last_name']);
    }

    public function testSelfUpdatePreservesValidNameAndTrimsEdges(): void
    {
        $fx = $this->makeLinkedSelfFixture('09120002222');

        $this->service()->updateMe($fx['user_id'], [ 'first_name' => "  علی‌رضا  " ], $fx['link_id']);

        $stored = $this->service()->get($fx['patient_id']);
        $this->assertSame('علی‌رضا', (string) $stored['first_name']);
    }

    public function testSelfUpdateRejectsBidiControlAndPreservesStoredRow(): void
    {
        $fx = $this->makeLinkedSelfFixture('09120003333');
        $before = $this->service()->get($fx['patient_id']);

        try {
            $this->service()->updateMe($fx['user_id'], [
                'first_name' => "خودی\u{202E}نام", // RLO
            ], $fx['link_id']);
            $this->fail('a bidi control character must be rejected on the self-update path');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            $this->assertSame('نام حاوی نویسه‌های کنترلِ جهت نوشتار است', $e->getMessage());
        }

        $after = $this->service()->get($fx['patient_id']);
        $this->assertSame((string) $before['first_name'], (string) $after['first_name']);
    }

    public function testSelfUpdateRejectsInvisibleOnlyAndPreservesStoredRow(): void
    {
        $fx = $this->makeLinkedSelfFixture('09120004444');
        $before = $this->service()->get($fx['patient_id']);

        try {
            $this->service()->updateMe($fx['user_id'], [
                'first_name' => "\u{200C}\u{200D}\u{2060}", // ZWNJ + ZWJ + WORD JOINER
            ], $fx['link_id']);
            $this->fail('an invisible-only name must be rejected on the self-update path');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            $this->assertSame('نام نمی‌تواند خالی باشد', $e->getMessage());
        }

        $after = $this->service()->get($fx['patient_id']);
        $this->assertSame((string) $before['first_name'], (string) $after['first_name']);
    }

    public function testSelfUpdateRejectsWhitespaceOnlyAndPreservesStoredRow(): void
    {
        $fx = $this->makeLinkedSelfFixture('09120005555');
        $before = $this->service()->get($fx['patient_id']);

        try {
            $this->service()->updateMe($fx['user_id'], [ 'first_name' => '   ' ], $fx['link_id']);
            $this->fail('a whitespace-only name must be rejected on the self-update path');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            $this->assertSame('نام نمی‌تواند خالی باشد', $e->getMessage());
        }

        $after = $this->service()->get($fx['patient_id']);
        $this->assertSame((string) $before['first_name'], (string) $after['first_name']);
    }

    // قرارداد B (دستور مالک 2026-11-07 — فقط ویرایش رکورد بیمار): خالیِ صریح/null برای نام = خطای اعتبارسنجی روشن؛
    // کلیدِ غایب = حفظِ مقدار موجود؛ و هر خطای نامی یعنی هیچ‌کدام از تغییرات همان درخواست ذخیره نمی‌شود (validate-then-write).

    public function testExplicitEmptyOrNullNameRejectedWithClearMessage(): void
    {
        $fx = $this->makeLinkedSelfFixture('09120006666');

        $cases = [
            [ ['first_name' => ''],   'نام نمی‌تواند خالی باشد' ],
            [ ['first_name' => null], 'نام نمی‌تواند خالی باشد' ],
            [ ['first_name' => '   '],'نام نمی‌تواند خالی باشد' ],
            [ ['last_name' => ''],    'نام خانوادگی نمی‌تواند خالی باشد' ],
            [ ['last_name' => null],  'نام خانوادگی نمی‌تواند خالی باشد' ],
            [ ['last_name' => '   '], 'نام خانوادگی نمی‌تواند خالی باشد' ],
        ];
        foreach ( $cases as [ $payload, $expectedMessage ] ) {
            $before = $this->service()->get( $fx['patient_id'] );
            try {
                $this->service()->updateMe( $fx['user_id'], $payload, $fx['link_id'] );
                $this->fail( 'a submitted empty/null name must be rejected (payload key: ' . key( $payload ) . ')' );
            } catch ( BookingException $e ) {
                $this->assertSame( 'CLINIC_VALIDATION_FAILED', $e->errorCode );
                $this->assertSame( 400, $e->httpStatus );
                $this->assertSame( $expectedMessage, $e->getMessage() );
            }
            $after = $this->service()->get( $fx['patient_id'] );
            $this->assertSame( (string) $before['first_name'], (string) $after['first_name'], 'a rejected update must not alter the stored first_name' );
            $this->assertSame( (string) $before['last_name'], (string) $after['last_name'], 'a rejected update must not alter the stored last_name' );
        }
    }

    public function testExplicitEmptyNameWithOtherFieldAppliesNothing(): void
    {
        $fx = $this->makeLinkedSelfFixture('09120007777');

        // گزینهٔ B: خطای نامِ خالی یعنی هیچ‌یک از تغییرات همان درخواست ذخیره نمی‌شود.
        try {
            $this->service()->updateMe($fx['user_id'], [
                'first_name' => '',
                'address' => 'تهران',
            ], $fx['link_id']);
            $this->fail('an invalid name must block the whole update atomically');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            $this->assertSame('نام نمی‌تواند خالی باشد', $e->getMessage());
        }

        $stored = $this->service()->get($fx['patient_id']);
        $this->assertSame('خودی', (string) $stored['first_name']);
        $this->assertSame('اصلی', (string) $stored['address']);
    }

    public function testExplicitNullNameWithOtherFieldAppliesNothing(): void
    {
        $fx = $this->makeLinkedSelfFixture('09120008888');

        try {
            $this->service()->updateMe($fx['user_id'], [
                'first_name' => null,
                'address' => 'اصفهان',
            ], $fx['link_id']);
            $this->fail('an explicit null name must block the whole update atomically');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            $this->assertSame('نام نمی‌تواند خالی باشد', $e->getMessage());
        }

        $stored = $this->service()->get($fx['patient_id']);
        $this->assertSame('خودی', (string) $stored['first_name']);
        $this->assertSame('اصلی', (string) $stored['address']);
    }

    public function testOmittedNameFieldPreservesNameAndAppliesOtherFields(): void
    {
        $fx = $this->makeLinkedSelfFixture('09120007800');

        $this->service()->updateMe($fx['user_id'], [
            'address' => 'شیراز',
        ], $fx['link_id']);

        $stored = $this->service()->get($fx['patient_id']);
        $this->assertSame('شیراز', (string) $stored['address']);
        $this->assertSame('خودی', (string) $stored['first_name']);
        $this->assertSame('تستی', (string) $stored['last_name']);
    }

    public function testInvalidNameWithOtherChangesAppliesNothing(): void
    {
        $fx = $this->makeLinkedSelfFixture('09120007801');
        $before = $this->service()->get($fx['patient_id']);

        try {
            $this->service()->updateMe($fx['user_id'], [
                'first_name' => "خودی\u{202E}نام", // RLO
                'address' => 'شیراز',
            ], $fx['link_id']);
            $this->fail('a bidi-control name must block the whole update atomically');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
        }

        $after = $this->service()->get($fx['patient_id']);
        $this->assertSame((string) $before['first_name'], (string) $after['first_name']);
        $this->assertSame((string) $before['address'], (string) $after['address']);
    }

    // مرزنمایش مجوز در C2: لینک فعال بیمار↔کاربر — نه نقش WP — تعیین می‌کند چه کسی کدام پرونده را ویرایش می‌کند.

    public function testSelfUpdateWithoutAnyLinkIsDenied(): void
    {
        $userId = (int) wp_create_user('unlinked_self', 'pass-12345', 'unlinked@test.local');

        try {
            $this->service()->updateMe($userId, [ 'first_name' => 'علی' ], null);
            $this->fail('a user without a patient link must not be able to self-update');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_NOT_FOUND', $e->errorCode);
            $this->assertSame(404, $e->httpStatus);
        }
    }

    public function testSelfUpdateCannotUseAnotherUsersLink(): void
    {
        $owner = $this->makeLinkedSelfFixture('09120009999');
        $intruderId = (int) wp_create_user('intruder_self', 'pass-12345', 'intruder@test.local');
        // Ownership is link-based: link_id belongs to the owner's patient, so a stranger resolving it
        // through their own (empty) link set must get nothing back — the update must be denied.
        try {
            $this->service()->updateMe($intruderId, [
                'first_name' => "نام\u{200C}بدلی",
            ], $owner['link_id']);
            $this->fail("another user's link must not authorize editing the patient");
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_NOT_FOUND', $e->errorCode);
            $this->assertSame(404, $e->httpStatus);
        }

        $stored = $this->service()->get($owner['patient_id']);
        $this->assertSame('خودی', (string) $stored['first_name']);
    }

    public function testSelfUpdateWithTwoActiveLinksRequiresExplicitSelection(): void
    {
        $first = $this->makeLinkedSelfFixture('09120001010');
        $second = $this->makeLinkedSelfFixture('09120002020');
        // همان کاربر اول — پروندهٔ دوم را نیز از مسیرِ مجاز به او وصل می‌کنیم:
        // هویتِ سازمانیِ همان دفترخانهٔ تستی را به دستِ کاربر اول می‌بندیم
        // (link_user — همان مرزی که OTP برای وصل‌کردنِ هویتِ کاربر تأیید می‌کند).
        global $wpdb;
        $orgId = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = %d LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $first['clinic_id']
            )
        );
        $secondIdentityId = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT identity_id FROM ' . $wpdb->prefix . 'cpms_patients WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $second['patient_id']
            )
        );
        $this->assertGreaterThan(0, $secondIdentityId, 'each factory-made patient carries an organization identity');
        App::patient_identity_service()->link_user($orgId, $secondIdentityId, $first['user_id'], '09120002020');

        try {
            $this->service()->updateMe($first['user_id'], [ 'birth_date' => '1990-01-01' ], null);
            $this->fail('ambiguous patient selection must not silently pick one');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_SELECTION_REQUIRED', $e->errorCode);
            $this->assertSame(422, $e->httpStatus);
        }

        // nothing persisted for either record
        $this->assertNull(App::db()->fetchRow(
            'SELECT birth_date FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d',
            [$first['patient_id']]
        )['birth_date'] ?? null);
        $this->assertNull(App::db()->fetchRow(
            'SELECT birth_date FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d',
            [$second['patient_id']]
        )['birth_date'] ?? null);
    }

    // =================== REST-dispatch coverage (round-32) ===================

    private const NS = '/clinic/v1';

    /**
     * Staff fixture: real WP user with the given CPMS role, an ACTIVE
     * clinic-scoped membership seeded via MembershipService (the same write
     * path the admin UI uses), and the membership row re-read and asserted.
     */
    private function makeRestStaff(string $login, string $roleKey): int
    {
        $userId = (int) wp_create_user($login, 'pass-12345', $login . '@rest-staff.test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($roleKey);
        }
        $clinicId = $this->defaultClinicId();
        $service = App::membership_service();
        if ($service->membership_for($clinicId, $userId) === null) {
            $service->create_membership($clinicId, $userId, $roleKey);
        }
        $membership = $service->membership_for($clinicId, $userId);
        $this->assertNotNull($membership, 'staff fixture must have a membership row');
        $this->assertSame('active', (string) ($membership['status'] ?? ''));
        $this->assertSame($roleKey, (string) ($membership['role_key'] ?? ''));

        return $userId;
    }

    /**
     * REST-dispatch fixture for the self-service (C2) boundary: staff-created
     * patient row + fresh WP user + explicit patient_user_links row (the OTP
     * flow shape). The CPMS portal role is granted explicitly — the REST
     * layer (requirePatient) enforces it, unlike the service boundary.
     *
     * @return array{patient_id: int, user_id: int, link_id: int, clinic_id: int, mobile: string}
     */
    private function makeRestLinkedSelfFixture(string $mobile): array
    {
        $fixture = $this->makeLinkedSelfFixture($mobile);
        $user = get_userdata((int) $fixture['user_id']);
        $this->assertNotFalse($user);
        $user->set_role('cpms_patient');
        $user = get_userdata((int) $fixture['user_id']);
        $this->assertNotFalse($user);
        $this->assertContains('cpms_patient', (array) $user->roles);

        return $fixture;
    }

    private function linkedSelfWithoutPortalRole(string $mobile): array
    {
        $fixture = $this->makeLinkedSelfFixture($mobile, false);
        $user = get_userdata((int) $fixture['user_id']);
        $this->assertNotFalse($user);
        $this->assertNotContains('cpms_patient', (array) $user->roles);

        return $fixture;
    }

    private function dispatch(string $method, string $route, array $body = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

        return rest_do_request($request);
    }

    private function assertClinicError(WP_REST_Response $response, string $code): void
    {
        $data = $response->get_data();
        $this->assertIsArray($data, 'Error envelope must be an array');
        $this->assertSame($code, $data['code'] ?? null, 'Top-level code must be the stable CLINIC_* token');
        $this->assertArrayHasKey('message', $data);
        $this->assertSame($response->get_status(), $data['data']['status'] ?? null);
    }

    private function patientRow(int $patientId): array
    {
        $row = App::db()->fetchRow(
            'SELECT first_name, last_name, birth_date, address FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d',
            [$patientId]
        );

        return is_array($row) ? $row : [];
    }

    // ---------- D5 staff edits (PUT /patients/{id}) ----------

    public function testStaffRestUpdateRejectsEmptyStringFirstName(): void
    {
        $patient = $this->createPatient(['first_name' => 'علی', 'last_name' => 'تست', 'address' => 'اصلی']);
        $patientId = (int) $patient['id'];
        $staffId = $this->makeRestStaff('rest_secretary_d5_empty', 'cpms_secretary');

        wp_set_current_user($staffId);
        $response = $this->dispatch('PUT', self::NS . '/patients/' . $patientId, [
            'first_name' => '',
        ]);

        $this->assertSame(400, $response->get_status());
        $this->assertClinicError($response, 'CLINIC_VALIDATION_FAILED');
        $this->assertSame('نام نمی‌تواند خالی باشد', $response->get_data()['message'] ?? null);
        $row = $this->patientRow($patientId);
        $this->assertSame('علی', $row['first_name']);
        $this->assertSame('اصلی', $row['address']);
    }

    public function testStaffRestUpdateRejectsNullFirstName(): void
    {
        $patient = $this->createPatient(['first_name' => 'علی', 'last_name' => 'تست', 'address' => 'اصلی']);
        $patientId = (int) $patient['id'];
        $staffId = $this->makeRestStaff('rest_secretary_d5_null', 'cpms_secretary');

        wp_set_current_user($staffId);
        $response = $this->dispatch('PUT', self::NS . '/patients/' . $patientId, [
            'first_name' => null,
        ]);

        $this->assertSame(400, $response->get_status());
        $this->assertClinicError($response, 'CLINIC_VALIDATION_FAILED');
        $this->assertSame('نام نمی‌تواند خالی باشد', $response->get_data()['message'] ?? null);
        $row = $this->patientRow($patientId);
        $this->assertSame('علی', $row['first_name']);
        $this->assertSame('اصلی', $row['address']);
    }

    public function testStaffRestUpdateInvalidNameRejectsWholeRequest(): void
    {
        $patient = $this->createPatient(['first_name' => 'علی', 'last_name' => 'تست', 'address' => 'اصلی']);
        $patientId = (int) $patient['id'];
        $staffId = $this->makeRestStaff('rest_secretary_d5_atomic', 'cpms_secretary');

        wp_set_current_user($staffId);
        $response = $this->dispatch('PUT', self::NS . '/patients/' . $patientId, [
            'first_name' => "علی\u{061C}رضا", // ARABIC LETTER MARK — bidi control
            'address'    => 'آدرس جدید',
        ]);

        $this->assertSame(400, $response->get_status());
        $this->assertClinicError($response, 'CLINIC_VALIDATION_FAILED');
        $this->assertSame('نام حاوی نویسه‌های کنترلِ جهت نوشتار است', $response->get_data()['message'] ?? null);
        $row = $this->patientRow($patientId);
        $this->assertSame('علی', $row['first_name'], 'invalid name must not be stored');
        $this->assertSame('اصلی', $row['address'], 'unrelated field of the SAME request must not be persisted either');
    }

    public function testStaffRestUpdateOmitsNamePreservesStoredName(): void
    {
        $patient = $this->createPatient(['first_name' => 'علی', 'last_name' => 'تست', 'address' => 'اصلی']);
        $patientId = (int) $patient['id'];
        $staffId = $this->makeRestStaff('rest_secretary_d5_omit', 'cpms_secretary');

        wp_set_current_user($staffId);
        $response = $this->dispatch('PUT', self::NS . '/patients/' . $patientId, [
            'address' => 'آدرس جدید',
        ]);

        $this->assertSame(200, $response->get_status());
        $row = $this->patientRow($patientId);
        $this->assertSame('علی', $row['first_name']);
        $this->assertSame('آدرس جدید', $row['address']);
    }

    public function testStaffRestUpdateZwnjNameRoundTrips(): void
    {
        $patient = $this->createPatient(['first_name' => 'علی', 'last_name' => 'تست', 'address' => 'اصلی']);
        $patientId = (int) $patient['id'];
        $staffId = $this->makeRestStaff('rest_secretary_d5_zwnj', 'cpms_secretary');

        wp_set_current_user($staffId);
        $response = $this->dispatch('PUT', self::NS . '/patients/' . $patientId, [
            'first_name' => "علی\u{200C}رضا",
        ]);

        $this->assertSame(200, $response->get_status());
        $row = $this->patientRow($patientId);
        $this->assertSame("علی\u{200C}رضا", $row['first_name']);
    }

    public function testStaffRestUpdateAccountantMembershipIsDenied(): void
    {
        $patient = $this->createPatient(['first_name' => 'علی', 'last_name' => 'تست', 'address' => 'اصلی']);
        $patientId = (int) $patient['id'];
        // ACTIVE clinic-scoped accountant membership (preset denies the cap)
        $staffId = $this->makeRestStaff('rest_accountant_d5_deny', 'cpms_accountant');

        wp_set_current_user($staffId);
        $response = $this->dispatch('PUT', self::NS . '/patients/' . $patientId, [
            'first_name' => 'تغییر',
        ]);

        $this->assertSame(403, $response->get_status());
        $this->assertClinicError($response, 'CLINIC_PERMISSION_DENIED');
        $row = $this->patientRow($patientId);
        $this->assertSame('علی', $row['first_name']);
    }

    // ---------- C2 self-service edits (PUT /patient/me) ----------

    public function testSelfRestUpdateRejectsEmptyStringFirstName(): void
    {
        $fixture = $this->makeRestLinkedSelfFixture('09120100021');
        wp_set_current_user((int) $fixture['user_id']);

        $response = $this->dispatch('PUT', self::NS . '/patient/me', [
            'first_name' => '',
        ]);

        $this->assertSame(400, $response->get_status());
        $this->assertClinicError($response, 'CLINIC_VALIDATION_FAILED');
        $this->assertSame('نام نمی‌تواند خالی باشد', $response->get_data()['message'] ?? null);
        $row = $this->patientRow((int) $fixture['patient_id']);
        $this->assertSame('خودی', $row['first_name']);
        $this->assertSame('اصلی', $row['address']);
    }

    public function testSelfRestUpdateRejectsNullFirstName(): void
    {
        $fixture = $this->makeRestLinkedSelfFixture('09120100022');
        wp_set_current_user((int) $fixture['user_id']);

        $response = $this->dispatch('PUT', self::NS . '/patient/me', [
            'first_name' => null,
        ]);

        // Widened C2 arg type ['string','null'] lets explicit null reach
        // updateMe(); the validator must reject it with the clear message.
        $this->assertSame(400, $response->get_status());
        $this->assertClinicError($response, 'CLINIC_VALIDATION_FAILED');
        $this->assertSame('نام نمی‌تواند خالی باشد', $response->get_data()['message'] ?? null);
        $row = $this->patientRow((int) $fixture['patient_id']);
        $this->assertSame('خودی', $row['first_name']);
        $this->assertSame('اصلی', $row['address']);
    }

    public function testSelfRestUpdateInvalidNameRejectsWholeRequest(): void
    {
        $fixture = $this->makeRestLinkedSelfFixture('09120100023');
        wp_set_current_user((int) $fixture['user_id']);

        $response = $this->dispatch('PUT', self::NS . '/patient/me', [
            'first_name' => "عل\u{061C}ی", // ARABIC LETTER MARK — bidi control
            'birth_date' => '1991-04-05',
        ]);

        $this->assertSame(400, $response->get_status());
        $this->assertClinicError($response, 'CLINIC_VALIDATION_FAILED');
        $this->assertSame('نام حاوی نویسه‌های کنترلِ جهت نوشتار است', $response->get_data()['message'] ?? null);
        $row = $this->patientRow((int) $fixture['patient_id']);
        $this->assertSame('خودی', $row['first_name'], 'invalid name must not be stored');
        $this->assertNull($row['birth_date'], 'unrelated field of the SAME request must not be persisted either');
    }

    public function testSelfRestUpdateOmitsNamePreservesStoredName(): void
    {
        $fixture = $this->makeRestLinkedSelfFixture('09120100024');
        wp_set_current_user((int) $fixture['user_id']);

        $response = $this->dispatch('PUT', self::NS . '/patient/me', [
            'address' => 'خانه‌ی جدید',
        ]);

        $this->assertSame(200, $response->get_status());
        $row = $this->patientRow((int) $fixture['patient_id']);
        $this->assertSame('خودی', $row['first_name']);
        $this->assertSame('خانه‌ی جدید', $row['address']);
    }

    public function testSelfRestUpdateZwnjNameRoundTrips(): void
    {
        $fixture = $this->makeRestLinkedSelfFixture('09120100025');
        wp_set_current_user((int) $fixture['user_id']);

        $response = $this->dispatch('PUT', self::NS . '/patient/me', [
            'first_name' => "علی\u{200C}رضا",
        ]);

        $this->assertSame(200, $response->get_status());
        $row = $this->patientRow((int) $fixture['patient_id']);
        $this->assertSame("علی\u{200C}رضا", $row['first_name']);
    }

    public function testSelfRestUpdateWithoutPortalRoleIsDenied(): void
    {
        $fixture = $this->linkedSelfWithoutPortalRole('09120100026');
        wp_set_current_user((int) $fixture['user_id']);

        $response = $this->dispatch('PUT', self::NS . '/patient/me', [
            'first_name' => 'تغییر',
        ]);

        $this->assertSame(403, $response->get_status());
        $this->assertClinicError($response, 'CLINIC_PERMISSION_DENIED');
        $row = $this->patientRow((int) $fixture['patient_id']);
        $this->assertSame('خودی', $row['first_name']);
    }
}
