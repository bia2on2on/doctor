<?php

declare( strict_types=1 );

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Patients\PatientIdentityActivator;
use ClinicCore\Application\Patients\PatientIdentityService;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Domain\Patients\PatientIdentityException;
use ClinicCore\Infrastructure\Repository\PatientIdentityRepository;
use WP_UnitTestCase;

/**
 * Organization Patient Identity — **activation** on the supported new-patient
 * creation paths (foundation of the approved post-license-expiration policy).
 *
 * The commercial license-expiry restrictions are intentionally NOT implemented
 * or asserted here; they depend on this identity foundation.
 *
 * Coverage map:
 *  A  creation path activates a fresh Organization identity .... testStaffCreateActivatesFreshOrganizationIdentity
 *  B  no equivalence from demographics (mobile/NID/name) ....... testMatchingDemographicsNeverReuseAnIdentity
 *  C  identity_id can never be chosen by the caller ............ testIdentityIdCannotBeInjectedByTheCaller
 *  D  tenant isolation: foreign-Clinic record refused .......... testActivationRefusesRecordFromAnotherClinic
 *  E  fail-closed + rollback: no half-created patient .......... testFailedActivationRollsBackTheWholeCreate
 *  F  concurrency: an existing link is never overwritten ....... testExistingLinkIsNeverOverwritten
 *  G  explicit trusted verification is the ONLY reuse path ..... testVerifiedUserLinkIsTheOnlyReusePath
 *  H  cross-organization link never reused ...................... testCrossOrganizationVerifiedLinkIsNotReused
 *  I  legacy NULL identity_id rows stay untouched ............... testLegacyNullIdentityRecordsAreNotBackfilled
 *  J  no schema change required (no migration 0028) ............ testActivationNeedsNoNewMigration
 */
final class OrganizationPatientIdentityActivationTest extends WP_UnitTestCase {

    private const LATEST_MIGRATION = '2026_10_13_0027';

    private PatientIdentityActivator $activator;
    private PatientIdentityService $identityService;
    private PatientIdentityRepository $identityRepo;

    /** Org A: Clinic A1 (seed, id=1) + A2. Org B: Clinic B1. */
    private int $orgA;
    private int $clinicA1 = 1;
    private int $clinicA2 = 71;
    private int $orgB;
    private int $clinicB1 = 72;

