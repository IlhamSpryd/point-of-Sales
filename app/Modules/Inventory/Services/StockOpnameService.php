<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Enums\IngredientStockMovementTypeEnum;
use App\Enums\StockMovementType;
use App\Enums\StockOpnameDriftResolutionEnum;
use App\Enums\StockOpnameStatusEnum;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Store;
use App\Modules\Finance\Services\InventoryAccountingService;
use App\Modules\Inventory\Exceptions\StockOpnameDriftException;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Inventory\Models\StockOpnameItem;
use App\Modules\Settings\Services\AuditTrailService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Service Dokumen Sesi Penghitungan Fisik Stok (Stock Opname).
 *
 * Mengelola siklus penghitungan fisik stok cabang:
 * 1. Draft: Pemilihan item opname (sebagian atau seluruh katalog cabang).
 * 2. Start Counting: Snapshot Sequence Pinning (snapshot_last_movement_id & snapshot_on_hand).
 * 3. Record Count & Submit: Input hasil hitung fisik dan kalkulasi selisih awal.
 * 4. Item-Level Drift Gate (OD-04): Mendeteksi mutasi sela (POS checkout, adjustment)
 *    antara waktu snapshot dan approval, memblokir persetujuan otomatis, dan
 *    memfasilitasi resolusi eksplisit (RE_SNAPSHOT recount / REBASE_OVERRIDE).
 * 5. Approve & Reconcile: Memasukkan ledger movement OPNAME_ADJUSTMENT hanya untuk item dengan variance != 0.
 */
class StockOpnameService
{
    public function __construct(
        protected InventoryLedgerService $ledgerService,
        protected AuditTrailService $auditTrailService
    ) {}

    /**
     * Membuat draft sesi opname baru.
     *
     * @param  array{
     *     opname_number?: ?string,
     *     idempotency_key?: ?string,
     *     notes?: ?string,
     *     all_items?: bool,
     *     items?: array<int, array{
     *         item_type: 'product'|'ingredient',
     *         product_id?: ?int,
     *         ingredient_id?: ?int,
     *         notes?: ?string
     *     }>
     * }  $data
     */
    public function createDraft(int $tenantId, int $storeId, array $data, int $userId): StockOpname
    {
        $store = Store::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $storeId)
            ->first();

        if (! $store) {
            throw new InvalidArgumentException("Cabang #{$storeId} tidak ditemukan untuk tenant #{$tenantId}.");
        }

        $idempotencyKey = $data['idempotency_key'] ?? (string) Str::uuid();

