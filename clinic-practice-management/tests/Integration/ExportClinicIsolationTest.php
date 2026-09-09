<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Notifications\NotificationEvents;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * C6 — Export همان Clinic را از درخواست تا Job/فایل/اعلان/دانلود/purge حفظ می‌کند.
 *
 * Job از payload است نه کاربر جاری. purge هر ردیف را با Clinic خودش می‌بیند.
 */
final class ExportClinicIsolationTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    private int $clinicA = 1;

    private int $clinicB = 2;

    private int $locA;

    private int $locB;

    private int $accountantUserId;

    private int $clinicianId;

    private int $patientAId;

    private int $patientBId;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();

        $this->today = gmdate('Y-m-d');
        $this->locA = $this->primaryLocation($this->clinicA);
        $this->insertClinic($this->clinicB, 'export-iso-b');
        $this->locB = $this->insertLocation($this->clinicB, 'export-iso-b-loc');

        $doctorUserId = $this->makeUser('ex_iso_doc', 'cpms_doctor');
        $this->accountantUserId = $this->makeUser('ex_iso_acc', 'subscriber');
        $acc = get_userdata($this->accountantUserId);
        $acc?->add_cap('cpms_report_read');
        $acc?->add_cap('cpms_patient_read');
        $acc?->add_cap('cpms_export');

        $membership = App::membership_service();
        $membership->create_membership($this->clinicA, $doctorUserId, 'cpms_doctor');
        $membership->create_membership($this->clinicB, $doctorUserId, 'cpms_doctor');
        $membership->create_membership($this->clinicA, $this->accountantUserId, 'cpms_accountant');
        $membership->create_membership($this->clinicB, $this->accountantUserId, 'cpms_accountant');

        $this->clinicianId = $this->insertClinician($this->clinicA, $doctorUserId, 'Dr Export Dual');
        $this->patientAId = $this->insertPatient($this->clinicA, 'IsoA');
        $this->patientBId = $this->insertPatient($this->clinicB, 'IsoB');
        $this->insertVisit($this->clinicA, $this->locA, $this->clinicianId, $this->patientAId);
        $this->insertVisit($this->clinicB, $this->locB, $this->clinicianId, $this->patientBId);
    }

    protected function tearDown(): void
    {
        App::resetScope();
        parent::tearDown();
    }

    public function testRequestPutsTrustedClinicOnJobAndGenerateRunsWithoutHttpScope(): void
    {
        $this->activateClinic($this->clinicA);
        wp_set_current_user($this->accountantUserId);

        $queued = App::exportService()->request($this->accountantUserId, 'visits', $this->today, $this->today);
        $jobId = (int) $queued['job_id'];
        $this->assertGreaterThan(0, $jobId);

        $payloadJson = (string) App::db()->fetchValue(
            'SELECT payload_json FROM ' . App::db()->table('cpms_jobs') . ' WHERE id = %d',
            [$jobId]
        );
        $payload = json_decode($payloadJson, true);
        $this->assertIsArray($payload);
        $this->assertSame($this->clinicA, (int) $payload['clinic_id']);

        App::resetScope();
        App::dispatcher()->tick(50);

        $jobRow = App::db()->fetchRow(
            'SELECT status, last_error FROM ' . App::db()->table('cpms_jobs') . ' WHERE id = %d',
            [$jobId]
        );
        $this->assertSame('success', (string) ($jobRow['status'] ?? ''), (string) ($jobRow['last_error'] ?? ''));

        $row = App::db()->fetchRow(
            'SELECT id, clinic_id, payload_json FROM ' . App::db()->table('cpms_notifications') .
            ' WHERE template = %s AND recipient_wp_user_id = %d ORDER BY id DESC LIMIT 1',
            [NotificationEvents::REPORT_EXPORT_READY, $this->accountantUserId]
        );
        $this->assertNotNull($row);
        $this->assertSame($this->clinicA, (int) $row['clinic_id']);
        $export = json_decode((string) $row['payload_json'], true)['export'] ?? [];
        $this->assertStringStartsWith($this->clinicA . '/', (string) ($export['file_path'] ?? ''));

        $this->activateClinic($this->clinicA);
        $csv = (string) App::localFileStorage()->read((string) $export['file_path']);
        $this->assertStringContainsString('IsoA', $csv);
        $this->assertStringNotContainsString('IsoB', $csv);
    }

    public function testGenerateUsesPayloadClinicNotLeftoverHttpScope(): void
    {
        $this->activateClinic($this->clinicA);
        wp_set_current_user($this->accountantUserId);

        App::exportService()->generate([
            'actor_id' => $this->accountantUserId,
            'clinic_id' => $this->clinicB,
            'type' => 'visits',
            'from' => $this->today,
            'to' => $this->today,
        ]);

        $this->assertSame($this->clinicA, App::scope()->clinicId, 'HTTP scope باید بعد از Job برگردد');

        $row = App::db()->fetchRow(
            'SELECT clinic_id, payload_json FROM ' . App::db()->table('cpms_notifications') .
            ' WHERE template = %s AND recipient_wp_user_id = %d ORDER BY id DESC LIMIT 1',
            [NotificationEvents::REPORT_EXPORT_READY, $this->accountantUserId]
        );
        $this->assertNotNull($row);
        $this->assertSame($this->clinicB, (int) $row['clinic_id']);
        $export = json_decode((string) $row['payload_json'], true)['export'] ?? [];
        $this->assertStringStartsWith($this->clinicB . '/', (string) ($export['file_path'] ?? ''));
        $csv = (string) App::localFileStorage()->read((string) $export['file_path']);
        $this->assertStringContainsString('IsoB', $csv);
        $this->assertStringNotContainsString('IsoA', $csv);
    }

    public function testListAndDownloadStayInsideActiveClinic(): void
    {
        $this->activateClinic($this->clinicA);
        wp_set_current_user($this->accountantUserId);
        App::exportService()->generate([
            'actor_id' => $this->accountantUserId,
            'clinic_id' => $this->clinicA,
            'type' => 'visits',
            'from' => $this->today,
            'to' => $this->today,
        ]);
        $notifA = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_notifications') .
            ' WHERE template = %s AND clinic_id = %d AND recipient_wp_user_id = %d ORDER BY id DESC LIMIT 1',
            [NotificationEvents::REPORT_EXPORT_READY, $this->clinicA, $this->accountantUserId]
        );
        $this->assertGreaterThan(0, $notifA);

        $this->activateClinic($this->clinicB);
        $listB = $this->payload($this->dispatch('GET', self::NS . '/reports/exports'));
        $this->assertSame([], $listB['exports'] ?? ['sentinel']);

        $cross = $this->dispatch('GET', self::NS . '/reports/exports/' . $notifA . '/download');
        $this->assertSame(404, $cross->get_status());

        App::exportService()->generate([
            'actor_id' => $this->accountantUserId,
            'clinic_id' => $this->clinicB,
            'type' => 'visits',
            'from' => $this->today,
            'to' => $this->today,
        ]);
        $listB2 = $this->payload($this->dispatch('GET', self::NS . '/reports/exports'));
        $this->assertCount(1, $listB2['exports']);
        $this->assertSame('visits', $listB2['exports'][0]['type']);

        $this->activateClinic($this->clinicA);
        $listA = $this->payload($this->dispatch('GET', self::NS . '/reports/exports'));
        $this->assertCount(1, $listA['exports']);
        $this->assertSame($notifA, (int) $listA['exports'][0]['notification_id']);

        $download = $this->dispatch('GET', self::NS . '/reports/exports/' . $notifA . '/download');
        $this->assertSame(200, $download->get_status());
        $csv = (string) $download->get_data();
        $this->assertStringContainsString('IsoA', $csv);
        $this->assertStringNotContainsString('IsoB', $csv);
    }

    public function testGenerateFailsClosedWithoutClinicInPayload(): void
    {
        $this->activateClinic($this->clinicA);
        $this->expectException(ScopeRequiredException::class);
        App::exportService()->generate([
            'actor_id' => $this->accountantUserId,
            'type' => 'visits',
            'from' => $this->today,
            'to' => $this->today,
        ]);
    }

    public function testRequestFailsClosedWhenScopeAmbiguous(): void
    {
        App::resetScope();
        $this->expectException(ScopeRequiredException::class);
        App::exportService()->request($this->accountantUserId, 'visits', $this->today, $this->today);
    }

    public function testPurgeExpiredUsesEachRowClinicNotLiteralOne(): void
    {
        $this->activateClinic($this->clinicA);
        $storage = App::localFileStorage();
        $pathA = $storage->store('expired-a', $this->clinicA, 'csv');
        $pathB = $storage->store('expired-b', $this->clinicB, 'csv');
        $pathKeep = $storage->store('keep-a', $this->clinicA, 'csv');

        $oldA = $this->insertExportNotification($this->clinicA, $pathA);
        $oldB = $this->insertExportNotification($this->clinicB, $pathB);
        $keepA = $this->insertExportNotification($this->clinicA, $pathKeep, false);

        $old = gmdate('Y-m-d H:i:s', time() - 10 * 86400) . '.000';
        App::db()->query(
            'UPDATE ' . App::db()->table('cpms_notifications') . ' SET created_at = %s WHERE id IN (%d, %d)',
            [$old, $oldA, $oldB]
        );

        // حتی با Scope کلینیک A، ردیف کلینیک B هم باید پاک شود (نه clinic_id=1).
        $this->activateClinic($this->clinicA);
        $purged = App::exportService()->purgeExpired();
        $this->assertGreaterThanOrEqual(2, $purged);

        $this->assertNull(App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_notifications') . ' WHERE id = %d',
            [$oldA]
        ));
        $this->assertNull(App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_notifications') . ' WHERE id = %d',
            [$oldB]
        ));
        $this->assertNotNull(App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_notifications') . ' WHERE id = %d',
            [$keepA]
        ));
        $this->assertNull($storage->read($pathA));
        $this->assertNull($storage->read($pathB));
        $this->assertSame('keep-a', $storage->read($pathKeep));
    }

    // ================= Fixture =================

    private function activateClinic(int $clinicId): void
    {
        App::resetScope();
        ScopeContext::set(ClinicScope::forClinic($clinicId));
    }

    private function insertExportNotification(int $clinicId, string $path, bool $expiredMeta = true): int
    {
        $id = App::notificationService()->publishToUser(
            $clinicId,
            $this->accountantUserId,
            NotificationEvents::REPORT_EXPORT_READY,
            ['report_label' => 'iso', 'expires_at' => '1400-01-01'],
            'export-iso-' . $clinicId . '-' . bin2hex(random_bytes(3))
        );
        $this->assertNotNull($id);
        $row = App::db()->fetchRow(
            'SELECT payload_json FROM ' . App::db()->table('cpms_notifications') . ' WHERE id = %d',
            [$id]
        );
        $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
        $payload['export'] = [
            'type' => 'visits',
            'file_path' => $path,
            'file_name' => 'iso.csv',
            'expires_at' => $expiredMeta
                ? gmdate('Y-m-d H:i:s', time() - 86400)
                : gmdate('Y-m-d H:i:s', time() + 86400),
        ];
        App::db()->query(
            'UPDATE ' . App::db()->table('cpms_notifications') . ' SET payload_json = %s WHERE id = %d',
            [(string) json_encode($payload, JSON_UNESCAPED_UNICODE), $id]
        );

        return (int) $id;
    }

    private function insertClinic(int $id, string $slug): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $orgId = (int) $wpdb->get_var('SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $ok = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at)
                 VALUES (%d, %d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $id,
                $orgId,
                'Clinic ' . $slug,
                $slug,
                'Asia/Tehran',
                $now,
                $now
            )
        );
        self::assertNotFalse($ok);
    }

    private function primaryLocation(int $clinicId): int
    {
        global $wpdb;
        $id = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . $wpdb->prefix . 'cpms_locations WHERE clinic_id = %d AND is_primary = 1 LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId
            )
        );
        self::assertGreaterThan(0, $id);

        return $id;
    }

    private function insertLocation(int $clinicId, string $slug): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, 1, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $slug,
                $slug,
                'Asia/Tehran',
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    private function insertClinician(int $homeClinicId, int $wpUserId, string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (%d, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $homeClinicId,
                $name,
                $wpUserId,
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    private function insertPatient(int $clinicId, string $lastName): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $seq = random_int(1000, 999999);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                'MR-EX-' . $seq . '-' . $clinicId,
                'Patient',
                $lastName,
                '0918' . sprintf('%07d', $seq),
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    private function insertVisit(int $clinicId, int $locationId, int $clinicianId, int $patientId): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_visits
                     (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, waiting_since, called_at, active, created_at, updated_at)
                 VALUES (%d, %d, %d, %d, "walk_in", "waiting", %s, %s, %s, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $locationId,
                $clinicianId,
                $patientId,
                $this->today,
                $this->today . ' 10:00:00.000',
                $this->today . ' 10:00:00.000',
                $this->today . ' 10:05:00.000',
                $now,
                $now
            )
        );
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login . bin2hex(random_bytes(3)), 'pass-12345', $login . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function dispatch(string $method, string $route, array $body = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

        return rest_do_request($request);
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
}
