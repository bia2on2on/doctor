<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Backup;

/**
 * Phase 15 — Slice 1: پوششِ رمزنگاریِ فایلِ بکاپ (Backup Encryption Envelope) — قالب v1.
 *
 * پریمیتیوِ کوچک و قابلِ استفادهٔ مجدد: یک فایلِ plaintext با پردازشِ جریانی/چانکی
 * به یک فایلِ رمزشده‌یِ **نسخه‌دار و احراز‌اصالت‌شده** تبدیل می‌شود و همان فایل با
 * کلیدِ درست به مقصدِ plaintext بازمی‌گردد.
 *
 * دامنهٔ عمداً باریک (Slice 1): فقط همین پوششِ فایلی. هیچ انتقالِ S3/شبکه، هیچ
 * provider/transport abstraction، هیچ تنظیمات/Setting، هیچ اعتبارنامه، هیچ UI،
 * هیچ capability/role، هیچ مهاجرت (migration)، هیچ تغییرِ backup.run/restore و
 * هیچ KDF/رمزِ عبور/key-id در این کلاس نیست. کلیدِ ۳۲ بایتیِ خام را فراخوان از
 * بیرونِ persistence می‌دهد (مالکیتِ کلید بیرونِ افزونه است).
 *
 * ## قالب v1 — دقیقاً بایت‌به‌بایت
 *
 *     offset 0      ۸ بایت   magic/version: `CPMSBK01`
 *     offset 8     ۲۴ بایت   هدرِ sodium secretstream XChaCha20-Poly1305
 *                            (SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES)
 *     offset 32       ...    جریانِ رکوردهای احراز‌اصالت‌شده؛ هر رکورد =
 *                            (تا CHUNK_BYTES بایت plaintext) + ۱۷ بایت tag
 *                            (SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES)
 *
 * همهٔ رکوردها به‌جز آخرین با TAG_MESSAGE و آخرین رکورد با TAG_FINAL زده می‌شود؛
 * بنابراین tagِ نهایی همیشه حضور دارد و هیچ بایتی بعد از رکوردِ نهایی پذیرفته
 * نمی‌شود. فایلِ خالی هم دقیقاً **یک** رکوردِ نهاییِ ۱۷ بایتی می‌دهد (قالب، فایلِ
 * خالی را بدون حالتِ خاص پشتیبانی می‌کند).
 *
 *     حجم کل = 32 + plaintext_size + 17 * max( 1, ceil( plaintext_size / CHUNK_BYTES ) )
 *
 * فقط اطلاعاتی ذخیره می‌شود که برای parse/decrypt ایمن لازم است: نشانگرِ
 * magic/version و هدرِ sodium. هیچ filename/timestamp/checksum/key-id/متابرعیتِ
 * دیگری اضافه نمی‌شود.
 *
 * ## قرارداد امنیتی (fail-closed)
 *
 *  - طولِ کلید **قبل** از هر عملیاتِ crypto بررسی می‌شود (دقیقاً ۳۲ بایت).
 *  - هیچ‌گاه کلِ فایلِ مبدأ/مقصد در حافظه خوانده نمی‌شود؛ پردازش چانکی است و
 *    سقفِ مصرفِ حافظه مستقل از اندازهٔ فایل باقی می‌ماند.
 *  - کلید و plaintext هرگز لاگ/ذخیره/چاپ نمی‌شوند و در پیامِ خطای قابلِ‌مشاهدهٔ
 *    فراخوان (message/code/data) ظاهر نمی‌شوند؛ پیام‌ها ثابت و بدونِ مسیر‌اند.
 *  - کلیدِ اشتباه، دست‌کاریِ بدنه/هدر، بریدگی، نبودِ tagِ نهایی، بایت‌های اضافی و
 *    magic/versionِ ناسازگار همه **یک** کدِ خطای یکسان می‌دهند (بدون اوراکلِ
 *    تمایزدهنده): encrypt ⇒ `CLINIC_BACKUP_ENCRYPTION_FAILED` و
 *    decrypt ⇒ `CLINIC_BACKUP_DECRYPTION_FAILED`.
 *  - خروجی ابتدا در فایلِ موقتِ همجوارِ مقصد (sibling) نوشته و در پایان با
 *    `rename` جایگزین می‌شود؛ فایلِ موقت **انحصاری** ساخته می‌شود
 *    (`fopen(..., 'xb')` ⇒ `O_CREAT|O_EXCL`، بدونِ هیچ check-then-open): مسیرِ
 *    موقتِ موجود هرگز truncate نمی‌شود، سیم‌لینک دنبال نمی‌شود، برخورد با نامِ
 *    اشغال‌شده با نامِ تصادفیِ بعدی (تعدادِ تلاشِ محدود) دور زده می‌شود و در
 *    نهایت شکست، عملیات fail-closed است. مقصدِ موجود هرگز با خروجیِ ناقص
 *    جایگزین نمی‌شود، در هر مسیرِ شکست فقط فایلِ موقتی که **خودمان ساخته‌ایم**
 *    پاک می‌شود و هیچ مقصدِ شبیهِ‌موفقیت باقی نمی‌ماند.
 *  - فایلِ مبدأ در هیچ مسیرِ موفق/ناموفقی تغییر نمی‌کند و حذف نمی‌شود؛ مبدأ و
 *    مقصدِ یکسان (realpath) صریحاً رد می‌شود.
 *  - نتیجهٔ همهٔ توابعِ فایل‌سیستمی بررسی می‌شود و هیچ خطایی به «موفقیت» تبدیل
 *    نمی‌شود؛ خطاهای داخلیِ crypto هرگز به بیرون درز نمی‌کنند.
 *
 * نام‌گذاریِ پارامترها طبق سبکِ وردپرس snake_case است؛ ترتیب/نوع/بازگشتِ امضای
 * عمومی (encryptFile/decryptFile) دقیقاً مطابقِ قراردادِ تثبیت‌شدهٔ Phase 15 است.
 *
 * @see \ClinicCore\Infrastructure\Backup\BackupException
 */
