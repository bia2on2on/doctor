<?php
/**
 * Phase 15 — Slice 2B: پیکربندیِ استقرارِ S3 بر پایهٔ ثابت‌های deployment — فقط‌خواندنی و از پیشِ اعتبارسنجی‌شده.
 *
 * تنها منبعِ تولیدیِ این پیکربندی، ثابت‌های deployment وردپرس/سرور است
 * (wp-config.php یا include سرور): `CPMS_S3_ENDPOINT`، `CPMS_S3_REGION`،
 * `CPMS_S3_BUCKET`، `CPMS_S3_PREFIX` (فقط فیلدِ اختیاری)، `CPMS_S3_PATH_STYLE`،
 * `CPMS_S3_ACCESS_KEY_ID`، `CPMS_S3_SECRET_ACCESS_KEY` و
 * `CPMS_BACKUP_ENCRYPTION_KEY_B64`. هیچ getenv، هیچ Settings/DB، هیچ UI و هیچ
 * پرچمِ `CPMS_S3_ENABLED` در این قرارداد وجود ندارد: نبودِ **همهٔ** ثابت‌ها =
 * «غیرفعال/پیکربندی‌نشده» (بازگشتِ null)؛ حضورِ بخشی از فیلدهای الزامی =
 * شکستِ صریح با `CLINIC_BACKUP_S3_PARTIAL_CONFIG`؛ مقدارِ ناسازگار = خطای
 * محدودِ همancode همان فیلد (`CLINIC_BACKUP_S3_*_INVALID` /
 * `CLINIC_BACKUP_ENCRYPTION_KEY_INVALID`).
 *
 * ## جداییِ رمزنگاری از ترابرد (عمدی)
 *
 * کلیدِ رمزنگاریِ بکاپ به ترابردِ S3 کاری ندارد. این کلاس کلیدِ رمزگشایی‌شده
 * را فقط **در حافظه** نگه می‌دارد و تنها از طریقِ متدِ عمداً باریکِ
 * `encryptionKeyForBackupEnvelope()` (برای `BackupEncryptionEnvelope` آینده)
 * در دسترس است. نمایِ ترابردی که به `S3BackupClientFactory` داده می‌شود
 * (`S3BackupTransportSettings`) **هرگز** کلید را حمل نمی‌کند و کارخانهٔ
 * S3Client کلید را به پیکربندی AWS SDK نمی‌دهد.
 *
 * ## پیمانِ محصولیِ کلیدِ آبجکت (بدونِ ادعای تشخیص)
 *
 * `OBJECT_KEY_PRODUCT_CONTRACT` را ببینید: خودِ CPMS هرگز شناسهٔ بیمار/Clinic/
 * بالینی به کلیدِ آبجکتِ ریموت نمی‌افزاید؛ نرم‌افزار نمی‌تواند و **ادعا نمی‌کند**
 * که PHI داخلِ prefixِ دلخواهِ پیکربندی‌شده را به‌صورت معنایی تشخیص می‌دهد.
 *
 * ## مخفی‌کاری (fail-closed)
 *
 * مقادیرِ حساس (access key id، secret، کلیدِ رمزنگاری) هرگز در پراپرتیِ
 * متعارفِ قابلِ dump نگه داشته نمی‌شوند؛ هر مقدار در یک Closure بسته‌شده
 * (PHP 8.1-compatible) نگهداری می‌شود، `__debugInfo()` فقط خلاصهٔ بدونِ مقدار
 * می‌دهد، `__serialize()` آرایهٔ خالی برمی‌گرداند، `__unserialize()` بازیابی را
 * فوراً رد می‌کند (هیچ کپیِ serializeشده به شیءِ قابلِ استفاده برنمی‌گردد) و
 * `__toString` / `JsonSerializable` تعریف **نمی‌شود** — بنابراین
 * `var_dump`/`print_r`/`var_export`/`serialize`/`json_encode` مسیرِ افشای
 * credential/کلید ندارند. پیامِ همهٔ خطاها ثابت و بدونِ مقدار است و `data`
 * همیشه خالی می‌ماند.
 *
 * ترتیبِ اعتبارسنجی ثابت است: endpoint، region، bucket، prefix، path style،
 * access key id، secret access key، کلیدِ رمزنگاری؛ اولین خطا برنده است.
 * بررسیِ «حضور» **قبل از** اعتبارسنجیِ مقدار انجام می‌شود (partial همیشه بر
 * invalid مقدار مقدم است).
 *
 * @see \ClinicCore\Infrastructure\Backup\BackupException
 * @see \ClinicCore\Infrastructure\Backup\S3BackupTransportSettings
 * @see \ClinicCore\Infrastructure\Backup\S3BackupClientFactory
 */

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Backup;

