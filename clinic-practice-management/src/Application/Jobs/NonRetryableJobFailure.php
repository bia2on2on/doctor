<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

/** Explicit typed disposition for deterministic job failures. */
interface NonRetryableJobFailure
{
}
