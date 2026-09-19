<?php
declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Application\Notifications\NotificationService;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\NotificationRepository;
use ClinicCore\Settings\Settings;
use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;

/**
 * M-2 fu.reminder — RED: starvation via 100 rejected prefix rows.
 *
 * Proves bounded sweep without durable cursor starves valid row at position 101.
 *
 * Topology:
 * - one main Clinic + Location (Asia/Tehran) with valid IANA
 * - one other Clinic + Location for mismatch
 * - 100 invalid follow_ups ordered before target, each clinic_mismatch (visit from other clinic)
 *   -> deterministically rejected, remain pending
 * - 1 valid follow_up after them, due tomorrow in Location-local calendar
 * - invoke handler 3 times as real job would, with fixed reference_utc
 * - valid must remain unprocessed without continuation (RED)
 */
final class FollowUpReminderStarvationRedTest extends WP_UnitTestCase
{
    private int $orgId = 0;
    private int $clinicId = 0;
    private int $otherClinicId = 0;
    private int $locId = 0;
    private int $otherLocId = 0;
    private int $clinicianId = 0;
    private int $patientId = 0;
    private int $validVisitId = 0;
    private int $mismatchVisitId = 0;

    private const TZ = 'Asia/Tehran';

    private function resetAppCaches(): void
    {
        $refClass = new \ReflectionClass(App::class);
        foreach (['db','op','audit','jobs','rate','loginRateLimiter','idem','settingsFactory','installationSettings','migrations','dispatcher','providers','vault','smsService','licenseGate','visitService'] as $propName) {
            if ($refClass->hasProperty($propName)) {
                $prop = $refClass->getProperty($propName);
                $prop->setAccessible(true);
                $prop->setValue(null, null);
            }
        }
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        if (method_exists(App::class, 'settingsFactory')) {
            try {
                App::settingsFactory()->reset();
            } catch (\Throwable $e) {
            }
        }
        try {
            $factory = App::settingsFactory();
            $ref = new \ReflectionClass($factory);
            if ($ref->hasProperty('instances')) {
                $p = $ref->getProperty('instances');
                $p->setAccessible(true);
                $p->setValue($factory, []);
            }
        } catch (\Throwable $e) {
        }
        // Clear FollowUpReminderHandler static candidateWindowCache
        try {
            $hRef = new \ReflectionClass(\ClinicCore\Application\Jobs\FollowUpReminderHandler::class);
            if ($hRef->hasProperty('candidateWindowCache')) {
                $p = $hRef->getProperty('candidateWindowCache');
                $p->setAccessible(true);
                $p->setValue(null, []);
            }
        } catch (\Throwable $e) {
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->resetAppCaches();
        $this->buildFixture();
        $this->purgeJobs();
        $this->resetAppCaches();
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        $this->purgeJobs();
        $this->purgeFixture();
        $this->resetAppCaches();
        parent::tearDown();
    }

    private function buildFixture(): void
    {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();

        $orgSlug = 'fu-starv-org-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, %s, %s, %s)',
            'FU Starv Org',
            $orgSlug,
            'active',
            $now,
            $now
        ));
        $this->orgId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->orgId, 'org inserted');

        $clinicSlug = 'fu-starv-clinic-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            $this->orgId,
            'FU Starv Clinic',
            $clinicSlug,
            self::TZ,
            $now,
            $now
        ));
        $this->clinicId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->clinicId);

        $otherClinicSlug = 'fu-starv-other-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            $this->orgId,
            'FU Starv Other Clinic',
            $otherClinicSlug,
            self::TZ,
            $now,
            $now
        ));
        $this->otherClinicId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->otherClinicId);

        $locSlug = 'fu-starv-loc-' . bin2hex(random_bytes(2));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, 1, 1, %s, %s)',
            $this->clinicId,
            'FU Starv Loc',
            $locSlug,
            self::TZ,
            $now,
            $now
        ));
        $this->locId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->locId);

        $otherLocSlug = 'fu-starv-other-loc-' . bin2hex(random_bytes(2));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, 1, 1, %s, %s)',
            $this->otherClinicId,
            'FU Starv Other Loc',
            $otherLocSlug,
            self::TZ,
            $now,
            $now
        ));
        $this->otherLocId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->otherLocId);

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, is_active, created_at, updated_at) VALUES (%d, %s, 1, %s, %s)',
            $this->clinicId,
            'Dr FU Starv',
            $now,
            $now
        ));
        $this->clinicianId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->clinicianId);

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)',
            $this->clinicId,
            'MR-FU-STARV-' . bin2hex(random_bytes(3)),
            'FUStarv',
            'Patient',
            '0912000' . random_int(1000, 9999),
            'active',
            $now,
            $now
        ));
        $this->patientId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->patientId);

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, created_at, updated_at) VALUES (%d, %d, %d, %d, %s, %s, %s, %s, %s, %s)',
            $this->clinicId,
            $this->locId,
            $this->clinicianId,
            $this->patientId,
            'walk_in',
            'checked_in',
            gmdate('Y-m-d'),
            $now,
            $now,
            $now
        ));
        $this->validVisitId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->validVisitId);

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, created_at, updated_at) VALUES (%d, %d, %d, %d, %s, %s, %s, %s, %s, %s)',
            $this->otherClinicId,
            $this->otherLocId,
            $this->clinicianId,
            $this->patientId,
            'walk_in',
            'checked_in',
            gmdate('Y-m-d'),
            $now,
            $now,
            $now
        ));
        $this->mismatchVisitId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->mismatchVisitId);

        Settings::flushCache();
        $settings = new Settings($db, $this->clinicId, App::audit());
        // Always-open: 24:00 is invalid per parseHour => fail-open true (avoids flakiness at hour 23 Tehran)
        $settings->set('notif.quiet_hours_start', '00:00');
        $settings->set('notif.quiet_hours_end', '24:00');
        Settings::flushCache();
        App::resetScope();
        SystemClinicResolver::flush();
        App::settingsFactory()->reset();
    }

    private function purgeFixture(): void
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        if ($this->orgId > 0) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_follow_ups') . ' WHERE clinic_id IN (%d,%d)', $this->clinicId, $this->otherClinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_visit_status_history') . ' WHERE visit_id IN (SELECT id FROM ' . $db->table('cpms_visits') . ' WHERE clinic_id IN (%d,%d))', $this->clinicId, $this->otherClinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_visits') . ' WHERE clinic_id IN (%d,%d)', $this->clinicId, $this->otherClinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_notifications') . ' WHERE clinic_id IN (%d,%d)', $this->clinicId, $this->otherClinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_sms_messages') . ' WHERE clinic_id IN (%d,%d)', $this->clinicId, $this->otherClinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_patients') . ' WHERE clinic_id IN (%d,%d)', $this->clinicId, $this->otherClinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinicians') . ' WHERE clinic_id IN (%d,%d)', $this->clinicId, $this->otherClinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id IN (%d,%d)', $this->clinicId, $this->otherClinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_settings') . ' WHERE clinic_id IN (%d,%d)', $this->clinicId, $this->otherClinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id IN (%d,%d)', $this->clinicId, $this->otherClinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_organizations') . ' WHERE id = %d', $this->orgId));
        }
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
        Settings::flushCache();
        App::resetScope();
        SystemClinicResolver::flush();
        if (method_exists(App::class, 'settingsFactory')) {
            App::settingsFactory()->reset();
        }
    }

    private function purgeJobs(): void
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query('DELETE FROM ' . $db->table('cpms_jobs') . ' WHERE type IN (\'visits.no_show\',\'slots.generate\',\'holds.expire\',\'cleanup.otp\',\'cleanup.rate_limits\',\'cleanup.idem\',\'cleanup.oplog\',\'handwriting.gc\',\'notif.dispatch\',\'appt.reminder\',\'fu.reminder\',\'license.refresh\',\'backup.run\',\'report.export\',\'sms.send\')');
    }

    private function locationLocalTomorrow(DateTimeImmutable $utc, string $tz): string
    {
        $zone = new DateTimeZone($tz);
        $local = $utc->setTimezone($zone);
        return $local->modify('+1 day')->format('Y-m-d');
    }

    private function buildHandler(?DateTimeImmutable $referenceUtc = null, bool $withQueue = false): object
    {
        $db = App::db();
        $op = App::op();

        try {
            $ref = new \ReflectionClass(\ClinicCore\Application\Jobs\FollowUpReminderHandler::class);
            $ctor = $ref->getConstructor();
            if ($ctor !== null) {
                $params = $ctor->getParameters();
                if (count($params) >= 4) {
                    $firstType = $params[1]->getType();
                    if ($firstType instanceof \ReflectionNamedType && $firstType->getName() === \ClinicCore\Settings\SettingsFactory::class) {
                        $utcClosure = $referenceUtc === null ? null : static fn (): DateTimeImmutable => $referenceUtc;
                        $queue = $withQueue ? App::jobs() : null;
                        // New signature: (db, settingsFactory, sms, notificationFactory, op, queue, utcNow)
                        return new \ClinicCore\Application\Jobs\FollowUpReminderHandler(
                            $db,
                            App::settingsFactory(),
                            App::smsService(),
                            static fn (int $clinicId): NotificationService => new NotificationService(
                                $db,
                                new NotificationRepository($db),
                                new MembershipRepository($db),
                                App::settingsFactory(),
                                static fn (): int => $clinicId,
                                $op
                            ),
                            $op,
                            $queue,
                            $utcClosure
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        App::replaceExplicitScope(ClinicScope::forClinic($this->clinicId));
        $settings = App::settings();
        $sms = App::smsService();
        $notifications = new NotificationService(
            $db,
            new NotificationRepository($db),
            new MembershipRepository($db),
            App::settingsFactory(),
            // همان Clinicِ معتبرِ صریحی که دو خط بالاتر بسته شد.
            static fn (): int => $this->clinicId,
            $op
        );
        return new \ClinicCore\Application\Jobs\FollowUpReminderHandler(
            $db,
            $settings,
            $sms,
            $notifications,
            $op
        );
    }

    public function testStarvationVia100RejectedPrefix(): void
    {
        global $wpdb;
        $db = App::db();
        $nowSql = $db->nowUtcSql();

        $referenceUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $tomorrow = $this->locationLocalTomorrow($referenceUtc, self::TZ);

        // Insert 100 invalid prefix rows (clinic mismatch)
        $prefixIds = [];
        for ($i = 0; $i < 100; $i++) {
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_follow_ups (clinic_id, visit_id, patient_id, clinician_id, is_needed, suggested_date, interval_days, reason, status, created_at) VALUES (%d, %d, %d, %d, 1, %s, 30, %s, %s, %s)',
                $this->clinicId,
                $this->mismatchVisitId,
                $this->patientId,
                $this->clinicianId,
                $tomorrow,
                'Starv prefix ' . $i,
                'pending',
                $nowSql
            ));
            $id = (int) $wpdb->insert_id;
            self::assertGreaterThan(0, $id, 'prefix follow_up inserted ' . $i);
            $prefixIds[] = $id;
        }

        // Valid target after prefix
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_follow_ups (clinic_id, visit_id, patient_id, clinician_id, is_needed, suggested_date, interval_days, reason, status, created_at) VALUES (%d, %d, %d, %d, 1, %s, 30, %s, %s, %s)',
            $this->clinicId,
            $this->validVisitId,
            $this->patientId,
            $this->clinicianId,
            $tomorrow,
            'Starv valid target',
            'pending',
            $nowSql
        ));
        $validId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $validId, 'valid target inserted');

        // Verify ordering and counts
        $countAll = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_follow_ups') . ' WHERE clinic_id = %d AND status = %s AND suggested_date = %s', $this->clinicId, 'pending', $tomorrow));
        self::assertSame(101, $countAll, 'total 101 due follow_ups (100 prefix + 1 valid)');

        $maxPrefix = max($prefixIds);
        self::assertLessThan($validId, $maxPrefix, 'valid must be after prefix by id ASC');
        self::assertSame(100, count($prefixIds), 'prefix really 100');

        // Ensure prefix rows are indeed rejected (clinic mismatch)
        $sampleVisitClinic = (int) $wpdb->get_var($wpdb->prepare('SELECT clinic_id FROM ' . $db->table('cpms_visits') . ' WHERE id = %d', $this->mismatchVisitId));
        self::assertSame($this->otherClinicId, $sampleVisitClinic, 'mismatch visit belongs to other clinic');
        self::assertNotSame($this->clinicId, $sampleVisitClinic);

        // --- GREEN path: use durable continuation via JobQueue ---
        // Enqueue root fu.reminder job (empty payload) with referenceUtc as runAt
        // The handler will use rootReferenceUtc() which will be close to referenceUtc, but we also test continuation chain preserves reference_utc
        // For deterministic test, we will run handler directly with queue to generate continuation chain,
        // then process continuation payloads manually, mimicking real job execution.

        $this->purgeJobs();
        $this->resetAppCaches();

        // First, test direct handler without queue still shows starvation if no continuation (for RED evidence)
        // But for GREEN, we use queue

        $queue = App::jobs();
        $queue->enqueue('fu.reminder', [], $referenceUtc, 4, 3);

        // Run tick multiple times to process root + continuation chain
        // App::runTick handles scheduler and dispatcher with GET_LOCK
        $ticks = 0;
        $maxTicks = 5;
        for ($t = 0; $t < $maxTicks; $t++) {
            $processed = App::runTick(20);
            $ticks++;
            if ($processed === -1) {
                // lock held, retry
                continue;
            }
            // Check if valid already reminded
            $reminded = $wpdb->get_var($wpdb->prepare('SELECT reminder_sent_at FROM ' . $db->table('cpms_follow_ups') . ' WHERE id = %d', $validId));
            if ($reminded !== null) {
                break;
            }
        }

        $remindedValid = $wpdb->get_var($wpdb->prepare('SELECT reminder_sent_at FROM ' . $db->table('cpms_follow_ups') . ' WHERE id = %d', $validId));
        $notifValid = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_notifications') . ' WHERE clinic_id = %d AND template = %s AND recipient_patient_id = %d', $this->clinicId, 'followup_reminder', $this->patientId));

        $prefixStillPending = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_follow_ups') . ' WHERE id IN (' . implode(',', array_map('intval', $prefixIds)) . ') AND reminder_sent_at IS NULL');

        // Check continuation payloads reference_utc preservation
        $jobs = $db->fetchAll('SELECT payload_json FROM ' . $db->table('cpms_jobs') . ' WHERE type = %s ORDER BY id DESC LIMIT 10', ['fu.reminder']);
        $refUtcs = [];
        foreach ($jobs as $j) {
            $p = json_decode($j['payload_json'] ?? '', true);
            if (is_array($p) && isset($p['reference_utc'])) {
                $refUtcs[] = $p['reference_utc'];
            }
        }
        $uniqueRefUtcs = array_unique($refUtcs);
        $refUtcPreserved = count($uniqueRefUtcs) <= 1; // all continuation share same refUtc or no continuation left

        $diag = sprintf(
            'Starvation GREEN: clinic=%d loc=%d tz=%s tomorrow=%s refUtc=%s prefixCount=%d maxPrefix=%d validId=%d validAfterPrefix=%s ticks=%d remindedValid=%s notifValid=%d prefixStillPending=%d refUtcPreserved=%s refUtcs=%s jobs=%s',
            $this->clinicId,
            $this->locId,
            self::TZ,
            $tomorrow,
            $referenceUtc->format('c'),
            count($prefixIds),
            $maxPrefix,
            $validId,
            $validId > $maxPrefix ? 'yes' : 'no',
            $ticks,
            $remindedValid ? 'set' : 'null',
            $notifValid,
            $prefixStillPending,
            $refUtcPreserved ? 'yes' : 'no',
            json_encode($refUtcs),
            json_encode($jobs)
        );

        // GREEN expects valid to be reminded despite 100 rejected prefix rows via durable continuation
        self::assertNotNull($remindedValid, 'Valid target must be reminded despite 100 rejected prefix rows - starvation must be fixed via durable continuation. ' . $diag);
        self::assertSame(1, $notifValid, 'Valid target notification must exist after fixing starvation. ' . $diag);
        self::assertSame(100, $prefixStillPending, 'Prefix rows must remain pending (rejected) and not block progress after fix. ' . $diag);
        self::assertTrue($refUtcPreserved, 'reference_utc must remain constant across continuation chain. ' . $diag);

        // Cleanup
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_follow_ups') . ' WHERE clinic_id = %d', $this->clinicId));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_notifications') . ' WHERE clinic_id = %d', $this->clinicId));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_sms_messages') . ' WHERE clinic_id = %d', $this->clinicId));
        $this->purgeJobs();
    }

    public function testMalformedContinuationFailsClosed(): void
    {
        global $wpdb;
        $db = App::db();

        $this->purgeJobs();
        $this->resetAppCaches();

        $handler = $this->buildHandler(null, true);

        $malformedPayloads = [
            ['continuation' => true, 'version' => 1, 'reference_utc' => 'invalid', 'cursor' => ['id' => 1]],
            ['continuation' => true, 'version' => 999, 'reference_utc' => gmdate('Y-m-d\TH:i:s\Z'), 'cursor' => ['id' => 1]],
            ['continuation' => true, 'version' => 1, 'reference_utc' => gmdate('Y-m-d\TH:i:s\Z'), 'cursor' => ['id' => -5]],
            ['continuation' => true, 'version' => 1, 'reference_utc' => gmdate('Y-m-d\TH:i:s\Z'), 'cursor' => ['id' => 0]],
            ['continuation' => false, 'version' => 1, 'reference_utc' => gmdate('Y-m-d\TH:i:s\Z'), 'cursor' => ['id' => 1]],
            ['clinic_id' => 123, 'continuation' => true, 'version' => 1, 'reference_utc' => gmdate('Y-m-d\TH:i:s\Z'), 'cursor' => ['id' => 1]],
        ];

        foreach ($malformedPayloads as $idx => $payload) {
            $thrown = false;
            $code = '';
            try {
                $handler($payload);
            } catch (\ClinicCore\Application\Jobs\JobPayloadInvalidException $e) {
                $thrown = true;
                $code = $e->errorCode;
            } catch (\Throwable $e) {
                $thrown = true;
                $code = $e->getMessage();
            }
            self::assertTrue($thrown, 'Malformed payload must throw, idx=' . $idx . ' payload=' . json_encode($payload));
            if ($code !== '') {
                self::assertStringContainsString('JOB_PAYLOAD_INVALID', $code, 'Error code must be JOB_PAYLOAD_INVALID for malformed payload idx=' . $idx);
            }
        }

        // Ensure no continuation was enqueued for malformed payloads
        $count = (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_jobs') . ' WHERE type = %s', ['fu.reminder']);
        self::assertSame(0, $count, 'Malformed payload must not enqueue continuation');

        $this->purgeJobs();
    }
}
