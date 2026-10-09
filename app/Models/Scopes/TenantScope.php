<?php

namespace App\Models\Scopes;

use App\Services\Context\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use RuntimeException;

/**
 * Global scope: membatasi seluruh query model ke tenant pada TenantContext.
 * Melempar RuntimeException bila konteks tenant belum diset — kegagalan cepat,
 * bukan kebocoran data lintas tenant.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);
        if ($context->hasTenant() === false && $context->isBypassed()) {
            return;
        }
        $tenantId = $context->requireTenantId();
        $builder->where($model->getTable().'.tenant_id', $tenantId);
    }
}
