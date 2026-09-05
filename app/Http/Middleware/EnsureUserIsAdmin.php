<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isAdmin()) {
            abort(403, 'This action requires platform admin access.');
        }

        // Admin endpoints run with explicit system context: they bypass
        // fail-closed tenant isolation (no SELECT * leak is still prevented for
        // normal requests, but admin may query across tenants explicitly).
        TenantContext::enableSystemContext();

        try {
            return $next($request);
        } finally {
            TenantContext::disableSystemContext();
        }
    }
}
