<?php

declare(strict_types=1);

namespace ClinicCore\Application\Auth;

use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Clinic-scoped authority for authenticated patient sessions.
 *
 * OTP verification stamps `cpms_clinic_id` onto the issued WordPress session
 * token inside `WP_Session_Tokens`. Subsequent patient requests resolve clinic
 * authority strictly from the active session token's `cpms_clinic_id` marker
 * or, for sessions without a marker (legacy cookie, fresh `wp_signon` token,
 * or application-password authentication), from the distinct clinic count of
 * durable `cpms_patient_user_links` rows.
 */
final class PatientSessionScope {

    public const SESSION_CLINIC_KEY = 'cpms_clinic_id';

    public const SCOPE_REQUIRED_MESSAGE = 'برای ادامه در این مرکز، احراز هویت با کد یک‌بارمصرف الزامی است';

    private ?int $pending_otp_user_id = null;

    private ?int $pending_otp_clinic_id = null;

    /**
     * Token most recently issued via `wp_set_auth_cookie` in this PHP process,
     * keyed by WordPress user ID.
     *
     * @var array<int, string>
     */
    private array $in_process_tokens_by_user = [];

    /**
     * Optional session-array reader seam for pure-PHP unit tests.
     *
     * @var (callable(int, ?string): ?array<string, mixed>)|null
     */
    private $session_reader;

    /**
     * Optional distinct-linked-clinic reader seam for pure-PHP unit tests.
     *
     * @var (callable(int): list<int>)|null
     */
    private $clinics_reader;

    /**
     * Optional application-password detector seam for pure-PHP unit tests.
     *
     * @var (callable(): bool)|null
     */
    private $app_password_check;

    /**
     * Optional session-token stamper seam for pure-PHP unit tests.
     *
     * @var (callable(int, string, int): void)|null
     */
    private $session_stamper;

    /**
     * Construct the patient session scope authority.
     *
     * @param CpmsDb|null                                         $db                 Database adapter.
     * @param (callable(int, ?string): ?array<string, mixed>)|null $session_reader     Optional session reader seam.
     * @param (callable(int): list<int>)|null                     $clinics_reader     Optional distinct-clinic reader seam.
     * @param (callable(): bool)|null                             $app_password_check Optional app-password check seam.
     * @param (callable(int, string, int): void)|null             $session_stamper    Optional session stamper seam.
     */
    public function __construct(
        private readonly ?CpmsDb $db = null,
        ?callable $session_reader = null,
        ?callable $clinics_reader = null,
        ?callable $app_password_check = null,
        ?callable $session_stamper = null
    ) {
        $this->session_reader     = $session_reader;
        $this->clinics_reader     = $clinics_reader;
        $this->app_password_check = $app_password_check;
        $this->session_stamper    = $session_stamper;
    }

    /**
     * Bind an issued `WP_Session_Tokens` token for `$wp_user_id` within the
     * current PHP process when cookie superglobals are not populated.
     */
    public function set_in_process_session_token( int $wp_user_id, string $token ): void {
        if ( $wp_user_id <= 0 || '' === $token ) {
            return;
        }

        $this->in_process_tokens_by_user[ $wp_user_id ] = $token;
    }

    /**
     * Register WordPress auth-cookie lifecycle hooks idempotently.
     */
    public function register_hooks(): void {
        if ( ! function_exists( 'add_action' ) ) {
            return;
        }

        if ( ! function_exists( 'has_action' ) || false === has_action( 'set_auth_cookie', [ $this, 'on_set_auth_cookie' ] ) ) {
            add_action( 'set_auth_cookie', [ $this, 'on_set_auth_cookie' ], 10, 6 );
        }
        if ( ! function_exists( 'has_action' ) || false === has_action( 'set_logged_in_cookie', [ $this, 'on_set_logged_in_cookie' ] ) ) {
            add_action( 'set_logged_in_cookie', [ $this, 'on_set_logged_in_cookie' ], 10, 6 );
        }
        if ( ! function_exists( 'has_action' ) || false === has_action( 'clear_auth_cookie', [ $this, 'on_clear_auth_cookie' ] ) ) {
            add_action( 'clear_auth_cookie', [ $this, 'on_clear_auth_cookie' ], 10, 0 );
        }
    }

