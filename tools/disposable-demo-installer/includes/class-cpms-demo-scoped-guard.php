<?php
/**
 * Server-side boundary around the demo tenants while a scoped demo is active.
 *
 * TWO JOBS, both server-side and both inert outside scoped demo mode:
 *
 * 1. RECORD WHAT DEMO WORKFLOWS CREATE.
 *    A demo persona (or the demo's own front-desk flow) legitimately creates
 *    patients, appointments, visits, invoices and payments inside the demo
 *    tenant. None of those rows carries a sealed id, and none of them can be
 *    proven owned by a structural rule alone, so every state-changing request
 *    made by a SYNTHETIC FIXTURE ACCOUNT is snapshotted before it runs and
 *    diffed after it finishes; the new rows are sealed into the ownership
 *    ledger as explicit registrations. The teardown therefore never has to
 *    guess which post-baseline rows in the demo tenant are demo data.
 *
 * 2. REFUSE REAL ACTORS WRITING INTO DEMO TENANTS.
 *    An authenticated user who is not part of the fixture (and is not a site
 *    administrator working on the demo itself) cannot write into the demo
 *    Clinic/Organization through the Management Portal or the REST API. They
 *    get a Persian refusal, so "a real staff member unknowingly created data
 *    that was later deleted" cannot happen. Anonymous public requests are
 *    still allowed - the demo's public booking journey depends on them - and
 *    any data they create for a real patient is NOT registered, so the
 *    teardown refuses instead of deleting it.
 *
 * Nothing here writes to a production table, and nothing here is a UI guard:
 * both refusals happen before dispatch, on the server.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Logical grouping is clearer than column alignment in these ownership maps.

/**
 * Tenant write barrier + demo-write recorder.
 */
final class ScopedTenantGuard {

	/** WP-CLI / cron contexts never carry a portal or REST actor. */
	private const WRITE_METHODS = array( 'POST', 'PUT', 'PATCH', 'DELETE' );

	/** @var array<string,list<int>>|null Snapshot taken at the start of this request. */
	private static ?array $snapshot = null;

	/** @var string Fixture identity of the snapshot. */
	private static string $fixture = '';

	/**
	 * Hook the guard and the recorder.
	 *
	 * Priority 0 on both sides: the snapshot must be taken before any plugin can
	 * write, and the diff must be computed after everything has finished.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'begin_request' ), 0 );
		add_action( 'shutdown', array( __CLASS__, 'finish_request' ), 0 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'guard_rest_request' ), 10, 3 );
	}

	/**
	 * Snapshot the demo tenants before a state-changing request runs.
	 *
	 * @return void
	 */
	public static function begin_request(): void {
		if ( ! self::is_write_request() ) {
			return;
		}
		$env      = new Env();
		$manifest = new ScopedManifest( $env );
		$loaded   = self::verified_manifest( $manifest );
		if ( null === $loaded ) {
			return;
		}
		$fixture = (string) ( $loaded['fixture_id'] ?? '' );
		// Only a synthetic fixture account can create REGISTRABLE demo data; any
		// other actor is either refused below or (anonymous) protected by the
		// teardown's attribution rules. Taking the snapshot only when it can be
		// used keeps every other request untouched.
		if ( ! self::is_fixture_actor( $loaded ) ) {
			return;
		}
		self::$fixture  = $fixture;
		self::$snapshot = self::snapshot( $env, new Platform(), $loaded );
	}

	/**
	 * Seal the rows this request created into the ownership ledger.
	 *
	 * @return void
	 */
	public static function finish_request(): void {
		if ( null === self::$snapshot || '' === self::$fixture ) {
			return;
		}
		$env       = new Env();
		$platform  = new Platform();
		$manifest  = new ScopedManifest( $env );
		$ownership = new ScopedOwnership( $env, $manifest );
		$after     = self::snapshot( $env, $platform, self::manifest_ids( $manifest ) );
		try {
			foreach ( $after as $short => $ids ) {
				$before = array_fill_keys( (array) ( self::$snapshot[ $short ] ?? array() ), true );
				$new    = array();
				foreach ( $ids as $id ) {
					if ( ! isset( $before[ $id ] ) ) {
						$new[] = $id;
					}
				}
				$ownership->register_rows(
					self::$fixture,
					(string) $short,
					$new,
					array( 'source' => 'demo-request', 'actor_id' => get_current_user_id() )
				);
			}
		} catch ( \Throwable $error ) {
			// The request already succeeded; the rows exist and are unregistered.
			// That is fail-closed by construction: the teardown will refuse them
			// rather than delete rows it cannot prove. Record why, once.
			update_option(
				'cpsd_recorder_error',
				array( 'code' => $error instanceof Exception ? $error->demo_code() : 'RECORDER', 'message' => $error->getMessage() ),
				false
			);
		}
		self::$snapshot = null;
		self::$fixture  = '';
	}

