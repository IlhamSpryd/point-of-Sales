<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Models\Order;
use App\Models\Table;
use App\Services\AuditTrailService;
use DomainException;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TableService mengisolasi seluruh logika CRUD Meja, siklus hidup QR,
 * dan sinkronisasi status meja dari Controller.
 */
class TableService
{
    public function getFilteredQuery(Request $request): Builder
    {
        $query = Table::query()->latest('id');
        if ($request->has('search') && $request->search) {
            $query->where('table_name', 'like', '%'.$request->search.'%');
        }

        return $query;
    }

    /**
     * Membuat meja baru. qr_token & secure_token otomatis di-generate
     * oleh model event Table::booted().
     */
    public function store(array $data): Table
    {
        return DB::transaction(function () use ($data) {
            $table = Table::create($data);

            try {
                app(AuditTrailService::class)->log(
                    tenantId: (int) $table->tenant_id,
                    action: 'table.created',
                    userId: auth()->id(),
                    storeId: (int) $table->store_id,
                    subjectType: Table::class,
                    subjectId: (int) $table->id,
                    description: "Meja '{$table->table_name}' dibuat pada cabang #{$table->store_id}.",
                    newValues: ['name' => $table->table_name, 'capacity' => $table->capacity, 'store_id' => $table->store_id]
                );
            } catch (\Throwable) {
            }

            return $table;
        });
    }

    public function update(Table $table, array $data): Table
    {
        return DB::transaction(function () use ($table, $data) {
            $oldValues = $table->only(['table_name', 'capacity', 'area', 'operational_status', 'is_active']);
            $table->update($data);

            try {
                app(AuditTrailService::class)->log(
                    tenantId: (int) $table->tenant_id,
                    action: 'table.updated',
                    userId: auth()->id(),
                    storeId: (int) $table->store_id,
                    subjectType: Table::class,
                    subjectId: (int) $table->id,
                    description: "Meja '{$table->table_name}' diperbarui.",
                    oldValues: $oldValues,
                    newValues: $table->only(['table_name', 'capacity', 'area', 'operational_status', 'is_active'])
                );
            } catch (\Throwable) {
            }

            return $table;
        });
    }

    /**
     * Menghapus meja. Ditolak jika meja masih memiliki riwayat pesanan,
     * demi menjaga integritas audit trail (INV-TBL-07).
     */
    public function delete(Table $table): bool
    {
        return DB::transaction(function () use ($table) {
            if ($table->orders()->exists()) {
                throw new Exception('Tidak dapat menghapus meja "'.$table->table_name.'" karena masih memiliki riwayat pesanan.');
            }

            try {
                app(AuditTrailService::class)->log(
                    tenantId: (int) $table->tenant_id,
                    action: 'table.deleted',
                    userId: auth()->id(),
                    storeId: (int) $table->store_id,
                    subjectType: Table::class,
                    subjectId: (int) $table->id,
                    description: "Meja '{$table->table_name}' dihapus."
                );
            } catch (\Throwable) {
            }

            return (bool) $table->delete();
        });
    }

    /**
     * Memperbarui kredensial QR Code dengan token kriptografis 64-hex baru.
     * Menaikkan qr_token_version, memperbarui qr_rotated_at, dan menghapus cache lama.
     * Nilai mentah token dilarang bocor ke log (ADR-010).
     */
    public function rotateQrToken(Table $table, ?int $actorId = null): Table
    {
        return DB::transaction(function () use ($table, $actorId) {
            $oldToken = $table->qr_token;
            $newToken = bin2hex(random_bytes(32));
            $newVersion = ((int) $table->qr_token_version) + 1;

            $table->update([
                'qr_token' => $newToken,
                'qr_token_version' => $newVersion,
                'qr_rotated_at' => now(),
                'secure_token' => (string) Str::uuid(),
            ]);

            // Bersihkan cache terkait meja ini secara atomik
            Cache::forget('pos:qr:table:'.$table->id);
            if ($oldToken) {
                Cache::forget('pos:qr:token:'.$oldToken);
            }

            try {
                app(AuditTrailService::class)->log(
                    tenantId: (int) $table->tenant_id,
                    action: 'table.qr_rotated',
                    userId: $actorId ?? auth()->id(),
                    storeId: (int) $table->store_id,
                    subjectType: Table::class,
                    subjectId: (int) $table->id,
                    description: "QR Code meja '{$table->table_name}' dirotasi ke versi {$newVersion}.",
                    metadata: [
                        'store_id' => $table->store_id,
                        'qr_token_version' => $newVersion,
                        'token_preview' => substr($newToken, 0, 8),
                    ]
                );
            } catch (\Throwable) {
            }

            return $table;
        });
    }

