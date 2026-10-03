<?php
/**
 * Part 15 — Slice 2C: آینه‌سازیِ **صریح** و **رمزنگاری‌شده** یک بکاپِ محلیِ موجود روی S3.
 *
 * قرارداد (پذیرفته‌شده در suiteٔ RED — `tests/Unit/Phase15S3MirrorUploadRedTest.php`):
 *  - تنها ورودی، شناسهٔ یک بکاپِ محلیِ **از‌پیش‌موجود** است؛ هیچ بکاپِ تازه‌ای ساخته
 *    نمی‌شود و این عملیات به `backup.run`/Job/زمان‌بند وصل نیست (عمداً).
 *  - پیش‌شرط مطلق: راستی‌آزماییِ «تثبیت‌شدهٔ محلی» (`LocalBackupVerifier` — همان منطقِ
 *    `BackupService::verifyIn`) به‌همراه الزامِ sidecar؛ تا این پاس نشود **هیچ**
 *    درخواستِ راه‌دور اجرا نمی‌شود. مبدأ محلی در همهٔ مسیرها (موفق/ناموفق) بایت‌به‌بایت
 *    دست‌نخورده است و این کلاس هرگز prune/delete روی محلی انجام نمی‌دهد.
 *  - مجموعهٔ راه‌دور: `db.sql` + `manifest.json` + یک شیء به‌ازای هر فایلِ storageِ
 *    مندرج در مانیفست + **یک کاتالوگِ فقط‌راه‌دور که آخر از همه آپلود می‌شود**.
 *    `manifest.json.sha256` عمداً شیءِ مستقلِ راه‌دور نیست (اثباتِ جانبیِ محلی است).
 *  - هر کلید = پیشوندِ پیکربندی‌شده + شناسهٔ opaque آینه + شناسهٔ opaque شیء
 *    (هرکدام ۱۶ بایت تصادفیِ رمزنگاری‌شده ⇒ ۱۲۸ بیت ⇒ ۳۲ کاراکتر hex)؛ پس هیچ
 *    شناسهٔ بکاپِ محلی / نام فایل / مسیر نسبی / هویتِ Clinic-بیمار-MRN / یادداشت /
 *    timestampِ مشتق‌شده از شناسهٔ بکاپ در نام اشیاء ظاهر نمی‌شود.
 *  - رمزنگاری: **فقط** `BackupEncryptionEnvelope` (Slice 1) پیش از هر درخواست؛ فقط
 *    ciphertext وارد بدنهٔ SDK می‌شود؛ کلید فقط از accessor باریکِ
 *    `S3BackupDeploymentConfig::encryptionKeyForBackupEnvelope()` خوانده می‌شود و
 *    هرگز در client config / metadata / log / audit / error / نامِ شیء قرار نمی‌گیرد.
 *  - سقفِ تک‌PUT: این slice **تک‌بخشی (single-part)** است — فقط `PutObject`؛ بدونِ
 *    `CreateMultipartUpload` / `UploadPart` / `CompleteMultipartUpload` / `MultipartUploader`.
 *    سقفِ محافظه‌کارانهٔ محصول `MAX_ENCRYPTED_OBJECT_BYTES` = 4 GiB = 4 * 1024^3 =
 *    4294967296 **بایتِ ciphertext** است (واحد: بایتِ باینری، صریحاً مستند).
 *    دلیلِ عدد: AWS سقفِ یک PUT را «5 GB» می‌نویسد و S3 FAQs همان را 5 GB =
 *    5368709120 بایت (دوئِ باینری) صریح کرده است؛ SDK قفل‌شده نیز همین را با ثابتِ
 *    `Aws\S3\MultipartUploader::PART_MAX_SIZE === 5368709120` بیان می‌کند ⇒ 4 GiB
 *    دقیقاً 1 GiB زیرِ سقفِ رسمی است. هیچ آستانهٔ ساختگی (مثلاً ۶۴MiB) استفاده نشده
 *    و `ObjectUploader::DEFAULT_MULTIPART_THRESHOLD` نیز heuristic خودکار است، نه limit.
 *    شیءِ بزرگ‌تر **پیش از** هر درخواست، Fail-Closed رد می‌شود.
 *  - راستی‌آزماییِ هر شیء: اندازه + SHA-256ِ محلیِ ciphertext محاسبه و همان digest
 *    به‌صورتِ `ChecksumSHA256` (هدر `x-amz-checksum-sha256`) در همان PUT ارسال می‌شود؛
 *    پاسخِ موفقِ PutObject (2xx) شرطِ لازمِ هر ادعاست. اگر endpoint اثباتِ سرویس را
 *    برگرداند (`PutObjectOutput.ChecksumSHA256` هم‌خوان با digest محلی و
 *    `x-amz-object-size` برابر اندازهٔ ciphertext محلی) ⇒ `VERIFIED`؛ اگر نتواند ⇒
 *    `ACKNOWLEDGED` که **هرگز** verified گزارش/لاگ نمی‌شود. ناهم‌خوانیِ **صریحِ**
 *    checksum یا size ⇒ شکستِ Fail-Closed. ETag و metadataِ اکوی‌شده هرگز دلیلِ
 *    برابریِ بدنه نیستند؛ HEAD هم برای اثباتِ بدنه استفاده نمی‌شود (پس جز PUT و
 *    نظافتِ bounded، هیچ درخواستِ دیگری در این عملیات نیست).
 *  - نتیجهٔ سطح‌مجموعه: همه `VERIFIED` ⇒ `RESULT_VERIFIED`؛ همه ≥ `ACKNOWLEDGED` با
 *    حداقل یک `ACKNOWLEDGED` ⇒ `RESULT_ACKNOWLEDGED`؛ هر شیء ناموفق ⇒ عملیات ناموفق.
 *    کاتالوگ هم در همین تجمیع حساب می‌شود. «آخری‌بودنِ کاتالوگ» فقط ترتیب/کمکِ
 *    بازیابیِ خوانندهٔ بعدی است، **نه** ادعای تراکنشِ اتمیک یا visibility هم‌زمان.
 *  - هیچ ادعای protected / recoverable / restore-ready / RPO / RTO در این slice نیست.
 *
 * وضعیتِ موقتِ محلی: ciphertextها و plaintextِ کاتالوگ در `scratch_dir` خصوصی نوشته
 * و در **همهٔ** مسیرهای پایان (موفق / ناموفق / استثنا) پاک می‌شوند؛ کاتالوگ هیچ‌گاه
 * در DB ذخیره نمی‌شود (بدونِ جدول/migration) و هیچ فایلِ کاتالوگی در ساختارِ بکاپِ
 * محلی باقی نمی‌ماند.
 *
 * نظافتِ راه‌دور: اگر پس از آپلودِ بخشی از مجموعه عملیات شکست بخورد، فقط و فقط همان
 * اشیاءِ همین تلاش (با کلیدهای opaqueٔ خودِ همین تلاش) به‌صورتِ best-effort پاک
 * می‌شوند — بدونِ هیچ API عمومیِ delete/retention و بدونِ ListObjects. اگر نظافت
 * کامل نشود پنهان نمی‌ماند: شمارشِ bounded + شناسه‌های opaque در `data['cleanup']`
 * خطا و در لاگِ عملیاتی ثبت می‌شود.
 *
 * ردپای audit/عملیاتی: همان سازوکار موجود، فقط با pointer boundedٔ `POINTER_FIELDS`
 * (شناسهٔ بکاپ محلی، شناسهٔ opaque آینه، شناسهٔ opaque کاتالوگ، تعداد اشیاء، مجموع
 * بایت ciphertext، قدرتِ راستی‌آزماییِ تجمعی، timestamp/result code). هیچ مسیرِ
 * منطقی، PHI، راز یا مقدارِ پیکربندی آنجا نمی‌رود.
 */

