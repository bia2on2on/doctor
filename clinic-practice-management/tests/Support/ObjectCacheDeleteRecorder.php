<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Support;

/**
 * Test-only decorator for the global WordPress object cache.
 *
 * WHY (auditor D7): a test-only wp_cache_flush() cannot prove production
 * rollback cache hygiene — it erases every group and masks stale ghost
 * identities instead of proving that the production code evicted exactly the
 * WordPress-owned user cache keys after a real ROLLBACK. This recorder wraps
 * the real WP_Object_Cache, delegates every operation unchanged and records:
 *
 *   - every delete($key, $group) call (assertions pin the exact groups
 *     users / user_meta / userlogins / useremail and their keys);
 *   - every flush() call (production rollback paths MUST stay at zero — a
 *     global flush would discard unrelated tenants' cache on Redis/Memcached).
 *
 * Nothing about production behavior is altered: the underlying cache performs
 * the actual deletes, so end-to-end reads (get_user_by(), get_userdata())
 * observe the same state a fresh request would after rollback.
 */
final class ObjectCacheDeleteRecorder
{
    /** @var array<int, array{key:string, group:string}> */
    private array $deletes = [];

    private int $flushes = 0;

    private object $previous;

    public static function install(): self
    {
        $recorder = new self();
        if ( ! isset($GLOBALS['wp_object_cache']) || ! is_object($GLOBALS['wp_object_cache'])) {
            throw new \RuntimeException('ObjectCacheDeleteRecorder requires the WordPress object cache to be initialized');
        }
        $recorder->previous = $GLOBALS['wp_object_cache'];
        $GLOBALS['wp_object_cache'] = $recorder;

        return $recorder;
    }

    public function restore(): void
    {
        if (isset($this->previous)) {
            $GLOBALS['wp_object_cache'] = $this->previous;
        }
    }

    /**
     * @param int|string $key
     */
    public function delete($key, string $group = ''): bool
    {
        $this->deletes[] = [
            'key'   => (string) $key,
            'group' => $group,
        ];

        return (bool) $this->previous->delete($key, $group);
    }

    public function flush(): bool
    {
        $this->flushes++;

        return (bool) $this->previous->flush();
    }

    /**
     * Delegate every other WP_Object_Cache method (get/set/add/replace/...).
     *
     * @param array<int, mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->previous->$name(...$arguments);
    }

    /**
     * Some WordPress internals touch cache properties directly.
     */
    public function __get(string $name): mixed
    {
        return $this->previous->$name ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->previous->$name = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->previous->$name);
    }

    public function __unset(string $name): void
    {
        unset($this->previous->$name);
    }

    /**
     * @return array<int, array{key:string, group:string}>
     */
    public function allDeletes(): array
    {
        return $this->deletes;
    }

    /**
     * @return list<string>
     */
    public function deletedKeysForGroup(string $group): array
    {
        $keys = [];
        foreach ($this->deletes as $entry) {
            if ($entry['group'] === $group) {
                $keys[] = $entry['key'];
            }
        }

        return $keys;
    }

    /**
     * @param int|string $key
     */
    public function deleted($key, string $group): bool
    {
        $needle = (string) $key;
        foreach ($this->deletes as $entry) {
            if ($entry['group'] === $group && $entry['key'] === $needle) {
                return true;
            }
        }

        return false;
    }

    public function flushCount(): int
    {
        return $this->flushes;
    }
}
