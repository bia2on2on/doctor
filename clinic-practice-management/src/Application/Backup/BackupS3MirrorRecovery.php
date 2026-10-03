<?php
/**
 * Phase 15 Slice 2D — rebuild a private temporary copy of one mirrored backup,
 * then run the existing local verifier/restore preflight. This is not a restore.
 */

declare( strict_types=1 );

// phpcs:disable WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
// Raw filesystem I/O is required for exclusive/private staging and canonical
// path checks. Every result is checked; the operation never writes outside its
// newly created private staging root.

namespace ClinicCore\Application\Backup;

use Aws\S3\S3Client;
use ClinicCore\Infrastructure\Backup\BackupEncryptionEnvelope;
use ClinicCore\Infrastructure\Backup\BackupException;
use ClinicCore\Infrastructure\Backup\ProtectedBackupStore;
use ClinicCore\Infrastructure\Backup\S3BackupClientFactory;
use ClinicCore\Infrastructure\Backup\S3BackupDeploymentConfig;
use ClinicCore\Infrastructure\Storage\PrivateStorageLocation;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Reconstruct a remote Slice 2C mirror in unique private staging and preflight it.
 * No provider abstraction, scheduler wiring, or destructive restore is involved.
 */
final class BackupS3MirrorRecovery {
    /** Maximum remote objects, including the catalog. */
    public const MAX_REMOTE_OBJECT_COUNT = 1024;

    /** Inherited Slice 2C single-object ciphertext cap, binary bytes. */
    public const MAX_ENCRYPTED_OBJECT_BYTES = BackupS3Mirror::MAX_ENCRYPTED_OBJECT_BYTES;

    /** Peak ciphertext plus plaintext staging budget, binary bytes. */
    public const MAX_AGGREGATE_STAGED_BYTES = 8589934592;

    /** Decrypted catalog JSON budget, bytes. */
    public const MAX_CATALOG_JSON_BYTES = 1048576;

    private const MAX_CATALOG_CIPHERTEXT_BYTES = self::MAX_CATALOG_JSON_BYTES + 49;
    private const MAX_BACKUP_ID_BYTES          = 121;
    private const MAX_LOGICAL_PATH_BYTES       = 2048;
    private const MAX_PATH_COMPONENT_BYTES     = 255;
    private const MIN_ENVELOPE_BYTES           = 49;
    private const MANIFEST_SIDECAR_BYTES       = 64;
    private const ERROR_NOT_CONFIGURED         = 'CLINIC_BACKUP_MIRROR_NOT_CONFIGURED';
    private const ERROR_RECOVERY_FAILED        = 'CLINIC_BACKUP_MIRROR_RECOVERY_FAILED';
    private const MESSAGE_NOT_CONFIGURED       = 'S3 backup reconstruction is not configured';
    private const MESSAGE_RECOVERY_FAILED      = 'remote backup reconstruction failed';
    private const SUCCESS_RESULT               = 'remote reconstruction passed existing restore preflight';

    /**
     * Private lower-only transfer limits. Defaults remain the production
     * contract constants; tests may scale them down through reflection without
     * changing or widening any production limit.
     */
    private int $catalog_download_limit_bytes    = self::MAX_CATALOG_CIPHERTEXT_BYTES;
    private int $object_download_limit_bytes     = self::MAX_ENCRYPTED_OBJECT_BYTES;
    private int $aggregate_download_budget_bytes = self::MAX_AGGREGATE_STAGED_BYTES;

    /** @var callable|null Slice 2B's documented local-only SDK test-handler seam. */
    private $http_handler;

    public function __construct(
        private readonly BackupService $backup_service,
        private readonly ?S3BackupDeploymentConfig $config = null,
        ?callable $http_handler = null,
        private readonly ?string $staging_root = null
    ) {
        $this->http_handler = $http_handler;
    }

    /**
     * Reconstruct the bounded Slice 2C pointer into private staging and preflight it.
     *
     * @param array<string, mixed> $pointer Exact BackupS3Mirror::POINTER_FIELDS projection.
     *
     * @return array{
     *     ok: true,
     *     result: string,
     *     backup_id: string,
     *     mirror_id: string,
     *     catalog_object_id: string,
     *     local_verifier_ok: true,
     *     restore_preflight_ok: true
     * }
     * @throws BackupException A bounded, non-sensitive failure.
     */
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- public Phase 15 Slice 2D contract.
    public function reconstructAndPreflight( array $pointer ): array {
        $stage         = null;
        $primary_error = null;
        $result        = null;

        try {
            $pointer = $this->validate_pointer( $pointer );
            $config  = $this->resolve_config();
            $key     = $config->encryptionKeyForBackupEnvelope();
            $client  = S3BackupClientFactory::create(
                $config->transportSettings(),
                $this->http_handler
            );
            $stage   = $this->create_stage_root();
            $result  = $this->reconstruct( $pointer, $config, $key, $client, $stage );
        } catch ( BackupException $error ) {
            // Preserve only this operation's fixed, bounded errors and the
            // encryption envelope's deliberately path-free codes. Other
            // existing services may include paths or identifiers in messages.
            $safe_codes    = [
                self::ERROR_NOT_CONFIGURED,
                self::ERROR_RECOVERY_FAILED,
                'CLINIC_BACKUP_ENCRYPTION_FAILED',
                'CLINIC_BACKUP_DECRYPTION_FAILED',
            ];
            $primary_error = in_array( $error->getErrorCode(), $safe_codes, true )
                ? $error
                : $this->recovery_failure();
        } catch ( Throwable ) {
            // SDK, stream, filesystem and JSON exceptions may contain provider
            // configuration, object keys, paths or payload excerpts.
            $primary_error = $this->recovery_failure();
        }

        if ( $stage !== null && ! $this->remove_owned_stage( $stage ) ) {
            $primary_error = $this->cleanup_incomplete_failure( $primary_error );
        }

        if ( $primary_error instanceof BackupException ) {
            throw $primary_error;
        }
        if ( ! is_array( $result ) ) {
            throw $this->recovery_failure();
        }

        return $result;
    }

