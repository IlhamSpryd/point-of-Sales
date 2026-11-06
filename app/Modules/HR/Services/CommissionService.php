<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Modules\HR\Enums\CommissionBasisTypeEnum;
use App\Modules\HR\Enums\CommissionRecordStatusEnum;
use App\Modules\HR\Enums\CommissionRecordTypeEnum;
use App\Modules\HR\Models\CommissionRecord;
use App\Modules\HR\Models\CommissionRule;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CommissionService
{
    /**
     * Hitung dan berikan komisi penjualan berdasarkan pesanan yang telah dibayar / selesai.
     *
     * @return array<int, CommissionRecord>
     */
    public function calculateAndAwardOrderCommission(Order $order, int $employeeId): array
    {
        // 1. Verifikasi pesanan sudah final & lunas
        if ($order->order_status !== OrderStatus::Completed && $order->order_status !== OrderStatus::Paid) {
            return [];
        }

        $tenantId = (int) $order->tenant_id;
        $storeId = (int) $order->store_id;
        $orderDate = $order->order_date ? Carbon::parse($order->order_date)->toDateString() : now()->toDateString();
        $orderAmount = (float) ($order->order_amount ?? $order->subtotal_amount ?? 0.00);

        // 2. Ambil aturan komisi aktif untuk cabang terkait atau seluruh cabang
        $rules = CommissionRule::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where(function ($q) use ($storeId) {
                $q->whereNull('store_id')->orWhere('store_id', $storeId);
            })
            ->where('valid_from', '<=', $orderDate)
            ->where(function ($q) use ($orderDate) {
                $q->whereNull('valid_to')->orWhere('valid_to', '>=', $orderDate);
            })
            ->get();

        $createdRecords = [];

        DB::transaction(function () use (
            $rules,
            $order,
            $tenantId,
            $storeId,
            $employeeId,
            $orderAmount,
            &$createdRecords
        ) {
            foreach ($rules as $rule) {
                if ($rule->basis_type === CommissionBasisTypeEnum::PERCENTAGE_OF_SALES) {
                    if ($orderAmount >= $rule->min_sales_threshold && $rule->rate_percent > 0) {
                        $commission = round($orderAmount * ($rule->rate_percent / 100), 2);
                        if ($commission > 0) {
                            $createdRecords[] = CommissionRecord::create([
                                'tenant_id' => $tenantId,
                                'store_id' => $storeId,
                                'employee_id' => $employeeId,
                                'commission_rule_id' => $rule->id,
                                'order_id' => $order->id,
                                'order_item_id' => null,
                                'calculation_basis_amount' => $orderAmount,
                                'commission_amount' => $commission,
                                'type' => CommissionRecordTypeEnum::STANDARD,
                                'reversal_source_id' => null,
                                'status' => CommissionRecordStatusEnum::PENDING,
                            ]);
                        }
                    }
                } elseif ($rule->basis_type === CommissionBasisTypeEnum::FIXED_PER_TRANSACTION) {
                    if ($orderAmount >= $rule->min_sales_threshold && $rule->rate_amount > 0) {
                        $createdRecords[] = CommissionRecord::create([
                            'tenant_id' => $tenantId,
                            'store_id' => $storeId,
                            'employee_id' => $employeeId,
                            'commission_rule_id' => $rule->id,
                            'order_id' => $order->id,
                            'order_item_id' => null,
                            'calculation_basis_amount' => $orderAmount,
                            'commission_amount' => $rule->rate_amount,
                            'type' => CommissionRecordTypeEnum::STANDARD,
                            'reversal_source_id' => null,
                            'status' => CommissionRecordStatusEnum::PENDING,
                        ]);
                    }
                } elseif ($rule->basis_type === CommissionBasisTypeEnum::PRODUCT_SPECIFIC && $rule->product_id !== null) {
                    /** @var OrderItem $item */
                    foreach ($order->orderItems as $item) {
                        if ((int) $item->product_id === (int) $rule->product_id) {
                            $qty = (float) ($item->qty ?? $item->quantity ?? 1);
                            $itemSubtotal = (float) ($item->order_subtotal ?? $item->subtotal ?? (($item->order_price ?? $item->price ?? 0) * $qty));

                            $commission = 0.0;
                            if ($rule->rate_amount > 0) {
                                $commission = round($qty * $rule->rate_amount, 2);
                            } elseif ($rule->rate_percent > 0) {
                                $commission = round($itemSubtotal * ($rule->rate_percent / 100), 2);
                            }

                            if ($commission > 0) {
                                $createdRecords[] = CommissionRecord::create([
                                    'tenant_id' => $tenantId,
                                    'store_id' => $storeId,
                                    'employee_id' => $employeeId,
                                    'commission_rule_id' => $rule->id,
                                    'order_id' => $order->id,
                                    'order_item_id' => $item->id,
                                    'calculation_basis_amount' => $itemSubtotal,
                                    'commission_amount' => $commission,
                                    'type' => CommissionRecordTypeEnum::STANDARD,
                                    'reversal_source_id' => null,
                                    'status' => CommissionRecordStatusEnum::PENDING,
                                ]);
                            }
                        }
                    }
                }
            }
        });

        return $createdRecords;
    }

    /**
     * Protokol pembalikan komisi otomatis saat terjadi retur atau pembatalan pesanan (REVERSAL).
     *
     * @return array<int, CommissionRecord>
     */
    public function processReturnReversal(
        int $orderId,
        ?int $orderItemId = null,
        ?float $returnedSubtotal = null
    ): array {
        return DB::transaction(function () use ($orderId, $orderItemId, $returnedSubtotal) {
            $query = CommissionRecord::where('order_id', $orderId)
                ->where('type', CommissionRecordTypeEnum::STANDARD)
                ->where('status', '!=', CommissionRecordStatusEnum::VOIDED);

            if ($orderItemId !== null) {
                $query->where('order_item_id', $orderItemId);
            }

            $standards = $query->lockForUpdate()->get();
            $reversals = [];

            foreach ($standards as $standard) {
                // Periksa apakah sudah ada pembalikan untuk record ini
                $existingReversalSum = (float) CommissionRecord::where('reversal_source_id', $standard->id)
                    ->where('type', CommissionRecordTypeEnum::REVERSAL)
                    ->sum('commission_amount');

                // Jika sudah dibalik penuh (selisih <= 0), lewati
                if (abs($existingReversalSum) >= $standard->commission_amount) {
                    continue;
                }

                $reversalAmount = 0.0;
                if ($returnedSubtotal !== null && (float) $standard->calculation_basis_amount > 0) {
                    $ratio = min(1.0, $returnedSubtotal / (float) $standard->calculation_basis_amount);
                    $reversalAmount = -round((float) $standard->commission_amount * $ratio, 2);
                } else {
                    $reversalAmount = -abs((float) $standard->commission_amount);
                }

                $reversals[] = CommissionRecord::create([
                    'tenant_id' => $standard->tenant_id,
                    'store_id' => $standard->store_id,
                    'employee_id' => $standard->employee_id,
                    'commission_rule_id' => $standard->commission_rule_id,
                    'order_id' => $standard->order_id,
                    'order_item_id' => $standard->order_item_id,
                    'calculation_basis_amount' => $returnedSubtotal ?? $standard->calculation_basis_amount,
                    'commission_amount' => $reversalAmount,
                    'type' => CommissionRecordTypeEnum::REVERSAL,
                    'reversal_source_id' => $standard->id,
                    'status' => CommissionRecordStatusEnum::PENDING,
                ]);
            }

            return $reversals;
        });
    }

    /**
     * Hitung total bersih komisi karyawan dalam periode tertentu (Positif - Pembalikan Reversal).
     */
    public function calculateEmployeePeriodCommission(
        int $tenantId,
        int $employeeId,
        CarbonInterface $startDate,
        CarbonInterface $endDate
    ): float {
        return (float) CommissionRecord::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->whereIn('status', [
                CommissionRecordStatusEnum::PENDING,
                CommissionRecordStatusEnum::APPROVED,
                CommissionRecordStatusEnum::EARNED,
                CommissionRecordStatusEnum::PROCESSED_IN_PAYROLL,
            ])
            ->whereBetween('created_at', [$startDate->toDateTimeString(), $endDate->toDateTimeString()])
            ->sum('commission_amount');
    }
}
