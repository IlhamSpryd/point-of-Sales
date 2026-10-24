<?php

declare(strict_types=1);

namespace App\Exports;

use App\Enums\StockMovementType;
use App\Models\IngredientStockMovement;
use App\Models\StockMovement;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export Kartu Stok (Stock Card / Inventory Ledger).
 */
class InventoryLedgerExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    public function __construct(
        protected Carbon $startDate,
        protected Carbon $endDate,
        protected ?int $storeId = null,
        protected string $itemType = 'all', // 'all', 'product', 'ingredient'
        protected ?int $itemId = null
    ) {}

    public function collection(): Collection
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $targetStoreId = $this->storeId ?? app(TenantContext::class)->getStoreId();

        $rows = collect();

        // 1. Ambil mutasi produk jika itemType 'all' atau 'product'
        if ($this->itemType === 'all' || $this->itemType === 'product') {
            $productMovementsQuery = StockMovement::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereBetween('created_at', [$this->startDate->startOfDay(), $this->endDate->endOfDay()])
                ->with(['product', 'store']);

            if ($targetStoreId !== null) {
                $productMovementsQuery->where('store_id', $targetStoreId);
            }

            if ($this->itemId !== null && $this->itemType === 'product') {
                $productMovementsQuery->where('product_id', $this->itemId);
            }

            $productMovements = $productMovementsQuery->get();

            foreach ($productMovements as $pm) {
                $typeVal = $pm->type instanceof \BackedEnum ? $pm->type->value : (string) $pm->type;
                $isPositive = $pm->type instanceof StockMovementType ? $pm->type->isPositive() : true;
                $rawQty = (string) $pm->quantity;
                $signedQty = $isPositive ? $rawQty : '-'.abs((float) $rawQty);
                $unitCost = (string) ($pm->unit_cost ?? '0.0000');
                $totalCost = bcmul((string) abs((float) $rawQty), $unitCost, 2);

                $rows->push([
                    'created_at' => $pm->created_at?->format('Y-m-d H:i:s'),
                    'store_name' => $pm->store->name ?? "Cabang #{$pm->store_id}",
                    'item_type' => 'Produk',
                    'item_name' => $pm->product->product_name ?? "Produk #{$pm->product_id}",
                    'item_code' => $pm->product->product_code ?? '-',
                    'movement_type' => strtoupper($typeVal),
                    'quantity' => $signedQty,
                    'unit_cost' => number_format((float) $unitCost, 2, '.', ''),
                    'total_cost' => number_format((float) $totalCost, 2, '.', ''),
                    'reason' => $pm->reason ?? '-',
                    'order_id' => $pm->order_id ? "Order #{$pm->order_id}" : '-',
                    '_raw_time' => $pm->created_at->timestamp ?? 0,
                ]);
            }
        }

        // 2. Ambil mutasi bahan baku jika itemType 'all' atau 'ingredient'
        if ($this->itemType === 'all' || $this->itemType === 'ingredient') {
            $ingredientMovementsQuery = IngredientStockMovement::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereBetween('created_at', [$this->startDate->startOfDay(), $this->endDate->endOfDay()])
                ->with(['ingredient', 'store']);

            if ($targetStoreId !== null) {
                $ingredientMovementsQuery->where('store_id', $targetStoreId);
            }

            if ($this->itemId !== null && $this->itemType === 'ingredient') {
                $ingredientMovementsQuery->where('ingredient_id', $this->itemId);
            }

            $ingredientMovements = $ingredientMovementsQuery->get();

            foreach ($ingredientMovements as $im) {
                $typeVal = $im->type->value;
                $isPositive = $im->type->isPositive();
                $rawQty = (string) $im->quantity;
                $signedQty = $isPositive ? $rawQty : '-'.abs((float) $rawQty);
                $unitCost = (string) ($im->unit_cost ?? '0.0000');
                $totalCost = bcmul((string) abs((float) $rawQty), $unitCost, 2);

                $rows->push([
                    'created_at' => $im->created_at?->format('Y-m-d H:i:s'),
                    'store_name' => $im->store->name ?? "Cabang #{$im->store_id}",
                    'item_type' => 'Bahan Baku',
                    'item_name' => $im->ingredient->name ?? "Bahan #{$im->ingredient_id}",
                    'item_code' => $im->ingredient->ingredient_code ?? '-',
                    'movement_type' => strtoupper($typeVal),
                    'quantity' => $signedQty,
                    'unit_cost' => number_format((float) $unitCost, 2, '.', ''),
                    'total_cost' => number_format((float) $totalCost, 2, '.', ''),
                    'reason' => $im->reason ?? '-',
                    'order_id' => $im->order_id ? "Order #{$im->order_id}" : '-',
                    '_raw_time' => $im->created_at->timestamp ?? 0,
                ]);
            }
        }

        // Urutkan kronologis
        return $rows->sortBy('_raw_time')->values();
    }

    public function headings(): array
    {
        return [
            'Waktu',
            'Cabang',
            'Tipe Item',
            'Nama Item',
            'Kode Item',
            'Tipe Mutasi',
            'Perubahan Kuantitas',
            'Biaya Satuan (Rp)',
            'Total Nilai (Rp)',
            'Alasan / Referensi',
            'Rujukan Order',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function map($row): array
    {
        return [
            $row['created_at'],
            $row['store_name'],
            $row['item_type'],
            $row['item_name'],
            $row['item_code'],
            $row['movement_type'],
            $row['quantity'],
            $row['unit_cost'],
            $row['total_cost'],
            $row['reason'],
            $row['order_id'],
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastRow = $sheet->getHighestRow();

        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1F2937'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ],
            "A1:K{$lastRow}" => [
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'E5E7EB'],
                    ],
                ],
            ],
        ];
    }
}
