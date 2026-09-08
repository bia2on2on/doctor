<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * Phase 2 — Scope Context Foundation (ADR-0031 / P2-B):
 *
 *  - Resolution سیستمی فقط در حالت «دقیقاً یک Clinic» جواب می‌دهد (مطب
 *    تک‌پزشکی روی همان مدل Core — AD-04) و مقدارش از DB می‌آید، نه ثابت.
 *  - صفر یا ≥۲ Clinic ⇒ Fail-Closed (CLINIC_SCOPE_REQUIRED) — هیچ fallback
 *    implicit («اولین Clinic» / clinic_id=1) وجود ندارد (AD-13/P2-B).
 *  - Scope صریح بر Resolution سیستمی مقدم است.
 */
final class ScopeContextTest extends WP_UnitTestCase
{
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

    private function insertClinic(int $id, string $slug): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at)
                 VALUES (%d, (SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = 1), %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $id,
                'کلینیک ' . $slug,
                $slug,
                'Asia/Tehran',
                $now,
                $now
            )
        );
    }

    public function testSystemResolverReturnsTheSingleSeededClinicFromDb(): void
    {
        $scope = App::scope();

        $seededId = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_clinics') . ' LIMIT 1'
        );

        self::assertSame($seededId, $scope->clinicId, 'Scope باید از DB حل شود، نه از ثابت.');
        self::assertSame(ClinicScope::SOURCE_SYSTEM_SINGLE, $scope->source);
        self::assertNotSame(0, $scope->clinicId);
    }

    public function testSystemResolverFailsClosedWithTwoClinics(): void
    {
        $this->insertClinic(2, 'scope-second');

        $caught = null;
        try {
            App::scope();
        } catch (ScopeRequiredException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'با ≥۲ Clinic، resolution ضمنی باید بسته شود.');
        self::assertSame('CLINIC_SCOPE_REQUIRED', $caught->errorCode);
        self::assertSame(2, $caught->data['clinic_count'] ?? null);
        self::assertSame(400, $caught->httpStatus());
    }

    public function testSystemResolverFailsClosedWithZeroClinics(): void
    {
        global $wpdb;
        // FKهای 0017 (RESTRICT) موقتاً خاموش — تراکنش تست همه را rollback می‌کند
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_clinics'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $this->expectException(ScopeRequiredException::class);
        $this->expectExceptionMessage('تعداد Clinicهای نصب: 0');
        App::scope();
    }

    public function testExplicitScopeOverridesSystemResolution(): void
    {
        $this->insertClinic(2, 'scope-explicit');
        $explicit = ClinicScope::forClinic(2);
        ScopeContext::set($explicit);

        self::assertSame($explicit, App::scope());
        self::assertSame(2, App::scope()->clinicId);
        self::assertSame(ClinicScope::SOURCE_EXPLICIT, App::scope()->source);
    }

    public function testClearRestoresSystemResolution(): void
    {
        $this->insertClinic(2, 'scope-clear');
        ScopeContext::set(ClinicScope::forClinic(2));
        ScopeContext::clear();

        // بدون Scope صریح، دوباره resolution سیستمی — که اینجا مبهم است
        $this->expectException(ScopeRequiredException::class);
        App::scope();
    }

    public function testSettingsUsesResolvedScopeNotLiteralDefault(): void
    {
        // Settings باید به Clinicِ حل‌شده بسته باشد (نه literal 1) — در نصب
        // تستی شناسهٔ Clinic تنها را تغییر می‌دهیم تا اثبات شود مقدار از ردیف
        // واقعی DB می‌آید، نه از ثابتِ کد.
        global $wpdb;
        $newId = 41;
        // FKهای ارجاع‌دار به clinics(id) موقتاً خاموش — تراکنش تست rollback می‌کند
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query(
            'UPDATE ' . $wpdb->prefix . 'cpms_clinics SET id = ' . $newId // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        App::resetScope();

        self::assertSame($newId, App::scope()->clinicId);

        $settings = new \ClinicCore\Settings\Settings(App::db(), $newId);
        self::assertSame('Asia/Tehran', $settings->clinicTimezone());
    }
}
