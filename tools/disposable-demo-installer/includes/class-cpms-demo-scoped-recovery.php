<?php
/**
 * Read-only recovery assessment for a scoped demo that already exists.
 *
 * WHY THIS EXISTS
 * ---------------
 * Running the provisioner a second time is refused (`ALREADY_PROVISIONED`) and
 * that refusal is correct: creating a second fixture on one site would double
 * every synthetic row. But the Owner then needs a safe answer to one of two
 * questions, and until now the package answered neither:
 *
 *   1. "an earlier run completed - can I just look at it?"  -> yes, if the
 *      sealed evidence still verifies; then the eight portals are the demo.
 *   2. "an earlier run was interrupted - can it be finished?" -> only when the
 *      interruption provably happened AFTER the last synthetic row was written
 *      (the seal stage), because every earlier stage creates rows that the
 *      production services cannot idempotently re-create.
 *
 * Ownership is NEVER inferred from a name, an e-mail domain, a date or a
 * matching slug. Every check below reads either the HMAC-sealed manifest (site
 * fingerprint + fixture id), the sealed baseline, or the sealed ownership
 * ledger, and compares the exact ids it names with the live rows.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Logical grouping is clearer than column alignment in these evidence maps.

defined( 'ABSPATH' ) || exit;

/**
 * Answers "what is the state of the existing demo, and is finishing it safe?".
 */
class ScopedRecovery {

	/** A verified, sealed, active fixture. Nothing to do but look at it. */
	public const VERDICT_ACTIVE = 'active';

	/** Every synthetic row exists and only the seal is missing; finishing is safe. */
	public const VERDICT_RESUMABLE = 'resumable';

	/** Ownership or completeness cannot be proven: nothing may be completed. */
	public const VERDICT_BLOCKED = 'blocked';