    /**
     * Membebaskan meja menjadi 'available'.
     * Menegakkan INV-TBL-07: wajib menolak pembebasan meja apabila masih
     * terdapat pesanan terkait yang belum dibayar (pending) dan belum dibatalkan (void/cancelled).
     */
    public function releaseTable(Table $table, ?int $actorId = null): Table
    {
        return DB::transaction(function () use ($table, $actorId) {
            // Kunci baris Table untuk serialisasi konkurensi (ADR-007)
            /** @var Table $lockedTable */
            $lockedTable = Table::withoutGlobalScopes()
                ->where('tenant_id', $table->tenant_id)
                ->where('store_id', $table->store_id)
                ->where('id', $table->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Periksa seluruh pesanan aktif pada meja ini
            $activeOrders = Order::withoutGlobalScopes()
                ->where('tenant_id', $lockedTable->tenant_id)
                ->where('store_id', $lockedTable->store_id)
                ->where('table_id', $lockedTable->id)
                ->whereIn('order_status', ['pending'])
                ->get();

            if ($activeOrders->isNotEmpty()) {
                $orderCodes = $activeOrders->pluck('order_code')->implode(', ');
                throw new DomainException("Tidak dapat membebaskan meja '{$lockedTable->table_name}' karena masih memiliki pesanan aktif belum lunas ({$orderCodes}).");
            }

            $lockedTable->update([
                'operational_status' => 'available',
            ]);

            try {
                app(AuditTrailService::class)->log(
                    tenantId: (int) $lockedTable->tenant_id,
                    action: 'table.released',
                    userId: $actorId ?? auth()->id(),
                    storeId: (int) $lockedTable->store_id,
                    subjectType: Table::class,
                    subjectId: (int) $lockedTable->id,
                    description: "Meja '{$lockedTable->table_name}' dibebaskan menjadi available."
                );
            } catch (\Throwable) {
            }

            return $lockedTable;
        });
    }

    /**
     * Menonaktifkan meja secara aman.
     * Ditolak jika meja saat ini sedang ditempati pesanan aktif.
     */
    public function disableTable(Table $table, ?int $actorId = null): Table
    {
        return DB::transaction(function () use ($table, $actorId) {
            /** @var Table $lockedTable */
            $lockedTable = Table::withoutGlobalScopes()
                ->where('tenant_id', $table->tenant_id)
                ->where('store_id', $table->store_id)
                ->where('id', $table->id)
                ->lockForUpdate()
                ->firstOrFail();

            $activeOrders = Order::withoutGlobalScopes()
                ->where('tenant_id', $lockedTable->tenant_id)
                ->where('store_id', $lockedTable->store_id)
                ->where('table_id', $lockedTable->id)
                ->whereIn('order_status', ['pending', 'paid'])
                ->exists();

            if ($activeOrders) {
                throw new DomainException("Tidak dapat menonaktifkan meja '{$lockedTable->table_name}' saat masih terdapat pesanan aktif.");
            }

            $lockedTable->update([
                'is_active' => false,
                'operational_status' => 'reserved', // atau nonaktif
            ]);

            try {
                app(AuditTrailService::class)->log(
                    tenantId: (int) $lockedTable->tenant_id,
                    action: 'table.disabled',
                    userId: $actorId ?? auth()->id(),
                    storeId: (int) $lockedTable->store_id,
                    subjectType: Table::class,
                    subjectId: (int) $lockedTable->id,
                    description: "Meja '{$lockedTable->table_name}' dinonaktifkan."
                );
            } catch (\Throwable) {
            }

            return $lockedTable;
        });
    }

    /**
     * Kompatibilitas wrapper lama untuk regenerateToken.
     */
    public function regenerateToken(Table $table): Table
    {
        return $this->rotateQrToken($table);
    }
}