final class BackupEncryptionEnvelope {

	/** نشانگرِ magic + version قالب v1: `CPMS` + `BK` (backup-keyed) + `01`. */
	public const MAGIC = 'CPMSBK01';

	/** بایت‌های plaintext در هر رکوردِ احراز‌اصالت‌شدهٔ قالب v1 (۱ مبی‌بایت). */
	public const CHUNK_BYTES = 1048576;

	/** اندازهٔ هدرِ secretstream — SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES. */
	private const HEADER_BYTES = 24;

	/** اندازهٔ tagِ هر رکورد — SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES. */
	private const TAG_BYTES = 17;

	/** طولِ کلیدِ secretstream — SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES. */
	private const KEY_BYTES = 32;

	/** framingِ ثابت هر فایل: ۸ بایت magic + ۲۴ بایت هدر. */
	private const ENVELOPE_OVERHEAD = 8 + self::HEADER_BYTES;

	/**
	 * پسوندِ نامِ فایلِ موقتِ همجوار. تلاشِ **اولِ** ساخت عمداً با همین نامِ قطعی
	 * انجام می‌شود تا سناریوی برخورد به‌صورت قطعی (نه احتمالی) قابلِ آزمایش باشد؛
	 * تلاش‌های بعدی نامِ تصادفیِ رمزنگاری‌شده می‌گیرند. قطعی‌بودنِ نام ایمن است
	 * چون ساخت **انحصاری** است: مسیرِ موجود truncate نمی‌شود و سیم‌لینک دنبال نمی‌شود.
	 */
	private const TEMP_SUFFIX = '.cpms-part';

	/**
	 * سقفِ تلاش برای ساختِ انحصاریِ فایلِ موقت (۱ تلاشِ قطعی + تلاش‌های تصادفی)؛
	 * پس از آن عملیات fail-closed شکست می‌خورد و مقصد دست‌نخورده می‌ماند.
	 */
	private const TEMP_CREATE_ATTEMPTS = 8;

	private const ENCRYPT_FAILED = 'CLINIC_BACKUP_ENCRYPTION_FAILED';

	private const DECRYPT_FAILED = 'CLINIC_BACKUP_DECRYPTION_FAILED';

	/** پیامِ ثابت و بی‌اوراکلِ encrypt — بدونِ کلید/plaintext/مسیر. */
	private const ENCRYPT_MESSAGE = 'backup file encryption failed';

	/** پیامِ ثابت و بی‌اوراکلِ decrypt — بدونِ کلید/plaintext/مسیر. */
	private const DECRYPT_MESSAGE = 'backup file decryption failed';

