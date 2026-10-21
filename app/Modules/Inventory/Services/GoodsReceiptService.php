<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Enums\GoodsReceiptStatusEnum;
use App\Enums\IngredientStockMovementTypeEnum;
use App\Enums\StockMovementType;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Ingredient;
use App\Models\Product;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Service Dokumen Penerimaan Barang Cabang (Goods Receipt / GRN) & Inbound Cost Capture.
 *
 * Mengelola siklus hidup penerimaan barang:
 * DRAFT -> POSTED -> REVERSED (atau DRAFT -> CANCELLED).
 * Menjamin idempotency, isolasi tenant & store, serta kepatuhan penuh terhadap
 * OD-02 (Lot Annotation), OD-05 (Strict Non-Negative Stock), dan OD-06 (Inbound WAC).
 */
class GoodsReceiptService
{
    public function __construct(
        protected InventoryLedgerService $ledgerService
    ) {}

    /**
     * Membuat draft dokumen penerimaan barang baru.
     *
     * @param  array{
     *     receipt_number?: ?string,
     *     idempotency_key?: ?string,
     *     supplier_reference?: ?string,
     *     purchase_order_reference?: ?string,
     *     received_at?: ?string,
     *     notes?: ?string,
     *     items: array<int, array{
     *         item_type: 'product'|'ingredient',
     *         product_id?: ?int,
     *         ingredient_id?: ?int,
     *         quantity_received: float|string,
     *         unit_cost?: float|string|null,
     *         batch_number?: ?string,
     *         expiry_date?: ?string,
     *         notes?: ?string
     *     }>
     * }  $data
     */
    public function createDraft(int $tenantId, int $storeId, array $data, int $userId): GoodsReceipt
    {
        if (empty($data['items'])) {
            throw new InvalidArgumentException('Dokumen penerimaan barang wajib memiliki minimal 1 item.');
        }

        $idempotencyKey = $data['idempotency_key'] ?? (string) Str::uuid();

        // Cek jika idempotency key sudah ada pada tenant ini
        $existing = GoodsReceipt::withoutTenantScope()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($tenantId, $storeId, $data, $userId, $idempotencyKey) {
            $receiptNumber = $data['receipt_number'] ?? $this->generateReceiptNumber($tenantId, $storeId);

            $receipt = GoodsReceipt::create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'receipt_number' => $receiptNumber,
                'idempotency_key' => $idempotencyKey,
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'purchase_order_reference' => $data['purchase_order_reference'] ?? null,
                'status' => GoodsReceiptStatusEnum::Draft,
                'received_at' => $data['received_at'] ?? now(),
                'created_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $itemData) {
                $this->validateAndCreateItem($receipt, $itemData);
            }

            return $receipt->load('items');
        });
    }

    /**
     * Memperbarui draft dokumen penerimaan barang.
     *
     * @param  array{
     *     supplier_reference?: ?string,
     *     purchase_order_reference?: ?string,
     *     received_at?: ?string,
     *     notes?: ?string,
     *     items?: array<int, array{
     *         item_type: 'product'|'ingredient',
     *         product_id?: ?int,
     *         ingredient_id?: ?int,
     *         quantity_received: float|string,
     *         unit_cost?: float|string|null,
     *         batch_number?: ?string,
     *         expiry_date?: ?string,
     *         notes?: ?string
     *     }>
     * }  $data
     */
    public function updateDraft(GoodsReceipt $receipt, array $data, int $userId): GoodsReceipt
    {
        if (! $receipt->isDraft()) {
            throw new DomainException("Hanya dokumen berstatus DRAFT yang dapat diperbarui. Status saat ini: {$receipt->status->value}");
        }

        return DB::transaction(function () use ($receipt, $data) {
            $receipt->update([
                'supplier_reference' => $data['supplier_reference'] ?? $receipt->supplier_reference,
                'purchase_order_reference' => $data['purchase_order_reference'] ?? $receipt->purchase_order_reference,
                'received_at' => $data['received_at'] ?? $receipt->received_at,
                'notes' => $data['notes'] ?? $receipt->notes,
            ]);

            if (isset($data['items'])) {
                if (empty($data['items'])) {
                    throw new InvalidArgumentException('Dokumen penerimaan barang wajib memiliki minimal 1 item.');
                }

                // Ganti item lama dengan kumpulan item baru
                $receipt->items()->delete();

                foreach ($data['items'] as $itemData) {
                    $this->validateAndCreateItem($receipt, $itemData);
                }
            }

            return $receipt->fresh(['items']);
        });
    }

    /**
     * Membatalkan draft penerimaan barang (CANCELLED).
     */
    public function cancelDraft(GoodsReceipt $receipt, int $userId): GoodsReceipt
    {
        if (! $receipt->isDraft()) {
            throw new DomainException("Hanya dokumen berstatus DRAFT yang dapat dibatalkan. Status saat ini: {$receipt->status->value}");
        }

        $receipt->update([
            'status' => GoodsReceiptStatusEnum::Cancelled,
        ]);

        return $receipt->fresh(['items']);
    }

    /**
     * Memposting penerimaan barang ke Inventory Ledger secara atomik.
     *
     * Menjalankan canonical locks, memperbarui saldo fisik, memperbarui WAC (average_unit_cost),
     * dan mengubah status dokumen menjadi POSTED.
     */
    public function postReceipt(GoodsReceipt $receipt, int $userId): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $userId) {
            /** @var GoodsReceipt $lockedReceipt */
            $lockedReceipt = GoodsReceipt::withoutTenantScope()
                ->withoutGlobalScopes()
                ->where('id', $receipt->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotency: Jika sudah POSTED, return dokumen langsung
            if ($lockedReceipt->isPosted()) {
                return $lockedReceipt->load('items');
            }

            if (! $lockedReceipt->isDraft()) {
                throw new DomainException("Hanya dokumen berstatus DRAFT yang dapat diposting. Status saat ini: {$lockedReceipt->status->value}");
            }

            $items = $lockedReceipt->items()->get();
            if ($items->isEmpty()) {
                throw new DomainException('Dokumen penerimaan barang tidak memiliki item detail.');
            }

            $movements = [];
            foreach ($items as $item) {
                $itemId = $item->item_type === 'product' ? (int) $item->product_id : (int) $item->ingredient_id;
                $movementType = $item->item_type === 'product'
                    ? StockMovementType::GoodsReceipt
                    : IngredientStockMovementTypeEnum::GoodsReceipt;

                $batchAnnotation = '';
                if (! empty($item->batch_number)) {
                    $batchAnnotation = " [Batch: {$item->batch_number}";
                    if ($item->expiry_date) {
                        $batchAnnotation .= " Exp: {$item->expiry_date->format('Y-m-d')}";
                    }
                    $batchAnnotation .= ']';
                }

                $movements[] = [
                    'store_id' => (int) $lockedReceipt->store_id,
                    'item_type' => $item->item_type,
                    'item_id' => $itemId,
                    'quantity_delta' => (string) $item->quantity_received,
                    'type' => $movementType,
                    'unit_cost' => (string) $item->unit_cost,
                    'reason' => "Goods Receipt #{$lockedReceipt->receipt_number}{$batchAnnotation}",
                    'idempotency_key' => "grn_{$lockedReceipt->id}_{$item->id}_post",
                    'user_id' => $userId,
                ];
            }

            // Catat pergerakan stok batch ke InventoryLedgerService
            $this->ledgerService->recordBatchMovements((int) $lockedReceipt->tenant_id, $movements);

            // Perbarui header dokumen
            $lockedReceipt->update([
                'status' => GoodsReceiptStatusEnum::Posted,
                'posted_at' => now(),
                'posted_by' => $userId,
            ]);

            return $lockedReceipt->fresh(['items']);
        });
    }

    /**
     * Membalikkan (reverse) penerimaan barang yang sudah diposting.
     *
     * Mematuhi OD-05: Penolakan mutlak jika pembalikan menyebabkan saldo cabang negatif.
     */
    public function reverseReceipt(GoodsReceipt $receipt, int $userId, string $reason): GoodsReceipt
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Alasan pembalikan (reversal_reason) wajib diisi.');
        }

        return DB::transaction(function () use ($receipt, $userId, $reason) {
            /** @var GoodsReceipt $lockedReceipt */
            $lockedReceipt = GoodsReceipt::withoutTenantScope()
                ->withoutGlobalScopes()
                ->where('id', $receipt->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotency: Jika sudah REVERSED, return dokumen langsung
            if ($lockedReceipt->isReversed()) {
                return $lockedReceipt->load('items');
            }

            if (! $lockedReceipt->isPosted()) {
                throw new DomainException("Hanya dokumen berstatus POSTED yang dapat direverse. Status saat ini: {$lockedReceipt->status->value}");
            }

            $items = $lockedReceipt->items()->get();
            if ($items->isEmpty()) {
                throw new DomainException('Dokumen penerimaan barang tidak memiliki item detail.');
            }

            // Validasi Pra-Kondisi OD-05: Pastikan saldo saat ini cukup untuk semua item
            foreach ($items as $item) {
                $itemId = $item->item_type === 'product' ? (int) $item->product_id : (int) $item->ingredient_id;
                $balance = $this->ledgerService->getBalance(
                    (int) $lockedReceipt->tenant_id,
                    (int) $lockedReceipt->store_id,
                    $item->item_type,
                    $itemId
                );

                $currentQty = (string) ($balance->quantity ?? '0.0000');
                $requiredQty = (string) $item->quantity_received;

                if (bccomp($currentQty, $requiredQty, 4) < 0) {
                    throw new DomainException(
                        "Reversal dibatalkan (OD-05): Saldo on-hand untuk item '{$item->item_name}' di cabang ini "
                        ."tersedia {$currentQty}, tidak mencukupi untuk membalikkan kuantitas penerimaan {$requiredQty}."
                    );
                }
            }

            // Siapkan counter-movements (delta negatif)
            $counterMovements = [];
            foreach ($items as $item) {
                $itemId = $item->item_type === 'product' ? (int) $item->product_id : (int) $item->ingredient_id;
                $movementType = $item->item_type === 'product'
                    ? StockMovementType::GoodsReceiptReversal
                    : IngredientStockMovementTypeEnum::GoodsReceiptReversal;

                $counterMovements[] = [
                    'store_id' => (int) $lockedReceipt->store_id,
                    'item_type' => $item->item_type,
                    'item_id' => $itemId,
                    'quantity_delta' => bcmul((string) $item->quantity_received, '-1', 4),
                    'type' => $movementType,
                    'unit_cost' => (string) $item->unit_cost,
                    'reason' => "Reversal GRN #{$lockedReceipt->receipt_number}: {$reason}",
                    'idempotency_key' => "grn_{$lockedReceipt->id}_{$item->id}_reverse",
                    'user_id' => $userId,
                ];
            }

            // Catat reversal mutasi ke InventoryLedgerService
            $this->ledgerService->recordBatchMovements((int) $lockedReceipt->tenant_id, $counterMovements);

            // Perbarui header dokumen menjadi REVERSED
            $lockedReceipt->update([
                'status' => GoodsReceiptStatusEnum::Reversed,
                'reversed_at' => now(),
                'reversed_by' => $userId,
                'reversal_reason' => $reason,
            ]);

            return $lockedReceipt->fresh(['items']);
        });
    }

    /**
     * Memvalidasi dan membuat baris item penerimaan barang.
     *
     * @param  array<string, mixed>  $itemData
     */
    protected function validateAndCreateItem(GoodsReceipt $receipt, array $itemData): GoodsReceiptItem
    {
        $itemType = (string) ($itemData['item_type'] ?? '');
        if (! in_array($itemType, ['product', 'ingredient'], true)) {
            throw new InvalidArgumentException("item_type harus 'product' atau 'ingredient'. Diberikan: {$itemType}");
        }

        $productId = isset($itemData['product_id']) ? (int) $itemData['product_id'] : null;
        $ingredientId = isset($itemData['ingredient_id']) ? (int) $itemData['ingredient_id'] : null;

        // Validasi XOR di level aplikasi sebelum database trigger
        if ($itemType === 'product') {
            if ($productId === null || $ingredientId !== null) {
                throw new InvalidArgumentException('Untuk item_type product, product_id wajib diisi dan ingredient_id harus null.');
            }

            $productExists = Product::withoutTenantScope()
                ->where('tenant_id', $receipt->tenant_id)
                ->where('id', $productId)
                ->exists();

            if (! $productExists) {
                throw new InvalidArgumentException("Product ID {$productId} tidak ditemukan pada tenant {$receipt->tenant_id}.");
            }
        } else {
            if ($ingredientId === null || $productId !== null) {
                throw new InvalidArgumentException('Untuk item_type ingredient, ingredient_id wajib diisi dan product_id harus null.');
            }

            $ingredientExists = Ingredient::withoutTenantScope()
                ->where('tenant_id', $receipt->tenant_id)
                ->where('id', $ingredientId)
                ->exists();

            if (! $ingredientExists) {
                throw new InvalidArgumentException("Ingredient ID {$ingredientId} tidak ditemukan pada tenant {$receipt->tenant_id}.");
            }
        }

        $qty = (string) $itemData['quantity_received'];
        if (bccomp($qty, '0', 4) <= 0) {
            throw new InvalidArgumentException("quantity_received harus lebih besar dari 0. Diberikan: {$qty}");
        }

        $unitCost = (string) ($itemData['unit_cost'] ?? '0.0000');
        if (bccomp($unitCost, '0', 4) < 0) {
            throw new InvalidArgumentException("unit_cost tidak boleh negatif. Diberikan: {$unitCost}");
        }

        return GoodsReceiptItem::create([
            'tenant_id' => $receipt->tenant_id,
            'store_id' => $receipt->store_id,
            'goods_receipt_id' => $receipt->id,
            'item_type' => $itemType,
            'product_id' => $productId,
            'ingredient_id' => $ingredientId,
            'quantity_received' => $qty,
            'unit_cost' => $unitCost,
            'batch_number' => $itemData['batch_number'] ?? null,
            'expiry_date' => $itemData['expiry_date'] ?? null,
            'notes' => $itemData['notes'] ?? null,
        ]);
    }

    /**
     * Menghasilkan nomor tanda terima barang unik per tenant & cabang: GRN-YYYYMMDD-XXXX.
     */
    protected function generateReceiptNumber(int $tenantId, int $storeId): string
    {
        $prefix = 'GRN-'.date('Ymd').'-';

        $lastNumber = GoodsReceipt::withoutTenantScope()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('receipt_number', 'like', "{$prefix}%")
            ->orderByDesc('id')
            ->value('receipt_number');

        if ($lastNumber) {
            $seq = (int) substr($lastNumber, -4);
            $nextSeq = str_pad((string) ($seq + 1), 4, '0', STR_PAD_LEFT);
        } else {
            $nextSeq = '0001';
        }

        return $prefix.$nextSeq;
    }
}
