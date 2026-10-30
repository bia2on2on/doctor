<?php
/**
 * Scoped demo — explicit recovery of OTP residue left by a foreign mobile.
 *
 * The situation (Mission B / Task 4): an owner, or a visitor, requests a
 * booking code on the demo clinic's public page with a mobile that is NOT the
 * synthetic patient mobile. The production OTP flow then writes, inside the
 * demo clinic:
 *
 *   * a `cpms_otp_tokens` challenge for that mobile (purged by the production
 *     `otp.cleanup` job once it is old enough — not immediately),
 *   * a `cpms_sms_messages` row (event `otp`, provider `log`, context
 *     `otp_token`) — never purged by anything.
 *
 * Both rows live in the demo clinic (clinic_id) and are younger than the sealed
 * baseline, so the teardown collects them; but the fail-closed attribution
 * (`@mobile` = the fixture's synthetic mobile) cannot attribute them to a
 * fixture artifact, so it refuses with SCOPED_FOREIGN_TENANT_ROW and the demo
 * stays TEARDOWN_BLOCKED — forever, in the case of the SMS log row. That refusal
 * is correct: the teardown must never guess. What was missing is an explicit,
 * separately-confirmed, narrowly-scoped recovery for exactly this residue.
 *
 * What this class removes — and ONLY this — after proving every condition:
 *
 *   1. `cpms_otp_tokens` rows of the demo clinic, newer than the sealed
 *      baseline, whose mobile is not the synthetic mobile, and which are
 *      already EXPIRED or CONSUMED (a live challenge is never touched);
 *   2. `cpms_sms_messages` rows of the demo clinic, newer than the baseline,
 *      with event `otp`, provider `log` (the demo clinic's provider — no real
 *      SMS was ever sent), context `otp_token`, a terminal status (nothing in
 *      flight), whose recipient is not the synthetic mobile and whose token is
 *      either a row from (1) or already purged by the production cleanup job.
 *
 * Refusals (nothing is deleted when any of them holds):
 *
 *   * RESIDUE_MOBILE_KNOWN — the foreign mobile appears in ANY patient,
 *     identity, slot hold or WordPress account on this site. Then the rows may
 *     belong to a real person's record and a human must decide; the Persian
 *     limitation text on the page says so.
 *   * RESIDUE_LIVE_CHALLENGE — a challenge for the mobile is still live.
 *   * RESIDUE_OUT_OF_SCOPE — a candidate row fails any of the proofs above.
 *   * AUDIT_CHAIN — the append-only audit chain does not verify before or after.
 *
 * Never touched: patients, identities, visits, appointments, invoices,
 * payments, clinical rows, rate-limit counters (production policy keeps them),
 * and audit rows (the recovery APPENDS one masked audit row through the
 * production audit service, which extends the hash chain; it never edits one).
 *
 * @package CpmsDemo
 */

declare(strict_types=1);

namespace CpmsDemo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Explicit OTP-residue recovery for the scoped demo.
 */
final class ScopedResidue {

	public const AUDIT_ACTION = 'CPSD_DEMO_RESIDUE_RECOVERED';

	/** Terminal SMS states (nothing in flight is ever removed). */
	private const SMS_TERMINAL = array( 'SENT', 'DELIVERED', 'FAILED' );

	/** Where a foreign mobile would prove that a real record exists. */
	private const MOBILE_HOLDERS = array(
		'cpms_patients'           => array( 'mobile' ),
		'cpms_patient_identities' => array( 'normalized_mobile' ),
		'cpms_slot_holds'         => array( 'holder_mobile' ),
	);

	private Env $env;
	private Platform $platform;
	private ScopedManifest $manifest;
	private ScopedAuditGuard $audit_guard;

	public function __construct( Env $env, Platform $platform, ScopedManifest $manifest, ScopedAuditGuard $audit_guard ) {
		$this->env         = $env;
		$this->platform    = $platform;
		$this->manifest    = $manifest;
		$this->audit_guard = $audit_guard;
	}

	/**
	 * Read-only plan: what would be removed, and why anything is refused.
	 *
	 * @param array<string,mixed> $fixture Verified scoped manifest.
	 * @return array<string,mixed>
	 */
	public function preview( array $fixture ): array {
		return $this->plan( $fixture );
	}

