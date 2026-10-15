<?php

namespace App\Models\Scopes;

use App\Services\Context\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope: membatasi query ke store aktif (cabang) pada TenantContext.
 * FAIL-CLOSED: bila storeId null dan tidak dalam bypass eksplisit (allStores()),
 * injeksikan klausa '1 = 0' agar tidak pernah fail-open membocorkan data store lain.
 */
class StoreScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return;
        }

        $storeId = $context->getStoreId();

        if ($storeId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('store_id'), $storeId);
    }
}