declare(strict_types=1);

// phpcs:disable WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions
//   I/O خامِ کنترل‌شده در این کلاس عمدی است: مبدأ بکاپ نباید با WP_Filesystem دست‌خورد
//   شود (read-only/atomic/realpath semanticsِ خودِ PHP لازم است)؛ همهٔ نتایج بررسی
//   می‌شوند و `@` فقط برای مسیرهای غیرحساس (نظافت) استفاده شده؛ `base64_encode` اینجا
//   encodingِ استانداردِ هدرِ checksumِ S3 است، نه obfuscation.

namespace ClinicCore\Application\Backup;

use Aws\S3\S3Client;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Backup\BackupEncryptionEnvelope;
use ClinicCore\Infrastructure\Backup\BackupException;
use ClinicCore\Infrastructure\Backup\ProtectedBackupStore;
use ClinicCore\Infrastructure\Backup\S3BackupClientFactory;
use ClinicCore\Infrastructure\Backup\S3BackupDeploymentConfig;
use ClinicCore\Infrastructure\Logging\OpLogger;

/**
 * عملیاتِ صریحِ آینه‌سازیِ رمزنگاری‌شدهٔ یک بکاپِ محلیِ موجود (بدونِ recovery).
 */
final class BackupS3Mirror {
	/** قالبِ کاتالوگِ فقط‌راه‌دور. */
	public const CATALOG_FORMAT         = 'cpms-s3-mirror-catalog';
	public const CATALOG_FORMAT_VERSION = 1;

	/** سقفِ تک‌PUT روی ciphertext: 4 GiB = 4 * 1024^3 = 4294967296 بایت (باینری، صریح). */
	public const MAX_ENCRYPTED_OBJECT_BYTES = 4294967296;

	public const STRENGTH_VERIFIED     = 'VERIFIED';
	public const STRENGTH_ACKNOWLEDGED = 'ACKNOWLEDGED';

	/** تنها دو ادعای مجازِ سطح‌مجموعه. */
	public const RESULT_VERIFIED     = 'encrypted upload verified';
	public const RESULT_ACKNOWLEDGED = 'encrypted upload acknowledged; checksum verification unavailable';