	/**
	 * رمزگذاریِ فایلِ plaintext به فایلِ رمزشده (قالب v1).
	 *
	 * خواندنِ مبدأ و نوشتنِ مقصد چانکی است (سقفِ CHUNK_BYTES در هر رکورد)؛ خروجی
	 * ابتدا در فایلِ موقتِ همجوار نوشته و پس از flush/close با `rename` اتمیک
	 * نهایی می‌شود. اگر مبدأ در میانهٔ عملیات بزرگ شود (بایتِ اضافهٔ خوانده‌نشده)
	 * عملیات fail-closed شکست می‌خورد.
	 *
	 * @param string $source_path      مسیرِ فایلِ plaintext (فایلِ معمولیِ موجود).
	 * @param string $destination_path مسیرِ فایلِ رمزشده (مقصد).
	 * @param string $key              کلیدِ خامِ ۳۲ بایتی (خارج از persistence).
	 *
	 * @throws BackupException کد `CLINIC_BACKUP_ENCRYPTION_FAILED` در هر شکست.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- نامِ عمومیِ تثبیت‌شدهٔ قرارداد Phase 15 (encryptFile/decryptFile در فایلِ RED پذیرفته‌شده).
	public static function encryptFile( string $source_path, string $destination_path, string $key ): void {
		if ( strlen( $key ) !== self::KEY_BYTES ) {
			throw BackupException::of( self::ENCRYPT_FAILED, self::ENCRYPT_MESSAGE );
		}

		$source = null;
		$output = null;
		$temp   = '';

		try {
			$source = self::open_source( $source_path, self::ENCRYPT_FAILED, self::ENCRYPT_MESSAGE );
			self::assert_distinct_destination( $source_path, $destination_path, self::ENCRYPT_FAILED, self::ENCRYPT_MESSAGE );

			$size              = self::source_size( $source, self::ENCRYPT_FAILED, self::ENCRYPT_MESSAGE );
			[ $output, $temp ] = self::open_exclusive_output( $destination_path, self::ENCRYPT_FAILED, self::ENCRYPT_MESSAGE );

			[ $state, $header ] = self::start_encryption( $key );
			self::write_all( $output, self::MAGIC . $header, self::ENCRYPT_FAILED, self::ENCRYPT_MESSAGE );

			$remaining = $size;
			while ( $remaining > self::CHUNK_BYTES ) {
				$plain  = self::read_exactly( $source, self::CHUNK_BYTES, self::ENCRYPT_FAILED, self::ENCRYPT_MESSAGE );
				$record = sodium_crypto_secretstream_xchacha20poly1305_push(
					$state,
					$plain,
					'',
					SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
				);
				self::write_all( $output, $record, self::ENCRYPT_FAILED, self::ENCRYPT_MESSAGE );
				$remaining -= self::CHUNK_BYTES;
			}

			$tail = self::read_exactly( $source, $remaining, self::ENCRYPT_FAILED, self::ENCRYPT_MESSAGE );
			$last = sodium_crypto_secretstream_xchacha20poly1305_push(
				$state,
				$tail,
				'',
				SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
			);
			self::write_all( $output, $last, self::ENCRYPT_FAILED, self::ENCRYPT_MESSAGE );

			// مبدأ نباید در میانهٔ عملیات بزرگ شود: هر بایتِ اضافه = شکستِ صریح.
			if ( ! self::is_source_exhausted( $source ) ) {
				throw BackupException::of( self::ENCRYPT_FAILED, self::ENCRYPT_MESSAGE );
			}

			self::finalise( $output, $temp, $destination_path, self::ENCRYPT_FAILED, self::ENCRYPT_MESSAGE );
			$output = null;
			$temp   = '';
		} catch ( BackupException $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			throw BackupException::of( self::ENCRYPT_FAILED, self::ENCRYPT_MESSAGE );
		} finally {
			self::release( $output, $source, $temp );
		}
	}

	/**
	 * رمزگشاییِ فایلِ رمزشده (قالب v1) به مقصدِ plaintext.
	 *
	 * رکوردها با اندازهٔ ثابتِ CHUNK_BYTES + 17 خوانده می‌شوند تا مرزِ رکورد —
	 * مستقل از مرزهای fread — دقیق حفظ شود؛ آخرین رکورد کوتاه‌تر است و باید
	 * TAG_FINAL داشته باشد. هر ناسازگاریِ framing (بریدگی، بایتِ اضافی بعد از
	 * رکوردِ نهایی، tagِ غیرمنتظره) fail-closed است. نوشتن مقصد مثل encrypt در
	 * فایلِ موقتِ همجوار و با `rename` نهایی می‌شود.
	 *
	 * @param string $source_path      مسیرِ فایلِ رمزشده (مبدأ).
	 * @param string $destination_path مسیرِ فایلِ plaintext (مقصد).
	 * @param string $key              کلیدِ خامِ ۳۲ بایتی (همان کلیدِ encrypt).
	 *
	 * @throws BackupException کد `CLINIC_BACKUP_DECRYPTION_FAILED` در هر شکست.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- نامِ عمومیِ تثبیت‌شدهٔ قرارداد Phase 15 (encryptFile/decryptFile در فایلِ RED پذیرفته‌شده).
	public static function decryptFile( string $source_path, string $destination_path, string $key ): void {
		if ( strlen( $key ) !== self::KEY_BYTES ) {
			throw BackupException::of( self::DECRYPT_FAILED, self::DECRYPT_MESSAGE );
		}

		$source = null;
		$output = null;
		$temp   = '';

		try {
			$source = self::open_source( $source_path, self::DECRYPT_FAILED, self::DECRYPT_MESSAGE );
			self::assert_distinct_destination( $source_path, $destination_path, self::DECRYPT_FAILED, self::DECRYPT_MESSAGE );

			$size = self::source_size( $source, self::DECRYPT_FAILED, self::DECRYPT_MESSAGE );
			if ( $size < self::ENVELOPE_OVERHEAD + self::TAG_BYTES ) {
				throw BackupException::of( self::DECRYPT_FAILED, self::DECRYPT_MESSAGE );
			}

			$prefix = self::read_exactly( $source, self::ENVELOPE_OVERHEAD, self::DECRYPT_FAILED, self::DECRYPT_MESSAGE );
			if ( self::MAGIC !== substr( $prefix, 0, 8 ) ) {
				throw BackupException::of( self::DECRYPT_FAILED, self::DECRYPT_MESSAGE );
			}

			$state             = self::start_decryption( substr( $prefix, 8, self::HEADER_BYTES ), $key );
			[ $output, $temp ] = self::open_exclusive_output( $destination_path, self::DECRYPT_FAILED, self::DECRYPT_MESSAGE );

			$remaining = $size - self::ENVELOPE_OVERHEAD;
			$finished  = false;
			while ( $remaining > 0 ) {
				$record_bytes = min( self::CHUNK_BYTES + self::TAG_BYTES, $remaining );
				$record       = self::read_exactly( $source, $record_bytes, self::DECRYPT_FAILED, self::DECRYPT_MESSAGE );
				$remaining   -= $record_bytes;

				$result = sodium_crypto_secretstream_xchacha20poly1305_pull( $state, $record );
				if ( ! is_array( $result ) || ! isset( $result[0], $result[1] ) || ! is_string( $result[0] ) || ! is_int( $result[1] ) ) {
					throw BackupException::of( self::DECRYPT_FAILED, self::DECRYPT_MESSAGE );
				}

				$plain = $result[0];
				$tag   = $result[1];

				if ( SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE === $tag ) {
					// رکوردِ غیرنهایی باید دقیقاً کامل باشد و رکوردِ نهایی در ادامه بیاید.
					if ( strlen( $plain ) !== self::CHUNK_BYTES || $remaining <= 0 ) {
						throw BackupException::of( self::DECRYPT_FAILED, self::DECRYPT_MESSAGE );
					}
					self::write_all( $output, $plain, self::DECRYPT_FAILED, self::DECRYPT_MESSAGE );
					continue;
				}

				// فقط TAG_FINAL پایانِ مجاز است؛ و باید آخرین بایت‌های فایل باشد.
				if ( SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL !== $tag || 0 !== $remaining || strlen( $plain ) > self::CHUNK_BYTES ) {
					throw BackupException::of( self::DECRYPT_FAILED, self::DECRYPT_MESSAGE );
				}

				self::write_all( $output, $plain, self::DECRYPT_FAILED, self::DECRYPT_MESSAGE );
				$finished = true;
				break;
			}

			// بریدگی / نبودِ رکوردِ نهایی: هیچ tagِ FINAL دیده نشد.
			if ( ! $finished ) {
				throw BackupException::of( self::DECRYPT_FAILED, self::DECRYPT_MESSAGE );
			}

			self::finalise( $output, $temp, $destination_path, self::DECRYPT_FAILED, self::DECRYPT_MESSAGE );
			$output = null;
			$temp   = '';
		} catch ( BackupException $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			throw BackupException::of( self::DECRYPT_FAILED, self::DECRYPT_MESSAGE );
		} finally {
			self::release( $output, $source, $temp );
		}
	}

	/**
	 * مبدأ و مقصد نباید یک فایل باشند (realpath) — وگرنه «مبدأ دست‌نخورده» نقض
	 * می‌شود. مقصدِ ناموجود هم با والدِ canonical مقایسه می‌شود.
	 */
	private static function assert_distinct_destination(
		string $source_path,
		string $destination_path,
		string $error_code,
		string $message
	): void {
		$source_real = realpath( $source_path );
		if ( false === $source_real ) {
			throw BackupException::of( $error_code, $message );
		}

		$destination_real = realpath( $destination_path );
		if ( false === $destination_real ) {
			$directory_real = realpath( dirname( $destination_path ) );
			if ( false !== $directory_real ) {
				$destination_real = $directory_real . DIRECTORY_SEPARATOR . basename( $destination_path );
			}
		}

		if ( false !== $destination_real && $source_real === $destination_real ) {
			throw BackupException::of( $error_code, $message );
		}
	}

