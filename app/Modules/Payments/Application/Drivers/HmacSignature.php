<?php

namespace App\Modules\Payments\Application\Drivers;

use Illuminate\Http\Request;

trait HmacSignature
{
    public static function sign(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    protected function hmacValid(Request $request, string $header, ?string $secret): bool
    {
        $given = (string) $request->header($header, '');

        return $secret !== null && $secret !== '' && $given !== ''
            && hash_equals(self::sign($request->getContent(), $secret), $given);
    }
}
