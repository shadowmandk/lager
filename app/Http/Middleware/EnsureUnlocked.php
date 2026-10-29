<?php

namespace App\Http\Middleware;

use App\Support\DeviceLock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Tavle, log, statistik og medarbejdervalg kræver, at enheden er låst op med koden. */
class EnsureUnlocked
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! DeviceLock::isUnlocked()) {
            DeviceLock::lock();

            // Livewire stopper kun en opdatering ved en omdirigering, så kun tavlens ping får et JSON-svar.
            return $request->routeIs('ping')
                ? response()->json(['locked' => true], 423)
                : redirect()->route('unlock');
        }

        // Tavlens automatiske opdatering må ikke holde enheden låst op.
        if (! $request->routeIs('ping')) {
            DeviceLock::touch();
        }

        $response = $next($request);

        // Må ikke kunne vises fra browserens cache, når enheden er låst.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
