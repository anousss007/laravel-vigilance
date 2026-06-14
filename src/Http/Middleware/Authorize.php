<?php

namespace Vigilance\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vigilance\Vigilance;

/**
 * Guards every dashboard route. Delegates the decision to the application's
 * configured Vigilance::auth() gate (local-only by default) and returns a 403
 * for anyone who is not authorized.
 */
class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Vigilance::check($request), 403);

        return $next($request);
    }
}