    protected function setUp(): void {
        parent::setUp();
        App::migrations()->migrate();

        $this->identityRepo    = new PatientIdentityRepository( App::db() );
        $this->identityService = App::patient_identity_service();
        $this->activator       = App::patient_identity_activator();

        global $wpdb;
        $now = App::db()->nowUtcSql();

        $this->orgA = (int) $wpdb->get_var(
            'SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = 1'
        ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertGreaterThan( 0, $this->orgA, 'seed organization must exist' );

        $this->insertClinic( $this->clinicA2, $this->orgA, 'activation-a2' );

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, created_at, updated_at)
                 VALUES (%s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'سازمان فعال‌سازی ب',
                'activation-org-b',
                $now,
                $now
            )
        );
        $this->orgB = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $this->orgB );

        $this->insertClinic( $this->clinicB1, $this->orgB, 'activation-b1' );
    }

    protected function tearDown(): void {
        $this->restoreAuditInserts();
        App::resetScope();
        parent::tearDown();
    }

    public function testIdentityCreationWritesPrivacyMinimalAuditEvidence(): void {
        $logs = App::db()->table( 'cpms_audit_logs' );

        $identityId = $this->identityService->create_identity( $this->orgA, '09125550123' );
        $row = App::db()->fetchRow(
            "SELECT action, resource_type, resource_id, patient_id, after_json, meta_json FROM {$logs} WHERE action = %s AND resource_id = %d ORDER BY id DESC LIMIT 1",
            [ 'PATIENT_IDENTITY_CREATED', $identityId ]
        );

        self::assertNotNull( $row );
        self::assertSame( 'patient_identity', $row['resource_type'] );
        self::assertSame( $identityId, (int) $row['resource_id'] );
        self::assertNull( $row['patient_id'] );
        $payload = (string) $row['after_json'] . ' ' . (string) $row['meta_json'];
        self::assertStringContainsString( 'organization_id', $payload );
        self::assertStringNotContainsString( '09125550123', $payload );
        self::assertStringNotContainsString( 'mobile', strtolower( $payload ) );
        self::assertTrue( App::audit()->verifyChain( 500 )['ok'] );
    }

    public function testPatientIdentityLinkWritesExactlyOneScopedAuditEvent(): void {
        global $wpdb;
        $unlinkedPatient = $this->insertPatient( $this->clinicA1, '09125550124' );
        $identityId      = $this->identityService->create_identity( $this->orgA );
        $logs            = App::db()->table( 'cpms_audit_logs' );

        $this->identityService->link_clinical_record( $this->orgA, $identityId, $this->clinicA1, $unlinkedPatient );
        $this->identityService->link_clinical_record( $this->orgA, $identityId, $this->clinicA1, $unlinkedPatient );

        self::assertSame( $identityId, (int) $this->patientRow( $unlinkedPatient )['identity_id'] );
        self::assertSame(
            1,
            (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$logs} WHERE action = %s AND resource_id = %d AND patient_id = %d AND clinic_id = %d",
                'PATIENT_IDENTITY_LINKED',
                $identityId,
                $unlinkedPatient,
                $this->clinicA1
            ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertTrue( App::audit()->verifyChain( 500 )['ok'] );
    }

    public function testIdentityAuditFailureRollsBackIdentityCreationAndPatientLink(): void {
        $identityCount = $this->countIdentities();
        $this->failAuditInserts();
        try {
            try {
                $this->identityService->create_identity( $this->orgA );
                self::fail( 'identity creation must fail when required audit persistence fails' );
            } catch ( \RuntimeException $exception ) {
                self::assertStringContainsString( 'audit', strtolower( $exception->getMessage() ) );
            }
        } finally {
            $this->restoreAuditInserts();
        }
        self::assertSame( $identityCount, $this->countIdentities(), 'identity insert rolls back with its audit' );

        $patientId  = $this->insertPatient( $this->clinicA1, '09125550125' );
        $identityId = $this->identityService->create_identity( $this->orgA );
        $this->failAuditInserts();
        try {
            try {
                $this->identityService->link_clinical_record( $this->orgA, $identityId, $this->clinicA1, $patientId );
                self::fail( 'linkage must fail when required audit persistence fails' );
            } catch ( \RuntimeException $exception ) {
                self::assertStringContainsString( 'audit', strtolower( $exception->getMessage() ) );
            }
        } finally {
            $this->restoreAuditInserts();
        }
        self::assertNull( $this->patientRow( $patientId )['identity_id'], 'linkage rolls back with its audit' );
    }

    public function testCrossOrganizationIdentityLinkIsRejectedWithoutSuccessAudit(): void {
        global $wpdb;
        $patientId  = $this->insertPatient( $this->clinicA1, '09125550126' );
        $identityId = $this->identityService->create_identity( $this->orgB );
        $logs       = App::db()->table( 'cpms_audit_logs' );
        $before     = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$logs} WHERE action = %s",
            'PATIENT_IDENTITY_LINKED'
        ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        try {
            $this->identityService->link_clinical_record( $this->orgA, $identityId, $this->clinicA1, $patientId );
            self::fail( 'cross-Organization identity linkage must be refused' );
        } catch ( PatientIdentityException $exception ) {
            self::assertSame( 'CLINIC_PATIENT_IDENTITY_NOT_FOUND', $exception->error_code );
        }

        self::assertNull( $this->patientRow( $patientId )['identity_id'] );
        self::assertSame( $before, (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$logs} WHERE action = %s",
            'PATIENT_IDENTITY_LINKED'
        ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * @param string $mobile
     */
    private function insertPatient( int $clinicId, string $mobile ): int {
        global $wpdb;
        $suffix = bin2hex( random_bytes( 4 ) );
        $wpdb->insert(
            $wpdb->prefix . 'cpms_patients',
            [
                'clinic_id' => $clinicId,
                'mrn' => 'ID-AUDIT-' . $suffix,
                'first_name' => 'Audit',
                'last_name' => 'Fixture',
                'mobile' => $mobile,
                'created_at' => App::db()->nowUtcSql(),
                'updated_at' => App::db()->nowUtcSql(),
            ]
        );

        return (int) $wpdb->insert_id;
    }

    private $auditFault = null;
    private bool $previousSuppression = false;

    private function failAuditInserts(): void {
        global $wpdb;
        $fault = static function ( $query ) {
            $sql = (string) $query;
            if ( preg_match( '/\\A\\s*INSERT\\s+INTO\\s+(\\S+)/i', $sql, $match ) === 1 && str_contains( $match[1], 'cpms_audit_logs' ) ) {
                return 'INSERT INTO ' . $match[1] . ' (`cpms_fault_injection_no_such_column`) VALUES (1)';
            }
            return $query;
        };
        $this->previousSuppression = (bool) $wpdb->suppress_errors( true );
        add_filter( 'query', $fault, 5 );
        $this->auditFault = $fault;
    }

    private function restoreAuditInserts(): void {
        if ( $this->auditFault === null ) {
            return;
        }
        remove_filter( 'query', $this->auditFault, 5 );
        $this->auditFault = null;
        global $wpdb;
        $wpdb->suppress_errors( $this->previousSuppression );
    }

    // ================= A — activation on the staff creation path =================

    public function testStaffCreateActivatesFreshOrganizationIdentity(): void {
        $before = $this->countIdentities();

        $created = $this->createPatientInClinic( $this->clinicA1, 'آوا', 'فعال', '09120000001' );

        $row = $this->patientRow( (int) $created['id'] );
        self::assertNotNull( $row['identity_id'], 'A: the new clinical record carries an Organization identity' );

        $identity = $this->identityRepo->find( (int) $row['identity_id'] );
        self::assertNotNull( $identity, 'A: the activated identity row exists' );
        self::assertSame( $this->orgA, (int) $identity['organization_id'], 'A: identity belongs to the Clinic organization' );
        self::assertMatchesRegularExpression(
            '/^PID-\d{6}-[0-9A-F]{12}$/',
            (string) $identity['internal_ref'],
            'A: immutable internal_ref minted by the established primitive'
        );
        self::assertSame( '09120000001', (string) $identity['normalized_mobile'], 'A: mobile is stored as a lookup attribute only' );
        self::assertSame( $before + 1, $this->countIdentities(), 'A: exactly one identity is minted' );

        // The clinical record stays Clinic-scoped — activation shares nothing.
        self::assertSame( $this->clinicA1, (int) $row['clinic_id'] );
        self::assertSame(
            [],
            $this->identityService->clinical_records_for_clinic( $this->orgA, (int) $row['identity_id'], $this->clinicA2 ),
            'A: the identity exposes no record in another Clinic of the same Organization'
        );
    }

    // ================= B — never infer identity from demographics =================

    public function testMatchingDemographicsNeverReuseAnIdentity(): void {
        // Same mobile, same names, same national ID — two Clinics, one Organization.
        $first  = $this->createPatientInClinic( $this->clinicA1, 'همنام', 'همتا', '09120000002', '0013542419' );
        $second = $this->createPatientInClinic( $this->clinicA2, 'همنام', 'همتا', '09120000002', '0013542419' );

        $idOne = (int) $this->patientRow( (int) $first['id'] )['identity_id'];
        $idTwo = (int) $this->patientRow( (int) $second['id'] )['identity_id'];

        self::assertGreaterThan( 0, $idOne );
        self::assertGreaterThan( 0, $idTwo );
        self::assertNotSame(
            $idOne,
            $idTwo,
            'B: identical mobile + national ID + names must NOT be treated as the same person'
        );

        // Both identities remain discoverable as non-destructive duplicate candidates.
        $candidates = $this->identityService->duplicate_candidates( $this->orgA, '09120000002' );
        self::assertContains( $idOne, $candidates );
        self::assertContains( $idTwo, $candidates );
    }

    // ================= C — identity_id is never caller-controlled =================

    public function testIdentityIdCannotBeInjectedByTheCaller(): void {
        $foreign = $this->identityService->create_identity( $this->orgB, '09120000003' );

        $created = $this->createPatientInClinic(
            $this->clinicA1,
            'بدون',
            'تزریق',
            '09120000004',
            null,
            [
                'identity_id'     => $foreign,
                'organization_id' => $this->orgB,
                'clinic_id'       => $this->clinicB1,
            ]
        );

        $row = $this->patientRow( (int) $created['id'] );
        self::assertSame( $this->clinicA1, (int) $row['clinic_id'], 'C: clinic_id still comes from the trusted scope' );
        self::assertNotSame( $foreign, (int) $row['identity_id'], 'C: a caller-supplied identity_id is ignored' );
        self::assertSame(
            $this->orgA,
            (int) $this->identityRepo->find( (int) $row['identity_id'] )['organization_id'],
            'C: the activated identity stays inside the trusted Organization'
        );
    }

    // ================= D — tenant isolation =================

    public function testActivationRefusesRecordFromAnotherClinic(): void {
        $patientInB = $this->insertRawPatient( $this->clinicB1, 'MR-ACT-B1', '09120000005' );

        $this->expectException( PatientIdentityException::class );
        $this->activator->activate_for_new_patient( $this->clinicA1, $patientInB, '09120000005' );
    }

    public function testActivationFailsClosedForAClinicWithoutOrganization(): void {
        $missingClinic = 9321;
        $patientInA    = $this->insertRawPatient( $this->clinicA1, 'MR-ACT-A9', '09120000006' );
        $before        = $this->countIdentities();

        try {
            $this->activator->activate_for_new_patient( $missingClinic, $patientInA, '09120000006' );
            self::fail( 'D: unresolved Organization must fail closed' );
        } catch ( PatientIdentityException $e ) {
            self::assertSame( 'CLINIC_PATIENT_IDENTITY_ORG_UNRESOLVED', $e->error_code );
        }

        self::assertSame( $before, $this->countIdentities(), 'D: no identity is minted on a fail-closed path' );
        self::assertNull( $this->patientRow( $patientInA )['identity_id'], 'D: the record stays unlinked' );
    }

    // ================= E — fail-closed rollback of the whole create =================

    public function testFailedActivationRollsBackTheWholeCreate(): void {
        $patientsBefore   = $this->countPatients();
        $identitiesBefore = $this->countIdentities();
        $now              = App::db()->nowUtcSql();

        // Exactly the production boundary used by PatientService::create() and
        // BookingService::createMinimalPatient(): clinical row + identity
        // activation inside ONE transaction. A later failure must leave neither.
        try {
            App::db()->transactional(
                function () use ( $now ): void {
                    App::db()->insert(
                        'cpms_patients',
                        [
                            'clinic_id'  => $this->clinicA1,
                            'mrn'        => 'MR-ACT-RB1',
                            'first_name' => 'برگشت',
                            'last_name'  => 'خورده',
                            'mobile'     => '09120000007',
                            'status'     => 'active',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]
                    );
                    $patientId = App::db()->wpdb_last_insert_id();
                    self::assertGreaterThan( 0, $patientId );

                    $identityId = $this->activator->activate_for_new_patient(
                        $this->clinicA1,
                        $patientId,
                        '09120000007'
                    );
                    self::assertGreaterThan( 0, $identityId );

                    throw new BookingException( 'CLINIC_INTERNAL_ERROR', 'شکست عمدی پس از فعال‌سازی', 500 );
                }
            );
            self::fail( 'E: the deliberate failure must propagate' );
        } catch ( BookingException $e ) {
            self::assertSame( 'CLINIC_INTERNAL_ERROR', $e->errorCode ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
        }

        self::assertSame( $patientsBefore, $this->countPatients(), 'E: no half-created clinical record survives' );
        self::assertSame( $identitiesBefore, $this->countIdentities(), 'E: no orphan identity survives' );
        self::assertNull(
            App::db()->fetchRow(
                'SELECT id FROM ' . App::db()->table( 'cpms_patients' ) . ' WHERE clinic_id = %d AND mobile = %s',
                [ $this->clinicA1, '09120000007' ]
            ),
            'E: the rolled-back mobile is still free'
        );
    }

    // ================= F — concurrency / never overwrite =================

    public function testExistingLinkIsNeverOverwritten(): void {
        $patient  = $this->insertRawPatient( $this->clinicA1, 'MR-ACT-CC', '09120000008' );
        $original = $this->identityService->create_identity( $this->orgA, '09120000008' );
        $rival    = $this->identityService->create_identity( $this->orgA, '09120000008' );

        self::assertSame( 1, $this->identityRepo->set_patient_identity_if_unlinked( $patient, $original ), 'F: first writer wins' );
        self::assertSame(
            0,
            $this->identityRepo->set_patient_identity_if_unlinked( $patient, $rival ),
            'F: the conditional write refuses to clobber an existing link'
        );
        self::assertSame( $original, (int) $this->patientRow( $patient )['identity_id'] );

        // Re-running activation on an already linked record is an idempotent no-op.
        $before = $this->countIdentities();
        self::assertSame(
            $original,
            $this->activator->activate_for_new_patient( $this->clinicA1, $patient, '09120000008' ),
            'F: activation is idempotent for an already-activated record'
        );
        self::assertSame( $before, $this->countIdentities(), 'F: no duplicate identity is minted on re-entry' );
    }

    public function testForeignOrganizationLinkOnARecordFailsClosed(): void {
        $patient = $this->insertRawPatient( $this->clinicA1, 'MR-ACT-XO', '09120000009' );
        $foreign = $this->identityService->create_identity( $this->orgB, '09120000009' );
        self::assertSame( 1, $this->identityRepo->set_patient_identity_if_unlinked( $patient, $foreign ) );

        $this->expectException( PatientIdentityException::class );
        $this->activator->activate_for_new_patient( $this->clinicA1, $patient, '09120000009' );
    }

    // ================= G/H — explicit trusted verification is the only reuse =================

    public function testVerifiedUserLinkIsTheOnlyReusePath(): void {
        $userId = self::factory()->user->create();

        $patientNoLink = $this->insertRawPatient( $this->clinicA1, 'MR-ACT-G0', '09120000010' );
        $mintedFresh   = $this->activator->activate_for_new_patient( $this->clinicA1, $patientNoLink, '09120000010', $userId );

        // No explicit identity<->user link exists yet ⇒ a fresh identity, never
        // a mobile match, even though the mobile is identical below.
        $existing = $this->identityService->create_identity( $this->orgA, '09120000011' );
        $this->identityService->link_user( $this->orgA, $existing, $userId, '09120000011' );

        $patientWithLink = $this->insertRawPatient( $this->clinicA2, 'MR-ACT-G1', '09120000012' );
        $reused          = $this->activator->activate_for_new_patient( $this->clinicA2, $patientWithLink, '09120000012', $userId );

        self::assertNotSame( $mintedFresh, $reused, 'G: without a trusted link no identity is reused' );
        self::assertSame( $existing, $reused, 'G: the single explicit trusted link is reused' );

        // Ambiguity (more than one explicit link) falls back to a fresh identity.
        $second = $this->identityService->create_identity( $this->orgA, '09120000013' );
        $this->identityService->link_user( $this->orgA, $second, $userId, '09120000013' );

        $patientAmbiguous = $this->insertRawPatient( $this->clinicA1, 'MR-ACT-G2', '09120000014' );
        $ambiguous        = $this->activator->activate_for_new_patient( $this->clinicA1, $patientAmbiguous, '09120000014', $userId );

        self::assertNotSame( $existing, $ambiguous, 'G: ambiguous links never auto-merge' );
        self::assertNotSame( $second, $ambiguous, 'G: ambiguous links never auto-merge' );
    }

    public function testCrossOrganizationVerifiedLinkIsNotReused(): void {
        $userId     = self::factory()->user->create();
        $identityB  = $this->identityService->create_identity( $this->orgB, '09120000015' );
        $this->identityService->link_user( $this->orgB, $identityB, $userId, '09120000015' );

        $patientInA = $this->insertRawPatient( $this->clinicA1, 'MR-ACT-H1', '09120000015' );
        $activated  = $this->activator->activate_for_new_patient( $this->clinicA1, $patientInA, '09120000015', $userId );

        self::assertNotSame( $identityB, $activated, 'H: an identity from another Organization is never reused' );
        self::assertSame(
            $this->orgA,
            (int) $this->identityRepo->find( $activated )['organization_id'],
            'H: the activated identity stays in the Clinic Organization'
        );
    }

    // ================= I — legacy records stay conservative =================

    public function testLegacyNullIdentityRecordsAreNotBackfilled(): void {
        $legacy = $this->insertRawPatient( $this->clinicA1, 'MR-ACT-LEG', '09120000016' );
        self::assertNull( $this->patientRow( $legacy )['identity_id'] );

        // A brand-new creation with the SAME mobile family must not touch it.
        $this->createPatientInClinic( $this->clinicA2, 'میراث', 'دست‌نخورده', '09120000016' );

        self::assertNull(
            $this->patientRow( $legacy )['identity_id'],
            'I: legacy NULL identity_id records are never auto-linked or merged'
        );

        // Re-running migrations must not backfill them either.
        App::migrations()->migrate();
        self::assertNull(
            $this->patientRow( $legacy )['identity_id'],
            'I: migrations perform no identity backfill'
        );
    }

    // ================= J — no new migration required =================

    public function testActivationNeedsNoNewMigration(): void {
        self::assertSame(
            self::LATEST_MIGRATION,
            App::migrations()->currentVersion(),
            'J: activation is a behaviour change only — no migration 0028 is introduced'
        );

        global $wpdb;
        $patients = $wpdb->prefix . 'cpms_patients';
        self::assertNotNull(
            $wpdb->get_row( "SHOW COLUMNS FROM {$patients} LIKE 'identity_id'", ARRAY_A ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'J: identity_id already exists (migration 0018)'
        );
        self::assertNotNull(
            $wpdb->get_row(
                'SELECT 1 AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()'
                . " AND TABLE_NAME = '{$patients}' AND COLUMN_NAME = 'identity_id'", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                ARRAY_A
            ),
            'J: identity_id is already indexed (FK fk_patients_identity) — no new index needed'
        );
    }

    // ================= helpers =================

    private function insertClinic( int $id, int $organizationId, string $slug ): void {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at)
                 VALUES (%d, %d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $id,
                $organizationId,
                'کلینیک ' . $slug,
                $slug,
                'Asia/Tehran',
                $now,
                $now
            )
        );
    }

    private function insertRawPatient( int $clinicId, string $mrn, string $mobile ): int {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                 (clinic_id, mrn, first_name, last_name, mobile, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $mrn,
                'خام',
                'رکورد',
                $mobile,
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $id );

        return $id;
    }

    /**
     * @param array<string, mixed> $extraFields
     * @return array<string, mixed>
     */
    private function createPatientInClinic(
        int $clinicId,
        string $firstName,
        string $lastName,
        string $mobile,
        ?string $nationalId = null,
        array $extraFields = []
    ): array {
        ScopeContext::set( ClinicScope::forClinic( $clinicId ) );

        $fields = [
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'mobile'     => $mobile,
        ] + $extraFields;
        if ( $nationalId !== null ) {
            $fields['national_id'] = $nationalId;
        }

        return App::patientService()->create( $fields, 1 );
    }

    /**
     * @return array<string, mixed>
     */
    private function patientRow( int $patientId ): array {
        $row = App::db()->fetchRow(
            'SELECT id, clinic_id, identity_id FROM ' . App::db()->table( 'cpms_patients' ) . ' WHERE id = %d',
            [ $patientId ]
        );
        self::assertNotNull( $row );

        return $row;
    }

    private function countIdentities(): int {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patient_identities' )
        );
    }

    private function countPatients(): int {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_patients' )
        );
    }
}
