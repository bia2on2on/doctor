<?php
/**
 * Demo-owned ADDITIONAL Clinics for the scoped (non-empty-site) demo.
 *
 * POLICY (Stage 2 of the ownership work)
 * --------------------------------------
 * In scoped demo mode the Management Portal may NOT create additional Clinics:
 * the synthetic Organization manager is given an explicit DENY on
 * `cpms_org_clinic_create` (written and read back by the provisioner) and any
 * portal POST of `org_create_clinic` is refused at the HTTP boundary by
 * ScopedAdmin::guard_portal_clinic_creation(). Both are server-side. Outside
 * scoped demo mode neither exists, so normal production behaviour is unchanged.
 *
 * When a demo session does need a second Clinic (to prove lifecycle and
 * teardown behaviour on more than one tenant boundary), it is created here:
 *
 *   1. the exact slug is sealed into the ownership ledger as an INTENT, before
 *      any row can exist, together with a random token;
 *   2. the Clinic is created by the REAL production provisioning service on
 *      behalf of the authenticated administrator who submitted the demo's own
 *      form, after that account was designated an Organization manager through
 *      the production service (the portal-facing persona is never promoted);
 *   3. the resulting Clinic id and its Locations are sealed back into the
 *      ledger, closing the intent.
 *
 * A Clinic is therefore demo-owned only when its id is sealed, or when it
 * matches a slug that was sealed before it could exist. An interrupted run
 * leaves a pending intent, which blocks the destructive teardown until an
 * administrator either registers the Clinic it names (only possible when the
 * live row matches the sealed Organization AND slug) or abandons the intent
 * after proving no such row exists.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Logical grouping is clearer than column alignment in these ownership maps.

/**
 * Creation and repair of explicitly registered demo-owned Clinics.
 */
final class ScopedClinics {

	/** Human label of the additional Clinic (synthetic). */
	public const EXTRA_NAME = 'درمانگاه نمایشی دوم';

	/** Human label of its first Location (synthetic). */
	public const EXTRA_LOCATION = 'شعبهٔ نمایشی دوم';

	/** Synthetic address (never a real address). */
	public const EXTRA_ADDRESS = 'نشانی کاملاً فرضی؛ هیچ نشانی واقعی نیست';

	/** @var Env */
	private Env $env;

	/** @var Platform */
	private Platform $platform;

	/** @var ScopedManifest */
	private ScopedManifest $manifest;

	/** @var ScopedOwnership */
	private ScopedOwnership $ownership;

	/**
	 * @param Env            $env       Environment.
	 * @param Platform       $platform  Adapter.
	 * @param ScopedManifest $manifest  Scoped manifest.
	 * @param ScopedOwnership $ownership Ownership ledger.
	 */
	public function __construct( Env $env, Platform $platform, ScopedManifest $manifest, ScopedOwnership $ownership ) {
		$this->env       = $env;
		$this->platform  = $platform;
		$this->manifest  = $manifest;
		$this->ownership = $ownership;
	}

