<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Enums\IngredientStockMovementTypeEnum;
use App\Enums\StockAdjustmentReasonEnum;
use App\Enums\StockAdjustmentStatusEnum;
use App\Enums\StockMovementType;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Modules\Settings\Services\AuditTrailService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Service Dokumen Penyesuaian Stok Terstruktur (Stock Adjustment).
 *
 * Mengelola penyesuaian stok langsung cabang (Wastage, Damage, Shrinkage, Correction)
 * dengan kode alasan resmi, penegakan Invariant OD-05 (Strict Non-Negative Stock),
 * snapshot biaya inbound/carrying (unit_cost_snapshot), dan audit trail menyeluruh.
 */
class StockAdjustmentService
{
    public function __construct(
        protected InventoryLedgerService $ledgerService,
        protected AuditTrailService $auditTrailService
    ) {}

    /**
     * Membuat draft dokumen penyesuaian stok baru.
     *
     * @param  array{
     *     adjustment_number?: ?string,
     *     idempotency_key?: ?string,
     *     reason_code: StockAdjustmentReasonEnum|string,
     *     adjusted_at?: ?string,
     *     notes?: ?string,
     *     items: array<int, array<string, mixed>>
     * }  $data
     */
    public function createDraft(int $tenantId, int $storeId, array $data, int $userId): StockAdjustment
    {
        if (empty($data['items'])) {
            throw new InvalidArgumentException('Dokumen penyesuaian stok wajib memiliki minimal 1 item.');
        }

        $idempotencyKey = $data['idempotency_key'] ?? (string) Str::uuid();

        // Cek Idempotency Key
        $existing = StockAdjustment::withoutTenantScope()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $existing->load('items');
        }

        $reasonCode = $data['reason_code'] instanceof StockAdjustmentReasonEnum
            ? $data['reason_code']
            : StockAdjustmentReasonEnum::from((string) $data['reason_code']);

        return DB::transaction(function () use ($tenantId, $storeId, $data, $userId, $idempotencyKey, $reasonCode) {
            $adjustmentNumber = $data['adjustment_number'] ?? $this->generateAdjustmentNumber($tenantId, $storeId);

            $adjustment = StockAdjustment::create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'adjustment_number' => $adjustmentNumber,
                'idempotency_key' => $idempotencyKey,
                'status' => StockAdjustmentStatusEnum::Draft,
                'reason_code' => $reasonCode,
                'adjusted_at' => $data['adjusted_at'] ?? now(),
                'created_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $itemData) {
                $this->validateAndCreateItem($adjustment, $itemData);
            }

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'inventory.stock_adjustment.created',
                userId: $userId,
                storeId: $storeId,
                subjectType: StockAdjustment::class,
                subjectId: (int) $adjustment->id,
                description: "Draft penyesuaian stok #{$adjustment->adjustment_number} dibuat dengan alasan {$reasonCode->value}",
                metadata: [
                    'adjustment_number' => $adjustment->adjustment_number,
                    'reason_code' => $reasonCode->value,
                    'items_count' => count($data['items']),
                ]
            );

