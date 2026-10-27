<?php
/**
 * Browser-only OTP display for the scoped demo.
 *
 * It rebinds the code hash of one live challenge that the patient already
 * requested through the real UI. It never sends SMS, creates a token, mints a
 * session, changes expiry/attempt counters, or bypasses verification.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Logical grouping is clearer than column alignment in these ownership maps.

/**
 * Scoped-mode OTP helper.
 */
class ScopedOtp {

	/** @var Env */
	private Env $env;

	/** @var Platform */
	private Platform $platform;

	/** @var ScopedManifest */
	private ScopedManifest $manifest;

	public function __construct( Env $env, Platform $platform, ScopedManifest $manifest ) {
		$this->env      = $env;
		$this->platform = $platform;
		$this->manifest = $manifest;
		$this->platform->set_env( $env );
	}

	/**
	 * @param array<string,mixed> $fixture Verified scoped manifest.
	 * @return array{code:string,token_id:int,expires_at:string}
	 */
	public function issue( array $fixture ): array {
		return $this->env->with_lock(
			'scoped_issue_otp',
			function () use ( $fixture ): array {
				return $this->do_issue( $fixture );
			}
		);
	}

	/**
	 * @param array<string,mixed> $fixture Verified scoped manifest.
	 * @return array{code:string,token_id:int,expires_at:string}
	 */
	private function do_issue( array $fixture ): array {
		if ( ! $this->manifest->verify( $fixture ) || 'active' !== (string) ( $fixture['status'] ?? '' ) ) {
			throw new Exception( 'OTP_SCOPE', 'دموی محدودشدهای فعال نیست.' );
		}
		$clinic_id = (int) ( $fixture['ids']['clinic_id'] ?? 0 );
		$mobile    = (string) ( $fixture['patient_mobile'] ?? '' );
		if ( 1 !== preg_match( '/^09\d{9}$/D', $mobile ) || $clinic_id <= 1 ) {
			throw new Exception( 'OTP_SCOPE', 'شماره یا درمانگاه ساختگی نامعتبر است.' );
		}
		if ( $this->platform->otp_echo_defined() ) {
			throw new Exception( 'OTP_SCOPE', 'ثابت CPMS_DEV_OTP_ECHO تعریف شده است.' );
		}
		if ( 'log' !== (string) $this->platform->get_setting( $clinic_id, 'sms.provider' ) ) {
			throw new Exception( 'OTP_SCOPE', 'ارائهدهنده پیامک درمانگاه ساختگی log نیست.' );
		}
		if ( $this->platform->patients_with_mobile_outside_clinic( $clinic_id, $mobile ) > 0 ) {
			throw new Exception( 'OTP_SCOPE', 'همین شماره ساختگی بیرون از درمانگاه دمو وجود دارد.' );
		}
		$challenge = $this->platform->latest_live_challenge( $clinic_id, $mobile, 'login' );
		if ( null === $challenge || null !== ( $challenge['consumed_at'] ?? null ) || (int) ( $challenge['attempts'] ?? 0 ) > 0 ) {
			throw new Exception( 'OTP_CHALLENGE', 'چالش زنده و دستنخوردهای یافت نشد.' );
		}
		if ( strtotime( (string) $challenge['expires_at'] . ' UTC' ) <= $this->env->now() ) {
			throw new Exception( 'OTP_CHALLENGE', 'چالش منقضی شده است.' );
		}
		$code = $this->platform->generate_otp_code();
		if ( ! $this->platform->rebind_challenge_code( (int) $challenge['id'], $code, $this->platform->otp_pepper() ) ) {
			throw new Exception( 'OTP_CHALLENGE', 'چالش همزمان تغییر کرد.' );
		}
		$this->platform->audit(
			$this->manifest->otp_audit_action(),
			(int) $fixture['admin_id'],
			$clinic_id,
			array( 'fixture_id' => (string) $fixture['fixture_id'], 'token_id' => (int) $challenge['id'], 'code_shown' => true )
		);
		return array(
			'code'       => $code,
			'token_id'   => (int) $challenge['id'],
			'expires_at' => (string) $challenge['expires_at'],
		);
	}
}
