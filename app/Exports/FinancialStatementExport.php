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
                'Account Code',
                'Account Name',
                'Account Type',
                'Beginning Debit',
                'Beginning Credit',
                'Period Debit',
                'Period Credit',
                'Ending Debit',
                'Ending Credit',
            ],
            'profit_and_loss' => [
                'Category',
                'Account Code',
                'Account Name',
                'Amount',
            ],
            'balance_sheet' => [
                'Classification',
                'Account Code',
                'Account Name',
                'Amount',
            ],
            'cash_flow' => [
                'Activity / Category',
                'Cash Inflow',
                'Cash Outflow',
                'Net Cash Flow',
            ],
            default => [
                'Column 1',
                'Column 2',
                'Column 3',
                'Amount',
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
                'Total Trial Balance',
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

            // 1. Revenue
            foreach ($sections['revenue']['items'] ?? [] as $item) {
                $rows[] = ['REVENUE', $item['account_code'], $item['account_name'], $item['amount']];
            }
            $rows[] = ['SUBTOTAL', '-', 'Total Revenue', $sections['revenue']['total'] ?? '0.00'];

            // 2. COGS
            foreach ($sections['cogs']['items'] ?? [] as $item) {
                $rows[] = ['COGS', $item['account_code'], $item['account_name'], $item['amount']];
            }
            $rows[] = ['SUBTOTAL', '-', 'Total COGS', $sections['cogs']['total'] ?? '0.00'];
            $rows[] = ['GROSS PROFIT', '-', 'Gross Profit', $sections['gross_profit'] ?? '0.00'];

            // 3. Operating Expenses
            foreach ($sections['operating_expenses']['items'] ?? [] as $item) {
                $rows[] = ['OPERATING EXPENSES', $item['account_code'], $item['account_name'], $item['amount']];
            }
            $rows[] = ['SUBTOTAL', '-', 'Total Operating Expenses', $sections['operating_expenses']['total'] ?? '0.00'];
            $rows[] = ['OPERATING INCOME', '-', 'Operating Income', $sections['operating_income'] ?? '0.00'];

            // 4. Other
            foreach ($sections['other_income']['items'] ?? [] as $item) {
                $rows[] = ['OTHER INCOME', $item['account_code'], $item['account_name'], $item['amount']];
            }
            foreach ($sections['other_expense']['items'] ?? [] as $item) {
                $rows[] = ['OTHER EXPENSE', $item['account_code'], $item['account_name'], $item['amount']];
            }

            $rows[] = ['NET INCOME', '-', 'Net Income for the Year', $this->reportData['net_income'] ?? '0.00'];
        } elseif ($this->reportType === 'balance_sheet') {
            // Current Assets
            foreach ($this->reportData['assets']['current_assets']['items'] ?? [] as $item) {
                $rows[] = ['CURRENT ASSETS', $item['account_code'], $item['account_name'], $item['amount']];
            }
            $rows[] = ['SUBTOTAL CURRENT ASSETS', '-', 'Total Current Assets', $this->reportData['assets']['current_assets']['total'] ?? '0.00'];

            // Non-Current Assets
            foreach ($this->reportData['assets']['non_current_assets']['items'] ?? [] as $item) {
                $rows[] = ['NON-CURRENT ASSETS', $item['account_code'], $item['account_name'], $item['amount']];
            }
            $rows[] = ['SUBTOTAL NON-CURRENT ASSETS', '-', 'Total Non-Current Assets', $this->reportData['assets']['non_current_assets']['total'] ?? '0.00'];
            $rows[] = ['TOTAL ASSETS', '-', 'Total Assets', $this->reportData['assets']['total_assets'] ?? '0.00'];

            // Current Liabilities
            foreach ($this->reportData['liabilities']['current_liabilities']['items'] ?? [] as $item) {
                $rows[] = ['CURRENT LIABILITIES', $item['account_code'], $item['account_name'], $item['amount']];
            }
            $rows[] = ['SUBTOTAL CURRENT LIABILITIES', '-', 'Total Current Liabilities', $this->reportData['liabilities']['current_liabilities']['total'] ?? '0.00'];

            // Non-Current Liabilities
            foreach ($this->reportData['liabilities']['non_current_liabilities']['items'] ?? [] as $item) {
                $rows[] = ['NON-CURRENT LIABILITIES', $item['account_code'], $item['account_name'], $item['amount']];
            }
            $rows[] = ['TOTAL LIABILITIES', '-', 'Total Liabilities', $this->reportData['liabilities']['total_liabilities'] ?? '0.00'];

            // Equity
            foreach ($this->reportData['equity']['items'] ?? [] as $item) {
                $rows[] = ['EQUITY', $item['account_code'], $item['account_name'], $item['amount']];
            }
            $rows[] = ['CURRENT YEAR NET INCOME', '-', 'Current Year Net Income (YTD)', $this->reportData['equity']['current_year_net_income'] ?? '0.00'];
            $rows[] = ['TOTAL EQUITY', '-', 'Total Equity', $this->reportData['equity']['total_equity'] ?? '0.00'];
            $rows[] = ['TOTAL LIABILITIES & EQUITY', '-', 'Total Liabilities and Equity', $this->reportData['total_liabilities_and_equity'] ?? '0.00'];
        } elseif ($this->reportType === 'cash_flow') {
            $rows[] = ['Beginning Cash Balance', '-', '-', $this->reportData['beginning_cash'] ?? '0.00'];

            $op = $this->reportData['operating_activities'] ?? [];
            $rows[] = ['Cash Flow from Operating Activities', $op['inflows'] ?? '0.00', $op['outflows'] ?? '0.00', $op['net'] ?? '0.00'];

            $inv = $this->reportData['investing_activities'] ?? [];
            $rows[] = ['Cash Flow from Investing Activities', $inv['inflows'] ?? '0.00', $inv['outflows'] ?? '0.00', $inv['net'] ?? '0.00'];

            $fin = $this->reportData['financing_activities'] ?? [];
            $rows[] = ['Cash Flow from Financing Activities', $fin['inflows'] ?? '0.00', $fin['outflows'] ?? '0.00', $fin['net'] ?? '0.00'];

            $rows[] = ['Net Increase / (Decrease) in Cash', '-', '-', $this->reportData['net_cash_flow'] ?? '0.00'];
            $rows[] = ['Ending Cash Balance', '-', '-', $this->reportData['ending_cash'] ?? '0.00'];
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
