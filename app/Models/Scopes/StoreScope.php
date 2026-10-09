<?php

namespace App\Models\Scopes;

use App\Services\Context\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Membatasi query ke store aktif. Bila context tidak punya store
 * (operasi sistem / seeder / command), scope tidak menambahkan filter
 * agar operasi lintas-cabang sistem tetap berjalan -- sama seperti pola
 * TenantScope.isBypassed().
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
            return;
        }

        $builder->where($model->getTable().'.store_id', $storeId);
    }
}
