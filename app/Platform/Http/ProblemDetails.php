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
            return self::make($e->status, $e->problem, __($e->getMessage()));
        });

        $exceptions->render(function (TenantException $e, Request $request) {
            return self::make(400, 'tenant-required', __($e->getMessage()));
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return self::make(422, 'validation', __('srv.problem.validation_detail'), ['errors' => $e->errors()]);
        });
    }

    /** srv.problem.<type> in the request locale, else the type humanised. */
    private static function title(string $type): string
    {
        $key = 'srv.problem.'.str_replace('-', '_', $type);
        $t = __($key);

        return $t === $key ? ucfirst(str_replace(['-', '_'], ' ', $type)) : $t;
    }

    public static function make(int $status, string $type, string $detail, array $extra = [])
    {
        return response()->json(array_merge([
            'type' => 'https://docs.opeshotelsoft.test/problems/'.$type,
            'title' => self::title($type),
            'status' => $status,
            'detail' => $detail,
        ], $extra), $status, ['Content-Type' => 'application/problem+json']);
    }
}
