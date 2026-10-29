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

	/**
	 * Production tables an entity-id route can address, in lookup order.
	 *
	 * `/clinic/v1/appointments/42` carries no tenant marker, so the row's own
	 * `clinic_id` is the only honest answer to "which tenant is this write for".
	 */
	private const ENTITY_TABLES = array(
		'cpms_appointments',
		'cpms_visits',
		'cpms_invoices',
		'cpms_payments',
		'cpms_patients',
		'cpms_prescriptions',
		'cpms_clinical_notes',
		'cpms_message_threads',
		'cpms_clinicians',
		'cpms_schedule',
		'cpms_services',
		'cpms_locations',
	);

	/** @var array<string,list<int>>|null Snapshot taken at the start of this request. */
	private static ?array $snapshot = null;

	/** @var string Fixture identity of the snapshot. */
	private static string $fixture = '';

	/**
	 * Set once this request can never become recordable: it is not a write, or
	 * there is no verified active fixture to record against.
	 *
	 * It is deliberately NOT set when the only missing piece is the actor. The
	 * snapshot MUST be taken once the current user is resolved, and "resolved"
	 * happens at a different moment on different request paths: at `init` a REST
	 * request has not authenticated its cookie yet (WordPress authenticates inside
	 * `serve_request()`, before dispatch), and `admin-post.php` authenticates
	 * later still. The first implementation set a single "considered" flag at the
	 * top of `begin_request()`, so the `init` pass - which always sees user 0 for
	 * REST - consumed it and every later pass returned immediately: a synthetic
	 * persona's REST write was never snapshotted, and the ledger stayed empty.
	 * A decision is now only recorded when it cannot change, and the actor check
	 * retries until the actor is known.
	 */
	private static bool $standdown = false;

	/**
	 * Hook the guard and the recorder.
	 *
	 * Priority 0 on both sides: the snapshot must be taken before any plugin can
	 * write, and the diff must be computed after everything has finished.
	 *
	 * @return void
	 */
	public static function register(): void {
		// `init` covers CLI/test contexts where the user is already set; the
		// admin hooks and `rest_pre_dispatch` cover the paths where WordPress
		// authenticates the cookie later. `begin_request()` is idempotent.
		add_action( 'init', array( __CLASS__, 'begin_request' ), 0 );
		// `wp_loaded` runs after WordPress has resolved the current user and
		// BEFORE any dispatch: admin-post.php fires its action after it,
		// admin-ajax.php fires the admin-AJAX actions after it, `template_redirect` (the
		// Management Portal) runs after it, and REST dispatch runs after it. It is
		// therefore the only universal place where a write can be both recorded
		// and refused for every surface, with the actor already known.
		add_action( 'wp_loaded', array( __CLASS__, 'guard_request' ), 0 );
		add_action( 'admin_init', array( __CLASS__, 'guard_request' ), 0 );
		add_action( 'admin_post', array( __CLASS__, 'begin_request' ), 1 );
		add_action( 'shutdown', array( __CLASS__, 'finish_request' ), 0 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'guard_rest_request' ), 10, 3 );
	}

	/**
	 * Snapshot the demo tenants before a state-changing request runs.
	 *
	 * @return void
	 */
	public static function begin_request(): void {
		if ( null !== self::$snapshot || self::$standdown ) {
			// Already recording, or this request can never be recorded.
			return;
		}
		if ( ! self::is_write_request() ) {
			self::$standdown = true;

			return;
		}
		$env      = new Env();
		$manifest = new ScopedManifest( $env );
		$loaded   = self::verified_manifest( $manifest );
		if ( null === $loaded ) {
			// No verified active fixture: there is no fixture identity to register
			// rows against, so nothing about this request can ever be recorded.
			self::$standdown = true;

			return;
		}
		$fixture = (string) ( $loaded['fixture_id'] ?? '' );
		// Only a synthetic fixture account can create REGISTRABLE demo data; any
		// other actor is either refused by the barrier or (anonymous) protected by
		// the teardown's attribution rules. Taking the snapshot only when it can be
		// used keeps every other request untouched.
		//
		// The actor is NOT a terminal decision: at `init` a REST request has not
		// authenticated yet, so "not a fixture actor" here usually means "not known
		// yet". The next write-capable entry point (`admin_init`, `admin_post`,
		// `rest_pre_dispatch`) re-evaluates, and the first one that sees a synthetic
		// actor takes the snapshot.
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
	 * Snapshot the tenant, then refuse a write that must not happen.
	 *
	 * REST is not the only way into the demo tenant: `admin-post.php`,
	 * `admin-ajax.php`, the Management Portal's `template_redirect` handler and any
	 * plugin screen that writes on `admin_init` reach the same production services.
	 * This is the single entry point they all pass through, and it runs after the
	 * actor is known (`wp_loaded`) and before the handler (`admin_init`,
	 * `wp_loaded`). Both halves are idempotent, so being called twice is harmless.
	 *
	 * @return void
	 */
	public static function guard_request(): void {
		self::begin_request();
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
		if ( ! in_array( $method, self::WRITE_METHODS, true ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only classification of the request, never used to authorize anything.
		$params  = (array) wp_unslash( $_REQUEST );
		$verdict = self::write_verdict( $method, $params );
		if ( true === $verdict ) {
			return;
		}
		wp_die( esc_html( $verdict ), esc_html__( 'CPMS Demo: نوشتن در درمانگاه نمایشی مجاز نیست', 'cpms' ), array( 'response' => 403 ) );
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
		// By the time this filter runs WordPress has authenticated the request,
		// so the actor is known: take the snapshot here for a REST write.
		self::begin_request();
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
		$scope   = self::demo_scope( $manifest );
		$clinics = (array) $scope['clinics'];
		if ( array() === $clinics ) {
			return false;
		}
		$org = (int) $scope['organization_id'];
		// The production API scopes a request with the X-CPMS-Clinic-Id header.
		if ( isset( $_SERVER['HTTP_X_CPMS_CLINIC_ID'] ) && in_array( (int) $_SERVER['HTTP_X_CPMS_CLINIC_ID'], $clinics, true ) ) {
			return true;
		}
		foreach ( array( 'clinic_id', 'clinicId', 'organization_id', 'organizationId' ) as $key ) {
			if ( ! isset( $params[ $key ] ) || ! is_scalar( $params[ $key ] ) ) {
				continue;
			}
			$value = (int) $params[ $key ];
			if ( in_array( $value, $clinics, true ) ) {
				return true;
			}
			if ( $org > 1 && $value === $org ) {
				return true;
			}
		}
		foreach ( array( 'location_id', 'locationId' ) as $key ) {
			if ( isset( $params[ $key ] ) && is_scalar( $params[ $key ] ) && in_array( (int) $params[ $key ], (array) $scope['locations'], true ) ) {
				return true;
			}
		}
		// ENTITY-ID ROUTES. `/clinic/v1/appointments/42` names no tenant at all: the
		// tenant is implied by the row itself. A browser-provided id is never
		// authorization, so the id is resolved against the production tables and
		// the row's own clinic decides.
		if ( self::entity_targets_demo_tenant( $clinics, $params ) ) {
			return true;
		}

		// IMPLICIT TRUSTED SCOPE. Production resolves the effective Clinic for this
		// request from the actor's memberships (SystemClinicResolver), and a write
		// with no tenant marker at all inherits it. Asking production the same
		// question it will ask is the only way to see that surface without
		// re-implementing authorization here.
		return self::resolved_scope_is_demo( $clinics );
	}

	/**
	 * Does an entity id in this request name a row inside a demo Clinic?
	 *
	 * Only the production tables that own a tenant column are consulted, and only
	 * for the id-shaped values the request actually carries.
	 *
	 * @param list<int>           $clinics Verified demo Clinics.
	 * @param array<string,mixed> $params  Request parameters.
	 * @return bool
	 */
	private static function entity_targets_demo_tenant( array $clinics, array $params ): bool {
		$candidates = array();
		foreach ( $params as $key => $value ) {
			if ( ! is_scalar( $value ) || ! ctype_digit( (string) $value ) ) {
				continue;
			}
			$key = (string) $key;
			if ( 'id' !== $key && ! preg_match( '/_(id|ids)$/', $key ) ) {
				continue;
			}
			$id = (int) $value;
			if ( $id > 0 ) {
				$candidates[ $id ] = true;
			}
		}
		if ( array() === $candidates ) {
			return false;
		}
		$ids  = array_keys( $candidates );
		$size = count( $clinics );
		foreach ( self::ENTITY_TABLES as $short ) {
			$platform = new Platform();
			if ( ! $platform->table_exists( $short ) ) {
				continue;
			}
			$full = $platform->table( $short );
			$cols = $platform->columns( $full );
			if ( ! in_array( 'id', $cols, true ) || ! in_array( 'clinic_id', $cols, true ) ) {
				continue;
			}
			$rows = $platform->fetch_all(
				'SELECT id FROM `' . $full . '` WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')'
				. ' AND clinic_id IN (' . implode( ',', array_fill( 0, $size, '%d' ) ) . ') LIMIT 1',
				array_merge( $ids, $clinics )
			);
			if ( array() !== $rows ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Does production's own resolution put this request inside a demo Clinic?
	 *
	 * @param list<int> $clinics Verified demo Clinics.
	 * @return bool
	 */
	private static function resolved_scope_is_demo( array $clinics ): bool {
		if ( ! class_exists( '\ClinicCore\Application\Scope\SystemClinicResolver' ) || ! class_exists( '\ClinicCore\Bootstrap\App' ) ) {
			return false;
		}
		try {
			$scope = \ClinicCore\Application\Scope\SystemClinicResolver::resolve( \ClinicCore\Bootstrap\App::db() );
		} catch ( \Throwable $error ) {
			// Production refuses to resolve an implicit scope (multi-Clinic site,
			// no selection). That is not a demo tenant, and it is production's own
			// refusal to make.
			return false;
		}
		$clinic = (int) ( $scope->clinicId ?? 0 ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- published production scope API.

		return $clinic > 0 && in_array( $clinic, $clinics, true );
	}

	/**
	 * The verified demo tenant scope for the barrier and the recorder.
	 *
	 * The fixture Clinic comes from the sealed manifest; any ADDITIONAL demo
	 * Clinic comes from the sealed ownership ledger, which only registers a Clinic
	 * it created itself. The ledger is optional here: an unreadable one leaves the
	 * manifest Clinic in force, which is the narrowest correct scope.
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @return array{clinics:list<int>,locations:list<int>,organization_id:int}
	 */
	private static function demo_scope( array $manifest ): array {
		$ids       = (array) ( $manifest['ids'] ?? array() );
		$clinics   = array( (int) ( $ids['clinic_id'] ?? 0 ) );
		$locations = array_map( 'intval', (array) ( $ids['location_ids'] ?? array() ) );
		$org       = (int) ( $ids['organization_id'] ?? 0 );
		try {
			$env       = new Env();
			$reader    = new ScopedManifest( $env );
			$ownership = new ScopedOwnership( $env, $reader );
			$summary   = $ownership->summary( (string) ( $manifest['fixture_id'] ?? '' ) );
			foreach ( (array) ( $summary['clinic_ids'] ?? array() ) as $clinic_id ) {
				$clinics[] = (int) $clinic_id;
			}
		} catch ( \Throwable $error ) {
			// Keep the manifest Clinic only; never widen the scope on a failure.
			unset( $error );
		}

		return array(
			'clinics'         => array_values( array_unique( array_filter( $clinics, static fn ( int $id ): bool => $id > 1 ) ) ),
			'locations'       => array_values( array_unique( array_filter( $locations ) ) ),
			'organization_id' => $org,
		);
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
		$scope  = self::demo_scope( $manifest );
		$org    = (int) $scope['organization_id'];
		if ( array() === $scope['clinics'] ) {
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
				$clauses[] = '`clinic_id` IN (' . implode( ',', array_fill( 0, count( $scope['clinics'] ), '%d' ) ) . ')';
				$params    = array_merge( $params, $scope['clinics'] );
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