final class S3BackupDeploymentConfig {

	/** نام ثابتِ deployment برای endpoint ترابرد (فقط HTTPS). */
	public const NAME_ENDPOINT = 'CPMS_S3_ENDPOINT';

	/** نام ثابتِ deployment برای region (بدون allowlist مخصوص AWS). */
	public const NAME_REGION = 'CPMS_S3_REGION';

	/** نام ثابتِ deployment برای bucket (بدون قانونِ DNS خاصِ provider). */
	public const NAME_BUCKET = 'CPMS_S3_BUCKET';

	/** نام ثابتِ deployment برای prefix — تنها فیلدِ اختیاریِ قرارداد. */
	public const NAME_PREFIX = 'CPMS_S3_PREFIX';

	/** نام ثابتِ deployment برای path style — قراردادِ boolean سخت‌گیرانه. */
	public const NAME_PATH_STYLE = 'CPMS_S3_PATH_STYLE';

	/** نام ثابتِ deployment برای access key id — حساس. */
	public const NAME_ACCESS_KEY_ID = 'CPMS_S3_ACCESS_KEY_ID';

	/** نام ثابتِ deployment برای secret access key — حساس؛ هرگز افشا نمی‌شود. */
	public const NAME_SECRET_ACCESS_KEY = 'CPMS_S3_SECRET_ACCESS_KEY';

	/** نام ثابتِ deployment برای کلیدِ رمزنگاریِ بکاپ (Base64 سخت‌گیرانه) — حساس. */
	public const NAME_ENCRYPTION_KEY_B64 = 'CPMS_BACKUP_ENCRYPTION_KEY_B64';

	/**
	 * پیمانِ محصولیِ کلیدِ آبجکت ریموت — مستندسازیِ محصول، نه ادعای فنی:
	 * خودِ CPMS هرگز شناسهٔ بیمار/Clinic/بالینی به کلیدِ آبجکتِ ریموت نمی‌افزاید؛
	 * کلیدِ آبجکت فقط از prefixِ deployment و نامِ فایل‌های بکاپِ متعلق به CPMS
	 * ساخته می‌شود. نرم‌افزار نمی‌تواند PHI داخلِ prefixِ دلخواه را به‌صورتِ
	 * معنایی «تشخیص» دهد و چنین ادعایی نمی‌شود.
	 */
	public const OBJECT_KEY_PRODUCT_CONTRACT = 'CPMS itself must never append patient, clinic or clinical identifiers to '
		. 'remote object keys; remote object keys are built only from the configured deployment prefix and CPMS-owned '
		. 'backup file names. The software cannot semantically detect PHI inside an arbitrary customer-configured '
		. 'prefix and no such detection is claimed or in scope.';

	/** حداکثر طولِ endpoint (بایت). */
	private const MAX_ENDPOINT_BYTES = 2048;

	/** حداکثر طولِ region (بایت). */
	private const MAX_REGION_BYTES = 128;

	/** حداکثر طولِ bucket (بایت). */
	private const MAX_BUCKET_BYTES = 255;

	/** حداکثر طولِ prefix (بایت). */
	private const MAX_PREFIX_BYTES = 512;

	/** حداکثر طولِ credential (بایت). */
	private const MAX_CREDENTIAL_BYTES = 256;

	private const ERROR_PARTIAL = 'CLINIC_BACKUP_S3_PARTIAL_CONFIG';

