<?php

namespace App\Models\Scopes;

use App\Services\Context\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Discount bersifat tenant-wide bila store_id NULL, atau spesifik cabang
 * bila store_id terisi.
 *
 * PENGELOMPOKAN BOOLEAN BERTANDA KURUNG (D1-DB-007):
 * Kueri WAJIB dikelompokkan dengan tanda kurung:
 *   WHERE tenant_id = ? AND (store_id = ? OR store_id IS NULL)
 * Tanpa pengelompokan ini, kondisi OR store_id IS NULL akan membocorkan
 * diskon global milik tenant lain ke tenant aktif!
 */
class DiscountStoreScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return;
        }

        $tenantId = $context->getTenantId();

        if ($tenantId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);

        $storeId = $context->getStoreId();

        if ($storeId !== null) {
            $builder->where(function (Builder $query) use ($model, $storeId) {
                $query->where($model->qualifyColumn('store_id'), $storeId)
                    ->orWhereNull($model->qualifyColumn('store_id'));
            });
        }
    }
}
