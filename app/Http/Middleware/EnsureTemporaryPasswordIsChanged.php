<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTemporaryPasswordIsChanged
{
    /**
     * Manda ao primeiro acesso quem ainda usa senha provisória, exceto nas rotas do próprio primeiro acesso e no logout.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password && ! $request->routeIs('first-access', 'first-access.*', 'logout')) {
            return redirect()->route('first-access');
        }

        return $next($request);
    }
}
