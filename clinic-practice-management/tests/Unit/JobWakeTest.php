<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Application\Jobs\JobWake;
use PHPUnit\Framework\TestCase;

/**
 * Phase 17 — fast-wake scheduling service, non-WordPress safety contract (RED).
 *
 * The wake-up is advisory: outside a booted WordPress (unit/CLI contexts, or a
 * host where the WordPress cron functions are unavailable) the scheduler must be
 * a silent no-op and must never throw, because it is called from `enqueue()`
 * immediately after the job row is persisted.
 */
final class JobWakeTest extends TestCase
{
    public function testWakeContractExists(): void
    {
        $this->assertTrue(
            class_exists(JobWake::class),
            'ClinicCore\Application\Jobs\JobWake does not exist yet (missing fast-wake contract)'
        );

        if (!class_exists(JobWake::class)) {
            return;
        }

        // Fixed, installation-wide key: no per-job, per-user, per-Clinic identity.
        $this->assertSame('cpms_jobs_wake', JobWake::HOOK);
    }

    public function testRequestAndSpawnAreSilentNoOpsWithoutWordPress(): void
    {
        if (!class_exists(JobWake::class)) {
            $this->fail('ClinicCore\Application\Jobs\JobWake does not exist yet (missing fast-wake contract)');

            return;
        }

        $this->assertFalse(function_exists('wp_schedule_single_event'), 'this unit context has no WordPress');

        $wake = new JobWake();
        $wake->request();
        JobWake::spawn();

        $this->assertTrue(true, 'the advisory wake-up must never throw or fail an enqueue');
    }
}
