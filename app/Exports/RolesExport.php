<?php

namespace App\Exports;

use App\Models\Role;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
class RolesExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    public function __construct(protected \Illuminate\Database\Eloquent\Builder $query) {}

    public function collection(): \Illuminate\Support\Collection
    {
        return $this->query->withCount('users')->get();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Role Name',
            'Total Users',
            'Created At',
        ];
    }

    public function map($role): array
    {
        return [
            $role->id,
            $role->name,
            $role->users_count,
            $role->created_at ? $role->created_at->format('d/m/Y H:i') : '-',
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
