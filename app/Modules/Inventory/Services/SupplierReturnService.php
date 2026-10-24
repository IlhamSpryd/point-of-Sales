<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Enums\IngredientStockMovementTypeEnum;
use App\Enums\StockMovementType;
use App\Enums\SupplierReturnStatusEnum;
use App\Models\GoodsReceipt;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Store;
use App\Modules\Inventory\Models\SupplierReturn;
use App\Modules\Inventory\Models\SupplierReturnItem;
use App\Modules\Settings\Services\AuditTrailService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Service Dokumen Retur Pembelian ke Pemasok (Supplier Return) & Stock Exit.
 *
 * Mengelola siklus retur barang ke supplier:
 * DRAFT -> POSTED -> REVERSED (atau DRAFT -> CANCELLED).
 * Menegakkan Invariant:
 * - INV-M4-01: Isolasi tenant & store ketat.
 * - INV-M4-02: Integritas XOR produk vs bahan baku.
 * - INV-M4-05: Strict non-negative stock (OD-05).
 * - INV-M4-06: Konservasi massa & pemotongan saldo akurat (SUPPLIER_RETURN_OUT).
 */
class SupplierReturnService
{
    public function __construct(
        protected InventoryLedgerService $ledgerService,
        protected AuditTrailService $auditTrailService
    ) {}

    /**
     * Membuat draft dokumen retur supplier baru.
     *
     * @param  array{
     *     return_number?: ?string,
     *     idempotency_key?: ?string,
     *     goods_receipt_id?: ?int,
     *     supplier_name?: ?string,
     *     supplier_reference?: ?string,
     *     returned_at?: ?string,
     *     reason?: ?string,
     *     notes?: ?string,
     *     items: array<int, array{
     *         item_type: 'product'|'ingredient',
     *         product_id?: ?int,
     *         ingredient_id?: ?int,
     *         quantity: float|string,
     *         unit_cost?: float|string|null,
     *         reason?: ?string,
     *         notes?: ?string
     *     }>
     * }  $data
     */
    public function createDraft(int $tenantId, int $storeId, array $data, int $userId): SupplierReturn
    {
        $store = Store::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $storeId)
            ->first();

        if (! $store) {
            throw new InvalidArgumentException("Cabang #{$storeId} tidak ditemukan untuk tenant ini.");
        }

        if (empty($data['items'])) {
            throw new InvalidArgumentException('Dokumen retur supplier wajib memiliki minimal 1 item.');
        }

        $idempotencyKey = $data['idempotency_key'] ?? (string) Str::uuid();

