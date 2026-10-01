<?php
/**
 * Phase 13 Slice 2 — Doctor Portal bounded finalized structured-prescription
 * history + reprint — TEST-ONLY RED.
 *
 * Authorized bounded contract (Owner decision, Slice 2):
 *   GET /clinic/v1/doctor/portal/prescriptions/history
 *   returns ONLY finalized structured prescriptions whose PERSISTED
 *   relationships prove: prescription -> its Visit, Visit -> trusted Clinic,
 *   Visit -> CURRENT trusted Location, Visit patient == prescription patient,
 *   Visit clinician == prescription clinician == server-derived current
 *   doctor, prescription status = finalized (non-draft, non-voided) with a
 *   persisted finalized_at and at least one structured item (i.e. eligible
 *   for the already-delivered Phase 13 Slice 1 print route).
 *
 * Authority (identical policy to the merged Slice 1 print boundary):
 *   authenticated WP user + doctor role + existing cpms_rx_read capability +
 *   existing WordPress nonce + server-derived clinician<->WP-user mapping
 *   (client clinician_id is never authority) + trusted Clinic + CURRENT
 *   operational Location under the established strict 0/1/N policy
 *   (0 => fail closed, 1 => auto-resolution, N>1 => explicit eligible
 *   Location required — never first/primary/global fallback, foreign /
 *   inactive / unassigned => fail closed).
 *
 * Privacy-minimal row (exact key set):
 *   prescription_id (print selector), visit_id (print selector),
 *   prescription_number, patient_name, finalized_at_local,
 *   finalized_at_jalali.
 *   NO patient_id / clinician_id / clinic_id / location_id / MRN / mobile /
 *   national id / notes / files / audit / correction internals / items.
 *
 * Bounds: existing Doctor/Staff Portal bounded-list convention — RESULT_LIMIT
 * 100 with the established limit+1 `has_more` probe (NOT the legacy
 * `past_visits` 50). Deterministic order: finalized_at DESC, id DESC.
 *
 * Reprint: every row re-invokes the EXISTING Slice 1 route
 *   GET /clinic/v1/doctor/portal/visits/{id}/prescriptions/{rx}/print
 * which MUST independently re-validate authority and eligibility; history
 * presence never grants print authority.
 *
 * INTENDED PRODUCT RED (verified pre-write on the live head): the history
 * route does NOT exist (REST dispatch -> 404 rest_no_route) and the Doctor
 * Portal shell has no history surface. Every fixture, the Slice 1 print
 * control and all negative/authority controls are already-delivered behavior
 * and must stay GREEN.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Application\Visits\VisitService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class Phase13DoctorPortalPrescriptionHistoryRedTest extends WP_UnitTestCase
{
    private const HISTORY = 'clinic/v1/doctor/portal/prescriptions/history';
    private const PRINT_ROUTE = 'clinic/v1/doctor/portal/visits/%d/prescriptions/%d/print';
    private const FIXED_UTC = '2026-03-14 10:00:00';
    private const FIXED_UTC_DATE = '2026-03-14';
    private const TZ_TEHRAN = 'Asia/Tehran';
    private const ROW_KEYS = [
        'prescription_id',
        'visit_id',
        'prescription_number',
        'patient_name',
        'finalized_at_local',
        'finalized_at_jalali',
    ];
    /** Established portal bounded-list result limit (FinancePortalController::RESULT_LIMIT). */
    private const RESULT_LIMIT = 100;

    private $filterCb = null;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        App::migrations()->migrate();

        $fixed = new \DateTimeImmutable(self::FIXED_UTC, new \DateTimeZone('UTC'));
        VisitService::setTestNowUtc($fixed);
        $this->filterCb = static function () use ($fixed): \DateTimeImmutable {
            return $fixed;
        };
        add_filter('cpms_visit_now_utc', $this->filterCb);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        VisitService::setTestNowUtc(null);
        if ($this->filterCb !== null) {
            remove_filter('cpms_visit_now_utc', $this->filterCb);
            $this->filterCb = null;
        }
        parent::tearDown();
    }

    // ============ G1 — success, privacy-minimal shape, ordering, timezone/Jalali, read-only ============

    public function testHistoryReturnsOwnFinalizedStructuredRowsOnlyWithPrivacyMinimalShape(): void
    {
        $fx = $this->makePortalStage('p13h1');
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'cpms_locations', ['timezone' => 'Pacific/Kiritimati'], ['id' => $fx['location']]);
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);

        $patientId = $this->insertPatient($fx['clinic'], 'p13h1');
        $visitId = $this->insertVisit($patientId, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');

        // Eligible finalized structured prescriptions (boundary-crossing UTC instants).
        $older = $this->insertFinalizedRx($visitId, $patientId, $fx['clinician'], $fx['clinic'], '2026-03-10 10:30:00.000', 1);
        $tieA = $this->insertFinalizedRx($visitId, $patientId, $fx['clinician'], $fx['clinic'], '2026-03-20 10:30:00.000', 2);
        $tieB = $this->insertFinalizedRx($visitId, $patientId, $fx['clinician'], $fx['clinic'], '2026-03-20 10:30:00.000', 3);

        // Ineligible states on the very same authorized Visit.
        $draft = $this->insertPrescription($visitId, $patientId, $fx['clinician'], $fx['clinic'], 'draft', 1, 4);
        $this->insertRxItem($draft, $this->validItem());
        $voided = $this->insertPrescription($visitId, $patientId, $fx['clinician'], $fx['clinic'], 'voided', 1, 5);
        $this->insertRxItem($voided, $this->validItem());
        // Finalized but with no structured item => not printable => not history.
        $itemless = $this->insertPrescription($visitId, $patientId, $fx['clinician'], $fx['clinic'], 'finalized', 1, 6);

        $before = $this->fullRowSnapshot($fx['clinic'], $visitId);

        wp_set_current_user($fx['doctor']);
        $response = $this->dispatch('GET', '/' . self::HISTORY, ['clinician_id' => 999999], $headers);
        $this->gate(200, $response, 'P13S2.RED: an authorized doctor must receive their bounded finalized prescription history');

        $data = $this->payload($response);
        self::assertSame(['prescriptions', 'has_more'], array_keys($data), 'P13S2: bounded history envelope is minimal');
        self::assertFalse($data['has_more'], 'P13S2: three rows are far below the bounded limit');
        $rows = $data['prescriptions'];
        self::assertCount(3, $rows, 'P13S2: draft, voided and itemless prescriptions are excluded');

        $ids = array_map(static fn(array $row): int => (int) $row['prescription_id'], $rows);
        self::assertSame([$tieB, $tieA, $older], $ids, 'P13S2: newest finalized first with stable descending id tie-break');
        self::assertNotContains($draft, $ids, 'P13S2: drafts are excluded');
        self::assertNotContains($voided, $ids, 'P13S2: voided prescriptions are excluded');
        self::assertNotContains($itemless, $ids, 'P13S2: prescriptions ineligible for the existing print flow are excluded');

        foreach ($rows as $row) {
            self::assertSame(self::ROW_KEYS, array_keys($row), 'P13S2: privacy-minimal row shape');
            foreach (['patient_id', 'clinician_id', 'clinic_id', 'location_id', 'mrn', 'mobile', 'national_id', 'notes', 'files', 'items', 'status'] as $forbidden) {
                self::assertArrayNotHasKey($forbidden, $row, 'P13S2: forbidden field ' . $forbidden . ' must never be exposed');
            }
            self::assertSame($visitId, (int) $row['visit_id'], 'P13S2: visit selector is the owning Visit');
        }
        self::assertSame('2026-03-21 00:30', $rows[0]['finalized_at_local'], 'P13S2: trusted Location timezone resolves the local instant');
        self::assertSame('1405/01/02', $rows[0]['finalized_at_jalali'], 'P13S2: Jalali conversion receives the Location-local Gregorian date');
        self::assertSame('2026-03-11 00:30', $rows[2]['finalized_at_local'], 'P13S2: every row uses the same Location timezone authority');
        $patientRow = App::db()->fetchRow(
            'SELECT first_name, last_name FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d',
            [$patientId]
        );
        self::assertIsArray($patientRow);
        self::assertSame(
            trim((string) $patientRow['first_name'] . ' ' . (string) $patientRow['last_name']),
            $rows[0]['patient_name'],
            'P13S2: patient display name only'
        );

        self::assertSame($before, $this->fullRowSnapshot($fx['clinic'], $visitId), 'P13S2: the history GET is full-row read-only (prescriptions, items, visits, audit)');
    }

    // ============ G2 — doctor / Clinic / Location isolation ============

    public function testHistoryIsolatesOtherDoctorsOtherLocationsAndForeignClinics(): void
    {
        $fx = $this->makePortalStage('p13h2');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);
        $patientId = $this->insertPatient($fx['clinic'], 'p13h2');
        $visitId = $this->insertVisit($patientId, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');
        $mine = $this->insertFinalizedRx($visitId, $patientId, $fx['clinician'], $fx['clinic'], '2026-03-12 08:00:00.000', 1);

        // Same Clinic + same Location, another doctor.
        $peerUser = $this->makeUser('p13h2_peer', RolesAndCapabilities::ROLE_DOCTOR);
        $peerClinician = $this->insertClinician('Dr QA p13h2peer', $fx['clinic'], 1, $peerUser);
        cpms_test_seed_membership($peerUser, $fx['clinic'], 'cpms_doctor');
        $peerPatient = $this->insertPatient($fx['clinic'], 'p13h2peer');
        $peerVisit = $this->insertVisit($peerPatient, $peerClinician, $fx['clinic'], $fx['location'], 'in_consultation');
        $peerRx = $this->insertFinalizedRx($peerVisit, $peerPatient, $peerClinician, $fx['clinic'], '2026-03-13 08:00:00.000', 2);

        // Same Clinic, another Location, same doctor.
        $otherLoc = $this->insertLocation($fx['clinic'], 'Stage Loc p13h2other', self::TZ_TEHRAN, 0);
        $otherLocVisit = $this->insertVisit($patientId, $fx['clinician'], $fx['clinic'], $otherLoc, 'in_consultation');
        $otherLocRx = $this->insertFinalizedRx($otherLocVisit, $patientId, $fx['clinician'], $fx['clinic'], '2026-03-13 09:00:00.000', 3);

        // Foreign Clinic entirely.
        $foreign = $this->makePortalStage('p13h2fgn');
        $foreignPatient = $this->insertPatient($foreign['clinic'], 'p13h2fgn');
        $foreignVisit = $this->insertVisit($foreignPatient, $foreign['clinician'], $foreign['clinic'], $foreign['location'], 'in_consultation');
        $foreignRx = $this->insertFinalizedRx($foreignVisit, $foreignPatient, $foreign['clinician'], $foreign['clinic'], '2026-03-13 10:00:00.000', 4);

        wp_set_current_user($fx['doctor']);
        // N>1 eligible Locations now exist => explicit Location is required.
        $ambiguous = $this->dispatch('GET', '/' . self::HISTORY, [], $this->scopeHeaders($fx['clinic']));
        $this->gateIn([400, 403], $ambiguous, 'P13S2: multiple eligible Locations require an explicit selection — no first/primary fallback');

        $response = $this->dispatch('GET', '/' . self::HISTORY, [], $headers);
        $this->gate(200, $response, 'P13S2: explicit CURRENT Location resolves the bounded history');
        $ids = array_map(static fn(array $row): int => (int) $row['prescription_id'], $this->payload($response)['prescriptions']);
        self::assertSame([$mine], $ids, 'P13S2: only the current doctor at the CURRENT trusted Location of the trusted Clinic');
        self::assertNotContains($peerRx, $ids, 'P13S2: another doctor is excluded');
        self::assertNotContains($otherLocRx, $ids, 'P13S2: the same Clinic other Location is excluded');
        self::assertNotContains($foreignRx, $ids, 'P13S2: a foreign Clinic is excluded');

        // A foreign Location selector inside the trusted Clinic scope fails closed.
        $foreignLocation = $this->dispatch('GET', '/' . self::HISTORY, [], $this->scopeHeaders($fx['clinic'], $foreign['location']));
        $this->gateIn([403, 404], $foreignLocation, 'P13S2: a foreign Location selector fails closed');

        // Inactive Location => not eligible => fail closed (single remaining Location deactivated too).
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'cpms_locations', ['is_active' => 0], ['id' => $fx['location']]);
        $wpdb->update($wpdb->prefix . 'cpms_locations', ['is_active' => 0], ['id' => $otherLoc]);
        $inactive = $this->dispatch('GET', '/' . self::HISTORY, [], $headers);
        $this->gateIn([400, 403, 404], $inactive, 'P13S2: inactive/zero eligible Location fails closed');
    }

    public function testHistoryRequiresNonceCapabilityAndServerDerivedClinicianIdentity(): void
    {
        $fx = $this->makePortalStage('p13h3');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);
        $patientId = $this->insertPatient($fx['clinic'], 'p13h3');
        $visitId = $this->insertVisit($patientId, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');
        $this->insertFinalizedRx($visitId, $patientId, $fx['clinician'], $fx['clinic'], '2026-03-12 08:00:00.000', 1);

        wp_set_current_user(0);
        $anonymous = $this->dispatch('GET', '/' . self::HISTORY, [], $headers);
        $this->gateIn([401, 403], $anonymous, 'P13S2: anonymous history read is denied');

        wp_set_current_user($fx['doctor']);
        $withoutNonce = $this->dispatch('GET', '/' . self::HISTORY, [], $headers, false);
        $this->gateIn([401, 403], $withoutNonce, 'P13S2: the existing nonce/CSRF boundary is required');

        $doctorUser = get_userdata($fx['doctor']);
        self::assertNotFalse($doctorUser);
        $doctorUser->set_role('subscriber');
        $withoutCapability = $this->dispatch('GET', '/' . self::HISTORY, [], $headers);
        $this->gateIn([401, 403], $withoutCapability, 'P13S2: the existing prescription-read capability is required');
        $doctorUser->set_role(RolesAndCapabilities::ROLE_DOCTOR);

        // Membership alone is insufficient: a member without a clinician mapping is denied.
        $secretary = $this->makeUser('p13h3_sec', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($secretary);
        $memberOnly = $this->dispatch('GET', '/' . self::HISTORY, [], $headers);
        $this->gateIn([401, 403], $memberOnly, 'P13S2: clinic membership alone never grants doctor history');
    }

    // ============ G3 — bound / has_more / N+1 ============

    public function testHistoryIsBoundedDeterministicAndFreeOfPerRowQueries(): void
    {
        $fx = $this->makePortalStage('p13h4');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);
        $patientId = $this->insertPatient($fx['clinic'], 'p13h4');
        $visitId = $this->insertVisit($patientId, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');

        wp_set_current_user($fx['doctor']);
        global $wpdb;

        for ($i = 1; $i <= 3; $i++) {
            $this->insertFinalizedRx($visitId, $patientId, $fx['clinician'], $fx['clinic'], sprintf('2026-03-%02d 08:00:00.000', $i), $i);
        }
        $warm = $this->dispatch('GET', '/' . self::HISTORY, [], $headers);
        $this->gate(200, $warm, 'P13S2: bounded history read succeeds with a small dataset');
        $baselineStart = (int) $wpdb->num_queries;
        $small = $this->dispatch('GET', '/' . self::HISTORY, [], $headers);
        $smallCost = (int) $wpdb->num_queries - $baselineStart;
        $this->gate(200, $small, 'P13S2: bounded history read succeeds with a small dataset');
        self::assertCount(3, $this->payload($small)['prescriptions']);

        for ($i = 4; $i <= self::RESULT_LIMIT + 1; $i++) {
            $this->insertFinalizedRx($visitId, $patientId, $fx['clinician'], $fx['clinic'], sprintf('2026-03-%02d 08:00:00.000', ($i % 28) + 1), $i);
        }
        $largeStart = (int) $wpdb->num_queries;
        $large = $this->dispatch('GET', '/' . self::HISTORY, [], $headers);
        $largeCost = (int) $wpdb->num_queries - $largeStart;
        $this->gate(200, $large, 'P13S2: bounded history read succeeds with an oversized dataset');
        $body = $this->payload($large);
        self::assertCount(self::RESULT_LIMIT, $body['prescriptions'], 'P13S2: the response is bounded by the established portal result limit');
        self::assertTrue($body['has_more'], 'P13S2: the established limit+1 probe reports has_more');
        self::assertLessThanOrEqual($smallCost + 1, $largeCost, 'P13S2: query cost is independent of row count (no N+1 enrichment)');
        self::assertLessThanOrEqual(25, $largeCost, 'P13S2: the bounded history read stays within a small fixed query budget');

        $ids = array_map(static fn(array $row): int => (int) $row['prescription_id'], $body['prescriptions']);
        self::assertSame($ids, array_values(array_unique($ids)), 'P13S2: deterministic, duplicate-free page');
        $repeat = $this->dispatch('GET', '/' . self::HISTORY, [], $headers);
        self::assertSame(
            $ids,
            array_map(static fn(array $row): int => (int) $row['prescription_id'], $this->payload($repeat)['prescriptions']),
            'P13S2: ordering is deterministic across identical reads'
        );
    }

    // ============ G4 — reprint reuses the Slice 1 route which re-authorizes independently ============

    public function testEveryHistoryRowReprintsThroughTheExistingPrintRouteWhichReauthorizes(): void
    {
        $fx = $this->makePortalStage('p13h5');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);
        $patientId = $this->insertPatient($fx['clinic'], 'p13h5');
        $visitId = $this->insertVisit($patientId, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');
        $rxId = $this->insertFinalizedRx($visitId, $patientId, $fx['clinician'], $fx['clinic'], '2026-03-12 08:00:00.000', 1);

        wp_set_current_user($fx['doctor']);
        $history = $this->dispatch('GET', '/' . self::HISTORY, [], $headers);
        $this->gate(200, $history, 'P13S2: history is available for the reprint journey');
        $rows = $this->payload($history)['prescriptions'];
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame($rxId, (int) $row['prescription_id']);

        $printRoute = '/' . sprintf(self::PRINT_ROUTE, (int) $row['visit_id'], (int) $row['prescription_id']);
        $print = $this->dispatch('GET', $printRoute, [], $headers);
        $this->gate(200, $print, 'P13S2: the row selectors invoke the existing Slice 1 print route unchanged');
        self::assertSame(
            ['prescription_number', 'patient', 'clinician', 'location', 'finalized_at_local', 'finalized_at_jalali', 'items'],
            array_keys($this->payload($print)),
            'P13S2: the delivered Slice 1 print projection is reused verbatim — no second print-data engine'
        );
        self::assertSame($row['prescription_number'], $this->payload($print)['prescription_number']);

        // The print route re-validates independently: history presence grants nothing.
        $doctorUser = get_userdata($fx['doctor']);
        self::assertNotFalse($doctorUser);
        $doctorUser->set_role('subscriber');
        $deniedPrint = $this->dispatch('GET', $printRoute, [], $headers);
        $this->gateIn([401, 403], $deniedPrint, 'P13S2: the print route independently rechecks capability after a history listing');
        $doctorUser->set_role(RolesAndCapabilities::ROLE_DOCTOR);

        $withoutNoncePrint = $this->dispatch('GET', $printRoute, [], $headers, false);
        $this->gateIn([401, 403], $withoutNoncePrint, 'P13S2: the print route independently requires the nonce');

        $peerUser = $this->makeUser('p13h5_peer', RolesAndCapabilities::ROLE_DOCTOR);
        $peerClinician = $this->insertClinician('Dr QA p13h5peer', $fx['clinic'], 1, $peerUser);
        cpms_test_seed_membership($peerUser, $fx['clinic'], 'cpms_doctor');
        wp_set_current_user($peerUser);
        $peerPrint = $this->dispatch('GET', $printRoute, [], $headers);
        $this->gateIn([403, 404], $peerPrint, 'P13S2: another doctor cannot print a listed row');
        $peerHistory = $this->dispatch('GET', '/' . self::HISTORY, [], $headers);
        $this->gate(200, $peerHistory, 'P13S2: the peer doctor has their own (empty) bounded history');
        self::assertSame([], $this->payload($peerHistory)['prescriptions'], 'P13S2: empty history state for a doctor without finalized prescriptions here');
    }

    // ============ G5 — the portal surface itself: no wp-admin, no handwriting ============

    public function testDoctorPortalShellExposesHistoryWithoutAdminOrHandwritingSurface(): void
    {
        $fx = $this->makePortalStage('p13h6');
        $url = $this->tryResolveDoctorPortalUrl();
        self::assertNotNull($url, 'P13S2.fixture: the independent Doctor Portal page must be resolvable');
        $html = $this->renderPortal($fx['doctor'], $url);

        self::assertStringContainsString('data-role="rx-history-section"', $html, 'P13S2.RED: the independent Doctor Portal must own a bounded prescription-history surface');
        self::assertStringContainsString('data-role="rx-history-list"', $html, 'P13S2.RED: the history surface renders a bounded list');
        self::assertStringContainsString('data-role="rx-history-empty"', $html, 'P13S2.RED: the history surface renders an explicit empty state');
        self::assertStringContainsString('data-role="rx-history-print"', $html, 'P13S2.RED: every eligible history row offers the existing print action');
        self::assertStringContainsString('/doctor/portal/prescriptions/history', $html, 'P13S2.RED: the surface reads the dedicated bounded history endpoint');
        self::assertStringNotContainsString('cpms-prescription-print', $html, 'P13S2: the hidden legacy wp-admin print page is not the user workflow');
        self::assertStringNotContainsString('rx-history-handwriting', $html, 'P13S2: handwriting is not part of this history surface');
    }

    // ================= helpers (established Doctor Portal test patterns) =================

    /**
     * @return array{clinic: int, location: int, clinician: int, doctor: int}
     */
    private function makePortalStage(string $tag): array
    {
        $org = $this->insertOrg('Stage ' . $tag);
        $clinic = $this->insertClinicInOrg('Stage Clinic ' . $tag, $org, self::TZ_TEHRAN);
        $loc = $this->insertLocation($clinic, 'Stage Loc ' . $tag, self::TZ_TEHRAN, 1);
        $doctor = $this->makeUser('qa_' . $tag . '_doc', RolesAndCapabilities::ROLE_DOCTOR);
        $clinician = $this->insertClinician('Dr QA ' . $tag, $clinic, 1, $doctor);
        cpms_test_seed_membership($doctor, $clinic, 'cpms_doctor');
        return ['clinic' => $clinic, 'location' => $loc, 'clinician' => $clinician, 'doctor' => $doctor];
    }

    /**
     * @return array<string, string>
     */
    private function scopeHeaders(int $clinic, ?int $location = null): array
    {
        $headers = ['X-CPMS-Clinic-Id' => (string) $clinic];
        if ($location !== null) {
            $headers['X-CPMS-Location-Id'] = (string) $location;
        }
        return $headers;
    }

    /**
     * Full-row before/after evidence set for the read-only proof.
     *
     * @return array<string, mixed>
     */
    private function fullRowSnapshot(int $clinicId, int $visitId): array
    {
        $db = App::db();
        return [
            'prescriptions' => $db->fetchAll(
                'SELECT * FROM ' . $db->table('cpms_prescriptions') . ' WHERE clinic_id = %d ORDER BY id ASC',
                [$clinicId]
            ),
            'items' => $db->fetchAll(
                'SELECT i.* FROM ' . $db->table('cpms_prescription_items') . ' i INNER JOIN ' .
                $db->table('cpms_prescriptions') . ' r ON r.id = i.prescription_id WHERE r.clinic_id = %d ORDER BY i.id ASC',
                [$clinicId]
            ),
            'visit' => $db->fetchRow('SELECT * FROM ' . $db->table('cpms_visits') . ' WHERE id = %d', [$visitId]),
            'audit' => $db->fetchAll(
                'SELECT * FROM ' . $db->table('cpms_audit_logs') . ' WHERE clinic_id = %d ORDER BY id ASC',
                [$clinicId]
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validItem(array $overrides = []): array
    {
        return $overrides + [
            'generic_name' => 'Amoxicillin',
            'brand_name' => 'Amoxil',
            'strength' => '500mg',
            'form' => 'capsule',
            'dose' => '1 کپسول',
            'frequency' => 'هر 12 ساعت',
            'route' => 'oral',
            'duration_days' => 7,
            'instructions' => 'با غذا',
        ];
    }

    private function insertFinalizedRx(int $visitId, int $patientId, int $clinicianId, int $clinicId, string $finalizedAt, int $ordinal): int
    {
        $id = $this->insertPrescription($visitId, $patientId, $clinicianId, $clinicId, 'finalized', 0, $ordinal);
        $this->insertRxItem($id, $this->validItem());
        global $wpdb;
        $updated = $wpdb->update($wpdb->prefix . 'cpms_prescriptions', ['finalized_at' => $finalizedAt], ['id' => $id]);
        self::assertSame(1, (int) $updated, 'P13S2.fixture: finalized_at instant must persist');
        return $id;
    }

    private function gate(int $wanted, WP_REST_Response $r, string $message): void
    {
        $got = $r->get_status();
        if ($got !== $wanted) {
            $this->annotate('status', $message, 'expected=' . $wanted . ' got=' . $got . ' code=' . $this->errCode($r));
        }
        self::assertSame($wanted, $got, $message);
    }

    /**
     * @param list<int> $wantedSet
     */
    private function gateIn(array $wantedSet, WP_REST_Response $r, string $message): void
    {
        $got = $r->get_status();
        if (!in_array($got, $wantedSet, true)) {
            $this->annotate('status-set', $message, 'expected=' . implode('|', $wantedSet) . ' got=' . $got . ' code=' . $this->errCode($r));
        }
        self::assertContains($got, $wantedSet, $message);
    }

    private function annotate(string $kind, string $message, string $detail): void
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $message . ' :: ' . $detail));
        $esc = str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', ' -', ';'], $text);
        $tokens = trim(preg_replace('/[^A-Za-z0-9._-]/', '', strstr($message, ':', true) ?: substr($message, 0, 12)));
        fwrite(STDERR, '::error title=CPMS-Phase13RxHistory-' . $kind . '-' . $tokens . '::' . $esc . PHP_EOL);
    }

    private function dispatch(string $method, string $route, array $params = [], array $headers = [], bool $withNonce = true): WP_REST_Response
    {
        $r = new WP_REST_Request($method, $route);
        foreach ($params as $k => $v) {
            $r->set_param($k, $v);
        }
        if ($withNonce) {
            $r->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        }
        foreach ($headers as $k => $v) {
            $r->set_header($k, $v);
        }
        return rest_do_request($r);
    }

    private function payload(WP_REST_Response $res): array
    {
        $b = $res->get_data();
        if (is_array($b) && isset($b['data']) && is_array($b['data'])) {
            return $b['data'];
        }
        return is_array($b) ? $b : [];
    }

    private function errCode(WP_REST_Response $res): string
    {
        $b = $res->get_data();
        if ($b instanceof \WP_Error) {
            return (string) $b->get_error_code();
        }
        return (string) (is_array($b) ? ($b['code'] ?? '') : '');
    }

    private function makeUser(string $login, string $role): int
    {
        $u = $login . '_' . bin2hex(random_bytes(3));
        $id = (int) wp_create_user($u, 'pass-not-used-123', $u . '@test.local');
        self::assertGreaterThan(0, $id);
        $user = get_userdata($id);
        self::assertNotFalse($user);
        $user->set_role($role);
        return $id;
    }

    private function insertOrg(string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, %s, %s, %s)', $name, 'org-' . bin2hex(random_bytes(3)), 'active', $now, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'organization fixture row must persist');
        return $id;
    }

    private function insertClinicInOrg(string $name, int $orgId, string $tz): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', $orgId, $name, 'cl-' . bin2hex(random_bytes(3)), $tz, $now, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id);
        App::resetScope();
        return $id;
    }

    private function insertLocation(int $clinicId, string $name, string $tz, int $primary): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, %d, 1, %s, %s)', $clinicId, $name, 'loc-' . bin2hex(random_bytes(3)), $tz, $primary, $now, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'location fixture row must persist');
        return $id;
    }

    private function insertClinician(string $name, int $clinicId, int $active, int $wpUserId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at) VALUES (%d, %s, %d, %d, %s, %s)', $clinicId, $name, $wpUserId, $active, $now, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'clinician fixture row must persist (wp_user_id must be distinct)');
        return $id;
    }

    private function insertPatient(int $clinicId, string $tag): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $mrn = 'MR-QA-' . strtoupper($tag) . '-' . bin2hex(random_bytes(2));
        $mobile = '0912' . sprintf('%07d', random_int(1000000, 9999999));
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)', $clinicId, $mrn, 'Test', 'Patient ' . substr($mrn, -4), $mobile, 'active', $now, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'patient fixture row must persist');
        return $id;
    }

    private function insertVisit(int $patientId, int $clinicianId, int $clinicId, int $locId, string $status): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $active = 'skipped' === $status ? 0 : 1;
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_visits (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, waiting_since, active, created_at, updated_at) VALUES (%d, %d, %d, %d, %s, %s, %s, %s, %s, %d, %s, %s)', $clinicId, $locId, $clinicianId, $patientId, 'walk_in', $status, self::FIXED_UTC_DATE, self::FIXED_UTC_DATE . ' 10:00:00', self::FIXED_UTC_DATE . ' 10:00:00', $active, $now, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'visit fixture row must persist (clinician/location patient FKs must be valid)');
        return $id;
    }

    private function insertPrescription(int $visitId, int $patientId, int $clinicianId, int $clinicId, string $status, int $visible, int $ordinal = 0): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $number = 'RX-8' . sprintf('%04d', $visitId % 10000) . sprintf('%03d', $ordinal);
        $finalizedAt = 'finalized' === $status ? $wpdb->prepare('%s', $now) : 'NULL';
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_prescriptions (clinic_id, prescription_number, visit_id, patient_id, clinician_id, status, is_patient_visible, void_reason, correction_of_prescription_id, finalized_at, created_at, updated_at) VALUES (%d, %s, %d, %d, %d, %s, %d, NULL, NULL, '
            . $finalizedAt . ', %s, %s)',
            $clinicId, $number, $visitId, $patientId, $clinicianId, $status, $visible, $now, $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'prescription fixture row must persist');
        return $id;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function insertRxItem(int $rxId, array $item): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $tok = static function ($v, string $type = '%s') use ($wpdb): string {
            return $v === null ? 'NULL' : (string) $wpdb->prepare($type, $v);
        };
        $sql = 'INSERT INTO ' . $wpdb->prefix . 'cpms_prescription_items '
            . '(prescription_id, drug_ref_id, generic_name, brand_name, strength, form, dose, frequency, route, duration_days, instructions, source, ocr_job_id, sort_order, created_at) VALUES ('
            . (int) $rxId . ', NULL, '
            . $tok($item['generic_name']) . ', '
            . $tok($item['brand_name'] ?? null) . ', '
            . $tok($item['strength'] ?? null) . ', '
            . $tok($item['form'] ?? 'tablet') . ', '
            . $tok($item['dose']) . ', '
            . $tok($item['frequency']) . ', '
            . $tok($item['route'] ?? 'oral') . ', '
            . $tok($item['duration_days'] ?? null, '%d') . ', '
            . $tok($item['instructions'] ?? null) . ', '
            . $tok('manual') . ', NULL, 0, '
            . $tok($now) . ')';
        $wpdb->query($sql);
        self::assertGreaterThan(0, (int) $wpdb->insert_id, 'rx item fixture row must persist');
    }

    private function tryResolveDoctorPortalUrl(): ?string
    {
        $classes = ['ClinicCore\\Frontend\\DoctorPortalShell'];
        $methods = ['portal_url', 'frontendPortalUrl', 'frontendUrl', 'doctorPortalFrontendUrl', 'pageUrl'];
        foreach ($classes as $c) {
            if (!class_exists($c)) {
                continue;
            }
            foreach ($methods as $m) {
                if (!is_callable([$c, $m])) {
                    continue;
                }
                try {
                    $u = (string) $c::{$m}();
                    if ($u !== '' && !str_contains($u, '/wp-admin/') && str_contains($u, 'http')) {
                        return $u;
                    }
                } catch (\Throwable $e) {
                    unset($e);
                }
            }
        }
        return null;
    }

    private function renderPortal(int $userId, string $url): string
    {
        wp_set_current_user($userId);
        $path = (string) (wp_parse_url($url, \PHP_URL_PATH) ?? '/');
        $query = (string) (wp_parse_url($url, \PHP_URL_QUERY) ?? '');
        $req = $path . ($query !== '' ? '?' . $query : '');
        if ($req === '') {
            $req = '/';
        }
        $this->go_to($req);
        $baseline = get_stylesheet_directory() . '/page.php';
        if (!is_readable($baseline)) {
            $baseline = get_stylesheet_directory() . '/index.php';
        }
        $tpl = (string) apply_filters('template_include', $baseline);
        self::assertNotSame($baseline, $tpl, 'P13S2: template_include must intercept with the plugin-owned template');
        self::assertFileExists($tpl);
        ob_start();
        include $tpl;
        return (string) ob_get_clean();
    }
}
