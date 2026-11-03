<?php

namespace App\Modules\Edge\Application;

use Illuminate\Support\Facades\Http;

/** Signed HTTPS calls to {EDGE_CLOUD_URL}/api/v1/edge/sync/{push|pull}. */
class HttpTransport implements EdgeTransport
{
    public function send(string $endpoint, array $body): array
    {
        $path = 'api/v1/edge/sync/'.$endpoint;
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $headers = Signature::headers((int) config('edge.node_id'), (string) config('edge.secret'), 'POST', $path, $json);

        return Http::withHeaders($headers + ['Accept' => 'application/json'])
            ->withBody($json, 'application/json')
            ->timeout(60)
            ->post(config('edge.cloud_url').'/'.$path)
            ->throw()
            ->json();
    }
}
