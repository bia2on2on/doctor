<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

/**
 * Assets اختصاصیِ صفحاتِ مدیریت سازمان (B-3).
 *
 * چرا یک allowlist جداگانه و نه افزودن به `CpmsAssets::PAGES`:
 *  قراردادِ `IranGeographyAddressFormsTest` این دو اسلاگ را عمداً «بدونِ assets
 *  عمومیِ CPMS» تعریف کرده است (این صفحات کنترلِ جغرافیا/JSِ عمومی ندارند و نباید
 *  `cpms-admin` را بگیرند). رفعِ overflow در 320px/390px با افزودنِ آن‌ها به آن
 *  allowlist یعنی شکستنِ همان قرارداد؛ بنابراین این کلاس فقط یک stylesheet
 *  کوچکِ مخصوصِ همین دو صفحه را لود می‌کند.
 *
 *  - اسکوپ: `body.cpms-org-manager` (فقط همین دو صفحه).
 *  - بدون JS، بدون شبکه، بدون تغییر در CSS/JS صفحات دیگر.
 *  - `CpmsAssets::isCpmsPage()` همچنان برای این اسلاگ‌ها false است.
 */
final class OrgManagerAssets {

	/** اسلاگ‌هایی که این stylesheet را می‌گیرند. */
	public const PAGES = array(
		'cpms-organization-managers',
		'cpms-organization-manager-bootstrap',
	);

	public const BODY_CLASS = 'cpms-org-manager';

	public const STYLE_HANDLE = 'cpms-org-manager';

	public static function register(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'admin_body_class', array( self::class, 'body_class' ) );
	}

	public static function is_org_manager_page(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- فقط تشخیص صفحهٔ نمایشی
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return '' !== $page && in_array( $page, self::PAGES, true );
	}

	public static function enqueue(): void {
		if ( ! self::is_org_manager_page() ) {
			return;
		}

		$base = defined( 'CPMS_PLUGIN_URL' ) && '' !== CPMS_PLUGIN_URL ? rtrim( (string) CPMS_PLUGIN_URL, '/' ) : '';
		if ( '' === $base ) {
			return;
		}

		wp_enqueue_style(
			self::STYLE_HANDLE,
			$base . '/assets/css/cpms-org-manager.css',
			array(),
			defined( 'CPMS_VERSION' ) ? CPMS_VERSION : 'dev'
		);
	}

	public static function body_class( string $classes ): string {
		if ( ! self::is_org_manager_page() ) {
			return $classes;
		}

		return trim( $classes . ' ' . self::BODY_CLASS );
	}
}
