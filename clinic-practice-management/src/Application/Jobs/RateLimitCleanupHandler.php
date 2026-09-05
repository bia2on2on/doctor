<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Infrastructure\Security\RateLimiter;

/**
 * پاک‌سازی پنجره‌های Rate Limit (Job: cleanup.rate_limits — روزانه).
 */
final class RateLimitCleanupHandler
{
    public function __construct(private readonly RateLimiter $rateLimiter)
    {
    }

    public function __invoke(array $payload): int
    {
        return $this->rateLimiter->cleanup(2 * 86400);
    }
}
