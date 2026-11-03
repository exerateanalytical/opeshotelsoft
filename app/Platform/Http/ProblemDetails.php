<?php

namespace App\Platform\Http;

use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Renders API errors as RFC 9457 problem details. */
class ProblemDetails
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->render(function (DomainException $e, Request $request) {
            return self::make($e->status, $e->problem, $e->getMessage());
        });

        $exceptions->render(function (TenantException $e, Request $request) {
            return self::make(400, 'tenant-required', $e->getMessage());
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return self::make(422, 'validation', 'The given data was invalid.', ['errors' => $e->errors()]);
        });
    }

    public static function make(int $status, string $type, string $detail, array $extra = [])
    {
        return response()->json(array_merge([
            'type' => 'https://docs.opeshotelsoft.test/problems/'.$type,
            'title' => ucfirst(str_replace('-', ' ', $type)),
            'status' => $status,
            'detail' => $detail,
        ], $extra), $status, ['Content-Type' => 'application/problem+json']);
    }
}
