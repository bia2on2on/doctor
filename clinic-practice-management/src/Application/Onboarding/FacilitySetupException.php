<?php

declare(strict_types=1);

namespace ClinicCore\Application\Onboarding;

use RuntimeException;

/**
 * Refusal of one Organization facility-setup operation.
 *
 * The reason is a stable machine code (tests, UI markers); the message is a short
 * Persian sentence that is safe to show to the actor: it never contains another
 * Organization's or Clinic's data, SQL, or patient information.
 */
final class FacilitySetupException extends RuntimeException {

    private string $reason;

    public function __construct( string $reason, string $message ) {
        parent::__construct( $message );
        $this->reason = $reason;
    }

    public function reason(): string {
        return $this->reason;
    }
}
