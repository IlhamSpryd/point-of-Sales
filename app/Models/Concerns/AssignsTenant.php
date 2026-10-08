<?php

namespace App\Models\Concerns;

use App\Services\Context\TenantContext;
use Illuminate\Database\Eloquent\Builder;

trait AssignsTenant
{
    protected static function bootAssignsTenant()
    {
        static::creating(function ($model) {
            $context = app(TenantContext::class);

            if (empty($model->tenant_id)) {
                $model->tenant_id = $context->requireTenantId();
            } else {
                if ($context->hasTenant() && $model->tenant_id !== $context->getTenantId()) {
                    throw new \RuntimeException("Cannot create record for tenant {$model->tenant_id} while in context of tenant {$context->getTenantId()}");
                }
            }
        });

        static::addGlobalScope('tenant', function (Builder $builder) {
            $context = app(TenantContext::class);
            if ($context->hasTenant()) {
                $builder->where($builder->getModel()->getTable().'.tenant_id', $context->getTenantId());
            }
        });
    }
}
