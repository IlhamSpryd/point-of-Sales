<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Enums\IngredientStockMovementTypeEnum;
use App\Enums\StockMovementType;
use App\Models\Ingredient;
use App\Models\Product;
use App\Services\AuditTrailService;
use App\Services\MenuCacheService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * InventoryLedgerService
 *
 * Layanan domain terpusat untuk orkestrasi mutasi persediaan, ledger append-only,
 * dan proyeksi saldo materialized tingkat cabang (Modul 4).
 *
 * Menegakkan Invariants:
 * - INV-M4-01: Isolasi referensial multi-tenant & cabang ketat.
 * - INV-M4-02: Pemisahan identitas item produk vs bahan baku (XOR).
 * - INV-M4-03: Immutabilitas ledger mutlak (hanya INSERT, no UPDATE/DELETE).
 * - INV-M4-04: Ekuivalensi atomik saldo terhadap ledger & kolom mirror legacy.
 * - INV-M4-05: Strict non-negative stock (OD-05).
 * - INV-M4-06: Konservasi massa dan akuntabilitas stok.
 * - INV-M4-07: Canonical lock ordering anti-deadlock (store_id, track_type, item_id).
 * - INV-M4-08: Aritmatika desimal eksak bcmath (OD-06).
 */
class InventoryLedgerService
{
    public function __construct(
        protected MenuCacheService $menuCache,
        protected AuditTrailService $auditTrail
    ) {}

    /**
     * Catat satu mutasi pergerakan stok dan sinkronisasi saldo.
     *
     * @param  'product'|'ingredient'  $itemType
     * @return array{movement: object, balance: object, previous_quantity: string, new_quantity: string}
     */
    public function recordMovement(
        int $tenantId,
        int $storeId,
        string $itemType,
        int $itemId,
        float|string $quantityDelta,
        StockMovementType|IngredientStockMovementTypeEnum|string $type,
        float|string|null $unitCost = null,
        string $reason = '',
        ?string $idempotencyKey = null,
        ?int $orderId = null,
        ?int $orderItemId = null,
        ?int $userId = null,
        ?int $storageLocationId = null // Forward-compatible parameter (OD-01)
    ): array {
        $movementItem = [
            'store_id' => $storeId,
            'item_type' => $itemType,
            'item_id' => $itemId,
            'quantity_delta' => (string) $quantityDelta,
            'type' => $type,
            'unit_cost' => $unitCost !== null ? (string) $unitCost : null,
            'reason' => $reason,
            'idempotency_key' => $idempotencyKey,
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'user_id' => $userId,
            'storage_location_id' => $storageLocationId,
        ];

        $results = $this->recordBatchMovements($tenantId, [$movementItem]);

        return $results[0];
    }

