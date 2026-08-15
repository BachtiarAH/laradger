<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->header('X-Tenant');

        if (blank($slug)) {
            return $next($request);
        }

        $tenant = Tenant::where('slug', $slug)->first();

        if (! $tenant) {
            throw new NotFoundHttpException('Tenant not found.');
        }

        TenantContext::set($tenant);

        try {
            return $next($request);
        } finally {
            TenantContext::forget();
        }
    }
}
