<?php

namespace App\Modules\Onboarding\Application;

/** @internal */
final class RowFailed extends \RuntimeException
{
    public function __construct(public array $errors)
    {
        parent::__construct(implode('; ', $errors));
    }
}