    /**
     * Catat kumpulan mutasi stok secara atomik dengan Canonical Lock Ordering.
     *
     * @param  array<int, array{
     *     store_id: int,
     *     item_type: 'product'|'ingredient',
     *     item_id: int,
     *     quantity_delta: float|string,
     *     type: StockMovementType|IngredientStockMovementTypeEnum|string,
     *     unit_cost?: float|string|null,
     *     reason?: string,
     *     idempotency_key?: ?string,
     *     order_id?: ?int,
     *     order_item_id?: ?int,
     *     user_id?: ?int,
     *     storage_location_id?: ?int
     * }>  $movements
     * @return array<int, array{movement: object, balance: object, previous_quantity: string, new_quantity: string}>
     *
     * @throws ValidationException|DomainException
     */
    public function recordBatchMovements(int $tenantId, array $movements): array
    {
        if (empty($movements)) {
            return [];
        }

        // 1. Cek Idempotency Key batch / item terlebih dahulu
        foreach ($movements as $item) {
            if (! empty($item['idempotency_key'])) {
                $existing = $this->findExistingMovement($tenantId, $item['item_type'], $item['idempotency_key']);
                if ($existing) {
                    $balance = $this->getBalance($tenantId, $item['store_id'], $item['item_type'], $item['item_id']);

                    return [[
                        'movement' => $existing,
                        'balance' => $balance,
                        'previous_quantity' => (string) ($balance->quantity ?? 0),
                        'new_quantity' => (string) ($balance->quantity ?? 0),
                    ]];
                }
            }
        }

        return DB::transaction(function () use ($tenantId, $movements) {
            // 2. Kumpulkan target unik untuk penguncian kanonik (INV-M4-07)
            $uniqueTargets = [];
            foreach ($movements as $m) {
                $key = "{$m['store_id']}_{$m['item_type']}_{$m['item_id']}";
                if (! isset($uniqueTargets[$key])) {
                    $uniqueTargets[$key] = [
                        'store_id' => (int) $m['store_id'],
                        'item_type' => $m['item_type'],
                        'item_id' => (int) $m['item_id'],
                        'net_delta' => '0',
                    ];
                }
                $uniqueTargets[$key]['net_delta'] = bcadd(
                    $uniqueTargets[$key]['net_delta'],
                    (string) $m['quantity_delta'],
                    6
                );
            }

            // Urutkan kunci kanonik: store_id ASC, track_type ASC ('ingredient' < 'product'), item_id ASC
            $sortedTargets = array_values($uniqueTargets);
            usort($sortedTargets, function (array $a, array $b): int {
                if ($a['store_id'] !== $b['store_id']) {
                    return $a['store_id'] <=> $b['store_id'];
                }
                $typeWeightA = $a['item_type'] === 'ingredient' ? 0 : 1;
                $typeWeightB = $b['item_type'] === 'ingredient' ? 0 : 1;
                if ($typeWeightA !== $typeWeightB) {
                    return $typeWeightA <=> $typeWeightB;
                }

                return $a['item_id'] <=> $b['item_id'];
            });

            // 3. Pastikan baris balance terinisialisasi sebelum lockForUpdate (10.1.2)
            foreach ($sortedTargets as $target) {
                $this->ensureBalanceRowExists($tenantId, $target['store_id'], $target['item_type'], $target['item_id']);
            }

            // 4. Kunci baris saldo dengan SELECT ... FOR UPDATE terurut
            $lockedBalances = [];
            foreach ($sortedTargets as $target) {
                $key = "{$target['store_id']}_{$target['item_type']}_{$target['item_id']}";
                $balanceRow = $this->lockBalanceRow($tenantId, $target['store_id'], $target['item_type'], $target['item_id']);

                if (! $balanceRow) {
                    throw new DomainException("Gagal mengunci saldo untuk {$target['item_type']} #{$target['item_id']} di cabang #{$target['store_id']}.");
                }

                // 5. Evaluasi Invariant Non-Negatif (INV-M4-05)
                $projectedQty = bcadd((string) $balanceRow->quantity, $target['net_delta'], 6);
                if (bccomp($projectedQty, '0', 4) < 0) {
                    $itemName = $this->resolveItemName($tenantId, $target['item_type'], $target['item_id']);
                    throw ValidationException::withMessages([
                        'stock' => "Stok {$target['item_type']} \"{$itemName}\" di cabang ini tidak mencukupi untuk mutasi keluar. (Tersedia: {$balanceRow->quantity}, Dibutuhkan: ".abs((float) $target['net_delta']).')',
                    ]);
                }

                $lockedBalances[$key] = $balanceRow;
            }

            // 6. Eksekusi mutasi baris per baris
            $results = [];
            $storesToFlushCache = [];

            foreach ($movements as $m) {
                $storeId = (int) $m['store_id'];
                $itemType = $m['item_type'];
                $itemId = (int) $m['item_id'];
                $delta = (string) $m['quantity_delta'];
                $key = "{$storeId}_{$itemType}_{$itemId}";

                $currentBalance = $lockedBalances[$key];
                $previousQty = (string) $currentBalance->quantity;
                $newQty = bcadd($previousQty, $delta, 6);

                $rawType = $m['type'] instanceof \BackedEnum ? $m['type']->value : (string) $m['type'];
                $reason = $m['reason'] ?? '';
                $idempotencyKey = $m['idempotency_key'] ?? "m4_ledger:{$tenantId}:{$storeId}:{$itemType}:{$itemId}:".Str::uuid()->toString();

                $currentAvgCost = (string) ($currentBalance->average_unit_cost ?? '0.0000');
                $currentLastCost = (string) ($currentBalance->last_unit_cost ?? '0.0000');

                // Kalkulasi WAC dan resolusi unit_cost
                $movementUnitCost = '0.0000';
                $newAvgCost = $currentAvgCost;
                $newLastCost = $currentLastCost;

                $inputUnitCost = $m['unit_cost'] ?? null;

                if (bccomp($delta, '0', 6) > 0) {
                    // Inbound mutation: gunakan unit cost input jika ada, lalu perbarui WAC
                    $inboundCost = $inputUnitCost !== null ? (string) $inputUnitCost : $currentAvgCost;
                    $movementUnitCost = $inboundCost;
                    $newAvgCost = $this->calculateMovingAverageCost($previousQty, $currentAvgCost, $delta, $inboundCost);
                    $newLastCost = $inboundCost;
                } else {
                    // Outbound mutation: snapshot WAC cabang saat ini
                    $movementUnitCost = $inputUnitCost !== null ? (string) $inputUnitCost : $currentAvgCost;
                }

                // 7. Sisipkan baris immutable ledger
                $movementId = $this->insertLedgerMovement(
                    tenantId: $tenantId,
                    storeId: $storeId,
                    itemType: $itemType,
                    itemId: $itemId,
                    type: $rawType,
                    quantity: $delta,
                    unitCost: $movementUnitCost,
                    reason: $reason,
                    idempotencyKey: $idempotencyKey,
                    orderId: $m['order_id'] ?? null,
                    orderItemId: $m['order_item_id'] ?? null,
                    userId: $m['user_id'] ?? null
                );

                // 8. Perbarui baris proyeksi balance
                $this->updateBalanceRow(
                    tenantId: $tenantId,
                    storeId: $storeId,
                    itemType: $itemType,
                    itemId: $itemId,
                    newQuantity: $newQty,
                    newAvgCost: $newAvgCost,
                    newLastCost: $newLastCost,
                    lastMovementId: $movementId
                );

                // 9. Sinkronisasi kolom mirror legacy (products.stock / ingredients.current_stock)
                // secara atomik tanpa memicu re-trigger ganda (INV-M4-04)
                $this->syncLegacyMirrorColumn($tenantId, $itemType, $itemId, $delta);

                // Update memori balance untuk item berikutnya dalam batch yang sama
                $currentBalance->quantity = $newQty;
                $currentBalance->average_unit_cost = $newAvgCost;
                $currentBalance->last_unit_cost = $newLastCost;
                $currentBalance->last_movement_id = $movementId;

                $results[] = [
                    'movement' => (object) [
                        'id' => $movementId,
                        'tenant_id' => $tenantId,
                        'store_id' => $storeId,
                        'item_type' => $itemType,
                        'item_id' => $itemId,
                        'type' => $rawType,
                        'quantity' => $delta,
                        'unit_cost' => $movementUnitCost,
                        'idempotency_key' => $idempotencyKey,
                    ],
                    'balance' => $currentBalance,
                    'previous_quantity' => $previousQty,
                    'new_quantity' => $newQty,
                ];

                $storesToFlushCache[$storeId] = true;
            }

            // 10. Post-commit side effects (invalidasi menu cache)
            DB::afterCommit(function () use ($tenantId, $storesToFlushCache) {
                foreach (array_keys($storesToFlushCache) as $storeId) {
                    $this->menuCache->flush($tenantId, $storeId);
                }
            });

            return $results;
        });
    }

