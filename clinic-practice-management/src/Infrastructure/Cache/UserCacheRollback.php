<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Cache;

/**
 * Narrow post-rollback invalidation of WordPress user caches.
 *
 * A ROLLBACK TO SAVEPOINT (or a full transaction ROLLBACK) restores durable
 * InnoDB state but cannot undo object-cache entries that wp_insert_user(),
 * wp_update_user() and user-meta writes populated while the transaction was
 * open. On a persistent backend (Redis/Memcached) those entries outlive the
 * HTTP request, so without explicit invalidation a rolled-back ghost identity
 * stays resolvable through get_user_by() and effective permissions can lag
 * behind durable state.
 *
 * The helper deletes ONLY the exact cache keys the WordPress user API owns,
 * after the database rollback has actually completed:
 *   - 'users'      keyed by user ID (WP_User instance, including role caps);
 *   - 'user_meta'  keyed by user ID;
 *   - 'userlogins' keyed by login;
 *   - 'useremail' keyed by email.
 *
 * It never performs a global wp_cache_flush() (unrelated cache groups and
 * other tenants' entries must survive), every delete is idempotent so IDs
 * whose rows no longer exist are safe, and profile updates pass BOTH the old
 * and the new login/email so the previous lookup keys are evicted too.
 *
 * Callers MUST invoke this from a catch block that runs only after the
 * enclosing CpmsDb::transactional() boundary has rolled back — never inside an
 * active outer transaction whose durable outcome is still undecided.
 */
final class UserCacheRollback
{
    /**
     * @param list<array{id:int, login?:?string, email?:?string, old_login?:?string, old_email?:?string}> $descriptors
     */
    public static function invalidate( array $descriptors ): void {
        if ( ! function_exists( 'wp_cache_delete' ) ) {
            // Pre-WP runtime (static analysis/tooling): there is no cache to
            // invalidate and the durable rollback itself already happened.
            return;
        }

        foreach ( $descriptors as $descriptor ) {
            $id = (int) ( $descriptor['id'] ?? 0 );
            if ( $id <= 0 ) {
                continue;
            }

            wp_cache_delete( $id, 'users' );
            wp_cache_delete( $id, 'user_meta' );

            foreach ( [ 'login', 'old_login' ] as $key ) {
                $login = (string) ( $descriptor[ $key ] ?? '' );
                if ( $login !== '' ) {
                    wp_cache_delete( $login, 'userlogins' );
                }
            }

            foreach ( [ 'email', 'old_email' ] as $key ) {
                $email = (string) ( $descriptor[ $key ] ?? '' );
                if ( $email !== '' ) {
                    wp_cache_delete( $email, 'useremail' );
                }
            }
        }
    }
}