            return $adjustment->load('items');
        });
    }

    /**
     * Memperbarui draft dokumen penyesuaian stok.
     *
     * @param  array{
     *     reason_code?: StockAdjustmentReasonEnum|string,
     *     adjusted_at?: ?string,
     *     notes?: ?string,
     *     items?: array<int, array<string, mixed>>
     * }  $data
     */
    public function updateDraft(StockAdjustment $adjustment, array $data, int $userId): StockAdjustment
    {
        if (! $adjustment->isDraft()) {
            throw new DomainException("Hanya dokumen berstatus DRAFT yang dapat diperbarui. Status saat ini: {$adjustment->status->value}");
        }

        return DB::transaction(function () use ($adjustment, $data, $userId) {
            $updates = [
                'adjusted_at' => $data['adjusted_at'] ?? $adjustment->adjusted_at,
                'notes' => $data['notes'] ?? $adjustment->notes,
            ];

            if (isset($data['reason_code'])) {
                $updates['reason_code'] = $data['reason_code'] instanceof StockAdjustmentReasonEnum
                    ? $data['reason_code']
                    : StockAdjustmentReasonEnum::from((string) $data['reason_code']);
            }

            $adjustment->update($updates);

            if (isset($data['items'])) {
                if (empty($data['items'])) {
                    throw new InvalidArgumentException('Dokumen penyesuaian stok wajib memiliki minimal 1 item.');
                }

                $adjustment->items()->delete();
                foreach ($data['items'] as $itemData) {
                    $this->validateAndCreateItem($adjustment, $itemData);
                }
            }

            $this->auditTrailService->log(
                tenantId: (int) $adjustment->tenant_id,
                action: 'inventory.stock_adjustment.updated',
                userId: $userId,
                storeId: (int) $adjustment->store_id,
                subjectType: StockAdjustment::class,
                subjectId: (int) $adjustment->id,
                description: "Draft penyesuaian stok #{$adjustment->adjustment_number} diperbarui",
            );

            return $adjustment->fresh(['items']);
        });
    }

    /**
     * Membatalkan draft penyesuaian stok (CANCELLED).
     */
    public function cancelDraft(StockAdjustment $adjustment, int $userId): StockAdjustment
    {
        if (! $adjustment->isDraft()) {
            throw new DomainException("Hanya dokumen berstatus DRAFT yang dapat dibatalkan. Status saat ini: {$adjustment->status->value}");
        }

        $adjustment->update([
            'status' => StockAdjustmentStatusEnum::Cancelled,
        ]);

        $this->auditTrailService->log(
            tenantId: (int) $adjustment->tenant_id,
            action: 'inventory.stock_adjustment.cancelled',
            userId: $userId,
            storeId: (int) $adjustment->store_id,
            subjectType: StockAdjustment::class,
            subjectId: (int) $adjustment->id,
            description: "Draft penyesuaian stok #{$adjustment->adjustment_number} dibatalkan",
        );

        return $adjustment->fresh(['items']);
    }

    /**
     * Memposting penyesuaian stok ke ledger secara atomik dengan validasi OD-05.
     */
    public function postAdjustment(StockAdjustment $adjustment, int $userId): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $userId) {
            /** @var StockAdjustment $locked */
            $locked = StockAdjustment::withoutTenantScope()
                ->withoutGlobalScopes()
                ->where('id', $adjustment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isPosted()) {
                return $locked->load('items');
            }

            if (! $locked->isDraft()) {
                throw new DomainException("Hanya dokumen berstatus DRAFT yang dapat diposting. Status saat ini: {$locked->status->value}");
            }

            $items = $locked->items()->get();
            if ($items->isEmpty()) {
                throw new DomainException('Dokumen penyesuaian stok tidak memiliki baris detail.');
            }

            $movements = [];
            foreach ($items as $item) {
                $itemId = $item->item_type === 'product' ? (int) $item->product_id : (int) $item->ingredient_id;
                $balance = $this->ledgerService->getBalance(
                    (int) $locked->tenant_id,
                    (int) $locked->store_id,
                    $item->item_type,
                    $itemId
                );

                // Pastikan unit_cost_snapshot terisi dari WAC cabang saat ini jika masih 0
                $unitCost = (string) $item->unit_cost_snapshot;
                if (bccomp($unitCost, '0', 4) === 0 && $balance && bccomp((string) $balance->average_unit_cost, '0', 4) > 0) {
                    $unitCost = (string) $balance->average_unit_cost;
                    $item->update(['unit_cost_snapshot' => $unitCost]);
                }

                if ($item->direction === 'OUT') {
                    // Validasi Keras Invariant OD-05: Larang pengurangan yang menyebabkan stok negatif
                    $currentQty = (string) ($balance->quantity ?? '0.0000');
                    $qtyToDeduct = (string) $item->quantity;

                    if (bccomp($currentQty, $qtyToDeduct, 4) < 0) {
                        throw new DomainException(
                            "Penyesuaian stok ditolak (OD-05): Saldo cabang untuk {$item->item_name} tersedia {$currentQty}, "
                            ."tidak mencukupi untuk pengurangan keluar sebesar {$qtyToDeduct}."
                        );
                    }

                    $delta = bcmul($qtyToDeduct, '-1', 4);
                    $mType = $item->item_type === 'product'
                        ? StockMovementType::AdjustmentOut
                        : ($locked->reason_code->isWaste() ? IngredientStockMovementTypeEnum::Waste : IngredientStockMovementTypeEnum::AdjustmentOut);
                } else {
                    $delta = (string) $item->quantity;
                    $mType = $item->item_type === 'product'
                        ? StockMovementType::AdjustmentIn
                        : IngredientStockMovementTypeEnum::AdjustmentIn;
                }

                $reasonText = "Stock Adjustment #{$locked->adjustment_number} [{$locked->reason_code->value}]";
                if ($item->reason_note) {
                    $reasonText .= ": {$item->reason_note}";
                }

                $movements[] = [
                    'store_id' => (int) $locked->store_id,
                    'item_type' => $item->item_type,
                    'item_id' => $itemId,
                    'quantity_delta' => $delta,
                    'type' => $mType,
                    'unit_cost' => $unitCost,
                    'reason' => $reasonText,
                    'idempotency_key' => "adj_{$locked->id}_{$item->id}_post",
                    'user_id' => $userId,
                ];
            }

            // Eksekusi mutasi batch atomik di InventoryLedgerService
            $this->ledgerService->recordBatchMovements((int) $locked->tenant_id, $movements);

            // Perbarui header dokumen
            $locked->update([
                'status' => StockAdjustmentStatusEnum::Posted,
                'posted_at' => now(),
                'posted_by' => $userId,
            ]);

            // Catat Jejak Audit
            $this->auditTrailService->log(
                tenantId: (int) $locked->tenant_id,
                action: 'inventory.stock_adjustment.posted',
                userId: $userId,
                storeId: (int) $locked->store_id,
                subjectType: StockAdjustment::class,
                subjectId: (int) $locked->id,
                description: "Penyesuaian stok #{$locked->adjustment_number} berhasil diposting ({$locked->reason_code->value})",
                metadata: [
                    'adjustment_number' => $locked->adjustment_number,
                    'reason_code' => $locked->reason_code->value,
                    'items_count' => $items->count(),
                    'total_value' => $locked->calculateTotalValue(),
                ]
            );

            return $locked->fresh(['items']);
        });
    }

    /**
     * Membuat dan memposting penyesuaian stok secara langsung (untuk quick adjustment UI).
     *
     * @param  array{
     *     adjustment_number?: ?string,
     *     idempotency_key?: ?string,
     *     reason_code: StockAdjustmentReasonEnum|string,
     *     adjusted_at?: ?string,
     *     notes?: ?string,
     *     items: array<int, array<string, mixed>>
     * }  $data
     */
    public function createAndPostDirect(int $tenantId, int $storeId, array $data, int $userId): StockAdjustment
    {
        $draft = $this->createDraft($tenantId, $storeId, $data, $userId);

        return $this->postAdjustment($draft, $userId);
    }

    /**
     * Memvalidasi dan membuat baris item penyesuaian stok.
     *
     * @param  array<string, mixed>  $itemData
     */
    protected function validateAndCreateItem(StockAdjustment $adjustment, array $itemData): StockAdjustmentItem
    {
        $itemType = (string) ($itemData['item_type'] ?? '');
        if (! in_array($itemType, ['product', 'ingredient'], true)) {
            throw new InvalidArgumentException("item_type harus 'product' atau 'ingredient'. Diberikan: {$itemType}");
        }

        $productId = isset($itemData['product_id']) ? (int) $itemData['product_id'] : null;
        $ingredientId = isset($itemData['ingredient_id']) ? (int) $itemData['ingredient_id'] : null;

        $direction = strtoupper((string) ($itemData['direction'] ?? $adjustment->reason_code->defaultDirection()));
        if (! in_array($direction, ['IN', 'OUT'], true)) {
            throw new InvalidArgumentException("direction harus 'IN' atau 'OUT'. Diberikan: {$direction}");
        }

        $qty = (string) ($itemData['quantity'] ?? '0');
        if (bccomp($qty, '0', 4) <= 0) {
            throw new InvalidArgumentException("quantity harus lebih besar dari 0. Diberikan: {$qty}");
        }

        // Resolusi unit cost snapshot
        $unitCostSnapshot = '0.0000';
        if (isset($itemData['unit_cost_snapshot']) && bccomp((string) $itemData['unit_cost_snapshot'], '0', 4) >= 0) {
            $unitCostSnapshot = (string) $itemData['unit_cost_snapshot'];
        }

        if ($itemType === 'product') {
            if ($productId === null || $ingredientId !== null) {
                throw new InvalidArgumentException('Untuk item_type product, product_id wajib diisi dan ingredient_id harus null.');
            }

            $product = Product::withoutTenantScope()
                ->where('tenant_id', $adjustment->tenant_id)
                ->where('id', $productId)
                ->first();

            if (! $product) {
                throw new InvalidArgumentException("Product ID {$productId} tidak ditemukan pada tenant {$adjustment->tenant_id}.");
            }

            if (bccomp($unitCostSnapshot, '0', 4) === 0) {
                $balance = $this->ledgerService->getBalance((int) $adjustment->tenant_id, (int) $adjustment->store_id, 'product', $productId);
                $unitCostSnapshot = $balance && bccomp((string) $balance->average_unit_cost, '0', 4) > 0
                    ? (string) $balance->average_unit_cost
                    : (string) ($product->standard_cost ?? '0.0000');
            }
        } else {
            if ($ingredientId === null || $productId !== null) {
                throw new InvalidArgumentException('Untuk item_type ingredient, ingredient_id wajib diisi dan product_id harus null.');
            }

            $ingredient = Ingredient::withoutTenantScope()
                ->where('tenant_id', $adjustment->tenant_id)
                ->where('id', $ingredientId)
                ->first();

            if (! $ingredient) {
                throw new InvalidArgumentException("Ingredient ID {$ingredientId} tidak ditemukan pada tenant {$adjustment->tenant_id}.");
            }

            if (bccomp($unitCostSnapshot, '0', 4) === 0) {
                $balance = $this->ledgerService->getBalance((int) $adjustment->tenant_id, (int) $adjustment->store_id, 'ingredient', $ingredientId);
                $unitCostSnapshot = $balance && bccomp((string) $balance->average_unit_cost, '0', 4) > 0
                    ? (string) $balance->average_unit_cost
                    : (string) ($ingredient->cost_per_unit ?? '0.0000');
            }
        }

        return StockAdjustmentItem::create([
            'tenant_id' => $adjustment->tenant_id,
            'store_id' => $adjustment->store_id,
            'stock_adjustment_id' => $adjustment->id,
            'item_type' => $itemType,
            'product_id' => $productId,
            'ingredient_id' => $ingredientId,
            'direction' => $direction,
            'quantity' => $qty,
            'unit_cost_snapshot' => $unitCostSnapshot,
            'reason_note' => $itemData['reason_note'] ?? null,
        ]);
    }

    /**
     * Menghasilkan nomor penyesuaian unik: ADJ-YYYYMMDD-XXXX.
     */
    protected function generateAdjustmentNumber(int $tenantId, int $storeId): string
    {
        $prefix = 'ADJ-'.date('Ymd').'-';

        $lastNumber = StockAdjustment::withoutTenantScope()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('adjustment_number', 'like', "{$prefix}%")
            ->orderByDesc('id')
            ->value('adjustment_number');

        if ($lastNumber) {
            $seq = (int) substr($lastNumber, -4);
            $nextSeq = str_pad((string) ($seq + 1), 4, '0', STR_PAD_LEFT);
        } else {
            $nextSeq = '0001';
        }

        return $prefix.$nextSeq;
    }
}