	/**
	 * Remove the proven residue (same plan as preview, under the demo lock).
	 *
	 * @param array<string,mixed> $fixture Verified scoped manifest.
	 * @param int                 $actor_id Acting Administrator.
	 * @return array<string,mixed>
	 */
	public function execute( array $fixture, int $actor_id ): array {
		return $this->env->with_lock(
			'scoped_residue',
			function () use ( $fixture, $actor_id ): array {
				return $this->do_execute( $fixture, $actor_id );
			}
		);
	}

	/**
	 * @param array<string,mixed> $fixture Verified scoped manifest.
	 * @param int                 $actor_id Acting Administrator.
	 * @return array<string,mixed>
	 */
	private function do_execute( array $fixture, int $actor_id ): array {
		$plan = $this->plan( $fixture );
		if ( ! $plan['ok'] ) {
			$first = (string) ( $plan['problems'][0] ?? 'RESIDUE_OUT_OF_SCOPE' );
			$code  = (string) ( explode( ':', $first, 2 )[0] );
			throw new Exception( $code, $this->persian_refusal( $code ) . ' (' . $first . ')' );
		}
		if ( 0 === (int) $plan['total'] ) {
			throw new Exception( 'RESIDUE_NONE', 'باقی‌ماندهٔ OTP قابل بازیابی وجود ندارد؛ هیچ ردیفی حذف نشد.' );
		}
		$before = $this->audit_guard->verify_chain();
		if ( ! $before['ok'] ) {
			throw new Exception( 'AUDIT_CHAIN', 'زنجیرهٔ حسابرسی پیش از بازیابی تأیید نشد؛ هیچ ردیفی حذف نشد. (' . (string) $before['error'] . ')' );
		}
		$deleted = array();
		// SMS rows first: they reference the token (context_id); a failure after
		// the token delete would otherwise leave an orphan SMS row behind.
		$sms_ids = $plan['sms_message_ids'];
		if ( array() !== $sms_ids ) {
			$deleted['cpms_sms_messages'] = $this->delete_ids( 'cpms_sms_messages', $sms_ids );
		}
		$token_ids = $plan['otp_token_ids'];
		if ( array() !== $token_ids ) {
			$deleted['cpms_otp_tokens'] = $this->delete_ids( 'cpms_otp_tokens', $token_ids );
		}
		$this->platform->audit(
			self::AUDIT_ACTION,
			$actor_id,
			(int) $plan['clinic_id'],
			array(
				'fixture_id'      => (string) ( $fixture['fixture_id'] ?? '' ),
				'otp_token_ids'   => $token_ids,
				'sms_message_ids' => $sms_ids,
				'mobiles_masked'  => $plan['mobiles_masked'],
				'reason'          => 'foreign-mobile OTP residue inside the demo clinic; expired/consumed challenges and their log-provider SMS echoes only',
			)
		);
		$after = $this->audit_guard->verify_chain();
		if ( ! $after['ok'] ) {
			throw new Exception( 'AUDIT_CHAIN', 'زنجیرهٔ حسابرسی پس از بازیابی تأیید نشد. (' . (string) $after['error'] . ')' );
		}

		return array(
			'ok'             => true,
			'clinic_id'      => (int) $plan['clinic_id'],
			'deleted'        => $deleted,
			'mobiles_masked' => $plan['mobiles_masked'],
			'audit_before'   => (string) ( $before['tail'] ?? '' ),
			'audit_after'    => (string) ( $after['tail'] ?? '' ),
			'audit_checked'  => (int) ( $after['checked'] ?? 0 ),
		);
	}

