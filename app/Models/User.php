<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'is_admin', 'status', 'ai_provider', 'ai_model', 'ai_api_key', 'ai_base_uri', 'ai_endpoint'])]
#[Hidden(['password', 'remember_token', 'ai_api_key'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    /**
     * Attribute defaults so freshly created instances never hold null
     * for columns whose database default the model may not know about.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_admin' => false,
        'status' => 'active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'status' => UserStatus::class,
            'ai_api_key' => 'encrypted',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->is_admin;
    }

    /**
     * Whether the user stored their own AI provider API key in-app. When false
     * the AI gateway falls back to the environment configuration.
     */
    public function hasAiApiKey(): bool
    {
        return filled($this->ai_api_key);
    }

    /**
     * The provider this user selected in-app, if any. Null means the gateway
     * keeps using the environment default.
     */
    public function aiProviderName(): ?string
    {
        return filled($this->ai_provider) ? (string) $this->ai_provider : null;
    }

    /**
     * Whether the account may log in (i.e. it is not suspended or terminated).
     */
    public function hasActiveStatus(): bool
    {
        return ! $this->status->isBlocked();
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }

    public function tenants()
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function belongsToTenant(string|Tenant $tenant): bool
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;

        return $this->tenants()->whereKey($tenantId)->exists();
    }
}
