<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers for every response. The CSP uses a per-request nonce that
 * Vite stamps on its script tags (including the React refresh preamble in
 * dev), and allows the Vite dev server origin (http + ws) only while the
 * hot file exists. HSTS is sent only in production over HTTPS.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Str::random(32);
        Vite::useCspNonce($nonce);

        $response = $next($request);

        $h = $response->headers;
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'SAMEORIGIN');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        $h->set('Cross-Origin-Opener-Policy', 'same-origin');
        if (! $h->has('Content-Security-Policy')) {
            $h->set('Content-Security-Policy', $this->csp($nonce));
        }
        if (app()->isProduction() && $request->isSecure()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function csp(string $nonce): string
    {
        $dev = [];
        if (Vite::isRunningHot()) {
            $hot = rtrim(trim((string) @file_get_contents(Vite::hotFile())), '/');
            $ws = preg_replace('#^http#', 'ws', $hot);
            $dev = [$hot, $ws];
        }
        $d = fn (array $src) => implode(' ', array_filter(array_merge($src, $dev)));

        return implode('; ', [
            "default-src 'self'",
            'script-src '.$d(["'self'", "'nonce-$nonce'"]),
            'style-src '.$d(["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com']),
            'font-src '.$d(["'self'", 'data:', 'https://fonts.gstatic.com']),
            'img-src '.$d(["'self'", 'data:', 'blob:']),
            'connect-src '.$d(["'self'"]),
            "frame-src 'self' blob:",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
    }
}
