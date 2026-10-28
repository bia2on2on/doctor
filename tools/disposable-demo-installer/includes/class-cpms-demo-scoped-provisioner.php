<?php
/**
 * Scoped-mode provisioner.
 *
 * Reuses the production-service provisioning path from Provisioner but adds a
 * 128-bit fixture ID, an explicit `cpsd-` namespace, collision checks, a sealed
 * baseline snapshot, and an exact per-record ownership map collected after the
 * manifest is sealed. Strict disposable behavior is unchanged.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Logical grouping is clearer than column alignment in these ownership maps.

/**
 * Provisioner for existing non-empty test sites.
 */
class ScopedProvisioner extends Provisioner {

	/** @var ScopedManifest */
	private ScopedManifest $scoped_manifest;

	/** @var ScopedBaseline */
	private ScopedBaseline $baseline;

	/** @var ScopedAuditGuard */
	private ScopedAuditGuard $audit_guard;

	/** @var string */
	private string $pending_fixture_id = '';

	/** @var array<string,mixed> */
	private array $pending_baseline = array();

	/** @var array<string,mixed> */
	private array $pending_host = array();

	public function __construct( Env $env, Platform $platform, ScopedManifest $manifest, ScopedPreflight $preflight, Credentials $credentials, ScopedBaseline $baseline, ScopedAuditGuard $audit_guard ) {
		parent::__construct( $env, $platform, $manifest, $preflight, $credentials );
		$this->scoped_manifest = $manifest;
		$this->baseline        = $baseline;
		$this->audit_guard     = $audit_guard;
	}

	/**
	 * Provision, keeping the sealed pre-provisioning baseline only when a
	 * manifest was actually written for it.
	 *
	 * A refusal that happens before the first synthetic row (no mode, collision,
	 * refused preflight, concurrent operation) must not leave a stale pending
	 * baseline behind: it is recovery evidence, and evidence without a fixture is
	 * noise that would also block a clean uninstall.
	 *
	 * @param int $admin_id Acting Administrator.
	 * @return array{manifest:array<string,mixed>,credentials:array<string,string>}
	 */
	public function provision( int $admin_id ): array {
		try {
			return parent::provision( $admin_id );
		} catch ( \Throwable $error ) {
			// Only refusals that are raised before any synthetic row can exist are
			// cleared. Anything else keeps the sealed baseline: an interrupted run
			// must stay recoverable (and visibly blocked in uninstall) rather than
			// silently discard the only proof of what pre-existed.
			$nothing_created = array( 'MODE_DISABLED', 'COLLISION', 'PREFLIGHT', 'CONCURRENT_OPERATION', 'ALREADY_PROVISIONED' );
			$code            = $error instanceof Exception ? $error->demo_code() : 'PROVISION_FAILED';
			if ( ! $this->scoped_manifest->exists() && in_array( $code, $nothing_created, true ) ) {
				$this->scoped_manifest->clear_pending_baseline();
			}
			throw $error;
		}
	}

	/**
	 * @return array{fixture_id:string,slug:string}
	 */
	protected function new_fixture(): array {
		if ( '' === $this->pending_fixture_id ) {
			$this->pending_fixture_id = $this->scoped_manifest->fixture_id();
		}
		return array(
			'fixture_id' => $this->pending_fixture_id,
			'slug'       => $this->scoped_manifest->fixture_prefix() . $this->pending_fixture_id,
		);
	}

	/**
	 * @param int $admin_id Acting Administrator.
	 * @return void
	 */
	protected function before_provision( int $admin_id ): void {
		unset( $admin_id );
		if ( ! $this->scoped_manifest->mode_enabled() ) {
			throw new Exception( 'MODE_DISABLED', 'حالت محدودشده فعال نیست.' );
		}
		$this->pending_fixture_id = $this->scoped_manifest->fixture_id();
		$this->check_collisions( $this->pending_fixture_id );
		$this->pending_host     = $this->host_evidence();
		$this->pending_baseline = $this->baseline->snapshot();
		// Seal the baseline before the first row is created: if this run is
		// interrupted, the recovery teardown can still prove what pre-existed.
		$this->scoped_manifest->save_pending_baseline( $this->pending_fixture_id, $this->pending_baseline, $this->pending_host );
	}

	/**
	 * Read-only evidence about the host site, sealed into the manifest so that the
	 * teardown can prove it changed nothing but its own additive rows.
	 *
	 * `clinic_count` is the production implicit-scope input: the scoped preflight
	 * refuses a site whose count is 0 or 1, so every accepted provisioning run
	 * starts from (and must keep) multi-Clinic semantics.
	 *
	 * @return array<string,mixed>
	 */
	private function host_evidence(): array {
		$clinic_table = $this->platform->table( 'cpms_clinics' );

		return array(
			'clinic_count'          => $this->platform->count_all( 'cpms_clinics' ),
			'organization_count'    => $this->platform->count_all( 'cpms_organizations' ),
			'location_count'        => $this->platform->count_all( 'cpms_locations' ),
			'active_clinic_count'   => $this->platform->count_where( $clinic_table, 'status = %s', array( 'active' ) ),
			'audit_max_id'          => $this->platform->max_id( 'cpms_audit_logs' ),
			'journal_max_id'        => $this->platform->max_id( 'cpms_jobs' ),
			'operational_max_id'    => $this->platform->max_id( 'cpms_operational_logs' ),
		);
	}