	/** تنها فیلدهای مجازِ ردپای ماندگار (audit/عملیاتی) — به همین ترتیب. */
	public const POINTER_FIELDS = [
		'backup_id',
		'mirror_id',
		'catalog_object_id',
		'object_count',
		'ciphertext_bytes',
		'verification',
		'timestamp',
		'result_code',
	];

	private const ROLE_DATABASE = 'database';
	private const ROLE_MANIFEST = 'manifest';
	private const ROLE_STORAGE  = 'storage';
	private const ROLE_CATALOG  = 'catalog';

	/** ۱۶ بایت تصادفیِ رمزنگاری‌شده ⇒ ۱۲۸ بیت آنتروپی برای هر دو شناسهٔ opaque. */
	private const ID_BYTES = 16;

	/** نسخهٔ قالبِ envelope که در کاتالوگ ثبت می‌شود (marker: `BackupEncryptionEnvelope::MAGIC`). */
	private const ENVELOPE_FORMAT_VERSION = 1;

	/** کدهای خطا — registry: `docs/api/error-codes.md` (خانوادهٔ CLINIC_BACKUP_*، ADR-0019). */
	private const E_NOT_CONFIGURED    = 'CLINIC_BACKUP_MIRROR_NOT_CONFIGURED';
	private const E_LOCAL_INVALID     = 'CLINIC_BACKUP_MIRROR_LOCAL_INVALID';
	private const E_ENCRYPTION_FAILED = 'CLINIC_BACKUP_MIRROR_ENCRYPTION_FAILED';
	private const E_OBJECT_TOO_LARGE  = 'CLINIC_BACKUP_MIRROR_OBJECT_TOO_LARGE';
	private const E_UPLOAD_FAILED     = 'CLINIC_BACKUP_MIRROR_UPLOAD_FAILED';
	private const E_CHECKSUM_MISMATCH = 'CLINIC_BACKUP_MIRROR_CHECKSUM_MISMATCH';
	private const E_SIZE_MISMATCH     = 'CLINIC_BACKUP_MIRROR_SIZE_MISMATCH';

	private const SCRATCH_PREFIX  = 'cpms-mirror-';
	private const DEFAULT_DB_FILE = 'db.sql';
	private const MANIFEST_FILE   = 'manifest.json';

	/** اقدام‌های لاگِ عملیاتی/audit — هم‌خانوادهٔ BACKUP_CREATED / BACKUP_DELETED. */
	private const OP_UPLOADED           = 'BACKUP_MIRROR_UPLOADED';
	private const OP_FAILED             = 'BACKUP_MIRROR_FAILED';
	private const OP_CLEANUP_INCOMPLETE = 'BACKUP_MIRROR_CLEANUP_INCOMPLETE';

	/**
     * seamِ مستندِ Slice 2B برای تست (در تولید همواره null است).
     *
     * @var callable|null
     */
	private $http_handler;

	public function __construct(
		private readonly ProtectedBackupStore $store,
		private readonly ?S3BackupDeploymentConfig $config = null,
		?callable $http_handler = null,
		private readonly ?string $scratch_dir = null,
		private readonly ?AuditLogger $audit = null,
		private readonly ?OpLogger $op = null
	) {
		$this->http_handler = $http_handler;
	}

