<?php
/**
 * WordPress environment facade.
 *
 * Every WordPress API call made by the demo installer's logic goes through this
 * class. Two reasons: (1) the safety logic stays reviewable in one place, and
 * (2) the automated tests can drive the exact same code with an in-memory
 * environment instead of a live database.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned

defined( 'ABSPATH' ) || exit;

/**
 * Production implementation: thin pass-through to real WordPress APIs.
 */
class Env {

	/**
	 * Active in-process lock token, or null when idle.
	 *
	 * @var string|null
	 */
	private ?string $active_lock_token = null;

	/**
	 * Optional test fault-injection callback (`callable(string $point, array $context): void`).
	 *
	 * @var callable|null
	 */
	private $fault_injector = null;

	/**
	 * @return bool
	 */
	public function is_multisite(): bool {
		return is_multisite();
	}

	/**
	 * @return bool
	 */
	public function is_ssl(): bool {
		return is_ssl();
	}

	/**
	 * @return string
	 */
	public function home_url(): string {
		return (string) home_url( '/' );
	}

	/**
	 * @return string
	 */
	public function site_url(): string {
		return (string) site_url( '/' );
	}

	/**
	 * Database identity is part of the ownership manifest: the same paths on a
	 * different database must never be treated as the same demo site.
	 *
	 * @return string
	 */
	public function db_name(): string {
		return defined( 'DB_NAME' ) ? (string) DB_NAME : '';
	}

	/**
	 * @return string
	 */
	public function table_prefix(): string {
		global $wpdb;

		return isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';
	}

	/**
	 * @return int
	 */
	public function current_user_id(): int {
		return (int) get_current_user_id();
	}

	/**
	 * @param int    $user_id User id.
	 * @param string $capability Capability.
	 * @return bool
	 */
	public function user_can( int $user_id, string $capability ): bool {
		return $user_id > 0 && user_can( $user_id, $capability );
	}

	/**
	 * @param int $user_id User id.
	 * @return array{id:int,login:string,email:string,roles:list<string>}|null
	 */
	public function user( int $user_id ): ?array {
		$user = get_userdata( $user_id );
		if ( $user === false ) {
			return null;
		}

		return array(
			'id'    => (int) $user->ID,
			'login' => (string) $user->user_login,
			'email' => (string) $user->user_email,
			'roles' => array_values( array_map( 'strval', (array) $user->roles ) ),
		);
	}

	/**
	 * @param string $login Login.
	 * @return array{id:int,login:string,email:string,roles:list<string>}|null
	 */
	public function user_by_login( string $login ): ?array {
		$user = get_user_by( 'login', $login );

		return $user === false ? null : $this->user( (int) $user->ID );
	}

	/**
	 * Complete user inventory — required for the "no other accounts" preflight.
	 *
	 * @return list<array{id:int,login:string,email:string,roles:list<string>}>
	 */
	public function all_users(): array {
		$users = get_users(
			array(
				'fields' => 'all',
				'number' => 1000,
			)
		);
		$out   = array();
		foreach ( $users as $user ) {
			$out[] = array(
				'id'    => (int) $user->ID,
				'login' => (string) $user->user_login,
				'email' => (string) $user->user_email,
				'roles' => array_values( array_map( 'strval', (array) $user->roles ) ),
			);
		}

		return $out;
	}

	/**
	 * @param string $login Desired login.
	 * @return bool
	 */
	public function username_exists( string $login ): bool {
		return (bool) username_exists( $login );
	}

	/**
	 * @param string $email Desired email.
	 * @return bool
	 */
	public function email_exists( string $email ): bool {
		return (bool) email_exists( $email );
	}

