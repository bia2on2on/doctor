<?php
/**
 * Read-only authority preview for the eight demo personas.
 *
 * WHY THIS EXISTS
 * ---------------
 * The Owner inspects the demo while signed in as their own WordPress
 * administrator. Two very different things must never be confused:
 *
 *   - "the administrator can SEE what a role is allowed to do" - a read-only
 *     description, computed by the production authorization engine;
 *   - "the administrator can act with that role's authority" - which would be a
 *     privilege escalation, and must never happen.
 *
 * WordPress's `manage_options` deliberately grants no clinical, financial, tenant
 * or messaging authority inside CPMS (AuthorizationService invariant 4). This class
 * therefore *reports* that fact instead of working around it: for each persona it
 * asks the production engines what that synthetic account may do, and it asks the
 * same question for the administrator, so the page can show the two columns side by
 * side and say plainly that the administrator's column is empty.
 *
 * It grants nothing, writes nothing, changes no session and caches nothing. Every
 * answer comes from `App::authorization_service()->can()` and
 * `OrganizationClinicProvisioningService::can()` - the same code that decides the
 * persona's real requests.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- Logical grouping is clearer than column alignment here.

/**
 * The production permission probe, rendered per persona and for the administrator.
 */
final class ScopedPreview {

	/**
	 * The domains the Owner actually asks about, each mapped to the production
	 * permission that decides it. These are not demo permissions: they are the
	 * plugin's own capability keys.
	 */
	public const DOMAINS = array(
		'clinical'  => array(
			'capability' => 'cpms_patient_read',
			'label'      => 'دادهٔ بالینی بیمار (خواندن)',
		),
		'financial' => array(
			'capability' => 'cpms_finance_read',
			'label'      => 'دادهٔ مالی و حسابداری',
		),
		'messaging' => array(
			'capability' => 'cpms_visit_read',
			'label'      => 'گفتگوی خصوصی کارکنان',
		),
		'tenant'    => array(
			// Organization-level authority, resolved by the provisioning service
			// rather than by the Clinic-scoped engine.
			'capability' => 'cpms_org_clinic_update',
			'label'      => 'مدیریت سازمان و درمانگاه',
		),
	);

	/** @var Env */
	private Env $env;

	/** @var Platform */
	private Platform $platform;

	/**
	 * @param Env      $env      Environment.
	 * @param Platform $platform Production adapter.
	 */
	public function __construct( Env $env, Platform $platform ) {
		$this->env      = $env;
		$this->platform = $platform;
	}

	/**
	 * Actual production decisions for one actor on the demo tenant.
	 *
	 * @param int                 $user_id  WordPress user id.
	 * @param array<string,mixed> $manifest Verified sealed manifest.
	 * @return array<string,bool> Domain => allowed, as decided by production.
	 */
	public function authority( int $user_id, array $manifest ): array {
		$clinic = (int) ( $manifest['ids']['clinic_id'] ?? 0 );
		$org    = (int) ( $manifest['ids']['organization_id'] ?? 0 );
		$result = array();
		foreach ( self::DOMAINS as $domain => $spec ) {
			$allowed = false;
			// The administrator is asked too, on purpose: if the production engine ever
			// granted a clinical permission to an account like this, the page must SAY so
			// rather than print a comfortable "no". Nothing is granted either way.
			if ( $user_id > 0 && $clinic > 1 ) {
				if ( 'tenant' === $domain ) {
					// Organization scope: an Organization id, not a Clinic id.
					$allowed = $org > 1 && $this->platform->production_can( $user_id, $org, (string) $spec['capability'] );
				} else {
					$allowed = $this->platform->clinical_can( $user_id, $clinic, (string) $spec['capability'] );
				}
			}
			$result[ $domain ] = (bool) $allowed;
		}

		return $result;
	}

