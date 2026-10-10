<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\PatientPortalPage;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * Cross-phase correction — Patient Portal «تماس با مطب» contact phone.
 *
 * Contract (RED→GREEN): the portal contact phone must be read from the
 * canonical persisted Clinic Profile (`cpms_clinics.phone`) resolved through
 * the trusted Patient Portal context (the current user's active linked
 * records) — never from the obsolete installation-wide `clinic.phone`
 * settings key.
 *
 * Cases:
 *  1. Sole linked Clinic  ⇒ canonical `cpms_clinics.phone` is rendered; the
 *     stale legacy `clinic.phone` settings value must NOT appear.
 *  2. Canonical phone missing (NULL) ⇒ existing safe empty presentation;
 *     the legacy value must NOT appear either (no fallback).
 *  3. Two-Clinic isolation ⇒ a patient linked to Clinic A only sees A's
 *     canonical phone; Clinic B's phone must NOT leak.
 *  4. Two-Clinic ambiguity ⇒ patient linked to both ⇒ fail-closed: neither
 *     canonical phone is rendered (explicit selection policy lives in the
 *     portal sections).
 *
 * ⚑ Clinicهای تست رزرو شده (≥ 61000) هستند و در tearDown پاک می‌شوند.
 */
final class PatientPortalContactPhoneTest extends WP_UnitTestCase
{
    private const LEGACY_PHONE = '+98-21-000-LEGACY';

    private const CANONICAL_PHONE_A = '+98-21-555-AAAA';

    private const CANONICAL_PHONE_B = '+98-21-555-BBBB';

    private const SECOND_CLINIC = 61001;

    private int $seedClinicId = 0;

    /** @var string|null مقدار قبلی phoneِ canonicalِ Clinic seed (برای بازگردانی). */
    private ?string $seedClinicPhoneBefore = null;

    /** @var mixed مقدار قبلی کلید legacy (null = ردیف وجود نداشت). */
    private mixed $legacySettingBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user( 0 );
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();

        global $wpdb;

        // Witness: هیچ Clinic رزرو (≥ 61000) از تست قبلی باقی نمانده باشد.
        $leftover = (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id >= 61000' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertSame( 0, $leftover, 'WITNESS: Clinic رزرو از تست قبلی باقی مانده — rollback/پاک‌سازی مختل شده' );

        // پیش‌شرط: نصب تک‌کلینیکی (یک Clinic seed شدهٔ suite).
        $clinicCount = (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_clinics' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertSame( 1, $clinicCount, 'precondition: exactly one seeded Clinic.' );

        $this->seedClinicId = (int) $wpdb->get_var(
            'SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics ORDER BY id ASC LIMIT 1' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertGreaterThan( 0, $this->seedClinicId, 'precondition: seeded Clinic id.' );

        // نگه‌داری مقدار فعلی برای بازگردانی در tearDown.
        $seedPhone = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT phone FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->seedClinicId
            )
        );
        $this->seedClinicPhoneBefore = $seedPhone === null ? null : (string) $seedPhone;
        $this->legacySettingBefore   = App::settings()->get( 'clinic.phone', null );

        // کلید legacyِ نصب (`clinic.phone`) — عمداً یک مقدار «نادرست» ثبت می‌شود
        // تا ثابت شود مقدار canonicalِ Clinic Profile بر آن غلبه می‌کند.
        App::settings()->set( 'clinic.phone', self::LEGACY_PHONE );
        Settings::flushCache();
    }