        // Idempotency check
        $existing = SupplierReturn::withoutTenantScope()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $existing->load('items');
        }

        return DB::transaction(function () use ($tenantId, $storeId, $data, $userId, $idempotencyKey) {
            $returnNumber = $data['return_number'] ?? $this->generateReturnNumber($tenantId, $storeId);

            $goodsReceiptId = isset($data['goods_receipt_id']) ? (int) $data['goods_receipt_id'] : null;
            if ($goodsReceiptId !== null) {
                $gr = GoodsReceipt::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $goodsReceiptId)
                    ->first();
                if (! $gr) {
                    throw new InvalidArgumentException("Dokumen Goods Receipt #{$goodsReceiptId} tidak ditemukan untuk tenant ini.");
                }
            }

            $return = SupplierReturn::create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'return_number' => $returnNumber,
                'idempotency_key' => $idempotencyKey,
                'goods_receipt_id' => $goodsReceiptId,
                'supplier_name' => $data['supplier_name'] ?? null,
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'status' => SupplierReturnStatusEnum::Draft,
                'returned_at' => $data['returned_at'] ?? now(),
                'created_by' => $userId,
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $itemData) {
                $this->validateAndCreateItem($return, $itemData);
            }

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'inventory.supplier_return.created',
                userId: $userId,
                storeId: $storeId,
                subjectType: SupplierReturn::class,
                subjectId: (int) $return->id,
                description: "Draft retur pembelian #{$return->return_number} dibuat untuk cabang #{$storeId}",
                metadata: [
                    'return_number' => $return->return_number,
                    'supplier_name' => $return->supplier_name,
                    'items_count' => count($data['items']),
                ]
            );

            return $return->load('items');
        });
    }

    /**
     * Memperbarui draft dokumen retur supplier.
     *
     * @param  array{
     *     supplier_name?: ?string,
     *     supplier_reference?: ?string,
     *     reason?: ?string,
     *     notes?: ?string,
     *     items?: array<int, array<string, mixed>>
     * }  $data
     */
    public function updateDraft(SupplierReturn $return, array $data, int $userId): SupplierReturn
    {
        return DB::transaction(function () use ($return, $data, $userId) {
            /** @var SupplierReturn $locked */
            $locked = SupplierReturn::withoutTenantScope()
                ->withoutGlobalScopes()
                ->where('tenant_id', $return->tenant_id)
                ->where('id', $return->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isDraft()) {
                throw new DomainException("Hanya retur supplier berstatus DRAFT yang dapat diperbarui (status: {$locked->status->value}).");
            }

            $locked->fill(array_filter([
                'supplier_name' => $data['supplier_name'] ?? $locked->supplier_name,
                'supplier_reference' => $data['supplier_reference'] ?? $locked->supplier_reference,
                'reason' => $data['reason'] ?? $locked->reason,
                'notes' => $data['notes'] ?? $locked->notes,
            ], fn ($v) => $v !== null));
            $locked->save();

            if (! empty($data['items'])) {
                $locked->items()->delete();
                foreach ($data['items'] as $itemData) {
                    $this->validateAndCreateItem($locked, $itemData);
                }
            }

            $this->auditTrailService->log(
                tenantId: (int) $locked->tenant_id,
                action: 'inventory.supplier_return.updated',
                userId: $userId,
                storeId: (int) $locked->store_id,
                subjectType: SupplierReturn::class,
                subjectId: (int) $locked->id,
                description: "Draft retur pembelian #{$locked->return_number} diperbarui.",
                metadata: [
                    'return_number' => $locked->return_number,
                ]
            );

            return $locked->fresh(['items']);
        });
    }

    /**
     * Membatalkan draft dokumen retur supplier.
     */
    public function cancelDraft(SupplierReturn $return, int $userId, ?string $reason = null): SupplierReturn
    {
        return DB::transaction(function () use ($return, $userId, $reason) {
            /** @var SupplierReturn $locked */
            $locked = SupplierReturn::withoutTenantScope()
                ->withoutGlobalScopes()
                ->where('tenant_id', $return->tenant_id)
                ->where('id', $return->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isDraft()) {
                throw new DomainException("Hanya retur supplier berstatus DRAFT yang dapat dibatalkan (status: {$locked->status->value}).");
            }

            $locked->update([
                'status' => SupplierReturnStatusEnum::Cancelled,
                'notes' => $reason ? trim(($locked->notes ?? '')."\n[Dibatalkan]: {$reason}") : $locked->notes,
            ]);

            $this->auditTrailService->log(
                tenantId: (int) $locked->tenant_id,
                action: 'inventory.supplier_return.cancelled',
                userId: $userId,
                storeId: (int) $locked->store_id,
                subjectType: SupplierReturn::class,
                subjectId: (int) $locked->id,
                description: "Retur pembelian #{$locked->return_number} dibatalkan.",
                metadata: [
                    'return_number' => $locked->return_number,
                    'reason' => $reason,
                ]
            );

            return $locked;
        });
    }

    /**
     * Memposting retur supplier (Stock Exit ke Pemasok).
     * Memotong saldo persediaan cabang dengan SUPPLIER_RETURN_OUT dan menegakkan Invariant non-negative stock (OD-05).
     */
    public function postReturn(SupplierReturn $return, int $userId): SupplierReturn
    {
        return DB::transaction(function () use ($return, $userId) {
            /** @var SupplierReturn $locked */
            $locked = SupplierReturn::withoutTenantScope()
                ->withoutGlobalScopes()
                ->where('tenant_id', $return->tenant_id)
                ->where('id', $return->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isPosted()) {
                return $locked->load(['items.product', 'items.ingredient']);
            }

            if (! $locked->isDraft()) {
                throw new DomainException("Hanya retur supplier berstatus DRAFT yang dapat diposting (status: {$locked->status->value}).");
            }

            $items = $locked->items()->get();
            if ($items->isEmpty()) {
                throw new DomainException('Dokumen retur supplier tidak memiliki item untuk diposting.');
            }

            $movements = [];

            foreach ($items as $item) {
                $rawItemId = (int) ($item->item_type === 'product' ? $item->product_id : $item->ingredient_id);
                $qty = (string) $item->quantity;

                // Ambil snapshot unit cost jika belum terisi
                $unitCost = (string) $item->unit_cost;
                if (bccomp($unitCost, '0', 4) <= 0) {
                    $balance = $this->ledgerService->getBalance(
                        tenantId: (int) $locked->tenant_id,
                        storeId: (int) $locked->store_id,
                        itemType: $item->item_type,
                        itemId: $rawItemId
                    );
                    $unitCost = $balance ? (string) ($balance->average_unit_cost ?? '0.0000') : '0.0000';
                    $item->unit_cost = $unitCost;
                    $item->save();
                }

                // Mutasi keluar cabang (SUPPLIER_RETURN_OUT, negatif)
                $movements[] = [
                    'store_id' => (int) $locked->store_id,
                    'item_type' => $item->item_type,
                    'item_id' => $rawItemId,
                    'quantity_delta' => bcmul($qty, '-1', 4),
                    'unit_cost' => $unitCost,
                    'type' => $item->item_type === 'product'
                        ? StockMovementType::SupplierReturnOut
                        : IngredientStockMovementTypeEnum::SupplierReturnOut,
                    'reason' => "Retur Pembelian ke Supplier #{$locked->return_number}".($locked->supplier_name ? " ({$locked->supplier_name})" : ''),
                    'idempotency_key' => "sr:{$locked->id}:item:{$item->id}:post",
                    'user_id' => $userId,
                ];
            }

            // Eksekusi pemotongan saldo secara atomik dengan validasi strict non-negative stock (OD-05)
            $this->ledgerService->recordBatchMovements(
                (int) $locked->tenant_id,
                $movements
            );

            $now = now();
            $locked->update([
                'status' => SupplierReturnStatusEnum::Posted,
                'posted_at' => $now,
                'posted_by' => $userId,
            ]);

            $this->auditTrailService->log(
                tenantId: (int) $locked->tenant_id,
                action: 'inventory.supplier_return.posted',
                userId: $userId,
                storeId: (int) $locked->store_id,
                subjectType: SupplierReturn::class,
                subjectId: (int) $locked->id,
                description: "Retur pembelian #{$locked->return_number} berhasil diposting (Status: POSTED).",
                metadata: [
                    'return_number' => $locked->return_number,
                    'supplier_name' => $locked->supplier_name,
                    'total_value' => $locked->calculateTotalValue(),
                    'posted_at' => $now->toIso8601String(),
                ]
            );

            return $locked->fresh(['items.product', 'items.ingredient']);
        });
    }

    /**
     * Membalikkan (reverse) retur supplier yang sudah diposting.
     * Mengembalikan kuantitas barang yang sebelumnya dikeluarkan kembali ke saldo cabang.
     */
    public function reverseReturn(SupplierReturn $return, int $userId, string $reason): SupplierReturn
    {
        return DB::transaction(function () use ($return, $userId, $reason) {
            /** @var SupplierReturn $locked */
            $locked = SupplierReturn::withoutTenantScope()
                ->withoutGlobalScopes()
                ->where('tenant_id', $return->tenant_id)
                ->where('id', $return->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isReversed()) {
                return $locked->load(['items.product', 'items.ingredient']);
            }

            if (! $locked->isPosted()) {
                throw new DomainException("Hanya retur supplier berstatus POSTED yang dapat di-reverse (status: {$locked->status->value}).");
            }

            $items = $locked->items()->get();
            $reversalMovements = [];

            foreach ($items as $item) {
                $rawItemId = (int) ($item->item_type === 'product' ? $item->product_id : $item->ingredient_id);
                $qty = (string) $item->quantity;

                // Mutasi masuk pembalikan (positif)
                $reversalMovements[] = [
                    'store_id' => (int) $locked->store_id,
                    'item_type' => $item->item_type,
                    'item_id' => $rawItemId,
                    'quantity_delta' => $qty,
                    'unit_cost' => (string) $item->unit_cost,
                    'type' => $item->item_type === 'product'
                        ? StockMovementType::GoodsReceipt
                        : IngredientStockMovementTypeEnum::GoodsReceipt,
                    'reason' => "Pembalikan Retur Supplier #{$locked->return_number}: {$reason}",
                    'idempotency_key' => "sr:{$locked->id}:item:{$item->id}:reverse",
                    'user_id' => $userId,
                ];
            }

            if (! empty($reversalMovements)) {
                $this->ledgerService->recordBatchMovements(
                    (int) $locked->tenant_id,
                    $reversalMovements
                );
            }

            $now = now();
            $locked->update([
                'status' => SupplierReturnStatusEnum::Reversed,
                'reversed_at' => $now,
                'reversed_by' => $userId,
                'reversal_reason' => $reason,
            ]);

            $this->auditTrailService->log(
                tenantId: (int) $locked->tenant_id,
                action: 'inventory.supplier_return.reversed',
                userId: $userId,
                storeId: (int) $locked->store_id,
                subjectType: SupplierReturn::class,
                subjectId: (int) $locked->id,
                description: "Retur pembelian #{$locked->return_number} berhasil dibalikkan (Status: REVERSED).",
                metadata: [
                    'return_number' => $locked->return_number,
                    'reason' => $reason,
                    'reversed_at' => $now->toIso8601String(),
                ]
            );

            return $locked->fresh(['items.product', 'items.ingredient']);
        });
    }

    /**
     * Validasi dan simpan item baris retur supplier.
     *
     * @param  array<string, mixed>  $itemData
     */
    protected function validateAndCreateItem(SupplierReturn $return, array $itemData): SupplierReturnItem
    {
        $itemType = $itemData['item_type'] ?? null;
        if (! in_array($itemType, ['product', 'ingredient'], true)) {
            throw new InvalidArgumentException("Tipe item tidak valid: '{$itemType}'. Harus 'product' atau 'ingredient'.");
        }

        $productId = isset($itemData['product_id']) ? (int) $itemData['product_id'] : null;
        $ingredientId = isset($itemData['ingredient_id']) ? (int) $itemData['ingredient_id'] : null;

        // XOR Invariant check
        if (($itemType === 'product' && (! $productId || $ingredientId)) ||
            ($itemType === 'ingredient' && (! $ingredientId || $productId))) {
            throw new InvalidArgumentException('Baris retur supplier harus memiliki tepat satu dari product_id atau ingredient_id yang sesuai dengan item_type (INV-M4-02).');
        }

        // Pastikan entitas ada dan milik tenant yang sama
        if ($itemType === 'product') {
            $product = Product::withoutGlobalScopes()
                ->where('tenant_id', $return->tenant_id)
                ->where('id', $productId)
                ->first();
            if (! $product) {
                throw new InvalidArgumentException("Produk #{$productId} tidak ditemukan untuk tenant ini.");
            }
        } else {
            $ingredient = Ingredient::withoutGlobalScopes()
                ->where('tenant_id', $return->tenant_id)
                ->where('id', $ingredientId)
                ->first();
            if (! $ingredient) {
                throw new InvalidArgumentException("Bahan baku #{$ingredientId} tidak ditemukan untuk tenant ini.");
            }
        }

        $qty = (string) ($itemData['quantity'] ?? '0');
        if (bccomp($qty, '0', 4) <= 0) {
            throw new InvalidArgumentException("Kuantitas retur untuk {$itemType} harus lebih besar dari 0.");
        }

        $unitCost = isset($itemData['unit_cost']) ? (string) $itemData['unit_cost'] : '0.0000';
        if (bccomp($unitCost, '0', 4) < 0) {
            throw new InvalidArgumentException("Biaya per unit retur tidak boleh bernilai negatif: {$unitCost}.");
        }

        return SupplierReturnItem::create([
            'tenant_id' => $return->tenant_id,
            'store_id' => $return->store_id,
            'supplier_return_id' => $return->id,
            'item_type' => $itemType,
            'product_id' => $productId,
            'ingredient_id' => $ingredientId,
            'quantity' => $qty,
            'unit_cost' => $unitCost,
            'reason' => $itemData['reason'] ?? null,
            'notes' => $itemData['notes'] ?? null,
        ]);
    }

    /**
     * Menghasilkan nomor dokumen retur supplier otomatis yang unik.
     */
    protected function generateReturnNumber(int $tenantId, int $storeId): string
    {
        $datePrefix = date('Ymd');
        $randomSuffix = strtoupper(Str::random(4));

        return "SR-{$storeId}-{$datePrefix}-{$randomSuffix}";
    }
}
