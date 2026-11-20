<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\User;

/**
 * Phase 6: Thread-Safe & Request-Scoped Tenant Context
 * Holds the current active tenant boundary for the request lifecycle.
 */
class TenantContext
{
    private ?int $tenantId = null;

    private bool $isSuperAdmin = false;

    public function setTenantId(?int $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    public function setSuperAdmin(bool $isSuperAdmin): void
    {
        $this->isSuperAdmin = $isSuperAdmin;
    }

    public function isSuperAdmin(): bool
    {
        return $this->isSuperAdmin;
    }

    /**
     * Initialize context from an authenticated User model.
     */
    public function setFromUser(?User $user, ?int $overrideTenantId = null): void
    {
        if ($user === null) {
            $this->tenantId = null;
            $this->isSuperAdmin = false;

            return;
        }

        $this->isSuperAdmin = ($user->role === 'SuperAdmin');

        // SuperAdmin can explicitly impersonate or filter by a specific tenant
        if ($this->isSuperAdmin && $overrideTenantId !== null) {
            $this->tenantId = $overrideTenantId;
        } elseif ($this->isSuperAdmin) {
            // Unrestricted global access
            $this->tenantId = null;
        } else {
            // Strictly pinned to the user's tenant
            $this->tenantId = (int) $user->tenant_id;
        }
    }

    /**
     * Clear context (useful for testing and reset).
     */
    public function reset(): void
    {
        $this->tenantId = null;
        $this->isSuperAdmin = false;
    }
}