	/**
	 * Create one additional Clinic that is provably demo-owned.
	 *
	 * @param array<string,mixed> $manifest Verified manifest (status `active`).
	 * @param int                 $admin_id Acting Administrator (sealed as creator).
	 * @return array{clinic_id:int,slug:string,token:string,location_ids:list<int>,name:string}
	 */
	public function create( array $manifest, int $admin_id ): array {
		$context = $this->context( $manifest );
		$fixture = $context['fixture'];
		$org     = $context['organization_id'];

		// A pending intent means a previous run stopped between "slug sealed" and
		// "Clinic registered". Creating another one on top of that would make the
		// evidence ambiguous, so it is refused until it is resolved.
		$summary = $this->ownership->summary( $fixture );
		if ( ! $summary['ok'] ) {
			throw new Exception( 'SCOPED_OWNERSHIP_LEDGER_INVALID', 'دفتر مالکیت دمو معتبر نیست؛ ساخت درمانگاه نمایشی متوقف شد. ' . $summary['detail'] );
		}
		if ( $summary['organization_id'] !== $org || array() === $summary['clinic_ids'] ) {
			throw new Exception( 'SCOPED_OWNERSHIP_LEDGER_INVALID', 'دفتر مالکیت با سازمان این دمو هم‌خوان نیست؛ ساخت درمانگاه نمایشی متوقف شد.' );
		}
		if ( array() !== $summary['pending'] ) {
			throw new Exception( 'SCOPED_OWNERSHIP_PENDING', 'یک ثبت ناتمام از اجرای قبلی باقی مانده است؛ ابتدا آن را تعیین تکلیف کنید.' );
		}

		$token = bin2hex( random_bytes( 12 ) );
		$slug  = $this->manifest->fixture_prefix() . 'x-' . $token;
		// The slug must be free BEFORE it is sealed as an intent: otherwise an
		// existing (possibly foreign) row could later be mistaken for ours.
		if ( null !== $this->platform->fetch_row( 'SELECT id FROM `' . $this->platform->table( 'cpms_clinics' ) . '` WHERE slug = %s LIMIT 1', array( $slug ) ) ) {
			throw new Exception( 'SCOPED_EXTRA_CLINIC_FAILED', 'شناسهٔ یکتای درمانگاه نمایشی از قبل استفاده شده است؛ ساخت متوقف شد.' );
		}

		$this->ownership->append(
			$fixture,
			'intent',
			array(
				'token'           => $token,
				'slug'            => $slug,
				'organization_id' => $org,
				'name'            => self::EXTRA_NAME,
				'actor_id'        => $admin_id,
			)
		);
		$this->env->maybe_inject_fault( 'scoped_ownership:after-intent', array( 'token' => $token ) );

		try {
			// The portal-facing Organization manager deliberately CANNOT create
			// Clinics in scoped mode, so this path never borrows its authority and
			// never weakens the DENY. The authenticated administrator who submitted
			// this form is designated an Organization manager of the demo
			// Organization through the production service, and the Clinic is then
			// created by the REAL provisioning service with that account's own
			// authority. Every persona therefore keeps its exact production role,
			// and the membership row this adds lives inside the demo tenant.
			$membership_id = $this->platform->set_manager( $context['manager_id'], $org, $admin_id, true );
			// The administrator is a REAL account, so the membership row this adds
			// can never be attributed by a structural rule. It is therefore
			// registered explicitly, before the Clinic exists: an interrupted run
			// leaves a registered row and a pending intent, never a row the teardown
			// would have to guess about.
			$this->ownership->register_rows(
				$fixture,
				'cpms_organization_memberships',
				array( $membership_id ),
				array( 'source' => 'extra-clinic', 'actor_id' => $admin_id )
			);
			$created = $this->platform->provision_clinic(
				$admin_id,
				$org,
				self::EXTRA_NAME,
				$slug,
				'Asia/Tehran',
				self::EXTRA_LOCATION,
				self::EXTRA_ADDRESS
			);
		} catch ( \Throwable $error ) {
			$this->abandon_if_absent( $fixture, $token, $slug );
			throw new Exception( 'SCOPED_EXTRA_CLINIC_FAILED', 'ساخت درمانگاه نمایشی دوم ناموفق بود: ' . $error->getMessage() );
		}

		$clinic_id = (int) ( $created['clinic_id'] ?? 0 );
		$this->env->maybe_inject_fault( 'scoped_ownership:after-create', array( 'clinic_id' => $clinic_id ) );
		if ( $clinic_id <= 1 ) {
			$this->abandon_if_absent( $fixture, $token, $slug );
			throw new Exception( 'SCOPED_EXTRA_CLINIC_FAILED', 'شناسهٔ درمانگاه نمایشی دوم معتبر نیست; ساخت متوقف شد.' );
		}

		return $this->register( $fixture, $token, $clinic_id, $org, $slug );
	}

	/**
	 * Close an interrupted intent from sealed evidence only.
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @param string              $token    Intent token from the sealed ledger.
	 * @param bool                $claim    True to register the matching live Clinic,
	 *                                      false to abandon the intent.
	 * @return array{decision:string,clinic_id:int,slug:string,token:string,location_ids:list<int>,name:string}
	 */
	public function resolve( array $manifest, string $token, bool $claim ): array {
		$context = $this->context( $manifest );
		$fixture = $context['fixture'];
		$org     = $context['organization_id'];
		$summary = $this->ownership->summary( $fixture );
		if ( ! $summary['ok'] ) {
			throw new Exception( 'SCOPED_OWNERSHIP_LEDGER_INVALID', 'دفتر مالکیت دمو معتبر نیست؛ تعیین تکلیف متوقف شد. ' . $summary['detail'] );
		}
		$intent = null;
		foreach ( $summary['pending'] as $candidate ) {
			if ( (string) $candidate['token'] === $token ) {
				$intent = $candidate;
			}
		}
		if ( ! is_array( $intent ) || '' === (string) $intent['slug'] || (int) $intent['organization_id'] !== $org ) {
			throw new Exception( 'SCOPED_OWNERSHIP_PENDING', 'قصد ناتمامی با این شناسه در دفتر مالکیت وجود ندارد.' );
		}

		$slug  = (string) $intent['slug'];
		$found = $this->platform->fetch_row(
			'SELECT id, organization_id, name FROM `' . $this->platform->table( 'cpms_clinics' ) . '` WHERE slug = %s LIMIT 1',
			array( $slug )
		);
		$live_id = is_array( $found ) ? (int) ( $found['id'] ?? 0 ) : 0;
		$live_in_demo_org = is_array( $found ) && (int) ( $found['organization_id'] ?? 0 ) === $org;

		if ( $claim ) {
			if ( $live_id <= 1 || ! $live_in_demo_org ) {
				throw new Exception( 'SCOPED_OWNERSHIP_PENDING', 'درمانگاه متناظر با این قصد در سازمان دمو وجود ندارد؛ ثبت مالکیت ممکن نیست.' );
			}

			return $this->register( $fixture, $token, $live_id, $org, $slug );
		}

		// Abandoning is only safe when the pre-committed slug provably has no row.
		// A row that matches it is either ours or an impersonation attempt; neither
		// may be discarded silently.
		if ( $live_id > 1 ) {
			throw new Exception( 'SCOPED_OWNERSHIP_PENDING', 'ردیفی با شناسهٔ یکتای این قصد وجود دارد؛ لغو کردن آن امن نیست و باید ثبت شود.' );
		}
		$this->ownership->append(
			$fixture,
			'abandon',
			array(
				'token' => $token,
				'slug'  => $slug,
			)
		);

		return array(
			'decision'     => 'abandoned',
			'clinic_id'    => 0,
			'slug'         => $slug,
			'token'        => $token,
			'location_ids' => array(),
			'name'         => '',
		);
	}

