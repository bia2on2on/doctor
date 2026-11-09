<?php

declare(strict_types=1);

namespace ClinicCore\Cli;

use ClinicCore\Domain\Backup\BackupVerificationErrorCodes;
use ClinicCore\Infrastructure\Backup\BackupException;
use ClinicCore\Infrastructure\Storage\StorageConfigurationException;

/**
 * Safe terminal diagnostics for CPMS command-line operations.
 */
final class CliErrorReporter {
    private const FALLBACK_ERROR_CODE     = 'CPMS_BACKUP_FAILED';
    private const SAFE_ERROR_CODE_PATTERN = '/\A[A-Z][A-Z0-9_]{0,63}\z/';

    /**
     * Report a backup failure without emitting exception messages or paths.
     *
     * @return int Non-zero CLI exit status.
     */
    public static function report_backup_failure( BackupException|StorageConfigurationException $error ): int {
        $error_code = $error->errorCode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Both typed exception APIs expose the stable `errorCode` property.
        $code       = preg_match( self::SAFE_ERROR_CODE_PATTERN, $error_code ) === 1
            ? $error_code
            : self::FALLBACK_ERROR_CODE;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI diagnostics must go to STDERR; WP_Filesystem is not applicable.
        fwrite( STDERR, sprintf( "CPMS backup failed [%s]. Details are omitted.\n", $code ) );

        return 1;
    }

    /**
     * Report a failed integrity verification using only allowlisted categories.
     *
     * @param array<mixed> $errors Verification errors, possibly containing untrusted detail.
     * @return int Distinct verification failure exit status.
     */
    public static function report_backup_verification_failure( array $errors ): int {
        $categories = BackupVerificationErrorCodes::safe_error_categories( $errors );
        if ( $categories === array() ) {
            $categories = array( BackupVerificationErrorCodes::VERIFICATION_FAILED );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI diagnostics must go to STDERR; WP_Filesystem is not applicable.
        fwrite(
            STDERR,
            sprintf( "CPMS backup verification failed [%s]. Details are omitted.\n", implode( ', ', $categories ) )
        );

        return 2;
    }

    /**
     * Report an unexpected backup command failure without exposing exception or driver details.
     *
     * @return int Non-zero CLI exit status.
     */
    public static function report_unexpected_backup_failure(): int {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI diagnostics must go to STDERR; WP_Filesystem is not applicable.
        fwrite( STDERR, sprintf( "CPMS backup failed [%s]. Details are omitted.\n", self::FALLBACK_ERROR_CODE ) );

        return 1;
    }
}
