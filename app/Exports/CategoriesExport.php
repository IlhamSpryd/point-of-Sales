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

class CategoriesExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
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

        return $this->query->with(['parent' => fn ($q) => $q->where('tenant_id', $tenantId)])
            ->withCount(['products' => function ($q) use ($tenantId, $storeId) {
                $q->where('tenant_id', $tenantId);
                if ($storeId !== null && Schema::hasColumn('products', 'store_id')) {
                    $q->where('store_id', $storeId);
                }
            }])->get();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Category Name',
            'Parent Category',
            'Sort Order',
            'Status',
            'Total Products',
            'Created At',
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

    public function map($category): array
    {
        $parentName = $category->parent ? $category->parent->category_name : '-';

        return [
            $category->id,
            $this->sanitizeFormula($category->category_name),
            $this->sanitizeFormula($parentName),
            $category->sort_order ?? 0,
            $category->is_active ? 'Active' : 'Inactive',
            $category->products_count ?? 0,
            $category->created_at ? $category->created_at->format('d/m/Y H:i') : '-',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastRow = $sheet->getHighestRow();
        $lastCol = $sheet->getHighestColumn();

        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['argb' => 'FFFFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF09090B'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->getStyle("A1:{$lastCol}{$lastRow}")->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FFE4E4E7'],
                ],
            ],
        ]);

        return [];
    }
}
