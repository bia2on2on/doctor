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

	public function __construct( Env $env, Platform $platform, ScopedManifest $manifest, ScopedPreflight $preflight, Credentials $credentials, ScopedBaseline $baseline, ScopedAuditGuard $audit_guard ) {
		parent::__construct( $env, $platform, $manifest, $preflight, $credentials );
		$this->scoped_manifest = $manifest;
		$this->baseline        = $baseline;
		$this->audit_guard     = $audit_guard;
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
		$this->pending_baseline = $this->baseline->snapshot();
	}

	/**
	 * @param array{manifest:array<string,mixed>,credentials:array<string,string>} $result Result.
	 * @param int $admin_id Acting Administrator.
	 * @return array{manifest:array<string,mixed>,credentials:array<string,string>}
	 */
	protected function after_provision( array $result, int $admin_id ): array {
		unset( $admin_id );
		$manifest = $result['manifest'];
		$manifest['mode']     = 'scoped';
		$manifest['baseline'] = $this->pending_baseline;
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
