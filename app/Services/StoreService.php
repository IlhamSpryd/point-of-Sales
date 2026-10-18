<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StoreService
{
    public function __construct(
        protected AuditTrailService $auditTrail,
        protected EntitlementService $entitlementService
    ) {}

    /**
     * Membangun kueri daftar cabang yang terisolasi per tenant aktif.
     */
    public function getFilteredQuery(Request $request): Builder
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        $query = Store::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->latest('id');

        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $isActive = $request->input('status') === 'active';
            $query->where('is_active', $isActive);
        }

        return $query;
    }

    /**
     * Mendaftarkan cabang baru di bawah tenant aktif.
     */
    public function store(array $data): Store
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        $this->entitlementService->assertCanCreateStore($tenantId);

        return DB::transaction(function () use ($tenantId, $data) {
            $store = new Store($data);
            $store->tenant_id = $tenantId;

            // Auto generate store code jika tidak diisi
            if (empty($store->code)) {
                $latest = Store::withoutTenantScope()->where('tenant_id', $tenantId)->latest('id')->first();
                $nextId = $latest ? $latest->id + 1 : 1;
                $store->code = 'STR-' . str_pad((string) $nextId, 3, '0', STR_PAD_LEFT);
            }

            $store->save();

            // Hubungkan owner yang sedang login ke cabang baru di user_stores
            if ($user = Auth::user()) {
                DB::table('user_stores')->insertOrIgnore([
                    'tenant_id' => $tenantId,
                    'user_id' => $user->id,
                    'store_id' => $store->id,
                ]);
            }

            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'store.created',
                userId: Auth::id(),
                storeId: $store->id,
                subjectType: Store::class,
                subjectId: $store->id,
                description: "Cabang baru '{$store->name}' ({$store->code}) berhasil dibuat.",
                newValues: $store->only(['name', 'code', 'timezone', 'address', 'phone', 'is_active'])
            );

            return $store;
        });
    }

    /**
     * Memperbarui informasi cabang.
     */
    public function update(Store $store, array $data): Store
    {
        return DB::transaction(function () use ($store, $data) {
            $oldValues = $store->only(['name', 'code', 'timezone', 'address', 'phone', 'is_active']);

            $store->update($data);

            $this->auditTrail->log(
                tenantId: (int) $store->tenant_id,
                action: 'store.updated',
                userId: Auth::id(),
                storeId: $store->id,
                subjectType: Store::class,
                subjectId: $store->id,
                description: "Informasi cabang '{$store->name}' ({$store->code}) diperbarui.",
                oldValues: $oldValues,
                newValues: $store->only(['name', 'code', 'timezone', 'address', 'phone', 'is_active'])
            );

            return $store;
        });
    }

    /**
     * Menghapus cabang dengan aturan perlindungan invarian operasional.
     *
     * @throws DomainException jika cabang adalah cabang awal, masih memiliki shift buka, atau perangkat aktif.
     */
    public function delete(Store $store): bool
    {
        if ($store->is_initial) {
            throw new DomainException('Cabang utama (initial store) tidak dapat dihapus.');
        }

        // 1. Cek shift aktif
        $hasOpenShift = Shift::withoutGlobalScopes()
            ->where('tenant_id', $store->tenant_id)
            ->where('store_id', $store->id)
            ->where('status', 'open')
            ->exists();

        if ($hasOpenShift) {
            throw new DomainException('Tidak dapat menghapus cabang yang masih memiliki shift kasir aktif.');
        }

        // 2. Cek perangkat aktif
        $hasActiveDevices = Device::withoutGlobalScopes()
            ->where('tenant_id', $store->tenant_id)
            ->where('store_id', $store->id)
            ->where('status', Device::STATUS_PAIRED)
            ->where('is_active', true)
            ->exists();

        if ($hasActiveDevices) {
            throw new DomainException('Tidak dapat menghapus cabang yang masih memiliki perangkat POS terhubung (paired).');
        }

        return DB::transaction(function () use ($store) {
            $tenantId = (int) $store->tenant_id;
            $storeId = (int) $store->id;
            $storeName = $store->name;
            $storeCode = $store->code;

            // Lepaskan penugasan di user_stores
            DB::table('user_stores')
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->delete();

            $deleted = $store->delete();

            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'store.deleted',
                userId: Auth::id(),
                storeId: $storeId,
                subjectType: Store::class,
                subjectId: $storeId,
                description: "Cabang '{$storeName}' ({$storeCode}) berhasil dihapus."
            );

            return (bool) $deleted;
        });
    }
}