	/**
	 * Abandon a just-failed intent, but only when no Clinic carries its slug.
	 *
	 * @param string $fixture Fixture identity.
	 * @param string $token   Intent token.
	 * @param string $slug    Sealed slug.
	 * @return void
	 */
	private function abandon_if_absent( string $fixture, string $token, string $slug ): void {
		$found = $this->platform->fetch_row(
			'SELECT id FROM `' . $this->platform->table( 'cpms_clinics' ) . '` WHERE slug = %s LIMIT 1',
			array( $slug )
		);
		if ( null !== $found ) {
			// Something exists with the sealed slug: leave the intent pending so the
			// teardown refuses and an administrator decides, never guesses.
			return;
		}
		try {
			$this->ownership->append(
				$fixture,
				'abandon',
				array(
					'token' => $token,
					'slug'  => $slug,
				)
			);
		} catch ( \Throwable $error ) {
			// The intent stays pending; the teardown refuses. That is the safe side.
			unset( $error );
		}
	}

	/**
	 * Seal the created Clinic (and its Locations) into the ledger.
	 *
	 * @param string $fixture   Fixture identity.
	 * @param string $token     Intent token.
	 * @param int    $clinic_id Created Clinic.
	 * @param int    $org       Demo Organization.
	 * @param string $slug      Sealed slug.
	 * @return array{clinic_id:int,slug:string,token:string,location_ids:list<int>,name:string,decision:string}
	 */
	private function register( string $fixture, string $token, int $clinic_id, int $org, string $slug ): array {
		$clinic = $this->platform->fetch_row(
			'SELECT id, organization_id, name FROM `' . $this->platform->table( 'cpms_clinics' ) . '` WHERE id = %d LIMIT 1',
			array( $clinic_id )
		);
		if ( ! is_array( $clinic ) || (int) ( $clinic['organization_id'] ?? 0 ) !== $org ) {
			throw new Exception( 'SCOPED_EXTRA_CLINIC_FAILED', 'درمانگاه نمایشی به سازمان دمو تعلق ندارد؛ ثبت مالکیت متوقف شد.' );
		}
		$location_ids = array();
		if ( $this->platform->table_exists( 'cpms_locations' ) ) {
			$rows = $this->platform->fetch_all(
				'SELECT id FROM `' . $this->platform->table( 'cpms_locations' ) . '` WHERE clinic_id = %d ORDER BY id ASC',
				array( $clinic_id )
			);
			foreach ( $rows as $row ) {
				$location_ids[] = (int) ( $row['id'] ?? 0 );
			}
		}
		$this->ownership->append(
			$fixture,
			'clinic',
			array(
				'token'        => $token,
				'clinic_id'    => $clinic_id,
				'location_ids' => array_values( array_filter( array_map( 'intval', $location_ids ) ) ),
			)
		);

		return array(
			'decision'     => 'registered',
			'clinic_id'    => $clinic_id,
			'slug'         => $slug,
			'token'        => $token,
			'location_ids' => array_values( array_filter( array_map( 'intval', $location_ids ) ) ),
			'name'         => (string) ( $clinic['name'] ?? '' ),
		);
	}

	/**
	 * Validate the demo context every operation depends on.
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @return array{fixture:string,organization_id:int,manager_id:int}
	 */
	private function context( array $manifest ): array {
		if ( ! $this->manifest->mode_enabled() ) {
			throw new Exception( 'MODE_DISABLED', 'حالت محدودشده فعال نیست.' );
		}
		$status = (string) ( $manifest['status'] ?? '' );
		if ( 'active' !== $status ) {
			throw new Exception( 'DEMO_NOT_ACTIVE', 'دموی فعالی وجود ندارد؛ ساخت درمانگاه نمایشی مجاز نیست.' );
		}
		$ids     = (array) ( $manifest['ids'] ?? array() );
		$users   = (array) ( $ids['user_ids'] ?? array() );
		$fixture = (string) ( $manifest['fixture_id'] ?? '' );
		$org     = (int) ( $ids['organization_id'] ?? 0 );
		$manager = (int) ( $users['organization_manager'] ?? 0 );
		if ( '' === $fixture || $org <= 1 || $manager <= 1 ) {
			throw new Exception( 'DEMO_NOT_ACTIVE', 'شناسه‌های لازم برای ساخت درمانگاه نمایشی در فایل مالکیت نیستند.' );
		}

		return array(
			'fixture'         => $fixture,
			'organization_id' => $org,
			'manager_id'      => $manager,
		);
	}
}
