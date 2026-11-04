<?php

namespace App\Platform\Http;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Error rendering for the whole product.
 *  - API and JSON requests: RFC 9457 problem+json, with a request_id and a safe `detail` (never an exception message for 5xx).
 *  - Browser and Inertia requests: the Inertia pages Errors/NotFound (404) and Errors/Show (403, 419, 429, 500, 503...).
 * Every failure gets a request id that is logged with the exception and shown to the user, so support can find it.
 * With APP_DEBUG on, 5xx errors keep Laravel's own debug page for developers.
 */
class ErrorPages
{
    /** Statuses that have a designed screen. Others fall back to the 500 wording. */
    private const PAGES = [403, 404, 419, 429, 500, 503];

    public static function register(Exceptions $exceptions): void
    {
        $exceptions->context(fn () => ['request_id' => self::requestId(request())]);

        $exceptions->render(function (Throwable $e, Request $request) {
            $status = self::status($e, $request);
            if ($status === null) {
                return null;
            }
            if (config('app.debug') && $status >= 500 && ! $request->is('api/*')) {
                return null;
            }
            $id = self::requestId($request);
            $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

            if ($request->is('api/*') || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
                return self::problem($e, $status, $id, $headers);
            }

            return self::page($request, $status, $id, $headers);
        });
    }

    /** One id per request, created on first use (the exception report and the response must agree). */
    public static function requestId(Request $request): string
    {
        $id = $request->attributes->get('request_id');
        if (! $id) {
            $incoming = (string) $request->headers->get('X-Request-Id');
            $id = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $incoming) ? $incoming : strtoupper(Str::random(10));
            $request->attributes->set('request_id', $id);
        }

        return $id;
    }

    /** The HTTP status this exception stands for, or null when Laravel should handle it (validation, redirects, login). */
    private static function status(Throwable $e, Request $request): ?int
    {
        return match (true) {
            $e instanceof ValidationException, $e instanceof HttpResponseException => null,
            // Web: Laravel redirects to the login screen. API: a 401 problem.
            $e instanceof AuthenticationException => $request->is('api/*') || $request->expectsJson() ? 401 : null,
            $e instanceof AuthorizationException => 403,
            $e instanceof ModelNotFoundException => 404,
            $e instanceof TokenMismatchException => 419,
            $e instanceof HttpExceptionInterface => $e->getStatusCode(),
            default => 500,
        };
    }

    private static function kind(int $status): string
    {
        return match (true) {
            $status === 401 => 'unauthenticated',
            $status === 403 => 'forbidden',
            $status === 404 => 'not_found',
            $status === 409 => 'conflict',
            $status === 419 => 'expired',
            $status === 429 => 'throttle',
            $status === 422, $status === 400 => 'unprocessable',
            in_array($status, [502, 503, 504], true) => 'unavailable',
            $status >= 500 => 'server',
            default => 'unknown',
        };
    }

    /** True for a short plain sentence a person can read: no class names, paths or SQL. */
    private static function safeMessage(?string $m): bool
    {
        return $m !== null && $m !== '' && mb_strlen($m) <= 300
            && ! preg_match('/(SQLSTATE|Exception|\\\\|\.php|vendor\/|Stack trace|[\[\]{}<>])/i', $m);
    }

    private static function text(string $kind, string $part): string
    {
        $all = HandleInertiaRequests::translations(app()->getLocale());

        return $all["states.err.$kind.$part"] ?? $all["states.err.unknown.$part"] ?? '';
    }

    private static function problem(Throwable $e, int $status, string $id, array $headers)
    {
        $kind = self::kind($status);
        $detail = self::text($kind, 'text');
        // 400, 409 and 422 messages are written for people by the domain code: keep them when they read as plain prose.
        if (in_array($status, [400, 409, 422], true) && self::safeMessage($e->getMessage())) {
            $detail = $e->getMessage();
        }
        $type = str_replace('_', '-', $kind === 'throttle' ? 'too-many-requests' : $kind);

        return ProblemDetails::make($status, $type, $detail, ['request_id' => $id, 'message' => $detail])
            ->withHeaders(array_merge($headers, ['X-Request-Id' => $id]));
    }

    private static function page(Request $request, int $status, string $id, array $headers)
    {
        $page = in_array($status, self::PAGES, true) ? $status : ($status >= 500 ? 500 : ($status === 401 ? 403 : 404));

        // The Inertia middleware may not have run yet (for example a CSRF failure): make sure the page can still be translated.
        try {
            Inertia::share(app(HandleInertiaRequests::class)->share($request));
        } catch (Throwable) {
            Inertia::share(['locale' => app()->getLocale(), 'translations' => HandleInertiaRequests::translations(app()->getLocale()), 'auth' => ['user' => null]]);
        }

        $retry = (int) ($headers['Retry-After'] ?? 0);
        $response = Inertia::render($page === 404 ? 'Errors/NotFound' : 'Errors/Show', [
            'status' => $page,
            'requestId' => $id,
            'retryAfter' => $retry > 0 ? $retry : null,
        ])->toResponse($request)->setStatusCode($status);
        $response->headers->set('X-Request-Id', $id);
        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
