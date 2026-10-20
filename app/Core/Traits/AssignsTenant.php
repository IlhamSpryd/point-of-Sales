<?php

declare(strict_types=1);

namespace App\Core\Traits;

use App\Core\Context\TenantContext;
use RuntimeException;

trait AssignsTenant
{
    protected static function bootAssignsTenant(): void
    {
        static::creating(function ($model) {
            $context = app(TenantContext::class);

            if (empty($model->tenant_id)) {
                $model->tenant_id = $context->requireTenantId();
            } else {
                if ($context->hasTenant() && (int) $model->tenant_id !== (int) $context->getTenantId()) {
                    throw new RuntimeException("Cannot create record for tenant {$model->tenant_id} while in context of tenant {$context->getTenantId()}");
                }
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('tenant_id')) {
                throw new RuntimeException('Tenant ownership (tenant_id) cannot be modified once set.');
            }
        });
    }
}