    /**
     * Hitung Moving Weighted Average Cost (WAC) secara eksak dengan bcmath (OD-06).
     *
     * Rumus: C_new = ((Q_old * C_old) + (Q_in * C_in)) / (Q_old + Q_in)
     */
    public function calculateMovingAverageCost(
        string|float $oldQty,
        string|float $oldAvgCost,
        string|float $incomingQty,
        string|float $incomingCost
    ): string {
        $qOld = (string) $oldQty;
        $cOld = (string) $oldAvgCost;
        $qIn = (string) $incomingQty;
        $cIn = (string) $incomingCost;

        if (bccomp($qIn, '0', 6) <= 0) {
            return bcadd($cOld, '0', 4);
        }

        if (bccomp($qOld, '0', 6) <= 0) {
            return bcadd($cIn, '0', 4);
        }

        $oldVal = bcmul($qOld, $cOld, 6);
        $inVal = bcmul($qIn, $cIn, 6);
        $totalVal = bcadd($oldVal, $inVal, 6);
        $totalQty = bcadd($qOld, $qIn, 6);

        if (bccomp($totalQty, '0', 6) === 0) {
            return '0.0000';
        }

        return bcdiv($totalVal, $totalQty, 4);
    }

    /**
     * Ambil data saldo saat ini untuk suatu item di cabang tertentu.
     */
    public function getBalance(int $tenantId, int $storeId, string $itemType, int $itemId, ?int $storageLocationId = null): ?object
    {
        $table = $itemType === 'product' ? 'product_stock_balances' : 'ingredient_stock_balances';
        $column = $itemType === 'product' ? 'product_id' : 'ingredient_id';

        return DB::table($table)
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where($column, $itemId)
            ->first();
    }

