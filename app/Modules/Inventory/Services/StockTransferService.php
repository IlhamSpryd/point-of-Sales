<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Enums\IngredientStockMovementTypeEnum;
use App\Enums\StockMovementType;
use App\Enums\StockTransferDiscrepancyResolutionEnum;
use App\Enums\StockTransferStatusEnum;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Store;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use App\Modules\Settings\Services\AuditTrailService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Service Dokumen Transfer Stok Antarcabang (Stock Transfer).
 *
 * Mengelola siklus pergerakan stok antarcabang secara 2-tahap (Two-Phase Transfer):
 * 1. Draft: Permintaan transfer barang antarcabang (from_store_id -> to_store_id).
 * 2. Ship / Dispatch: Mengurangi stok cabang pengirim (TRANSFER_OUT), snapshot carrying unit cost (OD-06),
 *    dan menandai status IN_TRANSIT.
 * 3. Receive & Discrepancy Resolution: Memvalidasi penerimaan fisik di cabang tujuan dengan blokir mutlak
 *    over-receipt (quantity_received <= quantity_shipped). Menangani selisih kekurangan via RETURN_TO_SOURCE
 *    atau WRITE_OFF_IN_TRANSIT (OD-03).
 * 4. Cancellation: Membatalkan draft, atau membatalkan in-transit dengan mengembalikan stok fisik ke cabang asal.
 *
 * Menegakkan Invariant:
 * - INV-M4-01: Isolasi tenant ketat (dilarang keras transfer lintas tenant).
 * - INV-M4-02: Integritas XOR produk vs bahan baku.
 * - INV-M4-05: Strict non-negative stock (OD-05).
 * - INV-M4-06: Konservasi massa & anti-over-receipt.
 * - INV-M4-07: Canonical lock ordering anti-deadlock lintas cabang (store_id, track_type, item_id).
 */
class StockTransferService
{
    public function __construct(
        protected InventoryLedgerService $ledgerService,
        protected AuditTrailService $auditTrailService
    ) {}

    /**
     * Membuat draft dokumen transfer stok antarcabang baru.
     *
     * @param  array{
     *     transfer_number?: ?string,
     *     ship_idempotency_key?: ?string,
     *     notes?: ?string,
     *     items: array<int, array{
     *         item_type: 'product'|'ingredient',
     *         product_id?: ?int,
     *         ingredient_id?: ?int,
     *         quantity_requested: float|string,
     *         quantity_shipped?: float|string|null,
     *         notes?: ?string
     *     }>
     * }  $data
     */
    public function createDraft(int $tenantId, int $fromStoreId, int $toStoreId, array $data, int $userId): StockTransfer
    {
        if ($fromStoreId === $toStoreId) {
            throw new InvalidArgumentException('Cabang asal (from_store_id) tidak boleh sama dengan cabang tujuan (to_store_id).');
        }

        $fromStore = Store::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $fromStoreId)
            ->first();