	/**
	 * Refuse a REST write that targets a demo tenant from a real actor.
	 *
	 * @param mixed            $result  Short-circuit value (null = continue).
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function guard_rest_request( $result, $server, $request ) {
		if ( null !== $result || ! ( $request instanceof \WP_REST_Request ) ) {
			return $result;
		}
		$verdict = self::write_verdict( (string) $request->get_method(), $request->get_params() );
		if ( true === $verdict ) {
			return $result;
		}

		return new \WP_Error(
			'SCOPED_DEMO_TENANT_WRITE_REFUSED',
			$verdict,
			array( 'status' => 403 )
		);
	}

	/**
	 * Decide whether a write may proceed.
	 *
	 * @param string              $method HTTP method.
	 * @param array<string,mixed> $params Request parameters/body.
	 * @return true|string True when allowed, Persian reason when refused.
	 */
	public static function write_verdict( string $method, array $params ) {
		if ( ! in_array( strtoupper( $method ), self::WRITE_METHODS, true ) ) {
			return true;
		}
		$env      = new Env();
		$manifest = new ScopedManifest( $env );
		$loaded   = self::verified_manifest( $manifest );
		if ( null === $loaded ) {
			return true;
		}
		if ( ! self::targets_demo_tenant( $loaded, $params ) ) {
			return true;
		}
		// The fixture's own accounts run the demo; an administrator is the
		// operator of the demo; everyone else must not write into the sandbox.
		if ( self::is_fixture_actor( $loaded ) || current_user_can( 'manage_options' ) ) {
			return true;
		}
		if ( 0 === get_current_user_id() ) {
			// Anonymous public booking/enrolment into the demo Clinic is the
			// demo's own public journey. It is ALLOWED, and anything it creates for
			// a non-fixture patient is refused by the teardown (never deleted).
			return true;
		}

		return 'در حالت «دموی محدودشده» نوشتن در درمانگاه/سازمان نمایشی فقط برای حساب‌های ساختگی همان دمو و مدیر سایت مجاز است. '
			. 'این درخواست در سرور رد شد تا دادهٔ واقعی با دادهٔ نمایشی اشتباه نشود.';
	}

