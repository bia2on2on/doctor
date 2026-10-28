<?php

declare(strict_types=1);

namespace ClinicCore\Application\Messaging;

use DomainException;

/**
 * Domain error for internal staff messaging. The REST boundary maps it to the
 * standard CLINIC_* envelope (ADR-0019); no raw SQL or PHI is carried in data.
 */
final class MessagingException extends DomainException {

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $error_code,
        string $message,
        public readonly int $http_status = 400,
        public readonly array $data = []
    ) {
        parent::__construct( $message );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function of( string $code, string $message, int $http = 400, array $data = [] ): self {
        return new self( $code, $message, $http, $data );
    }
}
