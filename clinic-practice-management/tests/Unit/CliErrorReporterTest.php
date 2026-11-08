<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CliErrorReporterTest extends TestCase
{
    public function testBackupExceptionUsesSafePlainTextAndNonzeroExit(): void
    {
        $result = $this->runReporterSubprocess(
            '\\ClinicCore\\Infrastructure\\Backup\\BackupException::of('
            . var_export( 'CLINIC_BACKUP_IO', true ) . ', '
            . var_export( 'PRIVATE_MESSAGE_MARKER /srv/private/backups', true )
            . ')'
        );

        self::assertSame( 1, $result['status'] );
        self::assertSame( '', $result['stdout'] );
        self::assertSame( "CPMS backup failed [CLINIC_BACKUP_IO]. Details are omitted.\n", $result['stderr'] );
        self::assertStringNotContainsString( 'PRIVATE_MESSAGE_MARKER', $result['stderr'] );
        self::assertStringNotContainsString( '/srv/private/backups', $result['stderr'] );
        self::assertStringNotContainsString( '<', $result['stderr'], 'CLI output must remain plain text, not rendered HTML.' );
    }

    public function testStorageConfigurationExceptionUsesSafePlainTextAndNonzeroExit(): void
    {
        $result = $this->runReporterSubprocess(
            'new \\ClinicCore\\Infrastructure\\Storage\\StorageConfigurationException('
            . var_export( 'CLINIC_BACKUP_STORAGE_INSIDE_WEBROOT', true ) . ', '
            . var_export( 'PRIVATE_CONFIGURATION_MARKER', true ) . ', '
            . var_export( '/srv/private/configured/path', true )
            . ')'
        );

        self::assertSame( 1, $result['status'] );
        self::assertSame( '', $result['stdout'] );
        self::assertSame(
            "CPMS backup failed [CLINIC_BACKUP_STORAGE_INSIDE_WEBROOT]. Details are omitted.\n",
            $result['stderr']
        );
        self::assertStringNotContainsString( 'PRIVATE_CONFIGURATION_MARKER', $result['stderr'] );
        self::assertStringNotContainsString( '/srv/private/configured/path', $result['stderr'] );
        self::assertStringNotContainsString( '<', $result['stderr'], 'CLI output must remain plain text, not rendered HTML.' );
    }

    public function testMalformedTypedErrorCodeIsReplacedWithBoundedFallback(): void
    {
        $result = $this->runReporterSubprocess(
            '\\ClinicCore\\Infrastructure\\Backup\\BackupException::of('
            . var_export( '<PRIVATE_ERROR_CODE_MARKER>', true ) . ', '
            . var_export( 'PRIVATE_MESSAGE_MARKER', true )
            . ')'
        );

        self::assertSame( 1, $result['status'] );
        self::assertSame( '', $result['stdout'] );
        self::assertSame( "CPMS backup failed [CPMS_BACKUP_FAILED]. Details are omitted.\n", $result['stderr'] );
        self::assertStringNotContainsString( 'PRIVATE_ERROR_CODE_MARKER', $result['stderr'] );
        self::assertStringNotContainsString( '<', $result['stderr'], 'Untrusted code text must not be emitted as markup.' );
    }

    /**
     * @return array{status:int,stdout:string,stderr:string}
     */
    private function runReporterSubprocess( string $error_expression ): array
    {
        $plugin_root = dirname( __DIR__, 2 );
        $script      = 'require ' . var_export( $plugin_root . '/src/Infrastructure/Backup/BackupException.php', true ) . '; '
            . 'require ' . var_export( $plugin_root . '/src/Infrastructure/Storage/StorageConfigurationException.php', true ) . '; '
            . 'require ' . var_export( $plugin_root . '/src/Cli/CliErrorReporter.php', true ) . '; '
            . 'exit(\\ClinicCore\\Cli\\CliErrorReporter::report_backup_failure(' . $error_expression . '));';
        $process  = proc_open(
            [ PHP_BINARY, '-r', $script ],
            [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
            $pipes,
            dirname( __DIR__, 2 )
        );

        if ( ! is_resource( $process ) ) {
            self::fail( 'Could not start the PHP subprocess for the CLI reporter test.' );
        }

        fclose( $pipes[0] );
        $stdout = (string) stream_get_contents( $pipes[1] );
        fclose( $pipes[1] );
        $stderr = (string) stream_get_contents( $pipes[2] );
        fclose( $pipes[2] );

        return [
            'status' => proc_close( $process ),
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }
}
