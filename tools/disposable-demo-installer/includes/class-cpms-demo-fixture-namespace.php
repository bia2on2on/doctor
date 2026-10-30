<?php
/**
 * Read-only survey of the `cpsd-` fixture namespace on a populated host site.
 *
 * WHY THIS CLASS EXISTS
 * ---------------------
 * The scoped preflight used to treat the mere existence of ANY account whose
 * login starts with `cpsd-` as a blocking collision. On a real test site that
 * is the wrong question: the logins this package will create are
 * `cpsd-<persona>-<128-bit fixture id>`, so a pre-existing `cpsd-…` account can
 * only ever collide with a new run by an exact string match that the
 * provisioning service already refuses (`ScopedProvisioner::check_collisions()`,
 * `COLLISION`, before the first synthetic row exists).
 *
 * What the pre-existing rows DO need is an honest, visible report: they are not
 * this package's proof of ownership, they are never adopted by a new fixture,
 * they are never modified, and the teardown never deletes a row it cannot prove
 * (see `ScopedTeardown::delete_wp_records()`, which requires both the `cpsd-`
 * prefix and the sealed fixture id). This class answers exactly that question,
 * once, for both the preflight and the admin UI, so the two can never disagree.
 *
 * Everything here is read-only: no option, user, post, tenant row or audit row
 * is written, and no heuristic is ever promoted to ownership.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Logical grouping is clearer than column alignment in these ownership maps.

/**
 * Classification of pre-existing `cpsd-` artifacts.
 */
final class FixtureNamespace {

	/** Reserved demo prefix shared by both modes' fixture identities. */
	public const PREFIX = 'cpsd-';

	/**
	 * The two e-mail domains this package always writes for a synthetic persona.
	 *
	 * A login shape alone can be produced by anybody (it is just text), so the
	 * "probably left by an earlier run of this package" label additionally needs
	 * the e-mail domain. That label is a HINT for a human reviewer, never proof
	 * of ownership and never an authorization to delete.
	 */
	public const EMAIL_DOMAINS = array( '@demo.invalid', '@otp.cpms.local' );

	/**
	 * Tenant tables and the predicate that marks a namespace row as live.
	 *
	 * Only ACTIVE rows are counted, exactly as the scoped preflight did before:
	 * a previous clean teardown leaves suspended/archived parent rows behind as
	 * audit foreign-key anchors, and those are inert.
	 */
	public const TENANT_PREDICATES = array(
		'cpms_organizations' => "slug LIKE %s AND status = 'active'",
		'cpms_clinics'       => "slug LIKE %s AND status = 'active'",
		'cpms_locations'     => 'slug LIKE %s AND is_active = 1',
	);

