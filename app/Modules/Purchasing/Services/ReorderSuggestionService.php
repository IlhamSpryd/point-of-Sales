<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Enums\PoReceivingStatusEnum;
use App\Enums\PurchaseOrderStatusEnum;
use App\Enums\PurchaseRequestFulfillmentStatusEnum;
use App\Enums\PurchaseRequestStatusEnum;
use App\Enums\ReorderSuggestionStatusEnum;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\User;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestLine;
use App\Modules\Purchasing\Models\ReorderSuggestion;
use App\Modules\Purchasing\Models\SupplierProduct;
use App\Services\AuditTrailService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReorderSuggestionService
{
    public function __construct(
        protected PurchaseRequestService $purchaseRequestService,
        protected DocumentSequenceService $sequenceService,
        protected AuditTrailService $auditTrailService
    ) {}

    /**
     * Hitung Posisi Stok Bersih (Net Inventory Position) secara deterministik.
     * Formula: NetPosition = Q_on_hand + Q_on_order_po + Q_open_approved_pr
     *
     * @return array{
     *     current_stock: string,
     *     on_order_po_qty: string,
     *     open_pr_qty: string,
     *     net_inventory_position: string
     * }
     */
    public function calculateNetInventoryPosition(
        int $tenantId,
        int $storeId,
        string $purchasableType,
        int $purchasableId
    ): array {
        // 1. Q_on_hand (Saldo stok saat ini dari M4)
        $onHand = '0.0000';
        if ($purchasableType === 'product') {
            $balance = StockBalance::query()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('product_id', $purchasableId)
                ->first();

            if ($balance) {
                $onHand = (string) $balance->quantity;
            }
        } elseif ($purchasableType === 'ingredient') {
            $ingBalance = DB::table('ingredient_stock_balances')
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('ingredient_id', $purchasableId)
                ->first();

            if ($ingBalance) {
                $onHand = (string) $ingBalance->quantity;
            } else {
                $ing = Ingredient::query()
                    ->where('tenant_id', $tenantId)
                    ->find($purchasableId);
                if ($ing) {
                    $onHand = (string) $ing->current_stock;
                }
            }
        }

        // 2. Q_on_order_po (Sisa penerimaan PO yang disetujui / dirilis untuk store_id ini)
        $openPoLines = PurchaseOrderLine::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
            ->where('purchase_orders.tenant_id', $tenantId)
            ->where('purchase_orders.store_id', $storeId)
            ->whereIn('purchase_orders.status', [
                PurchaseOrderStatusEnum::Approved->value,
                PurchaseOrderStatusEnum::Released->value,
            ])
            ->whereNotIn('purchase_orders.receiving_status', [
                PoReceivingStatusEnum::FullyReceived->value,
                PoReceivingStatusEnum::OverReceived->value,
            ])
            ->where('purchase_order_lines.purchasable_type', $purchasableType)
            ->where('purchase_order_lines.purchasable_id', $purchasableId)
            ->select('purchase_order_lines.*')
            ->get();

        $onOrderPo = '0.0000';
        foreach ($openPoLines as $pol) {
            $remQty = bcsub((string) $pol->ordered_qty, (string) $pol->accepted_qty, 4);
            if (bccomp($remQty, '0.0000', 4) > 0) {
                $uomFactor = (string) ($pol->uom_conversion_factor ?? '1.000000');
                $baseRem = bcmul($remQty, $uomFactor, 4);
                $onOrderPo = bcadd($onOrderPo, $baseRem, 4);
            }
        }

        // 3. Q_open_approved_pr (Sisa alokasi PR yang sudah disetujui untuk store_id ini)
        $openPrLines = PurchaseRequestLine::query()
            ->join('purchase_requests', 'purchase_requests.id', '=', 'purchase_request_lines.purchase_request_id')
            ->where('purchase_requests.tenant_id', $tenantId)
            ->where('purchase_requests.store_id', $storeId)
            ->where('purchase_requests.status', PurchaseRequestStatusEnum::Approved->value)
            ->whereNotIn('purchase_requests.fulfillment_status', [
                PurchaseRequestFulfillmentStatusEnum::FullyOrdered->value,
            ])
            ->where('purchase_request_lines.purchasable_type', $purchasableType)
            ->where('purchase_request_lines.purchasable_id', $purchasableId)
            ->select('purchase_request_lines.*')
            ->get();

        $openPr = '0.0000';
        foreach ($openPrLines as $prl) {
            $remQty = bcsub((string) $prl->approved_qty, (string) $prl->converted_to_po_qty, 4);
            if (bccomp($remQty, '0.0000', 4) > 0) {
                $uomFactor = (string) ($prl->uom_conversion_factor ?? '1.000000');
                $baseRem = bcmul($remQty, $uomFactor, 4);
                $openPr = bcadd($openPr, $baseRem, 4);
            }
        }

        // 4. NetPosition = Q_on_hand + Q_on_order_po + Q_open_approved_pr
        $netPosition = bcadd(bcadd($onHand, $onOrderPo, 4), $openPr, 4);

        return [
            'current_stock' => $onHand,
            'on_order_po_qty' => $onOrderPo,
            'open_pr_qty' => $openPr,
            'net_inventory_position' => $netPosition,
        ];
    }

    /**
     * Evaluasi dan buat/perbarui rekomendasi pemesanan ulang untuk satu item spesifik.
     * Sesuai Bagian 13.2 docs/implementationPlanModul5.md.
     *
     * @param array{
     *     daily_consumption_rate?: string|float,
     *     lead_time_days?: string|float,
     *     safety_stock?: string|float,
     *     reorder_point?: string|float,
     *     target_max_stock?: string|float
     * } $overrides
     */
    public function generateItemSuggestion(
        int $tenantId,
        int $storeId,
        string $purchasableType,
        int $purchasableId,
        array $overrides = []
    ): ?ReorderSuggestion {
        $netData = $this->calculateNetInventoryPosition($tenantId, $storeId, $purchasableType, $purchasableId);
        $onHand = $netData['current_stock'];
        $onOrderPo = $netData['on_order_po_qty'];
        $openPr = $netData['open_pr_qty'];
        $netPosition = $netData['net_inventory_position'];

        // Cari data katalog pemasok utama (preferred supplier)
        /** @var SupplierProduct|null $supplierProduct */
        $supplierProduct = SupplierProduct::query()
            ->where('tenant_id', $tenantId)
            ->where('purchasable_type', $purchasableType)
            ->where('purchasable_id', $purchasableId)
            ->where('is_active', true)
            ->orderBy('is_preferred', 'desc')
            ->orderBy('id', 'asc')
            ->first();

        $preferredSupplierId = $supplierProduct ? $supplierProduct->supplier_id : null;
        $moq = (string) ($supplierProduct->min_order_qty ?? $supplierProduct->moq ?? '1.0000');
        $orderMultiple = (string) ($supplierProduct->order_multiple_qty ?? $supplierProduct->order_multiple ?? '1.0000');
        $purchaseUom = (string) ($supplierProduct->purchase_uom ?? 'PCS');
        $uomFactor = (string) ($supplierProduct->uom_conversion_factor ?? '1.000000');
        $leadTimeDays = (string) ($overrides['lead_time_days'] ?? $supplierProduct->lead_time_days ?? '7.00');
        $estimatedUnitPrice = (string) ($supplierProduct->standard_price ?? $supplierProduct->unit_price ?? '0.0000');

        // Konsumsi harian rata-rata
        $dailyConsumption = (string) ($overrides['daily_consumption_rate'] ?? '0.0000');
        if (bccomp($dailyConsumption, '0.0000', 4) === 0) {
            if ($purchasableType === 'ingredient') {
                $forecast = DB::table('ingredient_restock_forecasts')
                    ->where('tenant_id', $tenantId)
                    ->where('ingredient_id', $purchasableId)
                    ->first();
                if ($forecast && isset($forecast->avg_daily_consumption)) {
                    $dailyConsumption = (string) $forecast->avg_daily_consumption;
                }
            }
        }

        // Minimum Stock & Safety Stock
        $configuredMin = '0.0000';
        $configuredMax = '0.0000';
        if ($purchasableType === 'product') {
            $balance = StockBalance::query()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('product_id', $purchasableId)
                ->first();
            if ($balance) {
                $configuredMin = (string) ($balance->min_stock_level ?? $balance->reorder_point ?? '0.0000');
                $configuredMax = (string) ($balance->max_stock_level ?? '0.0000');
            }
        } elseif ($purchasableType === 'ingredient') {
            $ing = Ingredient::query()->where('tenant_id', $tenantId)->find($purchasableId);
            if ($ing) {
                $configuredMin = (string) ($ing->reorder_level ?? '0.0000');
            }
        }

        $safetyStock = (string) ($overrides['safety_stock'] ?? '0.0000');
        $dynamicRop = bcadd(bcmul($dailyConsumption, $leadTimeDays, 4), $safetyStock, 4);

        $effectiveRop = bccomp($configuredMin, $dynamicRop, 4) > 0 ? $configuredMin : $dynamicRop;
        if (isset($overrides['reorder_point'])) {
            $effectiveRop = (string) $overrides['reorder_point'];
        }

        // Target Max Stock
        $targetMaxStock = (string) ($overrides['target_max_stock'] ?? $configuredMax);
        if (bccomp($targetMaxStock, $effectiveRop, 4) <= 0) {
            // Jika max stock belum disetel atau <= ROP, pasang default aman: ROP + (MOQ * uomFactor) atau ROP * 2
            $defaultBuffer = bcmul($moq, $uomFactor, 4);
            if (bccomp($defaultBuffer, '0.0000', 4) === 0) {
                $defaultBuffer = '10.0000';
            }
            $targetMaxStock = bcadd($effectiveRop, $defaultBuffer, 4);
        }

        // Pemicu Reorder: NetPosition <= EffectiveROP
        if (bccomp($netPosition, $effectiveRop, 4) > 0) {
            return null; // Stok masih berada di atas ambang batas
        }

        $rawDeficit = bcsub($targetMaxStock, $netPosition, 4);
        if (bccomp($rawDeficit, '0.0000', 4) <= 0) {
            return null;
        }

        // Konversi defisit ke satuan beli pemasok: Q_purch_raw = ceil(Q_raw_deficit / k_uom)
        $factorVal = (float) $uomFactor;
        if ($factorVal <= 0.0) {
            $factorVal = 1.0;
        }
        $rawPurchQty = ceil((float) $rawDeficit / $factorVal);

        // Terapkan MOQ dan Kelipatan Order (Order Multiple)
        $moqVal = (float) $moq;
        if ($moqVal < 1.0) {
            $moqVal = 1.0;
        }
        $multVal = (float) $orderMultiple;
        if ($multVal < 1.0) {
            $multVal = 1.0;
        }

        $suggestedPurch = max($moqVal, ceil($rawPurchQty / $multVal) * $multVal);
        $suggestedQuantity = number_format($suggestedPurch, 4, '.', '');

        $estimatedTotalCost = bcmul($suggestedQuantity, $estimatedUnitPrice, 2);

        $ropFloat = max(1.0, (float) $effectiveRop);
        $priorityScore = round(((float) $effectiveRop - (float) $netPosition) / $ropFloat * 100.0, 2);

        // Buat record ReorderSuggestion baru atau perbarui draf 'open' yang sudah ada
        /** @var ReorderSuggestion $suggestion */
        $suggestion = ReorderSuggestion::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'purchasable_type' => $purchasableType,
                'purchasable_id' => $purchasableId,
                'status' => ReorderSuggestionStatusEnum::Open,
            ],
            [
                'preferred_supplier_id' => $preferredSupplierId,
                'current_stock' => $onHand,
                'on_order_po_qty' => $onOrderPo,
                'open_pr_qty' => $openPr,
                'net_inventory_position' => $netPosition,
                'daily_consumption_rate' => $dailyConsumption,
                'lead_time_days' => $leadTimeDays,
                'safety_stock' => $safetyStock,
                'reorder_point' => $effectiveRop,
                'target_max_stock' => $targetMaxStock,
                'raw_deficit_qty' => $rawDeficit,
                'moq' => $moq,
                'order_multiple' => $orderMultiple,
                'suggested_quantity' => $suggestedQuantity,
                'purchase_uom' => $purchaseUom,
                'estimated_unit_price' => $estimatedUnitPrice,
                'estimated_total_cost' => $estimatedTotalCost,
                'priority_score' => number_format($priorityScore, 2, '.', ''),
                'calculated_at' => Carbon::now(),
            ]
        );

        return $suggestion;
    }

    /**
     * Jalankan evaluasi pemesanan ulang massal untuk suatu Store.
     *
     * @return Collection<int, ReorderSuggestion>
     */
    public function generateStoreSuggestions(int $tenantId, int $storeId, ?string $purchasableType = null): Collection
    {
        $suggestions = new Collection;

        if ($purchasableType === null || $purchasableType === 'product') {
            $products = Product::query()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->get();

            foreach ($products as $prod) {
                $sug = $this->generateItemSuggestion($tenantId, $storeId, 'product', (int) $prod->id);
                if ($sug !== null) {
                    $suggestions->push($sug);
                }
            }
        }

        if ($purchasableType === null || $purchasableType === 'ingredient') {
            $ingredients = Ingredient::query()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->get();

            foreach ($ingredients as $ing) {
                $sug = $this->generateItemSuggestion($tenantId, $storeId, 'ingredient', (int) $ing->id);
                if ($sug !== null) {
                    $suggestions->push($sug);
                }
            }
        }

        return $suggestions;
    }

    /**
     * Konversikan kumpulan saran pemesanan ulang menjadi satu draf Purchase Request (PR).
     * Batas Keamanan: Hanya membuat DRAF PR, tidak memutasikan stok atau membuat PO otomatis.
     *
     * @param  int[]  $suggestionIds
     */
    public function convertSuggestionsToPurchaseRequest(
        int $tenantId,
        int $storeId,
        array $suggestionIds,
        User $actor,
        ?string $requiredDate = null
    ): PurchaseRequest {
        if (empty($suggestionIds)) {
            throw ValidationException::withMessages([
                'suggestion_ids' => ['Minimal satu rekomendasi pemesanan ulang harus dipilih untuk konversi.'],
            ]);
        }

        return DB::transaction(function () use ($tenantId, $storeId, $suggestionIds, $actor, $requiredDate) {
            /** @var Collection<int, ReorderSuggestion> $suggestions */
            $suggestions = ReorderSuggestion::query()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->whereIn('id', $suggestionIds)
                ->lockForUpdate()
                ->get();

            if ($suggestions->count() !== count($suggestionIds)) {
                throw ValidationException::withMessages([
                    'suggestion_ids' => ['Beberapa rekomendasi pemesanan ulang tidak ditemukan pada cabang ini.'],
                ]);
            }

            foreach ($suggestions as $sug) {
                if ($sug->status !== ReorderSuggestionStatusEnum::Open) {
                    throw new DomainException("Saran pemesanan ulang #{$sug->id} berstatus '{$sug->status->value}' dan tidak dapat dikonversi.");
                }
            }

            $dateRequired = $requiredDate ?? Carbon::now()->addDays(7)->toDateString();

            $linesData = [];
            foreach ($suggestions as $sug) {
                $linesData[] = [
                    'purchasable_type' => $sug->purchasable_type,
                    'purchasable_id' => (int) $sug->purchasable_id,
                    'requested_qty' => (string) $sug->suggested_quantity,
                    'uom' => $sug->purchase_uom,
                    'purchase_uom' => $sug->purchase_uom,
                    'uom_conversion_factor' => '1.000000',
                    'estimated_unit_price' => (string) $sug->estimated_unit_price,
                    'preferred_supplier_id' => $sug->preferred_supplier_id,
                    'notes' => "Dibuat otomatis dari Saran Pemesanan Ulang #{$sug->id}",
                ];
            }

            $pr = $this->purchaseRequestService->createPurchaseRequest(
                headerData: [
                    'store_id' => $storeId,
                    'required_date' => $dateRequired,
                    'notes' => 'Generated from automated reorder suggestions engine',
                ],
                linesData: $linesData,
                requester: $actor
            );

            foreach ($suggestions as $sug) {
                $sug->status = ReorderSuggestionStatusEnum::Converted;
                $sug->converted_purchase_request_id = $pr->id;
                $sug->save();
            }

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'reorder.converted_to_pr',
                userId: $actor->id,
                subjectType: PurchaseRequest::class,
                subjectId: $pr->id,
                description: "Konversi {$suggestions->count()} saran pemesanan ulang menjadi draf PR #{$pr->pr_number}.",
                metadata: [
                    'store_id' => $storeId,
                    'suggestion_ids' => $suggestionIds,
                    'pr_number' => $pr->pr_number,
                ]
            );

            return $pr;
        });
    }

    /**
     * Abaikan (Dismiss) saran pemesanan ulang dengan alasan eksplisit.
     */
    public function dismissSuggestion(ReorderSuggestion $suggestion, string $reason, User $actor): ReorderSuggestion
    {
        if ($suggestion->status !== ReorderSuggestionStatusEnum::Open) {
            throw new DomainException("Hanya saran pemesanan ulang berstatus 'open' yang dapat diabaikan.");
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'dismissed_reason' => ['Alasan pengabaian rekomendasi pemesanan ulang wajib diisi.'],
            ]);
        }

        $suggestion->status = ReorderSuggestionStatusEnum::Dismissed;
        $suggestion->dismissed_reason = $reason;
        $suggestion->save();

        $this->auditTrailService->log(
            tenantId: (int) $suggestion->tenant_id,
            action: 'reorder.dismissed',
            userId: $actor->id,
            subjectType: ReorderSuggestion::class,
            subjectId: $suggestion->id,
            description: "Saran pemesanan ulang #{$suggestion->id} diabaikan: {$reason}",
            metadata: [
                'reason' => $reason,
                'purchasable_type' => $suggestion->purchasable_type,
                'purchasable_id' => $suggestion->purchasable_id,
            ]
        );

        return $suggestion;
    }
}