	/**
	 * @param array{user_login:string,user_pass:string,user_email:string,display_name:string,role:string} $args Arguments.
	 * @return int New user id.
	 * @throws Exception When WordPress refuses the account.
	 */
	public function create_user( array $args ): int {
		$user_id = wp_insert_user(
			array(
				'user_login'   => $args['user_login'],
				'user_pass'    => $args['user_pass'],
				'user_email'   => $args['user_email'],
				'display_name' => $args['display_name'],
				'role'         => $args['role'],
			)
		);
		if ( is_wp_error( $user_id ) ) {
			throw new Exception( 'USER_CREATE', 'ساخت حساب آزمایشی ناموفق بود: ' . $user_id->get_error_code() );
		}

		return (int) $user_id;
	}

	/**
	 * @param int    $user_id  User id.
	 * @param string $password New password.
	 * @return void
	 */
	public function set_user_password( int $user_id, string $password ): void {
		wp_set_password( $password, $user_id );
	}

	/**
	 * Invalidate every existing browser session of a demo persona.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function destroy_sessions( int $user_id ): void {
		delete_user_meta( $user_id, 'session_tokens' );
	}

	/**
	 * @param int $user_id User id.
	 * @return bool
	 */
	public function delete_user( int $user_id ): bool {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		return (bool) wp_delete_user( $user_id );
	}

	/**
	 * @param string $name     Option name.
	 * @param mixed  $fallback Fallback value.
	 * @return mixed
	 */
	public function option_get( string $name, $fallback = false ) {
		return get_option( $name, $fallback );
	}

	/**
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	public function option_set( string $name, $value ): bool {
		return (bool) update_option( $name, $value, false );
	}

	/**
	 * @param string $name Option name.
	 * @return bool
	 */
	public function option_delete( string $name ): bool {
		return (bool) delete_option( $name );
	}