        // Idempotency check
        $existing = StockOpname::withoutTenantScope()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $existing->load('items');
        }

        return DB::transaction(function () use ($tenantId, $storeId, $data, $userId, $idempotencyKey) {
            $opnameNumber = $data['opname_number'] ?? $this->generateOpnameNumber($tenantId, $storeId);

            $opname = StockOpname::create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'opname_number' => $opnameNumber,
                'idempotency_key' => $idempotencyKey,
                'status' => StockOpnameStatusEnum::Draft,
                'created_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);

            $itemsToCreate = [];

            if (! empty($data['all_items'])) {
                // Populate all products and ingredients
                $products = Product::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->get(['id']);

                foreach ($products as $prod) {
                    $itemsToCreate[] = [
                        'item_type' => 'product',
                        'product_id' => (int) $prod->id,
                        'ingredient_id' => null,
                        'notes' => null,
                    ];
                }

                $ingredients = Ingredient::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->get(['id']);

                foreach ($ingredients as $ing) {
                    $itemsToCreate[] = [
                        'item_type' => 'ingredient',
                        'product_id' => null,
                        'ingredient_id' => (int) $ing->id,
                        'notes' => null,
                    ];
                }
            } elseif (! empty($data['items'])) {
                $itemsToCreate = $data['items'];
            }

            if (empty($itemsToCreate)) {
                throw new InvalidArgumentException('Sesi opname wajib memiliki minimal 1 item untuk dihitung.');
            }

            foreach ($itemsToCreate as $itemData) {
                $this->validateAndCreateItem($opname, $itemData);
            }

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'inventory.stock_opname.created',
                userId: $userId,
                storeId: $storeId,
                subjectType: StockOpname::class,
                subjectId: (int) $opname->id,
                description: "Draft sesi stock opname #{$opname->opname_number} dibuat dengan ".count($itemsToCreate).' item',
                metadata: [
                    'opname_number' => $opname->opname_number,
                    'items_count' => count($itemsToCreate),
                ]
            );

            return $opname->load('items');
        });
    }

    /**
     * Memulai sesi penghitungan fisik stok dan melakukan Snapshot Sequence Pinning (OD-04).
     */
    public function startCounting(StockOpname $opname, int $userId): StockOpname
    {
        return DB::transaction(function () use ($opname, $userId) {
            /** @var StockOpname $lockedOpname */
            $lockedOpname = StockOpname::withoutTenantScope()
                ->withoutGlobalScopes()
                ->where('tenant_id', $opname->tenant_id)
                ->where('id', $opname->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedOpname->isDraft()) {
                throw new DomainException("Sesi opname #{$lockedOpname->opname_number} tidak dalam status DRAFT (status saat ini: {$lockedOpname->status->value}).");
            }

            $items = $lockedOpname->items()->get();
            if ($items->isEmpty()) {
                throw new DomainException('Sesi opname tidak memiliki item untuk dihitung.');
            }

            $now = now();

            foreach ($items as $item) {
                $balance = $this->getOrCreateBalanceRow(
                    tenantId: (int) $lockedOpname->tenant_id,
                    storeId: (int) $lockedOpname->store_id,
                    itemType: $item->item_type,
                    itemId: (int) ($item->item_type === 'product' ? $item->product_id : $item->ingredient_id)
                );

                $item->update([
                    'snapshot_on_hand' => $balance ? (string) $balance->quantity : '0.0000',
                    'snapshot_last_movement_id' => $balance ? $balance->last_movement_id : null,
                    'drift_resolution_mode' => StockOpnameDriftResolutionEnum::None,
                ]);
            }

            $lockedOpname->update([
                'status' => StockOpnameStatusEnum::Counting,
                'snapshot_taken_at' => $now,
            ]);

            $this->auditTrailService->log(
                tenantId: (int) $lockedOpname->tenant_id,
                action: 'inventory.stock_opname.counting_started',
                userId: $userId,
                storeId: (int) $lockedOpname->store_id,
                subjectType: StockOpname::class,
                subjectId: (int) $lockedOpname->id,
                description: "Sesi penghitungan fisik #{$lockedOpname->opname_number} dimulai. Snapshot saldo & sequence pinned.",
                metadata: [
                    'opname_number' => $lockedOpname->opname_number,
                    'snapshot_taken_at' => $now->toIso8601String(),
                    'items_count' => $items->count(),
                ]
            );

            return $lockedOpname->fresh(['items']);
        });
    }

    /**
     * Mencatat hasil hitung fisik (counted quantity) untuk satu atau beberapa baris item.
     *
     * @param  array<int, array{item_id: int, counted_quantity: float|string, notes?: ?string, reason_code?: ?string}>|array<int, float|string>  $counts
     */
    public function recordCounts(StockOpname $opname, array $counts, int $userId): StockOpname
    {
        return DB::transaction(function () use ($opname, $counts) {
            /** @var StockOpname $lockedOpname */
            $lockedOpname = StockOpname::withoutTenantScope()
                ->withoutGlobalScopes()
                ->where('tenant_id', $opname->tenant_id)
                ->where('id', $opname->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedOpname->isCounting()) {
                throw new DomainException("Hasil hitung hanya dapat dicatat saat sesi berstatus COUNTING (status saat ini: {$lockedOpname->status->value}).");
            }

            // Normalisasi array format: array of associative arrays OR key => value map
            $normalizedCounts = [];
            foreach ($counts as $key => $value) {
                if (is_array($value)) {
                    $normalizedCounts[(int) $value['item_id']] = [
                        'counted_quantity' => (string) $value['counted_quantity'],
                        'notes' => $value['notes'] ?? null,
                        'reason_code' => $value['reason_code'] ?? null,
                    ];
                } else {
                    $normalizedCounts[(int) $key] = [
                        'counted_quantity' => (string) $value,
                        'notes' => null,
                        'reason_code' => null,
                    ];
                }
            }

            $items = $lockedOpname->items()->whereIn('id', array_keys($normalizedCounts))->get();

            foreach ($items as $item) {
                $input = $normalizedCounts[$item->id];
                $countedQty = $input['counted_quantity'];

                if (bccomp($countedQty, '0', 4) < 0) {
                    throw new InvalidArgumentException("Hasil hitung fisik untuk item #{$item->id} tidak boleh bernilai negatif.");
                }

                $variance = bcsub($countedQty, (string) $item->snapshot_on_hand, 4);

                $updateData = [
                    'counted_quantity' => $countedQty,
                    'variance_quantity' => $variance,
                ];

                if ($input['notes'] !== null) {
                    $updateData['notes'] = $input['notes'];
                }
                if ($input['reason_code'] !== null) {
                    $updateData['reason_code'] = $input['reason_code'];
                }

                $item->update($updateData);
            }

            return $lockedOpname->fresh(['items']);
        });
    }

    /**
     * Mengajukan hasil opname untuk persetujuan manajer (Submit for Approval).
     */
    public function submitForApproval(StockOpname $opname, int $userId): StockOpname
    {
        return DB::transaction(function () use ($opname, $userId) {
            /** @var StockOpname $lockedOpname */
            $lockedOpname = StockOpname::withoutTenantScope()
                ->withoutGlobalScopes()
                ->where('tenant_id', $opname->tenant_id)
                ->where('id', $opname->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedOpname->isCounting()) {
                throw new DomainException("Pengajuan persetujuan hanya dapat dilakukan saat sesi berstatus COUNTING (status saat ini: {$lockedOpname->status->value}).");
            }

            $items = $lockedOpname->items()->get();
            $uncounted = $items->filter(fn (StockOpnameItem $item) => $item->counted_quantity === null);

            if ($uncounted->isNotEmpty()) {
                throw new DomainException('Seluruh item wajib diisi hasil hitung fisik sebelum diajukan ke manajer (terdapat '.$uncounted->count().' item belum dihitung).');
            }

            $now = now();

            foreach ($items as $item) {
                $variance = bcsub((string) $item->counted_quantity, (string) $item->snapshot_on_hand, 4);
                $item->update([
                    'variance_quantity' => $variance,
                ]);
            }

            $lockedOpname->update([
                'status' => StockOpnameStatusEnum::PendingApproval,
                'submitted_at' => $now,
                'submitted_by' => $userId,
            ]);

            $this->auditTrailService->log(
                tenantId: (int) $lockedOpname->tenant_id,
                action: 'inventory.stock_opname.submitted',
                userId: $userId,
                storeId: (int) $lockedOpname->store_id,
                subjectType: StockOpname::class,
                subjectId: (int) $lockedOpname->id,
                description: "Sesi opname #{$lockedOpname->opname_number} diajukan untuk persetujuan manajer.",
                metadata: [
                    'opname_number' => $lockedOpname->opname_number,
                    'submitted_at' => $now->toIso8601String(),
                    'items_count' => $items->count(),
                ]
            );

            return $lockedOpname->fresh(['items']);
        });
    }

    /**
     * Menyelesaikan status drift pada item opname tertentu (OD-04).
     */
    public function resolveDrift(
        StockOpname $opname,
        int $itemId,
        StockOpnameDriftResolutionEnum|string $mode,
        int $userId
    ): StockOpnameItem {
        $resolutionEnum = $mode instanceof StockOpnameDriftResolutionEnum
            ? $mode
            : StockOpnameDriftResolutionEnum::from((string) $mode);

        return DB::transaction(function () use ($opname, $itemId, $resolutionEnum, $userId) {
            /** @var StockOpname $lockedOpname */
            $lockedOpname = StockOpname::withoutTenantScope()
                ->withoutGlobalScopes()
                ->where('tenant_id', $opname->tenant_id)
                ->where('id', $opname->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var StockOpnameItem $item */
            $item = $lockedOpname->items()->where('id', $itemId)->lockForUpdate()->firstOrFail();

            if ($resolutionEnum === StockOpnameDriftResolutionEnum::ReSnapshot) {
                // Re-snapshot: Ambil ulang saldo dan last movement terkini, kosongkan hasil hitung
                $balance = $this->getOrCreateBalanceRow(
                    tenantId: (int) $lockedOpname->tenant_id,
                    storeId: (int) $lockedOpname->store_id,
                    itemType: $item->item_type,
                    itemId: (int) ($item->item_type === 'product' ? $item->product_id : $item->ingredient_id)
                );

                $item->update([
                    'snapshot_on_hand' => $balance ? (string) $balance->quantity : '0.0000',
                    'snapshot_last_movement_id' => $balance ? $balance->last_movement_id : null,
                    'counted_quantity' => null,
                    'variance_quantity' => null,
                    'drift_resolution_mode' => StockOpnameDriftResolutionEnum::None,
                ]);

                // Kembalikan status opname ke COUNTING agar petugas hitung dapat mengulang hitungan
                if ($lockedOpname->status === StockOpnameStatusEnum::PendingApproval) {
                    $lockedOpname->update([
                        'status' => StockOpnameStatusEnum::Counting,
                        'submitted_at' => null,
                        'submitted_by' => null,
                    ]);
                }

                $this->auditTrailService->log(
                    tenantId: (int) $lockedOpname->tenant_id,
                    action: 'inventory.stock_opname.re_snapshot',
                    userId: $userId,
                    storeId: (int) $lockedOpname->store_id,
                    subjectType: StockOpname::class,
                    subjectId: (int) $lockedOpname->id,
                    description: "Item #{$item->id} di-resnapshot ke posisi saldo terkini ({$item->snapshot_on_hand}). Status sesi dikembalikan ke COUNTING.",
                    metadata: [
                        'item_id' => $item->id,
                        'snapshot_on_hand' => $item->snapshot_on_hand,
                        'snapshot_last_movement_id' => $item->snapshot_last_movement_id,
                    ]
                );
            } elseif ($resolutionEnum === StockOpnameDriftResolutionEnum::RebaseOverride) {
                // Rebase Override: Manajer mengesampingkan drift dan menerima hasil hitung fisik
                $item->update([
                    'drift_resolution_mode' => StockOpnameDriftResolutionEnum::RebaseOverride,
                ]);

                $this->auditTrailService->log(
                    tenantId: (int) $lockedOpname->tenant_id,
                    action: 'inventory.stock_opname.rebase_override',
                    userId: $userId,
                    storeId: (int) $lockedOpname->store_id,
                    subjectType: StockOpname::class,
                    subjectId: (int) $lockedOpname->id,
                    description: "Item #{$item->id} disetujui untuk REBASE OVERRIDE oleh manajer.",
                    metadata: [
                        'item_id' => $item->id,
                        'drift_resolution_mode' => StockOpnameDriftResolutionEnum::RebaseOverride->value,
                    ]
                );
            }

            return $item->fresh();
        });
    }

    /**
     * Menyetujui hasil rekonsiliasi opname dengan penegakan Item-Level Drift Gate (OD-04).
     *
     * @param  array<int, StockOpnameDriftResolutionEnum|string>  $driftResolutions  Map item_id => resolution mode
     *
     * @throws StockOpnameDriftException|DomainException
     */
    public function approveOpname(StockOpname $opname, int $userId, array $driftResolutions = []): StockOpname
    {
        return DB::transaction(function () use ($opname, $userId, $driftResolutions) {
            /** @var StockOpname $lockedOpname */
            $lockedOpname = StockOpname::withoutTenantScope()
                ->withoutGlobalScopes()
                ->where('tenant_id', $opname->tenant_id)
                ->where('id', $opname->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOpname->isApproved()) {
                // Idempoten
                return $lockedOpname->load(['items.product', 'items.ingredient']);
            }

            if (! $lockedOpname->isPendingApproval()) {
                throw new DomainException("Sesi opname #{$lockedOpname->opname_number} tidak dalam status PENDING_APPROVAL (status: {$lockedOpname->status->value}).");
            }

            $items = $lockedOpname->items()->get();
            if ($items->isEmpty()) {
                throw new DomainException('Sesi opname tidak memiliki item untuk disetujui.');
            }

            // Terapkan resolusi drift ad-hoc jika dilewatkan dalam parameter approval
            foreach ($driftResolutions as $itemId => $resolution) {
                $targetItem = $items->firstWhere('id', (int) $itemId);
                if ($targetItem) {
                    $resEnum = $resolution instanceof StockOpnameDriftResolutionEnum
                        ? $resolution
                        : StockOpnameDriftResolutionEnum::from((string) $resolution);

                    if ($resEnum === StockOpnameDriftResolutionEnum::RebaseOverride) {
                        $targetItem->drift_resolution_mode = StockOpnameDriftResolutionEnum::RebaseOverride;
                        $targetItem->save();
                    }
                }
            }

            // Urutkan item secara kanonik anti-deadlock (INV-M4-07):
            // store_id ASC, item_type ASC ('ingredient' < 'product'), item_id ASC
            $sortedItems = $items->all();
            usort($sortedItems, function (StockOpnameItem $a, StockOpnameItem $b): int {
                $typeWeightA = $a->item_type === 'ingredient' ? 0 : 1;
                $typeWeightB = $b->item_type === 'ingredient' ? 0 : 1;
                if ($typeWeightA !== $typeWeightB) {
                    return $typeWeightA <=> $typeWeightB;
                }

                $idA = (int) ($a->item_type === 'product' ? $a->product_id : $a->ingredient_id);
                $idB = (int) ($b->item_type === 'product' ? $b->product_id : $b->ingredient_id);

                return $idA <=> $idB;
            });

            // 1. Kunci seluruh baris saldo stok dan jalankan ITEM-LEVEL DRIFT GATE (OD-04)
            $driftedItems = [];
            $lockedBalances = [];

            foreach ($sortedItems as $item) {
                $rawItemId = (int) ($item->item_type === 'product' ? $item->product_id : $item->ingredient_id);
                $key = "{$item->item_type}_{$rawItemId}";

                $balance = $this->lockBalanceRow(
                    tenantId: (int) $lockedOpname->tenant_id,
                    storeId: (int) $lockedOpname->store_id,
                    itemType: $item->item_type,
                    itemId: $rawItemId
                );

                $currentQty = $balance ? (string) $balance->quantity : '0.0000';
                $currentMovementId = $balance ? $balance->last_movement_id : null;
                $lockedBalances[$key] = $balance;

                // Evaluasi apakah saldo atau urutan mutasi berubah sejak snapshot diambil
                $qtyChanged = bccomp($currentQty, (string) $item->snapshot_on_hand, 4) !== 0;
                $movementChanged = (int) $currentMovementId !== (int) $item->snapshot_last_movement_id;

                if ($qtyChanged || $movementChanged) {
                    // Drift terdeteksi!
                    if ($item->drift_resolution_mode === StockOpnameDriftResolutionEnum::None) {
                        $itemName = $item->item_type === 'product'
                            ? ($item->product->product_name ?? "Product #{$item->product_id}")
                            : ($item->ingredient->name ?? "Ingredient #{$item->ingredient_id}");

                        $driftedItems[] = [
                            'item_id' => (int) $item->id,
                            'item_type' => $item->item_type,
                            'product_id' => $item->product_id,
                            'ingredient_id' => $item->ingredient_id,
                            'name' => $itemName,
                            'snapshot_on_hand' => (string) $item->snapshot_on_hand,
                            'current_on_hand' => $currentQty,
                            'snapshot_last_movement_id' => $item->snapshot_last_movement_id,
                            'current_last_movement_id' => $currentMovementId,
                            'drift_delta' => bcsub($currentQty, (string) $item->snapshot_on_hand, 4),
                        ];
                    } elseif ($item->drift_resolution_mode === StockOpnameDriftResolutionEnum::RebaseOverride) {
                        // Rebase terhadap saldo terkini
                        $rebasedVariance = bcsub((string) $item->counted_quantity, $currentQty, 4);
                        $item->update([
                            'variance_quantity' => $rebasedVariance,
                        ]);
                    } elseif ($item->drift_resolution_mode === StockOpnameDriftResolutionEnum::ReSnapshot) {
                        throw new DomainException("Item #{$item->id} ditandai RE_SNAPSHOT dan membutuhkan hitung ulang fisik sebelum opname disetujui.");
                    }
                }
            }

            // Jika terdeteksi item drift tanpa resolusi, batalkan transaksi dan lempar exception
            if (! empty($driftedItems)) {
                throw new StockOpnameDriftException($driftedItems);
            }

            // 2. Rekam mutasi OPNAME_ADJUSTMENT hanya untuk item dengan variance != 0
            $movementsToRecord = [];

            foreach ($sortedItems as $item) {
                // Refresh item state
                $item->refresh();

                if ($item->counted_quantity === null) {
                    throw new DomainException("Item #{$item->id} belum memiliki data hasil hitung fisik.");
                }

                $variance = (string) $item->variance_quantity;

                if (bccomp($variance, '0', 4) !== 0) {
                    $rawItemId = (int) ($item->item_type === 'product' ? $item->product_id : $item->ingredient_id);

                    $movementsToRecord[] = [
                        'store_id' => (int) $lockedOpname->store_id,
                        'item_type' => $item->item_type,
                        'item_id' => $rawItemId,
                        'quantity_delta' => $variance,
                        'unit_cost' => null,
                        'type' => $item->item_type === 'product'
                            ? StockMovementType::OpnameAdjustment
                            : IngredientStockMovementTypeEnum::OpnameAdjustment,
                        'reason' => "Stock Opname #{$lockedOpname->opname_number}".($item->notes ? ": {$item->notes}" : ''),
                        'idempotency_key' => "so:{$lockedOpname->id}:item:{$item->id}:approve",
                        'user_id' => $userId,
                    ];
                }
            }

            if (! empty($movementsToRecord)) {
                $this->ledgerService->recordBatchMovements(
                    (int) $lockedOpname->tenant_id,
                    $movementsToRecord
                );
            }

            $now = now();
            $lockedOpname->update([
                'status' => StockOpnameStatusEnum::Approved,
                'approved_at' => $now,
                'approved_by' => $userId,
            ]);

            $this->auditTrailService->log(
                tenantId: (int) $lockedOpname->tenant_id,
                action: 'inventory.stock_opname.approved',
                userId: $userId,
                storeId: (int) $lockedOpname->store_id,
                subjectType: StockOpname::class,
                subjectId: (int) $lockedOpname->id,
                description: "Sesi stock opname #{$lockedOpname->opname_number} disetujui. Rekonsiliasi ".count($movementsToRecord).' pergerakan stok dicatat ke ledger.',
                metadata: [
                    'opname_number' => $lockedOpname->opname_number,
                    'approved_at' => $now->toIso8601String(),
                    'adjusted_items_count' => count($movementsToRecord),
                    'total_items_count' => count($sortedItems),
                ]
            );

            // Emisi outbox akuntansi ke Modul 7
            app(InventoryAccountingService::class)->recordStockOpnameApproved($lockedOpname);

            return $lockedOpname->fresh(['items.product', 'items.ingredient']);
        });
    }

    /**
     * Membatalkan sesi opname.
     */
    public function cancelSession(StockOpname $opname, int $userId, ?string $reason = null): StockOpname
    {
        return DB::transaction(function () use ($opname, $userId, $reason) {
            /** @var StockOpname $lockedOpname */
            $lockedOpname = StockOpname::withoutTenantScope()
                ->withoutGlobalScopes()
                ->where('tenant_id', $opname->tenant_id)
                ->where('id', $opname->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedOpname->status->canCancel()) {
                throw new DomainException("Sesi opname dalam status {$lockedOpname->status->value} tidak dapat dibatalkan.");
            }

            $lockedOpname->update([
                'status' => StockOpnameStatusEnum::Cancelled,
                'notes' => $reason ? trim(($lockedOpname->notes ?? '')."\n[Dibatalkan]: {$reason}") : $lockedOpname->notes,
            ]);

            $this->auditTrailService->log(
                tenantId: (int) $lockedOpname->tenant_id,
                action: 'inventory.stock_opname.cancelled',
                userId: $userId,
                storeId: (int) $lockedOpname->store_id,
                subjectType: StockOpname::class,
                subjectId: (int) $lockedOpname->id,
                description: "Sesi stock opname #{$lockedOpname->opname_number} dibatalkan.",
                metadata: [
                    'opname_number' => $lockedOpname->opname_number,
                    'reason' => $reason,
                ]
            );

            return $lockedOpname->fresh();
        });
    }

    // =========================================================================
    // HELPER INTERNAL METHODS
    // =========================================================================

    /**
     * Validasi item data dan simpan ke database dengan mematuhi Invariant XOR INV-M4-02.
     *
     * @param  array<string, mixed>  $itemData
     */
    protected function validateAndCreateItem(StockOpname $opname, array $itemData): StockOpnameItem
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
                ->where('tenant_id', $opname->tenant_id)
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
                ->where('tenant_id', $opname->tenant_id)
                ->where('id', $ingredientId)
                ->first();

            if (! $ingredient) {
                throw new InvalidArgumentException("Ingredient #{$ingredientId} tidak valid untuk tenant ini.");
            }
        }

        return StockOpnameItem::create([
            'tenant_id' => $opname->tenant_id,
            'store_id' => $opname->store_id,
            'stock_opname_id' => $opname->id,
            'item_type' => $itemType,
            'product_id' => $productId,
            'ingredient_id' => $ingredientId,
            'snapshot_on_hand' => '0.0000',
            'snapshot_last_movement_id' => null,
            'counted_quantity' => null,
            'variance_quantity' => null,
            'drift_resolution_mode' => StockOpnameDriftResolutionEnum::None,
            'reason_code' => $itemData['reason_code'] ?? null,
            'notes' => $itemData['notes'] ?? null,
        ]);
    }

    /**
     * Pastikan baris saldo ada di database dan ambil objek saldonya.
     */
    protected function getOrCreateBalanceRow(int $tenantId, int $storeId, string $itemType, int $itemId): ?object
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

        return DB::table($table)
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where($column, $itemId)
            ->first();
    }

    /**
     * Kunci baris saldo dengan SELECT ... FOR UPDATE.
     */
    protected function lockBalanceRow(int $tenantId, int $storeId, string $itemType, int $itemId): ?object
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

    /**
     * Generate format nomor dokumen opname unik: OPN-{store_id}-{YYYYMMDD}-{XXXX}
     */
    protected function generateOpnameNumber(int $tenantId, int $storeId): string
    {
        $datePrefix = date('Ymd');
        $prefix = "OPN-{$storeId}-{$datePrefix}-";

        $latest = StockOpname::withoutTenantScope()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('opname_number', 'LIKE', "{$prefix}%")
            ->orderByDesc('opname_number')
            ->value('opname_number');

        if ($latest) {
            $lastSeq = (int) substr($latest, -4);
            $seq = str_pad((string) ($lastSeq + 1), 4, '0', STR_PAD_LEFT);
        } else {
            $seq = '0001';
        }

        return "{$prefix}{$seq}";
    }
}
