<?php

declare( strict_types=1 );

namespace ClinicCore\Domain\Licensing;

/** Returns eligibility only, never another Clinic's clinical record. */
interface ReturningPatientEvidence {
    public function qualifies( int $patient_id, int $clinic_id, int $expires_at ): bool;
}
