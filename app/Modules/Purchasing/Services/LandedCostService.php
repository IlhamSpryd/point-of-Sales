<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Core\Context\TenantContext;
use App\Enums\CostAllocationMethodEnum;
use App\Enums\PurchaseCostCategoryEnum;
use App\Enums\PurchaseCostStatusEnum;
use App\Models\User;
use App\Modules\Purchasing\Models\AccountingPayableOutbox;
use App\Modules\Purchasing\Models\GoodsReceivedNoteLine;
use App\Modules\Purchasing\Models\PurchaseAdditionalCost;
use App\Modules\Purchasing\Models\PurchaseCostAllocation;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * LandedCostService
 *
 * Layanan orkestrasi domain untuk pencatatan biaya tambahan pengadaan barang
 * dan alokasi proporsional (Landed Cost) dengan penanganan selisih pembulatan sen residual eksak.
 *
 * Menegakkan:
 * - Algoritma alokasi proporsional (BY_VALUE, BY_QUANTITY, MANUAL_EXACT)
 * - Invarian konservasi sen residual: sum(C_i) === C_total (Section 12.2)
 * - Pembaruan allocated_landed_cost_per_unit dan effective_unit_cost pada baris GRN
 * - Publikasi kontrak integrasi ke AccountingPayableOutbox (Batas Modul 7 - AT-17)
 */
class LandedCostService
{
    public function __construct(
        protected DocumentSequenceService $sequenceService
    ) {}

    /**
     * Memeriksa otorisasi pengguna untuk mengelola landed cost.
     */
    private function authorizeUser(User $actor): void
    {
        $hasPermission = $actor->hasPermission('purchasing.landed_cost.manage')
            || in_array($actor->role?->name, ['Owner', 'Manager', 'Accounting', 'Admin'], true)
            || (bool) $actor->role?->is_admin;

        if (! $hasPermission) {
            throw new DomainException('Pengguna tidak memiliki izin untuk mengelola biaya pengadaan tambahan (purchasing.landed_cost.manage).');
        }
    }

