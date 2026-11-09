<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Backup;

/**
 * Public, bounded verification categories. Never include a filename, storage
 * path, exception message, or database-driver detail in a verification result.
 */
final class BackupVerificationErrorCodes {
    public const MANIFEST_MISSING_OR_INVALID      = 'BACKUP_MANIFEST_MISSING_OR_INVALID';
    public const MANIFEST_TAMPERED                = 'BACKUP_MANIFEST_TAMPERED';
    public const MANIFEST_AUTHENTICITY_UNVERIFIED = 'BACKUP_MANIFEST_AUTHENTICITY_UNVERIFIED';
    public const MANIFEST_BACKUP_ID_MISMATCH      = 'BACKUP_MANIFEST_BACKUP_ID_MISMATCH';
    public const MANIFEST_INVALID                 = 'BACKUP_MANIFEST_INVALID';
    public const DATABASE_FILE_MISSING            = 'BACKUP_DATABASE_FILE_MISSING';
    public const DATABASE_FILE_INTEGRITY_MISMATCH = 'BACKUP_DATABASE_FILE_INTEGRITY_MISMATCH';
    public const STORAGE_FILE_MISSING             = 'BACKUP_STORAGE_FILE_MISSING';
    public const STORAGE_FILE_INTEGRITY_MISMATCH  = 'BACKUP_STORAGE_FILE_INTEGRITY_MISMATCH';
    public const VERIFICATION_FAILED              = 'BACKUP_VERIFICATION_FAILED';

    private const MAX_ERROR_CATEGORIES   = 6;
    private const MAX_WARNING_CATEGORIES = 2;

    /** @var list<string> */
    private const ERROR_CATEGORIES = [
        self::MANIFEST_MISSING_OR_INVALID,
        self::MANIFEST_TAMPERED,
        self::MANIFEST_AUTHENTICITY_UNVERIFIED,
        self::MANIFEST_BACKUP_ID_MISMATCH,
        self::MANIFEST_INVALID,
        self::DATABASE_FILE_MISSING,
        self::DATABASE_FILE_INTEGRITY_MISMATCH,
        self::STORAGE_FILE_MISSING,
        self::STORAGE_FILE_INTEGRITY_MISMATCH,
        self::VERIFICATION_FAILED,
    ];

    /** @var list<string> */
    private const WARNING_CATEGORIES = [
        self::MANIFEST_AUTHENTICITY_UNVERIFIED,
    ];

    private function __construct() {
    }

    /**
     * Drop unknown/untrusted detail, de-duplicate, and cap categories for display.
     *
     * @param array<mixed> $errors
     * @return list<string>
     */
    public static function safe_error_categories( array $errors ): array {
        return self::filter_categories( $errors, self::ERROR_CATEGORIES, self::MAX_ERROR_CATEGORIES, self::VERIFICATION_FAILED );
    }

    /**
     * @param array<mixed> $warnings
     * @return list<string>
     */
    public static function safe_warning_categories( array $warnings ): array {
        return self::filter_categories( $warnings, self::WARNING_CATEGORIES, self::MAX_WARNING_CATEGORIES, null );
    }

    /**
     * @param array<mixed> $values
     * @param list<string> $allowed
     * @return list<string>
     */
    private static function filter_categories( array $values, array $allowed, int $limit, ?string $fallback ): array {
        if ( $values === [] ) {
            return [];
        }

        $categories = [];
        foreach ( $values as $value ) {
            if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) || in_array( $value, $categories, true ) ) {
                continue;
            }
            $categories[] = $value;
            if ( count( $categories ) >= $limit ) {
                break;
            }
        }

        if ( $categories === [] && $fallback !== null ) {
            return [ $fallback ];
        }

        return $categories;
    }
}
