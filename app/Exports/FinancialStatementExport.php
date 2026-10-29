<?php

declare(strict_types=1);

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class FinancialStatementExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles
{
    public function __construct(
        protected string $reportType,
        protected array $reportData,
        protected ?string $title = null
    ) {}

    public function headings(): array
    {
        return match ($this->reportType) {
            'trial_balance' => [
                'Kode Akun',
                'Nama Akun',
                'Tipe Akun',
                'Saldo Awal Debit',
                'Saldo Awal Kredit',
                'Mutasi Debit',
                'Mutasi Kredit',
                'Saldo Akhir Debit',
                'Saldo Akhir Kredit',
            ],
            'profit_and_loss' => [
                'Kategori',
                'Kode Akun',
                'Nama Akun',
                'Nominal',
            ],
            'balance_sheet' => [
                'Klasifikasi',
                'Kode Akun',
                'Nama Akun',
                'Nominal',
            ],
            'cash_flow' => [
                'Aktivitas / Kategori',
                'Arus Masuk (Inflow)',
                'Arus Keluar (Outflow)',
                'Arus Kas Bersih',
            ],
            default => [
                'Kolom 1',
                'Kolom 2',
                'Kolom 3',
                'Nominal',
            ],
        };
    }

    public function array(): array
    {
        $rows = [];

        if ($this->reportType === 'trial_balance') {
            foreach ($this->reportData['rows'] ?? [] as $r) {
                $rows[] = [
                    $r['account_code'],
                    $r['account_name'],
                    $r['account_type'],
                    $r['opening_debit'],
                    $r['opening_credit'],
                    $r['period_debit'],
                    $r['period_credit'],
                    $r['closing_debit'],
                    $r['closing_credit'],
                ];
            }

            // Total row
            $totals = $this->reportData['totals'] ?? [];
            $rows[] = [
                'TOTAL',
                'Total Neraca Saldo',
                '-',
                $totals['total_opening_debit'] ?? '0.00',
                $totals['total_opening_credit'] ?? '0.00',
                $totals['total_period_debit'] ?? '0.00',
                $totals['total_period_credit'] ?? '0.00',
                $totals['total_closing_debit'] ?? '0.00',
                $totals['total_closing_credit'] ?? '0.00',
            ];
        } elseif ($this->reportType === 'profit_and_loss') {
            $sections = $this->reportData['sections'] ?? [];

            // 1. Pendapatan
            foreach ($sections['revenue']['items'] ?? [] as $item) {
                $rows[] = ['PENDAPATAN', $item['account_code'], $item['account_name'], $item['amount']];
            }
            $rows[] = ['SUBTOTAL', '-', 'Total Pendapatan', $sections['revenue']['total'] ?? '0.00'];

            // 2. HPP
            foreach ($sections['cogs']['items'] ?? [] as $item) {
                $rows[] = ['HPP', $item['account_code'], $item['account_name'], $item['amount']];
            }
            $rows[] = ['SUBTOTAL', '-', 'Total HPP', $sections['cogs']['total'] ?? '0.00'];
            $rows[] = ['LABA KOTOR', '-', 'Laba Kotor', $sections['gross_profit'] ?? '0.00'];

            // 3. Beban Operasional
            foreach ($sections['operating_expenses']['items'] ?? [] as $item) {
                $rows[] = ['BEBAN OPERASIONAL', $item['account_code'], $item['account_name'], $item['amount']];
            }
            $rows[] = ['SUBTOTAL', '-', 'Total Beban Operasional', $sections['operating_expenses']['total'] ?? '0.00'];
            $rows[] = ['LABA OPERASIONAL', '-', 'Laba Operasional', $sections['operating_income'] ?? '0.00'];

            // 4. Lain-lain
            foreach ($sections['other_income']['items'] ?? [] as $item) {
                $rows[] = ['PENDAPATAN LAIN', $item['account_code'], $item['account_name'], $item['amount']];
            }
            foreach ($sections['other_expense']['items'] ?? [] as $item) {
                $rows[] = ['BEBAN LAIN', $item['account_code'], $item['account_name'], $item['amount']];
            }

            $rows[] = ['LABA BERSIH', '-', 'Laba Bersih Tahun Berjalan', $this->reportData['net_income'] ?? '0.00'];
        } elseif ($this->reportType === 'balance_sheet') {
            // Aset Lancar
            foreach ($this->reportData['assets']['current_assets']['items'] ?? [] as $item) {
                $rows[] = ['ASET LANCAR', $item['account_code'], $item['account_name'], $item['amount']];
            }
            $rows[] = ['SUBTOTAL ASET LANCAR', '-', 'Total Aset Lancar', $this->reportData['assets']['current_assets']['total'] ?? '0.00'];

            // Aset Tidak Lancar
            foreach ($this->reportData['assets']['non_current_assets']['items'] ?? [] as $item) {
                $rows[] = ['ASET TIDAK LANCAR', $item['account_code'], $item['account_name'], $item['amount']];
            }
            $rows[] = ['SUBTOTAL ASET TIDAK LANCAR', '-', 'Total Aset Tidak Lancar', $this->reportData['assets']['non_current_assets']['total'] ?? '0.00'];
            $rows[] = ['TOTAL ASET', '-', 'Total Aset', $this->reportData['assets']['total_assets'] ?? '0.00'];

            // Liabilitas Lancar
            foreach ($this->reportData['liabilities']['current_liabilities']['items'] ?? [] as $item) {
                $rows[] = ['LIABILITAS LANCAR', $item['account_code'], $item['account_name'], $item['amount']];
            }
            $rows[] = ['SUBTOTAL LIABILITAS LANCAR', '-', 'Total Liabilitas Lancar', $this->reportData['liabilities']['current_liabilities']['total'] ?? '0.00'];

            // Liabilitas Jangka Panjang
            foreach ($this->reportData['liabilities']['non_current_liabilities']['items'] ?? [] as $item) {
                $rows[] = ['LIABILITAS JANGKA PANJANG', $item['account_code'], $item['account_name'], $item['amount']];
            }
            $rows[] = ['TOTAL LIABILITAS', '-', 'Total Liabilitas', $this->reportData['liabilities']['total_liabilities'] ?? '0.00'];

            // Ekuitas
            foreach ($this->reportData['equity']['items'] ?? [] as $item) {
                $rows[] = ['EKUITAS', $item['account_code'], $item['account_name'], $item['amount']];
            }
            $rows[] = ['LABA TAHUN BERJALAN', '-', 'Laba Bersih Tahun Berjalan (YTD)', $this->reportData['equity']['current_year_net_income'] ?? '0.00'];
            $rows[] = ['TOTAL EKUITAS', '-', 'Total Ekuitas', $this->reportData['equity']['total_equity'] ?? '0.00'];
            $rows[] = ['TOTAL LIABILITAS & EKUITAS', '-', 'Total Liabilitas dan Ekuitas', $this->reportData['total_liabilities_and_equity'] ?? '0.00'];
        } elseif ($this->reportType === 'cash_flow') {
            $rows[] = ['Saldo Kas Awal Periode', '-', '-', $this->reportData['beginning_cash'] ?? '0.00'];

            $op = $this->reportData['operating_activities'] ?? [];
            $rows[] = ['Arus Kas dari Aktivitas Operasi', $op['inflows'] ?? '0.00', $op['outflows'] ?? '0.00', $op['net'] ?? '0.00'];

            $inv = $this->reportData['investing_activities'] ?? [];
            $rows[] = ['Arus Kas dari Aktivitas Investasi', $inv['inflows'] ?? '0.00', $inv['outflows'] ?? '0.00', $inv['net'] ?? '0.00'];

            $fin = $this->reportData['financing_activities'] ?? [];
            $rows[] = ['Arus Kas dari Aktivitas Pendanaan', $fin['inflows'] ?? '0.00', $fin['outflows'] ?? '0.00', $fin['net'] ?? '0.00'];

            $rows[] = ['Kenaikan / (Penurunan) Kas Bersih', '-', '-', $this->reportData['net_cash_flow'] ?? '0.00'];
            $rows[] = ['Saldo Kas Akhir Periode', '-', '-', $this->reportData['ending_cash'] ?? '0.00'];
        }

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
            ],
        ];
    }
}
