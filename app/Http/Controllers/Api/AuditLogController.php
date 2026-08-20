<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function index(string $tenant): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AuditLog::class);

        return AuditLogResource::collection(
            AuditLog::with('user', 'journal')->latest()->paginate()
        );
    }

    public function show(string $tenant, AuditLog $auditLog): AuditLogResource
    {
        $this->authorize('view', $auditLog);

        return new AuditLogResource($auditLog->load('user', 'journal'));
    }
}
