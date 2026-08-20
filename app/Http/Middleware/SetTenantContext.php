<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('tenant');

        if (blank($slug)) {
            return $next($request);
        }

        $tenant = Tenant::where('slug', $slug)->first();

        if (! $tenant) {
            return $next($request);
        }

        TenantContext::set($tenant);

        try {
            return $next($request);
        } finally {
            TenantContext::forget();
        }
    }
}