	/**
	 * Build the plan. Pure read; shared by preview and execute.
	 *
	 * @param array<string,mixed> $fixture Verified scoped manifest.
	 * @return array<string,mixed>
	 */
	private function plan( array $fixture ): array {
		$problems = array();
		$out      = array(
			'ok'              => false,
			'problems'        => array(),
			'clinic_id'       => 0,
			'otp_token_ids'   => array(),
			'sms_message_ids' => array(),
			'mobiles_masked'  => array(),
			'refused'         => array(),
			'total'           => 0,
		);
		if ( ! $this->manifest->verify( $fixture ) ) {
			$out['problems'] = array( 'MANIFEST_TAMPERED' );

			return $out;
		}
		$status = (string) ( $fixture['status'] ?? '' );
		if ( ! in_array( $status, array( 'active', 'teardown_failed' ), true ) ) {
			$out['problems'] = array( 'RESIDUE_SCOPE: status=' . $status );

			return $out;
		}
		$clinic_id = (int) ( $fixture['ids']['clinic_id'] ?? 0 );
		$synthetic = $this->normalize_mobile( (string) ( $fixture['patient_mobile'] ?? '' ) );
		if ( $clinic_id <= 1 || '' === $synthetic ) {
			$out['problems'] = array( 'RESIDUE_SCOPE: clinic or synthetic mobile missing' );

			return $out;
		}
		$out['clinic_id'] = $clinic_id;
		$baseline         = $this->resolve_baseline( $fixture );
		if ( array() === $baseline ) {
			$out['problems'] = array( 'BASELINE_MISSING' );

			return $out;
		}
		$token_floor = (int) ( $baseline['cpms']['cpms_otp_tokens']['max_id'] ?? -1 );
		$sms_floor   = (int) ( $baseline['cpms']['cpms_sms_messages']['max_id'] ?? -1 );
		if ( $token_floor < 0 || $sms_floor < 0 ) {
			// A table that was not in the sealed baseline cannot be bounded.
			$out['problems'] = array( 'BASELINE_MISSING: otp/sms tables not sealed' );

			return $out;
		}
		$now = $this->env->now();

		// ---- 1. Foreign-mobile challenges of the demo clinic ------------------
		$tokens       = array();
		$live_mobiles = array();
		if ( $this->platform->table_exists( 'cpms_otp_tokens' ) ) {
			$rows = $this->platform->fetch_all(
				'SELECT id, mobile, purpose, expires_at, consumed_at, attempts, created_at FROM `' . $this->platform->table( 'cpms_otp_tokens' ) . '` WHERE clinic_id = %d AND id > %d ORDER BY id ASC',
				array( $clinic_id, $token_floor )
			);
			foreach ( $rows as $row ) {
				$id     = (int) ( $row['id'] ?? 0 );
				$mobile = $this->normalize_mobile( (string) ( $row['mobile'] ?? '' ) );
				if ( $id <= $token_floor || '' === $mobile || $mobile === $synthetic ) {
					continue; // the teardown itself owns the synthetic rows
				}
				$expired  = strtotime( (string) ( $row['expires_at'] ?? '' ) . ' UTC' ) <= $now;
				$consumed = null !== ( $row['consumed_at'] ?? null ) && '' !== (string) $row['consumed_at'];
				if ( ! $expired && ! $consumed ) {
					$live_mobiles[ $mobile ] = true;
					continue;
				}
				$tokens[ $id ] = $mobile;
			}
		}

		// ---- 2. Their log-provider SMS echoes ----------------------------------
		$sms          = array();
		$sms_mobiles  = array();
		$out_of_scope = array();
		if ( $this->platform->table_exists( 'cpms_sms_messages' ) ) {
			$rows = $this->platform->fetch_all(
				'SELECT id, recipient, event, provider, status, context_type, context_id, created_at FROM `' . $this->platform->table( 'cpms_sms_messages' ) . '` WHERE clinic_id = %d AND id > %d ORDER BY id ASC',
				array( $clinic_id, $sms_floor )
			);
			foreach ( $rows as $row ) {
				$id     = (int) ( $row['id'] ?? 0 );
				$mobile = $this->normalize_mobile( (string) ( $row['recipient'] ?? '' ) );
				if ( $id <= $sms_floor || '' === $mobile || $mobile === $synthetic ) {
					continue;
				}
				$is_otp_echo = 'otp' === strtolower( (string) ( $row['event'] ?? '' ) )
					&& 'log' === strtolower( (string) ( $row['provider'] ?? '' ) )
					&& 'otp_token' === (string) ( $row['context_type'] ?? '' )
					&& in_array( strtoupper( (string) ( $row['status'] ?? '' ) ), self::SMS_TERMINAL, true );
				if ( ! $is_otp_echo ) {
					$out_of_scope[] = 'cpms_sms_messages:' . $id;
					continue;
				}
				$context_id  = (int) ( $row['context_id'] ?? 0 );
				$token_known = isset( $tokens[ $context_id ] );
				$token_live  = ! $token_known && $context_id > 0 && $this->token_exists( $context_id );
				if ( $token_live ) {
					// Its challenge is still live (or synthetic) — not residue yet.
					$live_mobiles[ $mobile ] = true;
					continue;
				}
				$sms[ $id ]             = $mobile;
				$sms_mobiles[ $mobile ] = true;
			}
		}

		// ---- 3. The mobile must be unknown to the whole site -----------------
		$mobiles = array_unique( array_merge( array_values( $tokens ), array_values( $sms ) ) );
		$known   = array();
		foreach ( $mobiles as $mobile ) {
			$holder = $this->mobile_holder( $mobile );
			if ( '' !== $holder ) {
				$known[ $mobile ] = $holder;
			}
		}
		foreach ( $known as $mobile => $holder ) {
			$problems[] = 'RESIDUE_MOBILE_KNOWN: ' . $this->mask( $mobile ) . ' -> ' . $holder;
		}
		foreach ( array_keys( $live_mobiles ) as $mobile ) {
			if ( isset( $known[ $mobile ] ) ) {
				continue;
			}
			$problems[] = 'RESIDUE_LIVE_CHALLENGE: ' . $this->mask( $mobile );
		}
		if ( array() !== $out_of_scope ) {
			$problems[] = 'RESIDUE_OUT_OF_SCOPE: ' . implode( ',', array_slice( $out_of_scope, 0, 5 ) );
		}

		$masked = array();
		foreach ( $mobiles as $mobile ) {
			$masked[] = $this->mask( $mobile );
		}
		$out['otp_token_ids']   = array_map( 'intval', array_keys( $tokens ) );
		$out['sms_message_ids'] = array_map( 'intval', array_keys( $sms ) );
		$out['mobiles_masked']  = array_values( array_unique( $masked ) );
		$out['refused']         = $known;
		$out['total']           = count( $tokens ) + count( $sms );
		$out['problems']        = $problems;
		$out['ok']              = array() === $problems;

		return $out;
	}

