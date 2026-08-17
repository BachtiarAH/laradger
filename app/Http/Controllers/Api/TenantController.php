<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTenantRequest;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class TenantController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $tenants = request()->user()
            ->tenants()
            ->orderBy('name')
            ->paginate();

        return TenantResource::collection($tenants);
    }

    public function store(StoreTenantRequest $request): JsonResponse
    {
        $tenant = Tenant::create([
            'name' => $request->validated('name'),
            'slug' => $request->validated('slug')
                ?? Str::slug($request->validated('name')).'-'.Str::lower(Str::random(6)),
        ]);

        $tenant->users()->attach($request->user(), ['role' => 'owner']);

        $tenantWithRole = $request->user()
            ->tenants()
            ->whereKey($tenant->id)
            ->firstOrFail();

        return (new TenantResource($tenantWithRole))
            ->response()
            ->setStatusCode(201);
    }
}
