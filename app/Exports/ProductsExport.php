<?php

declare(strict_types=1);

namespace App\Exports;

use App\Core\Context\TenantContext;
use App\Exports\Concerns\SanitizesSpreadsheetFormulas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Ekspor Data Produk Anti-OOM (EXP-01).
 * Menggunakan FromQuery + WithCustomChunkSize(1000) dan fixed column widths untuk memori flat < 50 MB.
 */
class ProductsExport implements FromQuery, WithColumnWidths, WithCustomChunkSize, WithHeadings, WithMapping, WithStyles
{
    use SanitizesSpreadsheetFormulas;

    public function __construct(protected Builder $query) {}

    public function query(): Builder
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $storeId = app(TenantContext::class)->getStoreId();

        $table = $this->query->getModel()->getTable();
        $this->query->where($table.'.tenant_id', $tenantId);
        if ($storeId !== null && Schema::hasColumn($table, 'store_id')) {
            $this->query->where($table.'.store_id', $storeId);
        }

        return $this->query->with([
            'category' => fn ($q) => $q->where('tenant_id', $tenantId),
            'brand' => fn ($q) => $q->where('tenant_id', $tenantId),
            'baseUom' => fn ($q) => $q->where('tenant_id', $tenantId),
            'taxClass' => fn ($q) => $q->where('tenant_id', $tenantId),
        ]);
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function columnWidths(): array
    {
        return [
            'A' => 10,
            'B' => 18,
            'C' => 30,
            'D' => 18,
            'E' => 20,
            'F' => 18,
            'G' => 12,
            'H' => 15,
            'I' => 15,
            'J' => 15,
            'K' => 18,
            'L' => 15,
            'M' => 14,
            'N' => 15,
            'O' => 12,
            'P' => 12,
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'SKU',
            'Product Name',
            'Barcode',
            'Category',
            'Brand',
            'Base UoM',
            'Product Type',
            'Price (Rp)',
            'Costing Method',
            'Standard Cost (Rp)',
            'Tax Class',
            'Tax Inclusive',
            'Track Inventory',
            'Stock',
            'Status',
        ];
    }

    public function map($product): array
    {
        $categoryName = $product->category ? $product->category->category_name : '-';
        $brandName = $product->brand ? $product->brand->name : '-';
        $uomName = $product->baseUom ? $product->baseUom->name : '-';
        $taxName = $product->taxClass ? $product->taxClass->name : '-';

        return $this->sanitizeRow([
            $product->id,
            $product->product_code ?? $product->sku ?? null,
            $product->product_name,
            $product->barcode,
            $categoryName,
            $brandName,
            $uomName,
            $product->product_type ?? 'standard',
            $product->product_price,
            $product->costing_method ?? 'standard',
            $product->standard_cost ?? 0.00,
            $taxName,
            $product->is_tax_inclusive ? 'Yes' : 'No',
            $product->track_inventory ? 'Yes' : 'No',
            $product->stock,
            ucfirst((string) ($product->status ?? ($product->is_active ? 'Active' : 'Inactive'))),
        ]);
    }

    public function styles(Worksheet $sheet): array
    {
        $lastRow = $sheet->getHighestRow();
        $lastCol = $sheet->getHighestColumn();

        // Style the Header Row
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['argb' => 'FFFFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF09090B'], // Zinc 950 Dark theme
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        // Add borders
        $sheet->getStyle("A1:{$lastCol}{$lastRow}")->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FFE4E4E7'],
                ],
            ],
        ]);

        // Price & Cost formatting
        $sheet->getStyle("I2:I{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("K2:K{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');

        return [];
    }

    /**
     * Generator Streaming CSV murni (Anti-OOM) dengan memori flat < 50 MB.
     */
    public function streamCsv(string $filePath): void
    {
        $handle = fopen($filePath, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Gagal membuka file ekspor CSV untuk penulisan: {$filePath}");
        }

        try {
            fputcsv($handle, $this->headings());
            $q = $this->query();
            $q->chunkById(1000, function ($products) use ($handle) {
                foreach ($products as $product) {
                    fputcsv($handle, $this->map($product));
                }
            });
        } finally {
            fclose($handle);
        }
    }
}
