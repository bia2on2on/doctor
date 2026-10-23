<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use ClinicCore\Tests\Integration\Fixtures\Phase2MultiClinicSmsFixture;
use ClinicCore\Tests\Integration\Fixtures\RecordingSmsProvider;
use WP_UnitTestCase;

require_once __DIR__ . '/Fixtures/RecordingSmsProvider.php';
require_once __DIR__ . '/Fixtures/Phase2MultiClinicSmsFixture.php';

/**
 * D7 — explicit trusted clinic_id attribution with NO ambient scope.
 *
 * The auditor required that public/patient/multi-clinic audit events never
 * rely on the ambient request scope for their clinic_id: the trusted Clinic
 * must travel explicitly from durable data, and an event with no Clinic must
 * persist NULL rather than borrowing whatever scope happens to be active.
 *
 * Using the two reserved-ID Clinics from Phase2MultiClinicSmsFixture with
 * ScopeContext explicitly cleared, this suite proves:
 *  - an OTP request bound to Clinic A/B audits OTP_REQUEST with exactly that
 *    clinic_id while no ambient scope exists;
 *  - an OTP request with no Clinic persists NULL (never an invented id);
 *  - the append-only audit hash chain stays valid across the mixed
 *    attributed/NULL sequence.
 */
final class MultiClinicAuditAttributionTest extends WP_UnitTestCase
{
    use Phase2MultiClinicSmsFixture;

    private RecordingSmsProvider $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        SystemClinicResolver::flush();

        $this->buildMultiClinicSmsFixture();

        $this->recorder = new RecordingSmsProvider();
        App::providers()->register($this->recorder);
        self::assertTrue(App::providers()->has(RecordingSmsProvider::ID), 'precondition: recording transport registered');

        // Hard precondition for the whole suite: no ambient Clinic scope.
        self::assertNull(ScopeContext::tryGet(), 'the suite must run without an ambient clinic scope');
    }

    protected function tearDown(): void
    {
        $this->fxPurge();
        ScopeContext::clear();
        SystemClinicResolver::flush();
        Settings::flushCache();
        App::resetScope();

        parent::tearDown();
    }

    public function testOtpAuditsCarryTheExplicitTrustedClinicWithoutAmbientScope(): void
    {
        $mobileA = '0912' . random_int(1000000, 1999999);
        $mobileB = '0912' . random_int(2000000, 2999999);

        App::otpService()->request($mobileA, 'login', null, null, $this->fxClinicA);
        App::otpService()->request($mobileB, 'login', null, null, $this->fxClinicB);

        $rowA = App::db()->fetchRow(
            'SELECT clinic_id FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s ORDER BY id DESC LIMIT 1 OFFSET 1',
            ['OTP_REQUEST']
        );
        $rowB = App::db()->fetchRow(
            'SELECT clinic_id FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s ORDER BY id DESC LIMIT 1',
            ['OTP_REQUEST']
        );

        self::assertIsArray($rowA);
        self::assertIsArray($rowB);
        self::assertSame($this->fxClinicA, (int) $rowA['clinic_id'], 'Clinic A OTP audit must attribute to the explicit Clinic A, not ambient scope');
        self::assertSame($this->fxClinicB, (int) $rowB['clinic_id'], 'Clinic B OTP audit must attribute to the explicit Clinic B, not ambient scope');

        // The challenge tokens carry the same explicit Clinic stamp.
        $tokenA = App::db()->fetchValue(
            'SELECT clinic_id FROM ' . App::db()->table('cpms_otp_tokens') . ' WHERE mobile = %s ORDER BY id DESC LIMIT 1',
            [$mobileA]
        );
        self::assertSame($this->fxClinicA, (int) $tokenA);
    }

    public function testClinicLessEventPersistsNullInsteadOfInventingAnId(): void
    {
        // No ambient scope AND no explicit Clinic: the honest attribution is
        // NULL (a genuinely Clinic-less event), never 0/1 or a borrowed scope.
        // With suppression the ambient fallback must not even be consulted.
        App::audit()->log(
            'TEST_NULL_ATTRIBUTION_SUPPRESSED',
            ['wp_user_id' => null, 'role' => 'system'],
            'test',
            null,
            null,
            null,
            null,
            ['note' => 'no clinic, fallback suppressed'],
            null,
            true
        );
        $suppressed = App::db()->fetchRow(
            'SELECT clinic_id FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s ORDER BY id DESC LIMIT 1',
            ['TEST_NULL_ATTRIBUTION_SUPPRESSED']
        );
        self::assertIsArray($suppressed);
        self::assertNull($suppressed['clinic_id'], 'suppressed fallback with no explicit Clinic must persist NULL');

        // Without suppression, an absent ambient scope resolves to NULL too
        // (the fallback catches ScopeRequiredException) — it must never invent
        // an id such as 0 or 1.
        App::audit()->log(
            'TEST_NULL_ATTRIBUTION_FALLBACK',
            ['wp_user_id' => null, 'role' => 'system'],
            'test',
            null,
            null,
            null,
            null,
            ['note' => 'no clinic, fallback runs but scope is absent']
        );
        $fallback = App::db()->fetchRow(
            'SELECT clinic_id FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s ORDER BY id DESC LIMIT 1',
            ['TEST_NULL_ATTRIBUTION_FALLBACK']
        );
        self::assertIsArray($fallback);
        self::assertNull($fallback['clinic_id'], 'an absent ambient scope must resolve to NULL, never an invented id');

        $chain = App::audit()->verifyChain(200);
        self::assertTrue($chain['ok'], 'the hash chain must verify across attributed and NULL clinic events');
    }
}
