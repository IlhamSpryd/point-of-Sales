<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\ProductLot;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class LotFefoAllocationService
{
    /**
     * Catat penerimaan batch persediaan baru dengan tanggal kadaluwarsa.
     */
    public function recordLotReceipt(
        int $tenantId,
        int $storeId,
        int $productId,
        string $lotNumber,
        string $expiryDate,
        string|float|int $quantity,
        ?float $unitCost = null,
        ?int $variantId = null,
        ?int $warehouseId = null,
        ?int $companyId = null,
        ?string $notes = null
    ): ProductLot {
        $qtyStr = (string) $quantity;
        if (bccomp($qtyStr, '0', 4) <= 0) {
            throw new InvalidArgumentException('Kuantitas batch harus lebih besar dari nol.');
        }

        $expiry = Carbon::parse($expiryDate)->toDateString();

        return ProductLot::create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'store_id' => $storeId,
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'lot_number' => trim($lotNumber),
            'expiry_date' => $expiry,
            'received_date' => now()->toDateString(),
            'initial_quantity' => $qtyStr,
            'current_quantity' => $qtyStr,
            'unit_cost' => $unitCost !== null ? (string) $unitCost : null,
            'status' => 'active',
            'notes' => $notes,
        ]);
    }

    /**
     * Alokasi pengeluaran stok persediaan berdasarkan prinsip FEFO (First Expired, First Out).
     *
     * @return array{total_allocated: numeric-string, is_fully_allocated: bool, allocations: list<array{lot_id: int, lot_number: string, expiry_date: string, allocated_qty: numeric-string, remaining_lot_qty: numeric-string}>}
     */
    public function allocateFefo(
        int $tenantId,
        int $storeId,
        int $productId,
        string|float|int $quantityNeeded,
        ?int $variantId = null
    ): array {
        $neededStr = (string) $quantityNeeded;
        if (bccomp($neededStr, '0', 4) <= 0) {
            throw new InvalidArgumentException('Kuantitas yang dibutuhkan harus lebih besar dari nol.');
        }

        $query = ProductLot::where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('product_id', $productId)
            ->where('status', 'active')
            ->where('current_quantity', '>', 0)
            ->orderBy('expiry_date', 'asc') // Urutan FEFO: tanggal kadaluwarsa terdekat keluar lebih awal
            ->orderBy('id', 'asc');

        if ($variantId !== null) {
            $query->where('product_variant_id', $variantId);
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, ProductLot> $lots */
        $lots = $query->lockForUpdate()->get();

        $remainingToAllocate = $neededStr;
        $allocations = [];
        $totalAllocated = '0.0000';

        foreach ($lots as $lot) {
            if (bccomp($remainingToAllocate, '0', 4) <= 0) {
                break;
            }

            $currentLotQty = (string) $lot->current_quantity;
            if (bccomp($currentLotQty, '0', 4) <= 0) {
                continue;
            }

            // Alokasikan sebanyak yang tersedia atau sebanyak yang dibutuhkan
            if (bccomp($currentLotQty, $remainingToAllocate, 4) >= 0) {
                $allocatedFromThisLot = $remainingToAllocate;
                $remainingToAllocate = '0.0000';
            } else {
                $allocatedFromThisLot = $currentLotQty;
                $remainingToAllocate = bcsub($remainingToAllocate, $currentLotQty, 4);
            }

            $totalAllocated = bcadd($totalAllocated, $allocatedFromThisLot, 4);
            $newRemainingLotQty = bcsub($currentLotQty, $allocatedFromThisLot, 4);

            $allocations[] = [
                'lot_id' => $lot->id,
                'lot_number' => $lot->lot_number,
                'expiry_date' => $lot->expiry_date->toDateString(),
                'allocated_qty' => $allocatedFromThisLot,
                'remaining_lot_qty' => $newRemainingLotQty,
            ];
        }

        return [
            'total_allocated' => $totalAllocated,
            'is_fully_allocated' => bccomp($remainingToAllocate, '0', 4) === 0,
            'allocations' => $allocations,
        ];
    }

    /**
     * Eksekusi pemotongan saldo lot berdasarkan hasil alokasi FEFO secara transaksional.
     *
     * @param list<array{lot_id: int, allocated_qty: numeric-string}> $allocations
     */
    public function deductAllocatedLots(array $allocations): void
    {
        DB::transaction(function () use ($allocations) {
            foreach ($allocations as $alloc) {
                $lot = ProductLot::lockForUpdate()->findOrFail($alloc['lot_id']);
                $allocated = $alloc['allocated_qty'];

                $newBalance = bcsub((string) $lot->current_quantity, $allocated, 4);
                if (bccomp($newBalance, '0', 4) < 0) {
                    throw new RuntimeException("Saldo lot {$lot->lot_number} tidak mencukupi untuk pemotongan.");
                }

                $lot->current_quantity = $newBalance;
                if (bccomp($newBalance, '0', 4) === 0) {
                    $lot->status = 'depleted';
                }
                $lot->save();
            }
        });
    }

    /**
     * Dapatkan daftar batch yang mendekati tanggal kadaluwarsa (peringatan kasir & markdown).
     *
     * @return Collection<int, ProductLot>
     */
    public function getExpiringLots(int $tenantId, int $storeId, int $daysThreshold = 30): Collection
    {
        $cutoffDate = now()->addDays($daysThreshold)->toDateString();

        return ProductLot::where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('status', 'active')
            ->where('current_quantity', '>', 0)
            ->where('expiry_date', '<=', $cutoffDate)
            ->orderBy('expiry_date', 'asc')
            ->with(['product', 'variant'])
            ->get();
    }
}
