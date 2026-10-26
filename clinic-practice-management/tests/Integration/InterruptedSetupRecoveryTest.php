<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\CpmsAdminMenu;
use ClinicCore\Admin\CpmsSetupWizard;
use ClinicCore\Admin\FirstPracticeSetupPage;
use ClinicCore\Application\Onboarding\FirstPracticeSetupException;
use ClinicCore\Application\Onboarding\FirstPracticeSetupService;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * Interrupted first-practice setup recovery + unified wizard + wizard
 * Clinic-local authorization (real-MySQL/MariaDB integration contract).
 *
 * Historical defect chain covered here:
 *  1. The wizard GET handler used to persist `setup.started_at` (a
 *     SETTING_UPDATE audit row for the Clinic) on a plain page view.
 *  2. Migration 0026 conservatively backfilled `first_setup_claimed_at`
 *     for ANY Clinic with ANY audit row — including that setup-only
 *     history — permanently locking out a zero-membership installation
 *     whose administrator had merely opened the wizard.
 *  3. Two visible setup entries existed (wizard + first-practice page)
 *     and wizard booking/SMS writes accepted a generic WP capability
 *     without effective Clinic-local CONFIG authorization.
 *
 * Recovery is fail-closed: it is available only when the persisted audit
 * history is *strictly* recognized setup-only history, there is no prior
 * bootstrap, no membership of any status, no clinical/financial/staffing
 * or custom-role activity, exactly one Clinic under an active
 * Organization, and the actor is an authenticated WP installation
 * administrator who explicitly confirms with a valid nonce.
 */
final class InterruptedSetupRecoveryTest extends WP_UnitTestCase
{
    private int $clinic_id;
    private string $clinic_name;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();

        $row = App::db()->fetchRow(
            'SELECT id, name, first_setup_claimed_at FROM ' . App::db()->table('cpms_clinics') . ' ORDER BY id ASC LIMIT 1'
        );
        self::assertIsArray($row);
        self::assertSame(1, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinics')));
        self::assertNull($row['first_setup_claimed_at'], 'precondition: seed Clinic is unclaimed');
        $this->clinic_id   = (int) $row['id'];
        $this->clinic_name = (string) $row['name'];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET  = [];
        ScopeContext::clear();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        wp_set_current_user(0);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Defect 1: wizard GET must not mutate settings or audit.
    // ------------------------------------------------------------------

    public function testWizardGetPerformsZeroMutations(): void
    {
        $admin = $this->makeUser('rec_get_', 'administrator');
        cpms_test_seed_membership($admin, $this->clinic_id, 'cpms_manager');
        wp_set_current_user($admin);
        App::replaceExplicitScope(App::scope());

        $settings_before = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_settings') . ' WHERE clinic_id = %d',
            [$this->clinic_id]
        );
        $audit_before = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
            [$this->clinic_id]
        );

        $_GET['step'] = 'booking';
        ob_start();
        try {
            CpmsSetupWizard::render();
        } finally {
            $html = (string) ob_get_clean();
        }
        self::assertStringContainsString('cpms-wizard', $html . 'cpms-wizard'); // render executed