        $toStore = Store::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $toStoreId)
            ->first();

        if (! $fromStore || ! $toStore) {
            throw new InvalidArgumentException("Cabang asal #{$fromStoreId} atau cabang tujuan #{$toStoreId} tidak ditemukan untuk tenant ini.");
        }

        if (empty($data['items'])) {
            throw new InvalidArgumentException('Dokumen transfer stok wajib memiliki minimal 1 item.');
        }

        $shipIdempotencyKey = $data['ship_idempotency_key'] ?? (string) Str::uuid();

        // Idempotency check
        $existing = StockTransfer::withoutTenantScope()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('ship_idempotency_key', $shipIdempotencyKey)
            ->first();

        if ($existing) {
            return $existing->load('items');
        }

        return DB::transaction(function () use ($tenantId, $fromStoreId, $toStoreId, $data, $userId, $shipIdempotencyKey) {
            $transferNumber = $data['transfer_number'] ?? $this->generateTransferNumber($tenantId, $fromStoreId, $toStoreId);

            $transfer = StockTransfer::create([
                'tenant_id' => $tenantId,
                'from_store_id' => $fromStoreId,
                'to_store_id' => $toStoreId,
                'transfer_number' => $transferNumber,
                'ship_idempotency_key' => $shipIdempotencyKey,
                'status' => StockTransferStatusEnum::Draft,
                'created_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $itemData) {
                $this->validateAndCreateItem($transfer, $itemData);
            }

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'inventory.stock_transfer.created',
                userId: $userId,
                storeId: $fromStoreId,
                subjectType: StockTransfer::class,
                subjectId: (int) $transfer->id,
                description: "Draft transfer stok #{$transfer->transfer_number} dibuat dari cabang #{$fromStoreId} ke cabang #{$toStoreId}",
                metadata: [
                    'transfer_number' => $transfer->transfer_number,
                    'from_store_id' => $fromStoreId,
                    'to_store_id' => $toStoreId,
                    'items_count' => count($data['items']),
                ]
            );

            return $transfer->load('items');
        });
    }

    /**
     * Memperbarui draft dokumen transfer stok.
     *
     * @param  array{
     *     notes?: ?string,
     *     items?: array<int, array<string, mixed>>
     * }  $data
     */
    public function updateDraft(StockTransfer $transfer, array $data, int $userId): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $data, $userId) {
            /** @var StockTransfer $lockedTransfer */
            $lockedTransfer = StockTransfer::withoutTenantScope()
                ->withoutGlobalScopes()
                ->where('tenant_id', $transfer->tenant_id)
                ->where('id', $transfer->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedTransfer->isDraft()) {
                throw new DomainException("Hanya transfer berstatus DRAFT yang dapat diperbarui (status saat ini: {$lockedTransfer->status->value}).");
            }

            if (isset($data['notes'])) {
                $lockedTransfer->notes = $data['notes'];
                $lockedTransfer->save();
            }

            if (! empty($data['items'])) {
                $lockedTransfer->items()->delete();
                foreach ($data['items'] as $itemData) {
                    $this->validateAndCreateItem($lockedTransfer, $itemData);
                }
            }

            $this->auditTrailService->log(
                tenantId: (int) $lockedTransfer->tenant_id,
                action: 'inventory.stock_transfer.updated',
                userId: $userId,
                storeId: (int) $lockedTransfer->from_store_id,
                subjectType: StockTransfer::class,
                subjectId: (int) $lockedTransfer->id,
                description: "Draft transfer stok #{$lockedTransfer->transfer_number} diperbarui.",
                metadata: [
                    'transfer_number' => $lockedTransfer->transfer_number,
                ]
            );

            return $lockedTransfer->fresh(['items']);
        });
    }

    /**
     * Mengirimkan barang dari cabang asal (Phase 1 — Ship / Dispatch).
     *
     * @param  array<int, float|string>|null  $shippedQuantities  Map item_id => quantity_shipped
     */
    public function shipTransfer(StockTransfer $transfer, int $userId, ?array $shippedQuantities = null): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $userId, $shippedQuantities) {
            /** @var StockTransfer $lockedTransfer */
            $lockedTransfer = StockTransfer::withoutTenantScope()
                ->withoutGlobalScopes()
                ->where('tenant_id', $transfer->tenant_id)
                ->where('id', $transfer->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedTransfer->isDraft()) {
                throw new DomainException("Transfer #{$lockedTransfer->transfer_number} tidak dalam status DRAFT (status: {$lockedTransfer->status->value}).");
            }

            $items = $lockedTransfer->items()->get();
            if ($items->isEmpty()) {
                throw new DomainException('Dokumen transfer tidak memiliki item untuk dikirim.');
            }

            // Perbarui kuantitas kirim jika ada input kuantitas spesifik
            if ($shippedQuantities !== null) {
                foreach ($items as $item) {
                    if (isset($shippedQuantities[$item->id])) {
                        $qty = (string) $shippedQuantities[$item->id];
                        if (bccomp($qty, '0', 4) < 0) {
                            throw new InvalidArgumentException("Kuantitas kirim untuk item #{$item->id} tidak boleh negatif.");
                        }
                        $item->quantity_shipped = $qty;
                        $item->save();
                    }
                }
            } else {
                // Default: jika quantity_shipped masih 0, gunakan quantity_requested
                foreach ($items as $item) {
                    if (bccomp((string) $item->quantity_shipped, '0', 4) === 0) {
                        $item->quantity_shipped = (string) $item->quantity_requested;
                        $item->save();
                    }
                }
            }

            // Ambil snapshot average_unit_cost dari cabang pengirim sebagai carrying_unit_cost (OD-06)
            $movementsToRecord = [];

            foreach ($items as $item) {
                $rawItemId = (int) ($item->item_type === 'product' ? $item->product_id : $item->ingredient_id);
                $qtyShipped = (string) $item->quantity_shipped;

                if (bccomp($qtyShipped, '0', 4) <= 0) {
                    continue;
                }

                $balance = $this->ledgerService->getBalance(
                    tenantId: (int) $lockedTransfer->tenant_id,
                    storeId: (int) $lockedTransfer->from_store_id,
                    itemType: $item->item_type,
                    itemId: $rawItemId
                );

                $carryingCost = $balance ? (string) ($balance->average_unit_cost ?? '0.0000') : '0.0000';
                $item->carrying_unit_cost = $carryingCost;
                $item->save();

                // Mutasi keluar cabang asal (TRANSFER_OUT, negatif)
                $movementsToRecord[] = [
                    'store_id' => (int) $lockedTransfer->from_store_id,
                    'item_type' => $item->item_type,
                    'item_id' => $rawItemId,
                    'quantity_delta' => bcmul($qtyShipped, '-1', 4),
                    'unit_cost' => $carryingCost,
                    'type' => $item->item_type === 'product'
                        ? StockMovementType::TransferOut
                        : IngredientStockMovementTypeEnum::TransferOut,
                    'reason' => "Transfer Out ke Cabang #{$lockedTransfer->to_store_id} ({$lockedTransfer->transfer_number})",
                    'idempotency_key' => "st:{$lockedTransfer->id}:item:{$item->id}:ship",
                    'user_id' => $userId,
                ];
            }

            if (empty($movementsToRecord)) {
                throw new DomainException('Tidak ada kuantitas barang yang dikirim pada transfer ini.');
            }

            // Eksekusi mutasi keluar dengan penegakan strict non-negative stock (OD-05)
            $this->ledgerService->recordBatchMovements(
                (int) $lockedTransfer->tenant_id,
                $movementsToRecord
            );

            $now = now();
            $lockedTransfer->update([
                'status' => StockTransferStatusEnum::InTransit,
                'shipped_at' => $now,
                'shipped_by' => $userId,
            ]);

            $this->auditTrailService->log(
                tenantId: (int) $lockedTransfer->tenant_id,
                action: 'inventory.stock_transfer.shipped',
                userId: $userId,
                storeId: (int) $lockedTransfer->from_store_id,
                subjectType: StockTransfer::class,
                subjectId: (int) $lockedTransfer->id,
                description: "Transfer stok #{$lockedTransfer->transfer_number} dikirim dari cabang #{$lockedTransfer->from_store_id} (Status: IN_TRANSIT).",
                metadata: [
                    'transfer_number' => $lockedTransfer->transfer_number,
                    'from_store_id' => $lockedTransfer->from_store_id,
                    'to_store_id' => $lockedTransfer->to_store_id,
                    'items_count' => count($movementsToRecord),
                    'shipped_at' => $now->toIso8601String(),
                ]
            );

            return $lockedTransfer->fresh(['items']);
        });
    }

    /**
     * Menerima barang di cabang tujuan (Phase 2 — Receive & Discrepancy Resolution).
     *
     * @param  array{
     *     receive_idempotency_key?: ?string,
     *     notes?: ?string,
     *     items: array<int, array{
     *         item_id?: int,
     *         quantity_received: float|string,
     *         discrepancy_resolution?: StockTransferDiscrepancyResolutionEnum|string|null,
     *         discrepancy_reason_code?: ?string,
     *         notes?: ?string
     *     }>|array<int, float|string>
     * }  $receiptData
     */
    public function receiveTransfer(StockTransfer $transfer, array $receiptData, int $userId): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $receiptData, $userId) {
            /** @var StockTransfer $lockedTransfer */
            $lockedTransfer = StockTransfer::withoutTenantScope()
                ->withoutGlobalScopes()
                ->where('tenant_id', $transfer->tenant_id)
                ->where('id', $transfer->id)
                ->lockForUpdate()
                ->firstOrFail();

            $receiveIdempotencyKey = $receiptData['receive_idempotency_key'] ?? null;

            if ($lockedTransfer->isReceived()) {
                if ($receiveIdempotencyKey && $lockedTransfer->receive_idempotency_key === $receiveIdempotencyKey) {
                    return $lockedTransfer->load(['items.product', 'items.ingredient']);
                }
                throw new DomainException("Transfer #{$lockedTransfer->transfer_number} sudah berstatus RECEIVED.");
            }

            if (! $lockedTransfer->isInTransit()) {
                throw new DomainException("Hanya transfer berstatus IN_TRANSIT yang dapat diterima (status saat ini: {$lockedTransfer->status->value}).");
            }

            $items = $lockedTransfer->items()->get();

            // Normalisasi receipt inputs
            $normalizedItems = [];
            foreach ($receiptData['items'] as $key => $val) {
                if (is_array($val) && isset($val['item_id'])) {
                    $normalizedItems[(int) $val['item_id']] = $val;
                } elseif (is_array($val)) {
                    $normalizedItems[(int) $key] = $val;
                } else {
                    $normalizedItems[(int) $key] = [
                        'quantity_received' => (string) $val,
                        'discrepancy_resolution' => null,
                        'discrepancy_reason_code' => null,
                    ];
                }
            }

            $allMovements = [];

            foreach ($items as $item) {
                if (! isset($normalizedItems[$item->id])) {
                    throw ValidationException::withMessages([
                        'items' => "Item #{$item->id} tidak ditemukan dalam payload penerimaan.",
                    ]);
                }

                $input = $normalizedItems[$item->id];
                $qtyReceived = (string) $input['quantity_received'];
                $qtyShipped = (string) $item->quantity_shipped;

                if (bccomp($qtyReceived, '0', 4) < 0) {
                    throw ValidationException::withMessages([
                        'items' => "Kuantitas yang diterima untuk item #{$item->id} tidak boleh negatif.",
                    ]);
                }

                // HARD ANTI-OVER-RECEIPT CONSTRAINT (INV-M4-06 & OD-03)
                if (bccomp($qtyReceived, $qtyShipped, 4) > 0) {
                    throw ValidationException::withMessages([
                        'items' => "Kuantitas yang diterima ({$qtyReceived}) melebihi kuantitas yang dikirim ({$qtyShipped}). Over-receipt dilarang keras.",
                    ]);
                }

                $discrepancy = bcsub($qtyShipped, $qtyReceived, 4);
                $resolution = StockTransferDiscrepancyResolutionEnum::None;
                $reasonCode = null;

                if (bccomp($discrepancy, '0', 4) > 0) {
                    // Terjadi kekurangan barang saat transfer
                    $rawRes = $input['discrepancy_resolution'] ?? null;
                    if (! $rawRes || $rawRes === 'NONE' || $rawRes === StockTransferDiscrepancyResolutionEnum::None) {
                        throw ValidationException::withMessages([
                            'items' => "Terdapat selisih kekurangan penerimaan sebesar {$discrepancy} pada item #{$item->id}. Resolusi selisih (RETURN_TO_SOURCE atau WRITE_OFF_IN_TRANSIT) wajib ditentukan (OD-03).",
                        ]);
                    }

                    $resolution = $rawRes instanceof StockTransferDiscrepancyResolutionEnum
                        ? $rawRes
                        : StockTransferDiscrepancyResolutionEnum::from((string) $rawRes);

                    $reasonCode = $input['discrepancy_reason_code'] ?? 'SHORTAGE_IN_TRANSIT';
                }

                $item->update([
                    'quantity_received' => $qtyReceived,
                    'discrepancy_quantity' => $discrepancy,
                    'discrepancy_resolution' => $resolution,
                    'discrepancy_reason_code' => $reasonCode,
                    'notes' => $input['notes'] ?? $item->notes,
                ]);

                $rawItemId = (int) ($item->item_type === 'product' ? $item->product_id : $item->ingredient_id);

                // 1. Mutasi masuk ke cabang tujuan (TRANSFER_IN, positif)
                if (bccomp($qtyReceived, '0', 4) > 0) {
                    $allMovements[] = [
                        'store_id' => (int) $lockedTransfer->to_store_id,
                        'item_type' => $item->item_type,
                        'item_id' => $rawItemId,
                        'quantity_delta' => $qtyReceived,
                        'unit_cost' => (string) $item->carrying_unit_cost, // Propagasi carrying cost (OD-06)
                        'type' => $item->item_type === 'product'
                            ? StockMovementType::TransferIn
                            : IngredientStockMovementTypeEnum::TransferIn,
                        'reason' => "Transfer In dari Cabang #{$lockedTransfer->from_store_id} ({$lockedTransfer->transfer_number})",
                        'idempotency_key' => "st:{$lockedTransfer->id}:item:{$item->id}:receive",
                        'user_id' => $userId,
                    ];
                }

                // 2. Penanganan selisih kekurangan (OD-03)
                if (bccomp($discrepancy, '0', 4) > 0) {
                    if ($resolution === StockTransferDiscrepancyResolutionEnum::ReturnToSource) {
                        // Kembalikan selisih kekurangan ke saldo cabang asal (TRANSFER_RETURN_IN, positif)
                        $allMovements[] = [
                            'store_id' => (int) $lockedTransfer->from_store_id,
                            'item_type' => $item->item_type,
                            'item_id' => $rawItemId,
                            'quantity_delta' => $discrepancy,
                            'unit_cost' => (string) $item->carrying_unit_cost,
                            'type' => $item->item_type === 'product'
                                ? StockMovementType::TransferReturnIn
                                : IngredientStockMovementTypeEnum::TransferReturnIn,
                            'reason' => "Transfer Shortage Return dari Cabang #{$lockedTransfer->to_store_id} ({$lockedTransfer->transfer_number})",
                            'idempotency_key' => "st:{$lockedTransfer->id}:item:{$item->id}:return_to_source",
                            'user_id' => $userId,
                        ];
                    }
                    // Jika WRITE_OFF_IN_TRANSIT:
                    // Stok fisik di cabang asal sudah berkurang saat TRANSFER_OUT dan tidak masuk ke cabang tujuan.
                    // Konservasi massa dan akuntabilitas terjaga tanpa mutasi fisik tambahan.
                }
            }

            // Eksekusi seluruh mutasi saldo lintas cabang secara atomik
            // InventoryLedgerService otomatis melakukan canonical lock ordering (store_id, item_type, item_id)
            // sehingga mencegah cross-branch deadlock!
            if (! empty($allMovements)) {
                $this->ledgerService->recordBatchMovements(
                    (int) $lockedTransfer->tenant_id,
                    $allMovements
                );
            }

            $now = now();
            $receiveKey = $receiveIdempotencyKey ?? (string) Str::uuid();

            $lockedTransfer->update([
                'status' => StockTransferStatusEnum::Received,
                'received_at' => $now,
                'received_by' => $userId,
                'receive_idempotency_key' => $receiveKey,
                'notes' => isset($receiptData['notes']) ? trim(($lockedTransfer->notes ?? '')."\n".$receiptData['notes']) : $lockedTransfer->notes,
            ]);

            $this->auditTrailService->log(
                tenantId: (int) $lockedTransfer->tenant_id,
                action: 'inventory.stock_transfer.received',
                userId: $userId,
                storeId: (int) $lockedTransfer->to_store_id,
                subjectType: StockTransfer::class,
                subjectId: (int) $lockedTransfer->id,
                description: "Transfer stok #{$lockedTransfer->transfer_number} diterima di cabang #{$lockedTransfer->to_store_id} (Status: RECEIVED).",
                metadata: [
                    'transfer_number' => $lockedTransfer->transfer_number,
                    'from_store_id' => $lockedTransfer->from_store_id,
                    'to_store_id' => $lockedTransfer->to_store_id,
                    'received_at' => $now->toIso8601String(),
                ]
            );

            return $lockedTransfer->fresh(['items.product', 'items.ingredient']);
        });
    }

    /**
     * Membatalkan dokumen transfer stok.
     * Jika transfer berstatus IN_TRANSIT, barang yang sudah dikirim dikembalikan ke cabang asal.
     */
    public function cancelTransfer(StockTransfer $transfer, int $userId, ?string $reason = null): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $userId, $reason) {
            /** @var StockTransfer $lockedTransfer */
            $lockedTransfer = StockTransfer::withoutTenantScope()
                ->withoutGlobalScopes()
                ->where('tenant_id', $transfer->tenant_id)
                ->where('id', $transfer->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedTransfer->status->canCancel()) {
                throw new DomainException("Transfer dalam status {$lockedTransfer->status->value} tidak dapat dibatalkan.");
            }

            // Jika transfer dibatalkan saat IN_TRANSIT, stok yang sudah keluar dari cabang asal harus dipulihkan
            if ($lockedTransfer->isInTransit()) {
                $items = $lockedTransfer->items()->get();
                $restorationMovements = [];

                foreach ($items as $item) {
                    $qtyShipped = (string) $item->quantity_shipped;
                    if (bccomp($qtyShipped, '0', 4) > 0) {
                        $rawItemId = (int) ($item->item_type === 'product' ? $item->product_id : $item->ingredient_id);

                        $restorationMovements[] = [
                            'store_id' => (int) $lockedTransfer->from_store_id,
                            'item_type' => $item->item_type,
                            'item_id' => $rawItemId,
                            'quantity_delta' => $qtyShipped, // Pemulihan positif kembali ke cabang asal
                            'unit_cost' => (string) $item->carrying_unit_cost,
                            'type' => $item->item_type === 'product'
                                ? StockMovementType::TransferReturnIn
                                : IngredientStockMovementTypeEnum::TransferReturnIn,
                            'reason' => "Pemulihan Stok Akibat Pembatalan Transfer #{$lockedTransfer->transfer_number}",
                            'idempotency_key' => "st:{$lockedTransfer->id}:item:{$item->id}:cancel_restore",
                            'user_id' => $userId,
                        ];
                    }
                }

                if (! empty($restorationMovements)) {
                    $this->ledgerService->recordBatchMovements(
                        (int) $lockedTransfer->tenant_id,
                        $restorationMovements
                    );
                }
            }

            $now = now();
            $lockedTransfer->update([
                'status' => StockTransferStatusEnum::Cancelled,
                'cancelled_at' => $now,
                'cancelled_by' => $userId,
                'notes' => $reason ? trim(($lockedTransfer->notes ?? '')."\n[Dibatalkan]: {$reason}") : $lockedTransfer->notes,
            ]);

            $this->auditTrailService->log(
                tenantId: (int) $lockedTransfer->tenant_id,
                action: 'inventory.stock_transfer.cancelled',
                userId: $userId,
                storeId: (int) $lockedTransfer->from_store_id,
                subjectType: StockTransfer::class,
                subjectId: (int) $lockedTransfer->id,
                description: "Transfer stok #{$lockedTransfer->transfer_number} dibatalkan.",
                metadata: [
                    'transfer_number' => $lockedTransfer->transfer_number,
                    'reason' => $reason,
                ]
            );

            return $lockedTransfer->fresh(['items']);
        });
    }

    // =========================================================================
    // HELPER INTERNAL METHODS
    // =========================================================================

    /**
     * Validasi item data transfer dan simpan ke database dengan XOR constraint.
     *
     * @param  array<string, mixed>  $itemData
     */
    protected function validateAndCreateItem(StockTransfer $transfer, array $itemData): StockTransferItem
    {
        $itemType = $itemData['item_type'] ?? null;
        if (! in_array($itemType, ['product', 'ingredient'], true)) {
            throw new InvalidArgumentException("item_type harus bernilai 'product' atau 'ingredient'.");
        }

        $productId = isset($itemData['product_id']) ? (int) $itemData['product_id'] : null;
        $ingredientId = isset($itemData['ingredient_id']) ? (int) $itemData['ingredient_id'] : null;

        if ($itemType === 'product') {
            if (! $productId || $ingredientId !== null) {
                throw new InvalidArgumentException('Item bertipe product wajib menyertakan product_id dan mengosongkan ingredient_id.');
            }

            $product = Product::withoutGlobalScopes()
                ->where('tenant_id', $transfer->tenant_id)
                ->where('id', $productId)
                ->first();

            if (! $product) {
                throw new InvalidArgumentException("Product #{$productId} tidak valid untuk tenant ini.");
            }
        } else {
            if (! $ingredientId || $productId !== null) {
                throw new InvalidArgumentException('Item bertipe ingredient wajib menyertakan ingredient_id dan mengosongkan product_id.');
            }

            $ingredient = Ingredient::withoutGlobalScopes()
                ->where('tenant_id', $transfer->tenant_id)
                ->where('id', $ingredientId)
                ->first();

            if (! $ingredient) {
                throw new InvalidArgumentException("Ingredient #{$ingredientId} tidak valid untuk tenant ini.");
            }
        }

        $qtyRequested = (string) ($itemData['quantity_requested'] ?? '0');
        if (bccomp($qtyRequested, '0', 4) <= 0) {
            throw new InvalidArgumentException('quantity_requested harus bernilai lebih besar dari 0.');
        }

        $qtyShipped = isset($itemData['quantity_shipped']) ? (string) $itemData['quantity_shipped'] : '0.0000';
        if (bccomp($qtyShipped, '0', 4) < 0) {
            throw new InvalidArgumentException('quantity_shipped tidak boleh negatif.');
        }

        return StockTransferItem::create([
            'tenant_id' => $transfer->tenant_id,
            'stock_transfer_id' => $transfer->id,
            'item_type' => $itemType,
            'product_id' => $productId,
            'ingredient_id' => $ingredientId,
            'quantity_requested' => $qtyRequested,
            'quantity_shipped' => $qtyShipped,
            'quantity_received' => null,
            'discrepancy_quantity' => '0.0000',
            'discrepancy_resolution' => StockTransferDiscrepancyResolutionEnum::None,
            'discrepancy_reason_code' => null,
            'carrying_unit_cost' => '0.0000',
            'notes' => $itemData['notes'] ?? null,
        ]);
    }

    /**
     * Generate format nomor transfer stok unik: TRF-{from_store_id}-{to_store_id}-{YYYYMMDD}-{XXXX}
     */
    protected function generateTransferNumber(int $tenantId, int $fromStoreId, int $toStoreId): string
    {
        $datePrefix = date('Ymd');
        $prefix = "TRF-{$fromStoreId}-{$toStoreId}-{$datePrefix}-";

        $latest = StockTransfer::withoutTenantScope()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('from_store_id', $fromStoreId)
            ->where('to_store_id', $toStoreId)
            ->where('transfer_number', 'LIKE', "{$prefix}%")
            ->orderByDesc('transfer_number')
            ->value('transfer_number');

        if ($latest) {
            $lastSeq = (int) substr($latest, -4);
            $seq = str_pad((string) ($lastSeq + 1), 4, '0', STR_PAD_LEFT);
        } else {
            $seq = '0001';
        }

        return "{$prefix}{$seq}";
    }
}
