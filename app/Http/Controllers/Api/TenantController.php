<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
}