	private const ERROR_ENDPOINT = 'CLINIC_BACKUP_S3_ENDPOINT_INVALID';

	private const ERROR_REGION = 'CLINIC_BACKUP_S3_REGION_INVALID';

	private const ERROR_BUCKET = 'CLINIC_BACKUP_S3_BUCKET_INVALID';

	private const ERROR_PREFIX = 'CLINIC_BACKUP_S3_PREFIX_INVALID';

	private const ERROR_PATH_STYLE = 'CLINIC_BACKUP_S3_PATH_STYLE_INVALID';

	private const ERROR_CREDENTIALS = 'CLINIC_BACKUP_S3_CREDENTIALS_INVALID';

	private const ERROR_KEY = 'CLINIC_BACKUP_ENCRYPTION_KEY_INVALID';

	private const ERROR_RESTORE = 'CLINIC_BACKUP_S3_CONFIG_RESTORE_REJECTED';

	/** پیام‌های ثابت — بدونِ هیچ مقدارِ پیکربندی/credential/کلید/مسیر. */
	private const MESSAGE_PARTIAL = 'S3 backup deployment configuration is incomplete: some deployment constants are present but at least one required deployment constant is missing';

	private const MESSAGE_ENDPOINT = 'CPMS_S3_ENDPOINT is invalid: a bounded absolute HTTPS URL with a host and without user info, query, fragment, control characters or whitespace is required';

	private const MESSAGE_REGION = 'CPMS_S3_REGION is invalid: a bounded non-empty value without control characters, whitespace or slashes is required';

	private const MESSAGE_BUCKET = 'CPMS_S3_BUCKET is invalid: a bounded non-empty value without control characters, whitespace or slashes is required';

	private const MESSAGE_PREFIX = 'CPMS_S3_PREFIX is invalid: an optional bounded value without control characters, backslashes or dot-traversal segments is required';

	private const MESSAGE_PATH_STYLE = 'CPMS_S3_PATH_STYLE is invalid: exactly the boolean true or the boolean false is required';

	private const MESSAGE_CREDENTIALS = 'CPMS_S3_ACCESS_KEY_ID and CPMS_S3_SECRET_ACCESS_KEY are invalid: bounded non-empty values without control characters or whitespace are required';

	private const MESSAGE_KEY = 'CPMS_BACKUP_ENCRYPTION_KEY_B64 is invalid: strict Base64 decoding to exactly the Sodium secretstream KEYBYTES length is required';

	private const MESSAGE_RESTORE = 'S3 backup deployment configuration cannot be restored from serialized data';

	/**
	 * نگهدارنده‌های مقدار به‌صورتِ Closure (مخفی در برابرِ dump/export؛
	 * PHP 8.1-compatible — کوچک‌ترین مکانیسمِ کاملِ پیشگیریِ افشا).
	 *
	 * @var \Closure(): string
	 */
	private \Closure $endpoint_getter;

	/** @var \Closure(): string */
	private \Closure $region_getter;

	/** @var \Closure(): string */
	private \Closure $bucket_getter;

	/** @var \Closure(): string */
	private \Closure $prefix_getter;

	/** @var \Closure(): bool */
	private \Closure $path_style_getter;

	/** @var \Closure(): string */
	private \Closure $access_key_id_getter;

	/** @var \Closure(): string */
	private \Closure $secret_access_key_getter;

	/** @var \Closure(): string */
	private \Closure $encryption_key_getter;

