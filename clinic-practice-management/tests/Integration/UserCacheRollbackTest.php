<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Infrastructure\Cache\UserCacheRollback;
use ClinicCore\Tests\Support\ObjectCacheDeleteRecorder;
use WP_UnitTestCase;

/**
 * D7 — production post-rollback WordPress user-cache invalidation.
 *
 * The independent auditor rejected test-only wp_cache_flush() as a fix: on a
 * persistent backend (Redis/Memcached) object-cache entries outlive the
 * request, so rolled-back ghost identities stay resolvable unless production
 * code evicts the exact WordPress-owned keys. These tests pin the production
 * UserCacheRollback contract directly:
 *
 *  - only the users / user_meta / userlogins / useremail groups are touched;
 *  - login (incl. old_login) and email (incl. old_email) lookup keys go too;
 *  - every delete is idempotent and vanished IDs are tolerated;
 *  - a global wp_cache_flush() is NEVER used (unrelated groups must survive);
 *  - empty descriptor lists are no-ops.
 */
final class UserCacheRollbackTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \ClinicCore\Bootstrap\App::migrations()->migrate();
    }

    public function testInvalidatesExactlyTheWordPressOwnedUserCacheKeys(): void
    {
        $recorder = ObjectCacheDeleteRecorder::install();
        try {
            UserCacheRollback::invalidate([
                [
                    'id'        => 4242,
                    'login'     => 'ghost_login',
                    'email'     => 'ghost@example.test',
                    'old_login' => 'previous_login',
                    'old_email' => 'previous@example.test',
                ],
            ]);

            self::assertSame(0, $recorder->flushCount(), 'a global flush must never back the rollback invalidation');
            self::assertTrue($recorder->deleted(4242, 'users'), 'users key must be evicted by id');
            self::assertTrue($recorder->deleted(4242, 'user_meta'), 'user_meta key must be evicted by id');
            self::assertTrue($recorder->deleted('ghost_login', 'userlogins'), 'new login slug key must be evicted');
            self::assertTrue($recorder->deleted('previous_login', 'userlogins'), 'old login slug key must be evicted');
            self::assertTrue($recorder->deleted('ghost@example.test', 'useremail'), 'new email key must be evicted');
            self::assertTrue($recorder->deleted('previous@example.test', 'useremail'), 'old email key must be evicted');

            // No group outside the four WordPress user groups is ever touched.
            foreach ($recorder->allDeletes() as $entry) {
                self::assertContains(
                    $entry['group'],
                    ['users', 'user_meta', 'userlogins', 'useremail'],
                    'no unrelated cache group may be evicted: ' . $entry['group']
                );
            }
        } finally {
            $recorder->restore();
        }
    }

    public function testIdempotentAndTolerantOfVanishedIdentities(): void
    {
        // Deleting keys that were never populated (the row already vanished)
        // must be a safe no-op-style call that never raises.
        UserCacheRollback::invalidate([
            ['id' => 999999, 'login' => 'vanished', 'email' => 'vanished@example.test'],
        ]);
        UserCacheRollback::invalidate([
            ['id' => 999999, 'login' => 'vanished', 'email' => 'vanished@example.test'],
        ]);

        // Empty input must do nothing at all.
        $recorder = ObjectCacheDeleteRecorder::install();
        try {
            UserCacheRollback::invalidate([]);
            UserCacheRollback::invalidate([['id' => 0]]);
            self::assertSame([], $recorder->allDeletes(), 'empty/zero-id descriptors must not touch the cache');
            self::assertSame(0, $recorder->flushCount());
        } finally {
            $recorder->restore();
        }
    }

    public function testUnrelatedCacheEntriesSurviveTheTargetedInvalidation(): void
    {
        // Prove the narrow invalidation cannot damage unrelated cached state
        // (the reason a global flush is forbidden on shared persistent caches).
        wp_cache_set('unrelated_tenant_key', 'keep-me', 'a_different_group');
        wp_cache_set('another_user_row', 'keep-me-too', 'users');

        UserCacheRollback::invalidate([
            ['id' => 7777, 'login' => 'scoped_ghost', 'email' => 'scoped@example.test'],
        ]);

        self::assertSame('keep-me', wp_cache_get('unrelated_tenant_key', 'a_different_group'), 'unrelated group entries must survive');
        self::assertSame('keep-me-too', wp_cache_get('another_user_row', 'users'), 'only the named id key is evicted, never the whole group');
        self::assertFalse(wp_cache_get(7777, 'users'), 'the rolled-back id key must be gone');
    }
}