        self::assertSame($settings_before, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_settings') . ' WHERE clinic_id = %d',
            [$this->clinic_id]
        ), 'GET render must not write settings');
        self::assertSame($audit_before, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
            [$this->clinic_id]
        ), 'GET render must not write audit rows');
        $started = App::db()->fetchValue(
            'SELECT value_json FROM ' . App::db()->table('cpms_settings') . ' WHERE clinic_id = %d AND `key` = %s',
            [$this->clinic_id, 'setup.started_at']
        );
        self::assertNull($started, 'GET render must not persist setup.started_at');
    }

    // ------------------------------------------------------------------
    // Defects 4+5: one visible setup menu; no unauthorized Clinic form.
    // ------------------------------------------------------------------

    public function testOnlyWizardSetupSubmenuIsVisible(): void
    {
        $admin = $this->makeUser('rec_menu_', 'administrator');
        wp_set_current_user($admin);

        $GLOBALS['menu']    = [];
        $GLOBALS['submenu'] = [];
        CpmsAdminMenu::menu();
        CpmsSetupWizard::menu();
        FirstPracticeSetupPage::menu();
        // پنهان‌سازیِ نمایشی در محصول روی `admin_head` انجام می‌شود (پس از بررسیِ
        // دسترسیِ هسته، وگرنه URL مستقیم 403 می‌شود). همان گامِ واقعی را صدا می‌زنیم
        // تا این قرارداد با «فهرستِ زیرمنویی که کاربر می‌بیند» سنجیده شود.
        CpmsAdminMenu::prune_hidden_submenu_rows();

        $sub   = $GLOBALS['submenu']['cpms-dashboard'] ?? [];
        $slugs = array_map(static fn ($r) => $r[2] ?? '', $sub);
        self::assertContains(CpmsSetupWizard::PAGE_SLUG, $slugs, 'wizard is the single visible setup entry');
        self::assertNotContains(FirstPracticeSetupPage::PAGE_SLUG, $slugs, 'first-practice page must not add a second visible setup menu');

        self::assertStringContainsString(
            'page=' . CpmsSetupWizard::PAGE_SLUG,
            CpmsAdminMenu::setup_url(),
            'canonical setup URL must be the wizard'
        );
    }

    public function testWizardDoesNotRenderSubmittableClinicBookingOrSmsFormsWithoutClinicLocalConfig(): void
    {
        // WP administrator with the generic CONFIG capability but NO
        // Clinic membership at all (zero-membership, not first-setup state:
        // marker already consumed so the onboarding branch does not apply).
        $admin = $this->makeUser('rec_form_', 'administrator');
        wp_set_current_user($admin);
        $this->markClaimed();

        foreach (['clinic', 'booking', 'sms'] as $step) {
            $html = $this->renderWizardStep($step);
            self::assertStringNotContainsString(
                'name="step" value="' . $step . '"',
                $html,
                "step {$step} must not render a submittable form without Clinic-local CONFIG"
            );
        }
    }

    // ------------------------------------------------------------------
    // Defect 6: booking/SMS writes require Clinic-local CONFIG.
    // ------------------------------------------------------------------

    public function testSaveBookingAndSmsDeniedWithoutClinicLocalConfig(): void
    {
        $admin = $this->makeUser('rec_authz_', 'administrator');
        wp_set_current_user($admin);
        $this->establishScopeForClinic();

        $settings = App::settings();
        $duration_before = $settings->get('booking.duration_default_min');
        $provider_before = $settings->get('sms.provider', '');
        $err = CpmsSetupWizard::saveBooking($settings, [
            'duration' => 30, 'capacity' => 2, 'lead' => 3, 'future' => 30, 'cancel' => 12,
        ], $admin);
        self::assertNotSame('', $err, 'booking write without Clinic-local CONFIG must be denied');
        self::assertSame($duration_before, $settings->get('booking.duration_default_min'), 'denied booking write must persist nothing');

        $err = CpmsSetupWizard::saveSms($settings, [
            'sms_provider' => 'generic_api', 'sms_sender' => '1000',
        ], $admin);
        self::assertNotSame('', $err, 'SMS write without Clinic-local CONFIG must be denied');
        self::assertSame($provider_before, $settings->get('sms.provider', ''), 'denied SMS write must persist nothing');
    }

    public function testSaveBookingAndSmsSucceedForExistingClinicManager(): void
    {
        $admin = $this->makeUser('rec_mgr_', 'administrator');
        cpms_test_seed_membership($admin, $this->clinic_id, 'cpms_manager');
        wp_set_current_user($admin);
        $this->establishScopeForClinic();

        $settings = App::settings();
        $err = CpmsSetupWizard::saveBooking($settings, [
            'duration' => 30, 'capacity' => 2, 'lead' => 3, 'future' => 30, 'cancel' => 12,
        ], $admin);
        self::assertSame('', $err);
        self::assertSame(30, (int) $settings->get('booking.duration_default_min'));

        $err = CpmsSetupWizard::saveSms($settings, [
            'sms_provider' => 'generic_api', 'sms_sender' => '1000',
        ], $admin);
        self::assertSame('', $err);
        self::assertSame('generic_api', (string) $settings->get('sms.provider'));
    }

    // ------------------------------------------------------------------
    // Recovery: eligible interrupted setup (setup-only audit history).
    // ------------------------------------------------------------------

    public function testRecoverySucceedsForStrictlySetupOnlyInterruptedSetup(): void
    {
        $this->resetClinicActivityFixture();
        $admin = $this->makeUser('rec_ok_', 'administrator');
        $this->seedSetupOnlyHistory($admin);
        $this->markClaimed(); // migration 0026 historical backfill result

        $service = App::first_practice_setup_service();
        $candidate = $service->recovery_candidate();
        self::assertNotNull($candidate, 'strictly setup-only interrupted setup must be recovery-eligible');
        self::assertSame($this->clinic_id, (int) $candidate['clinic_id']);

        $result = $service->recover($admin, $this->clinic_id, 'مطب بازیابی‌شده', '021-111', 'تهران');
        self::assertGreaterThan(0, $result['membership_id']);
        self::assertGreaterThan(0, $result['audit_id']);

        $membership = App::membership_service()->membership_for($this->clinic_id, $admin);
        self::assertIsArray($membership);
        self::assertSame('cpms_manager', (string) $membership['role_key']);

        // Marker is NOT reset: it stays consumed (monotonic one-shot).
        self::assertNotNull(App::db()->fetchValue(
            'SELECT first_setup_claimed_at FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d',
            [$this->clinic_id]
        ));
        // Mandatory recovery audit exists; historical setup audit is preserved.
        self::assertSame(1, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND clinic_id = %d',
            [FirstPracticeSetupService::RECOVERY_AUDIT_ACTION, $this->clinic_id]
        ));
        self::assertGreaterThanOrEqual(1, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . " WHERE action = 'SETTING_UPDATE' AND clinic_id = %d",
            [$this->clinic_id]
        ), 'historical setup audit rows must never be deleted');

        // One-shot: a second recovery is denied.
        $second = $this->makeUser('rec_ok2_', 'administrator');
        try {
            $service->recover($second, $this->clinic_id, 'مطب دوم');
            self::fail('second recovery must be denied');
        } catch (FirstPracticeSetupException $e) {
            self::assertSame(FirstPracticeSetupException::CODE_RECOVERY_NOT_ELIGIBLE, $e->reason_code());
        }
    }

    /** Legacy wizard saveClinic wrote exactly these four keys before Phase 4.
     * Keep the same strict audit shape, actor continuity and no-activity gates.
     */
    public function testLegacyClinicSetupHistoryCanBeContinuedOnlyByOriginalAdmin(): void
    {
        $this->resetClinicActivityFixture();
        $admin = $this->makeUser('rec_legacy_', 'administrator');
        $other = $this->makeUser('rec_legacy_other_', 'administrator');
        $this->seedSetupOnlyHistory($admin);
        foreach (['name' => 'مطب قدیمی', 'address' => 'تهران', 'phone' => '021-111', 'timezone' => 'Asia/Tehran'] as $suffix => $value) {
            $key = 'setup.clinic.' . $suffix;
            App::audit()->log(
                'SETTING_UPDATE', ['wp_user_id' => $admin, 'role' => 'administrator'], 'setting', null, null,
                ['setting' => $key, 'value' => ''], ['setting' => $key, 'value' => $value],
                ['op' => 'config_update', 'clinic_id' => $this->clinic_id], $this->clinic_id
            );
            App::db()->query(
                'INSERT INTO ' . App::db()->table('cpms_settings') . ' (clinic_id, `key`, value_json, updated_by_wp_user_id, updated_at) VALUES (%d, %s, %s, %d, %s)',
                [$this->clinic_id, $key, wp_json_encode($value), $admin, App::db()->nowUtcSql()]
            );
        }
        $this->markClaimed();
        $service = App::first_practice_setup_service();
        self::assertNull($service->recovery_candidate($other));
        $this->assertRecoveryDenied($service, $other);
        self::assertNotNull($service->recovery_candidate($admin), 'recognized legacy clinic profile history is setup-only, not unknown clinical activity');
        $before = App::db()->fetchAll('SELECT * FROM ' . App::db()->table('cpms_settings') . ' WHERE clinic_id = %d ORDER BY id', [$this->clinic_id]);
        $result = $service->recover($admin, $this->clinic_id, 'مطب بازیابی قدیمی');
        self::assertGreaterThan(0, $result['membership_id']);
        self::assertSame($before, App::db()->fetchAll('SELECT * FROM ' . App::db()->table('cpms_settings') . ' WHERE clinic_id = %d ORDER BY id', [$this->clinic_id]), 'legacy settings are historical evidence, not rewritten canonical data');
    }

    public function testRealRecordTickInsertThenUpdatePersistsNullThenZero(): void
    {
        $this->resetClinicActivityFixture();
        App::replaceExplicitScope(\ClinicCore\Application\Scope\ClinicScope::forClinic($this->clinic_id));
        self::assertSame(0, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_settings') . ' WHERE clinic_id = %d AND `key` = %s',
            [$this->clinic_id, 'jobs.last_tick_at']
        ));
        App::recordTick();
        $inserted = $this->heartbeatRow();
        self::assertNotNull($inserted);
        self::assertNull($inserted['updated_by_wp_user_id'], 'wpdb::insert preserves the omitted system writer as NULL');
        self::assertSame('INTEGER', $inserted['value_type']);
        self::assertGreaterThan(0, json_decode($inserted['value_json'], true));

        // Exercise the real App -> Settings -> wpdb -> numeric column path,
        // not a handcrafted zero-writer INSERT or a mocked wpdb conversion.
        App::recordTick();
        $updated = $this->heartbeatRow();
        self::assertNotNull($updated);
        self::assertSame('0', (string) $updated['updated_by_wp_user_id'], 'Settings UPDATE binds NULL through %s; WordPress SQL mode stores zero in BIGINT');
        self::assertSame('INTEGER', $updated['value_type']);
        self::assertGreaterThan(0, json_decode($updated['value_json'], true));
        self::assertSame(0, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d', [$this->clinic_id]
        ), 'runtime ticks must not fabricate human ownership evidence');
        fwrite(STDOUT, 'HEARTBEAT_PERSISTENCE=' . wp_json_encode([
            'server' => App::db()->fetchValue('SELECT VERSION()'),
            'sql_mode' => App::db()->fetchValue('SELECT @@SESSION.sql_mode'),
            'insert_writer' => $inserted['updated_by_wp_user_id'],
            'insert_php_type' => gettype($inserted['updated_by_wp_user_id']),
            'update_writer' => $updated['updated_by_wp_user_id'],
            'update_php_type' => gettype($updated['updated_by_wp_user_id']),
            'json_type' => $updated['value_type'],
        ]) . PHP_EOL);
    }

    public function testOwnerShapedSixEventHistoryWithNaturalZeroWriterHeartbeatRecovers(): void
    {
        $this->resetClinicActivityFixture();
        $admin = $this->makeUser('rec_host_', 'administrator');
        $other = $this->makeUser('rec_host_other_', 'administrator');
        $settings = new \ClinicCore\Settings\Settings(App::db(), $this->clinic_id, App::audit());
        foreach ([
            ['setup.started_at', '2026-10-01T00:00:00+00:00'],
            ['setup.started_at', '2026-10-01T00:01:00+00:00'],
            ['setup.current_step', 'welcome'], ['setup.current_step', 'booking'],
            ['setup.completed', true], ['setup.completed', false],
        ] as [$key, $value]) {
            $settings->set($key, $value, $admin);
        }
        $settings->set('jobs.last_tick_at', 1790841600);
        self::assertNull($this->heartbeatRow()['updated_by_wp_user_id']);
        $settings->set('jobs.last_tick_at', 1790841601);
        self::assertSame('0', (string) $this->heartbeatRow()['updated_by_wp_user_id']);
        self::assertSame('INTEGER', $this->heartbeatRow()['value_type']);
        $metadata = App::db()->fetchAll(
            'SELECT `key`, JSON_TYPE(value_json) AS value_type, updated_by_wp_user_id FROM ' . App::db()->table('cpms_settings') . ' WHERE clinic_id = %d ORDER BY `key`',
            [$this->clinic_id]
        );
        self::assertSame(['jobs.last_tick_at', 'setup.completed', 'setup.current_step', 'setup.started_at'], array_column($metadata, 'key'));
        self::assertSame(['INTEGER', 'BOOLEAN', 'STRING', 'STRING'], array_column($metadata, 'value_type'));
        self::assertSame([0, $admin, $admin, $admin], array_map('intval', array_column($metadata, 'updated_by_wp_user_id')));
        self::assertSame(6, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d AND actor_wp_user_id = %d AND action = %s',
            [$this->clinic_id, $admin, 'SETTING_UPDATE']
        ));
        $this->markClaimed();
        $service = App::first_practice_setup_service();
        self::assertNull($service->recovery_candidate($other));
        $this->assertRecoveryDenied($service, $other);
        self::assertNotNull($service->recovery_candidate($admin), 'Owner-shaped six-event history plus a naturally zero-written INTEGER heartbeat must remain recoverable by its sole historical administrator');
        $before = App::db()->fetchAll('SELECT * FROM ' . App::db()->table('cpms_settings') . ' WHERE clinic_id = %d ORDER BY id', [$this->clinic_id]);
        $service->recover($admin, $this->clinic_id, 'مطب مطابق شاهد میزبان');
        self::assertSame(1, $this->membershipCount());
        self::assertSame($before, App::db()->fetchAll('SELECT * FROM ' . App::db()->table('cpms_settings') . ' WHERE clinic_id = %d ORDER BY id', [$this->clinic_id]));
        $this->assertRecoveryDenied($service, $admin);
        self::assertSame(1, $this->membershipCount(), 'replay must never provision another membership');
    }

    /** @return array<string, array{0: int|null}> */
    public static function systemHeartbeatWriters(): array
    {
        return ['insert-null' => [null], 'update-zero' => [0]];
    }

    /** @dataProvider systemHeartbeatWriters */
    public function testSystemHeartbeatIsInertAndDoesNotBlockOriginalAdministrator(?int $writer): void
    {
        $this->resetClinicActivityFixture();
        $admin = $this->makeUser('rec_tick_', 'administrator');
        $other = $this->makeUser('rec_tick_other_', 'administrator');
        $this->seedSetupOnlyHistory($admin);
        $this->markClaimed();
        App::db()->wpdb()->insert(App::db()->table('cpms_settings'), [
            'clinic_id' => $this->clinic_id, 'key' => 'jobs.last_tick_at',
            'value_json' => '1790841600', 'updated_by_wp_user_id' => $writer,
            'updated_at' => App::db()->nowUtcSql(),
        ]);
        $service = App::first_practice_setup_service();
        self::assertNotNull($service->recovery_candidate($admin));
        self::assertNull($service->recovery_candidate($other));
        $this->assertRecoveryDenied($service, $other);
        $before = App::db()->fetchAll('SELECT * FROM ' . App::db()->table('cpms_settings') . ' WHERE clinic_id = %d ORDER BY id', [$this->clinic_id]);
        $service->recover($admin, $this->clinic_id, 'مطب با ضربان سیستم');
        self::assertSame($before, App::db()->fetchAll('SELECT * FROM ' . App::db()->table('cpms_settings') . ' WHERE clinic_id = %d ORDER BY id', [$this->clinic_id]), 'heartbeat is preserved, never deleted or rewritten');
    }

    /** @dataProvider systemHeartbeatWriters */
    public function testHeartbeatAloneCannotEstablishOwnership(?int $writer): void
    {
        $this->resetClinicActivityFixture();
        $admin = $this->makeUser('rec_tick_only_', 'administrator');
        $this->markClaimed();
        App::db()->wpdb()->insert(App::db()->table('cpms_settings'), [
            'clinic_id' => $this->clinic_id, 'key' => 'jobs.last_tick_at',
            'value_json' => '1790841600', 'updated_by_wp_user_id' => $writer,
            'updated_at' => App::db()->nowUtcSql(),
        ]);
        $service = App::first_practice_setup_service();
        self::assertNull($service->recovery_candidate($admin));
        $this->assertRecoveryDenied($service, $admin);
    }

    public function testHumanAttributedOrMalformedHeartbeatRemainsUnknownHistory(): void
    {
        $this->resetClinicActivityFixture();
        $admin = $this->makeUser('rec_tick_bad_', 'administrator');
        $this->seedSetupOnlyHistory($admin);
        $this->markClaimed();
        App::db()->query(
            'INSERT INTO ' . App::db()->table('cpms_settings') . ' (clinic_id, `key`, value_json, updated_by_wp_user_id, updated_at) VALUES (%d, %s, %s, NULL, %s)',
            [$this->clinic_id, 'jobs.last_tick_at', '1790841600', App::db()->nowUtcSql()]
        );
        $cases = [[$admin, '1790841600']];
        foreach ([null, 0] as $writer) {
            foreach (['"1790841600"', '{}', '[]', 'null', 'true', 'false', '0', '-1', '1790841600.5', '1.7908416e9'] as $json) {
                $cases[] = [$writer, $json];
            }
        }
        foreach ($cases as [$writer, $json]) {
            App::db()->wpdb()->update(App::db()->table('cpms_settings'), ['value_json' => $json, 'updated_by_wp_user_id' => $writer], ['clinic_id' => $this->clinic_id, 'key' => 'jobs.last_tick_at']);
            $service = App::first_practice_setup_service();
            self::assertNull($service->recovery_candidate($admin));
            $this->assertRecoveryDenied($service, $admin);
        }
    }

    public function testZeroWriterCannotExemptArbitraryRuntimeSetting(): void
    {
        $this->resetClinicActivityFixture();
        $admin = $this->makeUser('rec_zero_key_', 'administrator');
        $this->seedSetupOnlyHistory($admin);
        $this->markClaimed();
        App::db()->wpdb()->insert(App::db()->table('cpms_settings'), [
            'clinic_id' => $this->clinic_id, 'key' => 'jobs.last_tick_at.extra',
            'value_json' => '1790841600', 'updated_by_wp_user_id' => 0,
            'updated_at' => App::db()->nowUtcSql(),
        ]);
        $service = App::first_practice_setup_service();
        self::assertNull($service->recovery_candidate($admin));
        $this->assertRecoveryDenied($service, $admin);
    }

    public function testRecoveryDeniedWithUnknownAuditAction(): void
    {
        $admin = $this->makeUser('rec_unk_', 'administrator');
        $this->seedSetupOnlyHistory($admin);
        App::audit()->log(
            'PATIENT_RECORD_VIEW',
            ['wp_user_id' => $admin, 'role' => 'administrator'],
            'patient',
            1,
            1,
            null,
            null,
            [],
            $this->clinic_id
        );
        $this->markClaimed();

        $service = App::first_practice_setup_service();
        self::assertNull($service->recovery_candidate(), 'unknown audit history must fail closed');
        $this->assertRecoveryDenied($service, $admin);
    }

    public function testRecoveryDeniedWithArbitrarySettingUpdateKey(): void
    {
        $admin = $this->makeUser('rec_key_', 'administrator');
        $this->seedSetupOnlyHistory($admin);
        // Arbitrary SETTING_UPDATE (not a wizard setup key) is NOT safe evidence.
        App::audit()->log(
            'SETTING_UPDATE',
            ['wp_user_id' => $admin, 'role' => 'administrator'],
            'setting',
            null,
            null,
            ['setting' => 'otp.ttl_sec', 'value' => 120],
            ['setting' => 'otp.ttl_sec', 'value' => 30],
            ['op' => 'config_update', 'clinic_id' => $this->clinic_id],
            $this->clinic_id
        );
        $this->markClaimed();

        $service = App::first_practice_setup_service();
        self::assertNull($service->recovery_candidate(), 'arbitrary SETTING_UPDATE must fail closed');
        $this->assertRecoveryDenied($service, $admin);
    }

    public function testRecoveryDeniedAfterPriorSuccessfulBootstrap(): void
    {
        $admin = $this->makeUser('rec_prior_', 'administrator');
        $this->seedSetupOnlyHistory($admin);
        App::audit()->log(
            FirstPracticeSetupService::AUDIT_ACTION,
            ['wp_user_id' => $admin, 'role' => 'wp_administrator'],
            FirstPracticeSetupService::RESOURCE_TYPE,
            $this->clinic_id,
            null,
            ['name' => $this->clinic_name],
            ['name' => 'مطب قبلی'],
            ['membership_id' => 1, 'role_key' => 'cpms_manager'],
            $this->clinic_id
        );
        $this->markClaimed();

        $service = App::first_practice_setup_service();
        self::assertNull($service->recovery_candidate(), 'prior successful bootstrap must fail closed');
        $this->assertRecoveryDenied($service, $admin);
    }

    public function testRecoveryDeniedWithSuspendedMembership(): void
    {
        $admin = $this->makeUser('rec_susp_', 'administrator');
        $member = $this->makeUser('rec_susp_m_', 'subscriber');
        $this->seedSetupOnlyHistory($admin);
        $membership_id = cpms_test_seed_membership($member, $this->clinic_id, 'cpms_manager');
        App::db()->query(
            'UPDATE ' . App::db()->table('cpms_clinic_memberships') . " SET status = 'suspended' WHERE id = %d",
            [$membership_id]
        );
        $this->markClaimed();

        $service = App::first_practice_setup_service();
        self::assertNull($service->recovery_candidate(), 'any membership (incl. suspended) must fail closed');
        $this->assertRecoveryDenied($service, $admin);
    }

    public function testRecoveryDeniedWithClinicalHistory(): void
    {
        $admin = $this->makeUser('rec_clin_', 'administrator');
        $this->seedSetupOnlyHistory($admin);
        $now = App::db()->nowUtcSql();
        App::db()->query(
            'INSERT INTO ' . App::db()->table('cpms_clinicians') . ' (clinic_id, wp_user_id, full_name, is_active, created_at, updated_at) VALUES (%d, NULL, %s, 1, %s, %s)',
            [$this->clinic_id, 'پزشک تاریخی', $now, $now]
        );
        $this->markClaimed();

        $service = App::first_practice_setup_service();
        self::assertNull($service->recovery_candidate(), 'staffing/clinical history must fail closed');
        $this->assertRecoveryDenied($service, $admin);
    }

    public function testRecoveryDeniedWithMultipleClinics(): void
    {
        $admin = $this->makeUser('rec_multi_', 'administrator');
        $this->seedSetupOnlyHistory($admin);
        $this->markClaimed();
        $org_id = (int) App::db()->fetchValue(
            'SELECT organization_id FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d',
            [$this->clinic_id]
        );
        $now = App::db()->nowUtcSql();
        App::db()->query(
            'INSERT INTO ' . App::db()->table('cpms_clinics') . ' (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            [$org_id, 'مطب دوم', 'rec-second-' . bin2hex(random_bytes(2)), 'Asia/Tehran', $now, $now]
        );

        $service = App::first_practice_setup_service();
        self::assertNull($service->recovery_candidate(), 'multi-Clinic installations must fail closed');
        $this->assertRecoveryDenied($service, $admin);
    }

    public function testRecoveryDeniedWithoutAnyAuditEvidence(): void
    {
        $admin = $this->makeUser('rec_none_', 'administrator');
        // Marker consumed but zero audit history: provenance is ambiguous
        // (could be membership-based backfill with rows later removed).
        $this->markClaimed();

        $service = App::first_practice_setup_service();
        self::assertNull($service->recovery_candidate(), 'absence of members alone is not trustworthy evidence');
        $this->assertRecoveryDenied($service, $admin);
    }

    public function testRecoveryDeniedForNonAdministrator(): void
    {
        $admin = $this->makeUser('rec_ev_', 'administrator');
        $this->seedSetupOnlyHistory($admin);
        $this->markClaimed();

        $subscriber = $this->makeUser('rec_sub_', 'subscriber');
        try {
            App::first_practice_setup_service()->recover($subscriber, $this->clinic_id, 'مطب بازیابی');
            self::fail('non-administrator recovery must be denied');
        } catch (FirstPracticeSetupException $e) {
            self::assertSame(FirstPracticeSetupException::CODE_NOT_ADMINISTRATOR, $e->reason_code());
        }
        self::assertSame(0, $this->membershipCount());
    }

    // ------------------------------------------------------------------
    // Recovery route: nonce + explicit confirmation are mandatory.
    // ------------------------------------------------------------------

    public function testRecoveryRouteRequiresValidNonceAndExplicitConfirmation(): void
    {
        $this->resetClinicActivityFixture();
        $admin = $this->makeUser('rec_route_', 'administrator');
        wp_set_current_user($admin);
        $this->seedSetupOnlyHistory($admin);
        $this->markClaimed();

        // Missing confirmation.
        $_POST = [
            '_wpnonce'   => wp_create_nonce('cpms_first_practice_recovery'),
            'clinic_id'  => (string) $this->clinic_id,
            'practice_name' => 'مطب مسیر',
        ];
        $location = $this->captureRedirect(static function (): void {
            FirstPracticeSetupPage::handle_recovery();
        });
        self::assertStringContainsString('page=' . CpmsSetupWizard::PAGE_SLUG, $location);
        self::assertSame(0, $this->membershipCount(), 'unconfirmed recovery must provision nothing');

        // Invalid nonce.
        $_POST = [
            '_wpnonce'   => 'invalid',
            'clinic_id'  => (string) $this->clinic_id,
            'confirm_recovery' => '1',
            'practice_name' => 'مطب مسیر',
        ];
        $location = $this->captureRedirect(static function (): void {
            FirstPracticeSetupPage::handle_recovery();
        });
        self::assertStringContainsString('page=' . CpmsSetupWizard::PAGE_SLUG, $location);
        self::assertSame(0, $this->membershipCount(), 'bad-nonce recovery must provision nothing');

        // Valid nonce + explicit confirmation succeeds and returns safely.
        $_POST = [
            '_wpnonce'   => wp_create_nonce('cpms_first_practice_recovery'),
            'clinic_id'  => (string) $this->clinic_id,
            'confirm_recovery' => '1',
            'practice_name' => 'مطب مسیر',
        ];
        $location = $this->captureRedirect(static function (): void {
            FirstPracticeSetupPage::handle_recovery();
        });
        self::assertSame(1, $this->membershipCount(), 'confirmed recovery with valid nonce must provision exactly one membership');
        self::assertStringNotContainsString(FirstPracticeSetupPage::PAGE_SLUG, $location, 'successful recovery must not loop back to the legacy page');
    }

    // ------------------------------------------------------------------
    // Recovery: mandatory audit failure rolls back everything.
    // ------------------------------------------------------------------

    /** @dataProvider systemHeartbeatWriters */
    public function testRecoveryFailedAuditRollsBack(?int $writer): void
    {
        $this->resetClinicActivityFixture();
        $admin = $this->makeUser('rec_rb_', 'administrator');
        $this->seedSetupOnlyHistory($admin);
        $this->markClaimed();
        App::db()->wpdb()->insert(App::db()->table('cpms_settings'), [
            'clinic_id' => $this->clinic_id, 'key' => 'jobs.last_tick_at',
            'value_json' => '1790841600', 'updated_by_wp_user_id' => $writer,
            'updated_at' => App::db()->nowUtcSql(),
        ]);
        self::assertNotNull(App::first_practice_setup_service()->recovery_candidate($admin), 'fault injection must reach an otherwise eligible recovery');
        $name_before = (string) App::db()->fetchValue(
            'SELECT name FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d',
            [$this->clinic_id]
        );

        $history_before = App::db()->fetchAll('SELECT * FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d ORDER BY id', [$this->clinic_id]);
        $settings_before = App::db()->fetchAll('SELECT * FROM ' . App::db()->table('cpms_settings') . ' WHERE clinic_id = %d ORDER BY id', [$this->clinic_id]);
        $audit_attempts = 0;
        $fail_audit = static function (string $query) use (&$audit_attempts): string {
            if (stripos($query, 'cpms_audit_logs') !== false && stripos($query, 'INSERT') !== false) {
                ++$audit_attempts;
                return 'INSERT INTO `cpms_missing_audit_probe` (`x`) VALUES (1)';
            }

            return $query;
        };
        add_filter('query', $fail_audit);
        try {
            App::first_practice_setup_service()->recover($admin, $this->clinic_id, 'مطب ناموفق');
            self::fail('failed mandatory audit must abort recovery');
        } catch (\Throwable) {
            self::assertTrue(true);
        } finally {
            remove_filter('query', $fail_audit);
        }

        self::assertSame(1, $audit_attempts, 'both system-writer cases must actually reach the mandatory audit insert');
        self::assertSame($history_before, App::db()->fetchAll('SELECT * FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d ORDER BY id', [$this->clinic_id]), 'failed audit must preserve all prior history');
        self::assertSame($settings_before, App::db()->fetchAll('SELECT * FROM ' . App::db()->table('cpms_settings') . ' WHERE clinic_id = %d ORDER BY id', [$this->clinic_id]), 'failed recovery must preserve NULL/zero heartbeat evidence');
        self::assertSame(0, $this->membershipCount(), 'failed audit must roll back the membership');
        self::assertSame($name_before, (string) App::db()->fetchValue(
            'SELECT name FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d',
            [$this->clinic_id]
        ), 'failed audit must roll back the practice-info update');
    }

    public function testConcurrentRecoverySecondActorCannotEnterCriticalSection(): void
    {
        $this->resetClinicActivityFixture();
        $admin = $this->makeUser('rec_conc_', 'administrator');
        $audit_ids = $this->seedSetupOnlyHistory($admin);
        $this->markClaimed();
        $clinic_id = $this->clinic_id;

        // Same two-connection protocol as the established claim concurrency
        // test: commit the parent test transaction so an independent
        // connection can hold the durable Clinic row lock.
        global $wpdb;
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $conn = $this->freshMysqli();
        try {
            self::assertTrue($conn->query('START TRANSACTION'));
            $res = $conn->query(
                'SELECT first_setup_claimed_at FROM ' . $this->table('cpms_clinics') .
                ' WHERE id = ' . $clinic_id . ' FOR UPDATE'
            );
            self::assertNotFalse($res, 'external lock acquisition failed: ' . $conn->error);

            // The service transaction takes the same Clinic row lock first;
            // with a short lock-wait timeout it must fail and write nothing.
            $wpdb->query('SET SESSION innodb_lock_wait_timeout = 2'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $threw = false;
            try {
                App::first_practice_setup_service()->recover($admin, $clinic_id, 'مطب هم‌زمان');
            } catch (\Throwable) {
                $threw = true;
            } finally {
                $wpdb->query('SET SESSION innodb_lock_wait_timeout = DEFAULT'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            }
            self::assertTrue($threw, 'recovery must not enter the critical section while the Clinic row is locked');
            self::assertSame(0, $this->membershipCount(), 'blocked recovery must provision nothing');

            self::assertTrue($conn->query('ROLLBACK'));

            // After the lock is released, exactly one recovery succeeds.
            $result = App::first_practice_setup_service()->recover($admin, $clinic_id, 'مطب هم‌زمان');
            self::assertGreaterThan(0, $result['membership_id']);
            self::assertSame(1, $this->membershipCount());
        } finally {
            $conn->query('ROLLBACK');
            $conn->close();
            // Manual committed-fixture cleanup (parent transaction was
            // committed above): remove only rows this test created and
            // restore the unclaimed seed shape for later tests.
            $wpdb->query('DELETE FROM ' . $this->table('cpms_clinic_memberships') . ' WHERE clinic_id = ' . $clinic_id); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'DELETE FROM ' . $this->table('cpms_audit_logs') . ' WHERE clinic_id = ' . $clinic_id .
                " AND action = '" . FirstPracticeSetupService::RECOVERY_AUDIT_ACTION . "'"
            );
            foreach ($audit_ids as $audit_id) {
                $wpdb->query('DELETE FROM ' . $this->table('cpms_audit_logs') . ' WHERE id = ' . (int) $audit_id); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            }
            $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'UPDATE ' . $this->table('cpms_clinics') .
                ' SET first_setup_claimed_at = NULL, name = ' . $wpdb->prepare('%s', $this->clinic_name) . // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                ' WHERE id = ' . $clinic_id
            );
            // The cleanup above runs in a fresh transaction opened after
            // the explicit COMMIT (autocommit stays off under the WP test
            // harness); commit it explicitly or tearDown's ROLLBACK would
            // resurrect the claimed marker and membership for later tests.
            $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
    }

    // ------------------------------------------------------------------
    // Actor continuity (cross-administrator takeover regression).
    // ------------------------------------------------------------------

    public function testCrossAdminRecoveryDeniedAndOriginalAdminStillSucceeds(): void
    {
        $this->resetClinicActivityFixture();
        $admin_a = $this->makeUser('rec_a_', 'administrator');
        $admin_b = $this->makeUser('rec_b_', 'administrator');
        $this->seedSetupOnlyHistory($admin_a);
        $this->markClaimed();

        $service = App::first_practice_setup_service();
        // Admin B did not start this setup: recovery is a takeover and is denied.
        $this->assertRecoveryDenied($service, $admin_b);
        self::assertSame(0, $this->membershipCount(), 'cross-admin attempt must not mutate memberships');

        // The historical actor can still complete their own interrupted setup.
        $result = $service->recover($admin_a, $this->clinic_id, 'مطب مدیر اول');
        self::assertGreaterThan(0, $result['membership_id']);
        $membership = App::membership_service()->membership_for($this->clinic_id, $admin_a);
        self::assertIsArray($membership);
        self::assertSame('cpms_manager', (string) $membership['role_key']);
        self::assertNull(App::membership_service()->membership_for($this->clinic_id, $admin_b), 'Admin B must never gain membership');
    }

    public function testMixedAdminHistoryDeniedForBothAdmins(): void
    {
        $admin_a = $this->makeUser('rec_mix_a_', 'administrator');
        $admin_b = $this->makeUser('rec_mix_b_', 'administrator');
        $this->seedSetupOnlyHistory($admin_a);
        // A second administrator also wrote a recognized setup key: ownership is ambiguous.
        App::audit()->log(
            'SETTING_UPDATE',
            ['wp_user_id' => $admin_b, 'role' => 'administrator'],
            'setting',
            null,
            null,
            ['setting' => 'setup.current_step', 'value' => 'booking'],
            ['setting' => 'setup.current_step', 'value' => 'sms'],
            ['op' => 'config_update', 'clinic_id' => $this->clinic_id],
            $this->clinic_id
        );
        $this->markClaimed();

        $service = App::first_practice_setup_service();
        self::assertNull($service->recovery_candidate(), 'mixed A/B history must fail closed');
        $this->assertRecoveryDenied($service, $admin_a);
        $this->assertRecoveryDenied($service, $admin_b);
    }

    public function testRecoveryDeniedWhenHistoricalActorAccountDeleted(): void
    {
        $admin_a = $this->makeUser('rec_del_a_', 'administrator');
        $admin_b = $this->makeUser('rec_del_b_', 'administrator');
        $this->seedSetupOnlyHistory($admin_a);
        $this->markClaimed();

        require_once ABSPATH . 'wp-admin/includes/user.php';
        self::assertTrue((bool) wp_delete_user($admin_a));
        self::assertFalse(get_userdata($admin_a));

        $service = App::first_practice_setup_service();
        self::assertNull($service->recovery_candidate(), 'deleted historical actor must fail closed');
        $this->assertRecoveryDenied($service, $admin_b);
    }

    public function testRecoveryDeniedWithInvalidHistoricalActorId(): void
    {
        $admin_b = $this->makeUser('rec_inv_b_', 'administrator');
        // Structurally valid setup rows attributed to a non-existent user id.
        App::audit()->log(
            'SETTING_UPDATE',
            ['wp_user_id' => 999999, 'role' => 'administrator'],
            'setting',
            null,
            null,
            ['setting' => 'setup.started_at', 'value' => ''],
            ['setting' => 'setup.started_at', 'value' => '2026-10-01T00:00:00+00:00'],
            ['op' => 'config_update', 'clinic_id' => $this->clinic_id],
            $this->clinic_id
        );
        $this->markClaimed();

        $service = App::first_practice_setup_service();
        self::assertNull($service->recovery_candidate(), 'invalid historical actor id must fail closed');
        $this->assertRecoveryDenied($service, $admin_b);
    }

    public function testRecoveryDeniedWithSystemOnlyHistory(): void
    {
        $admin_b = $this->makeUser('rec_sys_b_', 'administrator');
        // Unattributed/system setup rows establish no ownership at all.
        App::audit()->log(
            'SETTING_UPDATE',
            null,
            'setting',
            null,
            null,
            ['setting' => 'setup.started_at', 'value' => ''],
            ['setting' => 'setup.started_at', 'value' => '2026-10-01T00:00:00+00:00'],
            ['op' => 'config_update', 'clinic_id' => $this->clinic_id],
            $this->clinic_id
        );
        $this->markClaimed();

        $service = App::first_practice_setup_service();
        self::assertNull($service->recovery_candidate(), 'system-only history must fail closed');
        $this->assertRecoveryDenied($service, $admin_b);
    }

    public function testRecoveryDeniedWhenHistoricalActorNoLongerAdmin(): void
    {
        $admin_a = $this->makeUser('rec_dem_a_', 'administrator');
        $admin_b = $this->makeUser('rec_dem_b_', 'administrator');
        $this->seedSetupOnlyHistory($admin_a);
        $this->markClaimed();

        $user_a = get_userdata($admin_a);
        self::assertNotFalse($user_a);
        $user_a->set_role('subscriber');

        $service = App::first_practice_setup_service();
        self::assertNull($service->recovery_candidate(), 'historical actor without manage_options must fail closed');
        $this->assertRecoveryDenied($service, $admin_b);
    }

    public function testRecoveryDeniedWhenPersistedSettingActorContradictsAuditActor(): void
    {
        $this->resetClinicActivityFixture();
        $admin_a = $this->makeUser('rec_set_a_', 'administrator');
        $admin_b = $this->makeUser('rec_set_b_', 'administrator');
        $this->seedSetupOnlyHistory($admin_a);
        // Persisted setup setting last written by a different administrator:
        // ownership evidence contradicts the audit actor.
        $now = App::db()->nowUtcSql();
        App::db()->query(
            'INSERT INTO ' . App::db()->table('cpms_settings') . ' (clinic_id, `key`, value_json, updated_by_wp_user_id, updated_at) VALUES (%d, %s, %s, %d, %s)',
            [$this->clinic_id, 'setup.current_step', '"booking"', $admin_b, $now]
        );
        $this->markClaimed();

        $service = App::first_practice_setup_service();
        self::assertNull($service->recovery_candidate(), 'contradictory setting actor must fail closed');
        $this->assertRecoveryDenied($service, $admin_a);
        $this->assertRecoveryDenied($service, $admin_b);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function freshMysqli(): \mysqli
    {
        $host = defined('DB_HOST') ? (string) DB_HOST : '127.0.0.1';
        $port = null;
        if (str_contains($host, ':')) {
            [$host, $portPart] = explode(':', $host, 2);
            if (ctype_digit($portPart)) {
                $port = (int) $portPart;
            }
        }
        $user = defined('DB_USER') ? (string) DB_USER : 'root';
        $pass = defined('DB_PASSWORD') ? (string) DB_PASSWORD : '';
        $name = defined('DB_NAME') ? (string) DB_NAME : 'wordpress_test';

        return new \mysqli($host, $user, $pass, $name, $port);
    }

    private function table(string $short): string
    {
        global $table_prefix;

        return $table_prefix . $short;
    }

    private function assertRecoveryDenied(FirstPracticeSetupService $service, int $actor): void
    {
        $memberships_before = $this->membershipCount();
        try {
            $service->recover($actor, $this->clinic_id, 'مطب بازیابی');
            self::fail('recovery must be denied');
        } catch (FirstPracticeSetupException $e) {
            self::assertSame(FirstPracticeSetupException::CODE_RECOVERY_NOT_ELIGIBLE, $e->reason_code());
        }
        self::assertSame($memberships_before, $this->membershipCount(), 'denied recovery must provision nothing');
    }


    /**
     * Other suites' two-connection protocols COMMIT synthetic fixtures that
     * can leak activity rows for the seed Clinic into this test's view.
     * Clear them inside this test's transaction (rolled back afterwards)
     * so the interrupted-setup fixture is deterministic. Test-only.
     */
    private function resetClinicActivityFixture(): void
    {
        // Leaked rows may be FK-referenced by other leaked child rows
        // (e.g. schedule slots); test-fixture cleanup only.
        App::db()->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (['cpms_patients', 'cpms_clinicians', 'cpms_appointments', 'cpms_visits', 'cpms_invoices', 'cpms_payments', 'cpms_services', 'cpms_custom_role_defs'] as $table) {
                App::db()->query('DELETE FROM ' . App::db()->table($table) . ' WHERE clinic_id = %d', [$this->clinic_id]);
            }
        } finally {
            App::db()->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function membershipCount(): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d',
            [$this->clinic_id]
        );
    }

    /**
     * Exactly the audit shape Settings::auditChange() writes for wizard setup keys.
     *
     * @return list<int> created audit row ids
     */
    private function seedSetupOnlyHistory(int $admin): array
    {
        $ids   = [];
        $ids[] = (int) App::audit()->log(
            'SETTING_UPDATE',
            ['wp_user_id' => $admin, 'role' => 'administrator'],
            'setting',
            null,
            null,
            ['setting' => 'setup.started_at', 'value' => ''],
            ['setting' => 'setup.started_at', 'value' => '2026-10-01T00:00:00+00:00'],
            ['op' => 'config_update', 'clinic_id' => $this->clinic_id],
            $this->clinic_id
        );
        $ids[] = (int) App::audit()->log(
            'SETTING_UPDATE',
            ['wp_user_id' => $admin, 'role' => 'administrator'],
            'setting',
            null,
            null,
            ['setting' => 'setup.current_step', 'value' => 'welcome'],
            ['setting' => 'setup.current_step', 'value' => 'booking'],
            ['op' => 'config_update', 'clinic_id' => $this->clinic_id],
            $this->clinic_id
        );

        return $ids;
    }

    /** Simulates the durable result of migration 0026's historical backfill. */
    private function markClaimed(): void
    {
        App::db()->query(
            'UPDATE ' . App::db()->table('cpms_clinics') . ' SET first_setup_claimed_at = CURRENT_TIMESTAMP(3) WHERE id = %d AND first_setup_claimed_at IS NULL',
            [$this->clinic_id]
        );
    }

    /** @return array<string, mixed>|null */
    private function heartbeatRow(): ?array
    {
        return App::db()->fetchRow(
            'SELECT updated_by_wp_user_id, value_json, JSON_TYPE(value_json) AS value_type FROM ' . App::db()->table('cpms_settings') . ' WHERE clinic_id = %d AND `key` = %s',
            [$this->clinic_id, 'jobs.last_tick_at']
        );
    }

    private function establishScopeForClinic(): void
    {
        try {
            App::replaceExplicitScope(App::scope());
        } catch (\Throwable) {
            // Tests that seed membership establish scope through App::scope();
            // zero-membership tests rely on the seeded installation scope.
        }
    }

    private function renderWizardStep(string $step): string
    {
        $previous = $_GET;
        $_GET['step'] = $step;
        ob_start();
        try {
            CpmsSetupWizard::render();

            return (string) ob_get_clean();
        } finally {
            $_GET = $previous;
        }
    }

    private function captureRedirect(callable $fn): string
    {
        $location = '';
        $filter = static function (string $to) use (&$location): string {
            $location = $to;
            throw new \RuntimeException('REDIRECT::' . $to);
        };
        add_filter('wp_redirect', $filter, 1);
        try {
            $fn();
        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'REDIRECT::')) {
                return $location;
            }
            throw $e;
        } finally {
            remove_filter('wp_redirect', $filter, 1);
        }

        return $location;
    }

    private function makeUser(string $prefix, string $role): int
    {
        $login  = $prefix . bin2hex(random_bytes(3));
        $userId = (int) wp_create_user($login, 'pass-12345', $login . '@test.local');
        $user   = get_userdata($userId);
        self::assertNotFalse($user);
        $user->set_role($role);

        return $userId;
    }
}