	/**
	 * سازنده فقط از سازنده‌های استاتیکِ همین کلاس فراخوانی می‌شود؛ مقادیرِ
	 * فریمِ سازنده در trace هیچ خطایی نمی‌مانند (اعتبارسنجی پیش از ساخت است).
	 */
	private function __construct(
		string $endpoint,
		string $region,
		string $bucket,
		string $key_prefix,
		bool $use_path_style,
		string $access_key_id,
		string $secret_access_key,
		string $encryption_key
	) {
		$this->endpoint_getter          = static function () use ( $endpoint ): string {
			return $endpoint;
		};
		$this->region_getter            = static function () use ( $region ): string {
			return $region;
		};
		$this->bucket_getter            = static function () use ( $bucket ): string {
			return $bucket;
		};
		$this->prefix_getter            = static function () use ( $key_prefix ): string {
			return $key_prefix;
		};
		$this->path_style_getter        = static function () use ( $use_path_style ): bool {
			return $use_path_style;
		};
		$this->access_key_id_getter     = static function () use ( $access_key_id ): string {
			return $access_key_id;
		};
		$this->secret_access_key_getter = static function () use ( $secret_access_key ): string {
			return $secret_access_key;
		};
		$this->encryption_key_getter    = static function () use ( $encryption_key ): string {
			return $encryption_key;
		};
	}

