<?php

namespace App\Modules\Onboarding\Application;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a write to an existing /api/v1 endpoint in-process, as the current user
 * and hotel. Imports and the wizard reuse each module's own validation and
 * permission checks this way instead of writing to other modules' tables.
 */
final class InternalDispatcher
{
    public function __construct(private Router $router) {}

    /** @return array{0: int, 1: mixed} status and decoded JSON body */
    public function send(string $method, string $path, array $body = []): array
    {
        $outer = app()->bound('request') ? app('request') : null;
        $sub = Request::create('/api/v1/'.ltrim($path, '/'), $method, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode($body));
        if ($outer) {
            foreach (['Authorization', 'X-Hotel-Id', 'Cookie', 'Accept-Language', 'X-XSRF-TOKEN'] as $h) {
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

        try {
            app()->instance('request', $sub);
            /** @var Response $res */
            $res = $this->router->dispatch($sub);
        } finally {
            if ($outer) {
                app()->instance('request', $outer);
            }
        }

        return [$res->getStatusCode(), json_decode((string) $res->getContent(), true)];
    }
}