	/**
	 * @param string $mobile Normalized mobile.
	 * @return string "" when unknown, else "<table>.<column>" or "wp_users".
	 */
	private function mobile_holder( string $mobile ): string {
		foreach ( self::MOBILE_HOLDERS as $short => $columns ) {
			if ( ! $this->platform->table_exists( $short ) ) {
				continue;
			}
			$table = $this->platform->table( $short );
			$cols  = $this->platform->columns( $table );
			foreach ( $columns as $column ) {
				if ( ! in_array( $column, $cols, true ) ) {
					continue;
				}
				foreach ( $this->mobile_variants( $mobile ) as $variant ) {
					$rows = $this->platform->fetch_all( 'SELECT id FROM `' . $table . '` WHERE `' . $column . '` = %s LIMIT 1', array( $variant ) );
					if ( array() !== $rows ) {
						return $short . '.' . $column;
					}
				}
			}
		}
		if ( $this->env->email_exists( $mobile . '@otp.cpms.local' ) || $this->env->username_exists( $mobile ) ) {
			return 'wp_users';
		}

		return '';
	}

	/**
	 * Storage variants of one mobile (national, 98-prefixed, +98-prefixed).
	 *
	 * @param string $mobile Normalized `09…` mobile.
	 * @return list<string>
	 */
	private function mobile_variants( string $mobile ): array {
		$variants = array( $mobile );
		if ( 1 === preg_match( '/^0(9\d{9})$/D', $mobile, $m ) ) {
			$variants[] = '98' . $m[1];
			$variants[] = '+98' . $m[1];
			$variants[] = $m[1];
		}

		return array_values( array_unique( $variants ) );
	}