	/**
	 * خواندنِ پیکربندی از یک reader تزریقی (`callable(string $name): mixed`؛
	 * null = غایب). کوچک‌ترین درزِ تستِ هم‌راستا با مخزن؛ هیچ تغییرِ محیطِ
	 * global و هیچ ثابتِ فرایندیِ برگشت‌ناپذیری برای تست تعریف نمی‌شود.
	 *
	 * همهٔ مقادیر فقط به‌صورتِ متغیرِ محلیِ همین فریم نگه داشته می‌شوند؛
	 * helperهای اعتبارسنجی مقدار را به‌عنوان آرگومان می‌گیرند اما **قبل از**
	 * ساختِ هر exception بازمی‌گردند، پس trace خطا هرگز حاملِ مقدار نیست.
	 *
	 * @param callable(string): mixed $reader
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- نامِ عمومیِ تثبیت‌شدهٔ قراردادِ Phase 15 Slice 2B (suiteِ RED پذیرفته‌شده).
	public static function fromReader( callable $reader ): ?self {
		$raw_endpoint = $reader( self::NAME_ENDPOINT );
		$raw_region   = $reader( self::NAME_REGION );
		$raw_bucket   = $reader( self::NAME_BUCKET );
		$raw_prefix   = $reader( self::NAME_PREFIX );
		$raw_path     = $reader( self::NAME_PATH_STYLE );
		$raw_access   = $reader( self::NAME_ACCESS_KEY_ID );
		$raw_secret   = $reader( self::NAME_SECRET_ACCESS_KEY );
		$raw_key_b64  = $reader( self::NAME_ENCRYPTION_KEY_B64 );

		$present_any = null !== $raw_endpoint || null !== $raw_region || null !== $raw_bucket
			|| null !== $raw_prefix || null !== $raw_path || null !== $raw_access
			|| null !== $raw_secret || null !== $raw_key_b64;

		// هیچ ثابتی حاضر نیست ⇒ «غیرفعال/پیکربندی‌نشده» — بدونِ هیچ پرچمِ ENABLED.
		if ( ! $present_any ) {
			return null;
		}

		// حضور، قبل از اعتبارسنجیِ مقدار: partial همیشه بر invalid مقدم است.
		if ( null === $raw_endpoint || null === $raw_region || null === $raw_bucket
			|| null === $raw_path || null === $raw_access || null === $raw_secret
			|| null === $raw_key_b64 ) {
			throw BackupException::of( self::ERROR_PARTIAL, self::MESSAGE_PARTIAL );
		}

		// ۱) endpoint — فقط HTTPS مطلقِ محدود؛ host الزامی؛ بدونِ userinfo/query/fragment؛
		//    بدونِ کاراکترِ کنترلی/فاصله (شفافیت TLS هرگز غیرفعال نمی‌شود).
		if ( ! is_string( $raw_endpoint )
			|| '' === $raw_endpoint
			|| strlen( $raw_endpoint ) > self::MAX_ENDPOINT_BYTES
			|| self::has_forbidden_byte( $raw_endpoint )
			|| ! self::is_https_endpoint( $raw_endpoint ) ) {
			throw BackupException::of( self::ERROR_ENDPOINT, self::MESSAGE_ENDPOINT );
		}

		// ۲) region — محدود، بدونِ کنترل/فاصله/اسلش؛ بدونِ allowlist مخصوص AWS.
		if ( ! is_string( $raw_region )
			|| '' === $raw_region
			|| strlen( $raw_region ) > self::MAX_REGION_BYTES
			|| self::has_forbidden_byte( $raw_region )
			|| str_contains( $raw_region, '/' )
			|| str_contains( $raw_region, '\\' ) ) {
			throw BackupException::of( self::ERROR_REGION, self::MESSAGE_REGION );
		}

		// ۳) bucket — محدود، بدونِ کنترل/فاصله/اسلش؛ بدونِ قانونِ DNS خاصِ provider.
		if ( ! is_string( $raw_bucket )
			|| '' === $raw_bucket
			|| strlen( $raw_bucket ) > self::MAX_BUCKET_BYTES
			|| self::has_forbidden_byte( $raw_bucket )
			|| str_contains( $raw_bucket, '/' )
			|| str_contains( $raw_bucket, '\\' ) ) {
			throw BackupException::of( self::ERROR_BUCKET, self::MESSAGE_BUCKET );
		}

		// ۴) prefix — اختیاری؛ محدود؛ بدونِ کنترل/بک‌اسلش/سگمنتِ «.» و «..»؛
		//    اسلش‌های ابتدا/انتها/تکراری به‌طورِ یکدست نرمال می‌شوند.
		$prefix = '';
		if ( null !== $raw_prefix ) {
			if ( ! is_string( $raw_prefix )
				|| strlen( $raw_prefix ) > self::MAX_PREFIX_BYTES
				|| self::has_forbidden_byte( $raw_prefix )
				|| str_contains( $raw_prefix, '\\' ) ) {
				throw BackupException::of( self::ERROR_PREFIX, self::MESSAGE_PREFIX );
			}
			$normalized = self::normalize_prefix( $raw_prefix );
			if ( null === $normalized ) {
				throw BackupException::of( self::ERROR_PREFIX, self::MESSAGE_PREFIX );
			}
			$prefix = $normalized;
		}

		// ۵) path style — دقیقاً boolean؛ هیچ coercion رشته‌ای/عددی پذیرفته نیست.
		if ( ! is_bool( $raw_path ) ) {
			throw BackupException::of( self::ERROR_PATH_STYLE, self::MESSAGE_PATH_STYLE );
		}

		// ۶) access key id — حساس؛ محدود، بدونِ کنترل/فاصله.
		if ( ! is_string( $raw_access )
			|| '' === $raw_access
			|| strlen( $raw_access ) > self::MAX_CREDENTIAL_BYTES
			|| self::has_forbidden_byte( $raw_access ) ) {
			throw BackupException::of( self::ERROR_CREDENTIALS, self::MESSAGE_CREDENTIALS );
		}

		// ۷) secret access key — حساس؛ هرگز لاگ/serialize/نمایش نمی‌شود.
		if ( ! is_string( $raw_secret )
			|| '' === $raw_secret
			|| strlen( $raw_secret ) > self::MAX_CREDENTIAL_BYTES
			|| self::has_forbidden_byte( $raw_secret ) ) {
			throw BackupException::of( self::ERROR_CREDENTIALS, self::MESSAGE_CREDENTIALS );
		}

		// ۸) کلیدِ رمزنگاری — Base64 سخت‌گیرانه (بدونِ کنترل/فاصله)؛ طولِ
		//    رمزگشایی‌شده = KEYBYTES؛ در دسترس‌نبودنِ Sodium = fail-closed.
		if ( ! is_string( $raw_key_b64 )
			|| self::has_forbidden_byte( $raw_key_b64 ) ) {
			throw BackupException::of( self::ERROR_KEY, self::MESSAGE_KEY );
		}
		$encryption_key = self::decode_encryption_key( $raw_key_b64 );
		if ( null === $encryption_key ) {
			throw BackupException::of( self::ERROR_KEY, self::MESSAGE_KEY );
		}

		return new self( $raw_endpoint, $raw_region, $raw_bucket, $prefix, $raw_path, $raw_access, $raw_secret, $encryption_key );
	}

	/**
	 * مسیرِ واقعیِ تولید: خواندنِ همین ثابت‌های deployment از فرایندِ جاری
	 * (defined()/constant()) — بدونِ getenv. همهٔ ثابت‌ها غایب ⇒ null.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- نامِ عمومیِ تثبیت‌شدهٔ قراردادِ Phase 15 Slice 2B (suiteِ RED پذیرفته‌شده).
	public static function fromDeploymentConstants(): ?self {
		$reader = static function ( string $name ) {
			return \defined( $name ) ? \constant( $name ) : null;
		};

		return self::fromReader( $reader );
	}

	/** endpoint ترابرد (HTTPS، از پیشِ اعتبارسنجی‌شده). */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- نامِ عمومیِ تثبیت‌شدهٔ قراردادِ Phase 15 Slice 2B (suiteِ RED پذیرفته‌شده).
	public function endpoint(): string {
		return ( $this->endpoint_getter )();
	}