	/**
	 * وضعیتِ push را با هدرِ تصادفیِ sodium می‌سازد.
	 *
	 * @return array{0: string, 1: string} [state, header]
	 */
	private static function start_encryption( string $key ): array {
		/** @var array{0: string, 1: string} $setup */
		$setup = sodium_crypto_secretstream_xchacha20poly1305_init_push( $key );

		return $setup;
	}

	/**
	 * وضعیتِ pull را از هدرِ فایل می‌سازد؛ هدرِ نامعتبر fail-closed است.
	 */
	private static function start_decryption( string $header, string $key ): string {
		$state = sodium_crypto_secretstream_xchacha20poly1305_init_pull( $header, $key );
		if ( ! is_string( $state ) ) {
			throw BackupException::of( self::DECRYPT_FAILED, self::DECRYPT_MESSAGE );
		}

		return $state;
	}

	/*
	 * ---------------------------------------------------------------------
	 * I/O خامِ فایلی در این بخش **عمدی** است: WP_Filesystem برای یک پوششِ
	 * رمزنگاریِ جریانی/اتمیک مناسب نیست — نیازمند credentials/config است،
	 * renameِ اتمیک و رفتارِ باینریِ تضمین‌شده ندارد و می‌تواند کل فایل را در
	 * حافظه بگیرد. همهٔ نتایجِ این توابع بررسی می‌شود (fail-closed) و «@»
	 * فقط برای تبدیلِ صریحِ خطا به BackupException است، نه نادیده‌گرفتنِ خطا.
	 * ---------------------------------------------------------------------
	 */
	// phpcs:disable WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions -- I/O خام و کنترل‌شدهٔ رمزنگاری (بدونِ WP_Filesystem)؛ همهٔ نتایج بررسی می‌شوند.