	/**
     * آینه‌سازیِ رمزنگاری‌شدهٔ **یک** بکاپِ محلیِ موجود.
     *
     * @return array<string, mixed> نتیجهٔ bounded
     *
     * @throws BackupException کدِ boundedٔ `CLINIC_BACKUP_MIRROR_*` — بدونِ مسیر/کلید/راز/PHI
     */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- نامِ عمومیِ تثبیت‌شدهٔ قرارداد Phase 15 (suiteٔ RED پذیرفته‌شده).
	public function mirrorBackup( string $local_backup_id ): array {
		$config = $this->config;
		if ( $config === null ) {
			throw BackupException::of( self::E_NOT_CONFIGURED, 'S3 backup mirroring is not configured' );
		}

		// (1) پیش‌شرطِ محلی — پیش از هر درخواستِ راه‌دور (Fail-Closed).
		$local        = $this->verify_local( $local_backup_id );
		$dir          = (string) $local['dir'];
		$manifest     = (array) $local['manifest'];
		$manifest_sha = (string) hash_file( 'sha256', $dir . '/' . self::MANIFEST_FILE );

		$mirror_id = $this->hex_id();
		$key_root  = rtrim( $config->keyPrefix(), '/' ) . '/' . $mirror_id;
		// تنها نقطهٔ خواندنِ کلید؛ هرگز به client/log/error/نامِ شیء نمی‌رسد.
		$encryption_key = $config->encryptionKeyForBackupEnvelope();
		$scratch        = $this->scratch_root();
		$bucket         = $config->bucket();

		$staged       = array();
		$uploaded     = array();
		$objects      = array();
		$entries      = array();
		$total_bytes  = 0;
		$all_verified = true;
		$client       = null;

		try {
			$client = $this->client_for( $config );

			// (2) اشیاءِ داده — هرکدام: رمزنگاریِ محلی → گیتِ اندازه → PUT → strength.
			foreach ( $this->remote_objects( $dir, $manifest ) as $object ) {
				$source      = (string) $object['source'];
				$plain_bytes = self::bytes_of( $source );
				// پیش‌سنجیِ تحلیلی: مانعِ تخصیصِ ciphertextِ محکوم‌به‌رد می‌شود.
				$this->assert_ciphertext_within_single_put_limit( $this->expected_ciphertext_bytes( $plain_bytes ) );

				$object_id   = $this->hex_id();
				$cipher_path = $scratch['dir'] . '/' . self::SCRATCH_PREFIX . $object_id . '.enc';
				$staged[] = $cipher_path;
				$this->encrypt_to_scratch( $source, $cipher_path, $encryption_key );

				$cipher_bytes = self::bytes_of( $cipher_path );
				$this->assert_ciphertext_within_single_put_limit( $cipher_bytes );
				$cipher_sha = (string) hash_file( 'sha256', $cipher_path );

				try {
					$strength = $this->upload_object(
						$this->client_or_throw( $client ),
						$bucket,
						$key_root . '/' . $object_id,
						$cipher_path,
						$cipher_bytes,
						base64_encode( (string) hex2bin( $cipher_sha ) )
					);
				} catch ( BackupException $partial ) {
					if ( ! empty( $partial->data['stored'] ) ) {
						$uploaded[] = $object_id;
					}
					throw $partial;
				}
				$uploaded[] = $object_id;
				if ( $strength !== self::STRENGTH_VERIFIED ) {
					$all_verified = false;
				}

				$objects[] = array(
					'role'              => (string) $object['role'],
					'logical_path'      => (string) $object['logical_path'],
					'object_id'         => $object_id,
					'ciphertext_bytes'  => $cipher_bytes,
					'ciphertext_sha256' => $cipher_sha,
					'strength'          => $strength,
				);
				$entries[] = array(
					'role'                => (string) $object['role'],
					'logical_path'        => (string) $object['logical_path'],
					'object_id'           => $object_id,
					'ciphertext_bytes'    => $cipher_bytes,
					'ciphertext_sha256'   => $cipher_sha,
					'remote_verification' => $strength,
				);
				$total_bytes += $cipher_bytes;
			}

			// (3) کاتالوگ — تنها شیءِ «فقط‌راه‌دور»، آخر از همه (کمکِ ترتیب/بازیابی، نه اتمیسیتی).
			$catalog_object_id = $this->hex_id();
			$catalog_plain     = $scratch['dir'] . '/' . self::SCRATCH_PREFIX . $catalog_object_id . '.catalog.json';
			$staged[] = $catalog_plain;
			$this->write_catalog( $catalog_plain, $mirror_id, $catalog_object_id, $local_backup_id, $manifest_sha, $entries );

			$cipher_path = $scratch['dir'] . '/' . self::SCRATCH_PREFIX . $catalog_object_id . '.enc';
			$staged[] = $cipher_path;
			$this->encrypt_to_scratch( $catalog_plain, $cipher_path, $encryption_key );

			$cipher_bytes = self::bytes_of( $cipher_path );
			$this->assert_ciphertext_within_single_put_limit( $cipher_bytes );
			$cipher_sha = (string) hash_file( 'sha256', $cipher_path );

			try {
				$strength = $this->upload_object(
					$this->client_or_throw( $client ),
					$bucket,
					$key_root . '/' . $catalog_object_id,
					$cipher_path,
					$cipher_bytes,
					base64_encode( (string) hex2bin( $cipher_sha ) )
				);
			} catch ( BackupException $partial ) {
				if ( ! empty( $partial->data['stored'] ) ) {
					$uploaded[] = $catalog_object_id;
				}
				throw $partial;
			}
			$uploaded[] = $catalog_object_id;
			if ( $strength !== self::STRENGTH_VERIFIED ) {
				$all_verified = false;
			}
			$objects[] = array(
				'role'              => self::ROLE_CATALOG,
				// کاتالوگ همتای محلی ندارد؛ logical_path فقط در plaintextٔ رمزنگاری‌شدهٔ خودش مجاز است.
				'logical_path'      => null,
				'object_id'         => $catalog_object_id,
				'ciphertext_bytes'  => $cipher_bytes,
				'ciphertext_sha256' => $cipher_sha,
				'strength'          => $strength,
			);
			$total_bytes += $cipher_bytes;

			$result = array(
				'ok'                => true,
				'result'            => $all_verified ? self::RESULT_VERIFIED : self::RESULT_ACKNOWLEDGED,
				'backup_id'         => $local_backup_id,
				'mirror_id'         => $mirror_id,
				'catalog_object_id' => $catalog_object_id,
				'object_count'      => count( $objects ),
				'ciphertext_bytes'  => $total_bytes,
				'verification'      => $all_verified ? self::STRENGTH_VERIFIED : self::STRENGTH_ACKNOWLEDGED,
				'timestamp'         => time(),
				'result_code'       => 'ok',
				'objects'           => $objects,
			);
			$this->record_success( $result );

			return $result;
		} catch ( BackupException $error ) {
			throw $this->with_cleanup_evidence( $error, $client, $bucket, $key_root, $uploaded, $local_backup_id, $mirror_id );
		} finally {
			$this->purge_scratch( (string) $scratch['dir'], $staged, (bool) $scratch['created'] );
		}
	}