    /**
     * Arm transient pending state immediately before `OtpService::verify()`
     * calls `wp_set_auth_cookie()`. Always pair with `end_otp_cookie_issue()`
     * in a `finally` block.
     */
    public function begin_otp_cookie_issue( int $wp_user_id, int $clinic_id ): void {
        $this->register_hooks();
        if ( $wp_user_id <= 0 || $clinic_id <= 0 ) {
            $this->end_otp_cookie_issue();
            return;
        }

        $this->pending_otp_user_id = $wp_user_id;

        $this->pending_otp_clinic_id = $clinic_id;
    }

    /**
     * Clear transient pending OTP cookie state.
     */
    public function end_otp_cookie_issue(): void {
        $this->pending_otp_user_id = null;

        $this->pending_otp_clinic_id = null;
    }

    /**
     * Clear both transient OTP state and in-process token tracking (used on
     * `clear_auth_cookie` and `App::resetScope()`).
     */
    public function reset_in_process_state(): void {
        $this->end_otp_cookie_issue();

        $this->in_process_tokens_by_user = [];
    }

    /**
     * WordPress `set_auth_cookie` callback.
     *
     * @param mixed $auth_cookie Cookie string.
     * @param mixed $expire      Cookie expire timestamp.
     * @param mixed $expiration  Session expiration timestamp.
     * @param mixed $user_id     Authenticated WordPress user ID.
     * @param mixed $scheme      Cookie scheme.
     * @param mixed $token       Session token string.
     */
    public function on_set_auth_cookie(
        mixed $auth_cookie,
        mixed $expire = 0,
        mixed $expiration = 0,
        mixed $user_id = 0,
        mixed $scheme = '',
        mixed $token = ''
    ): void {
        $resolved_scheme = is_string( $scheme ) && '' !== $scheme ? $scheme : 'auth';
        $this->record_and_maybe_stamp_cookie_token( $auth_cookie, $user_id, $resolved_scheme, $token );
    }

    /**
     * WordPress `set_logged_in_cookie` callback.
     *
     * @param mixed $logged_in_cookie Cookie string.
     * @param mixed $expire           Cookie expire timestamp.
     * @param mixed $expiration       Session expiration timestamp.
     * @param mixed $user_id          Authenticated WordPress user ID.
     * @param mixed $scheme           Cookie scheme.
     * @param mixed $token            Session token string.
     */
    public function on_set_logged_in_cookie(
        mixed $logged_in_cookie,
        mixed $expire = 0,
        mixed $expiration = 0,
        mixed $user_id = 0,
        mixed $scheme = 'logged_in',
        mixed $token = ''
    ): void {
        $resolved_scheme = is_string( $scheme ) && '' !== $scheme ? $scheme : 'logged_in';
        $this->record_and_maybe_stamp_cookie_token( $logged_in_cookie, $user_id, $resolved_scheme, $token );
    }

    /**
     * WordPress `clear_auth_cookie` callback.
     */
    public function on_clear_auth_cookie(): void {
        $this->reset_in_process_state();
    }

    /**
     * Return the clinic ID stamped on the active session token for `$wp_user_id`,
     * or `null` when the request has no token or the token carries no marker.
     */
    public function stamped_session_clinic_id( int $wp_user_id ): ?int {
        $session = $this->active_session_for_user( $wp_user_id );
        if ( ! is_array( $session ) ) {
            return null;
        }

        $raw = $session[ self::SESSION_CLINIC_KEY ] ?? null;
        if ( is_int( $raw ) && $raw > 0 ) {
            return $raw;
        }
        if ( is_string( $raw ) && ctype_digit( $raw ) && (int) $raw > 0 ) {
            return (int) $raw;
        }

        return null;
    }

    /**
     * Non-throwing clinic resolution for `$wp_user_id`:
     *  - stamped `cpms_clinic_id` on active session => that clinic ID;
     *  - no marker + 1 distinct linked clinic => that clinic ID;
     *  - no marker + 0 or >1 distinct linked clinics => `null`.
     */
    public function resolve_clinic_id( int $wp_user_id ): ?int {
        if ( $wp_user_id <= 0 ) {
            return null;
        }

        $stamped = $this->stamped_session_clinic_id( $wp_user_id );
        if ( null !== $stamped ) {
            return $stamped;
        }

        $clinic_ids = $this->distinct_linked_clinic_ids( $wp_user_id );
        if ( 1 === count( $clinic_ids ) ) {
            return $clinic_ids[0];
        }

        return null;
    }

