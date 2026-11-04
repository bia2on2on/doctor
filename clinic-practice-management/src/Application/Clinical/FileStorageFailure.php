<?php

declare(strict_types=1);

namespace ClinicCore\Application\Clinical;

use ClinicCore\Infrastructure\Storage\StorageConfigurationException;

/**
 * نگاشتِ شکستِ «زیرساختِ ذخیره‌سازی فایل بالینی» به دو خروجیِ جداگانه:
 *
 *  ۱) یک Contextِ اسکالرِ امن برای OpLogger (کد/ریشه/دلیلِ کوتاه‌شده) تا شکست
 *     در Operational Log قابلِ ردیابی باشد و دیگر 500ِ بی‌صدا نداشته باشیم.
 *  ۲) یک ClinicalException با کدِ ثبت‌شدهٔ موجود «CLINIC_INTERNAL_ERROR/500»
 *     (docs/api/error-codes.md) و پیامِ فارسیِ قابلِ اقدام — هدایتِ کاربر به
 *     مدیر کلینیک و صفحهٔ «سلامت سیستم». مسیرِ فایل‌سیستم هرگز به کلاینتِ
 *     REST نمی‌رسد (فقط در لاگِ سمت سرور می‌ماند).
 *
 * تمایز با استثناهای قاعدهٔ بالینی: این نگاشت فقط زمانِ فراخوانیِ مستقیمِ
 * ذخیره‌سازی (store/read) اعمال می‌شود؛ استثناهای ClinicalExceptionِ
 * دامنه‌ای هرگز از این مسیر رد نمی‌شوند. رفتار Fail-Closed حفظ می‌شود:
 * در شکستِ ذخیره‌سازی هیچ بایت/ردیفی نوشته نمی‌شود و عملیات کامل رد می‌شود.
 */
final class FileStorageFailure {

	/** رویدادِ Operational Log (مانند SMS_FAILED: رویدادِ لاگ، نه کد API). */
	public const OP_EVENT = 'CPMS_FILE_STORAGE_FAILED';

	/** بیشینهٔ طولِ دلیلِ ثبت‌شده در Context. */
	private const REASON_MAX = 160;

	/**
	 * Contextِ تخت و اسکالر برای OpLogger::error — نامِ کلیدها عمداً فاقدِ
	 * زیررشته‌های maskِ OpLogger::sanitize (code/text/…) است.
	 *
	 * @return array{root: string, failure: string, reason: string}
	 */
	public static function op_context( string $root, \Throwable $e ): array {
		// «کدِ تایپ‌شدهٔ» خطای پیکربندیِ ذخیره‌سازی (مثل CLINIC_STORAGE_NOT_WRITABLE)
		// وگرنه کلاسِ استثنا — هر دو اسکالر و بدونِ مسیر محرمانه نیستند.
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$failure = $e instanceof StorageConfigurationException
			? $e->errorCode
			: get_class( $e );
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		return [
			'root'    => $root,
			'failure' => $failure,
			'reason'  => mb_substr( $e->getMessage(), 0, self::REASON_MAX ),
		];
	}

	/**
	 * خطای سمتِ کلاینت — کد/وضعیتِ از پیش ثبت‌شده + پیامِ فارسیِ قابلِ اقدام.
	 * پیام فقط «اقدامِ بعدی» را می‌گوید و هیچ جزئیاتِ فنی/مسیری فاش نمی‌کند.
	 *
	 * @param string $action_fa جملهٔ کوتاهِ فارسیِ عملیاتِ شکست‌خورده.
	 */
	public static function client_exception( string $action_fa ): ClinicalException {
		return ClinicalException::of(
			'CLINIC_INTERNAL_ERROR',
			$action_fa . ' — ذخیره‌سازی پرونده‌های پزشکی در دسترس نیست؛ '
				. 'لطفاً با مدیر کلینیک تماس بگیرید و وضعیت را از صفحهٔ '
				. '«سلامت سیستم» بررسی کنید.',
			500
		);
	}
}
