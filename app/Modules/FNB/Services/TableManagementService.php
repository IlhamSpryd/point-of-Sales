<?php

declare(strict_types=1);

namespace App\Modules\FNB\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\Table;
use App\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TableManagementService
{
    public function __construct(
        protected AuditTrailService $auditTrail,
        protected TenantContext $tenantContext
    ) {}

    /**
     * Buka pesanan meja (check-in tamu ke meja).
     *
     * @throws DomainException
     */
    public function checkInTable(int $tenantId, int $storeId, int $tableId, int $guestCount = 1, ?int $userId = null): Table
    {
        if ($guestCount <= 0) {
            throw new InvalidArgumentException('Jumlah tamu harus minimal 1 orang.');
        }

        return DB::transaction(function () use ($tenantId, $storeId, $tableId, $guestCount, $userId) {
            $table = Table::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('id', $tableId)
                ->lockForUpdate()
                ->first();

            if (! $table) {
                throw new DomainException('Table not found at this branch.');
            }

            if (! $table->is_active) {
                throw new DomainException("Table {$table->table_name} is currently inactive.");
            }

            if ($table->operational_status === 'occupied') {
                throw new DomainException("Table {$table->table_name} is currently occupied.");
            }

            $table->update([
                'operational_status' => 'occupied',
            ]);

            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'fnb.table_checkin',
                userId: $userId,
                storeId: $storeId,
                subjectType: Table::class,
                subjectId: $table->id,
                description: "Check-in meja {$table->table_name} untuk {$guestCount} tamu.",
                metadata: [
                    'table_id' => $table->id,
                    'table_name' => $table->table_name,
                    'guest_count' => $guestCount,
                ]
            );

            return $table;
        });
    }

    /**
     * Pindah meja (Table Move): Memindahkan pesanan aktif dari meja sumber ke meja tujuan.
     *
     * @return array{source_table: Table, target_table: Table, moved_orders_count: int}
     *
     * @throws DomainException
     */
    public function moveTable(int $tenantId, int $storeId, int $sourceTableId, int $targetTableId, int $userId): array
    {
        if ($sourceTableId === $targetTableId) {
            throw new InvalidArgumentException('Meja sumber dan meja tujuan tidak boleh sama.');
        }

        return DB::transaction(function () use ($tenantId, $storeId, $sourceTableId, $targetTableId, $userId) {
            // Lock kedua meja dalam urutan ID ASC untuk mencegah deadlock
            $ids = [$sourceTableId, $targetTableId];
            sort($ids);

            $tables = Table::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $sourceTable = $tables->get($sourceTableId);
            $targetTable = $tables->get($targetTableId);

            if (! $sourceTable || ! $targetTable) {
                throw new DomainException('One or both tables not found.');
            }

            if ($targetTable->operational_status === 'occupied') {
                throw new DomainException("Target table ({$targetTable->table_name}) is currently occupied.");
            }

            // Ambil semua order aktif yang belum lunas di meja sumber
            $activeOrders = Order::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('table_id', $sourceTable->id)
                ->whereNotIn('order_status', ['paid', 'completed', 'void', 'cancelled'])
                ->lockForUpdate()
                ->get();

            if ($activeOrders->isEmpty()) {
                throw new DomainException("No active orders found on source table ({$sourceTable->table_name}).");
            }

            // Pindahkan pesanan ke meja tujuan
            foreach ($activeOrders as $order) {
                $order->update([
                    'table_id' => $targetTable->id,
                    'table_number' => $targetTable->table_name,
                ]);
            }

            // Update status operasional kedua meja
            $sourceTable->update(['operational_status' => 'available']);
            $targetTable->update(['operational_status' => 'occupied']);

            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'fnb.table_move',
                userId: $userId,
                storeId: $storeId,
                subjectType: Table::class,
                subjectId: $targetTable->id,
                description: "Moved table from {$sourceTable->table_name} to {$targetTable->table_name} ({$activeOrders->count()} orders moved).",
                metadata: [
                    'source_table_id' => $sourceTable->id,
                    'target_table_id' => $targetTable->id,
                    'orders_count' => $activeOrders->count(),
                ]
            );

            return [
                'source_table' => $sourceTable,
                'target_table' => $targetTable,
                'moved_orders_count' => $activeOrders->count(),
            ];
        });
    }

    /**
     * Gabung meja (Table Merge): Menggabungkan meja-meja sekunder ke dalam satu meja utama.
     *
     * @param  array<int>  $secondaryTableIds
     * @return array{primary_table: Table, merged_table_ids: array<int>, orders_consolidated: int}
     *
     * @throws DomainException
     */
    public function mergeTables(int $tenantId, int $storeId, int $primaryTableId, array $secondaryTableIds, int $userId): array
    {
        $filteredSecondary = array_values(array_filter(array_unique($secondaryTableIds), fn ($id) => $id !== $primaryTableId));
        if (empty($filteredSecondary)) {
            throw new InvalidArgumentException('Secondary tables list must contain at least one table other than the primary table.');
        }

        return DB::transaction(function () use ($tenantId, $storeId, $primaryTableId, $filteredSecondary, $userId) {
            $allIds = array_merge([$primaryTableId], $filteredSecondary);
            sort($allIds);

            $tables = Table::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->whereIn('id', $allIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $primaryTable = $tables->get($primaryTableId);
            if (! $primaryTable) {
                throw new DomainException('Primary table not found.');
            }

            // Pindahkan seluruh pesanan aktif meja sekunder ke meja utama
            $movedOrders = 0;
            foreach ($filteredSecondary as $secId) {
                $secTable = $tables->get($secId);
                if (! $secTable) {
                    continue;
                }

                $orders = Order::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('store_id', $storeId)
                    ->where('table_id', $secTable->id)
                    ->whereNotIn('order_status', ['paid', 'completed', 'void', 'cancelled'])
                    ->lockForUpdate()
                    ->get();

                foreach ($orders as $order) {
                    $order->update([
                        'table_id' => $primaryTable->id,
                        'table_number' => $primaryTable->table_name,
                    ]);
                    $movedOrders++;
                }

                $secTable->update(['operational_status' => 'occupied']);
            }

            $primaryTable->update(['operational_status' => 'occupied']);

            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'fnb.tables_merged',
                userId: $userId,
                storeId: $storeId,
                subjectType: Table::class,
                subjectId: $primaryTable->id,
                description: "Tables merged into primary table {$primaryTable->table_name}.",
                metadata: [
                    'primary_table_id' => $primaryTable->id,
                    'secondary_table_ids' => $filteredSecondary,
                    'moved_orders' => $movedOrders,
                ]
            );

            return [
                'primary_table' => $primaryTable,
                'merged_table_ids' => $filteredSecondary,
                'orders_consolidated' => $movedOrders,
            ];
        });
    }

    /**
     * Mencetak tagihan sementara (Proforma Bill).
     * Wajib mencantumkan tanda tegas: "PROFORMA INVOICE - NOT A PROOF OF PAYMENT".
     *
     * @return array{disclaimer: string, is_proforma: bool, table: array{id: int, table_name: string, area: string, capacity: int}, order: array{id: int, order_code: string, subtotal: float, discount: float, tax: float, service_charge: float, total_amount: float, order_status: string}, items: array<int, array{product_name: string, quantity: int, price: float, subtotal: float, notes: ?string}>, generated_at: string}
     *
     * @throws DomainException
     */
    public function generateProformaBill(int $tenantId, int $storeId, int $tableId): array
    {
        $table = Table::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('id', $tableId)
            ->first();

        if (! $table) {
            throw new DomainException('Table not found.');
        }

        $activeOrder = Order::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('table_id', $table->id)
            ->whereNotIn('order_status', ['paid', 'completed', 'void', 'cancelled'])
            ->with(['orderItems.product'])
            ->latest('id')
            ->first();

        if (! $activeOrder) {
            throw new DomainException("No active orders found on table {$table->table_name}.");
        }

        $items = [];
        foreach ($activeOrder->orderItems as $item) {
            $product = $item->product;
            $productName = $item->product_name_snapshot ?: ($product instanceof Product ? $product->product_name : 'Item');
            $items[] = [
                'product_name' => (string) $productName,
                'quantity' => (int) $item->qty,
                'price' => (float) $item->order_price,
                'subtotal' => (float) $item->order_subtotal,
                'notes' => $item->notes,
            ];
        }

        return [
            'disclaimer' => 'PROFORMA INVOICE - NOT A PROOF OF PAYMENT',
            'is_proforma' => true,
            'table' => [
                'id' => (int) $table->id,
                'table_name' => (string) $table->table_name,
                'area' => (string) ($table->area ?? 'General'),
                'capacity' => (int) $table->capacity,
            ],
            'order' => [
                'id' => (int) $activeOrder->id,
                'order_code' => (string) $activeOrder->order_code,
                'subtotal' => (float) $activeOrder->subtotal_amount,
                'discount' => (float) $activeOrder->discount_amount,
                'tax' => (float) $activeOrder->tax_amount,
                'service_charge' => (float) $activeOrder->service_charge_amount,
                'total_amount' => (float) $activeOrder->order_amount,
                'order_status' => (string) $activeOrder->order_status->value,
            ],
            'items' => $items,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Membebaskan meja kembali AVAILABLE hanya setelah pesanan lunas.
     *
     * @throws DomainException
     */
    public function releaseTableAfterPayment(int $tenantId, int $storeId, int $tableId): bool
    {
        return DB::transaction(function () use ($tenantId, $storeId, $tableId) {
            $table = Table::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('id', $tableId)
                ->lockForUpdate()
                ->first();

            if (! $table) {
                throw new DomainException('Table not found.');
            }

            // Cek apakah masih ada pesanan yang belum lunas di meja ini
            $hasUnpaidOrder = Order::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('table_id', $table->id)
                ->whereNotIn('order_status', ['paid', 'completed', 'void', 'cancelled'])
                ->exists();

            if ($hasUnpaidOrder) {
                throw new DomainException("Table {$table->table_name} cannot be released because it has unpaid orders.");
            }

            $table->update([
                'operational_status' => 'available',
            ]);

            return true;
        });
    }
}
