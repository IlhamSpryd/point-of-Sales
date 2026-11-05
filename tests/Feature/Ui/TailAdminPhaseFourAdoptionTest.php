<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class TailAdminPhaseFourAdoptionTest extends TestCase
{
    public function test_purchasing_matching_workbench_renders_3_way_comparison(): void
    {
        $html = Blade::render(
            '<x-erp.purchasing.matching-workbench
                po-number="PO-2026-0089"
                grn-number="GRN-2026-0045"
                invoice-number="INV-SUP-9912"
                :po-total="5000000"
                :grn-total="5000000"
                :invoice-total="5000000"
                :lines="[
                    [\'item_name\' => \'Biji Kopi Arabika 10kg\', \'po_qty\' => 10, \'grn_qty\' => 10, \'inv_qty\' => 10, \'po_price\' => 500000, \'inv_price\' => 500000],
                ]"
            />'
        );

        $this->assertStringContainsString('PO-2026-0089', $html);
        $this->assertStringContainsString('GRN-2026-0045', $html);
        $this->assertStringContainsString('INV-SUP-9912', $html);
        $this->assertStringContainsString('Rp 5.000.000', $html);
        $this->assertStringContainsString('Matched', $html);
        $this->assertStringContainsString('Biji Kopi Arabika 10kg', $html);
    }

    public function test_journal_balance_banner_evaluates_balanced_and_imbalance(): void
    {
        // Balanced
        $balancedHtml = Blade::render(
            '<x-erp.finance.journal-balance-banner :total-debit="1500000" :total-credit="1500000" />'
        );
        $this->assertStringContainsString('Jurnal Seimbang', $balancedHtml);
        $this->assertStringContainsString('check_circle', $balancedHtml);
        $this->assertStringContainsString('Rp 1.500.000', $balancedHtml);

        // Imbalance
        $unbalancedHtml = Blade::render(
            '<x-erp.finance.journal-balance-banner :total-debit="2000000" :total-credit="1850000" />'
        );
        $this->assertStringContainsString('Jurnal Tidak Seimbang', $unbalancedHtml);
        $this->assertStringContainsString('error', $unbalancedHtml);
        $this->assertStringContainsString('Selisih', $unbalancedHtml);
        $this->assertStringContainsString('Rp 150.000', $unbalancedHtml);
    }

    public function test_inventory_variance_badge_renders_surplus_and_shortage(): void
    {
        $surplusHtml = Blade::render(
            '<x-erp.inventory.variance-badge :system-stock="100" :physical-stock="105" unit="Kg" />'
        );
        $this->assertStringContainsString('+5.00 Kg', $surplusHtml);
        $this->assertStringContainsString('trending_up', $surplusHtml);

        $shortageHtml = Blade::render(
            '<x-erp.inventory.variance-badge :system-stock="50" :physical-stock="48" unit="Box" />'
        );
        $this->assertStringContainsString('-2.00 Box', $shortageHtml);
        $this->assertStringContainsString('trending_down', $shortageHtml);
    }

    public function test_payroll_stepper_renders_four_steps(): void
    {
        $html = Blade::render(
            '<x-erp.hr.payroll-stepper :current-step="3" />'
        );

        $this->assertStringContainsString('Kehadiran &amp; Lembur', $html);
        $this->assertStringContainsString('Komisi &amp; Tunjangan', $html);
        $this->assertStringContainsString('PPh 21 TER &amp; BPJS', $html);
        $this->assertStringContainsString('Pengesahan &amp; Posting', $html);
    }

    public function test_payslip_renders_earnings_deductions_and_take_home_pay(): void
    {
        $html = Blade::render(
            '<x-erp.hr.payslip
                company-name="PT Apeiron Sukses Makmur"
                period="November 2026"
                employee-name="Ahmad Fadillah"
                employee-id="EMP-2026-0081"
                department="Operasional Dapur"
                position="Senior Cook"
                ptkp-status="K/1"
                :earnings="[
                    [\'label\' => \'Gaji Pokok\', \'amount\' => 5500000],
                    [\'label\' => \'Upah Lembur PP 35/2021\', \'amount\' => 750000],
                ]"
                :deductions="[
                    [\'label\' => \'PPh 21 TER Bulanan\', \'amount\' => 125000],
                    [\'label\' => \'BPJS Ketenagakerjaan (3%)\', \'amount\' => 165000],
                ]"
            />'
        );

        $this->assertStringContainsString('PT Apeiron Sukses Makmur', $html);
        $this->assertStringContainsString('Ahmad Fadillah', $html);
        $this->assertStringContainsString('Senior Cook', $html);
        $this->assertStringContainsString('Rp 6.250.000', $html); // Gross
        $this->assertStringContainsString('Rp 290.000', $html);   // Deductions
        $this->assertStringContainsString('Rp 5.960.000', $html); // Net (6.250.000 - 290.000)
    }
}
