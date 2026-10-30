<?php
/**
 * Passwordless entry into one sealed demo persona, from the administrator session.
 *
 * WHAT THIS IS, AND WHAT IT IS NOT
 * --------------------------------
 * The Owner wants to stay signed in as their own WordPress administrator and click
 * a persona button to land in that persona's REAL portal, without typing a
 * username or password. Doing that honestly needs exactly one thing: a genuine,
 * server-established WordPress session for that persona. So this class
 *
 *   1. refuses to run unless the caller is an authenticated administrator holding
 *      BOTH `manage_options` and `install_plugins`, with the action-specific nonce,
 *      the rendered site fingerprint and HTTPS (or loopback) - the same gate every
 *      other scoped action already goes through (`ScopedRequest::authorize()`);
 *   2. resolves the target ONLY from the HMAC-sealed manifest of the CURRENT demo
 *      fixture: the sealed user id for that persona, the sealed login
 *      `cpsd-<persona>-<fixture>`, and a live account that is neither an
 *      administrator nor `manage_options`-capable. A `cpsd-`-prefixed login, an
 *      e-mail domain or an id range is never accepted as ownership;
 *   3. issues a single-use, HMAC-sealed, site+fixture+persona+administrator bound
 *      ticket with a short TTL, and consumes it in ONE place;
 *   4. establishes the session with WordPress's own primitive (`wp_set_auth_cookie`)
 *      and redirects to an allow-listed portal URL that also comes from the sealed
 *      fixture. No user input is ever used as a redirect target.
 *
 * It is NOT impersonation: there is no "act as" flag, no capability copy, no
 * current-user override hook, no client-side role simulation and no administrator
 * authority inside the persona session. After the transition the
 * browser holds that persona's own session and the production authorization engine
 * decides everything, exactly as it does for a real member.
 *
 * THE UNAVOIDABLE UX LIMITATION, STATED
 * -------------------------------------
 * WordPress authentication is a cookie scoped to the whole site (`COOKIEHASH`
 * derived, `ADMIN_COOKIE_PATH`/`COOKIE_PATH`). Two tabs of the SAME browser share
 * that cookie jar, so a real persona session in one tab necessarily replaces the
 * administrator session in the others. This class therefore never claims tab
 * isolation. Two honest modes are offered instead:
 *
 *   - SEPARATE (default, recommended): the ticket URL is opened in a new window and
 *     the Owner is told to use a private window / another browser profile. That IS
 *     real isolation - a different cookie jar - and the administrator session in the
 *     original window is untouched;
 *   - SAME BROWSER (explicit): labelled as ending the administrator session in this
 *     browser. It stays safe because the way back is one click, never a password for
 *     a persona and never administrator authority for one.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- Logical grouping is clearer than column alignment in these evidence maps.

/**
 * Issues, redeems and ends one sealed demo persona session.
 */
final class ScopedPersonaSession {

	/**
	 * Option holding the pending ticket. One ticket only: issuing a new one
	 * supersedes the previous value, so a second click can never leave two live
	 * entries.
	 */
	public const TICKET_OPTION = 'cpsd_persona_ticket';

	/** Query argument carrying the one-time ticket value. */
	public const ENTRY_ARG = 'cpsd_persona_entry';

	/** Query argument that ends a persona session. */
	public const EXIT_ARG = 'cpsd_persona_exit';

	/** Seconds a ticket stays valid. Short on purpose: it is a session hand-off. */
	public const TTL = 300;

	/** Post-action marker so the login page can explain what just happened. */
	public const ENDED_ARG = 'cpsd_persona_ended';

	/** Post-action marker for a refused hand-off (stable code only, never the token). */
	public const ERROR_ARG = 'cpsd_persona_error';

	/** @var Env */
	private Env $env;

	/** @var Platform */
	private Platform $platform;

	/** @var ScopedManifest */
	private ScopedManifest $manifest;

	/**
	 * Per-request memo of `current()`.
	 *
	 * The admin-bar filter and the admin-bar node both ask the same question on
	 * every front-end request; answering it twice would read the sealed option (and
	 * its HMAC) twice per page. `null` means "not answered yet", so `false` is a
	 * cached negative.
	 *
	 * @var array{persona:string,user_id:int,manifest:array<string,mixed>}|null|false
	 */
	private static $current_cache = false;

