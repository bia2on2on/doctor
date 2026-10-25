<?php

declare(strict_types=1);

namespace ClinicCore\Application\Demo;

use ClinicCore\Bootstrap\App;
use WP_Error;
use WP_User;

/** Authentication/session expiry boundary for demo-only WordPress identities. */
final class DemoAccessGuard {

    public static function register(): void {
        add_action( 'init', [self::class, 'expire_if_due'], 1 );
        add_filter( 'authenticate', [self::class, 'authenticate'], 99, 3 );
        add_filter( 'determine_current_user', [self::class, 'determine_current_user'], PHP_INT_MAX, 1 );
        add_filter( 'allow_password_reset', [self::class, 'allow_password_reset'], PHP_INT_MAX, 2 );
        add_filter( 'rest_authentication_errors', [self::class, 'rest_authentication_errors'], PHP_INT_MAX, 1 );
        add_filter( 'cpms_otp_session_authorized', [self::class, 'otp_session_authorized'], PHP_INT_MAX, 2 );
        add_action( 'cpms_job_enqueued', [self::class, 'track_scoped_job'], 10, 2 );
    }

    public static function track_scoped_job( int $job_id, string $type ): void {
        App::demo_service()->track_scoped_job( $job_id, $type );
    }

    public static function expire_if_due(): void {
        App::demo_service()->expire_if_due();
    }

    /** @param WP_User|WP_Error|null $user */
    public static function authenticate( mixed $user, string $username = '', string $password = '' ): mixed {
        unset( $username, $password );
        if ( $user instanceof WP_User && App::demo_service()->is_blocked_user( (int) $user->ID ) ) {
            return new WP_Error( 'cpms_demo_inactive', 'This temporary demo account has expired or been revoked.' );
        }
        return $user;
    }

    /** @param int|false|null $user_id @return int|false|null */
    public static function determine_current_user( mixed $user_id ): mixed {
        if ( is_int( $user_id ) && $user_id > 0 && App::demo_service()->is_blocked_user( $user_id ) ) {
            return 0;
        }
        return $user_id;
    }

    public static function allow_password_reset( mixed $allowed, int $user_id ): mixed {
        if ( App::demo_service()->is_blocked_user( $user_id ) ) {
            return new WP_Error( 'cpms_demo_inactive', 'Password reset is disabled for this expired demo account.' );
        }
        return $allowed;
    }

    public static function rest_authentication_errors( mixed $result ): mixed {
        if ( $result instanceof WP_Error ) {
            return $result;
        }
        $user_id = (int) get_current_user_id();
        if ( $user_id > 0 && App::demo_service()->is_blocked_user( $user_id ) ) {
            return new WP_Error( 'cpms_demo_inactive', 'This demo session is no longer active.', ['status' => 403] );
        }
        return $result;
    }

    public static function otp_session_authorized( mixed $allowed, int $user_id ): bool {
        return (bool) $allowed && ! App::demo_service()->is_blocked_user( $user_id );
    }
}