	/**
	 * بازکردنِ مبدأ به‌صورت فقط‌خواندنی و باینری‌امن؛ مبدأ باید فایلِ معمولیِ موجود باشد.
	 *
	 * @return resource
	 */
	private static function open_source( string $source_path, string $error_code, string $message ) {
		if ( '' === $source_path || ! is_file( $source_path ) ) {
			throw BackupException::of( $error_code, $message );
		}

		$handle = @fopen( $source_path, 'rb' );
		if ( false === $handle ) {
			throw BackupException::of( $error_code, $message );
		}

		return $handle;
	}

	/**
	 * ساختِ **انحصاری** فایلِ موقت در همان مسیرِ مقصد (هم‌فایل‌سیستم ⇒ renameِ
	 * اتمیک). تلاشِ اول با نامِ قطعیِ `TEMP_SUFFIX` و تلاش‌های بعدی با نامِ
	 * تصادفیِ رمزنگاری‌شده انجام می‌شود؛ این‌جا **هیچ** `file_exists` قبلی‌ای
	 * وجود ندارد — انحصار را خودِ عملیاتِ بازکردن با پرچمِ `'x'` تحمیل می‌کند
	 * (`O_CREAT|O_EXCL`: اگر مسیر از قبل موجود باشد بازکردن شکست می‌خورد و
	 * بایت‌های فایلِ موجودِ بیگانه هرگز truncate/بازنویسی نمی‌شوند؛ سیم‌لینک هم
	 * دنبال نمی‌شود). پس از سقفِ تلاش‌ها عملیات fail-closed است.
	 *
	 * @return array{0: resource, 1: string} [handle, temp path]
	 */
	private static function open_exclusive_output( string $destination_path, string $error_code, string $message ): array {
		for ( $attempt = 0; $attempt < self::TEMP_CREATE_ATTEMPTS; $attempt++ ) {
			$suffix = 0 === $attempt ? self::TEMP_SUFFIX : self::TEMP_SUFFIX . '-' . bin2hex( random_bytes( 6 ) );

			// 'x' = O_CREAT|O_EXCL: ساختِ انحصاری؛ مسیرِ موجود هرگز truncate/بازنویسی نمی‌شود.
			$handle = @fopen( $destination_path . $suffix, 'xb' );
			if ( false !== $handle ) {
				return [ $handle, $destination_path . $suffix ];
			}
		}

		throw BackupException::of( $error_code, $message );
	}