	/**
	 * Does this request name the demo tenant?
	 *
	 * Three shapes are recognised, because the production API accepts the tenant
	 * as a header, as a body parameter, or as a path/entity id of the demo Clinic.
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @param array<string,mixed> $params   Request parameters.
	 * @return bool
	 */
	private static function targets_demo_tenant( array $manifest, array $params ): bool {
		$ids    = (array) ( $manifest['ids'] ?? array() );
		$clinic = (int) ( $ids['clinic_id'] ?? 0 );
		$org    = (int) ( $ids['organization_id'] ?? 0 );
		$owned  = array_values( array_filter( array_map( 'intval', (array) ( $ids['location_ids'] ?? array() ) ) ) );
		// The production API scopes a request with the X-CPMS-Clinic-Id header.
		if ( isset( $_SERVER['HTTP_X_CPMS_CLINIC_ID'] ) && $clinic > 1 && (int) $_SERVER['HTTP_X_CPMS_CLINIC_ID'] === $clinic ) {
			return true;
		}
		foreach ( array( 'clinic_id', 'clinicId', 'organization_id', 'organizationId' ) as $key ) {
			if ( ! isset( $params[ $key ] ) || ! is_scalar( $params[ $key ] ) ) {
				continue;
			}
			$value = (int) $params[ $key ];
			if ( $clinic > 1 && $value === $clinic ) {
				return true;
			}
			if ( $org > 1 && $value === $org ) {
				return true;
			}
		}
		foreach ( array( 'location_id', 'locationId' ) as $key ) {
			if ( isset( $params[ $key ] ) && is_scalar( $params[ $key ] ) && in_array( (int) $params[ $key ], $owned, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Is the current request made by one of the fixture's own synthetic accounts?
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @return bool
	 */
	private static function is_fixture_actor( array $manifest ): bool {
		$actor = get_current_user_id();
		if ( $actor <= 0 ) {
			return false;
		}
		$users = array_map( 'intval', (array) ( $manifest['ids']['user_ids'] ?? array() ) );

		return in_array( $actor, $users, true );
	}

	/**
	 * @param ScopedManifest $manifest Manifest (for the current fixture).
	 * @return array<string,mixed>
	 */
	private static function manifest_ids( ScopedManifest $manifest ): array {
		$loaded = self::verified_manifest( $manifest );

		return null === $loaded ? array() : $loaded;
	}

	/**
	 * The verified, active fixture manifest — or null when there is none.
	 *
	 * This is the ONE place that reads the manifest for the hooks below, and it
	 * is deliberately total: a manifest that fails verification (a tampered or
	 * foreign payload) is not an error to propagate here. These hooks run on
	 * ordinary requests — a login POST is a write request too — so letting the
	 * exception escape would turn a tampered manifest into a fatal error on
	 * every form submission of the site, including the one that would log the
	 * operator in.
	 *
	 * Standing down is the safe answer, not a weakening of the fail-closed
	 * policy: with no verified fixture there is no fixture identity to register
	 * rows against and no demo tenant this process can prove, so nothing new can
	 * become deletable — the teardown refuses every row it cannot attribute, and
	 * it refuses to run at all while the manifest does not verify.
	 *
	 * @param ScopedManifest $manifest Manifest reader.
	 * @return array<string,mixed>|null
	 */
	private static function verified_manifest( ScopedManifest $manifest ): ?array {
		try {
			if ( ! $manifest->mode_enabled() ) {
				return null;
			}
			$loaded = $manifest->load();
		} catch ( \Throwable $error ) {
			return null;
		}
		if ( ! is_array( $loaded ) || 'active' !== (string) ( $loaded['status'] ?? '' ) ) {
			return null;
		}
		if ( '' === (string) ( $loaded['fixture_id'] ?? '' ) ) {
			return null;
		}

		return $loaded;
	}

	/**
	 * @return bool
	 */
	private static function is_write_request(): bool {
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) );

		return in_array( $method, self::WRITE_METHODS, true );
	}

	/**
	 * Row ids currently present inside every demo tenant boundary.
	 *
	 * Only tables with a surrogate id and a tenant column are snapshotted: those
	 * are exactly the tables a demo workflow writes directly. Everything else is
	 * attributable through its declared parent.
	 *
	 * @param Env                 $env      Environment.
	 * @param Platform            $platform Adapter.
	 * @param array<string,mixed> $manifest Manifest.
	 * @return array<string,list<int>>
	 */
	private static function snapshot( Env $env, Platform $platform, array $manifest ): array {
		unset( $env );
		$out    = array();
		$ids    = (array) ( $manifest['ids'] ?? array() );
		$clinic = (int) ( $ids['clinic_id'] ?? 0 );
		$org    = (int) ( $ids['organization_id'] ?? 0 );
		if ( $clinic <= 1 ) {
			return $out;
		}
		foreach ( Preflight::KNOWN_TABLES as $short ) {
			if ( ! $platform->table_exists( $short ) ) {
				continue;
			}
			$full = $platform->table( $short );
			$cols = $platform->columns( $full );
			if ( ! in_array( 'id', $cols, true ) ) {
				continue;
			}
			$clauses = array();
			$params  = array();
			if ( in_array( 'clinic_id', $cols, true ) ) {
				$clauses[] = '`clinic_id` = %d';
				$params[]  = $clinic;
			}
			if ( $org > 1 && in_array( 'organization_id', $cols, true ) ) {
				$clauses[] = '`organization_id` = %d';
				$params[]  = $org;
			}
			if ( array() === $clauses ) {
				continue;
			}
			$rows = $platform->fetch_all(
				'SELECT id FROM `' . $full . '` WHERE (' . implode( ' OR ', $clauses ) . ') ORDER BY id ASC',
				$params
			);
			$list = array();
			foreach ( $rows as $row ) {
				$list[] = (int) ( $row['id'] ?? 0 );
			}
			$out[ $short ] = $list;
		}

		return $out;
	}
}
