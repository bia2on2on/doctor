<?php
/**
 * Phase 15 — Slice 2B: نمایِ ترابردیِ S3 — عمداً **بدونِ کلید**.
 *
 * تنها داده‌ای که `S3BackupClientFactory` برای ساختِ `Aws\S3\S3Client`
 * لازم دارد: endpoint، region، bucket، prefix (دادهٔ عملیاتیِ مقصدِ بکاپ —
 * به پیکربندیِ سازندهٔ کلاینت داده **نمی‌شود**)، path style و دو اعتبارنامهٔ
 * S3. کلیدِ رمزنگاریِ بکاپ **در این نما وجود ندارد** (هیچ عضو/متدی با نام یا
 * نقشِ «encryption») — جداییِ رمزنگاری از ترابرد از طریقِ همین نوع تضمین
 * می‌شود، نه با قراردادِ نوشتاری.
 *
 * ## مخفی‌کاری
 *
 * مثل `S3BackupDeploymentConfig`: همهٔ مقادیر داخلِ Closure بسته‌شده نگه
 * داشته می‌شوند؛ `__debugInfo()` خلاصهٔ بدونِ مقدار می‌دهد؛ `__serialize()`
 * آرایهٔ خالی برمی‌گرداند؛ `__toString`/`JsonSerializable` تعریف نمی‌شود ⇒
 * `var_dump`/`print_r`/`var_export`/`serialize`/`json_encode` هیچ
 * credentialای را افشا نمی‌کنند. فراخوان‌ها فقط از طریقِ accessorهای عمومیِ
 * این کلاس به مقادیر ترابردی می‌رسند.
 *
 * @see \ClinicCore\Infrastructure\Backup\S3BackupDeploymentConfig
 * @see \ClinicCore\Infrastructure\Backup\S3BackupClientFactory
 */

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Backup;

final class S3BackupTransportSettings {

	/** @var \Closure(): string */
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

	/**
	 * فقط `S3BackupDeploymentConfig::transportSettings()` این کلاس را می‌سازد.
	 */
	public function __construct(
		string $endpoint,
		string $region,
		string $bucket,
		string $key_prefix,
		bool $use_path_style,
		string $access_key_id,
		string $secret_access_key
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
	}

	/** endpoint ترابرد (HTTPS). */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- نامِ عمومیِ تثبیت‌شدهٔ قراردادِ Phase 15 Slice 2B (suiteِ RED پذیرفته‌شده).
	public function endpoint(): string {
		return ( $this->endpoint_getter )();
	}

	/** region ترابرد. */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- نامِ عمومیِ تثبیت‌شدهٔ قراردادِ Phase 15 Slice 2B (suiteِ RED پذیرفته‌شده).
	public function region(): string {
		return ( $this->region_getter )();
	}

	/** bucket مقصدِ بکاپ — دادهٔ عملیاتی؛ جزو پیکربندیِ سازندهٔ کلاینت نیست. */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- نامِ عمومیِ تثبیت‌شدهٔ قراردادِ Phase 15 Slice 2B (suiteِ RED پذیرفته‌شده).
	public function bucket(): string {
		return ( $this->bucket_getter )();
	}

	/** prefixِ نرمال‌شده — دادهٔ عملیاتی؛ جزو پیکربندیِ سازندهٔ کلاینت نیست. */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- نامِ عمومیِ تثبیت‌شدهٔ قراردادِ Phase 15 Slice 2B (suiteِ RED پذیرفته‌شده).
	public function keyPrefix(): string {
		return ( $this->prefix_getter )();
	}

	/** رفتارِ path-style. */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- نامِ عمومیِ تثبیت‌شدهٔ قراردادِ Phase 15 Slice 2B (suiteِ RED پذیرفته‌شده).
	public function usePathStyle(): bool {
		return ( $this->path_style_getter )();
	}

	/** access key id — حساس؛ فقط برای ساختِ client ترابرد. */
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
	 * خلاصهٔ debug بدونِ هیچ مقدار (var_dump/print_r).
	 *
	 * @return array<string, bool>
	 */
	public function __debugInfo(): array {
		return array(
			'transport_configured' => true,
		);
	}

	/**
	 * serialize هرگز state را حمل نمی‌کند.
	 *
	 * @return array<string, never>
	 */
	public function __serialize(): array {
		return array();
	}
}