	/**
     * تنها برداشتِ مجازِ ردپا برای audit/لاگِ عملیاتی — هیچ دادهٔ دیگری عبور نمی‌کند.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- نامِ عمومیِ تثبیت‌شدهٔ قرارداد Phase 15 (suiteٔ RED پذیرفته‌شده).
	public static function pointerFromResult( array $result ): array {
		return array(
			'backup_id'         => (string) ( $result['backup_id'] ?? '' ),
			'mirror_id'         => (string) ( $result['mirror_id'] ?? '' ),
			'catalog_object_id' => (string) ( $result['catalog_object_id'] ?? '' ),
			'object_count'      => (int) ( $result['object_count'] ?? 0 ),
			'ciphertext_bytes'  => (int) ( $result['ciphertext_bytes'] ?? 0 ),
			'verification'      => (string) ( $result['verification'] ?? '' ),
			'timestamp'         => (int) ( $result['timestamp'] ?? 0 ),
			'result_code'       => (string) ( $result['result_code'] ?? 'unknown' ),
		);
	}

	/**
     * بکاپ باید در مخزن محفوظ باشد و با **همان** راستی‌آزماییِ تثبیت‌شده پاس شود؛
     * sidecarِ مانیفست نیز الزامی است (بدونِ آن digestٔ منتقل‌شده/ثبت‌شده مؤثق نیست).
     *
     * @return array{dir: string, manifest: array<string, mixed>}
     */
	private function verify_local( string $backup_id ): array {
		try {
			if ( ! $this->store->exists( $backup_id ) ) {
				throw BackupException::of( self::E_LOCAL_INVALID, 'local backup not found' );
			}
			$dir = $this->store->dirOf( $backup_id );
		} catch ( BackupException $error ) {
			if ( $error->getErrorCode() === self::E_LOCAL_INVALID ) {
				throw $error;
			}
			// شناسهٔ غیرمجاز از `ProtectedBackupStore` — همان Fail-Closed، با کدِ boundedٔ خودِ slice.
			throw BackupException::of( self::E_LOCAL_INVALID, 'local backup not found' );
		}

		$verified = LocalBackupVerifier::verify( $dir, $backup_id, true );
		if ( ! (bool) $verified['ok'] || ! is_array( $verified['manifest'] ) ) {
			throw BackupException::of( self::E_LOCAL_INVALID, 'local backup failed verification' );
		}

		return array( 'dir' => $dir, 'manifest' => $verified['manifest'] );
	}

	/**
     * نگاشتِ «مجموعهٔ منطقیِ اشیاء» از مانیفستِ راستی‌آزمایی‌شده.
     * `manifest.json.sha256` در این فهرست نیست (تصمیمِ صریحِ قرارداد).
     *
     * @param array<string, mixed> $manifest
     *
     * @return list<array{role: string, logical_path: string, source: string}>
     */
	private function remote_objects( string $dir, array $manifest ): array {
		$db_file      = (string) ( $manifest['db']['file'] ?? self::DEFAULT_DB_FILE );
		$storage_root = (string) ( $manifest['storage']['root'] ?? 'storage' );
		self::assert_relative( $db_file );
		self::assert_relative( $storage_root );

		$objects = array(
			array(
				'role'         => self::ROLE_DATABASE,
				'logical_path' => $db_file,
				'source'       => $dir . '/' . $db_file,
			),
			array(
				'role'         => self::ROLE_MANIFEST,
				'logical_path' => self::MANIFEST_FILE,
				'source'       => $dir . '/' . self::MANIFEST_FILE,
			),
		);

		foreach ( (array) ( $manifest['storage']['files'] ?? array() ) as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}
			$rel = (string) ( $file['path'] ?? '' );
			if ( $rel === '' ) {
				continue;
			}
			self::assert_relative( $rel );
			$objects[] = array(
				'role'         => self::ROLE_STORAGE,
				'logical_path' => $storage_root . '/' . $rel,
				'source'       => $dir . '/' . $storage_root . '/' . $rel,
			);
		}

