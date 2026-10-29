<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Faste sikkerhedsheadere på alle sider. HSTS sættes af nginx, så det kun gælder over HTTPS. */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        // POC: siden skal ikke kunne findes i søgemaskiner.
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