    private function resolve_config(): S3BackupDeploymentConfig {
        $config = $this->config ?? S3BackupDeploymentConfig::fromDeploymentConstants();
        if ( ! $config instanceof S3BackupDeploymentConfig ) {
            throw BackupException::of( self::ERROR_NOT_CONFIGURED, self::MESSAGE_NOT_CONFIGURED );
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $pointer
     * @return array{
     *     backup_id: string,
     *     mirror_id: string,
     *     catalog_object_id: string,
     *     object_count: int,
     *     ciphertext_bytes: int,
     *     verification: string,
     *     timestamp: int,
     *     result_code: string
     * }
     */
    private function validate_pointer( array $pointer ): array {
        if ( ! $this->has_exact_keys( $pointer, BackupS3Mirror::POINTER_FIELDS ) ) {
            throw $this->recovery_failure();
        }

        $backup_id = $pointer['backup_id'];
        if ( ! is_string( $backup_id )
            || strlen( $backup_id ) > self::MAX_BACKUP_ID_BYTES
            || preg_match( '/^[0-9a-z][0-9a-z._-]{3,120}$/D', $backup_id ) !== 1
        ) {
            throw $this->recovery_failure();
        }
        if ( ! is_string( $pointer['mirror_id'] )
            || ! is_string( $pointer['catalog_object_id'] )
            || ! $this->is_opaque_id( $pointer['mirror_id'] )
            || ! $this->is_opaque_id( $pointer['catalog_object_id'] )
        ) {
            throw $this->recovery_failure();
        }
        if ( ! is_int( $pointer['object_count'] )
            || $pointer['object_count'] < 3
            || $pointer['object_count'] > self::MAX_REMOTE_OBJECT_COUNT
        ) {
            throw $this->recovery_failure();
        }
        if ( ! is_int( $pointer['ciphertext_bytes'] )
            || $pointer['ciphertext_bytes'] < self::MIN_ENVELOPE_BYTES
            || $pointer['ciphertext_bytes'] > intdiv(
                self::MAX_AGGREGATE_STAGED_BYTES - self::MANIFEST_SIDECAR_BYTES,
                2
            )
        ) {
            throw $this->recovery_failure();
        }
        if ( ! is_string( $pointer['verification'] )
            || ! in_array(
                $pointer['verification'],
                [BackupS3Mirror::STRENGTH_VERIFIED, BackupS3Mirror::STRENGTH_ACKNOWLEDGED],
                true
            )
            || ! is_int( $pointer['timestamp'] )
            || $pointer['timestamp'] <= 0
            || $pointer['result_code'] !== 'ok'
        ) {
            throw $this->recovery_failure();
        }

        return [
            'backup_id'         => $backup_id,
            'mirror_id'         => $pointer['mirror_id'],
            'catalog_object_id' => $pointer['catalog_object_id'],
            'object_count'      => $pointer['object_count'],
            'ciphertext_bytes'  => $pointer['ciphertext_bytes'],
            'verification'      => $pointer['verification'],
            'timestamp'         => $pointer['timestamp'],
            'result_code'       => 'ok',
        ];
    }

    /**
     * @param array{backup_id: string, mirror_id: string, catalog_object_id: string, object_count: int, ciphertext_bytes: int, verification: string, timestamp: int, result_code: string} $pointer
     * @param array{path: string, real: string, device: int, inode: int} $stage
     * @return array<string, mixed>
     */
    private function reconstruct(
        array $pointer,
        S3BackupDeploymentConfig $config,
        string $key,
        S3Client $client,
        array $stage
    ): array {
        $stage_root          = $stage['path'];
        $stage_real          = $stage['real'];
        $bucket              = $config->bucket();
        $mirror_id           = (string) $pointer['mirror_id'];
        $catalog_id          = (string) $pointer['catalog_object_id'];
        $catalog_cipher_path = $stage_root . '/.catalog.enc';
        $catalog_json_path   = $stage_root . '/.catalog.json';
        $catalog_key         = $this->remote_object_key( $config, $mirror_id, $catalog_id );

        $aggregate_download_budget = min(
            self::MAX_AGGREGATE_STAGED_BYTES,
            max( 0, $this->aggregate_download_budget_bytes )
        );
        $catalog_download_limit    = min(
            self::MAX_CATALOG_CIPHERTEXT_BYTES,
            max( 0, $this->catalog_download_limit_bytes ),
            $aggregate_download_budget
        );
        $catalog_cipher_bytes      = $this->download_object(
            $client,
            $bucket,
            $catalog_key,
            $catalog_cipher_path,
            $catalog_download_limit,
            null,
            null,
            $stage_real
        );
        BackupEncryptionEnvelope::decryptFile( $catalog_cipher_path, $catalog_json_path, $key );
        $this->assert_private_staged_file( $catalog_json_path, $stage_real );
        $catalog_json_bytes = $this->file_size( $catalog_json_path );
        if ( $catalog_json_bytes < 1 || $catalog_json_bytes > self::MAX_CATALOG_JSON_BYTES ) {
            throw $this->recovery_failure();
        }
        $catalog_json = file_get_contents( $catalog_json_path );
        if ( ! is_string( $catalog_json ) || strlen( $catalog_json ) !== $catalog_json_bytes ) {
            throw $this->recovery_failure();
        }
        $catalog = json_decode( $catalog_json, true, 64, JSON_THROW_ON_ERROR );
        if ( ! is_array( $catalog ) || array_is_list( $catalog ) ) {
            throw $this->recovery_failure();
        }

        $validated             = $this->validate_catalog( $pointer, $catalog, $catalog_cipher_bytes );
        $data_ciphertext_bytes = $validated['ciphertext_bytes'] - $catalog_cipher_bytes;
        $this->assert_available_staging_space( $stage_root, $data_ciphertext_bytes );
        try {
            $stage_store = ProtectedBackupStore::active( $stage_root );
        } catch ( Throwable ) {
            throw $this->recovery_failure();
        }
        $backup_dir   = $stage_root . '/' . $pointer['backup_id'];
        $backup_real  = $this->create_backup_directory( $backup_dir, $stage_real );
        $download_dir = $stage_root . '/.objects';
        $this->create_contained_directory( $download_dir, $stage_real );

        $cipher_paths                     = [];
        $remaining_data_ciphertext_bytes  = $data_ciphertext_bytes;
        $downloaded_data_ciphertext_bytes = 0;
        foreach ( $validated['entries'] as $entry ) {
            $object_id                        = $entry['object_id'];
            $cipher_path                      = $download_dir . '/' . $object_id . '.enc';
            $remote_key                       = $this->remote_object_key( $config, $mirror_id, $object_id );
            $entry_bytes                      = $entry['ciphertext_bytes'];
            $remaining_data_ciphertext_bytes -= $entry_bytes;
            if ( $remaining_data_ciphertext_bytes < 0 ) {
                throw $this->recovery_failure();
            }

            // Reserve the complete plaintext output and not-yet-downloaded
            // ciphertext before choosing this object's streaming ceiling.
            $reserved_before_current           = $catalog_cipher_bytes
                + $catalog_json_bytes
                + $downloaded_data_ciphertext_bytes
                + $remaining_data_ciphertext_bytes
                + $data_ciphertext_bytes
                + self::MANIFEST_SIDECAR_BYTES;
            $remaining_aggregate_bytes         = max(
                0,
                $aggregate_download_budget - $reserved_before_current
            );
            $object_download_limit             = min(
                self::MAX_ENCRYPTED_OBJECT_BYTES,
                max( 0, $this->object_download_limit_bytes ),
                $entry_bytes,
                $remaining_aggregate_bytes
            );
            $actual_ciphertext_bytes           = $this->download_object(
                $client,
                $bucket,
                $remote_key,
                $cipher_path,
                $object_download_limit,
                $entry_bytes,
                $entry['ciphertext_sha256'],
                $stage_real
            );
            $downloaded_data_ciphertext_bytes += $actual_ciphertext_bytes;
            $cipher_paths[ $object_id ]        = $cipher_path;
        }

        // All paths were validated before any payload request. Create and
        // canonicalize every destination directory before writing plaintext.
        $destinations   = $this->prepare_destinations(
            $validated['entries'],
            $backup_real,
            $stage_real
        );
        $manifest_entry = null;
        foreach ( $validated['entries'] as $entry ) {
            if ( $entry['role'] === 'manifest' ) {
                $manifest_entry = $entry;
                break;
            }
        }
        if ( ! is_array( $manifest_entry ) ) {
            throw $this->recovery_failure();
        }

        $manifest_path = $destinations[ $manifest_entry['object_id'] ];
        $this->reserve_destination( $manifest_path, $stage_real );
        BackupEncryptionEnvelope::decryptFile(
            $cipher_paths[ $manifest_entry['object_id'] ],
            $manifest_path,
            $key
        );
        $this->assert_private_staged_file( $manifest_path, $stage_real );
        $manifest_hash = hash_file( 'sha256', $manifest_path );
        if ( ! is_string( $manifest_hash )
            || ! hash_equals( $validated['local_manifest_sha256'], $manifest_hash )
        ) {
            throw $this->recovery_failure();
        }
        $this->write_exclusive_file(
            $backup_real . '/manifest.json.sha256',
            $validated['local_manifest_sha256'],
            $stage_real
        );

        foreach ( $validated['entries'] as $entry ) {
            if ( $entry['role'] === 'manifest' ) {
                continue;
            }
            $destination = $destinations[ $entry['object_id'] ];
            $this->reserve_destination( $destination, $stage_real );
            BackupEncryptionEnvelope::decryptFile(
                $cipher_paths[ $entry['object_id'] ],
                $destination,
                $key
            );
            $this->assert_private_staged_file( $destination, $stage_real );
        }

        try {
            $preflight = $this->backup_service->restorePreflight(
                (string) $pointer['backup_id'],
                $stage_store
            );
        } catch ( Throwable ) {
            throw $this->recovery_failure();
        }
        if ( ( $preflight['integrity_ok'] ?? false ) !== true
            || ( $preflight['db_reachable'] ?? false ) !== true
            || ( $preflight['restore_safe'] ?? false ) !== true
            || ( $preflight['legacy_unverified'] ?? true ) !== false
        ) {
            throw $this->recovery_failure();
        }

        return [
            'ok'                   => true,
            'result'               => self::SUCCESS_RESULT,
            'backup_id'            => $pointer['backup_id'],
            'mirror_id'            => $mirror_id,
            'catalog_object_id'    => $catalog_id,
            'local_verifier_ok'    => true,
            'restore_preflight_ok' => true,
        ];
    }

    /**
     * @param array{backup_id: string, mirror_id: string, catalog_object_id: string, object_count: int, ciphertext_bytes: int, verification: string, timestamp: int, result_code: string} $pointer
     * @param array<array-key, mixed> $catalog
     * @return array{entries: list<array{role: string, logical_path: string, object_id: string, ciphertext_bytes: int, ciphertext_sha256: string, remote_verification: string}>, local_manifest_sha256: string, ciphertext_bytes: int}
     */
    private function validate_catalog(
        array $pointer,
        array $catalog,
        int $catalog_cipher_bytes
    ): array {
        $catalog_fields = [
            'format',
            'format_version',
            'mirror_id',
            'catalog_object_id',
            'local_backup_id',
            'local_manifest_sha256',
            'envelope_format',
            'envelope_format_version',
            'entries',
        ];
        if ( ! $this->has_exact_keys( $catalog, $catalog_fields )
            || $catalog['format'] !== BackupS3Mirror::CATALOG_FORMAT
            || $catalog['format_version'] !== BackupS3Mirror::CATALOG_FORMAT_VERSION
            || $catalog['mirror_id'] !== $pointer['mirror_id']
            || $catalog['catalog_object_id'] !== $pointer['catalog_object_id']
            || $catalog['local_backup_id'] !== $pointer['backup_id']
            || $catalog['envelope_format'] !== BackupEncryptionEnvelope::MAGIC
            || $catalog['envelope_format_version'] !== 1
            || ! is_string( $catalog['local_manifest_sha256'] )
            || preg_match( '/^[0-9a-f]{64}$/D', $catalog['local_manifest_sha256'] ) !== 1
            || ! is_array( $catalog['entries'] )
            || ! array_is_list( $catalog['entries'] )
            || count( $catalog['entries'] ) > self::MAX_REMOTE_OBJECT_COUNT - 1
        ) {
            throw $this->recovery_failure();
        }

        $entries          = [];
        $object_ids       = [ (string) $pointer['catalog_object_id'] => true];
        $path_keys        = [];
        $database_count   = 0;
        $manifest_count   = 0;
        $ciphertext_bytes = $catalog_cipher_bytes;
        foreach ( $catalog['entries'] as $entry ) {
            if (
                ! is_array( $entry )
                || ! $this->has_exact_keys(
                    $entry,
                    [
                        'role',
                        'logical_path',
                        'object_id',
                        'ciphertext_bytes',
                        'ciphertext_sha256',
                        'remote_verification',
                    ]
                )
            ) {
                throw $this->recovery_failure();
            }
            if ( ! is_string( $entry['role'] )
                || ! is_string( $entry['logical_path'] )
                || ! is_string( $entry['object_id'] )
                || ! $this->is_opaque_id( $entry['object_id'] )
                || ! is_int( $entry['ciphertext_bytes'] )
                || $entry['ciphertext_bytes'] < self::MIN_ENVELOPE_BYTES
                || $entry['ciphertext_bytes'] > self::MAX_ENCRYPTED_OBJECT_BYTES
                || ! is_string( $entry['ciphertext_sha256'] )
                || preg_match( '/^[0-9a-f]{64}$/D', $entry['ciphertext_sha256'] ) !== 1
                || ! is_string( $entry['remote_verification'] )
                || ! in_array(
                    $entry['remote_verification'],
                    [BackupS3Mirror::STRENGTH_VERIFIED, BackupS3Mirror::STRENGTH_ACKNOWLEDGED],
                    true
                )
                || ( $pointer['verification'] === BackupS3Mirror::STRENGTH_VERIFIED
                    && $entry['remote_verification'] !== BackupS3Mirror::STRENGTH_VERIFIED
                )
            ) {
                throw $this->recovery_failure();
            }

            $object_id = $entry['object_id'];
            if ( isset( $object_ids[ $object_id ] ) ) {
                throw $this->recovery_failure();
            }
            $object_ids[ $object_id ] = true;

            $path = $entry['logical_path'];
            if ( ! $this->is_allowed_logical_path( $entry['role'], $path ) ) {
                throw $this->recovery_failure();
            }
            $path_key = strtolower( $path );
            foreach ( $path_keys as $existing_path => $_present ) {
                if ( $path_key === $existing_path
                    || str_starts_with( $path_key, $existing_path . '/' )
                    || str_starts_with( $existing_path, $path_key . '/' )
                ) {
                    // Exact duplicates, case-fold aliases and file/directory
                    // prefix collisions are all ambiguous destinations.
                    throw $this->recovery_failure();
                }
            }
            $path_keys[ $path_key ] = true;

            if ( $entry['role'] === 'database' ) {
                ++$database_count;
            } elseif ( $entry['role'] === 'manifest' ) {
                ++$manifest_count;
            } elseif ( $entry['role'] !== 'storage' ) {
                throw $this->recovery_failure();
            }

            if ( $ciphertext_bytes > PHP_INT_MAX - $entry['ciphertext_bytes'] ) {
                throw $this->recovery_failure();
            }
            $ciphertext_bytes += $entry['ciphertext_bytes'];
            $entries[]         = [
                'role'                => $entry['role'],
                'logical_path'        => $path,
                'object_id'           => $object_id,
                'ciphertext_bytes'    => $entry['ciphertext_bytes'],
                'ciphertext_sha256'   => $entry['ciphertext_sha256'],
                'remote_verification' => $entry['remote_verification'],
            ];
        }

        if ( $database_count !== 1
            || $manifest_count !== 1
            || count( $entries ) + 1 !== $pointer['object_count']
            || $ciphertext_bytes !== $pointer['ciphertext_bytes']
        ) {
            throw $this->recovery_failure();
        }

        // Every stored ciphertext can expand to no more than its byte size.
        // Reserve 64 bytes for the local manifest hash sidecar, without
        // allocating a fixture near these deliberately conservative limits.
        if ( $ciphertext_bytes > intdiv(
            self::MAX_AGGREGATE_STAGED_BYTES - self::MANIFEST_SIDECAR_BYTES,
            2
        ) ) {
            throw $this->recovery_failure();
        }
        $data_ciphertext_bytes = $ciphertext_bytes - $catalog_cipher_bytes;
        if ( $data_ciphertext_bytes < 0
            || $data_ciphertext_bytes > intdiv(
                self::MAX_AGGREGATE_STAGED_BYTES - self::MANIFEST_SIDECAR_BYTES,
                2
            )
        ) {
            throw $this->recovery_failure();
        }

        return [
            'entries'               => $entries,
            'local_manifest_sha256' => $catalog['local_manifest_sha256'],
            'ciphertext_bytes'      => $ciphertext_bytes,
        ];
    }

    private function assert_available_staging_space(
        string $stage_root,
        int $data_ciphertext_bytes
    ): void {
        if ( $data_ciphertext_bytes < 0
            || $data_ciphertext_bytes > intdiv( PHP_INT_MAX - self::MANIFEST_SIDECAR_BYTES, 2 )
        ) {
            throw $this->recovery_failure();
        }
        $required_bytes = ( $data_ciphertext_bytes * 2 ) + self::MANIFEST_SIDECAR_BYTES;
        $free_bytes     = @disk_free_space( $stage_root );
        if ( ( ! is_float( $free_bytes ) && ! is_int( $free_bytes ) ) || $free_bytes < $required_bytes ) {
            throw $this->recovery_failure();
        }
    }

    private function is_allowed_logical_path( string $role, string $path ): bool {
        if ( $path === ''
            || strlen( $path ) > self::MAX_LOGICAL_PATH_BYTES
            || preg_match( '//u', $path ) !== 1
            || preg_match( '/[\x00-\x1f\x7f]/', $path ) === 1
            || str_contains( $path, '\\' )
            || str_contains( $path, '..' )
            || str_starts_with( $path, '/' )
            || preg_match( '/^[A-Za-z]:/', $path ) === 1
            || preg_match( '/^[A-Za-z0-9._\/-]+$/D', $path ) !== 1
        ) {
            return false;
        }

        $parts = explode( '/', $path );
        foreach ( $parts as $part ) {
            if ( $part === ''
                || $part === '.'
                || $part === '..'
                || strlen( $part ) > self::MAX_PATH_COMPONENT_BYTES
                || str_ends_with( $part, '.' )
                || preg_match( '/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\..*)?$/iD', $part ) === 1
            ) {
                return false;
            }
        }

        if ( $role === 'database' ) {
            return $path === 'db.sql';
        }
        if ( $role === 'manifest' ) {
            return $path === 'manifest.json';
        }
        if ( $role !== 'storage'
            || count( $parts ) < 3
            || $parts[0] !== 'storage'
            || preg_match( '/^[1-9][0-9]{0,9}$/D', $parts[1] ) !== 1
        ) {
            return false;
        }

        return true;
    }

    /** @return array{path: string, real: string, device: int, inode: int} */
    private function create_stage_root(): array {
        if ( PHP_INT_SIZE < 8 ) {
            throw $this->recovery_failure();
        }
        $requested = $this->staging_root;
        if ( $requested === null || $requested === '' ) {
            $requested = rtrim( (string) sys_get_temp_dir(), '/' )
                . '/cpms-mirror-recovery-'
                . bin2hex( random_bytes( 16 ) );
        }
        $requested = $this->canonical_candidate( $requested );
        if ( $requested === null ) {
            throw $this->recovery_failure();
        }
        $parent = realpath( dirname( $requested ) );
        if ( ! is_string( $parent ) || ! is_dir( $parent ) ) {
            throw $this->recovery_failure();
        }
        $path = rtrim( str_replace( '\\', '/', $parent ), '/' ) . '/' . basename( $requested );
        if ( $this->is_forbidden_stage_location( $path )
            || PrivateStorageLocation::isInsideWebRoot( $path )
        ) {
            throw $this->recovery_failure();
        }
        if ( file_exists( $path ) || is_link( $path ) ) {
            throw $this->recovery_failure();
        }
        if ( ! @mkdir( $path, 0700, false ) ) {
            throw $this->recovery_failure();
        }

        $stat = @lstat( $path );
        if ( ! is_array( $stat ) || ( ( $stat['mode'] & 0170000 ) !== 0040000 ) || is_link( $path ) ) {
            if ( ( file_exists( $path ) || is_link( $path ) ) && ! @rmdir( $path ) ) {
                throw $this->cleanup_incomplete_failure();
            }
            throw $this->recovery_failure();
        }
        $real  = realpath( $path );
        $stage = [
            'path'   => $path,
            'real'   => is_string( $real ) ? $real : $path,
            'device' => (int) $stat['dev'],
            'inode'  => (int) $stat['ino'],
        ];

        try {
            if ( ! is_string( $real )
                || is_link( $path )
                || ! @chmod( $path, 0700 )
                || ( ( $this->file_mode( $path ) & 0077 ) !== 0 )
                || $this->is_forbidden_stage_location( $real )
                || PrivateStorageLocation::isInsideWebRoot( $real )
            ) {
                throw $this->recovery_failure();
            }
        } catch ( Throwable $error ) {
            if ( ! $this->remove_owned_stage( $stage ) ) {
                throw $this->cleanup_incomplete_failure(
                    $error instanceof BackupException ? $error : $this->recovery_failure()
                );
            }
            if ( $error instanceof BackupException ) {
                throw $error;
            }
            throw $this->recovery_failure();
        }

        return $stage;
    }

    private function is_forbidden_stage_location( string $candidate ): bool {
        $canonical_candidate = $this->canonical_existing_or_future_path( $candidate );
        if ( ! is_string( $canonical_candidate ) ) {
            throw $this->recovery_failure();
        }

        try {
            $clinical_roots = $this->backup_service->activeClinicalStorageRoots();
        } catch ( Throwable ) {
            throw $this->recovery_failure();
        }
        foreach ( $clinical_roots as $clinical_root ) {
            if ( ! is_string( $clinical_root ) || $clinical_root === '' ) {
                throw $this->recovery_failure();
            }
            $canonical_clinical_root = $this->canonical_existing_or_future_path( $clinical_root );
            if ( ! is_string( $canonical_clinical_root ) ) {
                // A trusted root that cannot be resolved must never be silently
                // omitted from the staging disjointness check.
                throw $this->recovery_failure();
            }
            if ( $this->canonical_path_contains( $canonical_candidate, $canonical_clinical_root )
                || $this->canonical_path_contains( $canonical_clinical_root, $canonical_candidate )
            ) {
                return true;
            }
        }

        $store_paths = [
            $this->backup_service->store()->basePath(),
            ProtectedBackupStore::defaultBasePath(),
            ProtectedBackupStore::legacyBasePath(),
        ];
        foreach ( $store_paths as $store_path ) {
            $canonical_store = $this->canonical_existing_or_future_path( $store_path );
            if ( ! is_string( $canonical_store ) ) {
                // Backup roots are trusted exclusions too; an unresolved one
                // cannot be silently omitted from the staging safety check.
                throw $this->recovery_failure();
            }
            if ( $this->canonical_path_contains( $canonical_candidate, $canonical_store )
                || $this->canonical_path_contains( $canonical_store, $canonical_candidate )
            ) {
                // Neither place staging inside a backup root nor make the
                // stage a parent that cleanup could recursively erase.
                return true;
            }
        }

        return false;
    }

    private function canonical_candidate( string $path ): ?string {
        if ( $path === ''
            || strlen( $path ) > 4096
            || ! str_starts_with( $path, '/' )
            || preg_match( '/[\x00-\x1f\x7f]/', $path ) === 1
            || str_contains( $path, '\\' )
        ) {
            return null;
        }
        $trimmed = rtrim( $path, '/' );
        if ( $trimmed === '' ) {
            return null;
        }
        foreach ( explode( '/', ltrim( $trimmed, '/' ) ) as $part ) {
            if ( $part === '' || $part === '.' || $part === '..' ) {
                return null;
            }
        }

        return $trimmed;
    }

    private function canonical_existing_or_future_path( string $path ): ?string {
        $path = $this->canonical_candidate( $path );
        if ( $path === null ) {
            return null;
        }
        $probe  = $path;
        $suffix = [];
        $real   = realpath( $probe );
        while ( ! is_string( $real ) ) {
            if ( is_link( $probe ) ) {
                return null;
            }
            $name = basename( $probe );
            if ( $name === '' || $name === '.' || $name === '..' ) {
                return null;
            }
            array_unshift( $suffix, $name );
            $parent = dirname( $probe );
            if ( $parent === $probe ) {
                return null;
            }
            $probe = $parent;
            $real  = realpath( $probe );
        }
        $resolved = rtrim( str_replace( '\\', '/', $real ), '/' );
        foreach ( $suffix as $part ) {
            $resolved .= '/' . $part;
        }

        return $resolved;
    }

    private function canonical_path_contains( string $candidate, string $root ): bool {
        $root = rtrim( $root, '/' );

        return $candidate === $root || str_starts_with( $candidate, $root . '/' );
    }

    private function is_within_canonical_path( string $candidate, string $root ): bool {
        $candidate_real = $this->canonical_existing_or_future_path( $candidate );
        $root_real      = $this->canonical_existing_or_future_path( $root );
        if ( ! is_string( $candidate_real ) || ! is_string( $root_real ) ) {
            return false;
        }
        $root_real = rtrim( $root_real, '/' );

        return $candidate_real === $root_real || str_starts_with( $candidate_real, $root_real . '/' );
    }

    private function remote_object_key(
        S3BackupDeploymentConfig $config,
        string $mirror_id,
        string $object_id
    ): string {
        if ( ! $this->is_opaque_id( $mirror_id ) || ! $this->is_opaque_id( $object_id ) ) {
            throw $this->recovery_failure();
        }
        $prefix = $config->keyPrefix();
        return ( $prefix === '' ? '' : $prefix . '/' ) . $mirror_id . '/' . $object_id;
    }

    /**
     * @param list<array{role: string, logical_path: string, object_id: string, ciphertext_bytes: int, ciphertext_sha256: string, remote_verification: string}> $entries
     * @return array<string, string>
     */
    private function prepare_destinations(
        array $entries,
        string $backup_real,
        string $stage_real
    ): array {
        $destinations = [];
        foreach ( $entries as $entry ) {
            $parts = explode( '/', $entry['logical_path'] );
            array_pop( $parts );
            $current = $backup_real;
            foreach ( $parts as $part ) {
                $current .= '/' . $part;
                $this->create_contained_directory( $current, $stage_real );
            }
            $destination = $backup_real . '/' . $entry['logical_path'];
            if ( file_exists( $destination ) || is_link( $destination ) ) {
                throw $this->recovery_failure();
            }
            $parent_real = realpath( dirname( $destination ) );
            if ( ! is_string( $parent_real )
                || ! $this->is_within_canonical_path( $parent_real, $stage_real )
            ) {
                throw $this->recovery_failure();
            }
            $destinations[ $entry['object_id'] ] = $destination;
        }

        return $destinations;
    }

    private function create_backup_directory( string $path, string $stage_real ): string {
        if ( file_exists( $path ) || is_link( $path ) || ! @mkdir( $path, 0700, false ) ) {
            throw $this->recovery_failure();
        }
        if ( ! @chmod( $path, 0700 ) ) {
            throw $this->recovery_failure();
        }
        $real = realpath( $path );
        if ( ! is_string( $real )
            || is_link( $path )
            || ( ( $this->file_mode( $path ) & 0077 ) !== 0 )
            || ! $this->is_within_canonical_path( $real, $stage_real )
        ) {
            throw $this->recovery_failure();
        }

        return $real;
    }

    private function create_contained_directory( string $path, string $stage_real ): void {
        if ( is_link( $path ) ) {
            throw $this->recovery_failure();
        }
        if ( ! file_exists( $path ) ) {
            if ( ! @mkdir( $path, 0700, false ) ) {
                throw $this->recovery_failure();
            }
            if ( ! @chmod( $path, 0700 ) ) {
                throw $this->recovery_failure();
            }
        }
        if ( ! is_dir( $path ) || is_link( $path ) ) {
            throw $this->recovery_failure();
        }
        $real = realpath( $path );
        if ( ! is_string( $real )
            || ( ( $this->file_mode( $path ) & 0077 ) !== 0 )
            || ! $this->is_within_canonical_path( $real, $stage_real )
        ) {
            throw $this->recovery_failure();
        }
    }

    private function reserve_destination( string $path, string $stage_real ): void {
        if ( file_exists( $path ) || is_link( $path ) ) {
            throw $this->recovery_failure();
        }
        $parent_real = realpath( dirname( $path ) );
        if ( ! is_string( $parent_real ) || ! $this->is_within_canonical_path( $parent_real, $stage_real ) ) {
            throw $this->recovery_failure();
        }
        $handle = @fopen( $path, 'xb' );
        if ( ! is_resource( $handle ) ) {
            throw $this->recovery_failure();
        }
        $ok     = @chmod( $path, 0600 );
        $closed = fclose( $handle );
        if ( ! $ok || ! $closed || ( ( $this->file_mode( $path ) & 0077 ) !== 0 ) ) {
            throw $this->recovery_failure();
        }
        $real = realpath( $path );
        if ( ! is_string( $real ) || ! $this->is_within_canonical_path( $real, $stage_real ) ) {
            throw $this->recovery_failure();
        }
    }

    private function write_exclusive_file( string $path, string $contents, string $stage_real ): void {
        $this->reserve_destination( $path, $stage_real );
        $handle = @fopen( $path, 'wb' );
        if ( ! is_resource( $handle ) ) {
            throw $this->recovery_failure();
        }
        $flush_ok = false;
        $close_ok = false;
        try {
            $this->write_all( $handle, $contents );
            $flush_ok = fflush( $handle );
        } finally {
            $close_ok = fclose( $handle );
        }
        clearstatcache( true, $path );
        if ( ! $flush_ok || ! $close_ok || filesize( $path ) !== strlen( $contents ) ) {
            throw $this->recovery_failure();
        }
    }

    /**
     * Stream GetObject directly into an exclusive private file sink. A
     * trustworthy oversized Content-Length is rejected in Guzzle's
     * pre-consumption on_headers hook; the write-only sink independently
     * enforces the hard ceiling for absent, malformed, chunked or dishonest
     * lengths. ETag is deliberately never inspected.
     */
    private function download_object(
        S3Client $client,
        string $bucket,
        string $key,
        string $destination,
        int $maximum_bytes,
        ?int $expected_bytes,
        ?string $expected_sha256,
        string $stage_real
    ): int {
        $maximum_bytes = max( 0, $maximum_bytes );
        $hard_limit    = $expected_bytes === null
            ? $maximum_bytes
            : min( $maximum_bytes, max( 0, $expected_bytes ) );
        if ( file_exists( $destination ) || is_link( $destination ) ) {
            throw $this->recovery_failure();
        }
        $parent_real = realpath( dirname( $destination ) );
        if ( ! is_string( $parent_real ) || ! $this->is_within_canonical_path( $parent_real, $stage_real ) ) {
            throw $this->recovery_failure();
        }

        $handle = @fopen( $destination, 'xb' );
        if ( ! is_resource( $handle ) ) {
            throw $this->recovery_failure();
        }
        $sink            = null;
        $response_length = null;
        $completed       = false;
        try {
            if ( ! @chmod( $destination, 0600 ) ) {
                throw $this->recovery_failure();
            }
            $this->assert_private_staged_file( $destination, $stage_real );
            $sink = $this->bounded_download_sink( $handle, $hard_limit );

            $on_headers = function ( ResponseInterface $response, ?RequestInterface $request = null ) use ( &$response_length, $hard_limit ): void {
                if ( $request !== null && strtoupper( $request->getMethod() ) !== 'GET' ) {
                    throw new \RuntimeException( 'bounded download requires a GET response' );
                }
                $response_length = $this->trustworthy_content_length(
                    $response->getHeader( 'Content-Length' )
                );
                if ( $response_length !== null && $response_length > $hard_limit ) {
                    // Guzzle calls this hook before handing response-body bytes
                    // to the sink, so no over-limit prefix is consumed here.
                    throw new \RuntimeException( 'response exceeds bounded download sink' );
                }
            };

            $response = $client->getObject(
                [
                    'Bucket' => $bucket,
                    'Key'    => $key,
                    '@http'  => [
                        'sink'       => $sink,
                        'on_headers' => $on_headers,
                    ],
                ]
            );
            $body     = $response['Body'] ?? null;
            if ( ! $body instanceof StreamInterface ) {
                throw $this->recovery_failure();
            }

            // The response body is the same write-only stream passed as the
            // SDK sink. Never read or stringify it in PHP.
            if ( $body !== $sink ) {
                throw $this->recovery_failure();
            }
            $sink->close();
            $sink   = null;
            $handle = null;

            $this->assert_private_staged_file( $destination, $stage_real );
            $actual_bytes = $this->file_size( $destination );
            if ( $actual_bytes > $hard_limit
                || ( $response_length !== null && $actual_bytes !== $response_length )
                || ( $expected_bytes !== null && $actual_bytes !== $expected_bytes )
            ) {
                throw $this->recovery_failure();
            }
            if ( is_string( $expected_sha256 ) ) {
                $actual_sha256 = hash_file( 'sha256', $destination );
                if ( ! is_string( $actual_sha256 ) || ! hash_equals( $expected_sha256, $actual_sha256 ) ) {
                    throw $this->recovery_failure();
                }
            }

            $completed = true;
            return $actual_bytes;
        } catch ( Throwable ) {
            throw $this->recovery_failure();
        } finally {
            if ( $sink instanceof StreamInterface ) {
                try {
                    $sink->close();
                } catch ( Throwable ) {
                    @unlink( $destination );
                }
            } elseif ( is_resource( $handle ) ) {
                @fclose( $handle );
            }
            if ( ! $completed && ( file_exists( $destination ) || is_link( $destination ) ) ) {
                @unlink( $destination );
            }
        }
    }

    /**
     * @param resource $handle
     */
    private function bounded_download_sink( $handle, int $maximum_bytes ): StreamInterface {
        $stream  = Utils::streamFor( $handle );
        $written = 0;
        $write   = static function ( string $chunk ) use ( $stream, &$written, $maximum_bytes ): int {
            $chunk_bytes = strlen( $chunk );
            if ( $chunk_bytes > $maximum_bytes - $written ) {
                throw new \LengthException( 'bounded download sink limit reached' );
            }

            $offset = 0;
            while ( $offset < $chunk_bytes ) {
                $written_bytes = $stream->write( substr( $chunk, $offset ) );
                if ( $written_bytes < 1 ) {
                    throw new \RuntimeException( 'bounded download sink write failed' );
                }
                $offset += $written_bytes;
            }
            $written += $chunk_bytes;

            return $chunk_bytes;
        };

        return FnStream::decorate(
            $stream,
            [
                '__toString'  => static fn (): string => '',
                'getContents' => static function (): string {
                    throw new \RuntimeException( 'bounded download sink is write-only' );
                },
                'isReadable'  => static fn (): bool => false,
                'read'        => static function ( int $length ): string {
                    if ( $length < 0 ) {
                        throw new \InvalidArgumentException( 'bounded download read length is invalid' );
                    }
                    throw new \RuntimeException( 'bounded download sink is write-only' );
                },
                'write'       => $write,
            ]
        );
    }

    /** @param list<string> $values */
    private function trustworthy_content_length( array $values ): ?int {
        if ( count( $values ) !== 1 ) {
            return null;
        }
        $value = trim( $values[0], " \t" );
        if ( $value === '' || preg_match( '/^[0-9]+$/D', $value ) !== 1 ) {
            return null;
        }
        $normalized = ltrim( $value, '0' );
        if ( $normalized === '' ) {
            return 0;
        }
        $maximum = (string) PHP_INT_MAX;
        if ( strlen( $normalized ) > strlen( $maximum )
            || ( strlen( $normalized ) === strlen( $maximum ) && strcmp( $normalized, $maximum ) > 0 )
        ) {
            // PHP_INT_MAX is still greater than every supported transfer cap.
            return PHP_INT_MAX;
        }

        return (int) $normalized;
    }

    private function assert_private_staged_file( string $path, string $stage_real ): void {
        if ( is_link( $path ) || ! is_file( $path ) || ! @chmod( $path, 0600 ) ) {
            throw $this->recovery_failure();
        }
        $real = realpath( $path );
        if ( ! is_string( $real )
            || ( ( $this->file_mode( $path ) & 0077 ) !== 0 )
            || ! $this->is_within_canonical_path( $real, $stage_real )
        ) {
            throw $this->recovery_failure();
        }
    }

    private function file_size( string $path ): int {
        clearstatcache( true, $path );
        $size = is_file( $path ) ? filesize( $path ) : false;
        if ( ! is_int( $size ) || $size < 0 ) {
            throw $this->recovery_failure();
        }

        return $size;
    }

    /** @param resource $handle */
    private function write_all( $handle, string $bytes ): void {
        $length = strlen( $bytes );
        $offset = 0;
        while ( $offset < $length ) {
            $written = fwrite( $handle, substr( $bytes, $offset ) );
            if ( ! is_int( $written ) || $written < 1 ) {
                throw $this->recovery_failure();
            }
            $offset += $written;
        }
    }

    /** @param array{path: string, real: string, device: int, inode: int} $stage */
    private function remove_owned_stage( array $stage ): bool {
        try {
            $path = $stage['path'];
            if ( ! file_exists( $path ) && ! is_link( $path ) ) {
                return true;
            }
            if ( is_link( $path ) ) {
                return false;
            }
            $stat = @lstat( $path );
            if ( ! is_array( $stat )
                || ( ( $stat['mode'] & 0170000 ) !== 0040000 )
                || (int) $stat['dev'] !== $stage['device']
                || (int) $stat['ino'] !== $stage['inode']
            ) {
                return false;
            }

            return $this->remove_owned_directory( $path, true );
        } catch ( Throwable ) {
            return false;
        }
    }

    private function remove_owned_directory( string $path, bool $root = false ): bool {
        if ( is_link( $path ) ) {
            return ! $root && @unlink( $path );
        }
        if ( ! is_dir( $path ) ) {
            return ! file_exists( $path ) || @unlink( $path );
        }
        if ( ! @chmod( $path, 0700 ) ) {
            return false;
        }
        $children = @scandir( $path );
        if ( ! is_array( $children ) ) {
            return false;
        }
        $complete = true;
        foreach ( $children as $name ) {
            if ( $name === '.' || $name === '..' ) {
                continue;
            }
            $child = $path . '/' . $name;
            if ( is_link( $child ) ) {
                if ( ! @unlink( $child ) ) {
                    $complete = false;
                }
            } elseif ( is_dir( $child ) ) {
                if ( ! $this->remove_owned_directory( $child ) ) {
                    $complete = false;
                }
            } elseif ( file_exists( $child ) && ! @unlink( $child ) ) {
                $complete = false;
            }
        }
        if ( ! @rmdir( $path ) ) {
            $complete = false;
        }

        return $complete && ! file_exists( $path ) && ! is_link( $path );
    }

    private function file_mode( string $path ): int {
        clearstatcache( true, $path );
        $mode = fileperms( $path );
        if ( ! is_int( $mode ) ) {
            throw $this->recovery_failure();
        }

        return $mode & 0777;
    }

    private function is_opaque_id( mixed $value ): bool {
        return is_string( $value ) && preg_match( '/^[0-9a-f]{32}$/D', $value ) === 1;
    }

    /** @param array<array-key, mixed> $value @param list<string> $expected */
    private function has_exact_keys( array $value, array $expected ): bool {
        $actual = array_keys( $value );
        sort( $actual );
        sort( $expected );

        return $actual === $expected;
    }

    private function cleanup_incomplete_failure(
        ?BackupException $primary_error = null
    ): BackupException {
        $data = [
            'cleanup' => [
                'attempted' => true,
                'complete'  => false,
            ],
        ];
        if ( $primary_error instanceof BackupException ) {
            $data['primary_error_code'] = $primary_error->getErrorCode();
        }

        return BackupException::of(
            self::ERROR_RECOVERY_FAILED,
            self::MESSAGE_RECOVERY_FAILED,
            $data
        );
    }

    private function recovery_failure(): BackupException {
        return BackupException::of( self::ERROR_RECOVERY_FAILED, self::MESSAGE_RECOVERY_FAILED );
    }
}
