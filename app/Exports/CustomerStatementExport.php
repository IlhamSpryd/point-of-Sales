<?php

declare(strict_types=1);

namespace App\Exports;

use App\Modules\Customers\Models\Customer;
use App\Modules\Sales\Services\AccountsReceivableService;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CustomerStatementExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles
{
    protected array $statementData;

    protected ?Customer $customer;

    public function __construct(
        protected int $customerId,
        protected Carbon $startDate,
        protected Carbon $endDate
    ) {
        $arService = app(AccountsReceivableService::class);
        $this->statementData = $arService->generateCustomerStatement($customerId, $startDate, $endDate);
        $this->customer = Customer::withoutGlobalScopes()->find($customerId);
    }

    public function headings(): array
    {
        return [
            'ID Entry',
            'Tanggal',
            'Tipe Mutasi',
            'No. Referensi',
            'Keterangan / Catatan',
            'Debet (Tagihan Baru)',
            'Kredit (Pembayaran / CN)',
            'Saldo Berjalan (Piutang)',
        ];
    }

    public function array(): array
    {
        $rows = [];

        // Saldo Awal row
        $rows[] = [
            '-',
            $this->startDate->format('d/m/Y'),
            'SALDO AWAL',
            '-',
            'Saldo piutang sebelum periode ini',
            '0.00',
            '0.00',
            $this->statementData['opening_balance'],
        ];

        foreach ($this->statementData['transactions'] as $tx) {
            $rows[] = [
                $tx['id'],
                Carbon::parse($tx['entry_date'])->format('d/m/Y H:i'),
                $tx['entry_type_label'] ?? $tx['entry_type'],
                $tx['reference_number'] ?? '-',
                $tx['notes'] ?? '-',
                $tx['debit_amount'],
                $tx['credit_amount'],
                $tx['running_balance'],
            ];
        }

        // Saldo Akhir row
        $rows[] = [
            '-',
            $this->endDate->format('d/m/Y'),
            'SALDO AKHIR',
            '-',
            'Total sisa piutang pada akhir periode',
            '0.00',
            '0.00',
            $this->statementData['closing_balance'],
        ];

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF1E293B'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ],
            2 => [
                'font' => ['bold' => true, 'italic' => true],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFF1F5F9'],
                ],
            ],
        ];
    }
}
