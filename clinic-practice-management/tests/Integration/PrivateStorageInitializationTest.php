<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\System\SystemHealthService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Storage\LocalFileStorage;
use ClinicCore\Infrastructure\Storage\PrivateStorageInitializer;
use ClinicCore\Infrastructure\Storage\StorageConfigurationException;
use WP_UnitTestCase;

/**
 * C — راه‌اندازیِ ریشه‌های فعالِ ذخیره‌سازی خصوصی.
 *
 * پوششِ نقص‌های تأییدشده:
 *  - C1/C2: پیش از این پوشه/گاردها فقط در اولین `store()` ساخته می‌شدند و
 *    راه‌اندازی هیچ ریشه‌ای نمی‌ساخت (وابستگیِ پنهان به legacy).
 *  - C4: مسیرِ داخل DocumentRoot باید رد شود — و همین‌طور مسیرِ symlink‌شده
 *    به داخل DocumentRoot (مقایسه روی realpath).
 *  - C5: نوشتنِ همهٔ گاردها پس از نوشتن دوباره بررسی می‌شود.
 *  - C8: هیچ مسیرِ موجودی پاک/جابه‌جا نمی‌شود.
 *  - C10: میزبانِ غیرقابل‌نوشتن باید خطای تایپ‌شده بدهد (نه «اختیاری/سالم»).
 *  - C12: نصبِ تازه، والدِ غیرقابل‌نوشتن، داخل-webroot، و چند-Clinic.
 */
final class PrivateStorageInitializationTest extends WP_UnitTestCase
{
    private string $tmpBase = '';

    protected function setUp(): void
    {
        parent::setUp();

        App::migrations()->migrate();
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user( 0 );

        $this->tmpBase = rtrim( sys_get_temp_dir(), '/' ) . '/cpms-storage-' . bin2hex( random_bytes( 6 ) );
        self::assertTrue( mkdir( $this->tmpBase, 0700, true ) );

        PrivateStorageInitializer::forget();
    }

