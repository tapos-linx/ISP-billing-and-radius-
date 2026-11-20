<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Rbac\RbacService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'tenant_id',
        'role',
        'status',
        'api_token',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'api_token',
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
            'tenant_id' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === RbacService::ROLE_SUPER_ADMIN;
    }

    public function isTenantAdmin(): bool
    {
        return $this->role === RbacService::ROLE_TENANT_ADMIN;
    }

    public function isNoc(): bool
    {
        return $this->role === RbacService::ROLE_NOC_ENGINEER;
    }

    public function isReseller(): bool
    {
        return $this->role === RbacService::ROLE_RESELLER;
    }

    public function isBilling(): bool
    {
        return $this->role === RbacService::ROLE_BILLING_CLERK;
    }

    public function canAccess(string $permission, ?Model $resource = null): bool
    {
        return app(RbacService::class)->can($this, $permission, $resource);
    }
}