	/**
	 * @param array{manifest:array<string,mixed>,credentials:array<string,string>} $result Result.
	 * @param int $admin_id Acting Administrator.
	 * @return array{manifest:array<string,mixed>,credentials:array<string,string>}
	 */
	protected function after_provision( array $result, int $admin_id ): array {
		unset( $admin_id );
		$manifest = $result['manifest'];
		$manifest['mode']        = 'scoped';
		$manifest['baseline']    = $this->pending_baseline;
		$manifest['host_before'] = $this->pending_host;
		$teardown = new ScopedTeardown( $this->env, $this->platform, $this->scoped_manifest, $this->audit_guard, $this->baseline );
		$scope    = $teardown->collect_scope( $manifest, $this->pending_baseline );
		$manifest['owned'] = array(
			'tables'           => $scope['tables'],
			'jobs'             => $scope['jobs'],
			'operational_logs' => $scope['operational_logs'],
			'audit_logs'       => $scope['audit_logs'],
			'rate_limit_keys'  => $scope['rate_limit_keys'],
		);
		$manifest['audit_chain_at_seal'] = $this->audit_guard->verify_chain();
		$manifest = $this->scoped_manifest->save( $manifest );
		// The baseline is now sealed inside the manifest itself.
		$this->scoped_manifest->clear_pending_baseline();
		$result['manifest'] = $manifest;
		return $result;
	}

	/**
	 * Prove that the fixture namespace and synthetic mobile cannot collide.
	 *
	 * @param string $fixture 128-bit fixture ID.
	 * @return void
	 */
	private function check_collisions( string $fixture ): void {
		$prefix = $this->scoped_manifest->fixture_prefix();
		$mobile = self::mobile_for( $fixture );
		foreach ( Credentials::PERSONA_ORDER as $persona ) {
			$login = $prefix . $persona . '-' . $fixture;
			$email = 'patient' === $persona ? $mobile . '@otp.cpms.local' : $login . '@demo.invalid';
			if ( $this->env->username_exists( $login ) || $this->env->email_exists( $email ) ) {
				throw new Exception( 'COLLISION', 'نام کاربری یا ایمیل دمو از قبل وجود دارد.' );
			}
		}
		foreach ( $this->env->posts_summary() as $post ) {
			if ( (string) ( $post['slug'] ?? '' ) === $prefix . $fixture . '-booking' ) {
				throw new Exception( 'COLLISION', 'slug برگه رزرو دمو از قبل وجود دارد.' );
			}
		}
		$slugs = array(
			$prefix . $fixture,
			$prefix . $fixture . '-clinic',
			$prefix . $fixture . '-main',
			$prefix . $fixture . '-branch',
		);
		foreach ( array( 'cpms_organizations', 'cpms_clinics', 'cpms_locations' ) as $short ) {
			foreach ( $slugs as $slug ) {
				$rows = $this->platform->fetch_all( 'SELECT id FROM `' . $this->platform->table( $short ) . '` WHERE slug = %s LIMIT 1', array( $slug ) );
				if ( array() !== $rows ) {
					throw new Exception( 'COLLISION', 'slug سازمان/درمانگاه/شعبه دمو از قبل وجود دارد.' );
				}
			}
		}
		$mobile_checks = array(
			'cpms_patients'          => 'mobile',
			'cpms_otp_tokens'        => 'mobile',
			'cpms_sms_messages'      => 'recipient',
			'cpms_patient_identities' => 'normalized_mobile',
		);
		foreach ( $mobile_checks as $short => $column ) {
			if ( ! $this->platform->table_exists( $short ) || ! in_array( $column, $this->platform->columns( $this->platform->table( $short ) ), true ) ) {
				continue;
			}
			$rows = $this->platform->fetch_all( 'SELECT id FROM `' . $this->platform->table( $short ) . '` WHERE `' . $column . '` = %s LIMIT 1', array( $mobile ) );
			if ( array() !== $rows ) {
				throw new Exception( 'COLLISION', 'شماره ساختگی دمو از قبل وجود دارد.' );
			}
		}
		if ( $this->platform->table_exists( 'cpms_idempotency_keys' ) ) {
			$rows = $this->platform->fetch_all( 'SELECT id FROM `' . $this->platform->table( 'cpms_idempotency_keys' ) . '` WHERE `key` LIKE %s LIMIT 1', array( '%' . $fixture . '%' ) );
			if ( array() !== $rows ) {
				throw new Exception( 'COLLISION', 'کلید idempotency دمو از قبل وجود دارد.' );
			}
		}
		if ( $this->platform->table_exists( 'cpms_rate_limits' ) ) {
			$rows = $this->platform->fetch_all( 'SELECT window_key FROM `' . $this->platform->table( 'cpms_rate_limits' ) . '` WHERE window_key LIKE %s LIMIT 1', array( '%' . $fixture . '%' ) );
			if ( array() !== $rows ) {
				throw new Exception( 'COLLISION', 'کلید محدودیت نرخ دمو از قبل وجود دارد.' );
			}
		}
	}
}
