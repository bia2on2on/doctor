<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use WP_UnitTestCase;

/**
 * M4 — Patient name entrances must reject empty, invisible-only and
 * bidi-control names, and must preserve valid Persian names with ZWNJ.
 *
 * Through-service coverage of the shared first_name/last_name validator
 * (PatientService::cleanName via validateForUpdate), on the secretary
 * create (D4) and update (D5) paths. Note: on update, `''` means "field not
 * supplied" by contract and is skipped — `' '` (whitespace/invisible) is the
 * "empty name" probe there.
 */
final class PatientNameValidationTest extends WP_UnitTestCase
{
    private int $secretaryUserId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();

        // Note (round-29 independent review): wp_create_user accepts only
        // (username, password, email) — a fourth array argument is silently
        // ignored and does NOT grant cpms_secretary. It was never needed
        // here: on the D4/D5 service paths authorization lives in the REST
        // layer (membership/capability checks), not in the service, so these
        // tests do not depend on any WordPress role. The self-service (C2)
        // boundary below is instead authorized by the explicit patient↔user
        // link, which is the layer it actually enforces.
        $this->secretaryUserId = (int) wp_create_user('secretary_name_tests', 'pass-12345', 'secname@test.local');
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
     * Deterministic linked-patient fixture for the C2 (self-update) boundary:
     * a staff-created patient row, a fresh WP user, and a direct link row
     * (the same shape the OTP flow produces). No WP role is involved — the
     * self-service boundary is authorized by the link itself.
     *
     * @return array{patient_id: int, user_id: int, link_id: int, clinic_id: int, mobile: string}
     */
    private function makeLinkedSelfFixture(string $mobile): array
    {
        $clinicId = $this->defaultClinicId();

        $created = $this->service()->create([
            'first_name' => 'خودی',
            'last_name' => 'تستی',
            'mobile' => $mobile,
            'address' => 'اصلی',
        ], $this->secretaryUserId);
        $patientId = (int) $created['id'];

        $userId = (int) wp_create_user(
            'self_' . str_replace(' ', '_', $mobile),
            'pass-12345',
            $mobile . '@self.test.local'
        );

        global $wpdb;
        $link_id = null;
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patient_user_links
                     (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at)
                 VALUES (%d, %d, %d, %s, 1, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $patientId,
                $userId,
                $mobile,
                App::db()->nowUtcSql()
            )
        );
        $link_id = (int) $wpdb->insert_id;

        return [
            'patient_id' => $patientId,
            'user_id' => $userId,
            'link_id' => $link_id,
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
        // همان کاربر اول — یک پروندهٔ دوم هم به او لینک می‌دهیم (مخزن وابستهٔ کلینیک).
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patient_user_links
                     (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at)
                 VALUES (%d, %d, %d, %s, 0, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $second['clinic_id'],
                $second['patient_id'],
                $first['user_id'],
                $second['mobile'],
                App::db()->nowUtcSql()
            )
        );

        try {
            $this->service()->updateMe($first['user_id'], [ 'birth_date' => '1990-01-01' ], null);
            $this->fail('ambiguous patient selection must not silently pick one');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_SELECTION_REQUIRED', $e->errorCode);
            $this->assertSame(422, $e->httpStatus);
        }
    }
}
