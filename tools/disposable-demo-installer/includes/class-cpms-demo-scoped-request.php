<?php
/**
 * Request authorization for the separate scoped demo mode.
 *
 * Inherits the strict POST / capability / nonce / phrase / site-fingerprint gate
 * from Request but uses distinct actions and phrases so a strict-disposable
 * confirmation can never authorize a scoped-site operation.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Logical grouping is clearer than column alignment in these ownership maps.

/**
 * Scoped-mode request gates.
 */
class ScopedRequest extends Request {

	public const ACTION_ENABLE    = 'enable';
	public const ACTION_PROVISION = 'provision';
	public const ACTION_OTP       = 'otp';
	public const ACTION_ROTATE    = 'rotate_credentials';
	public const ACTION_DIAGNOSE  = 'diagnose';
	public const ACTION_TEARDOWN  = 'teardown';
	// Explicitly registered additional Clinic, and the repair path for a run that
	// was interrupted between sealing the intent and registering the Clinic.
	public const ACTION_ADD_CLINIC     = 'add_clinic';
	public const ACTION_RESOLVE_CLINIC = 'resolve_clinic';
	// Accept, in so many words, that a single-Clinic host is a PRIVATE TEST SITE
	// and that the demo's archived anchor Clinic will change its implicit scope
	// for good. Its confirmation phrase is the site's own host name, so the
	// acceptance is site-specific and cannot be replayed on another install.
	public const ACTION_PRIVATE_HOST = 'private_host';

	/**
	 * @return array<string,array{nonce:string,phrase:string,label:string}>
	 */
	public static function actions(): array {
		return array(
			self::ACTION_ENABLE    => array(
				'nonce'  => 'cpsd_enable',
				'phrase' => 'I_AUTHORIZE_SCOPED_DEMO_ON_NON_EMPTY_SITE',
				'label'  => 'فعالسازی حالت محدودشده',
			),
			self::ACTION_PROVISION => array(
				'nonce'  => 'cpsd_provision',
				'phrase' => 'CREATE_SCOPED_DEMO_ON_TEST_SITE',
				'label'  => 'ساخت دموی محدودشده',
			),
			self::ACTION_OTP       => array(
				'nonce'  => 'cpsd_otp',
				'phrase' => 'SHOW_SCOPED_DEMO_OTP',
				'label'  => 'ساخت کد OTP نمایشی',
			),
			self::ACTION_ROTATE    => array(
				'nonce'  => 'cpsd_rotate_credentials',
				'phrase' => 'ROTATE_SCOPED_DEMO_CREDENTIALS',
				'label'  => 'چرخش رمزهای نمایشی',
			),
			self::ACTION_DIAGNOSE  => array(
				'nonce'  => 'cpsd_diagnose',
				'phrase' => 'RUN_READ_ONLY_SCOPED_DIAGNOSTIC',
				'label'  => 'پیشنمایش پاکسازی',
			),
			self::ACTION_TEARDOWN  => array(
				'nonce'  => 'cpsd_teardown',
				'phrase' => 'REMOVE_ONLY_SCOPED_DEMO_DATA',
				'label'  => 'پاکسازی فقط دادههای دمو',
			),
			self::ACTION_ADD_CLINIC     => array(
				'nonce'  => 'cpsd_add_clinic',
				'phrase' => 'REGISTER_ADDITIONAL_DEMO_CLINIC',
				'label'  => 'ساخت درمانگاه نمایشی ثبت‌شده',
			),
			self::ACTION_RESOLVE_CLINIC => array(
				'nonce'  => 'cpsd_resolve_clinic',
				'phrase' => 'RESOLVE_INTERRUPTED_DEMO_CLINIC',
				'label'  => 'تعیین تکلیف درمانگاه نمایشی ناتمام',
			),
			// `phrase` is resolved per site by phrase(); the value here is only a
			// placeholder so the action stays discoverable next to its siblings.
			self::ACTION_PRIVATE_HOST  => array(
				'nonce'  => 'cpsd_private_host',
				'phrase' => '',
				'label'  => 'تأیید سایت آزمایشی خصوصی',
			),
		);
	}

	/**
	 * The exact text an administrator types to accept the private-test-site
	 * consequence: this install's own host name.
	 *
	 * @param Env $env Environment.
	 * @return string
	 */
	public static function private_host_phrase( Env $env ): string {
		return ScopedManifest::host_of( $env->home_url() );
	}

	/**
	 * Per-site phrase resolution. Only ACTION_PRIVATE_HOST is dynamic; every
	 * other action keeps its fixed, shared-nothing machine phrase.
	 *
	 * @param string $action Action key.
	 * @return string
	 * @throws Exception On unknown action.
	 */
	public function phrase( string $action ): string {
		if ( self::ACTION_PRIVATE_HOST === $action ) {
			$phrase = self::private_host_phrase( $this->env );
			// An unreadable home_url() must never degrade into "the empty phrase
			// matches the empty field".
			if ( '' === $phrase ) {
				throw new Exception( 'PRIVATE_HOST_MISMATCH', 'نام دامنهٔ این سایت خوانده نشد؛ تأیید سایت آزمایشی خصوصی ممکن نیست.' );
			}

			return $phrase;
		}

		return parent::phrase( $action );
	}
}