    /**
     * Ambil kuantitas stok yang tersedia (on-hand).
     */
    public function getAvailableQuantity(int $tenantId, int $storeId, string $itemType, int $itemId): float
    {
        $balance = $this->getBalance($tenantId, $storeId, $itemType, $itemId);

        return $balance ? (float) $balance->quantity : 0.0;
    }

    /**
     * Perbarui konfigurasi ambang batas reorder cabang (min, reorder point, max).
     */
    public function updateReorderThresholds(
        int $tenantId,
        int $storeId,
        string $itemType,
        int $itemId,
        float|string $minStock,
        float|string $reorderPoint,
        float|string|null $maxStock = null
    ): void {
        $min = (string) $minStock;
        $reorder = (string) $reorderPoint;
        $max = $maxStock !== null ? (string) $maxStock : null;

        if (bccomp($min, '0', 4) < 0) {
            throw new DomainException('min_stock_level tidak boleh bernilai negatif.');
        }

        if (bccomp($reorder, '0', 4) < 0) {
            throw new DomainException('reorder_point tidak boleh bernilai negatif.');
        }

        if ($max !== null && bccomp($max, $reorder, 4) < 0) {
            throw new DomainException('max_stock_level tidak boleh lebih kecil dari reorder_point.');
        }

        $table = $itemType === 'product' ? 'product_stock_balances' : 'ingredient_stock_balances';
        $column = $itemType === 'product' ? 'product_id' : 'ingredient_id';

        $this->ensureBalanceRowExists($tenantId, $storeId, $itemType, $itemId);

        DB::table($table)
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where($column, $itemId)
            ->update([
                'min_stock_level' => $min,
                'reorder_point' => $reorder,
                'max_stock_level' => $max,
                'updated_at' => now(),
            ]);
    }

    // =========================================================================
    // HELPER INTERNAL METHODS
    // =========================================================================