	/**
	 * Guards the one-time banner render: hooks are attached to several surfaces and
	 * a theme may fire more than one of them.
	 *
	 * @var bool
	 */
	private static $boundary_printed = false;

	/**
	 * @param Env            $env      Environment.
	 * @param Platform       $platform Production adapter.
	 * @param ScopedManifest $manifest Sealed manifest store.
	 */
	public function __construct( Env $env, Platform $platform, ScopedManifest $manifest ) {
		$this->env      = $env;
		$this->platform = $platform;
		$this->manifest = $manifest;
	}

	/**
	 * Registers the two endpoints. Both are GET-only and both verify their own
	 * nonce: neither is an anonymous surface.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Priority 2: after WordPress has established who is logged in, and before
		// anything is rendered, so the ticket never survives into page output.
		add_action( 'init', array( __CLASS__, 'maybe_redeem' ), 2 );
		add_action( 'init', array( __CLASS__, 'maybe_end' ), 3 );
		// The session boundary must be visible in the persona's own pages, which are
		// the production portal screens. An admin-bar node is the canonical WordPress
		// way to show persistent, plugin-owned context - it adds no page and no
		// capability.
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_node' ), 100 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'force_admin_bar_for_persona_session' ), 20 );
		// The admin bar is the canonical place for plugin-owned context, but it is not
		// guaranteed: a portal screen rendered by a production template that does not
		// call wp_footer(), or a site that disables the admin bar for the persona's
		// role, would hide the boundary - and an invisible boundary is not a boundary.
		// The server-rendered banner is therefore the authoritative one, and the
		// admin-bar node is the secondary one.
		add_action( 'wp_body_open', array( __CLASS__, 'render_boundary' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'render_boundary' ), 1 );
		add_action( 'admin_footer', array( __CLASS__, 'render_boundary' ), 1 );
	}

	/**
	 * Print the session boundary once per request, on whatever surface rendered.
	 *
	 * It names the persona, states plainly that this is a separate demo session
	 * without administrator authority, and carries the one nonced way back. It grants
	 * nothing and changes nothing: it is a notice plus a link.
	 *
	 * @return void
	 */
	public static function render_boundary(): void {
		if ( self::$boundary_printed ) {
			return;
		}
		$env     = new Env();
		$session = new self( $env, new Platform(), new ScopedManifest( $env ) );
		$current = $session->current();
		if ( null === $current ) {
			return;
		}
		self::$boundary_printed = true;
		$personas = Credentials::personas();
		$label    = (string) ( $personas[ $current['persona'] ]['label'] ?? $current['persona'] );
		echo '<div id="cpsd-persona-boundary" dir="rtl" data-cpsd-session-boundary="1" data-cpsd-persona="'
			. esc_attr( (string) $current['persona'] ) . '" style="position:fixed;inset-inline:0;bottom:0;z-index:99999;'
			. 'background:#1d2327;color:#fff;padding:10px 16px;font:14px/1.6 Tahoma,sans-serif;text-align:center;'
			. 'box-shadow:0 -2px 8px rgba(0,0,0,.25)">';
		echo '<span data-cpsd-session-label="1">نشست نمایشی: <strong>' . esc_html( $label ) . '</strong></span> — ';
		echo '<span>این نشست جداست و اختیار مدیرکل ندارد؛ همهٔ مجوزها همان‌طور که برای یک کاربر واقعی سنجیده می‌شود، سنجیده می‌شود.</span> ';
		echo '<a href="' . esc_url( $session->exit_url() ) . '" data-cpsd-session-exit="1" '
			. 'style="color:#72aee6;font-weight:700;text-decoration:underline">پایان نشست نمایشی و بازگشت</a>';
		echo '</div>';
	}

