<?php

declare(strict_types=1);

namespace App\Services\Rbac;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 6: Role-Based Access Control (RBAC) & Granular Permission Engine
 * Defines carrier roles: SuperAdmin, TenantAdmin, NocEngineer, Reseller, BillingClerk.
 * Validates role-to-permission mapping and strictly blocks cross-tenant resource tampering.
 */
class RbacService
{
    public const ROLE_SUPER_ADMIN = 'SuperAdmin';

    public const ROLE_TENANT_ADMIN = 'TenantAdmin';

    public const ROLE_NOC_ENGINEER = 'NocEngineer';

    public const ROLE_RESELLER = 'Reseller';

    public const ROLE_BILLING_CLERK = 'BillingClerk';

    /**
     * Role-to-permission mapping.
     */
    private const ROLE_PERMISSIONS = [
        self::ROLE_SUPER_ADMIN => [
            '*',
        ],
        self::ROLE_TENANT_ADMIN => [
            'subscribers.view',
            'subscribers.create',
            'subscribers.update',
            'subscribers.delete',
            'invoices.view',
            'invoices.create',
            'invoices.settle',
            'payments.view',
            'ip_pools.view',
            'radius.coa',
            'radius.view_sessions',
            'reports.vat',
            'reports.btrc',
            'users.view',
            'users.manage',
        ],
        self::ROLE_NOC_ENGINEER => [
            'subscribers.view',
            'radius.coa',
            'radius.view_sessions',
            'nas.manage',
            'nas.view',
            'ip_pools.view',
            'metrics.view',
        ],
        self::ROLE_RESELLER => [
            'subscribers.view',
            'subscribers.create',
            'subscribers.update',
            'invoices.view',
            'payments.view',
            'ip_pools.view',
        ],
        self::ROLE_BILLING_CLERK => [
            'subscribers.view',
            'invoices.view',
            'invoices.create',
            'invoices.settle',
            'payments.view',
            'payments.refund',
            'reports.vat',
            'reports.btrc',
        ],
    ];

    /**
     * Check if a user has a specific permission.
     * If a target resource (e.g. Subscriber or Invoice) is provided,
     * cross-tenant access is strictly verified.
     */
    public function can(User $user, string $permission, ?Model $resource = null): bool
    {
        // 1. SuperAdmin has unrestricted global permissions
        if ($user->role === self::ROLE_SUPER_ADMIN) {
            return true;
        }

        // 2. Validate role permission grant
        $userPerms = self::ROLE_PERMISSIONS[$user->role] ?? [];
        $hasPermission = in_array('*', $userPerms, true)
            || in_array($permission, $userPerms, true)
            || $this->matchesWildcard($userPerms, $permission);

        if (! $hasPermission) {
            return false;
        }

        // 3. Enforce Cross-Tenant Data Isolation Boundary
        if ($resource !== null && isset($resource->tenant_id)) {
            if ((int) $resource->tenant_id !== (int) $user->tenant_id) {
                // Cross-tenant data leak attempted
                return false;
            }
        }

        return true;
    }

    /**
     * Check if user possesses any of the given roles.
     */
    public function hasRole(User $user, array|string $roles): bool
    {
        $roleList = is_array($roles) ? $roles : explode(',', $roles);
        $roleList = array_map('trim', $roleList);

        if ($user->role === self::ROLE_SUPER_ADMIN) {
            return true;
        }

        return in_array($user->role, $roleList, true);
    }

    private function matchesWildcard(array $userPerms, string $permission): bool
    {
        $parts = explode('.', $permission, 2);
        if (count($parts) === 2) {
            $wildcard = $parts[0].'.*';

            return in_array($wildcard, $userPerms, true);
        }

        return false;
    }
}