	/** region ترابرد. */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- نامِ عمومیِ تثبیت‌شدهٔ قراردادِ Phase 15 Slice 2B (suiteِ RED پذیرفته‌شده).
	public function region(): string {
		return ( $this->region_getter )();
	}

	/** bucket مقصدِ بکاپ (دادهٔ عملیاتی؛ هرگز به پیکربندیِ SDK کلاینت داده نمی‌شود). */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- نامِ عمومیِ تثبیت‌شدهٔ قراردادِ Phase 15 Slice 2B (suiteِ RED پذیرفته‌شده).
	public function bucket(): string {
		return ( $this->bucket_getter )();
	}

	/** prefixِ نرمال‌شده؛ خالی = بدونِ prefix. */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- نامِ عمومیِ تثبیت‌شدهٔ قراردادِ Phase 15 Slice 2B (suiteِ RED پذیرفته‌شده).
	public function keyPrefix(): string {
		return ( $this->prefix_getter )();
	}

	/** رفتارِ path-style (دقیقاً boolean). */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- نامِ عمومیِ تثبیت‌شدهٔ قراردادِ Phase 15 Slice 2B (suiteِ RED پذیرفته‌شده).
	public function usePathStyle(): bool {
		return ( $this->path_style_getter )();
	}

	/** access key id — حساس؛ فراخوان فقط برای ساختِ client ترابرد. */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- نامِ عمومیِ تثبیت‌شدهٔ قراردادِ Phase 15 Slice 2B (suiteِ RED پذیرفته‌شده).
	public function accessKeyId(): string {
		return ( $this->access_key_id_getter )();
	}

	/** secret access key — حساس؛ هرگز لاگ/serialize/نمایش نمی‌شود. */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- نامِ عمومیِ تثبیت‌شدهٔ قراردادِ Phase 15 Slice 2B (suiteِ RED پذیرفته‌شده).
	public function secretAccessKey(): string {
		return ( $this->secret_access_key_getter )();
	}

	/**
	 * کلیدِ خامِ رمزنگاری (Sodium secretstream KEYBYTES بایت) — تنها مسیرِ
	 * عمداً باریکِ درون‌حافظه‌ای برای لایهٔ رمزنگاریِ بکاپ
	 * (`BackupEncryptionEnvelope` در sliceهای آینده). این مقدار هرگز persist/
	 * لاگ/serialize نمی‌شود و هرگز به پیکربندیِ AWS SDK نمی‌رود.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- نامِ عمومیِ تثبیت‌شدهٔ قراردادِ Phase 15 Slice 2B (suiteِ RED پذیرفته‌شده).
	public function encryptionKeyForBackupEnvelope(): string {
		return ( $this->encryption_key_getter )();
	}

	/**
	 * نمایِ ترابردیِ **بدونِ کلید** برای `S3BackupClientFactory` — کلیدِ
	 * رمزنگاری در این نما وجود ندارد (جدا سازیِ عمدیِ رمزنگاری از ترابرد).
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- نامِ عمومیِ تثبیت‌شدهٔ قراردادِ Phase 15 Slice 2B (suiteِ RED پذیرفته‌شده).
	public function transportSettings(): S3BackupTransportSettings {
		return new S3BackupTransportSettings(
			$this->endpoint(),
			$this->region(),
			$this->bucket(),
			$this->keyPrefix(),
			$this->usePathStyle(),
			$this->accessKeyId(),
			$this->secretAccessKey()
		);
	}

	/**
	 * خلاصهٔ debug بدونِ هیچ مقدار (var_dump/print_r) — هیچ credential/کلیدی
	 * و هیچ مقدارِ پیکربندی‌ای در این سطح دیده نمی‌شود.
	 *
	 * @return array<string, bool>
	 */
	public function __debugInfo(): array {
		return array(
			'configured' => true,
		);
	}

