<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Tenant::where('slug', $request->route('tenant'))->first();

        if (! $tenant) {
            throw new NotFoundHttpException('Tenant not found.');
        }

        if (! $request->user()?->tenants()->whereKey($tenant->id)->exists()) {
            abort(403, 'You are not a member of this tenant.');
        }

        TenantContext::set($tenant);

        $response = $next($request);

        TenantContext::forget();

        return $response;
    }
}