	/**
	 * Write an option and prove the value is durably stored.
	 *
	 * `update_option()` returns false both when the write FAILS and when the new
	 * value equals the stored one, so its return value alone cannot tell success
	 * from loss. Every critical companion record (sealed baseline, ownership
	 * manifest, mode marker, teardown tombstone) is therefore written through this
	 * helper and read back before the caller is allowed to rely on it.
	 *
	 * The read is forced past the options cache so a cached value cannot mask a
	 * lost write.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value to store.
	 * @return bool True only when the stored value reads back equal.
	 */
	public function persist_verified( string $name, $value ): bool {
		$this->maybe_inject_fault( 'option_persist:' . $name, array( 'name' => $name ) );
		$this->option_set( $name, $value );
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $name, 'options' );
		}
		$stored = $this->option_get( $name, false );

		return $this->canonical_option( $stored ) === $this->canonical_option( $value );
	}

	/**
	 * Deterministic comparison form for an option value (order-insensitive).
	 *
	 * @param mixed $value Option value.
	 * @return string
	 */
	private function canonical_option( $value ): string {
		$value = $this->canonical_option_value( $value );

		return (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );
	}

	/**
	 * @param mixed $value Option value.
	 * @return mixed
	 */
	private function canonical_option_value( $value ) {
		if ( is_array( $value ) ) {
			$sorted = array();
			foreach ( $value as $key => $item ) {
				$sorted[ $key ] = $this->canonical_option_value( $item );
			}
			ksort( $sorted );

			return $sorted;
		}
		if ( $value instanceof \stdClass ) {
			return $this->canonical_option_value( (array) $value );
		}
		if ( is_bool( $value ) || null === $value ) {
			return $value;
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return $value;
	}

	/**
	 * @return list<string> Active plugin basenames.
	 */
	public function active_plugins(): array {
		$active = (array) get_option( 'active_plugins', array() );

		return array_values( array_map( 'strval', $active ) );
	}

	/**
	 * Minimal post inventory for the "default WordPress content only" check.
	 *
	 * @return list<array{id:int,type:string,status:string,slug:string,author:int}>
	 */
	public function posts_summary(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT ID, post_type, post_status, post_name, post_author FROM ' . $wpdb->posts . ' ORDER BY ID ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, no user input.
			ARRAY_A
		);
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'     => (int) $row['ID'],
				'type'   => (string) $row['post_type'],
				'status' => (string) $row['post_status'],
				'slug'   => (string) $row['post_name'],
				'author' => (int) $row['post_author'],
			);
		}

		return $out;
	}

	/**
	 * Comments that are NOT part of the stock WordPress starter content.
	 *
	 * A fresh install ships exactly one sample comment on the "Hello world!"
	 * post by "A WordPress Commenter"; anything else means the site is in use.
	 *
	 * @return int
	 */
	public function comment_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $wpdb->comments . ' WHERE NOT ( comment_post_ID = %d AND ( comment_author_email = %s OR comment_author = %s ) )', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is core, values prepared.
				1,
				'wapuu@wordpress.example',
				'A WordPress Commenter'
			)
		);
	}

	/**
	 * @param array{title:string,slug:string,content:string,author:int} $args Page arguments.
	 * @return int New page id.
	 * @throws Exception When the page cannot be created.
	 */
	public function insert_page( array $args ): int {
		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_author'  => (int) $args['author'],
				'post_name'    => $args['slug'],
				'post_title'   => $args['title'],
				'post_content' => $args['content'],
			),
			true
		);
		if ( is_wp_error( $page_id ) ) {
			throw new Exception( 'PAGE_CREATE', 'ساخت برگهٔ عمومی رزرو ناموفق بود: ' . $page_id->get_error_code() );
		}

		return (int) $page_id;
	}

	/**
	 * @param int $post_id Post id.
	 * @return array{id:int,type:string,status:string,slug:string,content:string}|null
	 */
	public function post( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		return array(
			'id'      => (int) $post->ID,
			'type'    => (string) $post->post_type,
			'status'  => (string) $post->post_status,
			'slug'    => (string) $post->post_name,
			'content' => (string) $post->post_content,
		);
	}

	/**
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public function delete_post( int $post_id ): bool {
		return (bool) wp_delete_post( $post_id, true );
	}

	/**
	 * @param int $post_id Post id.
	 * @return string
	 */
	public function permalink( int $post_id ): string {
		return (string) get_permalink( $post_id );
	}

	/**
	 * A persistent object cache can hold CPMS setting caches across requests and
	 * make "verify immediately before mutation" unreliable; refuse instead.
	 *
	 * @return bool
	 */
	public function object_cache_enabled(): bool {
		return function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
	}

	/**
	 * @return string
	 */
	public function wp_version(): string {
		global $wp_version;

		return (string) $wp_version;
	}

	/**
	 * @return string
	 */
	public function php_version(): string {
		return PHP_VERSION;
	}

	/**
	 * @return int
	 */
	public function now(): int {
		return time();
	}

	/**
	 * @return string MySQL DATETIME in UTC.
	 */
	public function now_sql(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Site-specific secret used only to key the manifest MAC. Never displayed.
	 *
	 * @return string
	 */
	public function mac_key(): string {
		$salt = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
		if ( $salt === '' ) {
			throw new Exception( 'NO_SALT', 'کلیدهای امنیتی وردپرس در دسترس نیستند؛ ادامه امن نیست.' );
		}

		return $salt . '|cpms-disposable-demo|1';
	}

	/**
	 * @param string $action Nonce action.
	 * @return string
	 */
	public function nonce_create( string $action ): string {
		return (string) wp_create_nonce( $action );
	}

	/**
	 * @param string $nonce  Nonce value.
	 * @param string $action Nonce action.
	 * @return bool
	 */
	public function nonce_verify( string $nonce, string $action ): bool {
		return (bool) wp_verify_nonce( $nonce, $action );
	}

	/**
	 * @return bool
	 */
	public function is_user_logged_in(): bool {
		return is_user_logged_in();
	}

	/**
	 * @param string $key Configuration constant name.
	 * @return bool
	 */
	public function constant_defined( string $key ): bool {
		return defined( $key ) && constant( $key );
	}

	/**
	 * @param string $name     Option name.
	 * @param mixed  $fallback Fallback value.
	 * @return mixed
	 */
	public function filterable_option( string $name, $fallback = false ) {
		return get_option( $name, $fallback );
	}

	/**
	 * Compute the HMAC-SHA256 IP hash matching AuditLogger::ipHash().
	 *
	 * Note: matching an IP hash is never sufficient proof that a global
	 * shared-IP rate-limit row belongs exclusively to the demo.
	 *
	 * @param string $ip Client IP address.
	 * @return string
	 */
	public function ip_hash( string $ip ): string {
		$pepper = defined( 'CPMS_PEPPER' ) ? (string) CPMS_PEPPER : '';
		if ( '' === $pepper ) {
			$pepper = 'cpms-dev-pepper-change-me';
		}

		return hash_hmac( 'sha256', $ip, $pepper );
	}

	/**
	 * Compute the 32-char SHA-256 username hash matching LoginRateLimiter::userKey().
	 *
	 * @param string $username WordPress login name.
	 * @return string
	 */
	public function username_hash( string $username ): string {
		return substr( hash( 'sha256', strtolower( trim( $username ) ) ), 0, 32 );
	}

	/**
	 * Deterministic MySQL advisory lock name for this WordPress site (max 64 chars).
	 *
	 * @return string
	 */
	public function lock_name(): string {
		return 'cpdd_mutex_' . substr( hash( 'sha256', $this->db_name() . '|' . $this->table_prefix() ), 0, 32 );
	}

	/**
	 * Acquire a non-blocking session-bound concurrency guard (S-5).
	 *
	 * Uses MySQL GET_LOCK(name, 0) so the lock never expires while the holding
	 * DB connection is still running, and is automatically released by MySQL if
	 * the PHP process crashes or disconnects.
	 *
	 * @param string $operation Operation identifier.
	 * @return string Lock token.
	 */
	public function acquire_lock( string $operation ): string {
		if ( null !== $this->active_lock_token ) {
			throw new Exception( 'CONCURRENT_OPERATION', 'عملیات دیگری روی دمو در حال اجراست؛ هم‌زمانی مسدود شد.' );
		}
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory session lock on the active DB connection.
			$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $this->lock_name() ) );
			if ( '1' !== (string) $acquired && 1 !== $acquired ) {
				throw new Exception( 'CONCURRENT_OPERATION', 'عملیات دیگری روی دمو در حال اجراست؛ هم‌زمانی مسدود شد.' );
			}
		}
		$token                   = bin2hex( random_bytes( 12 ) );
		$this->active_lock_token = $token;
		$this->option_set(
			'cpdd_lock_state',
			array(
				'operation'  => $operation,
				'token'      => $token,
				'started_at' => $this->now_sql(),
			)
		);

		return $token;
	}

	/**
	 * Release a previously acquired concurrency guard.
	 *
	 * @param string $token Lock token returned by acquire_lock().
	 */
	public function release_lock( string $token ): void {
		if ( $this->active_lock_token !== $token ) {
			return;
		}
		$this->active_lock_token = null;
		$this->option_delete( 'cpdd_lock_state' );
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Release advisory session lock on the active DB connection.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name() ) );
		}
	}

	/**
	 * Execute a mutation under the concurrency guard, always releasing on exit.
	 *
	 * @template T
	 * @param string          $operation Operation identifier.
	 * @param callable():mixed $callback  Mutation callback.
	 * @return mixed
	 */
	public function with_lock( string $operation, callable $callback ) {
		$token = $this->acquire_lock( $operation );
		try {
			return $callback();
		} finally {
			$this->release_lock( $token );
		}
	}

	/**
	 * Register or clear a test fault-injection callback.
	 *
	 * @param callable|null $callback Callback `function(string $point, array $context): void`.
	 */
	public function set_fault_injector( ?callable $callback ): void {
		$this->fault_injector = $callback;
	}

	/**
	 * Trigger a registered test fault-injection callback if present.
	 *
	 * @param string               $point   Named fault-injection point.
	 * @param array<string,mixed>  $context Context metadata.
	 */
	public function maybe_inject_fault( string $point, array $context = array() ): void {
		if ( is_callable( $this->fault_injector ) ) {
			( $this->fault_injector )( $point, $context );
		}
	}
}
