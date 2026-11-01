<?php

/**
 * Product Decision — Financial authorization policy (2026-10-15), real-MySQL
 * regression suite (truthful RED first; product bytes in a later commit).
 *
 * Approved policy under test
 * (docs/decisions/2026-10-15-financial-authorization-policy.md):
 *   1. SECRETARY  — ordinary payment registration permitted; payment void/refund denied by default.
 *   2. DOCTOR     — sensitive payment operations require effective permission AND verified ownership
 *                   of the actual service/transaction; a patient relationship alone is insufficient.
 *   3. ACCOUNTANT — established legitimate authority preserved.
 *   4. MANAGER    — may explicitly delegate bounded sensitive financial capabilities to authorized
 *                   members; delegation is revocable, audited, Clinic-scoped and subject to the
 *                   existing GRANT/DENY precedence; no default medical-record access is created.
 *   5. Existing explicit grants are evaluated before changed defaults (existing installations keep
 *      deliberate grants); existing financial records are never rewritten.
 *
 * Everything here is a real contract test: real WordPress REST dispatch, real MySQL/MariaDB
 * persistence, no mocks, no fabricated evidence. The suite intentionally fails on the product bytes
 * that still implement the pre-decision defaults (that failure is the RED evidence).
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Finance\FinanceDelegationService;
use ClinicCore\Application\Finance\FinanceException;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\CustomRolePolicy;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class FinanceAuthorizationPolicyRegressionTest extends WP_UnitTestCase
{
    private const PAY = '/clinic/v1/staff/portal/finance/invoices/%d/payments';
    private const PAY_VOID = '/clinic/v1/payments/%d/void';
    private const PAY_REFUND = '/clinic/v1/payments/%d/refund';
    private const INVOICE_ADJUST = '/clinic/v1/invoices/%d/adjustments';

    /** @var callable|null active wpdb 'query' fault injector */
    private $auditFault = null;

    private bool $previousSuppression = false;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        App::migrations()->migrate();
    }

    protected function tearDown(): void
    {
        $this->restoreAuditInserts();
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        parent::tearDown();
    }

    // ================= 1 — Secretary: void/refund denied by default =================

    public function testSecretaryDefaultVoidAndRefundAreDenied(): void
    {
        [$clinic, $location, , $invoice, ] = $this->financeFixture();
        $secretary = $this->staff($clinic, RolesAndCapabilities::ROLE_SECRETARY, 'sec_default');

        // Premise: the same actor CAN record an ordinary payment (group 2) — the denial below is
        // about the sensitive operation, not about a broken fixture.
        wp_set_current_user($secretary);
        $capture = $this->pay($invoice, $clinic, $location, ['amount' => 30000, 'method' => 'cash']);
        self::assertSame(201, $capture->get_status(), 'premise: ordinary capture works — ' . $this->errorCode($capture));
        $paymentId = (int) ($this->payload($capture)['payment']['id'] ?? 0);
        self::assertGreaterThan(0, $paymentId);

        $before = $this->paymentRow($paymentId);
        $invoiceBefore = $this->invoiceRow($invoice);

        $void = $this->dispatch('POST', sprintf(self::PAY_VOID, $paymentId), [], $this->scopeHeaders($clinic), ['reason' => 'policy probe']);
        self::assertSame(403, $void->get_status(), 'secretary payment void must be denied by default — ' . $this->errorCode($void));

        $refund = $this->dispatch('POST', sprintf(self::PAY_REFUND, $paymentId), [], $this->scopeHeaders($clinic), ['reason' => 'policy probe']);
        self::assertSame(403, $refund->get_status(), 'secretary payment refund must be denied by default — ' . $this->errorCode($refund));

        self::assertSame($before, $this->paymentRow($paymentId), 'a denied void/refund must not mutate the payment');
        self::assertSame($invoiceBefore, $this->invoiceRow($invoice), 'a denied void/refund must not mutate the invoice');
        self::assertSame(0, $this->auditCount('PAYMENT_VOID'), 'no PAYMENT_VOID audit event may exist for a denial');
        self::assertSame(0, $this->auditCount('PAYMENT_REFUND'), 'no PAYMENT_REFUND audit event may exist for a denial');

        // The effective capability is denied at the engine too — not only at the HTTP boundary.
        self::assertFalse(
            App::authorization_service()->can($secretary, $clinic, RolesAndCapabilities::PAYMENT_VOID),
            'the AuthorizationService must deny secretary payment void by default'
        );
        self::assertFalse(
            App::authorization_service()->can($secretary, $clinic, RolesAndCapabilities::PAYMENT_REFUND),
            'the AuthorizationService must deny secretary payment refund by default'
        );
        self::assertNotContains(
            RolesAndCapabilities::PAYMENT_VOID,
            RolesAndCapabilities::SECRETARY_CAPS,
            'cpms_payment_void must not be part of the built-in secretary preset'
        );
        self::assertNotContains(
            RolesAndCapabilities::PAYMENT_REFUND,
            RolesAndCapabilities::SECRETARY_CAPS,
            'cpms_payment_refund must not be part of the built-in secretary preset'
        );
    }

    // ================= 2 — Secretary: ordinary registration remains permitted =================

    public function testSecretaryOrdinaryPaymentRegistrationRemainsPermitted(): void
    {
        [$clinic, $location, , $invoice, $visit] = $this->financeFixture();
        $secretary = $this->staff($clinic, RolesAndCapabilities::ROLE_SECRETARY, 'sec_capture');

        wp_set_current_user($secretary);
        $response = $this->pay($invoice, $clinic, $location, ['amount' => 25000, 'method' => 'card_pos']);

        self::assertSame(201, $response->get_status(), 'ordinary payment registration stays permitted — ' . $this->errorCode($response));
        self::assertSame('captured', (string) ($this->payload($response)['payment']['status'] ?? ''));
        self::assertSame(25000.0, (float) ($this->payload($response)['payment']['amount'] ?? -1));
        self::assertSame(1, $this->paymentCount($invoice));
        self::assertSame(1, $this->auditCount('PAYMENT_CAPTURE'));
        self::assertSame('awaiting_payment', (string) App::db()->fetchValue(
            'SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d',
            [$visit]
        ));
        self::assertContains(
            RolesAndCapabilities::PAYMENT_CREATE,
            RolesAndCapabilities::SECRETARY_CAPS,
            'cpms_payment_create must stay in the secretary preset'
        );
    }

    // ================= 3 — Doctor: owned transaction =================

    public function testDoctorMayVoidAndRefundOwnedTransaction(): void
    {
        [$clinic, $location, , $invoice, $visit] = $this->financeFixture();
        $doctor = $this->doctor($clinic, $visit, 'dr_owner');

        // A secretary records the money (ordinary capture is a secretary/accountant operation);
        // the OWNER of the visit's service must then be able to correct it.
        $secretary = $this->staff($clinic, RolesAndCapabilities::ROLE_SECRETARY, 'sec_for_dr');
        wp_set_current_user($secretary);
        $capture = $this->pay($invoice, $clinic, $location, ['amount' => 40000, 'method' => 'cash']);
        self::assertSame(201, $capture->get_status(), 'premise capture — ' . $this->errorCode($capture));
        $paymentId = (int) ($this->payload($capture)['payment']['id'] ?? 0);
        self::assertGreaterThan(0, $paymentId, 'premise: the capture returned a real payment id');

        wp_set_current_user($doctor);
        $void = $this->dispatch('POST', sprintf(self::PAY_VOID, $paymentId), [], $this->scopeHeaders($clinic), ['reason' => 'owned correction']);
        self::assertSame(200, $void->get_status(), 'the owning doctor must be able to void their own transaction — ' . $this->errorCode($void));
        self::assertSame('voided', (string) $this->paymentRow($paymentId)['status']);
        self::assertSame(1, $this->auditCount('PAYMENT_VOID'));

        // Second payment → refund by the same owner. Ordinary capture is again a
        // secretary operation (the doctor preset has no PAYMENT_CREATE by design).
        wp_set_current_user($secretary);
        $capture2 = $this->pay($invoice, $clinic, $location, ['amount' => 15000, 'method' => 'cash']);
        self::assertSame(201, $capture2->get_status(), 'premise capture 2 — ' . $this->errorCode($capture2));
        $payment2 = (int) ($this->payload($capture2)['payment']['id'] ?? 0);
        self::assertGreaterThan(0, $payment2, 'premise: the second capture returned a real payment id');

        // The refund must be attempted by the OWNER: the capture above was performed as the
        // secretary (ordinary capture is a secretary operation), so the actor has to switch back.
        wp_set_current_user($doctor);
        $refund = $this->dispatch('POST', sprintf(self::PAY_REFUND, $payment2), [], $this->scopeHeaders($clinic), ['reason' => 'owned refund']);
        self::assertSame(
            200,
            $refund->get_status(),
            'the owning doctor must be able to refund their own transaction — '
            . $this->errorCode($refund) . ' reason=' . (string) ($this->payload($refund)['reason'] ?? '')
        );
        self::assertSame(15000.0, (float) $this->paymentRow($payment2)['refunded_amount']);
        self::assertSame(1, $this->auditCount('PAYMENT_REFUND'));
    }

    // ================= 4 — Doctor: unrelated transaction denied =================

    public function testDoctorCannotVoidOrRefundUnrelatedTransaction(): void
    {
        [$clinic, $location, , $invoiceA, $visitA] = $this->financeFixture();

        // A SECOND service in the SAME Clinic: same Clinic, same patient relationship layer, but a
        // different responsible clinician. That is the "unrelated transaction" case.
        $otherPatient = $this->insertPatient($clinic, 'FinPolicyOther');
        $otherClinician = $this->insertClinician($clinic, 'Dr Fixture Other', 0);
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        $visitB = $this->insertVisit($clinic, $location, $otherPatient, $otherClinician, $date, App::db()->nowUtcSql(), 'awaiting_payment');
        $invoiceB = $this->insertInvoice($clinic, $location, $otherPatient, $visitB, $otherClinician, 'open');

        $owner = $this->doctor($clinic, $visitA, 'dr_real_owner');
        $other = $this->doctor($clinic, $visitB, 'dr_unrelated');

        $secretary = $this->staff($clinic, RolesAndCapabilities::ROLE_SECRETARY, 'sec_unrelated');
        wp_set_current_user($secretary);
        $capture = $this->pay($invoiceA, $clinic, $location, ['amount' => 20000, 'method' => 'cash']);
        self::assertSame(201, $capture->get_status(), 'premise capture — ' . $this->errorCode($capture));
        $paymentId = (int) ($this->payload($capture)['payment']['id'] ?? 0);

        // The unrelated doctor holds the SAME capability through the same preset, is a member of the
        // same Clinic, and patients of that Clinic are visible to them — none of that is ownership of
        // this transaction.
        self::assertTrue(
            App::authorization_service()->can($other, $clinic, RolesAndCapabilities::PAYMENT_VOID),
            'premise: the unrelated doctor holds the capability itself'
        );
        self::assertTrue(
            App::authorization_service()->can($other, $clinic, RolesAndCapabilities::PATIENT_READ),
            'premise: the unrelated doctor has a legitimate patient relationship in the same Clinic'
        );
        self::assertGreaterThan(0, (int) $invoiceB, 'premise: a second transaction exists in the same Clinic');

        wp_set_current_user($other);
        $void = $this->dispatch('POST', sprintf(self::PAY_VOID, $paymentId), [], $this->scopeHeaders($clinic), ['reason' => 'unrelated attempt']);
        self::assertSame(403, $void->get_status(), 'an unrelated doctor must not void the transaction — ' . $this->errorCode($void));

        $refund = $this->dispatch('POST', sprintf(self::PAY_REFUND, $paymentId), [], $this->scopeHeaders($clinic), ['reason' => 'unrelated attempt']);
        self::assertSame(403, $refund->get_status(), 'an unrelated doctor must not refund the transaction — ' . $this->errorCode($refund));

        self::assertSame('captured', (string) $this->paymentRow($paymentId)['status'], 'nothing may be written for an unrelated doctor');
        self::assertSame(0, $this->auditCount('PAYMENT_VOID'));
        self::assertSame(0, $this->auditCount('PAYMENT_REFUND'));

        // An ownership fact supplied by the caller is never evidence. The target is a REAL, existing
        // payment of the same Clinic (never a fabricated id): the denial must come from the ownership
        // conjunct, not from an absent row.
        $claimed = $this->dispatch(
            'POST',
            sprintf(self::PAY_VOID, $paymentId),
            ['clinician_id' => $other, 'authorized_by_ownership' => 1],
            $this->scopeHeaders($clinic),
            ['reason' => 'payload ownership claim', 'clinician_id' => $other]
        );
        self::assertSame(403, $claimed->get_status(), 'payload ownership claims must never be trusted — ' . $this->errorCode($claimed));
        self::assertSame('AUTH_OWNERSHIP_REQUIRED', (string) ($this->payload($claimed)['reason'] ?? ''), 'the denial must be the ownership rule, not the menu gate');
        self::assertSame('captured', (string) $this->paymentRow($paymentId)['status'], 'nothing may be written for a payload ownership claim');

        // The genuine owner keeps authority: the ownership rule is not a blanket doctor denial.
        wp_set_current_user($owner);
        $owned = $this->dispatch('POST', sprintf(self::PAY_VOID, $paymentId), [], $this->scopeHeaders($clinic), ['reason' => 'owner correction']);
        self::assertSame(200, $owned->get_status(), 'the real owner must still be able to correct the transaction — ' . $this->errorCode($owned));
        self::assertSame('voided', (string) $this->paymentRow($paymentId)['status'], 'the owner correction must take effect');
        self::assertSame(1, $this->auditCount('PAYMENT_VOID'), 'exactly one void audit row');
    }

    // ================= 5 — Cross-Clinic denial =================

    public function testCrossClinicPaymentOperationIsDenied(): void
    {
        [$clinicA, $locationA, , $invoiceA, $visitA] = $this->financeFixture();
        [$clinicB, $locationB, , $invoiceB, $visitB] = $this->financeFixture();
        self::assertNotSame($clinicA, $clinicB);

        $doctorA = $this->doctor($clinicA, $visitA, 'dr_clinic_a');
        $secretaryA = $this->staff($clinicA, RolesAndCapabilities::ROLE_SECRETARY, 'sec_clinic_a');

        wp_set_current_user($secretaryA);
        $capture = $this->pay($invoiceA, $clinicA, $locationA, ['amount' => 12000, 'method' => 'cash']);
        self::assertSame(201, $capture->get_status(), 'premise capture — ' . $this->errorCode($capture));
        $paymentA = (int) ($this->payload($capture)['payment']['id'] ?? 0);
        $before = $this->paymentRow($paymentA);

        // (a) Same user, foreign Clinic header.
        wp_set_current_user($secretaryA);
        $foreign = $this->dispatch('POST', sprintf(self::PAY_VOID, $paymentA), [], $this->scopeHeaders($clinicB), ['reason' => 'cross clinic']);
        self::assertContains($foreign->get_status(), [400, 403, 404], 'a foreign Clinic scope must never void this payment — ' . $this->errorCode($foreign));

        // (b) A doctor of another Clinic holding the capability in their own Clinic.
        $doctorB = $this->doctor($clinicB, $visitB, 'dr_clinic_b');
        wp_set_current_user($doctorB);
        $foreignDoctor = $this->dispatch('POST', sprintf(self::PAY_REFUND, $paymentA), [], $this->scopeHeaders($clinicB), ['reason' => 'cross clinic refund']);
        self::assertContains($foreignDoctor->get_status(), [400, 403, 404], 'a foreign-Clinic doctor must never refund this payment — ' . $this->errorCode($foreignDoctor));

        self::assertSame($before, $this->paymentRow($paymentA), 'no cross-Clinic write may reach the payment');
        self::assertSame(0, $this->auditCount('PAYMENT_VOID'));
        self::assertSame(0, $this->auditCount('PAYMENT_REFUND'));

        // The owner in the payment's own Clinic is unaffected.
        wp_set_current_user($doctorA);
        self::assertSame(
            200,
            $this->dispatch('POST', sprintf(self::PAY_VOID, $paymentA), [], $this->scopeHeaders($clinicA), ['reason' => 'in clinic'])->get_status()
        );

        // Foreign object isolation stays intact for ordinary capture too.
        wp_set_current_user($secretaryA);
        $foreignCapture = $this->pay($invoiceB, $clinicB, $locationB, ['amount' => 1000, 'method' => 'cash']);
        self::assertContains($foreignCapture->get_status(), [400, 403, 404]);
        self::assertSame(0, $this->paymentCount($invoiceB));
    }

    // ================= 6 — Accountant: established authority preserved =================

    public function testAccountantRetainsVoidAndRefundAuthority(): void
    {
        [$clinic, $location, , $invoice, ] = $this->financeFixture();
        $accountant = $this->staff($clinic, RolesAndCapabilities::ROLE_ACCOUNTANT, 'acct_keep');

        wp_set_current_user($accountant);
        $capture = $this->pay($invoice, $clinic, $location, ['amount' => 30000, 'method' => 'cash']);
        self::assertSame(201, $capture->get_status(), 'accountant ordinary capture — ' . $this->errorCode($capture));
        $payment1 = (int) ($this->payload($capture)['payment']['id'] ?? 0);

        $void = $this->dispatch('POST', sprintf(self::PAY_VOID, $payment1), [], $this->scopeHeaders($clinic), ['reason' => 'accountant void']);
        self::assertSame(200, $void->get_status(), 'the accountant void authority must be preserved — ' . $this->errorCode($void));

        $capture2 = $this->pay($invoice, $clinic, $location, ['amount' => 20000, 'method' => 'cash']);
        self::assertSame(201, $capture2->get_status(), 'accountant capture 2 — ' . $this->errorCode($capture2));
        $payment2 = (int) ($this->payload($capture2)['payment']['id'] ?? 0);

        $refund = $this->dispatch('POST', sprintf(self::PAY_REFUND, $payment2), [], $this->scopeHeaders($clinic), ['reason' => 'accountant refund']);
        self::assertSame(200, $refund->get_status(), 'the accountant refund authority must be preserved — ' . $this->errorCode($refund));

        // Established authority is preserved unchanged — the accountant needs no ownership and no
        // delegation, and the preset still carries both capabilities.
        self::assertContains(RolesAndCapabilities::PAYMENT_VOID, RolesAndCapabilities::ACCOUNTANT_CAPS);
        self::assertContains(RolesAndCapabilities::PAYMENT_REFUND, RolesAndCapabilities::ACCOUNTANT_CAPS);
        self::assertTrue(App::authorization_service()->can($accountant, $clinic, RolesAndCapabilities::PAYMENT_VOID));
        self::assertTrue(App::authorization_service()->can($accountant, $clinic, RolesAndCapabilities::PAYMENT_REFUND));
    }

    // ================= 7 — Explicit manager delegation =================

    public function testClinicManagerCanExplicitlyDelegateABoundedSensitiveCapability(): void
    {
        [$clinic, $location, , $invoice, ] = $this->financeFixture();
        $manager = $this->staff($clinic, RolesAndCapabilities::ROLE_MANAGER, 'mgr_delegate');
        $secretary = $this->staff($clinic, RolesAndCapabilities::ROLE_SECRETARY, 'sec_delegate');
        $membershipId = $this->membershipId($clinic, $secretary);

        // Premise: managers hold no default sensitive finance authority.
        self::assertFalse(App::authorization_service()->can($manager, $clinic, RolesAndCapabilities::PAYMENT_VOID));

        $result = $this->delegation()->delegate($manager, $clinic, $membershipId, RolesAndCapabilities::PAYMENT_VOID);
        self::assertSame('grant', (string) ($result['effect'] ?? ''), 'the delegation must be recorded as an explicit grant');
        self::assertSame(RolesAndCapabilities::PAYMENT_VOID, (string) ($result['capability'] ?? ''));
        self::assertSame($clinic, (int) ($result['clinic_id'] ?? 0), 'the delegation must be Clinic-scoped');
        self::assertSame($membershipId, (int) ($result['membership_id'] ?? 0));

        // Persisted in the EXISTING override storage — no parallel engine, no new table.
        $persisted = (new MembershipRepository(App::db()))->capabilities_for($membershipId);
        $effects = [];
        foreach ($persisted as $row) {
            $effects[(string) $row['capability']] = (string) $row['effect'];
        }
        self::assertSame('grant', $effects[RolesAndCapabilities::PAYMENT_VOID] ?? null, 'the delegation must live in cpms_membership_capabilities');

        // Audited.
        self::assertSame(1, $this->auditCount(FinanceDelegationService::AUDIT_DELEGATED), 'the delegation must be audited');

        // Effective immediately for the delegated member.
        self::assertTrue(App::authorization_service()->can($secretary, $clinic, RolesAndCapabilities::PAYMENT_VOID));

        $secretaryForCapture = $secretary;
        wp_set_current_user($secretaryForCapture);
        $capture = $this->pay($invoice, $clinic, $location, ['amount' => 18000, 'method' => 'cash']);
        self::assertSame(201, $capture->get_status(), 'premise capture — ' . $this->errorCode($capture));
        $paymentId = (int) ($this->payload($capture)['payment']['id'] ?? 0);

        // Premise proving WHICH layer authorizes this request: the delegated member holds no
        // WordPress-GLOBAL void capability (the secretary preset no longer carries it), so the legacy
        // menu gate could never authorize them. A 200 below is therefore evidence that the
        // Clinic-scoped boundary plus the delegation itself authorized the operation.
        self::assertNotContains(RolesAndCapabilities::PAYMENT_VOID, RolesAndCapabilities::SECRETARY_CAPS, 'premise: the secretary preset carries no void');
        $secretary_user = get_userdata($secretary);
        self::assertNotFalse($secretary_user, 'premise: the delegated member is a real user');
        self::assertFalse($secretary_user->has_cap(RolesAndCapabilities::PAYMENT_VOID), 'premise: the WP-global menu gate cannot authorize the delegated member');

        $void = $this->dispatch('POST', sprintf(self::PAY_VOID, $paymentId), [], $this->scopeHeaders($clinic), ['reason' => 'delegated void']);
        self::assertSame(200, $void->get_status(), 'a delegated member must be able to void — ' . $this->errorCode($void));
        self::assertSame('voided', (string) $this->paymentRow($paymentId)['status'], 'the delegated void must take effect');
        self::assertSame(1, $this->auditCount('PAYMENT_VOID'), 'the delegated void must be audited');

        // No side effects: no medical-record access, no other capability, no WordPress-global change.
        self::assertFalse(CustomRolePolicy::is_editable_capability(RolesAndCapabilities::PAYMENT_VOID), 'sensitive capabilities stay non-delegable through custom roles');
        self::assertFalse(App::authorization_service()->can($secretary, $clinic, 'cpms_medical_read'), 'no default medical-record access may be created');
        self::assertFalse(App::authorization_service()->can($secretary, $clinic, RolesAndCapabilities::PAYMENT_REFUND), 'an unrelated sensitive capability must stay denied');
        self::assertNotContains(RolesAndCapabilities::PAYMENT_VOID, RolesAndCapabilities::SECRETARY_CAPS, 'delegation must not mutate the preset');

        // The other delegable finance capability on a REST route: invoice adjustment.
        // The baseline actor is the MANAGER, whose preset deliberately holds no invoice_adjust
        // (the secretary preset does, by design), so a 200 below cannot come from a preset.
        $manager_user = get_userdata($manager);
        self::assertNotFalse($manager_user, 'premise: the manager is a real user');
        self::assertFalse($manager_user->has_cap(RolesAndCapabilities::INVOICE_ADJUST), 'premise: the WP-global gate cannot authorize the manager');
        $managerMembership = $this->membershipId($clinic, $manager);

        wp_set_current_user($manager);
        $adjust_denied = $this->dispatch('POST', sprintf(self::INVOICE_ADJUST, $invoice), [], $this->scopeHeaders($clinic), ['type' => 'credit', 'amount' => 100, 'reason' => 'undelegated baseline']);
        self::assertSame(403, $adjust_denied->get_status(), 'a manager without a delegation must not be able to adjust an invoice — ' . $this->errorCode($adjust_denied));

        $adjust_delegation = $this->delegation()->delegate($manager, $clinic, $managerMembership, RolesAndCapabilities::INVOICE_ADJUST);
        self::assertSame('grant', (string) ($adjust_delegation['effect'] ?? ''), 'invoice_adjust must be delegable');
        self::assertTrue(App::authorization_service()->can($manager, $clinic, RolesAndCapabilities::INVOICE_ADJUST), 'the delegation must be effective in the scoped engine');

        $adjust = $this->dispatch('POST', sprintf(self::INVOICE_ADJUST, $invoice), [], $this->scopeHeaders($clinic), ['type' => 'credit', 'amount' => 100, 'reason' => 'delegated adjustment']);
        self::assertSame(200, $adjust->get_status(), 'a delegated invoice_adjust must reach the service — ' . $this->errorCode($adjust));
    }

    // ================= 8 — Delegation revocation =================

    public function testDelegationIsRevocable(): void
    {
        [$clinic, $location, , $invoice, ] = $this->financeFixture();
        $manager = $this->staff($clinic, RolesAndCapabilities::ROLE_MANAGER, 'mgr_revoke');
        $secretary = $this->staff($clinic, RolesAndCapabilities::ROLE_SECRETARY, 'sec_revoke');
        $membershipId = $this->membershipId($clinic, $secretary);

        $this->delegation()->delegate($manager, $clinic, $membershipId, RolesAndCapabilities::PAYMENT_REFUND);
        self::assertTrue(App::authorization_service()->can($secretary, $clinic, RolesAndCapabilities::PAYMENT_REFUND));

        $revoked = $this->delegation()->revoke($manager, $clinic, $membershipId, RolesAndCapabilities::PAYMENT_REFUND);
        self::assertSame(RolesAndCapabilities::PAYMENT_REFUND, (string) ($revoked['capability'] ?? ''));
        self::assertSame(1, $this->auditCount(FinanceDelegationService::AUDIT_REVOKED), 'the revocation must be audited');

        $remaining = [];
        foreach ((new MembershipRepository(App::db()))->capabilities_for($membershipId) as $row) {
            $remaining[(string) $row['capability']] = (string) $row['effect'];
        }
        self::assertArrayNotHasKey(RolesAndCapabilities::PAYMENT_REFUND, $remaining, 'the delegation row must be gone');

        self::assertFalse(App::authorization_service()->can($secretary, $clinic, RolesAndCapabilities::PAYMENT_REFUND), 'authority returns to default deny');

        wp_set_current_user($secretary);
        $capture = $this->pay($invoice, $clinic, $location, ['amount' => 5000, 'method' => 'cash']);
        self::assertSame(201, $capture->get_status(), 'ordinary capture is unaffected by revocation — ' . $this->errorCode($capture));
        $paymentId = (int) ($this->payload($capture)['payment']['id'] ?? 0);

        $refund = $this->dispatch('POST', sprintf(self::PAY_REFUND, $paymentId), [], $this->scopeHeaders($clinic), ['reason' => 'after revoke']);
        self::assertSame(403, $refund->get_status(), 'after revocation the sensitive operation is denied again — ' . $this->errorCode($refund));
        self::assertSame('captured', (string) $this->paymentRow($paymentId)['status']);

        // Re-delegation is possible (revocation is not terminal).
        $this->delegation()->delegate($manager, $clinic, $membershipId, RolesAndCapabilities::PAYMENT_REFUND);
        self::assertTrue(App::authorization_service()->can($secretary, $clinic, RolesAndCapabilities::PAYMENT_REFUND));
    }

    // ================= 9 — DENY precedence =================

    public function testExplicitMembershipDenyOutranksDelegation(): void
    {
        [$clinic, $location, , $invoice, ] = $this->financeFixture();
        $manager = $this->staff($clinic, RolesAndCapabilities::ROLE_MANAGER, 'mgr_deny');
        $secretary = $this->staff($clinic, RolesAndCapabilities::ROLE_SECRETARY, 'sec_deny');
        $membershipId = $this->membershipId($clinic, $secretary);

        (new MembershipRepository(App::db()))->set_capability($membershipId, RolesAndCapabilities::PAYMENT_VOID, 'deny');

        $refused = null;
        try {
            $this->delegation()->delegate($manager, $clinic, $membershipId, RolesAndCapabilities::PAYMENT_VOID);
        } catch (FinanceException $e) {
            $refused = $e;
        }
        self::assertNotNull($refused, 'a delegation must not be created where an explicit DENY is in force');
        self::assertSame(409, $refused->httpStatus, 'the refusal must be a typed conflict, not a silent success');

        $effects = [];
        foreach ((new MembershipRepository(App::db()))->capabilities_for($membershipId) as $row) {
            $effects[(string) $row['capability']] = (string) $row['effect'];
        }
        self::assertSame('deny', $effects[RolesAndCapabilities::PAYMENT_VOID] ?? null, 'the explicit DENY must remain the only row');
        self::assertFalse(App::authorization_service()->can($secretary, $clinic, RolesAndCapabilities::PAYMENT_VOID));
        self::assertSame(0, $this->auditCount(FinanceDelegationService::AUDIT_DELEGATED), 'a refused delegation is not audited as a change');

        wp_set_current_user($secretary);
        $capture = $this->pay($invoice, $clinic, $location, ['amount' => 7000, 'method' => 'cash']);
        self::assertSame(201, $capture->get_status(), 'premise capture — ' . $this->errorCode($capture));
        $paymentId = (int) ($this->payload($capture)['payment']['id'] ?? 0);
        self::assertSame(
            403,
            $this->dispatch('POST', sprintf(self::PAY_VOID, $paymentId), [], $this->scopeHeaders($clinic), ['reason' => 'deny probe'])->get_status()
        );

        // And a manager who holds the capability only through a delegation still cannot exceed the
        // precedence: DENY on the actor beats a GRANT.
        $managerMembership = $this->membershipId($clinic, $manager);
        (new MembershipRepository(App::db()))->set_capability($managerMembership, RolesAndCapabilities::PAYMENT_VOID, 'grant');
        (new MembershipRepository(App::db()))->set_capability($managerMembership, RolesAndCapabilities::PAYMENT_VOID, 'deny');
        self::assertFalse(App::authorization_service()->can($manager, $clinic, RolesAndCapabilities::PAYMENT_VOID), 'DENY must win over GRANT for the same membership and capability');
    }

    // ================= 10 — Unauthorized delegation is denied =================

    public function testUnauthorizedDelegationIsDenied(): void
    {
        [$clinic, $location, , $invoice, ] = $this->financeFixture();
        [$clinicB, , , , ] = $this->financeFixture();
        self::assertNotSame($clinic, $clinicB);

        $secretary = $this->staff($clinic, RolesAndCapabilities::ROLE_SECRETARY, 'sec_unauth');
        $doctor = $this->staff($clinic, RolesAndCapabilities::ROLE_DOCTOR, 'dr_unauth');
        $manager = $this->staff($clinic, RolesAndCapabilities::ROLE_MANAGER, 'mgr_ok');
        $secretaryB = $this->staff($clinicB, RolesAndCapabilities::ROLE_SECRETARY, 'sec_other_clinic');
        $foreignMembership = $this->membershipId($clinicB, $secretaryB);
        $targetMembership = $this->membershipId($clinic, $secretary);

        foreach ([$secretary => 'secretary without cpms_config', $doctor => 'doctor without cpms_config'] as $actor => $label) {
            $denied = null;
            try {
                $this->delegation()->delegate((int) $actor, $clinic, $targetMembership, RolesAndCapabilities::PAYMENT_VOID);
            } catch (FinanceException $e) {
                $denied = $e;
            }
            self::assertNotNull($denied, $label . ' must not be able to delegate');
            self::assertSame(403, $denied->httpStatus, $label . ' must be denied with 403');
        }

        // A doctor must not obtain an unrelated doctor's transaction either way.
        $deniedSelf = null;
        try {
            $this->delegation()->delegate($doctor, $clinic, $this->membershipId($clinic, $doctor), RolesAndCapabilities::PAYMENT_VOID);
        } catch (FinanceException $e) {
            $deniedSelf = $e;
        }
        self::assertNotNull($deniedSelf, 'a doctor must not self-delegate sensitive finance without management authority');

        // Cross-Clinic target: a manager of Clinic A cannot address a Clinic B membership.
        $crossClinic = null;
        try {
            $this->delegation()->delegate($manager, $clinic, $foreignMembership, RolesAndCapabilities::PAYMENT_VOID);
        } catch (FinanceException $e) {
            $crossClinic = $e;
        }
        self::assertNotNull($crossClinic, 'a foreign-Clinic membership must never be addressable');
        self::assertContains($crossClinic->httpStatus, [403, 404]);
        self::assertFalse(App::authorization_service()->can($secretaryB, $clinicB, RolesAndCapabilities::PAYMENT_VOID), 'the foreign membership must gain nothing');

        // Bounded set: a manager cannot delegate clinical/medical-record or unrelated capabilities.
        foreach (['cpms_medical_read', 'cpms_export', 'cpms_audit_read', 'cpms_config', 'cpms_patient_read', 'totally_unknown_cap'] as $outOfScope) {
            $rejected = null;
            try {
                $this->delegation()->delegate($manager, $clinic, $targetMembership, $outOfScope);
            } catch (FinanceException $e) {
                $rejected = $e;
            }
            self::assertNotNull($rejected, $outOfScope . ' must not be delegable');
        }
        self::assertSame(
            ['cpms_invoice_adjust', 'cpms_invoice_void', 'cpms_payment_refund', 'cpms_payment_void'],
            FinanceDelegationService::delegable_capabilities(),
            'the delegable set must be exactly the bounded sensitive financial set'
        );

        $effects = [];
        foreach ((new MembershipRepository(App::db()))->capabilities_for($targetMembership) as $row) {
            $effects[(string) $row['capability']] = (string) $row['effect'];
        }
        self::assertSame([], $effects, 'no refused delegation may persist anything');
        self::assertSame(0, $this->auditCount(FinanceDelegationService::AUDIT_DELEGATED));
        self::assertSame(0, $this->auditCount('PERMISSION_GRANTED'));

        // A suspended member cannot receive a delegation.
        $suspended = $this->staff($clinic, RolesAndCapabilities::ROLE_SECRETARY, 'sec_suspended');
        $suspendedMembership = $this->membershipId($clinic, $suspended);
        App::membership_service()->suspend_membership($suspendedMembership);
        $suspendedRefusal = null;
        try {
            $this->delegation()->delegate($manager, $clinic, $suspendedMembership, RolesAndCapabilities::PAYMENT_VOID);
        } catch (FinanceException $e) {
            $suspendedRefusal = $e;
        }
        self::assertNotNull($suspendedRefusal, 'a suspended membership must not be a delegation target');

        // Nothing above touched the Clinic's money.
        self::assertSame(0, $this->paymentCount($invoice));
    }

    // ================= 11 — Audit rollback =================

    public function testDelegationAuditFailureRollsBackTheChange(): void
    {
        [$clinic, , , , ] = $this->financeFixture();
        $manager = $this->staff($clinic, RolesAndCapabilities::ROLE_MANAGER, 'mgr_audit');
        $secretary = $this->staff($clinic, RolesAndCapabilities::ROLE_SECRETARY, 'sec_audit');
        $membershipId = $this->membershipId($clinic, $secretary);

        // Healthy-path premise: the same delegation works while the audit path is intact.
        $ok = $this->delegation()->delegate($manager, $clinic, $membershipId, RolesAndCapabilities::PAYMENT_VOID);
        self::assertSame(RolesAndCapabilities::PAYMENT_VOID, (string) $ok['capability']);
        $this->delegation()->revoke($manager, $clinic, $membershipId, RolesAndCapabilities::PAYMENT_VOID);

        $this->failAuditInserts();

        $thrown = null;
        try {
            $this->delegation()->delegate($manager, $clinic, $membershipId, RolesAndCapabilities::PAYMENT_REFUND);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        } finally {
            $this->restoreAuditInserts();
        }

        self::assertNotNull($thrown, 'a failed audit persistence must fail the delegation call — never a silent success');
        self::assertStringContainsString('audit', strtolower($thrown->getMessage()));

        $effects = [];
        foreach ((new MembershipRepository(App::db()))->capabilities_for($membershipId) as $row) {
            $effects[(string) $row['capability']] = (string) $row['effect'];
        }
        self::assertArrayNotHasKey(RolesAndCapabilities::PAYMENT_REFUND, $effects, 'the delegation must be rolled back with its audit event');
        self::assertFalse(App::authorization_service()->can($secretary, $clinic, RolesAndCapabilities::PAYMENT_REFUND));

        // The sealed audit history is intact and the failed attempt left no partial row.
        self::assertSame(0, $this->auditCount(FinanceDelegationService::AUDIT_DELEGATED, RolesAndCapabilities::PAYMENT_REFUND));
        self::assertTrue(App::audit()->verifyChain(500)['ok'], 'the hash chain must stay valid after a rolled-back mutation');

        // A later healthy call still works (no poisoned state).
        $after = $this->delegation()->delegate($manager, $clinic, $membershipId, RolesAndCapabilities::PAYMENT_REFUND);
        self::assertSame(RolesAndCapabilities::PAYMENT_REFUND, (string) $after['capability']);
    }

    // ================= 12 — Existing-install upgrade behaviour =================

    public function testExistingInstallUpgradePreservesExplicitGrantsAndFinancialRecords(): void
    {
        [$clinic, $location, , $invoice, ] = $this->financeFixture();
        $secretary = $this->staff($clinic, RolesAndCapabilities::ROLE_SECRETARY, 'sec_upgrade');
        $membershipId = $this->membershipId($clinic, $secretary);

        // Simulate a pre-existing installation: an explicit GRANT row that predates this policy.
        (new MembershipRepository(App::db()))->set_capability($membershipId, RolesAndCapabilities::PAYMENT_VOID, 'grant');
        (new MembershipRepository(App::db()))->set_capability($membershipId, RolesAndCapabilities::PAYMENT_REFUND, 'grant');

        wp_set_current_user($secretary);
        $capture = $this->pay($invoice, $clinic, $location, ['amount' => 33000, 'method' => 'cash']);
        self::assertSame(201, $capture->get_status(), 'premise capture — ' . $this->errorCode($capture));
        $paymentId = (int) ($this->payload($capture)['payment']['id'] ?? 0);
        $paymentBefore = $this->paymentRow($paymentId);
        $invoiceBefore = $this->invoiceRow($invoice);

        // Upgrade path: the preset re-synchronization that runs on plugin upgrade.
        RolesAndCapabilities::register();

        $effects = [];
        foreach ((new MembershipRepository(App::db()))->capabilities_for($membershipId) as $row) {
            $effects[(string) $row['capability']] = (string) $row['effect'];
        }
        self::assertSame('grant', $effects[RolesAndCapabilities::PAYMENT_VOID] ?? null, 'an existing explicit grant must survive the upgrade');
        self::assertSame('grant', $effects[RolesAndCapabilities::PAYMENT_REFUND] ?? null, 'an existing explicit grant must survive the upgrade');

        // Explicit grants are evaluated BEFORE the changed defaults.
        self::assertTrue(App::authorization_service()->can($secretary, $clinic, RolesAndCapabilities::PAYMENT_VOID));
        self::assertTrue(App::authorization_service()->can($secretary, $clinic, RolesAndCapabilities::PAYMENT_REFUND));

        // Same premise as the delegation test: the actor holds the grant ONLY as a membership override,
        // so the legacy WP-global menu gate cannot authorize this request.
        $secretary_user = get_userdata($secretary);
        self::assertNotFalse($secretary_user, 'premise: the granted member is a real user');
        self::assertFalse($secretary_user->has_cap(RolesAndCapabilities::PAYMENT_VOID), 'premise: the WP-global menu gate cannot authorize the granted member');

        // A pre-existing explicit grant must keep working end-to-end after the upgrade.
        $void = $this->dispatch('POST', sprintf(self::PAY_VOID, $paymentId), [], $this->scopeHeaders($clinic), ['reason' => 'pre-existing grant']);
        self::assertSame(200, $void->get_status(), 'a pre-existing explicit grant must keep working — ' . $this->errorCode($void));
        self::assertSame('voided', (string) $this->paymentRow($paymentId)['status'], 'the granted void must take effect');
        self::assertSame(1, $this->auditCount('PAYMENT_VOID'), 'the granted void must be audited');

        // A secretary WITHOUT an explicit grant on the same upgraded installation is denied.
        $plain = $this->staff($clinic, RolesAndCapabilities::ROLE_SECRETARY, 'sec_plain');
        self::assertFalse(App::authorization_service()->can($plain, $clinic, RolesAndCapabilities::PAYMENT_VOID), 'the changed default applies where no explicit grant exists');
        self::assertSame(
            0,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_membership_capabilities') . ' WHERE membership_id = %d',
                [$this->membershipId($clinic, $plain)]
            ),
            'the upgrade must not create capability rows for other members'
        );

        // Existing financial records are preserved, not rewritten, and no new migration was needed.
        self::assertSame($paymentBefore['amount'], $this->paymentRow($paymentId)['amount'], 'the payment amount must be untouched by the upgrade');
        self::assertSame($invoiceBefore['total'], $this->invoiceRow($invoice)['total'], 'the invoice total must be untouched by the upgrade');
        self::assertSame('voided', (string) $this->paymentRow($paymentId)['status'], 'only the authorized operation may change the payment');

        // Only timestamped migration files count as migrations: the directory also holds the
        // runner/helper classes (e.g. MigrationRunner.php), which are not migrations.
        $migration_files = glob(dirname(__DIR__, 2) . '/src/Migrations/*.php');
        self::assertIsArray($migration_files);
        $names = [];
        foreach ($migration_files as $migration_file) {
            $base = basename($migration_file);
            if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{4}_/', $base) === 1) {
                $names[] = $base;
            }
        }
        self::assertNotEmpty($names, 'premise: the timestamped migration set is discoverable');
        sort($names);
        self::assertSame('2026_10_31_0031_audit_append_serialization.php', end($names), 'the latest schema version is the audit guard migration');

        // Cross-Clinic isolation is unchanged on an upgraded install.
        [$clinicB, $locationB, , $invoiceB, ] = $this->financeFixture();
        wp_set_current_user($secretary);
        $foreign = $this->pay($invoiceB, $clinicB, $locationB, ['amount' => 4000, 'method' => 'cash']);
        self::assertContains($foreign->get_status(), [400, 403, 404], 'explicit grants are Clinic-scoped');
    }

    // ================= 13 — explicit GRANT cannot bypass doctor ownership =================

    public function testExplicitMembershipGrantDoesNotBypassDoctorOwnership(): void
    {
        [$clinic, $location, , $invoiceA, $visitA] = $this->financeFixture();
        $visitB = $this->secondVisit($clinic, $location);

        $owner = $this->doctor($clinic, $visitA, 'dr_grant_owner');
        $other = $this->doctor($clinic, $visitB, 'dr_grant_other');

        // A deliberate, pre-existing explicit GRANT for the unrelated doctor — the strongest
        // non-role authority this installation can hold. Ownership must still be mandatory.
        $otherMembership = $this->membershipId($clinic, $other);
        (new MembershipRepository(App::db()))->set_capability($otherMembership, RolesAndCapabilities::PAYMENT_VOID, 'grant');
        self::assertTrue(
            App::authorization_service()->can($other, $clinic, RolesAndCapabilities::PAYMENT_VOID),
            'premise: the explicit grant is effective for the capability itself'
        );

        $secretary = $this->staff($clinic, RolesAndCapabilities::ROLE_SECRETARY, 'sec_grant_probe');
        wp_set_current_user($secretary);
        $capture = $this->pay($invoiceA, $clinic, $location, ['amount' => 9000, 'method' => 'cash']);
        self::assertSame(201, $capture->get_status(), 'premise capture — ' . $this->errorCode($capture));
        $paymentId = (int) ($this->payload($capture)['payment']['id'] ?? 0);
        $before = $this->paymentRow($paymentId);

        wp_set_current_user($other);
        $void = $this->dispatch('POST', sprintf(self::PAY_VOID, $paymentId), [], $this->scopeHeaders($clinic), ['reason' => 'grant bypass attempt']);
        self::assertSame(403, $void->get_status(), 'an explicit grant must NOT bypass doctor ownership — ' . $this->errorCode($void));
        self::assertSame($before, $this->paymentRow($paymentId), 'the granted-but-unrelated doctor must not mutate the payment');
        self::assertSame(0, $this->auditCount('PAYMENT_VOID'));

        // The owner is unaffected, and the owner's own grant-free path still works.
        wp_set_current_user($owner);
        self::assertSame(
            200,
            $this->dispatch('POST', sprintf(self::PAY_VOID, $paymentId), [], $this->scopeHeaders($clinic), ['reason' => 'owner correction'])->get_status()
        );
    }

    // ================= 14 — delegation to a doctor keeps ownership mandatory =================

    public function testDelegationToDoctorDoesNotGrantUnrestrictedFinancialAuthority(): void
    {
        [$clinic, $location, , $invoiceA, $visitA] = $this->financeFixture();
        $visitB = $this->secondVisit($clinic, $location);

        $manager = $this->staff($clinic, RolesAndCapabilities::ROLE_MANAGER, 'mgr_to_doctor');
        $owner = $this->doctor($clinic, $visitA, 'dr_deleg_owner');
        $other = $this->doctor($clinic, $visitB, 'dr_deleg_other');
        $otherMembership = $this->membershipId($clinic, $other);

        // The manager explicitly delegates the sensitive capability to the unrelated doctor's
        // membership: an authorized delegation, revoked-able and audited.
        $result = $this->delegation()->delegate($manager, $clinic, $otherMembership, RolesAndCapabilities::PAYMENT_REFUND);
        self::assertSame('grant', (string) ($result['effect'] ?? ''));
        self::assertSame(1, $this->auditCount(FinanceDelegationService::AUDIT_DELEGATED));
        self::assertTrue(App::authorization_service()->can($other, $clinic, RolesAndCapabilities::PAYMENT_REFUND));

        $secretary = $this->staff($clinic, RolesAndCapabilities::ROLE_SECRETARY, 'sec_deleg_probe');
        wp_set_current_user($secretary);
        $capture = $this->pay($invoiceA, $clinic, $location, ['amount' => 11000, 'method' => 'cash']);
        self::assertSame(201, $capture->get_status(), 'premise capture — ' . $this->errorCode($capture));
        $paymentId = (int) ($this->payload($capture)['payment']['id'] ?? 0);

        // Delegation grants the CAPABILITY, never ownership of somebody else's transaction.
        wp_set_current_user($other);
        $refund = $this->dispatch('POST', sprintf(self::PAY_REFUND, $paymentId), [], $this->scopeHeaders($clinic), ['reason' => 'delegated but unrelated']);
        self::assertSame(403, $refund->get_status(), 'a delegated doctor must stay bounded by transaction ownership — ' . $this->errorCode($refund));
        self::assertSame('AUTH_OWNERSHIP_REQUIRED', (string) ($this->payload($refund)['reason'] ?? ''), 'the denial must be the ownership conjunct, not a capability gate');
        self::assertSame('captured', (string) $this->paymentRow($paymentId)['status']);
        self::assertSame(0, $this->auditCount('PAYMENT_REFUND'));

        // The owner keeps working authority; revocation returns the delegate to default deny.
        wp_set_current_user($owner);
        self::assertSame(
            200,
            $this->dispatch('POST', sprintf(self::PAY_REFUND, $paymentId), [], $this->scopeHeaders($clinic), ['reason' => 'owner refund'])->get_status()
        );
        $this->delegation()->revoke($manager, $clinic, $otherMembership, RolesAndCapabilities::PAYMENT_REFUND);
        self::assertNull($this->delegation()->explicit_effect($otherMembership, RolesAndCapabilities::PAYMENT_REFUND), 'revocation must remove the delegation row');
        self::assertSame(1, $this->auditCount(FinanceDelegationService::AUDIT_REVOKED), 'revocation must be audited');
        // A doctor keeps the capability through the doctor PRESET (own-service corrections);
        // what the revoked delegation must never leave behind is the explicit override row, and
        // ownership remains mandatory regardless (asserted above).
    }

    // ================= 15 — manager self-delegation is bounded, audited, revocable =================

    public function testManagerSelfDelegationIsBoundedAuditedAndRevocable(): void
    {
        [$clinic, , , , ] = $this->financeFixture();
        $manager = $this->staff($clinic, RolesAndCapabilities::ROLE_MANAGER, 'mgr_self');
        $managerMembership = $this->membershipId($clinic, $manager);

        self::assertFalse(App::authorization_service()->can($manager, $clinic, RolesAndCapabilities::PAYMENT_VOID), 'premise: managers hold no default sensitive finance authority');

        $result = $this->delegation()->delegate($manager, $clinic, $managerMembership, RolesAndCapabilities::PAYMENT_VOID);
        self::assertSame(RolesAndCapabilities::PAYMENT_VOID, (string) ($result['capability'] ?? ''));
        self::assertSame(1, $this->auditCount(FinanceDelegationService::AUDIT_DELEGATED));
        self::assertTrue(App::authorization_service()->can($manager, $clinic, RolesAndCapabilities::PAYMENT_VOID));

        // Bounded: exactly one capability row was added, and nothing outside the finance set.
        $rows = (new MembershipRepository(App::db()))->capabilities_for($managerMembership);
        self::assertCount(1, $rows, 'self-delegation must add exactly one bounded capability row');
        self::assertSame(RolesAndCapabilities::PAYMENT_VOID, (string) $rows[0]['capability']);
        foreach ([RolesAndCapabilities::MEDICAL_READ, RolesAndCapabilities::PRIVATE_NOTE_READ, RolesAndCapabilities::EXPORT, RolesAndCapabilities::AUDIT_READ, RolesAndCapabilities::PAYMENT_REFUND] as $notDelegated) {
            self::assertFalse(App::authorization_service()->can($manager, $clinic, $notDelegated), $notDelegated . ' must stay denied for the self-delegated manager');
        }

        // Revocable: back to default deny (the manager keeps only its preset authority).
        $this->delegation()->revoke($manager, $clinic, $managerMembership, RolesAndCapabilities::PAYMENT_VOID);
        self::assertFalse(App::authorization_service()->can($manager, $clinic, RolesAndCapabilities::PAYMENT_VOID));
        self::assertTrue(App::authorization_service()->can($manager, $clinic, RolesAndCapabilities::CONFIG), 'the manager keeps its management authority');
        self::assertSame(1, $this->auditCount(FinanceDelegationService::AUDIT_REVOKED));
    }

    // ================= 16 — DENY still beats grant and ownership =================

    public function testExplicitDenyBeatsOwnershipAndGrant(): void
    {
        [$clinic, $location, , $invoice, $visit] = $this->financeFixture();
        $owner = $this->doctor($clinic, $visit, 'dr_denied_owner');
        $ownerMembership = $this->membershipId($clinic, $owner);

        (new MembershipRepository(App::db()))->set_capability($ownerMembership, RolesAndCapabilities::PAYMENT_VOID, 'deny');

        $secretary = $this->staff($clinic, RolesAndCapabilities::ROLE_SECRETARY, 'sec_deny_owner');
        wp_set_current_user($secretary);
        $capture = $this->pay($invoice, $clinic, $location, ['amount' => 6000, 'method' => 'cash']);
        self::assertSame(201, $capture->get_status(), 'premise capture — ' . $this->errorCode($capture));
        $paymentId = (int) ($this->payload($capture)['payment']['id'] ?? 0);

        // The actor owns the transaction AND holds the role, but an explicit DENY still wins.
        self::assertFalse(App::authorization_service()->can($owner, $clinic, RolesAndCapabilities::PAYMENT_VOID));
        wp_set_current_user($owner);
        $void = $this->dispatch('POST', sprintf(self::PAY_VOID, $paymentId), [], $this->scopeHeaders($clinic), ['reason' => 'denied owner']);
        self::assertSame(403, $void->get_status(), 'an explicit DENY must beat ownership — ' . $this->errorCode($void));
        self::assertSame('captured', (string) $this->paymentRow($paymentId)['status']);
        self::assertSame(0, $this->auditCount('PAYMENT_VOID'));
    }

    // ================= 17 — delegation cannot widen authority through overrides =================

    public function testDelegationCannotWriteCapabilitiesOutsideTheBoundedSet(): void
    {
        [$clinic, , , , ] = $this->financeFixture();
        $manager = $this->staff($clinic, RolesAndCapabilities::ROLE_MANAGER, 'mgr_bounds');
        $target = $this->staff($clinic, RolesAndCapabilities::ROLE_SECRETARY, 'sec_bounds');
        $targetMembership = $this->membershipId($clinic, $target);

        $outOfBounds = [
            RolesAndCapabilities::MEDICAL_READ,
            RolesAndCapabilities::PRIVATE_NOTE_READ,
            RolesAndCapabilities::NOTE_CREATE,
            RolesAndCapabilities::EXPORT,
            RolesAndCapabilities::AUDIT_READ,
            RolesAndCapabilities::CONFIG,
            RolesAndCapabilities::SMS_CONFIG,
            RolesAndCapabilities::PATIENT_READ,
            RolesAndCapabilities::PAYMENT_CREATE,
            'cpms_role_manage',
            'manage_options',
        ];

        foreach ($outOfBounds as $capability) {
            $rejected = null;
            try {
                $this->delegation()->delegate($manager, $clinic, $targetMembership, $capability);
            } catch (FinanceException $e) {
                $rejected = $e;
            }
            self::assertNotNull($rejected, $capability . ' must never be delegable');
            self::assertSame(422, $rejected->httpStatus, $capability . ' must be refused as out of bounds');
            self::assertNull($this->delegation()->explicit_effect($targetMembership, $capability), $capability . ' must not gain any override row');
        }

        self::assertSame([], (new MembershipRepository(App::db()))->capabilities_for($targetMembership), 'no out-of-bounds row may be written');
        self::assertSame(0, $this->auditCount(FinanceDelegationService::AUDIT_DELEGATED));
        self::assertTrue(App::authorization_service()->can($target, $clinic, RolesAndCapabilities::PAYMENT_CREATE), 'ordinary capture authority is unchanged for the secretary');
        self::assertFalse(App::authorization_service()->can($target, $clinic, RolesAndCapabilities::MEDICAL_READ), 'no medical-record access may be created by delegation');
    }

    // ================= product wiring / non-claims =================

    public function testPolicyDoesNotIntroduceNewCapabilitiesOrMedicalAccess(): void
    {
        self::assertCount(46, RolesAndCapabilities::ALL_CAPS, 'the capability catalogue must stay exactly 46');

        $manager = RolesAndCapabilities::MANAGER_CAPS;
        foreach ([RolesAndCapabilities::PAYMENT_VOID, RolesAndCapabilities::PAYMENT_REFUND, RolesAndCapabilities::INVOICE_ADJUST, RolesAndCapabilities::INVOICE_VOID] as $cap) {
            self::assertNotContains($cap, $manager, 'the manager preset must not gain financial capabilities by default');
        }
        foreach ([RolesAndCapabilities::MEDICAL_READ, RolesAndCapabilities::PRIVATE_NOTE_READ, RolesAndCapabilities::PRIVATE_NOTE_CREATE, RolesAndCapabilities::NOTE_CREATE] as $clinical) {
            self::assertNotContains($clinical, $manager, 'no default medical-record access may be created for managers');
            self::assertNotContains($clinical, RolesAndCapabilities::SECRETARY_CAPS, 'no default medical-record access may be created for secretaries');
        }
        self::assertTrue(CustomRolePolicy::is_editable_capability(RolesAndCapabilities::PAYMENT_CREATE), 'ordinary capture stays delegable through custom roles');
        foreach ([RolesAndCapabilities::PAYMENT_VOID, RolesAndCapabilities::PAYMENT_REFUND] as $sensitive) {
            self::assertFalse(CustomRolePolicy::is_editable_capability($sensitive), 'sensitive financial capabilities stay non-delegable through custom roles');
        }
    }

    // ================= fixtures / helpers =================

    /**
     * Clinic + Locations + patient/clinician/Visit/invoice at the first Location.
     *
     * @return array{0:int,1:int,2:int,3:int,4:int}
     */
    private function financeFixture(): array
    {
        $org = $this->insertOrg('Finance policy org');
        $clinic = $this->insertClinic($org);
        $location = $this->insertLocation($clinic, 'Asia/Tehran');
        $otherLocation = $this->insertLocation($clinic, 'Asia/Tehran');
        $clinician = $this->insertClinician($clinic, 'Dr Fixture Owner', 0);
        $patient = $this->insertPatient($clinic, 'FinPolicy');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        $visit = $this->insertVisit($clinic, $location, $patient, $clinician, $date, App::db()->nowUtcSql(), 'awaiting_payment');
        $invoice = $this->insertInvoice($clinic, $location, $patient, $visit, $clinician, 'open');

        return [$clinic, $location, $otherLocation, $invoice, $visit];
    }

    /** A WP user with an ACTIVE membership of the given built-in role in $clinic. */
    private function staff(int $clinicId, string $roleKey, string $tag): int
    {
        $userId = $this->makeUser($tag, $roleKey);
        cpms_test_seed_membership($userId, $clinicId, $roleKey);

        return $userId;
    }

    /**
     * A doctor actor: WP user + Clinician profile linked 1:1 (`clinicians.wp_user_id`) + ACTIVE
     * membership. The Visit's responsible clinician is set to this profile — the ONLY durable
     * ownership fact the policy accepts.
     */
    private function doctor(int $clinicId, int $visitId, string $tag): int
    {
        $userId = $this->makeUser($tag, RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($userId, $clinicId, RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianId = $this->insertClinician($clinicId, 'Dr ' . $tag, $userId);
        App::db()->update('cpms_visits', ['clinician_id' => $clinicianId], ['id' => $visitId]);
        self::assertSame(
            $clinicianId,
            (int) App::db()->fetchValue('SELECT clinician_id FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [$visitId]),
            'fixture: the Visit must be owned by this clinician'
        );

        return $userId;
    }

    /** A second service (Visit + invoice) in the SAME Clinic, owned by another clinician. */
    private function secondVisit(int $clinicId, int $locationId): int
    {
        $patient = $this->insertPatient($clinicId, 'FinPolicyOther');
        $clinician = $this->insertClinician($clinicId, 'Dr Fixture Other', 0);
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');

        return $this->insertVisit($clinicId, $locationId, $patient, $clinician, $date, App::db()->nowUtcSql(), 'awaiting_payment');
    }

    private function delegation(): FinanceDelegationService
    {
        return App::finance_delegation_service();
    }

    private function membershipId(int $clinicId, int $userId): int
    {
        $membership = App::membership_service()->membership_for($clinicId, $userId);
        self::assertNotNull($membership);

        return (int) $membership['id'];
    }

    /** @param array<string, mixed> $body */
    private function pay(int $invoiceId, int $clinicId, ?int $locationId, array $body): WP_REST_Response
    {
        return $this->dispatch(
            'POST',
            sprintf(self::PAY, $invoiceId),
            [],
            $this->scopeHeaders($clinicId, $locationId),
            $body,
            ['Idempotency-Key' => $this->uuid()]
        );
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** @return array<string, mixed> */
    private function paymentRow(int $paymentId): array
    {
        $row = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_payments') . ' WHERE id = %d', [$paymentId]);
        self::assertNotNull($row);

        return $row;
    }

    /** @return array<string, mixed> */
    private function invoiceRow(int $invoiceId): array
    {
        $row = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_invoices') . ' WHERE id = %d', [$invoiceId]);
        self::assertNotNull($row);

        return $row;
    }

    private function paymentCount(int $invoiceId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_payments') . ' WHERE invoice_id = %d',
            [$invoiceId]
        );
    }

    private function auditCount(string $action, ?string $capability = null): int
    {
        if ($capability === null) {
            return (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s',
                [$action]
            );
        }

        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs')
                . ' WHERE action = %s AND (meta_json LIKE %s OR after_json LIKE %s)',
            [$action, '%' . $capability . '%', '%' . $capability . '%']
        );
    }

    /** @return array<string, string> */
    private function scopeHeaders(int $clinicId, ?int $locationId = null): array
    {
        $headers = ['X-CPMS-Clinic-Id' => (string) $clinicId];
        if ($locationId !== null) {
            $headers['X-CPMS-Location-Id'] = (string) $locationId;
        }

        return $headers;
    }

    /**
     * @param array<string, mixed>      $params
     * @param array<string, string>     $headers
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $extraHeaders
     */
    private function dispatch(string $method, string $route, array $params = [], array $headers = [], ?array $body = null, array $extraHeaders = [], bool $withNonce = true): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        if ($body !== null) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body((string) wp_json_encode($body));
        }
        if ($withNonce) {
            $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        }
        foreach ($headers as $key => $value) {
            $request->set_header($key, $value);
        }
        foreach ($extraHeaders as $key => $value) {
            $request->set_header($key, $value);
        }

        return rest_do_request($request);
    }

    /** @return array<string, mixed> */
    private function payload(WP_REST_Response $response): array
    {
        $data = $response->get_data();

        return is_array($data) && is_array($data['data'] ?? null) ? $data['data'] : (is_array($data) ? $data : []);
    }

    private function errorCode(WP_REST_Response $response): string
    {
        $data = $response->get_data();

        return is_array($data) ? (string) ($data['code'] ?? '') : '';
    }

    private function makeUser(string $login, string $role): int
    {
        $id = (int) wp_create_user($login . '_' . bin2hex(random_bytes(3)), 'test-password-123', $login . '@test.local');
        self::assertGreaterThan(0, $id);
        get_userdata($id)->set_role($role);

        return $id;
    }

    /**
     * Real MySQL fault injection: the audit INSERT is rewritten into a statement naming a column that
     * does not exist, so the insert genuinely fails (no fixture/bootstrap failure is simulated).
     */
    private function failAuditInserts(): void
    {
        global $wpdb;
        $this->previousSuppression = (bool) $wpdb->suppress_errors;
        $wpdb->suppress_errors = true;
        $this->auditFault = static function (string $sql): string {
            if (str_contains($sql, 'cpms_audit_logs') && stripos(ltrim($sql), 'insert') === 0) {
                return str_replace('row_hash', 'row_hash_missing_column', $sql);
            }

            return $sql;
        };
        add_filter('query', $this->auditFault, 999);
    }

    private function restoreAuditInserts(): void
    {
        global $wpdb;
        if ($this->auditFault !== null) {
            remove_filter('query', $this->auditFault, 999);
            $this->auditFault = null;
        }
        $wpdb->suppress_errors = $this->previousSuppression;
    }

    private function insertOrg(string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_organizations', ['name' => $name, 'slug' => 'org-' . bin2hex(random_bytes(3)), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]));

        return (int) $wpdb->insert_id;
    }

    private function insertClinic(int $orgId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_clinics', ['organization_id' => $orgId, 'name' => 'Finance policy Clinic', 'slug' => 'clinic-' . bin2hex(random_bytes(3)), 'timezone' => 'Asia/Tehran', 'created_at' => $now, 'updated_at' => $now]));

        return (int) $wpdb->insert_id;
    }

    private function insertLocation(int $clinicId, string $timezone): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_locations', ['clinic_id' => $clinicId, 'name' => 'Finance policy Location', 'slug' => 'location-' . bin2hex(random_bytes(3)), 'timezone' => $timezone, 'is_primary' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]));

        return (int) $wpdb->insert_id;
    }

    private function insertPatient(int $clinicId, string $tag): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_patients', ['clinic_id' => $clinicId, 'mrn' => 'M-' . bin2hex(random_bytes(5)), 'first_name' => $tag, 'last_name' => 'Finance', 'mobile' => '09' . random_int(1000000000, 9999999999), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]));

        return (int) $wpdb->insert_id;
    }

    private function insertClinician(int $clinicId, string $name, int $wpUserId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_clinicians', [
            'clinic_id' => $clinicId,
            'full_name' => $name,
            'is_active' => 1,
            'wp_user_id' => $wpUserId > 0 ? $wpUserId : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        return (int) $wpdb->insert_id;
    }

    private function insertVisit(int $clinicId, int $locationId, int $patientId, int $clinicianId, string $date, string $checkIn, string $status): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_visits', [
            'clinic_id' => $clinicId, 'location_id' => $locationId, 'clinician_id' => $clinicianId,
            'patient_id' => $patientId, 'appointment_id' => null, 'source' => 'walk_in', 'status' => $status,
            'visit_date' => $date, 'check_in_at' => $checkIn, 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]));

        return (int) $wpdb->insert_id;
    }

    private function insertInvoice(int $clinicId, int $locationId, int $patientId, int $visitId, int $actorId, string $status): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $total = '100000.00';
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_invoices', [
            'clinic_id' => $clinicId, 'location_id' => $locationId, 'invoice_number' => 'INV-FP-' . bin2hex(random_bytes(4)),
            'patient_id' => $patientId, 'visit_id' => $visitId, 'status' => $status, 'subtotal' => $total,
            'total' => $total, 'currency' => 'IRR', 'paid_amount' => '0.00', 'balance' => $total,
            'issued_by_wp_user_id' => $actorId, 'created_at' => $now, 'updated_at' => $now,
        ]));

        return (int) $wpdb->insert_id;
    }
}
