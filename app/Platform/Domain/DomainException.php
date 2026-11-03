<?php

namespace App\Platform\Domain;

use RuntimeException;

/**
 * A business rule was violated (e.g. room not available, folio unbalanced).
 * Rendered as RFC 9457 problem details with status 422.
 */
class DomainException extends RuntimeException
{
    public function __construct(string $message, public readonly string $problem = 'business-rule', public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