    /**
     * Clinic resolution for patient READ endpoints:
     *  - stamped `cpms_clinic_id` => `int` clinic ID;
     *  - no marker + 1 distinct linked clinic => `int` clinic ID;
     *  - no marker + 0 links => `null` (caller returns empty read result);
     *  - no marker + >1 distinct linked clinics => throws `ScopeRequiredException`
     *    (`CLINIC_SCOPE_REQUIRED`, HTTP 400, no clinic names).
     *
     * @throws ScopeRequiredException When a no-marker session spans >1 clinics.
     */
    public function require_clinic_for_read( int $wp_user_id ): ?int {
        if ( $wp_user_id <= 0 ) {
            return null;
        }

        $stamped = $this->stamped_session_clinic_id( $wp_user_id );
        if ( null !== $stamped ) {
            return $stamped;
        }

        $clinic_ids = $this->distinct_linked_clinic_ids( $wp_user_id );
        $count      = count( $clinic_ids );
        if ( 0 === $count ) {
            return null;
        }
        if ( 1 === $count ) {
            return $clinic_ids[0];
        }

        throw $this->scope_required_exception();
    }

    /**
     * Clinic resolution for patient WRITE endpoints that do not target a
     * pre-identified clinic object (e.g. `markRead`):
     *  - stamped `cpms_clinic_id` => `int` clinic ID;
     *  - no marker + 1 distinct linked clinic => `int` clinic ID;
     *  - no marker + 0 or >1 distinct linked clinics => throws
     *    `ScopeRequiredException` (`CLINIC_SCOPE_REQUIRED`, HTTP 400).
     *
     * @throws ScopeRequiredException When no single session clinic is established.
     */
    public function require_clinic_for_write( int $wp_user_id ): int {
        $resolved = $this->resolve_clinic_id( $wp_user_id );
        if ( null === $resolved || $resolved <= 0 ) {
            throw $this->scope_required_exception();
        }

        return $resolved;
    }

    /**
     * Verify that `$wp_user_id`'s resolved session clinic equals `$expected_clinic_id`.
     *
     * Throws the identical clinic-name-free `ScopeRequiredException` whether
     * the session has 0 links without a marker, >1 clinics without a marker,
     * or a resolved clinic different from `$expected_clinic_id`.
     *
     * @throws ScopeRequiredException When session clinic != `$expected_clinic_id`.
     */
    public function require_clinic_matching( int $wp_user_id, int $expected_clinic_id ): int {
        if ( $expected_clinic_id <= 0 ) {
            throw $this->scope_required_exception();
        }

        $resolved = $this->resolve_clinic_id( $wp_user_id );
        if ( null === $resolved || $resolved !== $expected_clinic_id ) {
            throw $this->scope_required_exception();
        }

        return $resolved;
    }

    /**
     * Build the canonical clinic-name-free `CLINIC_SCOPE_REQUIRED` exception.
     */
    public function scope_required_exception(): ScopeRequiredException {
        return new ScopeRequiredException(
            'CLINIC_SCOPE_REQUIRED',
            self::SCOPE_REQUIRED_MESSAGE,
            [],
            400
        );
    }

    /**
     * Distinct clinic IDs across durable `cpms_patient_user_links` joined to
     * clinic-consistent `cpms_patients` rows for `$wp_user_id`.
     *
     * @return list<int>
     */
    private function distinct_linked_clinic_ids( int $wp_user_id ): array {
        if ( null !== $this->clinics_reader ) {
            $custom = ( $this->clinics_reader )( $wp_user_id );
            $out    = [];
            foreach ( $custom as $cid ) {
                $cid = (int) $cid;
                if ( $cid > 0 && ! in_array( $cid, $out, true ) ) {
                    $out[] = $cid;
                }
            }
            sort( $out );
            return $out;
        }

        if ( null === $this->db ) {
            return [];
        }

        $rows = $this->db->fetchAll(
            'SELECT DISTINCT l.clinic_id'
            . ' FROM ' . $this->db->table( 'cpms_patient_user_links' ) . ' l'
            . ' INNER JOIN ' . $this->db->table( 'cpms_patients' ) . ' p'
            . ' ON p.id = l.patient_id AND p.clinic_id = l.clinic_id'
            . ' WHERE l.wp_user_id = %d'
            . ' ORDER BY l.clinic_id ASC',
            [ $wp_user_id ]
        );

        $ids = [];
        foreach ( $rows as $row ) {
            $cid = (int) ( $row['clinic_id'] ?? 0 );
            if ( $cid > 0 ) {
                $ids[] = $cid;
            }
        }

        return $ids;
    }