	/**
	 * خواندنِ دقیقِ $length بایت؛ هر بریدگی/خطای خواندن شکست است (fail-closed).
	 *
	 * @param resource $handle
	 */
	private static function read_exactly( $handle, int $length, string $error_code, string $message ): string {
		$buffer = '';
		$got    = 0;
		while ( $got < $length ) {
			$piece = @fread( $handle, $length - $got );
			if ( false === $piece || '' === $piece ) {
				throw BackupException::of( $error_code, $message );
			}
			$buffer = 0 === $got ? $piece : $buffer . $piece;
			$got   += strlen( $piece );
		}

		return $buffer;
	}

	/**
	 * نوشتنِ کاملِ $bytes؛ نوشتنِ جزئی را ادامه می‌دهد و شکست را بالا می‌دهد.
	 *
	 * @param resource $handle
	 */
	private static function write_all( $handle, string $bytes, string $error_code, string $message ): void {
		$length = strlen( $bytes );
		$offset = 0;
		while ( $offset < $length ) {
			$slice   = 0 === $offset ? $bytes : substr( $bytes, $offset );
			$written = @fwrite( $handle, $slice );
			if ( false === $written || 0 === $written ) {
				throw BackupException::of( $error_code, $message );
			}
			$offset += $written;
		}
	}

	/**
	 * flush + close + جایگزینیِ اتمیکِ مقصد با فایلِ موقت (rename).
	 *
	 * @param resource $handle
	 */
	private static function finalise( $handle, string $temp_path, string $destination_path, string $error_code, string $message ): void {
		$flushed = @fflush( $handle );
		$closed  = @fclose( $handle );
		if ( false === $flushed || false === $closed ) {
			throw BackupException::of( $error_code, $message );
		}

		if ( ! @rename( $temp_path, $destination_path ) ) {
			throw BackupException::of( $error_code, $message );
		}
	}

	/**
	 * پاک‌سازیِ قطعی در finally: بستنِ handleها و حذفِ فایلِ موقت — اما **فقط**
	 * اگر همین فراخوانی آن را انحصاری ساخته باشد (`$temp_path` صرفاً پس از یک
	 * ساختِ انحصاریِ موفق مقدار می‌گیرد). یک فایلِ بیگانهٔ برخوردکننده هرگز
	 * حذف نمی‌شود، چون نه handle‌ای از آن داریم و نه مسیرش این‌جا ثبت می‌شود.
	 *
	 * @param resource|null $output
	 * @param resource|null $source
	 */
	private static function release( $output, $source, string $temp_path ): void {
		if ( is_resource( $output ) ) {
			@fclose( $output );
		}
		if ( is_resource( $source ) ) {
			@fclose( $source );
		}
		if ( '' !== $temp_path && file_exists( $temp_path ) ) {
			@unlink( $temp_path );
		}
	}

	/**
	 * اندازهٔ بایتِ مبدأ از همان handleِ خواندنی.
	 *
	 * @param resource $handle
	 */
	private static function source_size( $handle, string $error_code, string $message ): int {
		$stat = @fstat( $handle );
		if ( ! is_array( $stat ) || ! isset( $stat['size'] ) || ! is_int( $stat['size'] ) || $stat['size'] < 0 ) {
			throw BackupException::of( $error_code, $message );
		}

		return $stat['size'];
	}

	/**
	 * آیا مبدأ کامل مصرف شده است؟ هر بایتِ اضافه (رشدِ فایل در میانهٔ کار) شکست است.
	 *
	 * @param resource $handle
	 */
	private static function is_source_exhausted( $handle ): bool {
		$extra = @fread( $handle, 1 );

		return '' === $extra;
	}

	// phpcs:enable WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
}