		return $objects;
	}

	/**
     * مسیرِ نسبیِ اعلام‌شده در مانیفست باید واقعاً نسبی و بدونِ فرارِ مسیر باشد؛
     * وگرنه مبدأ محلی قابلِ اعتماد نیست (دفاعِ عمیق، مستقل از verifyFiles).
     */
	private static function assert_relative( string $relative ): void {
		$ok = $relative !== ''
			&& ! str_starts_with( $relative, '/' )
			&& ! str_contains( $relative, '..' )
			&& preg_match( '#^[A-Za-z0-9._/-]+$#', $relative ) === 1;
		if ( ! $ok ) {
			throw BackupException::of( self::E_LOCAL_INVALID, 'local backup failed verification' );
		}
	}

	/**
     * کاتالوگ v1 — plaintext خصوصیِ محلی که پیش از آپلود رمزنگاری می‌شود.
     * مسیرهای منطقی **فقط** اینجا مجازند (چون شیء رمزنگاری‌شده است). هیچ
     * credential/کلیدی در آن نیست و هیچ‌گاه در DB ذخیره نمی‌شود.
     *
     * @param list<array<string, mixed>> $entries
     */
	private function write_catalog(
		string $path,
		string $mirror_id,
		string $catalog_object_id,
		string $backup_id,
		string $manifest_sha,
		array $entries
	): void {
		// فایلِ موقتِ خصوصی: دسترسی فقط برای مالکِ پروسه (pre-umask-independent).
		$handle = @fopen( $path, 'xb' );
		if ( $handle !== false ) {
			fclose( $handle );
			@chmod( $path, 0600 );
		}
		$json = (string) json_encode(
			array(
				'format'                  => self::CATALOG_FORMAT,
				'format_version'          => self::CATALOG_FORMAT_VERSION,
				'mirror_id'               => $mirror_id,
				'catalog_object_id'       => $catalog_object_id,
				'local_backup_id'         => $backup_id,
				'local_manifest_sha256'   => $manifest_sha,
				'envelope_format'         => BackupEncryptionEnvelope::MAGIC,
				'envelope_format_version' => self::ENVELOPE_FORMAT_VERSION,
				'entries'                 => $entries,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if ( file_put_contents( $path, $json ) === false ) {
			throw BackupException::of( self::E_ENCRYPTION_FAILED, 'backup mirror staging failed' );
		}
	}

	/**
     * رمزنگاری با **تنها** primitive تثبیت‌شده (Slice 1) به فایلِ موقتِ scratch.
     */
	private function encrypt_to_scratch( string $source, string $destination, string $key ): void {
		try {
			BackupEncryptionEnvelope::encryptFile( $source, $destination, $key );
		} catch ( \Throwable $error ) {
			// هیچ fallbackٔ plaintext و هیچ پیامِ جزئیاتی: فقط کدِ bounded.
			throw BackupException::of( self::E_ENCRYPTION_FAILED, 'backup file encryption failed' );
		}
		if ( ! is_file( $destination ) ) {
			throw BackupException::of( self::E_ENCRYPTION_FAILED, 'backup file encryption failed' );
		}
	}

	/**
     * یک `PutObject` تک‌بخشی روی فایلِ موقتِ ciphertext — بدنه stream است و کل
     * ciphertext هرگز در حافظه بارگذاری نمی‌شود.
     *
     * @throws BackupException کدِ boundedِ upload/checksum/size
     */
	private function upload_object(
		S3Client $client,
		string $bucket,
		string $key,
		string $cipher_path,
		int $bytes,
		string $digest_b64
	): string {
		$handle = @fopen( $cipher_path, 'rb' );
		if ( $handle === false ) {
			throw BackupException::of( self::E_ENCRYPTION_FAILED, 'backup file encryption failed' );
		}

		try {
			try {
				$result = $client->putObject(
					array(
						'Bucket'         => $bucket,
						'Key'            => $key,
						'Body'           => $handle,
						'ContentLength'  => $bytes,
						// digest از پیش محاسبه‌شده ⇒ SDK هشِ خودش را حساب نمی‌کند
						// (Aws\S3\ApplyChecksumMiddleware::hasAlgorithmHeader) و بدنه را
						// بایت‌به‌بایت منتقل می‌کند: بدونِ aws-chunked، بدونِ تغییرِ trailer.
						'ChecksumSHA256' => $digest_b64,
					)
				);
			} catch ( BackupException $error ) {
				throw $error;
			} catch ( \Throwable $error ) {
				// خطای SDK/transport هرگز با پیامِ خام بیرون نمی‌رود (ممکن است
				// endpoint/bucket/مسیر داشته باشد)؛ فقط کدِ bounded.
				throw BackupException::of( self::E_UPLOAD_FAILED, 'S3 mirror upload failed' );
			}

			/** @var array<string, mixed> $array */
			$array = $result->toArray();

			try {
				return $this->strength_from_acknowledgement( $array, $bytes, $digest_b64 );
			} catch ( BackupException $proof ) {
				// PUT با 2xx پذیرفته شده ⇒ شیء ذخیره است، حتی اگر اثبات ناهم‌خوان باشد.
				// این علامتِ داخلی به `with_cleanup_evidence()` می‌گوید که نظافت باید آن را هم ببیند.
				throw BackupException::of( $proof->getErrorCode(), $proof->getMessage(), array( 'stored' => true ) );
			}
		} finally {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
		}
	}

	/**
     * حداقلِ راستی‌آزماییِ مجاز پس از ack — دقیقاً همان چیزی که مدلِ SDK قفل‌شده
     * برای PutObject exposes: `ChecksumSHA256` و `Size` در پاسخِ خودِ PUT.
     * (HEAD برای اثباتِ بدنه استفاده نمی‌شود؛ ETag و اکویِ metadata دلیلِ برابریِ بدنه نیستند.)
     *
     * @param array<string, mixed> $result
     */
	private function strength_from_acknowledgement( array $result, int $bytes, string $digest_b64 ): string {
		$echoed = trim( (string) ( $result['ChecksumSHA256'] ?? '' ) );
		if ( $echoed === '' ) {
			// endpoint اثباتِ checksum ندارد ⇒ ACKNOWLEDGED، نه بیشتر.
			return self::STRENGTH_ACKNOWLEDGED;
		}
		if ( ! hash_equals( $digest_b64, $echoed ) ) {
			throw BackupException::of( self::E_CHECKSUM_MISMATCH, 'S3 mirror checksum verification failed' );
		}

		$remote_size = $result['Size'] ?? null;
		if ( $remote_size === null ) {
			// checksum هم‌خوان ولی طولِ اعلام‌شده در دسترس نیست ⇒ اثباتِ کامل نداریم.
			return self::STRENGTH_ACKNOWLEDGED;
		}
		if ( (int) $remote_size !== $bytes ) {
			throw BackupException::of( self::E_SIZE_MISMATCH, 'S3 mirror size verification failed' );
		}

		return self::STRENGTH_VERIFIED;
	}

	/**
     * گیتِ single-part: بزرگ‌تر از سقف ⇒ Fail-Closed، پیش از هر درخواست، بدونِ
     * multipart و بدونِ هر دادهٔ حساس در خطا.
     */
	private function assert_ciphertext_within_single_put_limit( int $bytes ): void {
		if ( $bytes > self::MAX_ENCRYPTED_OBJECT_BYTES ) {
			throw BackupException::of( self::E_OBJECT_TOO_LARGE, 'encrypted object exceeds the single PutObject limit' );
		}
	}

	/**
     * کرانهٔ تحلیلیِ اندازهٔ ciphertext (پیش‌سنجیِ ارزان، پیش از رمزنگاری):
     * magic + headerٔ secretstream + payload + یک tag ۱۷بایتی به‌ازای هر فریم.
     * گیتِ **الزام‌آور** همان `assert_ciphertext_within_single_put_limit()` روی
     * اندازهٔ واقعیِ ciphertext است؛ این فقط از نوشتنِ بیهودهٔ گیگابایت‌ها جلوگیری می‌کند.
     */
	private function expected_ciphertext_bytes( int $plain_bytes ): int {
		$chunk  = BackupEncryptionEnvelope::CHUNK_BYTES;
		$frames = $chunk > 0 ? (int) ceil( $plain_bytes / $chunk ) : 1;
		if ( $frames < 1 ) {
			$frames = 1;
		}
		$header = defined( 'SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES' )
			? (int) SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES
			: 24;
		$tag = defined( 'SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES' )
			? (int) SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES
			: 17;

		return strlen( BackupEncryptionEnvelope::MAGIC ) + $header + $plain_bytes + $tag * $frames;
	}

	/**
     * @return array{dir: string, created: bool}
     */
	private function scratch_root(): array {
		if ( $this->scratch_dir !== null && $this->scratch_dir !== '' ) {
			$dir = rtrim( $this->scratch_dir, '/' );
			if ( is_dir( $dir ) ) {
				return array( 'dir' => $dir, 'created' => false );
			}
			if ( ! @mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
				throw BackupException::of( self::E_ENCRYPTION_FAILED, 'backup mirror staging failed' );
			}

			return array( 'dir' => $dir, 'created' => true );
		}

		// هرگز داخلِ مسیرِ بکاپ‌ها staging نمی‌شود (قالبِ محلی باید دست‌نخورده بماند).
		$dir = rtrim( (string) sys_get_temp_dir(), '/' ) . '/' . self::SCRATCH_PREFIX . $this->hex_id();
		if ( ! mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
			throw BackupException::of( self::E_ENCRYPTION_FAILED, 'backup mirror staging failed' );
		}

		return array( 'dir' => $dir, 'created' => true );
	}

	/**
     * پاک‌سازیِ وضعیتِ موقتِ خصوصی در همهٔ مسیرهای پایان — ciphertext، کاتالوگِ
     * plaintext و (فقط اگر خودمان ساخته‌ایم) دایرکتوریِ scratch.
     *
     * @param list<string> $staged
     */
	private function purge_scratch( string $dir, array $staged, bool $created ): void {
		$leftovers = 0;
		foreach ( $staged as $path ) {
			if ( is_file( $path ) ) {
				@unlink( $path );
				if ( is_file( $path ) ) {
					++$leftovers;
				}
			}
		}
		if ( $created && is_dir( $dir ) ) {
			@rmdir( $dir );
		}
		if ( $leftovers > 0 ) {
			// نظافتِ محلی کامل نشد — پنهان نمی‌ماند؛ فقط شمارش (بدونِ مسیر/کلید/PHI).
			$this->op_log( self::OP_CLEANUP_INCOMPLETE, array( 'local_leftover_files' => $leftovers ) );
		}
	}

	/**
     * نظافتِ bounded از اشیاءِ همین تلاشِ ناموفق — بدونِ API عمومیِ delete/retention،
     * بدونِ ListObjects؛ فقط کلیدهای opaqueٔ خودِ همین attempt.
     *
     * @param list<string> $uploaded
     *
     * @return array{attempted: int, failed: int, object_ids: list<string>}
     */
	private function delete_attempted_objects( S3Client $client, string $bucket, string $key_root, array $uploaded ): array {
		$attempted  = 0;
		$failed     = 0;
		$failed_ids = array();
		foreach ( $uploaded as $object_id ) {
			++$attempted;
			try {
				$client->deleteObject( array( 'Bucket' => $bucket, 'Key' => $key_root . '/' . (string) $object_id ) );
			} catch ( \Throwable $error ) {
				++$failed;
				$failed_ids[] = (string) $object_id;
			}
		}

		return array( 'attempted' => $attempted, 'failed' => $failed, 'object_ids' => $failed_ids );
	}

	/**
     * شکست بعد از آپلودِ بخشی از مجموعه: اول نظافتِ bounded، بعد همان خطای bounded
     * به‌همراه evidenceٔ نظافت (اگر کامل نشود، پنهان نمی‌ماند).
     *
     * @param list<string> $uploaded
     */
	private function with_cleanup_evidence(
		BackupException $error,
		?S3Client $client,
		string $bucket,
		string $key_root,
		array $uploaded,
		string $backup_id,
		string $mirror_id
	): BackupException {
		// علامتِ داخلی `stored` هرگز به بیرون نمی‌رود؛ `data` یا خالی است یا فقط evidenceٔ نظافت.
		$data = array_diff_key( $error->data, array( 'stored' => true ) );
		if ( $client === null || $uploaded === array() ) {
			return $this->rebuild( $error, $data );
		}

		$cleanup = $this->delete_attempted_objects( $client, $bucket, $key_root, $uploaded );
		$this->op_log(
			self::OP_FAILED,
			array(
				'backup_id'        => $backup_id,
				'mirror_id'        => $mirror_id,
				'error_code'       => $error->getErrorCode(),
				'uploaded_objects' => count( $uploaded ),
				'cleanup_deleted'  => $cleanup['attempted'] - $cleanup['failed'],
			)
		);
		if ( $cleanup['failed'] === 0 ) {
			return $this->rebuild( $error, $data );
		}

		$this->op_log(
			self::OP_CLEANUP_INCOMPLETE,
			array(
				'backup_id'                 => $backup_id,
				'mirror_id'                 => $mirror_id,
				'cleanup_attempted'         => $cleanup['attempted'],
				'cleanup_failed'            => $cleanup['failed'],
				'cleanup_failed_object_ids' => $cleanup['object_ids'],
			)
		);

		return $this->rebuild( $error, array_merge( $data, array( 'cleanup' => $cleanup ) ) );
	}

	/**
	 * همان کد/پیامِ bounded، با `data`ِ اصلاح‌شده — برای اینکه هیچ علامتِ داخلی نشت نکند.
	 *
	 * @param array<string, mixed> $data
	 */
	private function rebuild( BackupException $error, array $data ): BackupException {
		if ( $data === $error->data ) {
			return $error;
		}

		return BackupException::of( $error->getErrorCode(), $error->getMessage(), $data );
	}

	/**
     * @param array<string, mixed> $result
     */
	private function record_success( array $result ): void {
		$pointer = self::pointerFromResult( $result );
		if ( $this->audit !== null ) {
			$this->audit->log( self::OP_UPLOADED, null, 'backup', null, null, null, null, $pointer );
		}
		$this->op_log( self::OP_UPLOADED, $pointer );
	}

	private function client_or_throw( ?S3Client $client ): S3Client {
		if ( $client === null ) {
			throw BackupException::of( self::E_UPLOAD_FAILED, 'S3 mirror upload failed' );
		}

		return $client;
	}

	private function client_for( S3BackupDeploymentConfig $config ): S3Client {
		// سازندهٔ zero-network (Slice 2B)؛ `$this->http_handler` فقط seamٔ تستی است.
		return S3BackupClientFactory::create( $config->transportSettings(), $this->http_handler );
	}

	/**
     * لاگِ عملیاتیِ bounded — بدونِ PHI، بدونِ راز، بدونِ مقدارِ پیکربندی.
     *
     * @param array<string, mixed> $context
     */
	private function op_log( string $action, array $context ): void {
		if ( $this->op === null ) {
			return;
		}
		if ( $action === self::OP_UPLOADED ) {
			$this->op->info( $action, $context );

			return;
		}
		$this->op->warning( $action, $context );
	}

	/** اندازهٔ فایل با بررسیِ خطا — نبودِ فایل یعنی مبدأ محلی قابلِ اعتماد نیست. */
	private static function bytes_of( string $path ): int {
		clearstatcache( true, $path );
		$bytes = is_file( $path ) ? filesize( $path ) : false;
		if ( ! is_int( $bytes ) ) {
			throw BackupException::of( self::E_LOCAL_INVALID, 'local backup file is not readable' );
		}

		return $bytes;
	}

	/** شناسهٔ opaque: ۱۶ بایت تصادفیِ رمزنگاری‌شده ⇒ ۳۲ کاراکتر hex، بدونِ ساختار. */
	private function hex_id(): string {
		return bin2hex( random_bytes( self::ID_BYTES ) );
	}
}
