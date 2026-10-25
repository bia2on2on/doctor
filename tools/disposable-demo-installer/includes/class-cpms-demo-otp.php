<?php
/**
 * Browser-only demo OTP delivery.
 *
 * WHY THIS IS NOT AN AUTHENTICATION BYPASS
 * ----------------------------------------
 * The demo site must let the Owner test the *real* patient OTP flow without a
 * real SMS. The only production-safe way to do that without editing wp-config or
 * shipping a master code is to hand the operator the one-time code of a
 * challenge the patient already requested through the real UI.
 *
 * This class therefore rebinds the `code_hash` of exactly one already-live,
 * zero-attempt, unconsumed challenge — the same fixture method the repository's
 * audit-approved WP-CLI tool uses — and shows the code once in a no-store
 * response. It does NOT: create tokens, extend expiry, reset attempts, clear
 * locks, lift cooldowns, skip verification, mint sessions, echo codes in logs,
 * or touch any other mobile/clinic. The patient still submits the code to the
 * normal verify route, which enforces TTL, attempts, lockout, one-time
 * consumption and the patient-capability gate.
 *
 * Scope is triple-checked: the manifest must verify, the clinic must be the
 * fixture clinic with the `log` SMS provider, the mobile must be this fixture's
 * synthetic mobile, and no patient with that mobile may exist outside the
 * fixture clinic.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

defined( 'ABSPATH' ) || exit;

/**
 * Demo OTP display.
 */
class Otp {

	/**
	 * @var Env
	 */
	private Env $env;

	/**
	 * @var Platform
	 */
	private Platform $platform;

	/**
	 * @param Env      $env Environment.
	 * @param Platform $platform CPMS adapter.
	 */
	public function __construct( Env $env, Platform $platform ) {
		$this->env      = $env;
		$this->platform = $platform;
	}

	/**
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @return array{code:string,token_id:int,expires_at:string}
	 * @throws Exception When no safe demo challenge exists.
	 */
	public function issue( array $manifest ): array {
		$clinic_id = (int) $manifest['ids']['clinic_id'];
		$mobile    = (string) $manifest['patient_mobile'];
		$fixture   = (string) $manifest['fixture_id'];

		if ( preg_match( '/^09\d{9}$/', $mobile ) !== 1 ) {
			throw new Exception( 'OTP_SCOPE', 'شمارهٔ ساختگی این دمو معتبر نیست.' );
		}
		if ( 'active' !== (string) $manifest['status'] ) {
			throw new Exception( 'OTP_SCOPE', 'دمو در وضعیت فعال نیست.' );
		}
		if ( $this->platform->otp_echo_defined() ) {
			throw new Exception( 'OTP_SCOPE', 'ثابت CPMS_DEV_OTP_ECHO تعریف شده است؛ این محیط امن نیست.' );
		}
		if ( (string) $this->platform->get_setting( $clinic_id, 'sms.provider' ) !== 'log' ) {
			throw new Exception( 'OTP_SCOPE', 'ارائه‌دهندهٔ پیامک درمانگاه ساختگی «log» نیست؛ برای جلوگیری از ارسال واقعی متوقف شد.' );
		}
		if ( $this->platform->patients_with_mobile_outside_clinic( $clinic_id, $mobile ) > 0 ) {
			throw new Exception( 'OTP_SCOPE', 'همین شمارهٔ ساختگی بیرون از درمانگاه دمو وجود دارد؛ متوقف شد.' );
		}

		$challenge = $this->platform->latest_live_challenge( $clinic_id, $mobile, 'login' );
		if ( null === $challenge ) {
			throw new Exception( 'OTP_CHALLENGE', 'چالش فعالی وجود ندارد. ابتدا در مرورگر بیمار، از صفحهٔ رزرو واقعی «درخواست کد» را بزنید.' );
		}
		if ( null !== ( $challenge['consumed_at'] ?? null ) ) {
			throw new Exception( 'OTP_CHALLENGE', 'این کد قبلاً مصرف شده است؛ کد تازه درخواست کنید.' );
		}
		if ( (int) ( $challenge['attempts'] ?? 0 ) > 0 ) {
			throw new Exception( 'OTP_CHALLENGE', 'برای این چالش تلاش ناموفق ثبت شده است؛ کد تازه درخواست کنید.' );
		}
		if ( strtotime( (string) $challenge['expires_at'] . ' UTC' ) <= $this->env->now() ) {
			throw new Exception( 'OTP_CHALLENGE', 'چالش منقضی شده است؛ کد تازه درخواست کنید.' );
		}

		$pepper = $this->platform->otp_pepper();
		$code   = $this->platform->generate_otp_code();
		$ok     = $this->platform->rebind_challenge_code( (int) $challenge['id'], $code, $pepper );
		unset( $pepper );
		if ( ! $ok ) {
			throw new Exception( 'OTP_CHALLENGE', 'چالش هم‌زمان تغییر کرد؛ کد تازه درخواست کنید.' );
		}

		try {
			$this->platform->audit(
				'CPDD_DEMO_OTP_REBOUND',
				(int) $manifest['admin_id'],
				$clinic_id,
				array(
					'fixture_id' => $fixture,
					'token_id'   => (int) $challenge['id'],
					'code_shown' => true,
				)
			);
		} catch ( \Throwable $ignored ) {
			unset( $ignored );
		}

		return array(
			'code'       => $code,
			'token_id'   => (int) $challenge['id'],
			'expires_at' => (string) $challenge['expires_at'],
		);
	}
}