    protected function tearDown(): void
    {
        global $wpdb;

        // پاک‌سازی Clinic رزرو و داده‌های وابستهٔ fixture (rollbackِ suite + belt-and-braces).
        $wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query(
            'DELETE FROM ' . $wpdb->prefix . 'cpms_patient_user_links WHERE clinic_id >= 61000' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );
        $wpdb->query(
            'DELETE FROM ' . $wpdb->prefix . 'cpms_patients WHERE clinic_id >= 61000' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );
        $wpdb->query(
            'DELETE FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id >= 61000' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );
        $wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        // بازگردانی phoneِ canonicalِ Clinic seed.
        $wpdb->update(
            $wpdb->prefix . 'cpms_clinics',
            [ 'phone' => $this->seedClinicPhoneBefore ],
            [ 'id' => $this->seedClinicId ]
        );

        // بازگردانی کلید legacy: مقدار قبلی موجود بود ⇒ set؛ وگرنه حذف ردیف.
        if ( $this->legacySettingBefore !== null ) {
            App::settings()->set( 'clinic.phone', $this->legacySettingBefore );
        } else {
            $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM ' . $wpdb->prefix . 'cpms_settings WHERE clinic_id = %d AND `key` = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $this->seedClinicId,
                    'clinic.phone'
                )
            );
        }

        wp_set_current_user( 0 );
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        parent::tearDown();
    }

    public function testSoleLinkedClinicRendersCanonicalProfilePhoneNotLegacySetting(): void
    {
        $this->setCanonicalClinicPhone( $this->seedClinicId, self::CANONICAL_PHONE_A );

        $userId = $this->makePurePatientLinkedTo( $this->seedClinicId, 'canon' );

        $html = $this->renderPortalAs( $userId );

        self::assertStringContainsString(
            '(تلفن: <b dir="ltr">' . self::CANONICAL_PHONE_A . '</b>)',
            $html,
            'Portal contact phone must come from the canonical cpms_clinics.phone.'
        );
        self::assertStringNotContainsString(
            self::LEGACY_PHONE,
            $html,
            'Stale legacy `clinic.phone` settings value must never override the canonical Clinic phone.'
        );
    }

    public function testMissingCanonicalPhoneRendersSafeEmptyLineWithoutLegacyFallback(): void
    {
        $this->setCanonicalClinicPhone( $this->seedClinicId, null );

        $userId = $this->makePurePatientLinkedTo( $this->seedClinicId, 'empty' );

        $html = $this->renderPortalAs( $userId );

        self::assertStringNotContainsString(
            'تلفن: <b',
            $html,
            'Missing canonical phone must produce the existing safe empty presentation.'
        );
        self::assertStringNotContainsString(
            self::LEGACY_PHONE,
            $html,
            'No fallback to the installation-global legacy phone is allowed.'
        );
    }

    public function testTwoClinicIsolationShowsOnlyTheOwnLinkedClinicPhone(): void
    {
        $this->setCanonicalClinicPhone( $this->seedClinicId, self::CANONICAL_PHONE_A );
        $this->insertSecondClinic( self::CANONICAL_PHONE_B );

        // بیمار فقط به Clinic A (seed) لینک است؛ phoneِ Clinic B نباید نشت کند.
        $userId = $this->makePurePatientLinkedTo( $this->seedClinicId, 'iso' );

        $html = $this->renderPortalAs( $userId );

        self::assertStringContainsString(
            '(تلفن: <b dir="ltr">' . self::CANONICAL_PHONE_A . '</b>)',
            $html,
            'Sole trusted linked Clinic must resolve even in a multi-Clinic installation.'
        );
        self::assertStringNotContainsString(
            self::CANONICAL_PHONE_B,
            $html,
            'No cross-Clinic contact information leakage (Clinic B phone must not appear).'
        );
        self::assertStringNotContainsString( self::LEGACY_PHONE, $html );
    }

    public function testTwoLinksToSameClinicRenderCanonicalPhoneNotLegacy(): void
    {
        // Regression (acceptance blocker): two distinct active patient links that
        // both durably belong to the SAME Clinic must NOT suppress the canonical
        // phone — one distinct Clinic, one canonical cpms_clinics.phone.
        $this->setCanonicalClinicPhone( $this->seedClinicId, self::CANONICAL_PHONE_A );

        $patientA = $this->makePatientInClinic( $this->seedClinicId, 'same' );
        $patientB = $this->makePatientInClinic( $this->seedClinicId, 'same' );
        $userId   = $this->makePurePatientUser( 'same' );
        $this->linkUserToClinic( $userId, $this->seedClinicId, $patientA['patient_id'], $patientA['mobile'], true );
        $this->linkUserToClinic( $userId, $this->seedClinicId, $patientB['patient_id'], $patientB['mobile'], false );

        $html = $this->renderPortalAs( $userId );

        self::assertStringContainsString(
            '(تلفن: <b dir="ltr">' . self::CANONICAL_PHONE_A . '</b>)',
            $html,
            'Multiple links to the SAME Clinic must not create false ambiguity — the canonical phone renders.'
        );
        self::assertStringNotContainsString(
            self::LEGACY_PHONE,
            $html,
            'The obsolete global clinic.phone value must never render.'
        );
    }

    public function testTwoLinkedClinicsFailClosedWithoutAnyPhone(): void
    {
        $this->setCanonicalClinicPhone( $this->seedClinicId, self::CANONICAL_PHONE_A );
        $secondClinicId = $this->insertSecondClinic( self::CANONICAL_PHONE_B );

        // همان کاربر به هر دو Clinic لینک است ⇒ زمینهٔ مبهم ⇒ fail-closed.
        $patientA = $this->makePatientInClinic( $this->seedClinicId, 'amb' );
        $patientB = $this->makePatientInClinic( $secondClinicId, 'amb' );
        $userId   = $this->makePurePatientUser( 'amb' );
        $this->linkUserToClinic( $userId, $this->seedClinicId, $patientA['patient_id'], $patientA['mobile'], true );
        $this->linkUserToClinic( $userId, $secondClinicId, $patientB['patient_id'], $patientB['mobile'], false );

        $html = $this->renderPortalAs( $userId );

        self::assertStringNotContainsString(
            'تلفن: <b',
            $html,
            'Ambiguous Clinic context must fail closed (explicit selection policy lives in the portal sections).'
        );
        self::assertStringNotContainsString( self::CANONICAL_PHONE_A, $html, 'Clinic A phone must not be selected implicitly.' );
        self::assertStringNotContainsString( self::CANONICAL_PHONE_B, $html, 'Clinic B phone must not be selected implicitly.' );
        self::assertStringNotContainsString( self::LEGACY_PHONE, $html );
    }

    // =================================================================
    // Fixture helpers
    // =================================================================

    private function setCanonicalClinicPhone( int $clinicId, ?string $phone ): void
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'cpms_clinics',
            [
                'phone'      => $phone,
                'updated_at' => App::db()->nowUtcSql(),
            ],
            [ 'id' => $clinicId ]
        );
        self::assertSame( '', (string) $wpdb->last_error, 'fixture: canonical phone update failed' );
    }

    private function insertSecondClinic( string $phone ): int
    {
        global $wpdb;

        $orgId = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->seedClinicId
            )
        );
        self::assertGreaterThan( 0, $orgId, 'precondition: organization of the seeded Clinic.' );

        $now = App::db()->nowUtcSql();
        $ok  = $wpdb->insert(
            $wpdb->prefix . 'cpms_clinics',
            [
                'id'              => self::SECOND_CLINIC,
                'organization_id' => $orgId,
                'name'            => 'Clinic ContactPhone B',
                'slug'            => 'cpcontact-clinic-b',
                'timezone'        => 'Asia/Tehran',
                'phone'           => $phone,
                'created_at'      => $now,
                'updated_at'      => $now,
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
        self::assertTrue( (bool) $ok, 'fixture: second Clinic insert failed: ' . $wpdb->last_error );

        App::resetScope();
        SystemClinicResolver::flush();

        return self::SECOND_CLINIC;
    }

    /**
     * @return array{patient_id:int, mobile:string}
     */
    private function makePatientInClinic( int $clinicId, string $tag ): array
    {
        global $wpdb;

        $now    = App::db()->nowUtcSql();
        $mobile = '09' . substr( bin2hex( random_bytes( 5 ) ), 0, 9 );
        $mrn    = 'MR-CPC' . strtoupper( (string) ( preg_replace( '/[^a-z0-9]/i', '', $tag ) ?? '' ) ) . bin2hex( random_bytes( 4 ) );

        $ok = $wpdb->insert(
            $wpdb->prefix . 'cpms_patients',
            [
                'clinic_id'  => $clinicId,
                'mrn'        => $mrn,
                'first_name' => 'ContactPhone',
                'last_name'  => $tag,
                'mobile'     => $mobile,
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
        self::assertTrue( (bool) $ok, 'fixture: patient insert failed: ' . $wpdb->last_error );

        return [ 'patient_id' => (int) $wpdb->insert_id, 'mobile' => $mobile ];
    }

    private function makePurePatientUser( string $tag ): int
    {
        $suffix = strtolower( (string) ( preg_replace( '/[^a-z0-9]/i', '', $tag ) ?? '' ) ) . '_' . bin2hex( random_bytes( 3 ) );
        $userId = (int) wp_create_user(
            'cpcontact_' . $suffix . '_' . uniqid( '', false ),
            'pass-not-used-123',
            'cpcontact_' . $suffix . '_' . uniqid( '', false ) . '@test.local'
        );
        self::assertGreaterThan( 0, $userId, 'fixture: WP user created.' );
        $user = get_userdata( $userId );
        self::assertNotFalse( $user );
        $user->set_role( RolesAndCapabilities::ROLE_PATIENT );

        return $userId;
    }

    private function linkUserToClinic( int $userId, int $clinicId, int $patientId, string $mobile, bool $primary ): void
    {
        global $wpdb;

        $ok = $wpdb->insert(
            $wpdb->prefix . 'cpms_patient_user_links',
            [
                'clinic_id'      => $clinicId,
                'patient_id'     => $patientId,
                'wp_user_id'     => $userId,
                'mobile_at_link' => $mobile,
                'is_primary'     => $primary ? 1 : 0,
                'linked_at'      => App::db()->nowUtcSql(),
            ],
            [ '%d', '%d', '%d', '%s', '%d', '%s' ]
        );
        self::assertTrue( (bool) $ok, 'fixture: patient_user_link insert failed: ' . $wpdb->last_error );
    }

    private function makePurePatientLinkedTo( int $clinicId, string $tag ): int
    {
        $patient = $this->makePatientInClinic( $clinicId, $tag );
        $userId  = $this->makePurePatientUser( $tag );
        $this->linkUserToClinic( $userId, $clinicId, $patient['patient_id'], $patient['mobile'], true );

        return $userId;
    }

    private function renderPortalAs( int $userId ): string
    {
        wp_set_current_user( $userId );
        ob_start();
        try {
            PatientPortalPage::render();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }
}
