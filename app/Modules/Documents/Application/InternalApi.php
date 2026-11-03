<?php

namespace App\Modules\Documents\Application;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;

/**
 * Calls an existing /api/v1 endpoint in-process, as the current user and hotel.
 * The underlying controller does its own permission and tenant checks, so a
 * document or export is only ever as visible as the resource it is built from.
 * A non-2xx answer (403, 404, 422...) is passed straight back to the client.
 */
final class InternalApi
{
    public function __construct(private Router $router) {}

    public function get(string $path, array $query = [], ?int $hotelId = null): mixed
    {
        $res = $this->raw($path, $query, $hotelId);

        return json_decode((string) $res->getContent(), true);
    }

    public function raw(string $path, array $query = [], ?int $hotelId = null): Response
    {
        $outer = app()->bound('request') ? app('request') : null;
        $sub = Request::create('/api/v1/'.ltrim($path, '/'), 'GET', $query);
        if ($outer) {
            foreach (['Authorization', 'X-Hotel-Id', 'Cookie', 'Accept-Language'] as $h) {
                if ($outer->headers->has($h)) {
                    $sub->headers->set($h, $outer->headers->get($h));
                }
            }
            $sub->cookies->replace($outer->cookies->all());
            $sub->setUserResolver($outer->getUserResolver());
            if ($outer->hasSession()) {
                $sub->setLaravelSession($outer->session());
            }
        }
        $sub->headers->set('Accept', 'application/json');
        if ($hotelId) {
            $sub->headers->set('X-Hotel-Id', (string) $hotelId);
        }

        try {
            app()->instance('request', $sub);
            $res = $this->router->dispatch($sub);
        } finally {
            if ($outer) {
                app()->instance('request', $outer);
            }
        }

        if ($res->getStatusCode() >= 400) {
            throw new HttpResponseException($res);
        }

        return $res;
    }
}
