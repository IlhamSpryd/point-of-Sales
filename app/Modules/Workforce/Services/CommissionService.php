<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Services;

use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use App\Modules\Workforce\Enums\CommissionBasisTypeEnum;
use App\Modules\Workforce\Enums\CommissionRecordStatusEnum;
use App\Modules\Workforce\Enums\CommissionRecordTypeEnum;
use App\Modules\Workforce\Models\CommissionRecord;
use App\Modules\Workforce\Models\CommissionRule;
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
        $statusVal = $order->order_status->value;
        if (! in_array(strtolower($statusVal), ['completed', 'paid'], true)) {
            return [];
        }

        $tenantId = (int) $order->tenant_id;
        $storeId = (int) $order->store_id;
        $orderDate = $order->order_date ? Carbon::parse($order->order_date)->toDateString() : now()->toDateString();
        // DPP Murni (Dasar Pengenaan Pajak): Subtotal dikurangi Diskon (keluarkan PB1/PPN & Service Charge)
        $orderAmount = max(0.00, (float) ($order->subtotal_amount ?? 0.00) - (float) ($order->discount_amount ?? 0.00));

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
                $basisTypeVal = $rule->basis_type->value;
                $ratePercent = (float) $rule->rate_percent;
                $rateAmount = (float) $rule->rate_amount;
                $minSales = (float) $rule->min_sales_threshold;

                if ($basisTypeVal === CommissionBasisTypeEnum::PERCENTAGE_OF_SALES->value) {
                    if ($orderAmount >= $minSales && $ratePercent > 0) {
                        $commission = round($orderAmount * ($ratePercent / 100), 2);
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
                } elseif ($basisTypeVal === CommissionBasisTypeEnum::FIXED_PER_TRANSACTION->value) {
                    if ($orderAmount >= $minSales && $rateAmount > 0) {
                        $createdRecords[] = CommissionRecord::create([
                            'tenant_id' => $tenantId,
                            'store_id' => $storeId,
                            'employee_id' => $employeeId,
                            'commission_rule_id' => $rule->id,
                            'order_id' => $order->id,
                            'order_item_id' => null,
                            'calculation_basis_amount' => $orderAmount,
                            'commission_amount' => $rateAmount,
                            'type' => CommissionRecordTypeEnum::STANDARD,
                            'reversal_source_id' => null,
                            'status' => CommissionRecordStatusEnum::PENDING,
                        ]);
                    }
                } elseif ($basisTypeVal === CommissionBasisTypeEnum::PRODUCT_SPECIFIC->value && $rule->product_id !== null) {
                    /** @var OrderItem $item */
                    foreach ($order->orderItems as $item) {
                        if ((int) $item->product_id === (int) $rule->product_id) {
                            $qty = (float) ($item->qty ?? $item->quantity ?? 1);
                            $rawSubtotal = (float) ($item->order_subtotal ?? $item->subtotal ?? (($item->order_price ?? $item->price ?? 0) * $qty));
                            $itemSubtotal = max(0.00, $rawSubtotal - (float) ($item->discount_amount ?? 0.00));

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
     * Protokol pembalikan komisi otomatis saat terjadi retur atau pembatalan pesanan (REVERSAL / CLAWBACK).
     *
     * @param  array<int, mixed>|null  $returnItemsWithDisposition
     * @return array<int, CommissionRecord>
     */
    public function clawbackCommission(
        int $orderId,
        ?array $returnItemsWithDisposition = null,
        ?float $returnedAmount = null,
        string $reason = 'ORDER_VOID'
    ): array {
        return DB::transaction(function () use ($orderId, $returnedAmount, $reason) {
            $records = CommissionRecord::where('order_id', $orderId)
                ->where('type', CommissionRecordTypeEnum::STANDARD)
                ->whereNotIn('status', [CommissionRecordStatusEnum::VOIDED, CommissionRecordStatusEnum::REVERSED])
                ->lockForUpdate()
                ->get();

            $processed = [];

            foreach ($records as $record) {
                // Skenario 1: Belum dibayarkan (PENDING / APPROVED / EARNED)
                if ($record->status !== CommissionRecordStatusEnum::PAID && $record->status !== CommissionRecordStatusEnum::PROCESSED_IN_PAYROLL) {
                    if ($reason === 'ORDER_VOID') {
                        if ($returnedAmount !== null && (float) $record->calculation_basis_amount > 0 && $returnedAmount < (float) $record->calculation_basis_amount) {
                            $reductionRatio = min(1.0, $returnedAmount / (float) $record->calculation_basis_amount);
                            $record->commission_amount = number_format(round((float) $record->commission_amount * (1.0 - $reductionRatio), 2), 2, '.', '');
                            $record->save();
                        } else {
                            $record->status = CommissionRecordStatusEnum::VOIDED;
                            $record->save();
                        }
                        $processed[] = $record;
                    } else {
                        // ORDER_RETURN: Buat record REVERSAL negatif
                        $reversalAmount = 0.0;
                        if ($returnedAmount !== null && (float) $record->calculation_basis_amount > 0) {
                            $ratio = min(1.0, $returnedAmount / (float) $record->calculation_basis_amount);
                            $reversalAmount = -round((float) $record->commission_amount * $ratio, 2);
                        } else {
                            $reversalAmount = -abs((float) $record->commission_amount);
                        }

                        $processed[] = CommissionRecord::create([
                            'tenant_id' => $record->tenant_id,
                            'store_id' => $record->store_id,
                            'employee_id' => $record->employee_id,
                            'commission_rule_id' => $record->commission_rule_id,
                            'order_id' => $record->order_id,
                            'order_item_id' => $record->order_item_id,
                            'calculation_basis_amount' => $returnedAmount ?? $record->calculation_basis_amount,
                            'commission_amount' => $reversalAmount,
                            'type' => CommissionRecordTypeEnum::REVERSAL,
                            'reversal_source_id' => $record->id,
                            'status' => CommissionRecordStatusEnum::PENDING,
                        ]);
                    }
                } else {
                    // Skenario 2: Sudah PAID di batch tertutup (Koreksi #5)
                    // Terbitkan record CLAWBACK bernilai negatif untuk memotong periode payroll berikutnya tanpa mengubah buku historis!
                    $clawbackAmount = (float) $record->commission_amount;
                    if ($returnedAmount !== null && (float) $record->calculation_basis_amount > 0 && $returnedAmount < (float) $record->calculation_basis_amount) {
                        $ratio = min(1.0, $returnedAmount / (float) $record->calculation_basis_amount);
                        $clawbackAmount = round((float) $record->commission_amount * $ratio, 2);
                    }

                    if ($clawbackAmount > 0) {
                        $clawbackRecord = CommissionRecord::create([
                            'tenant_id' => $record->tenant_id,
                            'store_id' => $record->store_id,
                            'employee_id' => $record->employee_id,
                            'commission_rule_id' => $record->commission_rule_id,
                            'order_id' => $record->order_id,
                            'order_item_id' => $record->order_item_id,
                            'calculation_basis_amount' => $returnedAmount ?? $record->calculation_basis_amount,
                            'commission_amount' => -abs($clawbackAmount),
                            'type' => CommissionRecordTypeEnum::CLAWBACK,
                            'reversal_source_id' => $record->id,
                            'status' => CommissionRecordStatusEnum::APPROVED,
                        ]);
                        $processed[] = $clawbackRecord;
                    }
                }
            }

            return $processed;
        });
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
        return $this->clawbackCommission($orderId, null, $returnedSubtotal, 'ORDER_RETURN');
    }

    /**
     * Hitung total bersih komisi karyawan dalam periode tertentu (Positif - Pembalikan Reversal / Clawback).
     */
    public function calculateEmployeePeriodCommission(
        int $tenantId,
        int $employeeId,
        CarbonInterface $startDate,
        CarbonInterface $endDate
    ): float {
        return (float) CommissionRecord::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->whereNull('payroll_batch_id')
            ->whereIn('status', [
                CommissionRecordStatusEnum::PENDING,
                CommissionRecordStatusEnum::APPROVED,
                CommissionRecordStatusEnum::EARNED,
                CommissionRecordStatusEnum::PROCESSED_IN_PAYROLL,
                CommissionRecordStatusEnum::CLAWBACK,
            ])
            ->whereBetween('created_at', [$startDate->toDateTimeString(), $endDate->toDateTimeString()])
            ->sum('commission_amount');
    }
}