	/**
	 * The one-time entry URL for a persona, or a refusal.
	 *
	 * @param array<string,mixed> $manifest Verified manifest of the live fixture.
	 * @param string              $persona  Persona key.
	 * @param int                 $admin_id Authenticated administrator issuing it.
	 * @return array{token:string,url:string,expires_at:int,persona:string,user_id:int,login:string,portal:string}
	 * @throws Exception When the persona is not provably part of this fixture.
	 */
	public function issue( array $manifest, string $persona, int $admin_id ): array {
		$target = $this->target( $manifest, $persona, $admin_id );

		$token = bin2hex( random_bytes( 24 ) );
		$now   = time();
		$ticket = array(
			'format'     => 1,
			'site'       => $this->manifest->site_fingerprint(),
			'fixture_id' => (string) $manifest['fixture_id'],
			'persona'    => $persona,
			'user_id'    => (int) $target['user_id'],
			'login'      => (string) $target['login'],
			'admin_id'   => $admin_id,
			'issued_at'  => $now,
			'expires_at' => $now + self::TTL,
			// Only the digest is stored: the option is a database row, and a stolen
			// copy of it must not be a usable ticket.
			'token_hash' => hash( 'sha256', $token ),
		);
		$ticket['mac'] = $this->seal( $ticket );
		if ( ! $this->env->persist_verified( self::TICKET_OPTION, $ticket ) ) {
			throw new Exception( 'OPTION_WRITE_FAILED', 'بلیت ورود یک‌بارمصرف ذخیره و تأیید نشد؛ عملیات متوقف شد.' );
		}

		$this->platform->audit(
			'CPSD_DEMO_PERSONA_ENTRY_ISSUED',
			$admin_id,
			(int) ( $manifest['ids']['clinic_id'] ?? 0 ),
			array(
				'fixture_id' => (string) $manifest['fixture_id'],
				'persona'    => $persona,
				'target_id'  => (int) $target['user_id'],
				'expires_in' => self::TTL,
			)
		);

		return array(
			'token'      => $token,
			'url'        => $this->entry_url( $token ),
			'expires_at' => $now + self::TTL,
			'persona'    => $persona,
			'user_id'    => (int) $target['user_id'],
			'login'      => (string) $target['login'],
			'portal'     => (string) $target['portal'],
		);
	}

	/**
	 * Absolute URL carrying the ticket. It is built from the site's own home URL, so
	 * it can never become an open redirect.
	 *
	 * @param string $token Ticket value.
	 * @return string
	 */
	public function entry_url( string $token ): string {
		return add_query_arg( self::ENTRY_ARG, rawurlencode( $token ), $this->env->home_url() . '/' );
	}

	/**
	 * Resolve and prove the target account from the sealed fixture only.
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @param string              $persona  Persona key.
	 * @param int                 $admin_id Activating administrator.
	 * @return array{user_id:int,login:string,portal:string}
	 * @throws Exception On any refusal.
	 */
	public function target( array $manifest, string $persona, int $admin_id ): array {
		if ( ! in_array( $persona, Credentials::PERSONA_ORDER, true ) ) {
			throw new Exception( 'PERSONA_UNKNOWN', 'نقش درخواستی یکی از هشت نقش نمایشی این بسته نیست.' );
		}
		$fixture = (string) ( $manifest['fixture_id'] ?? '' );
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/D', $fixture ) ) {
			throw new Exception( 'PERSONA_NOT_SEALED', 'شناسهٔ دمو برای ورود بدون رمز معتبر نیست.' );
		}
		$users   = (array) ( $manifest['ids']['user_ids'] ?? array() );
		$user_id = (int) ( $users[ $persona ] ?? 0 );
		if ( $user_id <= 1 || $user_id === $admin_id ) {
			throw new Exception( 'PERSONA_NOT_SEALED', 'حساب این نقش در سند مهرشدهٔ دمو ثبت نشده است.' );
		}
		$account = $this->env->user( $user_id );
		if ( null === $account ) {
			throw new Exception( 'PERSONA_NOT_SEALED', 'حساب این نقش دیگر وجود ندارد؛ ورود بدون رمز انجام نشد.' );
		}
		// The sealed id is necessary, never sufficient: the live account must still be
		// exactly the account the seal names, and must not hold administrator authority.
		$expected_login = 'cpsd-' . $persona . '-' . $fixture;
		if ( (string) $account['login'] !== $expected_login ) {
			throw new Exception( 'PERSONA_NOT_SEALED', 'نام کاربری این حساب با سند مهرشدهٔ دمو هم‌خوان نیست.' );
		}
		if ( in_array( 'administrator', (array) ( $account['roles'] ?? array() ), true ) || $this->env->user_can( $user_id, 'manage_options' ) ) {
			throw new Exception( 'PERSONA_NOT_SAFE', 'حساب این نقش اختیار مدیرکل دارد؛ ورود بدون رمز مجاز نیست.' );
		}
		$links  = (array) ( $manifest['links'] ?? array() );
		$portal = (string) ( $links[ (string) ( ScopedAdmin::persona_portals()[ $persona ]['link'] ?? '' ) ] ?? '' );
		if ( '' === $portal ) {
			throw new Exception( 'PERSONA_PORTAL_MISSING', 'نشانی درگاه این نقش در سند دمو ثبت نشده است.' );
		}

