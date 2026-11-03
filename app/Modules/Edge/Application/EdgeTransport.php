<?php

namespace App\Modules\Edge\Application;

/** How the edge reaches the cloud sync API. */
interface EdgeTransport
{
    /** @param 'push'|'pull' $endpoint */
    public function send(string $endpoint, array $body): array;
}