	/**
	 * Does this login/email pair carry this package's synthetic persona signature?
	 *
	 * @param string $login User login.
	 * @param string $email User e-mail (may be empty).
	 * @return bool
	 */
	public static function persona_signature( string $login, string $email ): bool {
		$roles = array();
		foreach ( Credentials::PERSONA_ORDER as $persona ) {
			$roles[] = preg_quote( (string) $persona, '/' );
		}
		if ( 1 !== preg_match( '/^' . preg_quote( self::PREFIX, '/' ) . '(' . implode( '|', $roles ) . ')-[a-f0-9]{32}$/D', $login ) ) {
			return false;
		}
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return false;
		}
		foreach ( self::EMAIL_DOMAINS as $domain ) {
			if ( str_ends_with( $email, $domain ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Count every pre-existing artifact in the demo namespace.
	 *
	 * Never throws: a missing table, an unavailable CPMS core or a database error
	 * degrades to "not counted" instead of taking the administration page down.
	 * The number is therefore a lower bound and the wording says so.
	 *
	 * @param Env           $env      Environment.
	 * @param Platform|null $platform CPMS adapter (null = do not scan tenant tables).
	 * @return array{
	 *   users: list<array{login:string,email_domain:string,roles:list<string>,registered:string,signature:bool}>,
	 *   signature_users: list<string>,
	 *   other_users: list<string>,
	 *   post_slugs: list<string>,
	 *   tenant_tables: array<string,int>,
	 *   tenant_total: int,
	 *   total: int
	 * }
	 */
	public static function survey( Env $env, ?Platform $platform = null ): array {
		$users     = array();
		$signature = array();
		$other     = array();
		foreach ( $env->all_users() as $user ) {
			$login = (string) ( $user['login'] ?? '' );
			if ( ! str_starts_with( $login, self::PREFIX ) ) {
				continue;
			}
			$email = (string) ( $user['email'] ?? '' );
			$match = self::persona_signature( $login, $email );
			$users[] = array(
				'login'        => $login,
				'email_domain' => self::email_domain( $email ),
				'roles'        => array_values( array_map( 'strval', (array) ( $user['roles'] ?? array() ) ) ),
				'registered'   => (string) ( $user['registered'] ?? '' ),
				'signature'    => $match,
			);
			if ( $match ) {
				$signature[] = $login;
			} else {
				$other[] = $login;
			}
		}

		$post_slugs = array();
		foreach ( $env->posts_summary() as $post ) {
			$slug = (string) ( $post['slug'] ?? '' );
			if ( str_starts_with( $slug, self::PREFIX ) ) {
				$post_slugs[] = $slug;
			}
		}

		$tenant = array();
		$total  = 0;
		if ( null !== $platform && $platform->available() ) {
			foreach ( self::TENANT_PREDICATES as $short => $predicate ) {
				$count = 0;
				try {
					if ( $platform->table_exists( $short ) ) {
						$count = (int) $platform->count_where( $platform->table( $short ), $predicate, array( self::PREFIX . '%' ) );
					}
				} catch ( \Throwable $error ) {
					unset( $error );
					$count = 0;
				}
				$tenant[ $short ] = $count;
				$total           += $count;
			}
		}

		return array(
			'users'           => $users,
			'signature_users' => $signature,
			'other_users'     => $other,
			'post_slugs'      => $post_slugs,
			'tenant_tables'   => $tenant,
			'tenant_total'    => $total,
			'total'           => count( $users ) + count( $post_slugs ) + $total,
		);
	}

	/**
	 * Persian one-liner for the preflight table.
	 *
	 * @param array<string,mixed> $survey Result of survey().
	 * @return string
	 */
	public static function summary_fa( array $survey ): string {
		$total = (int) ( $survey['total'] ?? 0 );
		if ( 0 === $total ) {
			return 'هیچ حساب، برگه یا slug فعالی با پیشوند cpsd- از قبل وجود ندارد.';
		}
		$parts = self::counts_fa( $survey );
		if ( '' === $parts ) {
			return 'هیچ حساب، برگه یا slug فعالی با پیشوند cpsd- از قبل وجود ندارد.';
		}

		return $total . ' مورد با پیشوند cpsd- از قبل روی این سایت وجود دارد (' . $parts . '). '
			. 'این موارد با شواهد مهرشدهٔ این بسته اثبات نمی‌شوند؛ بنابراین نه حذف می‌شوند، نه به دموی جدید متصل می‌شوند و نه تغییر می‌کنند. '
			. 'تضاد نام دقیق (نام کاربری/ایمیل/slug همین دمو) در لحظهٔ ساخت و پیش از نخستین ردیف بررسی و در صورت برخورد متوقف می‌شود.';
	}

	/**
	 * "accounts: n, pages: n, active tenant slugs: n" — only the non-zero parts.
	 *
	 * @param array<string,mixed> $survey Result of survey().
	 * @return string
	 */
	public static function counts_fa( array $survey ): string {
		$parts = array();
		$users = count( (array) ( $survey['users'] ?? array() ) );
		if ( $users > 0 ) {
			$parts[] = 'حساب کاربری: ' . $users;
		}
		$posts = count( (array) ( $survey['post_slugs'] ?? array() ) );
		if ( $posts > 0 ) {
			$parts[] = 'برگه/نوشته: ' . $posts;
		}
		$tenant = (int) ( $survey['tenant_total'] ?? 0 );
		if ( $tenant > 0 ) {
			$parts[] = 'slug فعال سازمان/درمانگاه/شعبه: ' . $tenant;
		}

		return implode( ' · ', $parts );
	}

	/**
	 * Domain part of an e-mail, for the operator's own inspection.
	 *
	 * @param string $email E-mail.
	 * @return string
	 */
	private static function email_domain( string $email ): string {
		$at = strrpos( $email, '@' );

		return false === $at ? '' : strtolower( substr( $email, $at + 1 ) );
	}
}
