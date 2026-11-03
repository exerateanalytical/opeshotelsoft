<?php

namespace App\Modules\Admin\Application;

use App\Platform\Http\ProblemDetails;
use RuntimeException;

/** Credentials were valid but the account needs a second factor; carries the challenge token. */
class TwoFactorRequired extends RuntimeException
{
    public function __construct(public readonly string $challenge, public readonly int $expiresIn)
    {
        parent::__construct('Two-factor authentication required.');
    }

    /** RFC 9457 401 carrying the challenge for POST /api/v1/auth/token/two-factor. */
    public function toProblem()
    {
        return ProblemDetails::make(401, 'two-factor-required', 'Enter the code from your authenticator app or a recovery code.', [
            'challenge_token' => $this->challenge,
            'challenge_expires_in' => $this->expiresIn,
            'challenge_url' => url('/api/v1/auth/token/two-factor'),
        ]);
    }
}
