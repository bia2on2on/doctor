<?php
/**
 * Phase 15 — Slice 2B: کارخانهٔ بدونِ شبکه برای `Aws\S3\S3Client`.
 *
 * از نمایِ ترابردیِ **بدونِ کلید** (`S3BackupTransportSettings`) یک کلاینتِ
 * رسمیِ SDK می‌سازد:
 *
 *   - endpoint HTTPS پیکربندی‌شده؛
 *   - region پیکربندی‌شده؛
 *   - `use_path_style_endpoint` صریح؛
 *   - امضای `signature_version = v4`؛
 *   - اعتبارنامهٔ صریح (key/secret) — زنجیرهٔ discovery محیطی را دور می‌زند؛
 *   - `http.verify = true` — راستی‌آزماییِ TLS هرگز غیرفعال نمی‌شود؛
 *   - bucket/prefix دادهٔ عملیاتیِ مقصدند و به پیکربندیِ سازندهٔ کلاینت
 *     داده نمی‌شوند (SDK به آن‌ها نیازی ندارد)؛
 *   - کلیدِ رمزنگاریِ بکاپ **هرگز** واردِ پیکربندیِ SDK نمی‌شود.
 *
 * ساختِ کلاینت **هیچ** command/request/فراخوانیِ شبکه‌ای انجام نمی‌دهد؛
 * برای تست، درزِ `http_handler` (همان تکنیکِ تثبیت‌شدهٔ Slice 2A در
 * `tests/bin/release-s3-client-smoke.php`) اجازهٔ شمردنِ صفرِ فراخوانیِ HTTP
 * می‌دهد. در تولید `$http_handler` باید null بماند (پیش‌فرضِ رسمیِ SDK).
 *
 * هیچ interface/provider abstraction/DI container وجود ندارد: دقیقاً یک
 * تابعِ استاتیکِ یک‌منظوره. هیچ عملیاتِ bucket در این slice نیست.
 *
 * @see \ClinicCore\Infrastructure\Backup\S3BackupTransportSettings
 * @see \ClinicCore\Infrastructure\Backup\S3BackupDeploymentConfig
 */

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Backup;

final class S3BackupClientFactory {

	/**
	 * ساختِ کلاینتِ رسمیِ S3 بدونِ هیچ فعالیتِ شبکه.
	 *
	 * @param S3BackupTransportSettings $settings     نمایِ ترابردیِ بدونِ کلید.
	 * @param callable|null             $http_handler فقط برای تست: handler جریانِ
	 *                                                  HTTP که به‌جای میانیِ رسمیِ
	 *                                                  SDK نصب می‌شود؛ در تولید
	 *                                                  همیشه null.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- نامِ عمومیِ تثبیت‌شدهٔ قراردادِ Phase 15 Slice 2B (suiteِ RED پذیرفته‌شده).
	public static function create( S3BackupTransportSettings $settings, ?callable $http_handler = null ): \Aws\S3\S3Client {
		$options = array(
			'version'                 => '2006-03-01',
			'endpoint'                => $settings->endpoint(),
			'region'                  => $settings->region(),
			'use_path_style_endpoint' => $settings->usePathStyle(),
			'signature_version'       => 'v4',
			'credentials'             => array(
				'key'    => $settings->accessKeyId(),
				'secret' => $settings->secretAccessKey(),
			),
			'http'                    => array(
				'verify' => true,
			),
		);

		if ( null !== $http_handler ) {
			$options['http_handler'] = $http_handler;
		}

		return new \Aws\S3\S3Client( $options );
	}
}
