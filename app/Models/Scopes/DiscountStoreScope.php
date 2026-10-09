<?php

namespace App\Models\Scopes;

use App\Services\Context\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Discount bersifat tenant-wide bila store_id NULL, atau spesifik cabang
 * bila store_id terisi. Karena itu filter-nya:
 *   store_id IS NULL OR store_id = active store
 */
class DiscountStoreScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return;
        }

        $storeId = $context->getStoreId();

        if ($storeId === null) {
            return;
        }

        $builder->where(function (Builder $q) use ($model, $storeId) {
            $q->whereNull($model->getTable().'.store_id')
                ->orWhere($model->getTable().'.store_id', $storeId);
        });
    }
}