    /**
     * Locate the active `WP_Session_Tokens` session array for `$wp_user_id`,
     * or `null` when authenticated via application password or without a token.
     *
     * @return array<string, mixed>|null
     */
    private function active_session_for_user( int $wp_user_id ): ?array {
        if ( $wp_user_id <= 0 ) {
            return null;
        }

        $is_app_password = null !== $this->app_password_check
            ? (bool) ( $this->app_password_check )()
            : ( function_exists( 'did_action' ) && did_action( 'application_password_did_authenticate' ) > 0 );
        if ( $is_app_password ) {
            return null;
        }

        $token = $this->active_cookie_token_for_user( $wp_user_id );
        if ( null === $token ) {
            $in_process = $this->in_process_tokens_by_user[ $wp_user_id ] ?? null;
            if ( is_string( $in_process ) && '' !== $in_process ) {
                $token = $in_process;
            }
        }

        if ( null !== $this->session_reader ) {
            $session = ( $this->session_reader )( $wp_user_id, $token );
            return is_array( $session ) ? $session : null;
        }

        if ( ! class_exists( '\WP_Session_Tokens' ) || null === $token || '' === $token ) {
            return null;
        }

        $manager = \WP_Session_Tokens::get_instance( $wp_user_id );
        $session = $manager->get( $token );

        return is_array( $session ) ? $session : null;
    }

    /**
     * Extract the validated cookie token for `$wp_user_id` via WordPress Core
     * auth-cookie helpers (never trusting unvalidated cookie strings).
     */
    private function active_cookie_token_for_user( int $wp_user_id ): ?string {
        if ( ! function_exists( 'wp_validate_auth_cookie' ) || ! function_exists( 'wp_parse_auth_cookie' ) ) {
            return null;
        }

        foreach ( [ 'logged_in', 'secure_auth', 'auth' ] as $scheme ) {
            $validated_user_id = wp_validate_auth_cookie( '', $scheme );
            if ( (int) $validated_user_id !== $wp_user_id ) {
                continue;
            }

            $parsed = wp_parse_auth_cookie( '', $scheme );
            if ( is_array( $parsed ) && isset( $parsed['token'] ) && is_string( $parsed['token'] ) && '' !== $parsed['token'] ) {
                return $parsed['token'];
            }
        }

        return null;
    }

    /**
     * Record the newly issued token for same-request resolution and stamp
     * `cpms_clinic_id` onto `WP_Session_Tokens` if an OTP issue is pending
     * for this exact user.
     */
    private function record_and_maybe_stamp_cookie_token(
        mixed $cookie,
        mixed $user_id,
        string $scheme,
        mixed $token
    ): void {
        $resolved_user_id = is_numeric( $user_id ) ? (int) $user_id : 0;
        if ( $resolved_user_id <= 0 ) {
            return;
        }

        $resolved_token = is_string( $token ) ? $token : '';
        if ( '' === $resolved_token && is_string( $cookie ) && '' !== $cookie && function_exists( 'wp_parse_auth_cookie' ) ) {
            $parsed = wp_parse_auth_cookie( $cookie, $scheme );
            if ( is_array( $parsed ) && isset( $parsed['token'] ) && is_string( $parsed['token'] ) ) {
                $resolved_token = $parsed['token'];
            }
        }

        if ( '' === $resolved_token ) {
            return;
        }

        $this->in_process_tokens_by_user[ $resolved_user_id ] = $resolved_token;

        if (
            $this->pending_otp_user_id !== $resolved_user_id
            || null === $this->pending_otp_clinic_id
            || $this->pending_otp_clinic_id <= 0
        ) {
            return;
        }

        if ( null !== $this->session_stamper ) {
            ( $this->session_stamper )( $resolved_user_id, $resolved_token, $this->pending_otp_clinic_id );
            return;
        }

        if ( ! class_exists( '\WP_Session_Tokens' ) ) {
            return;
        }

        $manager = \WP_Session_Tokens::get_instance( $resolved_user_id );
        $session = $manager->get( $resolved_token );
        if ( ! is_array( $session ) ) {
            return;
        }

        $session[ self::SESSION_CLINIC_KEY ] = $this->pending_otp_clinic_id;
        $manager->update( $resolved_token, $session );
    }
}
