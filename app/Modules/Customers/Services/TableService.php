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
                    description: "Table '{$table->table_name}' created at branch #{$table->store_id}.",
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
                    description: "Table '{$table->table_name}' updated.",
                    oldValues: $oldValues,
                    newValues: $table->only(['table_name', 'capacity', 'area', 'operational_status', 'is_active'])
                );
            } catch (\Throwable) {
            }

            return $table;
        });
    }

    /**
     * Delete table. Rejected if table has order history to preserve audit trail integrity.
     */
    public function delete(Table $table): bool
    {
        return DB::transaction(function () use ($table) {
            if ($table->orders()->exists()) {
                throw new Exception('Cannot delete table "'.$table->table_name.'" because it has existing order history.');
            }

            try {
                app(AuditTrailService::class)->log(
                    tenantId: (int) $table->tenant_id,
                    action: 'table.deleted',
                    userId: auth()->id(),
                    storeId: (int) $table->store_id,
                    subjectType: Table::class,
                    subjectId: (int) $table->id,
                    description: "Table '{$table->table_name}' deleted."
                );
            } catch (\Throwable) {
            }

            return (bool) $table->delete();
        });
    }

    /**
     * Rotate table QR code credentials with a new 64-hex token.
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

            // Clear table cache atomically
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
                    description: "Table QR Code for '{$table->table_name}' rotated to version {$newVersion}.",
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
     * Release table to 'available'.
     */
    public function releaseTable(Table $table, ?int $actorId = null): Table
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
                ->whereIn('order_status', ['pending'])
                ->get();

            if ($activeOrders->isNotEmpty()) {
                $orderCodes = $activeOrders->pluck('order_code')->implode(', ');
                throw new DomainException("Cannot release table '{$lockedTable->table_name}' because it has pending unpaid orders ({$orderCodes}).");
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
                    description: "Table '{$lockedTable->table_name}' released and marked available."
                );
            } catch (\Throwable) {
            }

            return $lockedTable;
        });
    }

    /**
     * Safely deactivate table.
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
                throw new DomainException("Cannot deactivate table '{$lockedTable->table_name}' while it has active orders.");
            }

            $lockedTable->update([
                'is_active' => false,
                'operational_status' => 'reserved',
            ]);

            try {
                app(AuditTrailService::class)->log(
                    tenantId: (int) $lockedTable->tenant_id,
                    action: 'table.disabled',
                    userId: $actorId ?? auth()->id(),
                    storeId: (int) $lockedTable->store_id,
                    subjectType: Table::class,
                    subjectId: (int) $lockedTable->id,
                    description: "Table '{$lockedTable->table_name}' deactivated."
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