	/**
	 * The whole picture: every sealed persona, plus the administrator's own column.
	 *
	 * @param int                 $admin_id Signed-in administrator.
	 * @param array<string,mixed> $manifest Verified sealed manifest.
	 * @return array{personas:list<array<string,mixed>>,admin:array<string,bool>,fixture:string}
	 */
	public function matrix( int $admin_id, array $manifest ): array {
		$users    = (array) ( $manifest['ids']['user_ids'] ?? array() );
		$personas = array();
		foreach ( Credentials::PERSONA_ORDER as $persona ) {
			$user_id = (int) ( $users[ $persona ] ?? 0 );
			$portal  = (array) ( ScopedAdmin::persona_portals()[ $persona ] ?? array() );
			$personas[] = array(
				'persona'   => $persona,
				'user_id'   => $user_id,
				'label'     => (string) ( Credentials::personas()[ $persona ]['label'] ?? $persona ),
				'portal'    => (string) ( $portal['portal'] ?? '' ),
				'hint'      => (string) ( $portal['hint'] ?? '' ),
				'authority' => $user_id > 1 ? $this->authority( $user_id, $manifest ) : array(),
			);
		}

		return array(
			'fixture'  => (string) ( $manifest['fixture_id'] ?? '' ),
			'personas' => $personas,
			'admin'    => $this->authority( $admin_id, $manifest ),
		);
	}

	/**
	 * One sentence a non-technical reader can check against the table.
	 *
	 * @param array<string,bool> $admin Authority of the administrator.
	 * @return string
	 */
	public static function admin_summary_fa( array $admin ): string {
		$granted = array();
		foreach ( self::DOMAINS as $domain => $spec ) {
			if ( ! empty( $admin[ $domain ] ) ) {
				$granted[] = '«' . $spec['label'] . '»';
			}
		}
		if ( array() === $granted ) {
			return 'حساب مدیرکل شما در این سایت هیچ اختیار بالینی، مالی، سازمانی یا پیام خصوصی ندارد و این بسته هم چنین اختیاری به آن نمی‌دهد. برای انجام عملیات واقعی، از نشست همان نقش نمایشی استفاده کنید.';
		}

		// Honest reporting: if the production engine DID grant something to this
		// account, the page says so instead of pretending otherwise.
		return 'توجه: موتور مجوز تولیدی به این حساب برای ' . implode( ' و ', $granted ) . ' اختیار داده است. این بسته چیزی به آن اضافه نکرده و چیزی هم از آن کم نمی‌کند؛ تصمیم واقعی همان موتور است.';
	}

	/**
	 * Render the matrix as a compact, RTL table.
	 *
	 * @param array<string,mixed> $matrix Result of matrix().
	 * @return void
	 */
	public static function render( array $matrix ): void {
		echo '<div class="cpdd-card" id="cpsd-authority" data-cpsd-authority="1"><h2>چه کسی اجازهٔ چه کاری دارد؟ (تصمیم موتور مجوز تولیدی)</h2>';
		echo '<p>این جدول فقط‌خواندنی است و هیچ اختیاری نمی‌دهد: هر خانه با همان سرویس مجوزدهی محصول پر شده است که درخواست واقعی همان نقش را هم تأیید یا رد می‌کند. ستون «مدیرکل» حساب خود شماست؛ اگر خالی است، یعنی مدیرکل بودن در این محصول اختیار بالینی/مالی ایجاد نمی‌کند.</p>';
		echo '<table class="widefat striped cpdd-authority"><thead><tr><th>نقش</th>';
		foreach ( self::DOMAINS as $spec ) {
			echo '<th>' . esc_html( (string) $spec['label'] ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( (array) ( $matrix['personas'] ?? array() ) as $row ) {
			echo '<tr data-cpsd-authority-persona="' . esc_attr( (string) $row['persona'] ) . '">';
			echo '<td><strong>' . esc_html( (string) $row['label'] ) . '</strong></td>';
			foreach ( array_keys( self::DOMAINS ) as $domain ) {
				$allowed = ! empty( $row['authority'][ $domain ] );
				echo '<td data-cpsd-allow="' . ( $allowed ? '1' : '0' ) . '">' . ( $allowed ? '✓ دارد' : '— ندارد' ) . '</td>';
			}
			echo '</tr>';
		}
		echo '<tr class="cpdd-authority-admin" data-cpsd-authority-admin="1">';
		echo '<td><strong>مدیرکل وردپرس (شما)</strong></td>';
		foreach ( array_keys( self::DOMAINS ) as $domain ) {
			$allowed = ! empty( $matrix['admin'][ $domain ] );
			echo '<td data-cpsd-admin-allow="' . ( $allowed ? '1' : '0' ) . '">' . ( $allowed ? '✓ دارد' : '— ندارد' ) . '</td>';
		}
		echo '</tr></tbody></table>';
		echo '<p class="cpdd-warn" data-cpsd-admin-summary="1">' . esc_html( self::admin_summary_fa( (array) ( $matrix['admin'] ?? array() ) ) ) . '</p>';
		echo '</div>';
	}
}