    /**
     * Mencatat biaya tambahan pengadaan baru (status draft).
     *
     * @param  array{
     *     store_id: int,
     *     goods_received_note_id?: int|null,
     *     purchase_order_id?: int|null,
     *     category: PurchaseCostCategoryEnum|string,
     *     description: string,
     *     amount: string|float,
     *     currency_code?: string,
     *     exchange_rate?: string|float,
     *     allocation_method?: CostAllocationMethodEnum|string,
     *     vendor_name?: string|null,
     *     invoice_reference?: string|null,
     *     notes?: string|null,
     *     idempotency_key?: string|null
     * }  $data
     *
     * @throws ValidationException
     */
    public function recordAdditionalCost(array $data, User $actor): PurchaseAdditionalCost
    {
        $this->authorizeUser($actor);

        $tenantId = app(TenantContext::class)->getTenantId();
        if ($tenantId === null) {
            throw new DomainException('TenantContext tidak valid.');
        }

        $amount = (string) $data['amount'];
        if (bccomp($amount, '0.00', 2) <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Nominal biaya tambahan harus lebih besar dari 0.'],
            ]);
        }

        $storeId = (int) $data['store_id'];

        return DB::transaction(function () use ($data, $actor, $tenantId, $storeId, $amount) {
            $costCode = $this->sequenceService->nextNumber(
                tenantId: $tenantId,
                docType: DocumentSequenceService::TYPE_LANDED_COST,
                storeId: $storeId
            );

            $category = $data['category'] instanceof PurchaseCostCategoryEnum
                ? $data['category']
                : PurchaseCostCategoryEnum::from((string) $data['category']);

            $allocationMethod = isset($data['allocation_method'])
                ? ($data['allocation_method'] instanceof CostAllocationMethodEnum
                    ? $data['allocation_method']
                    : CostAllocationMethodEnum::from((string) $data['allocation_method']))
                : CostAllocationMethodEnum::ByValue;

            $cost = new PurchaseAdditionalCost;
            $cost->tenant_id = $tenantId;
            $cost->store_id = $storeId;
            $cost->goods_received_note_id = $data['goods_received_note_id'] ?? null;
            $cost->purchase_order_id = $data['purchase_order_id'] ?? null;
            $cost->cost_code = $costCode;
            $cost->category = $category;
            $cost->description = $data['description'];
            $cost->amount = $amount;
            $cost->currency_code = $data['currency_code'] ?? 'IDR';
            $cost->exchange_rate = (string) ($data['exchange_rate'] ?? '1.000000');
            $cost->allocation_method = $allocationMethod;
            $cost->vendor_name = $data['vendor_name'] ?? null;
            $cost->invoice_reference = $data['invoice_reference'] ?? null;
            $cost->status = PurchaseCostStatusEnum::Draft;
            $cost->created_by = $actor->id;
            $cost->idempotency_key = $data['idempotency_key'] ?? null;
            $cost->notes = $data['notes'] ?? null;
            $cost->save();

            return $cost;
        });
    }

    /**
     * Mengeksekusi alokasi biaya tambahan ke baris GRN:
     * Menjamin invarian: sum(C_i) === C_total dengan penyesuaian sen residual pada baris terakhir.
     *
     * @param  int[]  $grnLineIds
     * @param  array<int, string|float>  $manualAmounts  [grn_line_id => amount] hanya untuk MANUAL_EXACT
     *
     * @throws ValidationException
     */
    public function allocateLandedCost(
        int $costId,
        array $grnLineIds,
        CostAllocationMethodEnum $method,
        User $actor,
        array $manualAmounts = []
    ): PurchaseAdditionalCost {
        $this->authorizeUser($actor);

        $tenantId = app(TenantContext::class)->getTenantId();
        if ($tenantId === null) {
            throw new DomainException('TenantContext tidak valid.');
        }

        if (empty($grnLineIds)) {
            throw ValidationException::withMessages([
                'grn_line_ids' => ['Minimal satu baris GRN wajib dipilih untuk alokasi landed cost.'],
            ]);
        }

        return DB::transaction(function () use ($costId, $grnLineIds, $method, $manualAmounts, $actor, $tenantId) {
            /** @var PurchaseAdditionalCost $lockedCost */
            $lockedCost = PurchaseAdditionalCost::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($costId);

            if ($lockedCost->status !== PurchaseCostStatusEnum::Draft) {
                throw ValidationException::withMessages([
                    'status' => ["Hanya biaya tambahan berstatus draft yang dapat dialokasikan. Status saat ini: {$lockedCost->status->value}."],
                ]);
            }

            // Kunci baris GRN secara deterministik berurutan
            $lines = GoodsReceivedNoteLine::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $grnLineIds)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            if ($lines->count() !== count($grnLineIds)) {
                throw ValidationException::withMessages([
                    'grn_line_ids' => ['Satu atau lebih baris GRN tidak ditemukan atau bukan milik tenant ini.'],
                ]);
            }

            $totalCost = (string) $lockedCost->amount;
            $allocationsData = [];

            if ($method === CostAllocationMethodEnum::ManualExact) {
                // Metode Manual Exact
                $manualSum = '0.00';
                foreach ($lines as $line) {
                    if (! isset($manualAmounts[$line->id])) {
                        throw ValidationException::withMessages([
                            'manual_amounts' => ["Nominal alokasi manual untuk baris GRN #{$line->id} wajib diisi."],
                        ]);
                    }

                    $allocAmt = (string) $manualAmounts[$line->id];
                    if (bccomp($allocAmt, '0.00', 2) < 0) {
                        throw ValidationException::withMessages([
                            'manual_amounts' => ["Nominal alokasi baris GRN #{$line->id} tidak boleh negatif."],
                        ]);
                    }

                    $manualSum = bcadd($manualSum, $allocAmt, 2);
                    $allocationsData[$line->id] = [
                        'amount' => $allocAmt,
                        'weight_ratio' => bcdiv($allocAmt, $totalCost, 8),
                        'is_residual' => false,
                    ];
                }

                if (bccomp($manualSum, $totalCost, 2) !== 0) {
                    throw ValidationException::withMessages([
                        'manual_amounts' => [
                            "Total alokasi manual ({$manualSum}) tidak sama persis dengan total biaya ({$totalCost}). Selisih: ".bcsub($manualSum, $totalCost, 2),
                        ],
                    ]);
                }
            } else {
                // Metode Proporsional: BY_VALUE atau BY_QUANTITY
                // Hitung total bobot (total weight)
                $weights = [];
                $totalWeight = '0.0000';

                foreach ($lines as $line) {
                    if ($method === CostAllocationMethodEnum::ByQuantity) {
                        // Bobot = base_accepted_qty
                        $w = (string) $line->base_accepted_qty;
                    } else {
                        // Bobot = base_accepted_qty * unit_cost_snapshot (nilai bersih barang diterima)
                        $w = bcmul((string) $line->base_accepted_qty, (string) $line->unit_cost_snapshot, 4);
                    }

                    if (bccomp($w, '0.0000', 4) <= 0) {
                        $w = '0.0001'; // Bobot minimal agar tidak devide by zero
                    }

                    $weights[$line->id] = $w;
                    $totalWeight = bcadd($totalWeight, $w, 4);
                }

                if (bccomp($totalWeight, '0.0000', 4) <= 0) {
                    throw ValidationException::withMessages([
                        'allocation' => ['Total bobot baris GRN untuk alokasi adalah 0.'],
                    ]);
                }

                // Kalkulasi alokasi dengan aturan sen residual (Section 12.2)
                $allocatedSum = '0.00';
                $lineCount = $lines->count();
                $index = 0;

                foreach ($lines as $line) {
                    $index++;
                    $w = $weights[$line->id];
                    $ratio = bcdiv($w, $totalWeight, 8);

                    if ($index < $lineCount) {
                        // Baris 1 s/d N-1: C_i = round(totalCost * (w_i / totalWeight), 2)
                        $lineAlloc = bcmul($totalCost, $ratio, 2);
                        $allocatedSum = bcadd($allocatedSum, $lineAlloc, 2);

                        $allocationsData[$line->id] = [
                            'amount' => $lineAlloc,
                            'weight_ratio' => $ratio,
                            'is_residual' => false,
                        ];
                    } else {
                        // Baris terakhir N: C_N = totalCost - sum(C_1 ... C_N-1)
                        // Invarian: sum(C_i) === totalCost down to 1 cent
                        $residualAlloc = bcsub($totalCost, $allocatedSum, 2);

                        $allocationsData[$line->id] = [
                            'amount' => $residualAlloc,
                            'weight_ratio' => $ratio,
                            'is_residual' => true,
                        ];
                    }
                }
            }

            // Simpan baris alokasi dan perbarui effective_unit_cost pada baris GRN
            foreach ($lines as $line) {
                $allocInfo = $allocationsData[$line->id];
                $allocAmt = $allocInfo['amount'];

                // Biaya tambahan per unit = round(C_i / base_accepted_qty, 4)
                $baseQty = bccomp((string) $line->base_accepted_qty, '0.0000', 4) > 0
                    ? (string) $line->base_accepted_qty
                    : '1.0000';

                $costPerUnit = bcdiv($allocAmt, $baseQty, 4);

                $allocation = new PurchaseCostAllocation;
                $allocation->tenant_id = $tenantId;
                $allocation->purchase_additional_cost_id = $lockedCost->id;
                $allocation->goods_received_note_line_id = $line->id;
                $allocation->allocated_amount = $allocAmt;
                $allocation->allocated_cost_per_unit = $costPerUnit;
                $allocation->weight_ratio = $allocInfo['weight_ratio'];
                $allocation->is_residual_line = $allocInfo['is_residual'];
                $allocation->is_post_receipt_adjustment = false;
                $allocation->save();

                // Perbarui allocated_landed_cost_per_unit & effective_unit_cost pada GRN line
                $line->allocated_landed_cost_per_unit = bcadd(
                    (string) $line->allocated_landed_cost_per_unit,
                    $costPerUnit,
                    4
                );
                $line->effective_unit_cost = bcadd(
                    (string) $line->unit_cost_snapshot,
                    (string) $line->allocated_landed_cost_per_unit,
                    4
                );
                $line->save();
            }

            // Perbarui status biaya tambahan menjadi allocated
            $lockedCost->status = PurchaseCostStatusEnum::Allocated;
            $lockedCost->allocation_method = $method;
            $lockedCost->allocated_at = now();
            $lockedCost->allocated_by = $actor->id;
            $lockedCost->save();

            // Menerbitkan event integrasi ke accounting_payable_outbox (Batas Modul 7 - AT-17)
            $eventId = Str::uuid()->toString();
            $idempotencyKey = "lc:alloc:{$tenantId}:{$lockedCost->id}";

            AccountingPayableOutbox::create([
                'tenant_id' => $tenantId,
                'event_id' => $eventId,
                'event_type' => 'LANDED_COST_ALLOCATED',
                'idempotency_key' => $idempotencyKey,
                'store_id' => $lockedCost->store_id,
                'source_document_type' => 'purchase_additional_cost',
                'source_document_id' => $lockedCost->id,
                'source_document_number' => $lockedCost->cost_code,
                'payload' => [
                    'schema_version' => '1.0',
                    'event_type' => 'LANDED_COST_ALLOCATED',
                    'event_id' => $eventId,
                    'tenant_id' => $tenantId,
                    'store_id' => $lockedCost->store_id,
                    'cost_code' => $lockedCost->cost_code,
                    'category' => $lockedCost->category->value,
                    'total_amount' => $lockedCost->amount,
                    'allocation_method' => $method->value,
                    'allocated_at' => now()->toIso8601String(),
                    'allocations' => $lines->map(function ($l) use ($allocationsData) {
                        return [
                            'goods_received_note_line_id' => $l->id,
                            'allocated_amount' => $allocationsData[$l->id]['amount'],
                            'is_residual_line' => $allocationsData[$l->id]['is_residual'],
                        ];
                    })->values()->toArray(),
                ],
                'status' => 'pending',
                'retry_count' => 0,
            ]);

            return $lockedCost->fresh(['allocations']);
        });
    }
}
