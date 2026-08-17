<?php

namespace App\Http\Middleware;

use App\Models\PrintBridge;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePrintBridge
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bridge = PrintBridge::findActiveByToken((string) $request->bearerToken());

        if ($bridge === null) {
            abort(401, 'Invalid bridge credentials.');
        }

        $bridge->forceFill(['last_seen_at' => now()])->save();
        $request->attributes->set('print_bridge', $bridge);

        return $next($request);
    }
}