    protected function tearDown(): void
    {
        if ( $this->tmpBase !== '' && is_dir( $this->tmpBase ) ) {
            @chmod( $this->tmpBase, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- test cleanup
            $this->removeTree( $this->tmpBase );
        }
        PrivateStorageInitializer::forget();
        App::resetScope();
        ScopeContext::clear();
        parent::tearDown();
    }

    public function testFreshInstallCreatesBothRootsWithEveryGuardFile(): void
    {
        $files   = $this->tmpBase . '/files';
        $backups = $this->tmpBase . '/backups';

        self::assertFalse( is_dir( $files ), 'precondition: files root must not exist' );
        self::assertFalse( is_dir( $backups ), 'precondition: backups root must not exist' );

        $result = PrivateStorageInitializer::initialize( $files, $backups );

        self::assertTrue( $result['ok'], 'fresh install initialization must succeed' );
        self::assertTrue( $result['roots']['files']['created'] );
        self::assertTrue( $result['roots']['backups']['created'] );

        // C5 — هر گاردِ الزامی باید پس از نوشتن واقعاً خوانده شود.
        foreach ( [ $files, $backups ] as $root ) {
            self::assertTrue( is_dir( $root ), 'root must exist: ' . $root );
            foreach ( [ '.htaccess', 'index.php', 'web.config', 'README-SECURITY.txt' ] as $guard ) {
                self::assertFileExists( $root . '/' . $guard, 'guard missing: ' . $root . '/' . $guard );
                self::assertTrue( is_readable( $root . '/' . $guard ) );
            }
            // C — هرگز مجوزِ باز (777) ساخته نمی‌شود.
            self::assertNotSame( 0777, fileperms( $root ) & 0777, 'مخزن نباید 0777 باشد: ' . $root );
        }
    }

    public function testInitializationIsIdempotentAndPreservesExistingContent(): void
    {
        $files = $this->tmpBase . '/files';

        $first = PrivateStorageInitializer::initialize( $files, $this->tmpBase . '/backups' );
        self::assertTrue( $first['ok'] );

        // C8 — دادهٔ موجودِ بالینی نباید دست بخورد.
        $clinical = $files . '/7/ab/clinical.bin';
        self::assertTrue( mkdir( dirname( $clinical ), 0750, true ) );
        file_put_contents( $clinical, 'PHI-SENTINEL' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

        $second = PrivateStorageInitializer::initialize( $files, $this->tmpBase . '/backups' );

        self::assertTrue( $second['ok'] );
        self::assertFalse( $second['roots']['files']['created'], 'second run must not report a new root' );
        self::assertSame( 'PHI-SENTINEL', (string) file_get_contents( $clinical ) );
    }

    public function testInsideWebRootPathIsRejectedAndNothingIsCreated(): void
    {
        $inside = rtrim( (string) ABSPATH, '/' ) . '/wp-content/cpms-should-not-exist-' . bin2hex( random_bytes( 4 ) );

        $result = PrivateStorageInitializer::initialize( $inside, $this->tmpBase . '/backups' );

        self::assertFalse( $result['ok'], 'ریشهٔ داخلِ DocumentRoot باید رد شود.' );
        self::assertFalse( $result['roots']['files']['ok'] );
        self::assertSame( 'CLINIC_STORAGE_INSIDE_WEBROOT', $result['roots']['files']['error_code'] );
        self::assertFalse( is_dir( $inside ), 'هیچ پوشه‌ای داخل webroot ساخته نمی‌شود.' );
        // ریشهٔ دیگر مستقل ارزیابی می‌شود.
        self::assertTrue( $result['roots']['backups']['ok'] );
    }

    public function testSymlinkIntoWebRootIsRejected(): void
    {
        $linkTarget = rtrim( (string) ABSPATH, '/' ) . '/wp-content';
        $link       = $this->tmpBase . '/link-to-webroot';

        if ( ! @symlink( $linkTarget, $link ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- platform may disallow symlinks
            self::markTestSkipped( 'این محیط اجازهٔ ساخت symlink نمی‌دهد.' );
        }

        $result = PrivateStorageInitializer::initialize( $link . '/cpms-files', $this->tmpBase . '/backups' );

        self::assertFalse( $result['roots']['files']['ok'], 'symlink به داخل webroot باید رد شود.' );
        self::assertSame( 'CLINIC_STORAGE_INSIDE_WEBROOT', $result['roots']['files']['error_code'] );
    }

    public function testUnwritableParentYieldsTypedActionableError(): void
    {
        if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
            self::markTestSkipped( 'اجرا به‌عنوان root مجوزها را بی‌اثر می‌کند.' );
        }

        $locked = $this->tmpBase . '/locked';
        self::assertTrue( mkdir( $locked, 0500, true ) ); // r-x, not writable

        $result = PrivateStorageInitializer::initialize( $locked . '/files', $this->tmpBase . '/backups' );

        self::assertFalse( $result['roots']['files']['ok'], 'والدِ غیرقابل‌نوشتن باید خطا بدهد.' );
        self::assertNotSame( '', $result['roots']['files']['error'], 'خطا باید متنِ قابل‌اقدام داشته باشد.' );
        self::assertContains(
            $result['roots']['files']['error_code'],
            [ 'CLINIC_STORAGE_NOT_WRITABLE', 'CLINIC_STORAGE_GUARD_NOT_WRITTEN', 'STORAGE_INIT_FAILED' ]
        );
        self::assertFalse( is_dir( $locked . '/files' ) );
    }

    public function testEnsureInitializedIsCheapAndRemembersSuccessButRetriesFailure(): void
    {
        $files   = $this->tmpBase . '/files';
        $backups = $this->tmpBase . '/backups';

        $first = PrivateStorageInitializer::ensure_initialized( $files, $backups );
        self::assertTrue( $first['ok'] );

        $state = PrivateStorageInitializer::state();
        self::assertIsArray( $state );
        self::assertSame( PrivateStorageInitializer::GUARD_VERSION, $state['guard_version'] );
        self::assertTrue( $state['ok'] );

        // مسیرها عوض شوند ⇒ امضا دیگر نمی‌خورد و کارِ واقعی انجام می‌شود.
        $other = PrivateStorageInitializer::ensure_initialized( $files, $this->tmpBase . '/backups-2' );
        self::assertTrue( $other['ok'] );
        self::assertTrue( is_dir( $this->tmpBase . '/backups-2' ) );

        // شکست کش نمی‌شود: مسیرِ داخلِ webroot وضعیتِ ناموفق ثبت می‌کند...
        $bad = PrivateStorageInitializer::ensure_initialized(
            rtrim( (string) ABSPATH, '/' ) . '/wp-content/nope-' . bin2hex( random_bytes( 3 ) ),
            $this->tmpBase . '/backups-3'
        );
        self::assertFalse( $bad['ok'] );
        self::assertFalse( (bool) PrivateStorageInitializer::state()['ok'] );

        // ...و فراخوانیِ بعدی دوباره تلاش می‌کند (نه «کشِ شکست تا ابد»).
        $retry = PrivateStorageInitializer::ensure_initialized( $files, $this->tmpBase . '/backups-4' );
        self::assertTrue( $retry['ok'], 'پس از رفعِ مشکل باید دوباره تلاش شود.' );
    }

    /**
     * C12 — راه‌اندازی سطحِ نصب است و نباید به ClinicScope وابسته باشد.
     */
    public function testInstallationInitializationDoesNotRequireAClinicScope(): void
    {
        App::resetScope();
        ScopeContext::clear();

        $result = App::initialize_private_storage( true );

        self::assertArrayHasKey( 'files', $result['roots'] );
        self::assertArrayHasKey( 'backups', $result['roots'] );
        // مسیرها همان پیش‌فرض‌های سطح نصب‌اند (هیچ Clinic‌ای حدس زده نمی‌شود).
        self::assertSame( LocalFileStorage::defaultBasePath(), $result['roots']['files']['path'] );
    }

    /**
     * چند-Clinic: خواندنِ تنظیمِ Clinic-local فقط با Scopeِ معتبر، و هرگز برای
     * Clinicِ دیگر.
     */
    public function testMultiClinicInitializationUsesTheScopedClinicOnlyWhenPresent(): void
    {
        $now   = App::db()->nowUtcSql();
        $uid   = bin2hex( random_bytes( 4 ) );
        $orgId = $this->insertRow(
            'cpms_organizations',
            [
                'name'       => 'Org STORAGE ' . $uid,
                'slug'       => 'org-storage-' . $uid,
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $clinicId = $this->insertRow(
            'cpms_clinics',
            [
                'organization_id' => $orgId,
                'name'            => 'Clinic STORAGE ' . $uid,
                'slug'            => 'clinic-storage-' . $uid,
                'timezone'        => 'Asia/Tehran',
                'created_at'      => $now,
                'updated_at'      => $now,
            ]
        );

        $custom = $this->tmpBase . '/clinic-files-custom';
        App::settingsFactory()->forClinic( $clinicId )->set( 'files.storage_path', $custom );
        \ClinicCore\Settings\Settings::flushCache();

        $previous = ScopeContext::tryGet();
        App::replaceExplicitScope( ClinicScope::forClinic( $clinicId ) );
        try {
            $scoped = App::initialize_private_storage( true );
        } finally {
            App::replaceExplicitScope( $previous );
        }

        self::assertSame( $custom, $scoped['roots']['files']['path'], 'با Scopeِ معتبر باید مسیرِ همان Clinic استفاده شود.' );
        self::assertTrue( is_dir( $custom ) );

        // بدونِ Scope، هیچ Clinic‌ای حدس زده نمی‌شود ⇒ پیش‌فرضِ سطحِ نصب.
        App::resetScope();
        ScopeContext::clear();
        $installation = App::initialize_private_storage( true );
        self::assertSame( LocalFileStorage::defaultBasePath(), $installation['roots']['files']['path'] );
        self::assertNotSame( $custom, $installation['roots']['files']['path'] );
    }

    /**
     * C/F3 — ردیفِ سلامتِ ریشهٔ ناموجود «مسدودکننده» است، نه «اختیاری».
     */
    public function testHealthRowForMissingStorageRootIsAFailureNotOptional(): void
    {
        $health = App::systemHealthService()->run();
        $row    = null;
        foreach ( $health['checks'] as $check ) {
            if ( 'storage.files' === $check['key'] ) {
                $row = $check;
                break;
            }
        }

        self::assertIsArray( $row, 'storage.files row must always be present' );
        self::assertNotSame( SystemHealthService::NOT_CONFIGURED, $row['status'], 'ریشهٔ الزامی هرگز NOT_CONFIGURED نیست.' );
        self::assertContains( $row['status'], [ SystemHealthService::PASS, SystemHealthService::FAIL ] );

        $groups = SystemHealthService::group_checks( $health['checks'] );
        self::assertSame( [], array_values( array_filter( $groups['optional'], static fn ( array $c ): bool => 'storage.files' === $c['key'] ) ) );
    }

    public function testLocalFileStorageEnsureReadyRejectsInsideWebRootDirectly(): void
    {
        $inside = rtrim( (string) ABSPATH, '/' ) . '/wp-content/cpms-nope-' . bin2hex( random_bytes( 3 ) );

        $this->expectException( StorageConfigurationException::class );
        ( new LocalFileStorage( $inside ) )->ensure_ready();
    }

    private function insertRow( string $table, array $data ): int
    {
        global $wpdb;

        $ok = $wpdb->insert( $wpdb->prefix . $table, $data );
        self::assertTrue( (bool) $ok, 'fixture insert failed: ' . $wpdb->last_error );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $id );

        return $id;
    }

    private function removeTree( string $dir ): void
    {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        foreach ( (array) scandir( $dir ) as $entry ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if ( is_dir( $path ) && ! is_link( $path ) ) {
                $this->removeTree( $path );
                continue;
            }
            @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup
        }
        @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- test cleanup
    }
}
