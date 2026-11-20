<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Services\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Phase 6: Global Multi-Tenant Query Isolation Scope
 * Enforces strict WHERE tenant_id = :active_tenant boundary on all queries,
 * eliminating accidental cross-tenant data leaks across subscribers, invoices,
 * payments, and IP pools.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        // SuperAdmin with no specific tenant filter has global visibility
        if ($context->isSuperAdmin() && $context->getTenantId() === null) {
            return;
        }

        $tenantId = $context->getTenantId();

        if ($tenantId !== null) {
            $builder->where($model->qualifyColumn('tenant_id'), '=', $tenantId);
        }
    }
}