	/**
	 * @param int $token_id Token id.
	 * @return bool Whether the challenge row still exists.
	 */
	private function token_exists( int $token_id ): bool {
		if ( ! $this->platform->table_exists( 'cpms_otp_tokens' ) ) {
			return false;
		}
		$rows = $this->platform->fetch_all( 'SELECT id FROM `' . $this->platform->table( 'cpms_otp_tokens' ) . '` WHERE id = %d', array( $token_id ) );

		return array() !== $rows;
	}

	/**
	 * @param string   $short Short table name.
	 * @param list<int> $ids Ids to delete.
	 * @return int Rows deleted.
	 */
	private function delete_ids( string $short, array $ids ): int {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( array() === $ids || ! $this->platform->table_exists( $short ) ) {
			return 0;
		}
		$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		return $this->platform->delete_where( $this->platform->table( $short ), 'id IN (' . $ph . ')', $ids );
	}

	/**
	 * @param array<string,mixed> $fixture Manifest.
	 * @return array<string,mixed> Sealed baseline (manifest or pending record).
	 */
	private function resolve_baseline( array $fixture ): array {
		$baseline = (array) ( $fixture['baseline'] ?? array() );
		if ( array() !== $baseline ) {
			return $baseline;
		}
		$pending = $this->manifest->load_pending_baseline( (string) ( $fixture['fixture_id'] ?? '' ) );

		return is_array( $pending ) ? (array) ( $pending['baseline'] ?? array() ) : array();
	}

	/**
	 * Same normalisation as the teardown's `@mobile` rule.
	 *
	 * @param string $mobile Raw mobile.
	 * @return string Digits, national `09…` form when recognisable.
	 */
	private function normalize_mobile( string $mobile ): string {
		$digits = preg_replace( '/\D+/', '', $mobile );
		$digits = is_string( $digits ) ? $digits : '';
		$digits = (string) preg_replace( '/^(?:0098|98)/', '0', $digits );
		if ( 10 === strlen( $digits ) && '9' === substr( $digits, 0, 1 ) ) {
			$digits = '0' . $digits;
		}

		return $digits;
	}

	/**
	 * @param string $mobile Normalized mobile.
	 * @return string Masked form kept in audit/UI (never the full number).
	 */
	private function mask( string $mobile ): string {
		if ( strlen( $mobile ) < 7 ) {
			return str_repeat( '*', strlen( $mobile ) );
		}

		return substr( $mobile, 0, 4 ) . str_repeat( '*', strlen( $mobile ) - 7 ) . substr( $mobile, -3 );
	}

	/**
	 * @param string $code Refusal code.
	 * @return string Persian operator-facing explanation.
	 */
	private function persian_refusal( string $code ): string {
		switch ( $code ) {
			case 'RESIDUE_MOBILE_KNOWN':
				return 'این شماره در پروندهٔ بیمار، هویت، نگه‌داشت نوبت یا حساب کاربری همین سایت وجود دارد؛ ممکن است به فرد واقعی تعلق داشته باشد و ابزار دمو آن را حذف نمی‌کند. این ردیف‌ها را فقط یک انسان پس از بررسی مالکیت تعیین تکلیف کند.';
			case 'RESIDUE_LIVE_CHALLENGE':
				return 'برای این شماره هنوز یک چالش OTP زنده وجود دارد؛ تا انقضای آن (چند دقیقه) هیچ ردیفی حذف نمی‌شود.';
			case 'RESIDUE_OUT_OF_SCOPE':
				return 'ردیفی در جدول پیامک درمانگاه دمو یافت شد که پژواک OTP با ارائه‌دهندهٔ log نیست؛ ابزار دمو آن را حذف نمی‌کند.';
			case 'BASELINE_MISSING':
				return 'مبنای مهرشدهٔ دمو در دسترس نیست؛ بدون مرز اثبات‌شده هیچ ردیفی حذف نمی‌شود.';
			case 'MANIFEST_TAMPERED':
				return 'مانیفست دمو معتبر نیست.';
			default:
				return 'بازیابی باقی‌ماندهٔ OTP انجام نشد؛ هیچ ردیفی حذف نشد.';
		}
	}
}
