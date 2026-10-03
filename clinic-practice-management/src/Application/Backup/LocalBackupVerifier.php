<?php
/**
 * Part 15 — Slice 2C: راست‌سنجیِ «تثبیت‌شدهٔ محلیِ» یک بکاپ، در یک نقطهٔ واحد.
 *
 * منطقِ این کلاس **عیناً** همان چیزی است که `BackupService::verifyIn()` از F10 داشت
 * (خواندنِ مانیفست + وضعیتِ hash-sidecar + هویتِ `backup_id` + `BackupManifest::verifyFiles`
 * روی همهٔ فایل‌های فهرست‌شده). تفاوتِ معماری فقط این است که این منطق حالا *یک*
 * منبعِ حقیقت دارد و `BackupService` نیز به همان delegate می‌کند؛ پس برای
 * آینه‌سازی S3 «راستی‌آزمایی دوم» اختراع نمی‌شود و قالبِ بکاپِ محلی (فایل‌ها،
 * مانیفست، `manifest.json.sha256`) دست‌نخورده می‌ماند.
 *
 * چرا کلاسِ مستقل و نه یک متدِ عمومیِ `BackupService`؟
 * `BackupService` به `CpmsDb`/Dumper/Settings/Audit/Op نیاز دارد؛ اما عملیاتِ صریحِ
 * آینه‌سازی باید *پیش از هر درخواستِ راه‌دور* همین راستی‌آزمایی را بدونِ هیچ
 * وابستگیِ DB/وردپرسی اجرا کند (قرارداد Suite: تستِ Unit بدونِ WP).
 *
 * حالتِ `requireManifestHash` برای عملیات‌هایی است که باید «اثرِ انگشتیِ مؤثقِ
 * مانیفست» را ثبت/منتقل کنند: در وضعیتِ legacy (غیبتِ `manifest.json.sha256`) که
 * اصالتِ مانیفست قابلِ اثبات نیست، به‌جای هشدار، Fail-Closed رد می‌شود.
 */

declare(strict_types=1);

// phpcs:disable WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
//   I/O خامِ خواندنیِ کنترل‌شده عمدی است: راستی‌آزماییِ مبدأ بکاپ باید دقیقاً همان
//   semanticsِ خودِ PHP (realpath/is_file/filesize/hash_file) را حفظ کند و با
//   WP_Filesystem عوض نشود؛ نتایجِ غیرحساس (`@unlink/@rmdir` در نظافت) بررسی‌شده‌اند.

namespace ClinicCore\Application\Backup;

use ClinicCore\Domain\Backup\BackupManifest;

/**
 * وریفایرِ خالصِ محلی — بدونِ نوشتن روی دیسک، بدونِ وردپرس، بدونِ DB.
 */
final class LocalBackupVerifier
{
	/** sidecar موجود و هم‌خوان ⇒ اصالتِ مانیفست اثبات‌شده. */
	public const HASH_OK = 'ok';

	/** sidecar غایب ⇒ وضعیتِ legacy (در مسیرِ معمولی فقط هشدار است، نه خطا). */
	public const HASH_MISSING = 'missing';

	/** sidecar موجود ولی ناهم‌خوان ⇒ مانیفست دست‌کاری‌شده. */
	public const HASH_MISMATCH = 'mismatch';

	/**
     * وضعیتِ sidecarِ `manifest.json.sha256` نسبت به خودِ `manifest.json`.
     *
     * @return string یکی از self::HASH_*
     */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- نامِ عمومیِ تثبیت‌شدهٔ قرارداد Phase 15 (suiteِ RED پذیرفته‌شده).
	public static function manifestHashState( string $dir ): string
	{
		$expected = @file_get_contents( $dir . '/manifest.json.sha256' );
		if ( ! is_string( $expected ) || trim( $expected ) === '' ) {
			return self::HASH_MISSING;
		}
		$actual = hash_file( 'sha256', $dir . '/manifest.json' );

		return is_string( $actual ) && hash_equals( trim( $expected ), $actual )
			? self::HASH_OK
			: self::HASH_MISMATCH;
	}

	/**
     * راستی‌آزماییِ کاملِ یک دایرکتوریِ بکاپ (هیچ فایلی کامل در حافظه خوانده نمی‌شود).
     *
     * @param bool $requireManifestHash حالتِ سخت: نبودِ sidecar نیز خطاست (مسیرِ آینه‌سازی).
     *
     * @return array{ok: bool, errors: list<string>, warnings: list<string>, manifest: array<string, mixed>|null}
     */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- نامِ عمومیِ تثبیت‌شدهٔ قرارداد Phase 15 (suiteِ RED پذیرفته‌شده).
	public static function verify( string $dir, string $backupId, bool $requireManifestHash = false ): array
	{
		$raw = self::readManifest( $dir );
		if ( $raw === null ) {
			return self::result( false, array( 'manifest missing/corrupt' ), array(), null );
		}

		$warnings = array();
		$shaState = self::manifestHashState( $dir );
		if ( $shaState === self::HASH_MISMATCH ) {
			return self::result( false, array( 'manifest.json tampered' ), array(), null );
		}
		if ( $shaState === self::HASH_MISSING ) {
			if ( $requireManifestHash ) {
				return self::result(
					false,
					array( 'manifest.json.sha256 missing — manifest authenticity cannot be verified' ),
					array(),
					null
				);
			}
			$warnings[] = 'manifest.json.sha256 missing — legacy backup; manifest authenticity cannot be verified';
		}
		if ( (string) ( $raw['backup_id'] ?? '' ) !== $backupId ) {
			return self::result( false, array( 'manifest backup_id mismatch' ), $warnings, null );
		}

		$result = BackupManifest::verifyFiles(
			$raw,
			static function ( string $rel ) use ( $dir ): ?array {
				$abs = $dir . '/' . $rel;
				if ( ! is_file( $abs ) ) {
					return null;
				}

				return array(
					'size' => (int) filesize( $abs ),
					'sha256' => hash_file( 'sha256', $abs ) ?: '',
				);
			}
		);

		return self::result(
			(bool) $result['ok'],
			array_values( (array) $result['errors'] ),
			array_merge( $warnings, (array) ( $result['warnings'] ?? array() ) ),
			$raw
		);
	}

	/**
     * @return array<string, mixed>|null
     */
	private static function readManifest( string $dir ): ?array
	{
		$json = @file_get_contents( $dir . '/manifest.json' );
		if ( $json === false ) {
			return null;
		}
		$raw = json_decode( $json, true );

		return is_array( $raw ) ? $raw : null;
	}

	/**
     * @param list<string>              $errors
     * @param list<string>              $warnings
     * @param array<string, mixed>|null $manifest
     *
     * @return array{ok: bool, errors: list<string>, warnings: list<string>, manifest: array<string, mixed>|null}
     */
	private static function result( bool $ok, array $errors, array $warnings, ?array $manifest ): array
	{
		return array(
			'ok' => $ok,
			'errors' => $errors,
			'warnings' => $warnings,
			'manifest' => $manifest,
		);
	}
}
