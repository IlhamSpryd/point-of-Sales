<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services\Providers;

/**
 * Metric calculations for CRM, CLV, ARPC, Retention, and Workforce Labor Cost (STR-14, ADR-108).
 */
class CustomerAndWorkforceMetricProvider
{
    /**
     * @return array<string, array{code: string, key: string, name: string, formula: string, authoritative_source: string, unit: string, scale: int, gl_account_code: string|null}>
     */
    public function definitions(): array
    {
        return [
            'KPI-CRM-01' => [
                'code' => 'KPI-CRM-01',
                'key' => 'customer_lifetime_value',
                'name' => 'Customer Lifetime Value (CLV / ARPC)',
                'formula' => 'Total Registered Customer Net Sales / Total Tracked Customers',
                'authoritative_source' => 'orders, customers',
                'unit' => 'IDR',
                'scale' => 2,
                'gl_account_code' => null,
            ],
            'KPI-CRM-02' => [
                'code' => 'KPI-CRM-02',
                'key' => 'repeat_purchase_rate',
                'name' => 'Customer Repeat Purchase Rate (%)',
                'formula' => '(Customers with >= 2 Orders / Total Transacting Customers) * 100',
                'authoritative_source' => 'orders, customers',
                'unit' => '%',
                'scale' => 2,
                'gl_account_code' => null,
            ],
            'KPI-HRM-01' => [
                'code' => 'KPI-HRM-01',
                'key' => 'labor_cost_pct',
                'name' => 'Labor Cost (%)',
                'formula' => '(Total Payroll + Overtime + BPJS / Net Sales) * 100',
                'authoritative_source' => 'payroll_batches & orders',
                'unit' => '%',
                'scale' => 2,
                'gl_account_code' => '5301',
            ],
        ];
    }

    /**
     * @return numeric-string
     */
    public function toNumericString(mixed $value, int $scale = 2): string
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            /** @var numeric-string $str */
            $str = (string) $value;

            return bcadd($str, '0', $scale);
        }

        /** @var numeric-string $zero */
        $zero = bcadd('0', '0', $scale);

        return $zero;
    }

    /**
     * KPI-CRM-01: Customer Lifetime Value (CLV) / ARPC.
     */
    public function calculateClv(mixed $totalCustomerRevenue, int $totalTrackedCustomers): string
    {
        if ($totalTrackedCustomers <= 0) {
            return '0.00';
        }

        $revenue = $this->toNumericString($totalCustomerRevenue, 2);

        return bcdiv($revenue, (string) $totalTrackedCustomers, 2);
    }

    /**
     * KPI-CRM-01: Average Net Revenue Per Customer (ARPC).
     */
    public function calculateArpc(mixed $registeredCustomerNetSales, int $uniqueRegisteredCustomers): string
    {
        return $this->calculateClv($registeredCustomerNetSales, $uniqueRegisteredCustomers);
    }

    /**
     * KPI-CRM-02: Tingkat Pembelian Berulang (Repeat Purchase Rate %) = (Pelanggan Transaksi >= 2 / Total Pelanggan Transaksi) * 100.
     */
    public function calculateRepeatPurchaseRate(int $repeatCustomerCount, int $totalCustomerCount): string
    {
        if ($totalCustomerCount <= 0) {
            return '0.00';
        }

        return $this->calculateContributionPct($repeatCustomerCount, $totalCustomerCount);
    }

    /**
     * KPI-HRM-01: Labor Cost % = (Total Labor Cost / Net Sales) * 100.
     */
    public function calculateLaborCostPct(mixed $totalLaborCost, mixed $netSales): string
    {
        return $this->calculateContributionPct($totalLaborCost, $netSales);
    }

    public function calculateContributionPct(mixed $partValue, mixed $totalValue): string
    {
        $part = $this->toNumericString($partValue, 4);
        $total = $this->toNumericString($totalValue, 4);

        if (bccomp($total, '0.0000', 4) <= 0) {
            return '0.00';
        }

        return bcmul(bcdiv($part, $total, 4), '100', 2);
    }
}
