<?php

namespace Database\Seeders\Pos\Concerns;

use App\Models\Store;
use App\Services\Context\TenantContext;
use Illuminate\Support\Facades\Schema;

/**
 * Helper untuk sub-seeder Pos yang memakai bulk DB::table()->insert().
 *
 * Phase 9/11: tenant_id dan (bila tabel memilikinya) store_id NOT NULL,
 * sedangkan bulk insert TIDAK memicu event model AssignsTenant/AssignsStore.
 * Trait ini mengambil tenant & store dari konteks aktif (dipasang oleh
 * PosFullSeeder/DatabaseSeeder) dan menyuntikkan kedua kolom ke setiap row.
 *
 * Nama tabel tujuan diberikan eksplisit oleh pemanggil `withTenant($rows, 'tabel')`
 * sehingga hanya kolom store_id yang benar-benar ada yang diisi.
 */
trait StampsTenantOnBulkInsert
{
    protected function tenantId(): int
    {
        return app(TenantContext::class)->requireTenantId();
    }

    protected function storeId(): int
    {
        $context = app(TenantContext::class);
        $storeId = $context->getStoreId();

        if ($storeId !== null) {
            return $storeId;
        }

        $store = Store::query()
            ->where('tenant_id', $context->requireTenantId())
            ->orderBy('id')
            ->first();

        if (! $store) {
            throw new \RuntimeException('Seeder membutuhkan store (cabang) untuk tenant ini.');
        }

        $context->setStoreId($store->id);

        return $store->id;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function withTenant(array $rows, ?string $table = null): array
    {
        $tenantId = $this->tenantId();
        $storeId = $this->storeId();
        $hasStoreColumn = $table !== null && Schema::hasColumn($table, 'store_id');

        return array_map(static function (array $row) use ($tenantId, $storeId, $hasStoreColumn): array {
            $row = ['tenant_id' => $tenantId] + $row;

            if ($hasStoreColumn && ! array_key_exists('store_id', $row)) {
                $row['store_id'] = $storeId;
            }

            return $row;
        }, $rows);
    }
}