    private function findExistingMovement(int $tenantId, string $itemType, string $idempotencyKey): ?object
    {
        $table = $itemType === 'product' ? 'stock_movements' : 'ingredient_stock_movements';

        return DB::table($table)
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function ensureBalanceRowExists(int $tenantId, int $storeId, string $itemType, int $itemId): void
    {
        $table = $itemType === 'product' ? 'product_stock_balances' : 'ingredient_stock_balances';
        $column = $itemType === 'product' ? 'product_id' : 'ingredient_id';

        DB::table($table)->insertOrIgnore([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            $column => $itemId,
            'quantity' => 0,
            'baseline_quantity' => 0,
            'baseline_movement_id' => 0,
            'min_stock_level' => 0,
            'reorder_point' => 0,
            'max_stock_level' => null,
            'average_unit_cost' => 0,
            'last_unit_cost' => 0,
            'last_movement_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function lockBalanceRow(int $tenantId, int $storeId, string $itemType, int $itemId): ?object
    {
        $table = $itemType === 'product' ? 'product_stock_balances' : 'ingredient_stock_balances';
        $column = $itemType === 'product' ? 'product_id' : 'ingredient_id';

        return DB::table($table)
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where($column, $itemId)
            ->lockForUpdate()
            ->first();
    }

    private function insertLedgerMovement(
        int $tenantId,
        int $storeId,
        string $itemType,
        int $itemId,
        string $type,
        string $quantity,
        string $unitCost,
        string $reason,
        string $idempotencyKey,
        ?int $orderId,
        ?int $orderItemId,
        ?int $userId
    ): int {
        if ($itemType === 'product') {
            return (int) DB::table('stock_movements')->insertGetId([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'product_id' => $itemId,
                'order_id' => $orderId,
                'order_item_id' => $orderItemId,
                'type' => $type,
                'quantity' => (int) round((float) $quantity),
                'unit_cost' => $unitCost,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return (int) DB::table('ingredient_stock_movements')->insertGetId([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'ingredient_id' => $itemId,
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'type' => $type,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'reason' => $reason,
            'idempotency_key' => $idempotencyKey,
            'created_by' => $userId,
            'created_at' => now(),
        ]);
    }

    private function updateBalanceRow(
        int $tenantId,
        int $storeId,
        string $itemType,
        int $itemId,
        string $newQuantity,
        string $newAvgCost,
        string $newLastCost,
        int $lastMovementId
    ): void {
        $table = $itemType === 'product' ? 'product_stock_balances' : 'ingredient_stock_balances';
        $column = $itemType === 'product' ? 'product_id' : 'ingredient_id';

        DB::table($table)
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where($column, $itemId)
            ->update([
                'quantity' => $newQuantity,
                'average_unit_cost' => $newAvgCost,
                'last_unit_cost' => $newLastCost,
                'last_movement_id' => $lastMovementId,
                'updated_at' => now(),
            ]);
    }

    private function syncLegacyMirrorColumn(int $tenantId, string $itemType, int $itemId, string $delta): void
    {
        if ($itemType === 'product') {
            $intDelta = (int) round((float) $delta);
            DB::table('products')
                ->where('tenant_id', $tenantId)
                ->where('id', $itemId)
                ->update([
                    'stock' => DB::raw("stock + ({$intDelta})"),
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('ingredients')
                ->where('tenant_id', $tenantId)
                ->where('id', $itemId)
                ->update([
                    'current_stock' => DB::raw("current_stock + ({$delta})"),
                    'updated_at' => now(),
                ]);
        }
    }

    private function resolveItemName(int $tenantId, string $itemType, int $itemId): string
    {
        if ($itemType === 'product') {
            $name = DB::table('products')
                ->where('tenant_id', $tenantId)
                ->where('id', $itemId)
                ->value('product_name');

            return $name !== null ? (string) $name : "#{$itemId}";
        }

        $name = DB::table('ingredients')
            ->where('tenant_id', $tenantId)
            ->where('id', $itemId)
            ->value('name');

        return $name !== null ? (string) $name : "#{$itemId}";
    }
}