	/**
	 * Stages after which every synthetic row has already been written.
	 *
	 * `seal` is set by Provisioner before `after_provision()` runs, and the seal
	 * is the only work left at that point: ownership ledger, exact scope, audit
	 * chain state and the `active` status. Re-running exactly that is idempotent.
	 *
	 * Every other stage (`users`, `organization`, `membership`, `clinical`,
	 * `finance`, `messaging`, `pricing`, `booking-page`, …) creates rows through
	 * production services that are not idempotent, so an interrupted run there is
	 * NOT resumed automatically: the partial fixture is cleaned up through the
	 * existing authorized teardown instead. Guessing would be worse than a clear
	 * refusal.
	 */
	public const RESUMABLE_STAGES = array( 'seal' );

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
	}

	/**
	 * Assess a verified manifest.
	 *
	 * The caller has already proved the manifest's HMAC and site fingerprint by
	 * calling `ScopedManifest::load()`, which throws instead of returning a
	 * tampered payload. This method therefore never has to trust a raw option.
	 *
	 * @param array<string,mixed> $manifest Verified manifest payload.
	 * @return array{verdict:string,code:string,reason:string,status:string,sealed:bool,checks:list<array{id:string,label:string,status:string,detail:string}>}
	 */
	public function assess( array $manifest ): array {
		$checks = array();

		$status = (string) ( $manifest['status'] ?? '' );
		$add    = static function ( string $id, string $label, bool $ok, string $detail ) use ( &$checks ): bool {
			$checks[] = array(
				'id'     => $id,
				'label'  => $label,
				'status' => $ok ? 'pass' : 'fail',
				'detail' => $detail,
			);

			return $ok;
		};

		$fixture  = (string) ( $manifest['fixture_id'] ?? '' );
		$ids      = (array) ( $manifest['ids'] ?? array() );
		$slugs    = (array) ( $manifest['slugs'] ?? array() );
		$links    = (array) ( $manifest['links'] ?? array() );
		$users    = (array) ( $ids['user_ids'] ?? array() );
		$org_id   = (int) ( $ids['organization_id'] ?? 0 );
		$clinic   = (int) ( $ids['clinic_id'] ?? 0 );

		$known_status = in_array( $status, array( 'active', 'provisioning', 'failed' ), true );
		$add(
			'status',
			'وضعیت ثبت‌شدهٔ دمو',
			$known_status,
			$known_status ? 'وضعیت: ' . $status : 'وضعیت ثبت‌شده ناشناخته است: ' . ( '' === $status ? '(خالی)' : $status )
		);

		// --- 1. The sealed identity has the exact shape this package writes ------
		$prefix   = $this->manifest->fixture_prefix();
		$expected = array(
			'organization'       => $prefix . $fixture,
			'clinic'             => $prefix . $fixture . '-clinic',
			'location_primary'   => $prefix . $fixture . '-main',
			'location_secondary' => $prefix . $fixture . '-branch',
			'page'               => $prefix . $fixture . '-booking',
		);
		$slugs_ok = true;
		foreach ( $expected as $key => $value ) {
			if ( (string) ( $slugs[ $key ] ?? '' ) !== $value ) {
				$slugs_ok = false;
			}
		}
		$shape_ok       = 1 === preg_match( '/^[a-f0-9]{32}$/D', $fixture )
			&& 'scoped' === (string) ( $manifest['mode'] ?? '' )
			&& CPMS_DEMO_SCHEMA_VERSION === (string) ( $manifest['schema_version'] ?? '' )
			&& $slugs_ok;
		$shape_problems = array();
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/D', $fixture ) ) {
			$shape_problems[] = 'شناسهٔ دمو با قالب این بسته هم‌خوان نیست';
		}
		if ( 'scoped' !== (string) ( $manifest['mode'] ?? '' ) ) {
			$shape_problems[] = 'حالت ثبت‌شده scoped نیست';
		}
		if ( CPMS_DEMO_SCHEMA_VERSION !== (string) ( $manifest['schema_version'] ?? '' ) ) {
			$shape_problems[] = 'نسخهٔ اسکیمای ثبت‌شده با اسکیمای این بسته هم‌خوان نیست';
		}
		if ( ! $slugs_ok ) {
			$shape_problems[] = 'slug‌های ثبت‌شده با شناسهٔ دمو هم‌خوان نیستند';
		}
		$add( 'fixture', 'شناسه و slug‌های مهرشده', $shape_ok, $shape_ok ? 'شناسه و همهٔ slug‌ها دقیقاً با الگوی این بسته هم‌خوان‌اند.' : implode( ' · ', $shape_problems ) );

		// --- 2. The sealed tenant still exists, with the sealed ids --------------
		$org_row    = $org_id > 0 ? $this->fetch_slug_row( 'cpms_organizations', $org_id ) : null;
		$clinic_row = $clinic > 0 ? $this->fetch_slug_row( 'cpms_clinics', $clinic ) : null;
		$tenant_ok  = is_array( $org_row )
			&& (string) ( $org_row['slug'] ?? '' ) === $expected['organization']
			&& is_array( $clinic_row )
			&& (string) ( $clinic_row['slug'] ?? '' ) === $expected['clinic']
			&& (int) ( $clinic_row['organization_id'] ?? $org_id ) === $org_id;
		$tenant_problems = array();
		if ( ! is_array( $org_row ) || (string) ( $org_row['slug'] ?? '' ) !== $expected['organization'] ) {
			$tenant_problems[] = 'سازمان ساختگی با شناسهٔ مهرشده پیدا نشد';
		}
		if ( ! is_array( $clinic_row ) || (string) ( $clinic_row['slug'] ?? '' ) !== $expected['clinic'] ) {
			$tenant_problems[] = 'درمانگاه ساختگی با شناسهٔ مهرشده پیدا نشد';
		} elseif ( (int) ( $clinic_row['organization_id'] ?? $org_id ) !== $org_id ) {
			$tenant_problems[] = 'درمانگاه ساختگی زیر سازمان مهرشده نیست';
		}
		if ( array() === $tenant_problems ) {
			$missing_locations = array();
			foreach ( (array) ( $ids['location_ids'] ?? array() ) as $location_id ) {
				$location = (int) $location_id > 0 ? $this->fetch_slug_row( 'cpms_locations', (int) $location_id ) : null;
				if ( ! is_array( $location ) || (int) ( $location['clinic_id'] ?? 0 ) !== $clinic ) {
					$missing_locations[] = (int) $location_id;
				}
			}
			if ( array() !== $missing_locations ) {
				$tenant_problems[] = 'شعبه‌های ساختگی پیدا نشدند: ' . implode( ', ', array_map( 'strval', $missing_locations ) );
			}
		}
		$tenant_ok = array() === $tenant_problems;
		$add( 'tenant', 'سازمان/درمانگاه/شعبه‌های ساختگی', $tenant_ok, $tenant_ok ? 'هر سه ردیف با همان شناسه و slug مهرشده وجود دارند.' : implode( ' · ', $tenant_problems ) );

		// --- 3. All eight sealed accounts exist, with the sealed logins ----------
		$account_problems = array();
		foreach ( Credentials::PERSONA_ORDER as $persona ) {
			$user_id = (int) ( $users[ $persona ] ?? 0 );
			$account = $user_id > 0 ? $this->env->user( $user_id ) : null;
			if ( null === $account ) {
				$account_problems[] = 'حساب «' . $persona . '» پیدا نشد';
				continue;
			}
			if ( $prefix . $persona . '-' . $fixture !== (string) $account['login'] ) {
				$account_problems[] = 'نام کاربری «' . $persona . '» با شناسهٔ مهرشده هم‌خوان نیست';
				continue;
			}
			if ( in_array( 'administrator', (array) $account['roles'], true ) || $this->env->user_can( $user_id, 'manage_options' ) ) {
				$account_problems[] = 'حساب «' . $persona . '» اختیار مدیرکل دارد';
			}
		}
		$accounts_ok = array() === $account_problems;
		$add( 'accounts', 'هشت حساب ساختگی مهرشده', $accounts_ok, $accounts_ok ? 'هر هشت حساب با شناسه و نام کاربری مهرشده وجود دارند و هیچ‌کدام مدیرکل نیستند.' : implode( ' · ', $account_problems ) );

		// --- 4. The fixture's own booking page ----------------------------------
		$page_id = (int) ( $ids['booking_page_id'] ?? 0 );
		$page    = $page_id > 0 ? $this->env->post( $page_id ) : null;
		$page_ok = is_array( $page ) && (string) ( $page['slug'] ?? '' ) === $expected['page'];
		$add( 'booking_page', 'برگهٔ رزرو عمومی دمو', $page_ok, $page_ok ? 'برگهٔ رزرو با شناسه و slug مهرشده وجود دارد.' : 'برگهٔ رزرو عمومی با شناسه/slug مهرشده پیدا نشد.' );

		// --- 5. The sealed baseline (the evidence a teardown needs) -------------
		$baseline  = (array) ( $manifest['baseline'] ?? array() );
		$pending   = $this->manifest->load_pending_baseline( $fixture );
		$has_base  = array() !== $baseline || ( is_array( $pending ) && array() !== (array) ( $pending['baseline'] ?? array() ) );
		$add(
			'baseline',
			'پایهٔ مرجع پیش از ساخت دمو',
			$has_base,
			$has_base
				? ( array() !== $baseline ? 'پایهٔ مرجع در سند مالکیت مهر شده است.' : 'پایهٔ مرجع مهرشدهٔ اجرای ناتمام موجود است.' )
				: 'پایهٔ مرجع پیدا نشد؛ بدون آن نه ادامه و نه پاک‌سازی قابل اثبات نیست.'
		);

		// --- 6. The sealed ownership ledger -------------------------------------
		$ledger     = ( new ScopedOwnership( $this->env, $this->manifest ) )->inspect( $fixture, false );
		$ledger_raw = $this->env->option_get( ScopedOwnership::OPTION, false );
		$ledger_ok  = ! empty( $ledger['ok'] );
		$add(
			'ledger',
			'دفتر مالکیت مهرشده',
			$ledger_ok,
			$ledger_ok
				? ( is_array( $ledger_raw ) ? 'زنجیرهٔ دفتر مالکیت معتبر است.' : 'هنوز باز نشده است (مالکیت درمانگاه اضافی ثبت نشده).' )
				: (string) ( $ledger['detail'] ?? 'دفتر مالکیت معتبر نیست.' )
		);

		// --- 7. Completeness: every stage before the seal recorded its result ---
		$required = array(
			'organization_id' => 'سازمان',
			'clinic_id'       => 'درمانگاه',
			'clinician_id'    => 'پزشک',
			'clinician_id_second' => 'پزشک دوم',
			'patient_id'      => 'بیمار',
			'appointment_id'  => 'نوبت',
			'visit_id'        => 'ویزیت',
			'invoice_id'      => 'صورتحساب',
			'payment_id'      => 'پرداخت',
			'booking_page_id' => 'برگهٔ رزرو',
		);
		$completion_problems = array();
		foreach ( $required as $key => $label ) {
			if ( (int) ( $ids[ $key ] ?? 0 ) <= 0 ) {
				$completion_problems[] = 'شناسهٔ «' . $label . '» ثبت نشده است';
			}
		}
		if ( array() === (array) ( $ids['slot_ids'] ?? array() ) ) {
			$completion_problems[] = 'شناسهٔ نوبت‌های آزاد ثبت نشده است';
		}
		if ( (string) ( $links['booking'] ?? '' ) === '' ) {
			$completion_problems[] = 'پیوند رزرو عمومی ثبت نشده است';
		}
		if ( array() === $completion_problems ) {
			foreach ( array(
				'cpms_invoices'     => array( (int) ( $ids['invoice_id'] ?? 0 ), 'صورتحساب' ),
				'cpms_payments'     => array( (int) ( $ids['payment_id'] ?? 0 ), 'پرداخت' ),
				'cpms_visits'       => array( (int) ( $ids['visit_id'] ?? 0 ), 'ویزیت' ),
				'cpms_appointments' => array( (int) ( $ids['appointment_id'] ?? 0 ), 'نوبت' ),
				'cpms_patients'     => array( (int) ( $ids['patient_id'] ?? 0 ), 'بیمار' ),
			) as $short => $spec ) {
				$row = $spec[0] > 0 ? $this->fetch_slug_row( $short, $spec[0] ) : null;
				if ( ! is_array( $row ) ) {
					$completion_problems[] = 'ردیف «' . $spec[1] . '» با شناسهٔ مهرشده پیدا نشد';
				} elseif ( (int) ( $row['clinic_id'] ?? $clinic ) !== $clinic ) {
					$completion_problems[] = 'ردیف «' . $spec[1] . '» به درمانگاه ساختگی تعلق ندارد';
				}
			}
		}
		$complete = array() === $completion_problems;
		$add(
			'completion',
			'کامل‌بودن داده‌های ساختگی تا مرحلهٔ مهر',
			$complete,
			$complete
				? 'همهٔ ردیف‌های مرحله‌های پیش از مهر با شناسهٔ مهرشده وجود دارند.'
				: implode( ' · ', array_slice( $completion_problems, 0, 4 ) )
		);

		// --- 8. How much of the seal itself survived ----------------------------
		$owned       = (array) ( $manifest['owned'] ?? array() );
		// `audit_chain_at_seal` is an ARRAY (the verifier's report), so it is tested
		// for presence, never string-cast: casting would raise "Array to string".
		$owned_ok    = array() !== $owned && ! empty( $manifest['audit_chain_at_seal'] );
		$sealed_flag = $owned_ok && is_array( $ledger_raw );
		$seal_detail = $sealed_flag
			? 'مهر (نقشهٔ مالکیت، وضعیت زنجیرهٔ حسابرسی و دفتر مالکیت) کامل است.'
			: ( array() === $owned
				? 'مهر ناتمام است: نقشهٔ مالکیت در سند ثبت نشده است.'
				: 'مهر ناتمام است: دفتر مالکیت یا وضعیت زنجیرهٔ حسابرسی ثبت نشده است.' );
		$add( 'seal', 'کامل‌بودن مهر مالکیت', $sealed_flag, $seal_detail );

		// --- Verdict -------------------------------------------------------------
		$structural = $shape_ok && $tenant_ok && $accounts_ok && $page_ok && $has_base;
		$code       = '';
		$reason     = '';

		if ( ! $known_status ) {
			$code   = 'DEMO_STATUS_UNKNOWN';
			$reason = 'وضعیت ثبت‌شدهٔ دمو شناخته‌شده نیست؛ هیچ عملی خودکار انجام نمی‌شود.';
		} elseif ( ! $structural ) {
			$code   = $tenant_ok && $accounts_ok ? 'DEMO_INCOMPLETE' : 'DEMO_EVIDENCE_MISSING';
			$reason = 'شواهد مهرشدهٔ دمو با ردیف‌های زنده هم‌خوان نیست؛ نه ادامهٔ خودکار مجاز است و نه پاک‌سازی، تا موضوع به‌صورت انسانی بررسی شود.';
		} elseif ( ! $ledger_ok ) {
			$code   = 'SCOPED_OWNERSHIP_LEDGER_INVALID';
			$reason = 'دفتر مالکیت مهرشده معتبر نیست؛ تا بازگردانی آن هیچ عملی انجام نمی‌شود.';
		} elseif ( 'active' === $status && $sealed_flag ) {
			return array(
				'verdict'  => self::VERDICT_ACTIVE,
				'code'     => '',
				'reason'   => 'دمو کامل و مهرشده است.',
				'status'   => $status,
				'sealed'   => true,
				'checks'   => $checks,
			);
		} elseif ( $owned_ok && ! is_array( $ledger_raw ) ) {
			// A sealed manifest whose ledger disappeared is lost ownership, not an
			// unfinished seal. Re-opening the ledger would silently re-derive it.
			$code   = 'SCOPED_OWNERSHIP_LEDGER_MISSING';
			$reason = 'مهر دمو وجود دارد اما دفتر مالکیت آن ناپدید شده است؛ تا بازگردانی دفتر هیچ عملی انجام نمی‌شود.';
		} elseif ( 'provisioning' === $status || 'failed' === $status || 'active' === $status ) {
			// Only the seal is left. It is refused unless the interruption is
			// provably at (or after) the last write stage.
			$stage           = (string) ( $manifest['failed_stage'] ?? '' );
			$stage_resumable = in_array( $stage, self::RESUMABLE_STAGES, true )
				|| ( '' === $stage && 'provisioning' === $status )
				// `active` written, then the process died inside the seal: the
				// sealed ownership map is exactly what is missing, so the same
				// additive seal finishes it.
				|| ( 'active' === $status && array() === $owned );
			if ( ! $stage_resumable ) {
				$code   = 'RECOVERY_NOT_SAFE';
				$reason = 'این اجرا در مرحلهٔ «' . ( '' === $stage ? 'نامشخص' : $stage ) . '» متوقف شده است. ادامهٔ خودکار فقط وقتی امن است که همهٔ ردیف‌های ساختگی پیش از مهر ساخته شده باشند؛ در این حالت ابتدا دموی ناتمام با تأیید شما پاک‌سازی و سپس دوباره ساخته می‌شود.';
			} elseif ( ! $complete ) {
				$code   = 'DEMO_INCOMPLETE';
				$reason = 'همهٔ ردیف‌های ساختگی تا مرحلهٔ مهر کامل نیستند؛ ادامهٔ خودکار مجاز نیست و مسیر درست، پاک‌سازی مجاز و ساخت دوباره است.';
			} else {
				return array(
					'verdict'  => self::VERDICT_RESUMABLE,
					'code'     => '',
					'reason'   => 'همهٔ ردیف‌های ساختگی ساخته شده‌اند و فقط مهر نهایی ناقص است؛ ادامه با همان شواهد مهرشده ایمن است.',
					'status'   => $status,
					'sealed'   => false,
					'checks'   => $checks,
				);
			}
		} else {
			$code   = 'RECOVERY_NOT_SAFE';
			$reason = 'وضعیت دمو برای ادامهٔ خودکار امن نیست.';
		}

		return array(
			'verdict' => self::VERDICT_BLOCKED,
			'code'    => $code,
			'reason'  => $reason,
			'status'  => $status,
			'sealed'  => $sealed_flag,
			'checks'  => $checks,
		);
	}

	/**
	 * Read one row by primary key, without inventing ownership.
	 *
	 * @param string $short Table short name.
	 * @param int    $id    Primary key.
	 * @return array<string,mixed>|null
	 */
	private function fetch_slug_row( string $short, int $id ): ?array {
		if ( $id <= 0 || ! $this->platform->table_exists( $short ) ) {
			return null;
		}

		return $this->platform->fetch_row( 'SELECT * FROM ' . $this->platform->table( $short ) . ' WHERE id = %d', array( $id ) );
	}
}
