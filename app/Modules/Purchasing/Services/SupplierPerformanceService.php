<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Enums\GrnStatusEnum;
use App\Enums\PoReceivingStatusEnum;
use App\Modules\Purchasing\Models\GoodsReceivedNote;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\SupplierPerformanceLog;
use Carbon\Carbon;

class SupplierPerformanceService
{
    /**
     * Catat log historis kinerja pengiriman dan harga pemasok saat GRN diposting.
     * Sesuai Bagian 13.1 docs/implementationPlanModul5.md.
     */
    public function recordGrnReceipt(GoodsReceivedNote $grn, PurchaseOrder $po): void
    {
        $tenantId = (int) $grn->tenant_id;
        $storeId = (int) $grn->store_id;
        $supplierId = (int) $po->supplier_id;
        $poId = (int) $po->id;
        $grnId = (int) $grn->id;

        $receivedAt = Carbon::parse($grn->received_at);
        $releasedAt = $po->released_at ? Carbon::parse($po->released_at) : Carbon::parse($po->order_date);
        $expectedDeliveryDate = Carbon::parse($po->expected_delivery_date);

        // Hitung LT_expected (dalam hari)
        $expectedLeadTimeDays = (float) $releasedAt->diffInDays($expectedDeliveryDate, false);
        if ($expectedLeadTimeDays < 0.0) {
            $expectedLeadTimeDays = 0.0;
        }

        // Cek apakah ada GRN yang lebih awal untuk PO ini
        $earliestPostedGrn = GoodsReceivedNote::query()
            ->where('tenant_id', $tenantId)
            ->where('purchase_order_id', $poId)
            ->where('status', GrnStatusEnum::Posted)
            ->where('id', '!=', $grnId)
            ->orderBy('received_at', 'asc')
            ->first();

        $firstReceiptAt = $earliestPostedGrn
            ? Carbon::parse($earliestPostedGrn->received_at)
            : $receivedAt;

        if ($firstReceiptAt->gt($receivedAt)) {
            $firstReceiptAt = $receivedAt;
        }

        $orderToFirstReceiptDays = round(($firstReceiptAt->getTimestamp() - $releasedAt->getTimestamp()) / 86400.0, 2);
        if ($orderToFirstReceiptDays < 0.0) {
            $orderToFirstReceiptDays = 0.0;
        }

        // Hitung LT_complete jika PO sudah fully_received atau over_received
        $isFullyReceived = in_array($po->receiving_status, [
            PoReceivingStatusEnum::FullyReceived,
            PoReceivingStatusEnum::OverReceived,
        ], true);

        $orderToFullReceiptDays = null;
        if ($isFullyReceived) {
            $orderToFullReceiptDays = round(($receivedAt->getTimestamp() - $releasedAt->getTimestamp()) / 86400.0, 2);
            if ($orderToFullReceiptDays < 0.0) {
                $orderToFullReceiptDays = 0.0;
            }
        }

        // Variance: Delta LT = LT_actual - LT_expected
        // LT_actual dihitung dari receipt saat ini
        $actualDays = round(($receivedAt->getTimestamp() - $releasedAt->getTimestamp()) / 86400.0, 2);
        $varianceFromExpectedDays = round($actualDays - $expectedLeadTimeDays, 2);

        $poLines = $po->lines()->get()->keyBy('id');
        $grnLines = $grn->lines()->get();

        foreach ($grnLines as $grnLine) {
            /** @var PurchaseOrderLine|null $poLine */
            $poLine = $poLines->get($grnLine->purchase_order_line_id);

            $orderedQty = $poLine ? (string) $poLine->ordered_qty : (string) $grnLine->received_qty;
            $unitPrice = $poLine ? (string) $poLine->unit_price : '0.0000';
            $effectiveCost = (string) ($grnLine->effective_unit_cost ?? $unitPrice);

            SupplierPerformanceLog::query()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'goods_received_note_line_id' => $grnLine->id,
                ],
                [
                    'store_id' => $storeId,
                    'supplier_id' => $supplierId,
                    'purchase_order_id' => $poId,
                    'goods_received_note_id' => $grnId,
                    'purchasable_type' => $grnLine->purchasable_type,
                    'purchasable_id' => $grnLine->purchasable_id,
                    'expected_delivery_date' => $expectedDeliveryDate->toDateString(),
                    'received_at' => $receivedAt,
                    'order_to_first_receipt_days' => number_format($orderToFirstReceiptDays, 2, '.', ''),
                    'order_to_full_receipt_days' => $orderToFullReceiptDays !== null ? number_format($orderToFullReceiptDays, 2, '.', '') : null,
                    'variance_from_expected_days' => number_format($varianceFromExpectedDays, 2, '.', ''),
                    'ordered_qty' => $orderedQty,
                    'accepted_qty' => (string) $grnLine->accepted_qty,
                    'unit_price' => $unitPrice,
                    'effective_unit_cost' => $effectiveCost,
                    'recorded_at' => Carbon::now(),
                ]
            );
        }
    }

    /**
     * Hitung rata-rata tertimbang eksponensial (EWMA) lead time pemasok.
     * Formula: LT_avg,new = alpha * LT_complete + (1 - alpha) * LT_avg,old
     *
     * @param  float  $alpha  Nilai perataan eksponensial (default 0.3)
     */
    public function calculateEwmaLeadTime(
        int $tenantId,
        int $supplierId,
        ?string $purchasableType = null,
        ?int $purchasableId = null,
        float $alpha = 0.3
    ): ?float {
        $query = SupplierPerformanceLog::query()
            ->where('tenant_id', $tenantId)
            ->where('supplier_id', $supplierId)
            ->whereNotNull('order_to_full_receipt_days');

        if ($purchasableType !== null && $purchasableId !== null) {
            $query->where('purchasable_type', $purchasableType)
                ->where('purchasable_id', $purchasableId);
        }

        $logs = $query->orderBy('recorded_at', 'asc')->get();

        if ($logs->isEmpty()) {
            return null;
        }

        $ewma = null;

        foreach ($logs as $log) {
            $ltComplete = (float) $log->order_to_full_receipt_days;
            if ($ewma === null) {
                $ewma = $ltComplete;
            } else {
                $ewma = ($alpha * $ltComplete) + ((1.0 - $alpha) * $ewma);
            }
        }

        return $ewma !== null ? round($ewma, 2) : null;
    }
}
