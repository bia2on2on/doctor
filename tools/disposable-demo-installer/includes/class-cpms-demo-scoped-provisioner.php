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
		$result['manifest'] = $this->seal_fixture( $result['manifest'], $this->pending_baseline, $this->pending_host );

		return $result;
	}

	/**
	 * Finish a fixture whose interruption was provably confined to the seal.
	 *
	 * This is the ONLY automatic recovery the scoped mode offers. It refuses
	 * unless `ScopedRecovery` proves - from the HMAC-sealed manifest, the sealed
	 * baseline and the sealed ownership ledger - that every synthetic row of every
	 * earlier stage exists with the exact ids the manifest recorded, so nothing is
	 * re-created and nothing is adopted from a name, an e-mail domain or a slug
	 * that merely looks similar.
	 *
	 * The operation is additive and idempotent: it opens the ownership ledger if
	 * it was never opened, collects the exact owned scope, records the audit-chain
	 * state, writes `active`, verifies the result by reading it back, and then
	 * applies the portal Clinic policy. Running it twice is a no-op the second
	 * time, because the second run is no longer `resumable` - it is `active`.
	 *
	 * @param int $admin_id Acting Administrator.
	 * @return array{manifest:array<string,mixed>,credentials:array<string,string>}
	 * @throws Exception When the fixture is not provably resumable.
	 */
	public function finish( int $admin_id ): array {
		return $this->env->with_lock(
			'provision',
			function () use ( $admin_id ): array {
				$manifest = $this->scoped_manifest->load();
				if ( ! is_array( $manifest ) ) {
					throw new Exception( 'NO_DEMO', 'سند مالکیت دمو برای ادامه پیدا نشد.' );
				}
				// Re-assessed INSIDE the lock: the state that was rendered to the
				// operator must still be the state on disk when the write happens.
				$assessment = ( new ScopedRecovery( $this->env, $this->platform, $this->scoped_manifest ) )->assess( $manifest );
				if ( ScopedRecovery::VERDICT_ACTIVE === $assessment['verdict'] ) {
					throw new Exception( 'ALREADY_PROVISIONED', 'این دمو از قبل کامل و مهرشده است؛ ادامه لازم نیست. برای دیدن درگاه‌ها از همین صفحه استفاده کنید.' );
				}
				if ( ScopedRecovery::VERDICT_RESUMABLE !== $assessment['verdict'] ) {
					throw new Exception(
						'' !== (string) $assessment['code'] ? (string) $assessment['code'] : 'RECOVERY_NOT_SAFE',
						(string) $assessment['reason']
					);
				}

				$fixture = (string) ( $manifest['fixture_id'] ?? '' );
				$pending = $this->scoped_manifest->load_pending_baseline( $fixture );
				$baseline = (array) ( $manifest['baseline'] ?? array() );
				if ( array() === $baseline && is_array( $pending ) ) {
					$baseline = (array) ( $pending['baseline'] ?? array() );
				}
				if ( array() === $baseline ) {
					throw new Exception( 'RECOVERY_NO_BASELINE', 'پایهٔ مرجع برای ادامه پیدا نشد؛ ادامه متوقف شد.' );
				}
				$host_before = (array) ( $manifest['host_before'] ?? array() );
				if ( array() === $host_before && is_array( $pending ) ) {
					$host_before = (array) ( $pending['host_before'] ?? array() );
				}

				$manifest = $this->seal_fixture( $manifest, $baseline, $host_before );

				// Additive audit evidence that this fixture was finished by an
				// authorized recovery instead of a first run. Audit rows are never
				// deleted, and this one names no secret.
				$this->platform->audit(
					$this->scoped_manifest->audit_action(),
					$admin_id,
					(int) ( $manifest['ids']['clinic_id'] ?? 0 ),
					array(
						'fixture_id' => $fixture,
						'recovered'  => true,
						'stage'      => (string) ( $manifest['failed_stage'] ?? '' ),
					)
				);

				return array(
					'manifest'    => $manifest,
					'credentials' => array(),
				);
			}
		);
	}

	/**
	 * Seal a fixture whose synthetic rows already exist.
	 *
	 * ONE implementation for the first run and for a resumed run, so the two
	 * paths can never drift. Ownership of the fixture is recorded explicitly,
	 * never inferred later from "it names the demo Organization and has a higher
	 * id". The ledger is opened FIRST, because the scope collected below must
	 * already see it: a sealed, active fixture is only trustworthy while its
	 * ledger exists.
	 *
	 * @param array<string,mixed> $manifest    Fixture manifest.
	 * @param array<string,mixed> $baseline    Sealed pre-provisioning baseline.
	 * @param array<string,mixed> $host_before Host counters captured before the run.
	 * @return array<string,mixed> Sealed, active manifest.
	 */
	private function seal_fixture( array $manifest, array $baseline, array $host_before ): array {
		$manifest['mode']        = 'scoped';
		$manifest['baseline']    = $baseline;
		$manifest['host_before'] = $host_before;
		$manifest['status']      = 'active';
		if ( (string) ( $manifest['sealed_at'] ?? '' ) === '' ) {
			$manifest['sealed_at'] = $this->env->now_sql();
		}
		$clinic_id = (int) ( $manifest['ids']['clinic_id'] ?? 0 );
		if ( (string) ( $manifest['shortcode'] ?? '' ) === '' && $clinic_id > 0 ) {
			$manifest['shortcode'] = $this->platform->booking_shortcode( $clinic_id );
		}
		$manifest['counts']['future_slots'] = (int) ( $manifest['counts']['future_slots'] ?? count( (array) ( $manifest['ids']['slot_ids'] ?? array() ) ) );
		$manifest['failed_stage']           = null;
		$manifest['failure_code']           = null;
		$manifest['failure_message']        = null;

		$ownership = new ScopedOwnership( $this->env, $this->scoped_manifest );
		if ( ! $ownership->exists() ) {
			$ownership->open(
				(string) $manifest['fixture_id'],
				(int) ( $manifest['ids']['organization_id'] ?? 0 ),
				$clinic_id
			);
		}
		$this->env->maybe_inject_fault(
			'scoped_ownership:after-open',
			array( 'fixture_id' => (string) $manifest['fixture_id'] )
		);
		$teardown = new ScopedTeardown( $this->env, $this->platform, $this->scoped_manifest, $this->audit_guard, $this->baseline );
		$scope    = $teardown->collect_scope( $manifest, $baseline );
		$manifest['owned'] = array(
			'tables'           => $scope['tables'],
			'jobs'             => $scope['jobs'],
			'operational_logs' => $scope['operational_logs'],
			'audit_logs'       => $scope['audit_logs'],
			'rate_limit_keys'  => $scope['rate_limit_keys'],
		);
		$manifest['audit_chain_at_seal'] = $this->audit_guard->verify_chain();
		$manifest = $this->scoped_manifest->save( $manifest );
		$this->env->maybe_inject_fault(
			'scoped_provision:after_seal_save',
			array( 'fixture_id' => (string) $manifest['fixture_id'] )
		);

		// Prove the sealed record actually survived the write before discarding
		// the pending copy. save() already reads the record back, but the seal is
		// the one payload the recovery path cannot reconstruct, so it is asserted
		// explicitly on a fresh load (which also re-verifies the HMAC and the site
		// fingerprint).
		$reloaded = $this->scoped_manifest->load();
		if ( ! is_array( $reloaded ) || array() === (array) ( $reloaded['baseline'] ?? array() ) ) {
			throw new Exception( 'OPTION_WRITE_FAILED', 'پایهٔ مرجع در فایل مالکیت تأیید نشد؛ عملیات متوقف شد.' );
		}
		// The scope map is what a teardown uses to prove what it may delete, so a
		// record that lost it is not a finished seal either.
		if ( array() === (array) ( $reloaded['owned'] ?? array() ) ) {
			throw new Exception( 'OPTION_WRITE_FAILED', 'نقشهٔ مالکیت در فایل مهرشده تأیید نشد؛ عملیات متوقف شد.' );
		}
		// The sealed audit-chain state is the third piece of the seal. A record
		// without it reports `sealed=false` to the recovery verdict, so finishing
		// here and claiming success would leave a fixture that can never be torn
		// down cleanly.
		if ( empty( $reloaded['audit_chain_at_seal'] ) ) {
			throw new Exception( 'OPTION_WRITE_FAILED', 'وضعیت زنجیرهٔ حسابرسی در فایل مهرشده تأیید نشد؛ عملیات متوقف شد.' );
		}

		// The portal policy is enforced BEFORE the duplicate baseline copy is
		// dropped. Ordering is evidence-preservation, not style: this method can
		// throw (a resolver that still grants Clinic creation, a missing
		// membership row, a refused write), and while the pending record exists
		// the run stays recoverable no matter which of the recoverable paths the
		// operator needs. Discarding it first and failing after is precisely how
		// the sealed evidence of a real install can disappear (see
		// Provisioner::record_failure()).
		$this->enforce_portal_clinic_policy( $manifest );

		// Only now is the pending copy redundant.
		$this->scoped_manifest->clear_pending_baseline();
		$this->env->maybe_inject_fault(
			'scoped_provision:after_seal_clear',
			array( 'fixture_id' => (string) $manifest['fixture_id'] )
		);

		return $manifest;
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

	/**
	 * Activate a complete fixture whose sealed baseline is gone.
	 *
	 * NOT a seal, and deliberately not written as one: no `owned` scope map and
	 * no `audit_chain_at_seal` are invented, so every gate that needs a complete
	 * seal keeps refusing. What is added is exactly:
	 *
	 *   - the ownership ledger record (org + clinic ids the sealed manifest
	 *     names), if it was never opened;
	 *   - `status: active` plus an `activation` block naming the reason, the
	 *     actor, the time and a fingerprint of the evidence that justified it;
	 *   - one append-only audit row.
	 *
	 * Idempotent: a second call finds the activation already recorded with the
	 * same evidence and returns the manifest unchanged (`state: already`).
	 *
	 * @param int $admin_id Acting Administrator.
	 * @return array{state:string,manifest:array<string,mixed>}
	 * @throws Exception When the fixture is not provably ours, or not complete.
	 */
	public function activate( int $admin_id ): array {
		return $this->env->with_lock(
			'activate',
			function () use ( $admin_id ): array {
				$manifest = $this->scoped_manifest->load();
				if ( ! is_array( $manifest ) ) {
					throw new Exception( 'NO_DEMO', 'سند مالکیت دمو برای فعال‌سازی پیدا نشد.' );
				}
				// Re-assessed INSIDE the lock: the state shown to the operator must
				// still be the state on disk when the write happens.
				$assessment = ( new ScopedRecovery( $this->env, $this->platform, $this->scoped_manifest ) )->assess( $manifest );
				if ( ScopedRecovery::VERDICT_ACTIVE === $assessment['verdict'] ) {
					return array( 'state' => 'already', 'manifest' => $manifest );
				}
				if ( ScopedRecovery::VERDICT_USABLE === $assessment['verdict'] && array() !== (array) ( $manifest['activation'] ?? array() ) ) {
					return array( 'state' => 'already', 'manifest' => $manifest );
				}
				if ( ScopedRecovery::VERDICT_USABLE !== $assessment['verdict'] ) {
					throw new Exception(
						'' !== (string) $assessment['code'] ? (string) $assessment['code'] : 'ACTIVATION_NOT_SAFE',
						(string) $assessment['reason']
					);
				}
				$ids     = (array) ( $manifest['ids'] ?? array() );
				$fixture = (string) ( $manifest['fixture_id'] ?? '' );
				$org     = (int) ( $ids['organization_id'] ?? 0 );
				$clinic  = (int) ( $ids['clinic_id'] ?? 0 );

				// The ledger proves which Clinics this fixture owns. Opening it is
				// additive and is refused if it already carries another fixture's
				// chain; that refusal is the correct answer, not a fallback.
				$ownership = new ScopedOwnership( $this->env, $this->scoped_manifest );
				if ( ! $ownership->exists() ) {
					$ownership->open( $fixture, $org, $clinic );
				}

				// The failure that made the repair necessary is kept, not erased: the
				// append-only `failure_history` already holds every attempt, and this
				// names the one that was repaired.
				$previous_failure = array(
					'stage' => (string) ( $manifest['failed_stage'] ?? '' ),
					'code'  => (string) ( $manifest['failure_code'] ?? '' ),
				);

				$payload                            = $manifest;
				$payload['status']                  = 'active';
				$payload['activation']              = array(
					'mode'       => 'baseline_unavailable',
					'at'         => $this->env->now_sql(),
					'actor_id'   => $admin_id,
					'evidence'   => $this->evidence_fingerprint( $manifest ),
					'row_effect' => 'none',
				);
				$payload['repaired_from_failure']   = $previous_failure;
				$payload['failed_stage']            = null;
				$payload['failure_code']            = null;
				$payload['failure_message']         = null;

				$saved = $this->scoped_manifest->save( $payload );
				// Prove the write before anything else observes it: a marker that
				// did not survive is not an activation.
				if ( 'active' !== (string) ( $saved['status'] ?? '' ) || array() === (array) ( $saved['activation'] ?? array() ) ) {
					throw new Exception( 'OPTION_WRITE_FAILED', 'نشانگر فعال‌سازی ذخیره و تأیید نشد؛ عملیات متوقف شد.' );
				}

				$this->platform->audit(
					'CPSD_DEMO_ACTIVATED_WITHOUT_BASELINE',
					$admin_id,
					$clinic,
					array(
						'fixture_id'       => $fixture,
						'evidence'         => (string) $saved['activation']['evidence'],
						'teardown_blocked' => 'BASELINE_MISSING',
						'row_effect'       => 'none',
					)
				);

				return array( 'state' => 'repaired', 'manifest' => $saved );
			}
		);
	}

	/**
	 * The single action an Owner needs: make the eight portals reachable.
	 *
	 * It decides from sealed evidence, never from the request:
	 *   ACTIVE    -> nothing to repair;
	 *   RESUMABLE -> the same additive seal `finish()` performs;
	 *   USABLE    -> the additive activation `activate()` performs;
	 *   anything else -> refused with the stable code, and the UI offers the two
	 *   safe alternatives (park + fresh demo, or an authorized teardown).
	 *
	 * Then it rotates the eight demo passwords once, because they are never
	 * stored and the run that created them may never have shown them. Rotation
	 * touches no data row: it sets a new password per synthetic persona and
	 * invalidates those personas' old sessions.
	 *
	 * @param int $admin_id Acting Administrator.
	 * @return array{state:string,manifest:array<string,mixed>,credentials:array<string,string>}
	 * @throws Exception On any refusal.
	 */
	public function launch( int $admin_id ): array {
		$manifest = $this->scoped_manifest->load();
		if ( ! is_array( $manifest ) ) {
			throw new Exception( 'NO_DEMO', 'سند مالکیت دمو پیدا نشد.' );
		}
		$assessment = ( new ScopedRecovery( $this->env, $this->platform, $this->scoped_manifest ) )->assess( $manifest );
		$state      = 'already';

		if ( ScopedRecovery::VERDICT_RESUMABLE === $assessment['verdict'] ) {
			// `finish()` re-assesses under the provisioning lock.
			$manifest = (array) $this->finish( $admin_id )['manifest'];
			$state    = 'resumed';
		} elseif ( ScopedRecovery::VERDICT_USABLE === $assessment['verdict'] ) {
			// `activate()` re-assesses under its own lock and is idempotent.
			$activation = $this->activate( $admin_id );
			$manifest   = (array) $activation['manifest'];
			$state      = 'repaired' === (string) $activation['state'] ? 'repaired' : 'already';
		} elseif ( ScopedRecovery::VERDICT_ACTIVE !== $assessment['verdict'] ) {
			throw new Exception(
				'' !== (string) $assessment['code'] ? (string) $assessment['code'] : 'LAUNCH_NOT_SAFE',
				(string) $assessment['reason']
			);
		}

		// Rotation requires an active fixture and re-verifies every persona's id,
		// login and non-administrator status before touching a password.
		$credentials = $this->credentials->rotate( $manifest, 'cpsd-' );

		return array(
			'state'       => $state,
			'manifest'    => $manifest,
			'credentials' => $credentials,
		);
	}

	/**
	 * Park an unresolvable fixture, then provision a fresh, isolated one.
	 *
	 * WHY PARKING IS NOT DELETION
	 * ---------------------------
	 * The old fixture may be incomplete in a way no sealed record can repair.
	 * Its rows must still never be adopted (a new fixture proves its own rows by
	 * its own 128-bit namespace), never modified, and never deleted without the
	 * evidence a teardown needs. So the whole sealed record - manifest plus the
	 * ownership ledger chain and its ratchet - is copied into a sealed
	 * `cpsd_preserved` record, the LIVE pointers (`cpsd_manifest` and the ledger
	 * options) are removed so a new fixture can have its own, and nothing else is
	 * touched. The parked copy is the input to a later, explicit, human-reviewed
	 * resolution; this class offers no code path that deletes it.
	 *
	 * Idempotency and refusal:
	 *   - refuses when the live manifest does not verify (never park a tampered
	 *     record as if it were evidence);
	 *   - refuses when the fixture is fully ACTIVE and sealed, because parking a
	 *     working demo would only lose the ability to tear it down;
	 *   - parking the same fixture twice returns the existing record unchanged.
	 *
	 * @param int $admin_id Acting Administrator.
	 * @return array{manifest:array<string,mixed>,credentials:array<string,string>,preserved:array<string,mixed>,previous_fixture:string}
	 * @throws Exception On any refusal.
	 */
	public function preserve_and_reprovision( int $admin_id ): array {
		$previous = '';
		// The park step is its own critical section. It must NOT wrap the
		// provisioning call: `Env::with_lock()` is a single-slot guard, so a
		// nested acquisition is refused as a concurrent operation - correctly, and
		// the two steps really are sequential.
		$this->env->with_lock(
			'preserve',
			function () use ( $admin_id, &$previous ): void {
				$manifest = $this->scoped_manifest->load();
				if ( ! is_array( $manifest ) ) {
					throw new Exception( 'NO_DEMO', 'دموی قابل کنارگذاری وجود ندارد.' );
				}
				$assessment = ( new ScopedRecovery( $this->env, $this->platform, $this->scoped_manifest ) )->assess( $manifest );
				if ( ScopedRecovery::VERDICT_ACTIVE === $assessment['verdict'] ) {
					// A fully sealed fixture can be torn down, so abandoning it would
					// only lose that ability. Everything else - including a fixture that
					// is usable but can never be torn down because its baseline is gone -
					// may be parked: that is exactly the state parking exists for.
					throw new Exception( 'DEMO_IS_USABLE', 'این دمو کامل و مهرشده است و پاک‌سازی آن ممکن است؛ برای کنارگذاشتن، نخست پاک‌سازی مجاز را اجرا کنید. برای استفاده، دکمهٔ «آماده‌سازی و ورود به هشت درگاه» را بزنید.' );
				}
				if ( ScopedRecovery::VERDICT_RESUMABLE === $assessment['verdict'] ) {
					// Finishing it is additive and safe, so the page offers that first;
					// parking a completable fixture would throw away a clean teardown.
					throw new Exception( 'DEMO_IS_COMPLETABLE', 'این دمو با یک مرحلهٔ افزودنی کامل می‌شود و پاک‌سازی آن هم ممکن است؛ کنارگذاشتن آن لازم نیست. دکمهٔ «آماده‌سازی و ورود به هشت درگاه» را بزنید.' );
				}
				$previous = (string) ( $manifest['fixture_id'] ?? '' );
				$this->scoped_manifest->preserve(
					$manifest,
					'' !== (string) $assessment['code'] ? (string) $assessment['code'] : (string) $assessment['verdict'],
					$admin_id
				);
			}
		);

		$result                     = $this->provision( $admin_id );
		$result['previous_fixture'] = $previous;
		$result['preserved']        = (array) $this->scoped_manifest->preserved_record( $previous );

		return $result;
	}

	/**
	 * Stable fingerprint of the evidence an activation relies on.
	 *
	 * Derived from the sealed manifest's own identity, tenancy and account ids,
	 * so it changes if - and only if - the evidence changes. It contains no
	 * password, OTP, session or PHI.
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @return string
	 */
	private function evidence_fingerprint( array $manifest ): string {
		$ids   = (array) ( $manifest['ids'] ?? array() );
		$users = (array) ( $ids['user_ids'] ?? array() );
		ksort( $users );
		$slugs = (array) ( $manifest['slugs'] ?? array() );
		ksort( $slugs );
		$material = wp_json_encode(
			array(
				'fixture_id'      => (string) ( $manifest['fixture_id'] ?? '' ),
				'schema_version'  => (string) ( $manifest['schema_version'] ?? '' ),
				'organization_id' => (int) ( $ids['organization_id'] ?? 0 ),
				'clinic_id'       => (int) ( $ids['clinic_id'] ?? 0 ),
				'location_ids'    => array_map( 'intval', (array) ( $ids['location_ids'] ?? array() ) ),
				'user_ids'        => $users,
				'slugs'           => $slugs,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		return substr( hash( 'sha256', (string) $material ), 0, 32 );
	}
}
