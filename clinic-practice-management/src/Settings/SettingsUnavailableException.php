<?php

declare(strict_types=1);

namespace ClinicCore\Settings;

use RuntimeException;

/**
 * A Clinic's persisted Settings could not be read, so no verified snapshot
 * exists. Operations that must not act on unverified defaults (settings
 * writes and their audit comparison, OTP rate-limit policy) fail closed
 * with this exception instead of guessing. The message is internal only;
 * production boundaries translate it into their established controlled
 * error (for REST, the neutral CLINIC_SERVICE_UNAVAILABLE 503 envelope).
 */
final class SettingsUnavailableException extends RuntimeException
{
}
