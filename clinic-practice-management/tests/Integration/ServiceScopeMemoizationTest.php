<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * B — سرویس‌های سطحِ نصب نباید به Clinicِ **قبلی** میخ شوند.
 *
 * ریشهٔ نقص (واقعی، در کدِ قبل از این تغییر):
 *  - `App::updateService()` یک `static $updates` تک‌متغیره داشت و
 *  - `App::system_health_service()` یک `static $health` تک‌متغیره داشت.
 *
 * هر دو، `Settings`ِ Clinicِ **اولین** فراخوانی را برای تمامِ عمرِ فرآیندِ PHP
 * نگه می‌داشتند. در یک فرآیند با چند Clinic (WP-CLI، PHPUnit، یا هر جریانِ
 * طولانی که Scope را عوض می‌کند) گزارشِ Clinic B با پیکربندیِ Clinic A ساخته
 * می‌شد — یعنی نشتِ پیکربندیِ بین‌Clinic.
 *
 * این تست همان الگوی اثباتِ `MultiClinicBookingSettingsScopeRedTest` را
 * می‌گیرد: دو Clinicِ واقعی با مقادیرِ **متفاوت**، و یک مقدارِ مشاهده‌پذیر از
 * خروجیِ عمومیِ سرویس (`license.gateway` در گزارشِ سلامت) که مستقیماً از
 * `license.server_url` همان Clinic می‌آید.
 */
final class ServiceScopeMemoizationTest extends WP_UnitTestCase
{
    private int $clinicA = 0;

    private int $clinicB = 0;

    protected function setUp(): void
    {
        parent::setUp();

        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user(0);

        $now = App::db()->nowUtcSql();
        $uid = bin2hex(random_bytes(4));

        $orgId = $this->insertRow(
            'cpms_organizations',
            [
                'name'       => 'Org SCOPE ' . $uid,
                'slug'       => 'org-scope-' . $uid,
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $this->clinicA = $this->insertRow(
            'cpms_clinics',
            [
                'organization_id' => $orgId,
                'name'            => 'Clinic A SCOPE ' . $uid,
                'slug'            => 'clinic-a-scope-' . $uid,
                'timezone'        => 'Asia/Tehran',
                'created_at'      => $now,
                'updated_at'      => $now,
            ]
        );

        $this->clinicB = $this->insertRow(
            'cpms_clinics',
            [
                'organization_id' => $orgId,
                'name'            => 'Clinic B SCOPE ' . $uid,
                'slug'            => 'clinic-b-scope-' . $uid,
                'timezone'        => 'Asia/Tehran',
                'created_at'      => $now,
                'updated_at'      => $now,
            ]
        );

        // Clinic A: سرور مجوز **تنظیم‌نشده** (پیش‌فرض خالی).
        App::settingsFactory()->forClinic( $this->clinicA )->set( 'license.server_url', '' );
        // Clinic B: سرور مجوز **تنظیم‌شده** — تنها تفاوتِ مشاهده‌پذیر.
        App::settingsFactory()->forClinic( $this->clinicB )->set( 'license.server_url', 'https://license.scope.test' );

        Settings::flushCache();

        self::assertGreaterThan( 1, $this->clinicA );
        self::assertGreaterThan( 1, $this->clinicB );
    }

    public function testHealthServiceIsNotPinnedToTheFirstClinicInTheProcess(): void
    {
        $rowA = $this->licenseGatewayRowFor( $this->clinicA );
        $rowB = $this->licenseGatewayRowFor( $this->clinicB );
        // دوباره A — الگوی A → B → A که نقص را آشکار می‌کند.
        $rowAAgain = $this->licenseGatewayRowFor( $this->clinicA );

        self::assertSame( 'not_configured', $rowA['status'], 'Clinic A سرور مجوز ندارد.' );
        self::assertSame( 'pass', $rowB['status'], 'Clinic B سرور مجوز دارد و باید PASS باشد.' );
        self::assertSame( 'not_configured', $rowAAgain['status'], 'بازگشت به Clinic A نباید پیکربندیِ B را نشان دهد.' );
    }

    public function testHealthServiceInstanceDiffersPerClinicAndIsStablePerClinic(): void
    {
        $a1 = $this->withScope( $this->clinicA, static fn () => App::system_health_service() );
        $b1 = $this->withScope( $this->clinicB, static fn () => App::system_health_service() );
        $a2 = $this->withScope( $this->clinicA, static fn () => App::system_health_service() );

        self::assertNotSame( $a1, $b1, 'دو Clinic نباید یک نمونهٔ سرویس را به اشتراک بگذارند.' );
        self::assertSame( $a1, $a2, 'همان Clinic باید همان نمونه را بگیرد (کشِ per-Clinic).' );
    }

    public function testUpdateServiceIsNotPinnedToTheFirstClinicInTheProcess(): void
    {
        $a1 = $this->withScope( $this->clinicA, static fn () => App::updateService() );
        $b1 = $this->withScope( $this->clinicB, static fn () => App::updateService() );
        $a2 = $this->withScope( $this->clinicA, static fn () => App::updateService() );

        self::assertNotSame( $a1, $b1, 'سرویس به‌روزرسانی نباید بین دو Clinic مشترک باشد.' );
        self::assertSame( $a1, $a2 );
    }

    /**
     * سطح نصب (بدونِ Scope) هرگز نباید نمونهٔ Clinic-محور بگیرد.
     */
    public function testInstallationLevelCallsNeverReceiveAClinicBoundInstance(): void
    {
        $this->withScope( $this->clinicB, static function (): void {
            App::updateService();
            App::system_health_service();
        });

        App::resetScope();
        $installation = App::updateService();
        $clinic       = $this->withScope( $this->clinicB, static fn () => App::updateService() );

        self::assertNotSame( $installation, $clinic, 'نمونهٔ سطح نصب نباید نمونهٔ Clinic را برگرداند.' );
    }

    /**
     * @return array{key:string,label:string,status:string,detail:string}
     */
    private function licenseGatewayRowFor( int $clinicId ): array
    {
        return $this->withScope(
            $clinicId,
            static function () use ( $clinicId ): array {
                foreach ( App::system_health_service()->run()['checks'] as $check ) {
                    if ( 'license.gateway' === $check['key'] ) {
                        return $check;
                    }
                }

                self::fail( 'license.gateway row missing for clinic ' . $clinicId );

                return [];
            }
        );
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function withScope( int $clinicId, callable $fn )
    {
        $previous = ScopeContext::tryGet();
        App::replaceExplicitScope( ClinicScope::forClinic( $clinicId ) );
        try {
            return $fn();
        } finally {
            App::replaceExplicitScope( $previous );
        }
    }

    private function insertRow( string $table, array $data ): int
    {
        global $wpdb;

        $ok = $wpdb->insert( $wpdb->prefix . $table, $data );
        self::assertTrue( (bool) $ok, 'fixture insert failed for ' . $table . ': ' . $wpdb->last_error );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $id );

        return $id;
    }
}
