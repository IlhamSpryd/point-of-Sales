<?php

declare(strict_types=1);

namespace App\Exports;

use App\Services\Context\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProductsExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    public function __construct(protected Builder $query) {}

    public function collection(): Collection
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
        ])->get();
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

    /**
     * Mencegah CSV Formula Injection (Excel / LibreOffice injection).
     */
    private function sanitizeFormula(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/^[\=\+\-\@\t\r]/', $value)) {
            return "'".$value;
        }

        return $value;
    }

    public function map($product): array
    {
        $categoryName = $product->category ? $product->category->category_name : '-';
        $brandName = $product->brand ? $product->brand->name : '-';
        $uomName = $product->baseUom ? $product->baseUom->name : '-';
        $taxName = $product->taxClass ? $product->taxClass->name : '-';

        return [
            $product->id,
            $this->sanitizeFormula($product->product_code ?? $product->sku ?? null),
            $this->sanitizeFormula($product->product_name),
            $this->sanitizeFormula($product->barcode),
            $this->sanitizeFormula($categoryName),
            $this->sanitizeFormula($brandName),
            $this->sanitizeFormula($uomName),
            $product->product_type ?? 'standard',
            $product->product_price,
            $product->costing_method ?? 'standard',
            $product->standard_cost ?? 0.00,
            $this->sanitizeFormula($taxName),
            $product->is_tax_inclusive ? 'Yes' : 'No',
            $product->track_inventory ? 'Yes' : 'No',
            $product->stock,
            ucfirst((string) ($product->status ?? ($product->is_active ? 'Active' : 'Inactive'))),
        ];
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
}
