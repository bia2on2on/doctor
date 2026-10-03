<?php
/**
 * Part 15 — Slice 2C: راستی‌آزماییِ «تثبیت‌شدهٔ محلی» در یک منبعِ یگانه.
 *
 * چرا این کلاس جدا است؟ دو مصرف‌کننده باید **دقیقاً** یک معنای «بکاپ محلی معتبر» را
 * بدهند: (۱) مسیرِ موجودِ `BackupService::verifyIn()` (REST/Admin) و (۲) پیش‌شرطِ
 * `BackupS3Mirror` که پیش از هر درخواستِ راه‌دور باید بداند بکاپِ مبدأ سالم است.
 * هر درفتی بین این دو (مثلاً «آینه‌سازی سخت‌گیرتر از verify است») به ادعای غلطِ
 * ریموت منتهی می‌شود، پس منطقِ بررسی اینجا است و `BackupService` به همین delegate
 * می‌کند؛ هیچ رفتارِ عمومیِ جدیدی اضافه نمی‌شود.
 *
 * تفاوتِ only-این-slice: حالتِ `$require_manifest_hash` — برای مسیرِ آینه‌سازی نبودِ
 * sidecar هم خطاست (نه فقط هشدار)، چون بدونِ آن اصالتِ مانیفست اثبات‌پذیر نیست و
 * آپلودِ «اثبات‌شده» معنا ندارد. مصرف‌کنندهٔ موجود (Admin/REST) مثلِ قبل فقط
 * warning می‌گیرد — شکلِ نتیجه و رشته‌های پیام عمداً یکسانِ قبل است.
 *
 * هیچ نوشتنی روی دیسک ندارد؛ فقط خواندنِ اتمیک/حجم‌محور (`filesize`/`hash_file`) تا
 * فایلِ بزرگِ `db.sql` هرگز در حافظه بارگذاری نشود.
 */

declare(strict_types=1);

// phpcs:disable WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
//   I/O خامِ خواندنیِ کنترل‌شده عمدی است: راستی‌آزماییِ مبدأ بکاپ باید دقیقاً همان
//   semanticsِ خودِ PHP (is_file/filesize/hash_file) را حفظ کند و با WP_Filesystem عوض
//   نشود.

namespace ClinicCore\Application\Backup;

use ClinicCore\Domain\Backup\BackupManifest;

/**
 * وریفایرِ خالصِ محلی — بدونِ نوشتن روی دیسک، بدونِ وردپرس، بدونِ DB.
 */
final class LocalBackupVerifier {
	/** sidecar موجود و هم‌خوان ⇒ اصالتِ مانیفست اثبات‌شده. */
	public const HASH_OK       = 'ok';
	/** sidecar غایب ⇒ وضعیتِ legacy (در مسیرِ معمولی فقط هشدار است، نه خطا). */
	public const HASH_MISSING  = 'missing';
	/** sidecar موجود ولی ناهم‌خوان ⇒ مانیفست دست‌کاری‌شده. */
	public const HASH_MISMATCH = 'mismatch';

	/**
	 * وضعیتِ sidecarِ `manifest.json.sha256` نسبت به خودِ `manifest.json`.
	 *
	 * @param string $dir دایرکتوریِ بکاپ.
	 *
	 * @return string یکی از self::HASH_*
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- نامِ عمومیِ تثبیت‌شدهٔ قرارداد Phase 15 (suiteِ RED پذیرفته‌شده).
	public static function manifestHashState( string $dir ): string {
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
	 * @param string $dir                 دایرکتوریِ بکاپ.
	 * @param string $backup_id           شناسهٔ بکاپ (باید با مانیفست بخواند).
	 * @param bool   $require_manifest_hash حالتِ سخت: نبودِ sidecar نیز خطاست (مسیرِ آینه‌سازی).
	 *
	 * @return array{ok: bool, errors: list<string>, warnings: list<string>, manifest: array<string, mixed>|null}
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- نامِ عمومیِ تثبیت‌شدهٔ قرارداد Phase 15 (suiteِ RED پذیرفته‌شده).
	public static function verify( string $dir, string $backup_id, bool $require_manifest_hash = false ): array {
		$raw = self::read_manifest( $dir );
		if ( $raw === null ) {
			return self::result( false, array( 'manifest missing/corrupt' ), array(), null );
		}

		$warnings   = array();
		$hash_state = self::manifestHashState( $dir );
		if ( $hash_state === self::HASH_MISMATCH ) {
			return self::result( false, array( 'manifest.json tampered' ), array(), null );
		}
		if ( $hash_state === self::HASH_MISSING ) {
			if ( $require_manifest_hash ) {
				return self::result(
					false,
					array( 'manifest.json.sha256 missing — manifest authenticity cannot be verified' ),
					array(),
					null
				);
			}
			$warnings[] = 'manifest.json.sha256 missing — legacy backup; manifest authenticity cannot be verified';
		}
		if ( (string) ( $raw['backup_id'] ?? '' ) !== $backup_id ) {
			return self::result( false, array( 'manifest backup_id mismatch' ), $warnings, null );
		}

		$result = BackupManifest::verifyFiles(
			$raw,
			static function ( string $rel ) use ( $dir ): ?array {
				$abs = $dir . '/' . $rel;
				if ( ! is_file( $abs ) ) {
					return null;
				}
				$hash = hash_file( 'sha256', $abs );

				return array(
					'size'   => (int) filesize( $abs ),
					'sha256' => is_string( $hash ) ? $hash : '',
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
	 * @param string $dir دایرکتوریِ بکاپ.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function read_manifest( string $dir ): ?array {
		$json = @file_get_contents( $dir . '/manifest.json' );
		if ( $json === false ) {
			return null;
		}
		$raw = json_decode( $json, true );

		return is_array( $raw ) ? $raw : null;
	}

	/**
	 * @param bool                    $ok        نتیجهٔ کلی.
	 * @param list<string>            $errors     خطاهای bounded (بدونِ مسیر/secret).
	 * @param list<string>            $warnings   هشدارها.
	 * @param array<string, mixed>|null $manifest مانیفستِ خوانده‌شده (برای مصرف‌کنندهٔ داخلی).
	 *
	 * @return array{ok: bool, errors: list<string>, warnings: list<string>, manifest: array<string, mixed>|null}
	 */
	private static function result( bool $ok, array $errors, array $warnings, ?array $manifest ): array {
		return array(
			'ok'       => $ok,
			'errors'   => $errors,
			'warnings' => $warnings,
			'manifest' => $manifest,
		);
	}
}