		return array(
			'user_id' => $user_id,
			'login'   => $expected_login,
			'portal'  => $portal,
		);
	}

	/**
	 * Consume a ticket and establish the persona's own WordPress session.
	 *
	 * Order is the security property: the ticket is verified, re-proved against the
	 * LIVE sealed fixture, and DELETED, all before a session exists. A replay finds
	 * no ticket; a replay against a torn-down fixture finds no fixture.
	 *
	 * @param string $token Ticket value from the URL.
	 * @return array{user_id:int,persona:string,redirect:string}
	 * @throws Exception On any refusal.
	 */
	public function redeem( string $token ): array {
		$token = trim( $token );
		if ( 1 !== preg_match( '/^[a-f0-9]{48}$/D', $token ) ) {
			throw new Exception( 'ENTRY_TOKEN_INVALID', 'پیوند ورود یک‌بارمصرف معتبر نیست.' );
		}
		$raw = $this->env->option_get( self::TICKET_OPTION, false );
		if ( ! is_array( $raw ) ) {
			throw new Exception( 'ENTRY_TOKEN_INVALID', 'پیوند ورود یک‌بارمصرف معتبر نیست یا پیش‌تر مصرف شده است.' );
		}
		// Single use, unconditionally: consumed before any other check can fail, so a
		// wrong-site or expired attempt can never be retried against a live ticket.
		$this->env->option_delete( self::TICKET_OPTION );

		if ( ! $this->verify( $raw ) ) {
			throw new Exception( 'ENTRY_TOKEN_INVALID', 'پیوند ورود یک‌بارمصرف معتبر نیست (مهر آن نمی‌خواند).' );
		}
		if ( (string) ( $raw['site'] ?? '' ) !== $this->manifest->site_fingerprint() ) {
			throw new Exception( 'ENTRY_TOKEN_INVALID', 'این پیوند به سایت دیگری تعلق دارد.' );
		}
		if ( (int) ( $raw['expires_at'] ?? 0 ) < time() ) {
			throw new Exception( 'ENTRY_TOKEN_EXPIRED', 'پیوند ورود یک‌بارمصرف منقضی شده است؛ دوباره از داشبورد بگیرید.' );
		}
		if ( ! hash_equals( (string) ( $raw['token_hash'] ?? '' ), hash( 'sha256', $token ) ) ) {
			throw new Exception( 'ENTRY_TOKEN_INVALID', 'پیوند ورود یک‌بارمصرف معتبر نیست.' );
		}

		// Re-prove against the CURRENT sealed fixture: a ticket issued before a
		// teardown, a re-provision or a persona change must not authenticate anything.
		$manifest = $this->manifest->load();
		if ( ! is_array( $manifest ) ) {
			throw new Exception( 'NO_DEMO', 'دموی فعالی وجود ندارد؛ ورود بدون رمز انجام نشد.' );
		}
		if ( (string) $manifest['fixture_id'] !== (string) ( $raw['fixture_id'] ?? '' ) ) {
			throw new Exception( 'ENTRY_TOKEN_INVALID', 'این پیوند به دموی دیگری تعلق دارد.' );
		}
		$target = $this->target( $manifest, (string) ( $raw['persona'] ?? '' ), (int) ( $raw['admin_id'] ?? 0 ) );
		if ( $target['user_id'] !== (int) ( $raw['user_id'] ?? 0 ) ) {
			throw new Exception( 'ENTRY_TOKEN_INVALID', 'حساب مقصد با سند مهرشدهٔ دمو هم‌خوان نیست.' );
		}

		$this->env->set_auth_cookie( $target['user_id'] );
		$this->platform->audit(
			'CPSD_DEMO_PERSONA_SESSION_OPENED',
			$target['user_id'],
			(int) ( $manifest['ids']['clinic_id'] ?? 0 ),
			array(
				'fixture_id' => (string) $manifest['fixture_id'],
				'persona'    => (string) $raw['persona'],
				// Who authorized the hand-off. The persona never gains the issuer's
				// authority; this is an audit fact, not a permission source.
				'issued_by'  => (int) ( $raw['admin_id'] ?? 0 ),
			)
		);

		return array(
			'user_id'  => $target['user_id'],
			'persona'  => (string) $raw['persona'],
			'redirect' => $target['portal'],
		);
	}

	/**
	 * Is the CURRENT session one of the sealed personas of the live fixture?
	 *
	 * Derived from sealed state on every request - never from a cookie or a query
	 * argument - so it cannot be forged, and it disappears the moment the fixture is
	 * torn down.
	 *
	 * @return array{persona:string,user_id:int,manifest:array<string,mixed>}|null
	 */
	public function current(): ?array {
		$user_id = $this->env->current_user_id();
		if ( $user_id <= 1 ) {
			return null;
		}
		if ( is_array( self::$current_cache ) && (int) self::$current_cache['user_id'] === $user_id ) {
			return self::$current_cache;
		}
		try {
			$manifest = $this->manifest->load();
		} catch ( \Throwable $refused ) {
			unset( $refused );

			return null;
		}
		if ( ! is_array( $manifest ) ) {
			return null;
		}
		$users = (array) ( $manifest['ids']['user_ids'] ?? array() );
		foreach ( Credentials::PERSONA_ORDER as $persona ) {
			if ( (int) ( $users[ $persona ] ?? 0 ) === $user_id ) {
				self::$current_cache = array(
					'persona'  => $persona,
					'user_id'  => $user_id,
					'manifest' => $manifest,
				);

				return self::$current_cache;
			}
		}

		return null;
	}

	/**
	 * Forgets the memo (used by tests and by the end-of-session path).
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$current_cache = false;
	}

	/**
	 * Nonced, GET-only exit URL for the current persona session.
	 *
	 * @return string
	 */
	public function exit_url(): string {
		return wp_nonce_url(
			add_query_arg( self::EXIT_ARG, '1', $this->env->home_url() . '/' ),
			'cpsd_persona_exit'
		);
	}

	/**
	 * `init` handler: burn a ticket and open the persona session.
	 *
	 * Every failure ends on the login screen with a stable code - never with the
	 * token echoed back, never in a JSON error that leaks the sealed fixture.
	 *
	 * @return void
	 */
	public static function maybe_redeem(): void {
		if ( ! isset( $_GET[ self::ENTRY_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A single-use HMAC-sealed ticket IS the credential; it is verified below.
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Format is validated strictly by redeem().
		$token = is_string( $_GET[ self::ENTRY_ARG ] ) ? (string) wp_unslash( $_GET[ self::ENTRY_ARG ] ) : '';
		$env   = new Env();
		try {
			$session = ( new self( $env, new Platform(), new ScopedManifest( $env ) ) )->redeem( $token );
			wp_safe_redirect( $session['redirect'] );
			exit;
		} catch ( Exception $error ) {
			wp_safe_redirect( add_query_arg( self::ERROR_ARG, rawurlencode( $error->demo_code() ), wp_login_url() ) );
			exit;
		}
	}

	/**
	 * `init` handler: end the persona session and send the browser back to the login
	 * screen with an explanation. It logs out only; it never authenticates anyone.
	 *
	 * @return void
	 */
	public static function maybe_end(): void {
		if ( ! isset( $_GET[ self::EXIT_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified by the nonce below.
			return;
		}
		$env     = new Env();
		$session = new self( $env, new Platform(), new ScopedManifest( $env ) );
		$current = $session->current();
		$nonce   = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( null === $current || '' === $nonce || ! wp_verify_nonce( $nonce, 'cpsd_persona_exit' ) ) {
			wp_safe_redirect( add_query_arg( self::ERROR_ARG, 'PERSONA_SESSION_INVALID', wp_login_url() ) );
			exit;
		}
		( new Platform() )->audit(
			'CPSD_DEMO_PERSONA_SESSION_CLOSED',
			$current['user_id'],
			(int) ( $current['manifest']['ids']['clinic_id'] ?? 0 ),
			array(
				'fixture_id' => (string) ( $current['manifest']['fixture_id'] ?? '' ),
				'persona'    => (string) $current['persona'],
			)
		);
		$env->logout();
		self::reset_cache();
		wp_safe_redirect( add_query_arg( self::ENDED_ARG, '1', wp_login_url() ) );
		exit;
	}

	/**
	 * Persistent, unmistakable session boundary in the persona's own screens.
	 *
	 * The node names the persona, states that this is a separate demo session without
	 * administrator authority, and offers the one-click way back. It is an admin-bar
	 * item, not a page and not a capability.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 * @return void
	 */
	public static function admin_bar_node( $bar ): void {
		if ( ! is_object( $bar ) || ! method_exists( $bar, 'add_node' ) ) {
			return;
		}
		$env     = new Env();
		$session = new self( $env, new Platform(), new ScopedManifest( $env ) );
		$current = $session->current();
		if ( null === $current ) {
			return;
		}
		$personas = Credentials::personas();
		$label    = (string) ( $personas[ $current['persona'] ]['label'] ?? $current['persona'] );
		$bar->add_node(
			array(
				'id'    => 'cpsd-demo-persona-session',
				'title' => 'نشست نمایشی: ' . $label,
				'href'  => $session->exit_url(),
				'meta'  => array(
					'title' => 'این نشست، نشست مستقل همین نقش نمایشی است و اختیار مدیرکل ندارد. با کلیک، نشست نمایشی پایان می‌یابد.',
					'class' => 'cpsd-demo-persona-session',
				),
			)
		);
		$bar->add_node(
			array(
				'id'     => 'cpsd-demo-persona-session-exit',
				'parent' => 'cpsd-demo-persona-session',
				'title'  => 'پایان نشست نمایشی و بازگشت',
				'href'   => $session->exit_url(),
			)
		);
	}

	/**
	 * Keep the boundary visible for the demo personas.
	 *
	 * A persona that hid the admin bar would hide the only in-context way back. This
	 * filter changes a display preference for the eight synthetic accounts only; it
	 * grants nothing.
	 *
	 * @param bool $show Current decision.
	 * @return bool
	 */
	public static function force_admin_bar_for_persona_session( $show ) {
		$env     = new Env();
		$session = new self( $env, new Platform(), new ScopedManifest( $env ) );
		if ( null !== $session->current() ) {
			return true;
		}

		return (bool) $show;
	}

	/**
	 * Seal one ticket payload with the manifest's HMAC key (same primitive as every
	 * other evidence record; no second secret).
	 *
	 * @param array<string,mixed> $ticket Payload without `mac`.
	 * @return string
	 */
	private function seal( array $ticket ): string {
		unset( $ticket['mac'] );

		return $this->manifest->seal( $ticket )['mac'];
	}

	/**
	 * @param array<string,mixed> $ticket Stored ticket.
	 * @return bool
	 */
	private function verify( array $ticket ): bool {
		$mac = isset( $ticket['mac'] ) && is_string( $ticket['mac'] ) ? $ticket['mac'] : '';
		if ( '' === $mac ) {
			return false;
		}
		unset( $ticket['mac'] );

		return hash_equals( $this->seal( $ticket ), $mac );
	}
}
