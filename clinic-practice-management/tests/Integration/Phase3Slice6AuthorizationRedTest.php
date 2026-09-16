<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Authorization\AuthorizationException;
use ClinicCore\Application\Finance\FinanceException;
use ClinicCore\Application\Handwriting\HandwritingException;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * Phase 3 Slice 6A — RED characterization tests for Clinic-scoped authorization
 * gaps in ClinicianAdminPage, HandwritingService, and FinanceService.
 *
 * These tests are intentionally NOT weakened; they document the security contract
 * (fail-closed with 403/404 parity, deny overrides preset, suspended fails closed,
 * cross-Clinic must not leak or mutate) and must remain RED until the production
 * code for Slice 6A is in place.
 */
final class Phase3Slice6AuthorizationRedTest extends WP_UnitTestCase
{
    private int $orgId = 0;
    /** @var array<string,int> clinic tag -> id */
    private array $clinics = [];
    /** @var array<int,int> clinicId -> primary location_id */
    private array $locations = [];

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user(0);

        $this->orgId = $this->createOrganization();
        $this->clinics['A'] = $this->createClinic($this->orgId, 'p3s6a');
        $this->clinics['B'] = $this->createClinic($this->orgId, 'p3s6b');
        $this->locations[$this->clinics['A']] = $this->createLocation($this->clinics['A']);
        $this->locations[$this->clinics['B']] = $this->createLocation($this->clinics['B']);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        \ClinicCore\Application\Scope\SystemClinicResolver::flush();
        \ClinicCore\Settings\Settings::flushCache();
        $this->purgeRows();
        parent::tearDown();
    }

    // ===================== RED 1: ClinicianAdmin cross-Clinic mutation =====================

    /**
     * RED-1: a manager scoped only to Clinic A (active membership there, no membership
     * in B) must not be able to mutate a Clinician row owned by Clinic B by sending
     * that row's id in POST.
     *
     * Today, saveClinician() loads with Repo::find($id) (no clinic_id predicate) and
     * updates with Repo::update($id, …). TrustedClinicEstablisher is NOT invoked for
     * save/toggle paths and CONFIG is only the global cpms_config.
     */
    public function testSaveClinicianManagerInClinicACannotMutateClinicianInClinicB(): void
    {
        global $wpdb;

        $managerA = $this->makeUser('p3s6_mgr_a', RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($managerA, $this->clinics['A'], RolesAndCapabilities::ROLE_MANAGER);

        // B owns a durable clinician linked to a separate wp_user (UNIQUE 0007
        // prevents reusing the same wp_user across Clinics).
        $doctorBUser = $this->makeUser('p3s6_doc_b', RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($doctorBUser, $this->clinics['B'], RolesAndCapabilities::ROLE_DOCTOR);
        $victimId = $this->insertClinician($this->clinics['B'], $doctorBUser, 'Dr B Victim P3S6');

        // Capture the redirect/exit via wp_redirect filter + set_transient.
        wp_set_current_user($managerA);
        $_POST = [
            'clinician_id' => (string) $victimId,
            'full_name' => 'CROSS CLINIC HACK',
            'specialty' => 'pwned',
            'room' => 'X',
            'wp_user_id' => '0',
            '_wpnonce' => wp_create_nonce('cpms_clinician_save'),
            '_wp_http_referer' => admin_url('admin.php?page=cpms-clinicians'),
        ];
        $_REQUEST = $_POST;

        $redirect = null;
        add_filter('wp_redirect', function ($url) use (&$redirect) {
            $redirect = (string) $url;

            return false;
        }, 1);

        $threw = null;
        try {
            \ClinicCore\Admin\ClinicianAdminPage::saveClinician();
        } catch (\Exception $e) {
            $threw = $e;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT clinic_id, full_name, specialty, room FROM ' . $wpdb->prefix . 'cpms_clinicians WHERE id = %d',
                $victimId
            ),
            ARRAY_A
        );
        self::assertIsArray($row, 'victim clinician must still exist');

        // SECURITY CONTRACT: the B-owned row MUST NOT be mutated by an A-only manager.
        self::assertSame(
            $this->clinics['B'],
            (int) $row['clinic_id'],
            'post-condition: victim row clinic_id must remain Clinic B'
        );
        self::assertSame(
            'Dr B Victim P3S6',
            (string) $row['full_name'],
            'RED-1 evidence: a Clinic-A-only manager MUST NOT update a Clinic-B clinician by POSTing clinician_id'
        );
        // Additional evidence: the contract is deny-or-error.
        $denied = false;
        if ($threw instanceof \WPDieException) {
            $denied = true;
        }
        if (is_string($redirect) && (str_contains($redirect, 'error=1') || !str_contains($redirect, 'message=5'))) {
            // The only legitimate outcomes are wp_die (403) or a redirect back with
            // an error notice. Any success-shaped redirect with the row mutated is a
            // breach. We assert no mutation above; this assertion is informative.
        }
        // If no denial signal and no mutation, the handler silently succeeded → RED.
        self::assertTrue($denied || (is_string($redirect) && str_contains($redirect, 'clinician_id=' . $victimId)),
            'expected an explicit deny signal (wp_die or error redirect); got threw=' . get_class($threw ?: new \stdClass()) . ', redirect=' . (string) $redirect);
    }

    // ===================== RED 2: Handwriting cross-Clinic disclosure =====================

    /**
     * RED-2: A doctor who is an active member of Clinic A only (with durable
     * NOTE_CREATE/MEDICAL_READ there) must NOT be able to read stroke_data of a
     * document owned by Clinic B, even when they hold the exact document_id/page_id.
     *
     * Today HandwritingService only checks global cpms_note_create/cpms_medical_read
     * and requireOwnVisit; no Clinic scope / scoped permission is enforced. To reach
     * the getPage path the actor must be the visit's clinician, so we set up a
     * parallel doctor in Clinic B (same role but independent wp_user + clinician
     * row) and then use Clinic-A scope to attempt a read on B's page. The existing
     * requireOwnVisit will fail because the A-doctor wp_user ≠ B-doctor wp_user,
     * so we choose addPage/write against a B-document where the only gate should be
     * scoped clinic authorization.
     *
     * Actually, the tightest RED is to use a manager with NOTE_CREATE role who
     * bypasses requireOwnVisit? No — requireOwnVisit enforces clinician ownership
     * which is AND with scoped authorization. We need a path where scoped
     * authorization is the missing piece.  The cleanest demonstration is: actor is
     * legitimately the B-clinician (so requireOwnVisit passes) but the trusted
     * scope is A (attacker-submitted X-CPMS-Clinic-Id:A while actor has NO membership
     * in A and membership in B is active). The controller would have validated the
     * header against membership, so actor cannot be in B when scoped to A.  That
     * means we can't simultaneously satisfy requireOwnVisit AND be scoped to the
     * wrong Clinic using a separate wp_user.
     *
     * Instead: use a doctor who has TWO memberships (A and B) but is the clinician
     * for a B-owned visit/document. Call getPage with explicit trusted scope = A.
     * The object clinic (doc.clinic_id = B) differs from trusted clinic (A) → must
     * 404 parity BEFORE returning strokes.
     */
    public function testGetPageWithScopeOnClinicAReturns404ForClinicBDocument(): void
    {
        // One doctor with active memberships in BOTH clinics, but the document is
        // owned by Clinic B (visit/patient/clinician in B).
        $docUser = $this->makeUser('p3s6_doc_dual', RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($docUser, $this->clinics['A'], RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($docUser, $this->clinics['B'], RolesAndCapabilities::ROLE_DOCTOR);

        // Build B-owned visit, clinician, patient, document, page (with non-empty strokes).
        $clinicianB = $this->insertClinician($this->clinics['B'], $docUser, 'Dr Dual P3S6 B');
        $patientB = $this->insertPatient($this->clinics['B']);
        $visitB = $this->insertVisit($this->clinics['B'], $this->locations[$this->clinics['B']], $clinicianB, $patientB, 'in_consultation');

        // Create the document under Clinic B's trusted scope so rows are durable B.
        $doc = $this->withScope($this->clinics['B'], function () use ($docUser, $visitB): array {
            return App::handwritingService()->createDocument($docUser, $visitB, 'p3s6-b-note', []);
        });
        $docId = (int) $doc['id'];
        $pageId = (int) $doc['pages'][0]['id'];
        // Add non-empty stroke data via savePage under B scope.
        $strokes = base64_encode(gzencode(json_encode([[
            'id' => 's-b', 'tool' => 'pen', 'color' => '#000', 'size' => 3,
            'points' => [[0, 0, 0.5, 1000], [100, 100, 0.5, 1001]],
        ]], JSON_UNESCAPED_UNICODE) ?: '[]') ?: '[]');
        $this->withScope($this->clinics['B'], function () use ($docUser, $pageId, $strokes): void {
            App::handwritingService()->savePage($docUser, $pageId, [
                'strokes' => $strokes,
                'width' => 1240,
                'height' => 1754,
                'client_version' => 1,
            ], null);
        });

        // Now try getPage under trusted scope Clinic A — must fail with 404 parity
        // BEFORE returning any stroke payload from B.
        wp_set_current_user($docUser);
        $threw = null;
        $payload = null;
        try {
            $payload = $this->withScope($this->clinics['A'], function () use ($docUser, $pageId): array {
                return App::handwritingService()->getPage($docUser, $pageId);
            });
        } catch (HandwritingException $e) {
            $threw = $e;
        }

        // SECURITY CONTRACT: must deny (404) — today this is RED because getPage
        // doesn't enforce clinic-scoped authorization at all; note that
        // requireOwnVisit will PASS for this user (they ARE the B visit's
        // clinician wp_user), so the only gate missing is scoped authz.
        self::assertNotNull($threw,
            'RED-2 evidence: cross-Clinic getPage must fail closed before returning B strokes; payload would leak: '
            . substr((string) wp_json_encode($payload), 0, 200));
        self::assertSame(404, $threw->httpStatus, 'must be 404 parity so existence is not disclosed');
        if (is_array($payload)) {
            self::assertArrayNotHasKey('strokes', $payload, 'stroke data must not leak');
        }
    }

    // ===================== RED 3: Finance scoped deny overrides preset =====================

    /**
     * RED-3: a Clinic-A member with role cpms_manager (which preset-grants
     * PAYMENT_CREATE globally) must be DENIED payment creation when an explicit
     * DENY is set on PAYMENT_CREATE for their membership — despite global
     * user_can('cpms_payment_create') returning true.
     *
     * Today FinanceService::recordPayment calls requireCap (global user_can) +
     * trustedClinicId/row checks but never AuthorizationService::authorize, so the
     * explicit deny is ignored and a payment is durably written.
     */
    public function testRecordPaymentIsDeniedWhenScopedPaymentCreateIsExplicitlyDenied(): void
    {
        // Secretary+manager role for actor so they hold global PAYMENT_CREATE preset.
        $mgr = $this->makeUser('p3s6_fin_mgr', RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($mgr, $this->clinics['A'], RolesAndCapabilities::ROLE_MANAGER);

        // Place EXPLICIT DENY on PAYMENT_CREATE for their membership.
        $svc = App::membership_service();
        $mem = $svc->membership_for($this->clinics['A'], $mgr);
        self::assertNotNull($mem, 'precondition: manager membership');
        $svc->set_capability((int) $mem['id'], RolesAndCapabilities::PAYMENT_CREATE, 'deny');

        // Build a legitimate open invoice on Clinic A (using a separate legitimate
        // secretary that can issue the invoice for us; manager explicit deny only
        // blocks PAYMENT_CREATE, not INVOICE_CREATE in this test).
        $sec = $this->makeUser('p3s6_fin_sec', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($sec, $this->clinics['A'], RolesAndCapabilities::ROLE_SECRETARY);
        $docUser = $this->makeUser('p3s6_fin_doc', RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($docUser, $this->clinics['A'], RolesAndCapabilities::ROLE_DOCTOR);
        $clinic = $this->insertClinician($this->clinics['A'], $docUser, 'Dr Fin P3S6');
        $patient = $this->insertPatient($this->clinics['A']);
        $visitId = $this->insertVisit($this->clinics['A'], $this->locations[$this->clinics['A']], $clinic, $patient, 'consultation_completed');
        // Bring visit to awaiting_payment + invoice via the legitimate secretary under Clinic A scope.
        $invoice = $this->withScope($this->clinics['A'], function () use ($sec, $visitId): array {
            return App::financeService()->issueInvoice($sec, [
                'visit_id' => $visitId,
                'items' => [['description' => 'P3S6 ویزیت', 'unit_price' => 50000]],
            ]);
        });
        $invoiceId = (int) $invoice['id'];
        self::assertGreaterThan(0, $invoiceId, 'precondition: invoice exists in Clinic A');

        // Confirm the global cap is in fact granted (the bug is that global cap ≠ scoped auth).
        self::assertTrue(user_can($mgr, RolesAndCapabilities::PAYMENT_CREATE),
            'precondition: manager has global PAYMENT_CREATE (the override must come from scoped deny)');

        // Confirm AuthorizationService itself reports DENY for the scoped call.
        $authz = App::authorization_service();
        self::assertFalse($authz->can($mgr, $this->clinics['A'], RolesAndCapabilities::PAYMENT_CREATE),
            'precondition: AuthorizationService reports scoped deny for PAYMENT_CREATE');
        $authzThrew = false;
        try {
            $authz->authorize($mgr, $this->clinics['A'], RolesAndCapabilities::PAYMENT_CREATE);
        } catch (AuthorizationException) {
            $authzThrew = true;
        }
        self::assertTrue($authzThrew, 'precondition: authorize() throws for the denied member');

        // NOW the real product call. Even though global cap is set, service must
        // enforce scoped permission and NOT write a payment row.
        wp_set_current_user($mgr);
        $threw = null;
        $before = $this->countPaymentsForInvoice($invoiceId);
        $paidBefore = (float) App::db()->fetchValue(
            'SELECT paid_amount FROM ' . App::db()->table('cpms_invoices') . ' WHERE id = %d',
            [$invoiceId]
        );
        try {
            $this->withScope($this->clinics['A'], function () use ($mgr, $invoiceId): void {
                App::financeService()->recordPayment(
                    $mgr,
                    $invoiceId,
                    ['amount' => 50000, 'method' => 'cash'],
                    $this->uuid()
                );
            });
        } catch (FinanceException $e) {
            $threw = $e;
        }

        $after = $this->countPaymentsForInvoice($invoiceId);
        $paidAfter = (float) App::db()->fetchValue(
            'SELECT paid_amount FROM ' . App::db()->table('cpms_invoices') . ' WHERE id = %d',
            [$invoiceId]
        );

        // SECURITY CONTRACT: must deny with CLINIC_PERMISSION_DENIED and zero payment side-effects.
        self::assertNotNull($threw,
            'RED-3 evidence: recordPayment must be denied when PAYMENT_CREATE is explicitly denied in the membership');
        self::assertSame('CLINIC_PERMISSION_DENIED', $threw->errorCode, 'must surface the scoped-deny machine code');
        self::assertSame(403, $threw->httpStatus);
        self::assertSame($before, $after, 'no payment row must be inserted by a denied user');
        self::assertSame($paidBefore, $paidAfter, 'invoice paid_amount must not change on denied payment');
    }

    // ===================== RED 4: Suspended member denied handwriting + finance =====================

    /**
     * RED-4: a suspended Clinic member must be denied both handwriting savePage and
     * finance recordPayment (fail-closed) BEFORE any transaction/mutation/version
     * append.
     */
    public function testSuspendedMemberIsDeniedHandwritingSaveAndPaymentCreate(): void
    {
        // Build a legitimate Clinic A doctor + secretary.
        $sec = $this->makeUser('p3s6_susp_sec', RolesAndCapabilities::ROLE_SECRETARY);
        $doc = $this->makeUser('p3s6_susp_doc', RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($sec, $this->clinics['A'], RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($doc, $this->clinics['A'], RolesAndCapabilities::ROLE_DOCTOR);
        $clinic = $this->insertClinician($this->clinics['A'], $doc, 'Dr Susp P3S6');
        $patient = $this->insertPatient($this->clinics['A']);
        $visitId = $this->insertVisit($this->clinics['A'], $this->locations[$this->clinics['A']], $clinic, $patient, 'in_consultation');

        // Create a handwriting document under valid scope (pre-suspension).
        $docRes = $this->withScope($this->clinics['A'], function () use ($doc, $visitId): array {
            return App::handwritingService()->createDocument($doc, $visitId, 'susp note', []);
        });
        $pageId = (int) $docRes['pages'][0]['id'];

        // Build a completed consultation + open invoice for finance RED.
        $this->withScope($this->clinics['A'], function () use ($doc, $visitId): void {
            App::clinicalService()->addNote($doc, $visitId, [
                'category' => 'chief_complaint',
                'visibility' => 'patient_visible',
                'content_text' => 'susp red fixture',
            ]);
            App::clinicalService()->completeConsultation($doc, $visitId);
        });

        // Suspend BOTH memberships.
        $memSvc = App::membership_service();
        $docMem = $memSvc->membership_for($this->clinics['A'], $doc);
        $secMem = $memSvc->membership_for($this->clinics['A'], $sec);
        self::assertNotNull($docMem);
        self::assertNotNull($secMem);
        $memSvc->suspend_membership((int) $docMem['id']);
        $memSvc->suspend_membership((int) $secMem['id']);

        // 4a: suspended doctor tries to savePage.
        wp_set_current_user($doc);
        $versionsBefore = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_handwriting_page_versions') . ' WHERE page_id = %d',
            [$pageId]
        );
        $hwThrew = null;
        try {
            $this->withScope($this->clinics['A'], function () use ($doc, $pageId): void {
                App::handwritingService()->savePage($doc, $pageId, [
                    'strokes' => base64_encode(gzencode('[]') ?: '[]'),
                    'width' => 1240,
                    'height' => 1754,
                    'client_version' => 1,
                ], null);
            });
        } catch (HandwritingException $e) {
            $hwThrew = $e;
        }
        $versionsAfter = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_handwriting_page_versions') . ' WHERE page_id = %d',
            [$pageId]
        );
        self::assertNotNull($hwThrew,
            'RED-4a evidence: suspended doctor must be denied handwriting savePage before any version append');
        self::assertSame(403, $hwThrew->httpStatus, 'suspended writes must be denied with status 403');
        self::assertSame($versionsBefore, $versionsAfter, 'no handwriting version may be appended by suspended user');

        // 4b: suspended secretary tries to issue invoice + record payment.
        wp_set_current_user($sec);
        $invCountBefore = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_invoices') . ' WHERE visit_id = %d',
            [$visitId]
        );
        $payCountBefore = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_payments')
            . ' p JOIN ' . App::db()->table('cpms_invoices') . ' i ON i.id = p.invoice_id WHERE i.visit_id = %d',
            [$visitId]
        );
        $finThrew = null;
        try {
            $this->withScope($this->clinics['A'], function () use ($sec, $visitId): void {
                $inv = App::financeService()->issueInvoice($sec, [
                    'visit_id' => $visitId,
                    'items' => [['description' => 'ویزیت susp', 'unit_price' => 30000]],
                ]);
                App::financeService()->recordPayment(
                    $sec,
                    (int) $inv['id'],
                    ['amount' => 30000, 'method' => 'cash'],
                    $this->uuid()
                );
            });
        } catch (FinanceException $e) {
            $finThrew = $e;
        }
        $invCountAfter = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_invoices') . ' WHERE visit_id = %d',
            [$visitId]
        );
        $payCountAfter = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_payments')
            . ' p JOIN ' . App::db()->table('cpms_invoices') . ' i ON i.id = p.invoice_id WHERE i.visit_id = %d',
            [$visitId]
        );
        self::assertNotNull($finThrew,
            'RED-4b evidence: suspended secretary must be denied finance issue/payment before any mutation');
        self::assertSame($invCountBefore, $invCountAfter, 'suspended user must not issue invoices');
        self::assertSame($payCountBefore, $payCountAfter, 'suspended user must not record payments');
    }

    // ===================== Fixture helpers =====================

    private function createOrganization(): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(5));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at)
             VALUES (%s, %s, %s, %s, %s)', // phpcs:ignore
            'P3S6 Org ' . $unique,
            'p3s6-org-' . $unique,
            'active',
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: organization inserted (' . $wpdb->last_error . ')');

        return $id;
    }

    private function createClinic(int $orgId, string $tag): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics
                 (organization_id, name, slug, timezone, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore
            $orgId,
            'P3S6 Clinic ' . $tag . ' ' . $unique,
            'p3s6-clinic-' . $tag . '-' . $unique,
            'Asia/Tehran',
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $id, 'precondition: clinic id is dynamic');
        self::assertSame($orgId, (int) $wpdb->get_var(
            $wpdb->prepare('SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = %d', $id)
        ), 'precondition: clinic bound to explicit organization');
        App::resetScope();

        return $id;
    }

    private function createLocation(int $clinicId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations
                 (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)
             VALUES (%d, %s, %s, %s, 1, 1, %s, %s)', // phpcs:ignore
            $clinicId,
            'P3S6 Loc ' . $unique,
            'p3s6-loc-' . $unique,
            'Asia/Tehran',
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: location inserted (' . $wpdb->last_error . ')');

        return $id;
    }

    private function makeUser(string $login, string $role): int
    {
        $unique = $login . '_' . bin2hex(random_bytes(3));
        $userId = (int) wp_create_user($unique, wp_generate_password(22), $unique . '@p3s6.test');
        self::assertGreaterThan(0, $userId, 'precondition: wp user ' . $login);
        $u = get_userdata($userId);
        self::assertNotFalse($u);
        $u->set_role($role);

        return $userId;
    }

    private function insertClinician(int $clinicId, int $wpUserId, string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                 (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
             VALUES (%d, %s, %d, 1, %s, %s)', // phpcs:ignore
            $clinicId, $name, $wpUserId, $now, $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: clinician row (' . $wpdb->last_error . ')');

        return $id;
    }

    private function insertPatient(int $clinicId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $seq = random_int(1000000, 9999999);
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                 (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %s, %s, %s, %s)', // phpcs:ignore
            $clinicId,
            'MR-P3S6-' . $seq,
            'P3S6',
            'Pat' . $seq,
            '0912' . str_pad((string) ($seq % 10000000), 7, '0', STR_PAD_LEFT),
            'active',
            $now, $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: patient row (' . $wpdb->last_error . ')');

        return $id;
    }

    private function insertVisit(int $clinicId, int $locationId, int $clinicianId, int $patientId, string $status): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $date = gmdate('Y-m-d');
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits
                 (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date,
                  check_in_at, waiting_since, called_at, consultation_started_at, active, created_at, updated_at)
             VALUES (%d, %d, %d, %d, %s, %s, %s, %s, %s, %s, %s, 1, %s, %s)', // phpcs:ignore
            $clinicId, $locationId, $clinicianId, $patientId,
            'walk_in', $status, $date,
            $date . ' 10:00:00.000', $date . ' 10:00:00.000', $date . ' 10:05:00.000', $date . ' 10:10:00.000',
            $now, $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: visit row (' . $wpdb->last_error . ')');

        return $id;
    }

    private function countPaymentsForInvoice(int $invoiceId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_payments') . ' WHERE invoice_id = %d',
            [$invoiceId]
        );
    }

    /**
     * @return mixed
     */
    private function withScope(int $clinicId, callable $fn)
    {
        $prev = ScopeContext::tryGet();
        App::replaceExplicitScope(ClinicScope::forClinic($clinicId));
        try {
            return $fn();
        } finally {
            App::replaceExplicitScope($prev);
        }
    }

    private function uuid(): string
    {
        $d = static fn (int $l): string => bin2hex(random_bytes((int) ceil($l / 2)));

        return sprintf('%s-%s-4%s-%s-%s', $d(8), $d(4), substr($d(3), 0, 3), substr($d(4), 0, 4), $d(12));
    }

    private function purgeRows(): void
    {
        global $wpdb;
        foreach ([
            'cpms_handwriting_page_versions',
            'cpms_handwriting_pages',
            'cpms_handwriting_documents',
            'cpms_payments',
            'cpms_invoice_items',
            'cpms_invoice_adjustments',
            'cpms_invoices',
            'cpms_visits',
            'cpms_clinician_schedule_exceptions',
            'cpms_clinician_schedules',
            'cpms_schedule_exceptions',
            'cpms_clinicians',
            'cpms_patients',
            'cpms_patient_user_links',
            'cpms_membership_caps',
            'cpms_clinic_memberships',
            'cpms_locations',
        ] as $table) {
            $wpdb->query('DELETE t FROM ' . $wpdb->prefix . $table . ' t
                          JOIN ' . $wpdb->prefix . 'cpms_clinics c ON c.id = t.clinic_id
                          WHERE c.organization_id = ' . (int) $this->orgId); // phpcs:ignore
        }
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . (int) $this->orgId); // phpcs:ignore
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_organizations WHERE id = ' . (int) $this->orgId); // phpcs:ignore
    }
}
