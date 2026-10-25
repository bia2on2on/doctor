<?php
/**
 * Request authorization for the single admin page.
 *
 * Every mutating action requires, in this order: POST, an authenticated
 * Administrator with manage_options AND install_plugins, an action-specific
 * nonce, an exact confirmation phrase typed by the operator, and the site
 * identity fingerprint that was displayed on the form. The last check is what
 * makes an accidental cross-tab/cross-site submission impossible.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

defined( 'ABSPATH' ) || exit;

/**
 * Capability + nonce + confirmation gate.
 */
class Request {

	public const ACTION_PROVISION = 'provision';
	public const ACTION_ROTATE    = 'rotate';
	public const ACTION_OTP       = 'otp';
	public const ACTION_TEARDOWN  = 'teardown';
	public const ACTION_DIAGNOSE  = 'diagnose';
	public const ACTION_DISMISS   = 'dismiss_tombstone';

	/**
	 * Per-action nonce action + the exact phrase the operator must type.
	 *
	 * @return array<string,array{nonce:string,phrase:string,label:string}>
	 */
	public static function actions(): array {
		return array(
			self::ACTION_PROVISION => array(
				'nonce'  => 'cpdd_provision',
				'phrase' => 'I_UNDERSTAND_THIS_IS_A_DISPOSABLE_EMPTY_SITE',
				'label'  => 'ساخت دمو',
			),
			self::ACTION_ROTATE    => array(
				'nonce'  => 'cpdd_rotate',
				'phrase' => 'ROTATE_DEMO_PASSWORDS',
				'label'  => 'چرخش رمزها',
			),
			self::ACTION_OTP       => array(
				'nonce'  => 'cpdd_otp',
				'phrase' => 'SHOW_DEMO_OTP',
				'label'  => 'ساخت کد OTP نمایشی',
			),
			self::ACTION_TEARDOWN  => array(
				'nonce'  => 'cpdd_teardown',
				'phrase' => 'DESTROY_DEMO_DATA_ONLY',
				'label'  => 'پاک‌سازی دمو',
			),
			self::ACTION_DIAGNOSE  => array(
				'nonce'  => 'cpdd_diagnose',
				'phrase' => 'RUN_READ_ONLY_DIAGNOSTIC',
				'label'  => 'تشخیص فقط‌خواندنی',
			),
			self::ACTION_DISMISS   => array(
				'nonce'  => 'cpdd_dismiss',
				'phrase' => 'CLEAR_TEARDOWN_RECORD',
				'label'  => 'پاک‌کردن سابقهٔ پاک‌سازی',
			),
		);
	}

	/**
	 * @var Env
	 */
	private Env $env;

	/**
	 * @var Manifest
	 */
	private Manifest $manifest;

	/**
	 * @param Env      $env Environment.
	 * @param Manifest $manifest Manifest store.
	 */
	public function __construct( Env $env, Manifest $manifest ) {
		$this->env      = $env;
		$this->manifest = $manifest;
	}

	/**
	 * @return string Nonce action for an action key.
	 * @throws Exception On unknown action.
	 */
	public function nonce_action( string $action ): string {
		$actions = self::actions();
		if ( ! isset( $actions[ $action ] ) ) {
			throw new Exception( 'ACTION_UNKNOWN', 'عملیات ناشناخته است.' );
		}

		return $actions[ $action ]['nonce'];
	}

	/**
	 * @return string Expected confirmation phrase.
	 * @throws Exception On unknown action.
	 */
	public function phrase( string $action ): string {
		$actions = self::actions();
		if ( ! isset( $actions[ $action ] ) ) {
			throw new Exception( 'ACTION_UNKNOWN', 'عملیات ناشناخته است.' );
		}

		return $actions[ $action ]['phrase'];
	}

	/**
	 * @param string $action Action key.
	 * @return string Nonce value for the rendered form.
	 */
	public function nonce( string $action ): string {
		return $this->env->nonce_create( $this->nonce_action( $action ) );
	}

	/**
	 * Value the form must carry back so a submission can only target the site it
	 * was rendered on.
	 *
	 * @return string
	 */
	public function site_fingerprint_field(): string {
		return $this->manifest->site_fingerprint();
	}

	/**
	 * Verify one submission. Throws on the first failure; never partially acts.
	 *
	 * @param string              $action Action key.
	 * @param array<string,mixed> $post   Submitted values.
	 * @return void
	 * @throws Exception On any failed gate.
	 */
	public function authorize( string $action, array $post ): void {
		$expected_phrase = $this->phrase( $action );
		$nonce_action    = $this->nonce_action( $action );

		$user_id = $this->env->current_user_id();
		if ( ! $this->env->is_user_logged_in() || $user_id <= 0 ) {
			throw new Exception( 'AUTH_REQUIRED', 'نشست مدیر لازم است.' );
		}
		if ( ! $this->env->user_can( $user_id, 'manage_options' ) || ! $this->env->user_can( $user_id, 'install_plugins' ) ) {
			throw new Exception( 'CAPABILITY', 'این حساب اختیار لازم (manage_options و install_plugins) را ندارد.' );
		}

		$nonce = isset( $post['cpdd_nonce'] ) && is_string( $post['cpdd_nonce'] ) ? $post['cpdd_nonce'] : '';
		if ( '' === $nonce || ! $this->env->nonce_verify( $nonce, $nonce_action ) ) {
			throw new Exception( 'NONCE', 'توکن امنیتی فرم نامعتبر یا منقضی است؛ صفحه را دوباره بارگذاری کنید.' );
		}

		$phrase = isset( $post['cpdd_confirm'] ) && is_string( $post['cpdd_confirm'] ) ? trim( $post['cpdd_confirm'] ) : '';
		if ( ! hash_equals( $expected_phrase, $phrase ) ) {
			throw new Exception( 'CONFIRMATION', 'عبارت تأیید دقیقاً مطابق متن صفحه وارد نشده است.' );
		}

		$fingerprint = isset( $post['cpdd_site'] ) && is_string( $post['cpdd_site'] ) ? $post['cpdd_site'] : '';
		if ( ! hash_equals( $this->manifest->site_fingerprint(), $fingerprint ) ) {
			throw new Exception( 'SITE_IDENTITY', 'شناسهٔ سایت ارسالی با این سایت هم‌خوان نیست؛ عملیات متوقف شد.' );
		}

		if ( ! $this->env->is_ssl() ) {
			$host  = (string) wp_parse_url( $this->env->home_url(), PHP_URL_HOST );
			$local = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
			if ( ! $local ) {
				throw new Exception( 'HTTPS_REQUIRED', 'این عملیات فقط روی HTTPS (یا localhost) مجاز است.' );
			}
		}
	}
}