	/**
	 * serialize هرگز state (و بنابراین Closureها) را حمل نمی‌کند — «بدونِ
	 * persistence» از جمله در برابرِ serialize پیش‌فرض PHP.
	 *
	 * @return array<string, never>
	 */
	public function __serialize(): array {
		return array();
	}

	/**
	 * بازیابی از دادهٔ serializeشده **فوراً رد می‌شود**: هیچ کپیِ serializeشده
	 * هرگز به یک شیءِ config قابلِ استفاده بازتولید نمی‌شود. شکست محدود و
	 * بی‌خطر است: کد/پیام ثابت‌اند و `data` خالی می‌ماند — هیچ مقدارِ
	 * endpoint/bucket/prefix/credential/کلید در آن‌ها حضور ندارد. آرگومانِ
	 * `$data` بازرسی نمی‌شود (ردِ فورِ بازیابی، مستقل از محتوای payload).
	 */
	public function __unserialize( array $data ): void {
		throw BackupException::of( self::ERROR_RESTORE, self::MESSAGE_RESTORE );
	}

	/**
	 * بایتِ ممنوع: کنترل‌های ASCII و فاصله/تب/LF/CR/VT/FF و DEL.
	 */
	private static function has_forbidden_byte( string $value ): bool {
		return 1 === preg_match( '/[\x00-\x20\x7f]/', $value );
	}

	/**
	 * فقط HTTPS مطلق با host؛ بدونِ userinfo/query/fragment (مسیر مجاز است).
	 */
	private static function is_https_endpoint( string $value ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- کلاس باید بدونِ WordPress قابلِ Unit-test باشد؛ PHP 8 parse_url در تست‌ها پین شده است.
		$parts = parse_url( $value );
		if ( ! is_array( $parts ) ) {
			return false;
		}
		if ( strtolower( (string) ( $parts['scheme'] ?? '' ) ) !== 'https' ) {
			return false;
		}
		$host = $parts['host'] ?? '';
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		if ( isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * نرمالِ prefix: حذفِ اسلش‌های ابتدا/انتها و فشردنِ تکراری‌ها؛ سپس
	 * سگمنت‌های دقیقِ «.» / «..» = traversal ⇒ نامعتبر (null).
	 */
	private static function normalize_prefix( string $value ): ?string {
		$collapsed = preg_replace( '{/+/}', '/', $value );
		if ( ! is_string( $collapsed ) ) {
			return null;
		}
		$normalized = trim( $collapsed, '/' );
		if ( '' === $normalized ) {
			return '';
		}
		foreach ( explode( '/', $normalized ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				return null;
			}
		}

		return $normalized;
	}

	/**
	 * رمزگشاییِ Base64 سخت‌گیرانه + بررسیِ طولِ دقیقِ Sodium secretstream
	 * KEYBYTES؛ Sodium در دسترس نباشد ⇒ null (fail-closed).
	 */
	private static function decode_encryption_key( string $key_b64 ): ?string {
		if ( ! \defined( 'SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES' ) ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- رمزگشاییِ سخت‌گیرانهٔ کلیدِ ۳۲بایتیِ deployment است؛ هیچ استفادهٔ obfuscation وجود ندارد.
		$decoded = base64_decode( $key_b64, true );
		if ( ! is_string( $decoded ) ) {
			return null;
		}
		if ( strlen( $decoded ) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES ) {
			return null;
		}

		return $decoded;
	}
}
