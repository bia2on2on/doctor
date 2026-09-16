<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Authorization\AuthorizationService;
use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Notifications\NotificationEvents;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Storage\LocalFileStorage;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Phase 3 Slice 4 — Clinic-scoped authorization for reports + CSV export.
 *
 * The surface under test (all real product routes):
 *   GET  /clinic/v1/reports
 *   GET  /clinic/v1/reports/{type}
 *   GET  /clinic/v1/reports/{type}/print
 *   POST /clinic/v1/reports/{type}/export
 *   GET  /clinic/v1/reports/exports
 *   GET  /clinic/v1/reports/exports/{id}/download
 *
 * Fixture design (no first-row tenant discovery):
 *  - every test creates an explicit Organization (status=active) with a
 *    database-generated ID and asserts the insert;
 *  - Clinics are created under that Organization with database-generated IDs
 *    and their organization linkage is asserted;
 *  - users/memberships are created dynamically; no fixed Clinic/Organization id
 *    and no `SELECT ... LIMIT 1` tenant discovery is used.
 *
 * RED evidence (see the two `testRed*` cases) is intentionally split:
 *  - RED A proves that a scoped `AuthorizationService` denial (durable active
 *    membership + explicit membership deny for EXPORT) does not stop the real
 *    export-request route;
 *  - RED B is an independent test that counts durable `report.export` jobs for
 *    the same unauthorized request. The job-count assertion is NOT placed after
 *    an expected-403 assertion in the same test.
 */
final class ReportsExportClinicAuthorizationTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
    }

    protected function tearDown(): void
    {
        App::resetScope();
        parent::tearDown();
    }

    // ================= RED evidence =================

    /**
     * RED A — the current product path reaches the export request even though
     * scoped authorization denies EXPORT for this Clinic.
     *
     * Preconditions proven in-test: the global coarse gate is fully satisfied,
     * a durable ACTIVE membership exists, the scoped EXPORT permission is denied
     * by an explicit membership deny, and the trusted Clinic is established from
     * durable membership by the same boundary REST uses.
     */
    public function testRedACoarseGlobalCapabilityCannotBypassScopedExportDenialOnExportRequest(): void
    {
        [$clinicId, $actorId] = $this->createScopedExportDeniedActor();

        $res = $this->dispatchPost(self::NS . '/reports/revenue/export', $clinicId, $actorId, [
            'from' => gmdate('Y-m-d'),
            'to' => gmdate('Y-m-d'),
        ]);

        self::assertSame(
            403,
            $res->get_status(),
            'An actor whose durable membership denies scoped EXPORT must not reach the export request path. '
                . 'Defective main returned HTTP ' . $res->get_status() . '.'
        );
        self::assertSame(
            'CLINIC_PERMISSION_DENIED',
            $this->errorCode($res),
            'the denial must use the established generic 403 envelope'
        );
    }

    /**
     * RED B — independent from RED A: no `report.export` job may be enqueued for
     * an unauthorized export request.
     */
    public function testRedBUnauthorizedExportRequestMustNotEnqueueReportExportJob(): void
    {
        [$clinicId, $actorId] = $this->createScopedExportDeniedActor();

        $before = $this->reportExportJobCount($clinicId, $actorId);

        $res = $this->dispatchPost(self::NS . '/reports/revenue/export', $clinicId, $actorId, [
            'from' => gmdate('Y-m-d'),
            'to' => gmdate('Y-m-d'),
        ]);

        $after = $this->reportExportJobCount($clinicId, $actorId);

        self::assertSame(
            0,
            $after - $before,
            'Authorization denial must happen before jobs->enqueue(report.export). '
                . 'The request returned HTTP ' . $res->get_status()
                . ' and created ' . ($after - $before) . ' durable job(s) for Clinic ' . $clinicId . '.'
        );
    }

    // ================= DENY =================

    public function testDenyGlobalCapableActorWithoutAnyClinicMembership(): void
    {
        $orgId = $this->createOrganization('no-mem');
        $clinicId = $this->createClinic($orgId, 'no-mem');
        $actorId = $this->makeUser('rep_no_mem', 'cpms_accountant');

        $user = get_userdata($actorId);
        self::assertNotFalse($user);
        self::assertTrue($user->has_cap(RolesAndCapabilities::EXPORT), 'precondition: global EXPORT is held');
        self::assertSame(
            [],
            App::membership_service()->active_memberships_for_user($actorId),
            'precondition: the actor holds no durable Clinic membership'
        );

        $read = $this->dispatchGet(self::NS . '/reports', $clinicId, $actorId);
        self::assertSame(403, $read->get_status(), 'installation capability alone must not read Clinic report data');

        $export = $this->dispatchPost(self::NS . '/reports/revenue/export', $clinicId, $actorId, [
            'from' => gmdate('Y-m-d'),
            'to' => gmdate('Y-m-d'),
        ]);
        self::assertSame(403, $export->get_status(), 'installation capability alone must not export Clinic data');
        self::assertSame(0, $this->exportReadyCount($clinicId, $actorId), 'no artifact notification may be produced');
    }

    public function testDenySuspendedMembershipOnReadAndExport(): void
    {
        $orgId = $this->createOrganization('suspended');
        $clinicId = $this->createClinic($orgId, 'suspended');
        $actorId = $this->makeUser('rep_suspended', 'cpms_accountant');

        $memId = App::membership_service()->create_membership($clinicId, $actorId, 'cpms_accountant');
        App::membership_service()->suspend_membership($memId);
        self::assertNull(
            App::membership_service()->active_membership_for($clinicId, $actorId),
            'precondition: the membership is suspended'
        );

        $jobsBefore = $this->reportExportJobCount($clinicId, $actorId);
        $export = $this->dispatchPost(self::NS . '/reports/revenue/export', $clinicId, $actorId, [
            'from' => gmdate('Y-m-d'),
            'to' => gmdate('Y-m-d'),
        ]);
        $jobsAfter = $this->reportExportJobCount($clinicId, $actorId);
        self::assertSame(0, $jobsAfter - $jobsBefore, 'no durable job may be created for a suspended membership');
        self::assertSame(403, $export->get_status(), 'a suspended membership must not export');

        $read = $this->dispatchGet(self::NS . '/reports', $clinicId, $actorId);
        self::assertSame(403, $read->get_status(), 'a suspended membership must fail closed');
    }

    public function testDenyActiveMembershipLackingReportRead(): void
    {
        $orgId = $this->createOrganization('no-read');
        $clinicId = $this->createClinic($orgId, 'no-read');
        $actorId = $this->makeUser('rep_no_read', 'cpms_accountant');

        App::membership_service()->create_membership($clinicId, $actorId, 'cpms_secretary');
        self::assertFalse(
            $this->authz()->can($actorId, $clinicId, RolesAndCapabilities::REPORT_READ),
            'precondition: scoped REPORT_READ is not granted by the secretary preset'
        );

        $export = $this->dispatchPost(self::NS . '/reports/revenue/export', $clinicId, $actorId, [
            'from' => gmdate('Y-m-d'),
            'to' => gmdate('Y-m-d'),
        ]);
        self::assertSame(
            0,
            $this->reportExportJobCount($clinicId, $actorId),
            'no export job may be enqueued when scoped REPORT_READ is missing'
        );
        self::assertSame(403, $export->get_status());

        $read = $this->dispatchGet(self::NS . '/reports', $clinicId, $actorId);
        self::assertSame(403, $read->get_status());
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errorCode($read));

        $print = $this->dispatchGet(self::NS . '/reports/revenue/print', $clinicId, $actorId);
        self::assertSame(403, $print->get_status(), 'print is a report read and needs the same scoped permission');
    }

    public function testDenyActiveMembershipLackingExportWhileReportReadIsAllowed(): void
    {
        $orgId = $this->createOrganization('no-export');
        $clinicId = $this->createClinic($orgId, 'no-export');
        $actorId = $this->makeUser('rep_no_export', 'cpms_accountant');

        App::membership_service()->create_membership($clinicId, $actorId, 'cpms_manager');
        self::assertTrue(
            $this->authz()->can($actorId, $clinicId, RolesAndCapabilities::REPORT_READ),
            'precondition: the manager preset grants scoped REPORT_READ'
        );
        self::assertFalse(
            $this->authz()->can($actorId, $clinicId, RolesAndCapabilities::EXPORT),
            'precondition: the manager preset does not grant scoped EXPORT'
        );

        $export = $this->dispatchPost(self::NS . '/reports/revenue/export', $clinicId, $actorId, [
            'from' => gmdate('Y-m-d'),
            'to' => gmdate('Y-m-d'),
        ]);
        self::assertSame(
            0,
            $this->reportExportJobCount($clinicId, $actorId),
            'EXPORT is required separately from REPORT_READ: no durable job may be created'
        );
        self::assertSame(
            403,
            $export->get_status(),
            'EXPORT must be required separately from REPORT_READ (defective main returned '
                . $export->get_status() . ')'
        );

        $read = $this->dispatchGet(self::NS . '/reports', $clinicId, $actorId);
        self::assertSame(200, $read->get_status(), 'report reading must stay allowed for REPORT_READ holders');
    }

    public function testDenyExplicitMembershipDenyForReportRead(): void
    {
        $orgId = $this->createOrganization('deny-read');
        $clinicId = $this->createClinic($orgId, 'deny-read');
        $actorId = $this->makeUser('rep_deny_read', 'cpms_accountant');

        $memId = App::membership_service()->create_membership($clinicId, $actorId, 'cpms_accountant');
        self::assertTrue(
            $this->authz()->can($actorId, $clinicId, RolesAndCapabilities::REPORT_READ),
            'precondition: the accountant preset grants scoped REPORT_READ before the deny is applied'
        );
        App::membership_service()->set_capability($memId, RolesAndCapabilities::REPORT_READ, 'deny');
        self::assertFalse(
            $this->authz()->can($actorId, $clinicId, RolesAndCapabilities::REPORT_READ),
            'precondition: the explicit deny overrides the role preset'
        );

        $export = $this->dispatchPost(self::NS . '/reports/revenue/export', $clinicId, $actorId, [
            'from' => gmdate('Y-m-d'),
            'to' => gmdate('Y-m-d'),
        ]);
        self::assertSame(0, $this->reportExportJobCount($clinicId, $actorId), 'no durable job may be created');
        self::assertSame(403, $export->get_status(), 'an explicit deny must also block the export request');

        $read = $this->dispatchGet(self::NS . '/reports', $clinicId, $actorId);
        self::assertSame(403, $read->get_status(), 'explicit membership deny must override the preset');
    }

    public function testDenyClinicAAuthorizationCannotBeUsedForClinicB(): void
    {
        $orgId = $this->createOrganization('cross');
        $clinicA = $this->createClinic($orgId, 'cross-a');
        $clinicB = $this->createClinic($orgId, 'cross-b');
        $actorId = $this->makeUser('rep_cross', 'cpms_accountant');

        App::membership_service()->create_membership($clinicA, $actorId, 'cpms_accountant');

        $crossExport = $this->dispatchPost(self::NS . '/reports/revenue/export', $clinicB, $actorId, [
            'from' => gmdate('Y-m-d'),
            'to' => gmdate('Y-m-d'),
        ]);
        self::assertSame(
            0,
            $this->reportExportJobCount($clinicB, $actorId),
            'nothing may be durably enqueued for the foreign Clinic'
        );
        self::assertSame(403, $crossExport->get_status(), 'Clinic A authorization must not export Clinic B data');

        $crossRead = $this->dispatchGet(self::NS . '/reports', $clinicB, $actorId);
        self::assertSame(403, $crossRead->get_status(), 'Clinic A authorization must not read Clinic B data');

        // Positive control: the same actor is authorized in Clinic A.
        $ownExport = $this->dispatchPost(self::NS . '/reports/revenue/export', $clinicA, $actorId, [
            'from' => gmdate('Y-m-d'),
            'to' => gmdate('Y-m-d'),
        ]);
        self::assertSame(1, $this->reportExportJobCount($clinicA, $actorId), 'the authorized Clinic must enqueue');
        self::assertSame(202, $ownExport->get_status(), 'the denial above must not be a blanket denial');
        self::assertSame(0, $this->reportExportJobCount($clinicB, $actorId), 'Clinic B must stay untouched');
    }

    public function testDenyScopedExportRevokedBetweenEnqueueAndExecutionProducesNoArtifact(): void
    {
        $orgId = $this->createOrganization('revoke');
        $clinicId = $this->createClinic($orgId, 'revoke');
        $actorId = $this->makeUser('rep_revoke', 'cpms_accountant');
        $memId = App::membership_service()->create_membership($clinicId, $actorId, 'cpms_accountant');

        $jobId = $this->enqueueAuthorizedExport($clinicId, $actorId);
        $filesBefore = $this->storedExportFileCount($clinicId);

        // Authorization REVOKED between enqueue and execution: the membership
        // stays ACTIVE, only the scoped EXPORT permission is denied.
        App::membership_service()->set_capability($memId, RolesAndCapabilities::EXPORT, 'deny');
        self::assertNotNull(
            App::membership_service()->active_membership_for($clinicId, $actorId),
            'precondition: the membership is still ACTIVE (only the permission was revoked)'
        );
        self::assertFalse(
            $this->authz()->can($actorId, $clinicId, RolesAndCapabilities::EXPORT),
            'precondition: scoped EXPORT is denied before the worker runs'
        );

        App::dispatcher()->tick(50);

        $after = App::db()->fetchRow(
            'SELECT status, attempts FROM ' . App::db()->table('cpms_jobs') . ' WHERE id = %d',
            [$jobId]
        );
        self::assertNotNull($after);
        self::assertNotSame(
            'success',
            (string) $after['status'],
            'a revoked EXPORT permission must not produce an export artifact (deterministic authorization denial)'
        );
        self::assertGreaterThanOrEqual(1, (int) $after['attempts'], 'the worker must have attempted the job');
        self::assertSame(0, $this->exportReadyCount($clinicId, $actorId), 'no success notification may be published');
        self::assertSame($filesBefore, $this->storedExportFileCount($clinicId), 'no CSV may be stored');
        self::assertSame(0, $this->opLogCount('report.export_ready'), 'no success operational log may be written');
    }

    public function testDenySuspensionBetweenEnqueueAndExecutionProducesNoArtifact(): void
    {
        $orgId = $this->createOrganization('suspend-mid');
        $clinicId = $this->createClinic($orgId, 'suspend-mid');
        $actorId = $this->makeUser('rep_suspend_mid', 'cpms_accountant');
        $memId = App::membership_service()->create_membership($clinicId, $actorId, 'cpms_accountant');

        $jobId = $this->enqueueAuthorizedExport($clinicId, $actorId);
        $filesBefore = $this->storedExportFileCount($clinicId);

        App::membership_service()->suspend_membership($memId);
        self::assertNull(
            App::membership_service()->active_membership_for($clinicId, $actorId),
            'precondition: the membership is suspended before the worker runs'
        );

        App::dispatcher()->tick(50);

        $after = App::db()->fetchRow(
            'SELECT status, attempts FROM ' . App::db()->table('cpms_jobs') . ' WHERE id = %d',
            [$jobId]
        );
        self::assertNotNull($after);
        self::assertNotSame('success', (string) $after['status'], 'a suspended actor must fail closed');
        self::assertGreaterThanOrEqual(1, (int) $after['attempts'], 'the worker must have attempted the job');
        self::assertSame(0, $this->exportReadyCount($clinicId, $actorId), 'no success notification may be published');
        self::assertSame($filesBefore, $this->storedExportFileCount($clinicId), 'no CSV may be stored');
        self::assertSame(0, $this->opLogCount('report.export_ready'), 'no success operational log may be written');
    }

    /**
     * The worker must use the durable job facts (trusted Clinic + actor) and must
     * not depend on the current WordPress user at execution time.
     */
    public function testAllowWorkerExecutionDoesNotDependOnCurrentWordPressUser(): void
    {
        $orgId = $this->createOrganization('no-current-user');
        $clinicId = $this->createClinic($orgId, 'no-current-user');
        $actorId = $this->makeUser('rep_no_current_user', 'cpms_accountant');
        App::membership_service()->create_membership($clinicId, $actorId, 'cpms_accountant');

        $jobId = $this->enqueueAuthorizedExport($clinicId, $actorId);

        wp_set_current_user(0);
        App::resetScope();

        App::dispatcher()->tick(50);

        $row = App::db()->fetchRow(
            'SELECT status, last_error FROM ' . App::db()->table('cpms_jobs') . ' WHERE id = %d',
            [$jobId]
        );
        self::assertNotNull($row);
        self::assertSame('success', (string) $row['status'], (string) ($row['last_error'] ?? ''));
        self::assertSame(1, $this->exportReadyCount($clinicId, $actorId), 'the artifact belongs to the persisted actor');
    }

    public function testDenyDownloadOfAnotherActorsArtifactInTheSameClinic(): void
    {
        $orgId = $this->createOrganization('owner');
        $clinicId = $this->createClinic($orgId, 'owner');
        $ownerId = $this->makeUser('rep_owner', 'cpms_accountant');
        $otherId = $this->makeUser('rep_other', 'cpms_accountant');
        App::membership_service()->create_membership($clinicId, $ownerId, 'cpms_accountant');
        App::membership_service()->create_membership($clinicId, $otherId, 'cpms_accountant');

        $notifId = $this->createAuthorizedExport($clinicId, $ownerId);
        self::assertGreaterThan(0, $notifId);

        $cross = $this->dispatchGet(self::NS . '/reports/exports/' . $notifId . '/download', $clinicId, $otherId);
        self::assertSame(404, $cross->get_status(), 'the recipient is part of the durable ownership check');

        $own = $this->dispatchGet(self::NS . '/reports/exports/' . $notifId . '/download', $clinicId, $ownerId);
        self::assertSame(200, $own->get_status(), 'the owner must still be able to download');
    }

    public function testDenyCrossClinicDownloadEvenWhenAuthorizedInBothClinics(): void
    {
        $orgId = $this->createOrganization('cross-dl');
        $clinicA = $this->createClinic($orgId, 'cross-dl-a');
        $clinicB = $this->createClinic($orgId, 'cross-dl-b');
        $actorId = $this->makeUser('rep_cross_dl', 'cpms_accountant');
        App::membership_service()->create_membership($clinicA, $actorId, 'cpms_accountant');
        App::membership_service()->create_membership($clinicB, $actorId, 'cpms_accountant');

        $notifId = $this->createAuthorizedExport($clinicA, $actorId);
        self::assertGreaterThan(0, $notifId);

        $cross = $this->dispatchGet(self::NS . '/reports/exports/' . $notifId . '/download', $clinicB, $actorId);
        self::assertSame(404, $cross->get_status(), 'a Clinic B scope must not serve a Clinic A artifact');

        $own = $this->dispatchGet(self::NS . '/reports/exports/' . $notifId . '/download', $clinicA, $actorId);
        self::assertSame(200, $own->get_status(), 'the owning Clinic must still serve the artifact');
    }

    public function testDenyDownloadAfterScopedExportWasRevoked(): void
    {
        $orgId = $this->createOrganization('dl-revoke');
        $clinicId = $this->createClinic($orgId, 'dl-revoke');
        $actorId = $this->makeUser('rep_dl_revoke', 'cpms_accountant');
        $memId = App::membership_service()->create_membership($clinicId, $actorId, 'cpms_accountant');

        $notifId = $this->createAuthorizedExport($clinicId, $actorId);
        self::assertGreaterThan(0, $notifId);

        App::membership_service()->set_capability($memId, RolesAndCapabilities::EXPORT, 'deny');
        self::assertFalse(
            $this->authz()->can($actorId, $clinicId, RolesAndCapabilities::EXPORT),
            'precondition: scoped EXPORT is denied again'
        );

        $denied = $this->dispatchGet(self::NS . '/reports/exports/' . $notifId . '/download', $clinicId, $actorId);
        self::assertSame(
            403,
            $denied->get_status(),
            'download must re-require scoped EXPORT (defective main returned ' . $denied->get_status() . ')'
        );
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errorCode($denied));
    }

    // ================= ALLOW =================

    public function testAllowReportReadAndPrintForScopedAuthorizedActor(): void
    {
        $orgId = $this->createOrganization('allow-read');
        $clinicId = $this->createClinic($orgId, 'allow-read');
        $actorId = $this->makeUser('rep_allow_read', 'cpms_accountant');
        App::membership_service()->create_membership($clinicId, $actorId, 'cpms_manager');

        $catalog = $this->dispatchGet(self::NS . '/reports', $clinicId, $actorId);
        self::assertSame(200, $catalog->get_status(), 'an authorized reader must still read the catalog');
        self::assertCount(12, $this->payload($catalog)['reports']);

        $print = $this->dispatchGet(self::NS . '/reports/revenue/print', $clinicId, $actorId);
        self::assertSame(200, $print->get_status(), 'an authorized reader must still print');
        self::assertStringContainsString('watermark', (string) $print->get_data());
    }

    public function testAllowAuthorizedExportLifecycleRequestJobListAndDownload(): void
    {
        $orgId = $this->createOrganization('allow-lifecycle');
        $clinicId = $this->createClinic($orgId, 'allow-lifecycle');
        $actorId = $this->makeUser('rep_allow_lifecycle', 'cpms_accountant');
        App::membership_service()->create_membership($clinicId, $actorId, 'cpms_accountant');

        $res = $this->dispatchPost(self::NS . '/reports/revenue/export', $clinicId, $actorId, [
            'from' => gmdate('Y-m-d'),
            'to' => gmdate('Y-m-d'),
        ]);
        self::assertSame(202, $res->get_status(), 'the authorized export request must stay accepted');
        $jobId = (int) $this->payload($res)['job_id'];
        self::assertGreaterThan(0, $jobId);

        App::dispatcher()->tick(50);
        $job = App::db()->fetchRow(
            'SELECT status FROM ' . App::db()->table('cpms_jobs') . ' WHERE id = %d',
            [$jobId]
        );
        self::assertNotNull($job);
        self::assertSame('success', (string) $job['status'], 'the authorized job must still complete');

        $notifId = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_notifications')
                . ' WHERE template = %s AND clinic_id = %d AND recipient_wp_user_id = %d ORDER BY id DESC LIMIT 1',
            [NotificationEvents::REPORT_EXPORT_READY, $clinicId, $actorId]
        );
        self::assertGreaterThan(0, $notifId, 'the requested artifact must still be published to the requester');

        $list = $this->payload($this->dispatchGet(self::NS . '/reports/exports', $clinicId, $actorId));
        self::assertCount(1, $list['exports']);
        self::assertSame($notifId, (int) $list['exports'][0]['notification_id']);
        self::assertSame('revenue', (string) $list['exports'][0]['type']);

        $download = $this->dispatchGet(self::NS . '/reports/exports/' . $notifId . '/download', $clinicId, $actorId);
        self::assertSame(200, $download->get_status(), 'the authorized download must still work');
        $csv = (string) $download->get_data();
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv, 'CSV BOM must be preserved');
        self::assertStringContainsString('revenue', $csv);
    }

    public function testAllowExplicitMembershipGrantWhereSemanticallyValid(): void
    {
        $orgId = $this->createOrganization('explicit-grant');
        $clinicId = $this->createClinic($orgId, 'explicit-grant');
        $actorId = $this->makeUser('rep_grant', 'cpms_accountant');

        $memId = App::membership_service()->create_membership($clinicId, $actorId, 'cpms_manager');
        self::assertFalse(
            $this->authz()->can($actorId, $clinicId, RolesAndCapabilities::EXPORT),
            'precondition: the preset alone does not grant EXPORT'
        );
        App::membership_service()->set_capability($memId, RolesAndCapabilities::EXPORT, 'grant');
        self::assertTrue(
            $this->authz()->can($actorId, $clinicId, RolesAndCapabilities::EXPORT),
            'precondition: the explicit membership grant takes effect'
        );

        $res = $this->dispatchPost(self::NS . '/reports/revenue/export', $clinicId, $actorId, [
            'from' => gmdate('Y-m-d'),
            'to' => gmdate('Y-m-d'),
        ]);
        self::assertSame(202, $res->get_status(), 'an explicit durable EXPORT grant must be honored');

        App::dispatcher()->tick(50);
        self::assertGreaterThan(0, $this->exportReadyCount($clinicId, $actorId));
    }

    public function testAllowSameActorAuthorizedIndependentlyInTwoClinics(): void
    {
        $orgId = $this->createOrganization('multi');
        $clinicA = $this->createClinic($orgId, 'multi-a');
        $clinicB = $this->createClinic($orgId, 'multi-b');
        $actorId = $this->makeUser('rep_multi', 'cpms_accountant');
        App::membership_service()->create_membership($clinicA, $actorId, 'cpms_accountant');
        App::membership_service()->create_membership($clinicB, $actorId, 'cpms_accountant');

        $today = gmdate('Y-m-d');
        $resA = $this->dispatchPost(self::NS . '/reports/revenue/export', $clinicA, $actorId, ['from' => $today, 'to' => $today]);
        $resB = $this->dispatchPost(self::NS . '/reports/revenue/export', $clinicB, $actorId, ['from' => $today, 'to' => $today]);
        self::assertSame(202, $resA->get_status(), 'the actor must be authorized in Clinic A');
        self::assertSame(202, $resB->get_status(), 'the same actor must be authorized independently in Clinic B');

        $payloadA = json_decode((string) $this->jobPayloadJson((int) $this->payload($resA)['job_id']), true);
        $payloadB = json_decode((string) $this->jobPayloadJson((int) $this->payload($resB)['job_id']), true);
        self::assertSame($clinicA, (int) $payloadA['clinic_id']);
        self::assertSame($clinicB, (int) $payloadB['clinic_id']);

        App::dispatcher()->tick(50);
        self::assertSame(1, $this->exportReadyCount($clinicA, $actorId), 'Clinic A artifact only');
        self::assertSame(1, $this->exportReadyCount($clinicB, $actorId), 'Clinic B artifact only');

        $listA = $this->payload($this->dispatchGet(self::NS . '/reports/exports', $clinicA, $actorId));
        self::assertCount(1, $listA['exports'], 'the list must stay inside the trusted Clinic');
    }

    // ================= Fixtures =================

    /**
     * Global coarse capability present + durable ACTIVE membership whose scoped
     * EXPORT permission is denied by an explicit membership deny.
     *
     * @return array{0: int, 1: int} [clinicId, actorId]
     */
    private function createScopedExportDeniedActor(): array
    {
        $orgId = $this->createOrganization('red');
        $clinicId = $this->createClinic($orgId, 'red');
        $actorId = $this->makeUser('rep_red', 'cpms_accountant');

        $memId = App::membership_service()->create_membership($clinicId, $actorId, 'cpms_manager');
        self::assertGreaterThan(0, $memId, 'a durable membership must be created');
        App::membership_service()->set_capability($memId, RolesAndCapabilities::EXPORT, 'deny');

        $user = get_userdata($actorId);
        self::assertNotFalse($user, 'the actor user must exist');
        // The coarse gate the current product path checks is fully satisfied.
        self::assertTrue($user->has_cap(RolesAndCapabilities::REPORT_READ), 'coarse REPORT_READ must be held');
        self::assertTrue($user->has_cap(RolesAndCapabilities::EXPORT), 'coarse EXPORT must be held');
        self::assertTrue(
            $user->has_cap(RolesAndCapabilities::FINANCE_READ),
            'coarse FINANCE_READ (the `revenue` type capability) must be held'
        );

        $active = App::membership_service()->active_membership_for($clinicId, $actorId);
        self::assertNotNull($active, 'a durable ACTIVE membership must exist');
        self::assertSame('active', (string) $active['status']);

        $authz = $this->authz();
        self::assertTrue(
            $authz->can($actorId, $clinicId, RolesAndCapabilities::REPORT_READ),
            'the denial must be specific: scoped REPORT_READ is still granted'
        );
        self::assertFalse(
            $authz->can($actorId, $clinicId, RolesAndCapabilities::EXPORT),
            'scoped EXPORT must be denied by the explicit membership deny'
        );

        // The trusted-Clinic boundary used by REST must accept this Clinic from
        // durable membership — so a denial below cannot be blamed on scope setup.
        $scope = (new TrustedClinicEstablisher(App::db(), new MembershipRepository(App::db())))
            ->establish($actorId, $clinicId);
        self::assertSame($clinicId, $scope->clinicId, 'the trusted Clinic must be established from durable membership');

        return [$clinicId, $actorId];
    }

    /**
     * Authorized export request through the real route — asserts the durable job
     * payload carries the server-created trusted Clinic, the actor and the type.
     *
     * @return int job id
     */
    private function enqueueAuthorizedExport(int $clinicId, int $actorId): int
    {
        $res = $this->dispatchPost(self::NS . '/reports/revenue/export', $clinicId, $actorId, [
            'from' => gmdate('Y-m-d'),
            'to' => gmdate('Y-m-d'),
        ]);
        self::assertSame(202, $res->get_status(), 'precondition: the authorized request is accepted');
        $jobId = (int) $this->payload($res)['job_id'];
        self::assertGreaterThan(0, $jobId, 'precondition: a durable job id was returned');

        $payload = json_decode($this->jobPayloadJson($jobId), true);
        self::assertIsArray($payload, 'the persisted payload must be readable');
        self::assertSame($clinicId, (int) ($payload['clinic_id'] ?? 0), 'the trusted Clinic must be persisted on the job');
        self::assertSame($actorId, (int) ($payload['actor_id'] ?? 0), 'the actor must be persisted on the job');
        self::assertSame('revenue', (string) ($payload['type'] ?? ''), 'the requested report type must be persisted');

        return $jobId;
    }

    /**
     * Creates an authorized export through the real route and runs the worker.
     *
     * @return int notification id of the produced artifact
     */
    private function createAuthorizedExport(int $clinicId, int $actorId): int
    {
        $today = gmdate('Y-m-d');
        $res = $this->dispatchPost(self::NS . '/reports/revenue/export', $clinicId, $actorId, [
            'from' => $today,
            'to' => $today,
        ]);
        self::assertSame(202, $res->get_status(), 'precondition: the export request must be authorized');

        App::dispatcher()->tick(50);

        return (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_notifications')
                . ' WHERE template = %s AND clinic_id = %d AND recipient_wp_user_id = %d ORDER BY id DESC LIMIT 1',
            [NotificationEvents::REPORT_EXPORT_READY, $clinicId, $actorId]
        );
    }

    private function createOrganization(string $suffix): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Org ' . $suffix . ' ' . $unique,
                'org-' . $suffix . '-' . $unique,
                'active',
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, "explicit organization $suffix must be created with a generated id");

        $status = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT status FROM ' . $wpdb->prefix . 'cpms_organizations WHERE id = %d LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $id
            )
        );
        self::assertSame('active', (string) $status, 'the explicit organization must be active');

        return $id;
    }

    private function createClinic(int $organizationId, string $suffix): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(3));

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $organizationId,
                'Clinic ' . $suffix . ' ' . $unique,
                'rep-authz-' . $suffix . '-' . $unique,
                'Asia/Tehran',
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, "clinic $suffix must be created with a generated id");

        $linked = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = %d LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $id
            )
        );
        self::assertSame($organizationId, $linked, 'the clinic must belong to the explicitly created organization');

        return $id;
    }

    private function makeUser(string $login, string $role): int
    {
        $unique = bin2hex(random_bytes(3));
        $userId = (int) wp_create_user($login . '_' . $unique, wp_generate_password(24), $login . '_' . $unique . '@rep.test');
        self::assertGreaterThan(0, $userId, "user $login must be created");

        $user = get_userdata($userId);
        self::assertNotFalse($user, "user $login must be readable");
        $user->set_role($role);

        return $userId;
    }

    private function authz(): AuthorizationService
    {
        return new AuthorizationService(new MembershipRepository(App::db()));
    }

    // ================= Helpers =================

    /**
     * @param array<string, mixed> $params
     */
    private function dispatchGet(string $route, int $clinicId, int $userId, array $params = []): WP_REST_Response
    {
        wp_set_current_user($userId);
        $request = new WP_REST_Request('GET', $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_header('X-CPMS-Clinic-Id', (string) $clinicId);

        return rest_do_request($request);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function dispatchPost(string $route, int $clinicId, int $userId, array $params = []): WP_REST_Response
    {
        wp_set_current_user($userId);
        $request = new WP_REST_Request('POST', $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_header('X-CPMS-Clinic-Id', (string) $clinicId);

        return rest_do_request($request);
    }

    /**
     * Durable `report.export` jobs for one trusted Clinic.
     *
     * `cpms_jobs` has NO clinic_id column — the tenant scope of a job lives in
     * its durable payload (`payload_json.clinic_id` / `payload_json.actor_id`),
     * which is exactly the server-created trusted Clinic persisted at enqueue.
     */
    private function reportExportJobCount(int $clinicId, int $actorId = 0): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . App::db()->table('cpms_jobs')
            . " WHERE type = %s AND CAST(JSON_EXTRACT(payload_json, '$.clinic_id') AS UNSIGNED) = %d";
        $params = ['report.export', $clinicId];

        if ($actorId > 0) {
            $sql .= " AND CAST(JSON_EXTRACT(payload_json, '$.actor_id') AS UNSIGNED) = %d";
            $params[] = $actorId;
        }

        return (int) App::db()->fetchValue($sql, $params); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private function exportReadyCount(int $clinicId, int $userId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_notifications')
                . ' WHERE template = %s AND clinic_id = %d AND recipient_wp_user_id = %d',
            [NotificationEvents::REPORT_EXPORT_READY, $clinicId, $userId]
        );
    }

    private function opLogCount(string $message): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_operational_logs') . ' WHERE message = %s',
            [$message]
        );
    }

    /**
     * Files physically stored for this Clinic. A baseline is taken by callers so
     * leftovers from earlier tests can never be mistaken for a produced artifact.
     */
    private function storedExportFileCount(int $clinicId): int
    {
        $dir = rtrim(LocalFileStorage::defaultBasePath(), '/') . '/' . $clinicId;
        if (!is_dir($dir)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    private function jobPayloadJson(int $jobId): string
    {
        return (string) App::db()->fetchValue(
            'SELECT payload_json FROM ' . App::db()->table('cpms_jobs') . ' WHERE id = %d',
            [$jobId]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(WP_REST_Response $res): array
    {
        $body = $res->get_data();
        if (is_array($body) && array_key_exists('data', $body)) {
            return is_array($body['data']) ? $body['data'] : [];
        }

        return is_array($body) ? $body : [];
    }

    private function errorCode(WP_REST_Response $res): string
    {
        $body = $res->get_data();
        if ($body instanceof \WP_Error) {
            return (string) $body->get_error_code();
        }

        return (string) (is_array($body) ? ($body['code'] ?? '') : '');
    }
}
