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
		// Ownership of the fixture is recorded explicitly, never inferred later
		// from "it names the demo Organization and has a higher id". The ledger is
		// opened FIRST, because the scope collected below must already see it: a
		// sealed, active fixture is only trustworthy while its ledger exists.
		$ownership = new ScopedOwnership( $this->env, $this->scoped_manifest );
		$ownership->open(
			(string) $manifest['fixture_id'],
			(int) ( $manifest['ids']['organization_id'] ?? 0 ),
			(int) ( $manifest['ids']['clinic_id'] ?? 0 )
		);
		$this->env->maybe_inject_fault(
			'scoped_ownership:after-open',
			array( 'fixture_id' => (string) $manifest['fixture_id'] )
		);
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

		// Prove the sealed baseline actually survived the write before discarding
		// the pending copy. save() already reads the record back, but the baseline
		// is the one field the recovery path cannot reconstruct, so it is asserted
		// explicitly on a fresh load (which also re-verifies the HMAC and the site
		// fingerprint).
		$reloaded = $this->scoped_manifest->load();
		if ( ! is_array( $reloaded ) || array() === (array) ( $reloaded['baseline'] ?? array() ) ) {
			throw new Exception( 'OPTION_WRITE_FAILED', 'پایهٔ مرجع در فایل مالکیت تأیید نشد؛ عملیات متوقف شد.' );
		}
		// Only now is the pending copy redundant.
		$this->scoped_manifest->clear_pending_baseline();

		$this->enforce_portal_clinic_policy( $manifest );

		$result['manifest'] = $manifest;
		return $result;
	}

	/**
	 * Remove the portal's create-Clinic authority from the synthetic Organization
	 * manager, and prove it was removed.
	 *
	 * WHY: the portal legitimately creates additional Clinics under an
	 * Organization. In a scoped demo that is exactly the data-safety hazard this
	 * work addresses, because such a Clinic would be indistinguishable from a
	 * genuine non-demo Clinic. The authority is therefore withheld from the
	 * portal-facing synthetic persona for the lifetime of the demo, using the
	 * production capability resolver (DENY > GRANT > preset), and the resulting
	 * decision is read back through that resolver. The row is additive and lives
	 * inside the fixture (it hangs off a membership of the demo Organization), so
	 * the teardown owns and removes it.
	 *
	 * Everything else the persona needs - reading the Organization, Clinic
	 * profile updates, and lifecycle transitions - is untouched.
	 *
	 * @param array<string,mixed> $manifest Sealed manifest.
	 * @return void
	 */
	private function enforce_portal_clinic_policy( array $manifest ): void {
		$ids     = (array) ( $manifest['ids'] ?? array() );
		$users   = (array) ( $ids['user_ids'] ?? array() );
		$org     = (int) ( $ids['organization_id'] ?? 0 );
		$manager = (int) ( $users['organization_manager'] ?? 0 );
		$admin   = (int) ( $manifest['admin_id'] ?? 0 );
		if ( $org <= 1 || $manager <= 1 ) {
			throw new Exception( 'PORTAL_POLICY', 'شناسه‌های سازمان/مدیر برای اعمال محدودیت درگاه مدیریت کامل نیستند.' );
		}
		$capability = 'cpms_org_clinic_create';
		$membership = $this->platform->fetch_row(
			'SELECT id FROM `' . $this->platform->table( 'cpms_organization_memberships' ) . '` WHERE organization_id = %d AND wp_user_id = %d LIMIT 1',
			array( $org, $manager )
		);
		if ( null === $membership ) {
			throw new Exception( 'PORTAL_POLICY', 'عضویت سازمانی مدیر نمایشی پیدا نشد؛ محدودیت ساخت درمانگاه تأیید نشد.' );
		}
		$membership_id = (int) ( $membership['id'] ?? 0 );
		$existing      = $this->platform->fetch_row(
			'SELECT id, effect FROM `' . $this->platform->table( 'cpms_organization_membership_capabilities' ) . '` WHERE membership_id = %d AND capability = %s LIMIT 1',
			array( $membership_id, $capability )
		);
		if ( null === $existing ) {
			$this->platform->insert_row(
				'cpms_organization_membership_capabilities',
				array(
					'membership_id'          => $membership_id,
					'capability'             => $capability,
					'effect'                 => 'deny',
					'created_by_wp_user_id'  => $admin > 0 ? $admin : null,
					'created_at'             => $this->env->now_sql(),
				)
			);
		} elseif ( 'deny' !== (string) ( $existing['effect'] ?? '' ) ) {
			throw new Exception( 'PORTAL_POLICY', 'برای مدیر نمایشی مجوز ساخت درمانگاه از قبل فعال است؛ محدودیت اعمال نشد.' );
		}

		// Read the policy back through the row AND through the production resolver:
		// a stored row that the resolver does not honour is not a policy.
		$stored = $this->platform->fetch_row(
			'SELECT id, effect FROM `' . $this->platform->table( 'cpms_organization_membership_capabilities' ) . '` WHERE membership_id = %d AND capability = %s LIMIT 1',
			array( $membership_id, $capability )
		);
		if ( null === $stored || 'deny' !== (string) ( $stored['effect'] ?? '' ) ) {
			throw new Exception( 'PORTAL_POLICY', 'محدودیت ساخت درمانگاه از درگاه مدیریت ذخیره و تأیید نشد؛ عملیات متوقف شد.' );
		}
		if ( $this->platform->production_can( $manager, $org, $capability ) ) {
			throw new Exception( 'PORTAL_POLICY', 'production capability resolver still allows the synthetic Organization manager to create Clinics.' );
		}
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
