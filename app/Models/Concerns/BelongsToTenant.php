<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::creating(function (Model $model): void {
            if (TenantContext::hasTenant() && ! $model->getAttribute('tenant_id')) {
                $model->setAttribute('tenant_id', TenantContext::id());
            }
        });

        static::addGlobalScope('tenant', function (Builder $builder): void {
            if (TenantContext::hasTenant()) {
                $builder->where($builder->getModel()->getTable().'.tenant_id', TenantContext::id());
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
