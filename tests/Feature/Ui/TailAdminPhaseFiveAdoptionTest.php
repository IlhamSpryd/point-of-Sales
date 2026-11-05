<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class TailAdminPhaseFiveAdoptionTest extends TestCase
{
    public function test_rbi_chart_card_renders_title_periods_and_legends(): void
    {
        $html = Blade::render(
            '<x-erp.rbi.chart-card
                title="Tren Penjualan & HPP"
                subtitle="Performa transaksi multi-cabang"
                :periods="[\'Hari Ini\', \'7 Hari\', \'Bulan Ini\']"
                active-period="Bulan Ini"
                :legends="[
                    [\'label\' => \'Penjualan Bersih\', \'color\' => \'bg-primary\'],
                    [\'label\' => \'Harga Pokok (HPP)\', \'color\' => \'bg-amber-500\'],
                ]">
                <div id="chart-canvas">Canvas Dummy</div>
            </x-erp.rbi.chart-card>'
        );

        $this->assertStringContainsString('Tren Penjualan &amp; HPP', $html);
        $this->assertStringContainsString('Performa transaksi multi-cabang', $html);
        $this->assertStringContainsString('Hari Ini', $html);
        $this->assertStringContainsString('7 Hari', $html);
        $this->assertStringContainsString('Bulan Ini', $html);
        $this->assertStringContainsString('Penjualan Bersih', $html);
        $this->assertStringContainsString('Harga Pokok (HPP)', $html);
        $this->assertStringContainsString('Canvas Dummy', $html);
    }

    public function test_workflow_approval_card_renders_details_and_actions(): void
    {
        $html = Blade::render(
            '<x-erp.wfl.approval-card
                type="Purchase Request (PR)"
                doc-number="PR-2026-0099"
                requested-by="Dimas Anggara"
                role="Store Manager"
                amount="Rp 8.500.000"
                priority="urgent"
                level="Level 2"
                reason="Pengadaan darurat bahan kopi"
                date="11:30 WIB"
            />'
        );

        $this->assertStringContainsString('Purchase Request (PR)', $html);
        $this->assertStringContainsString('PR-2026-0099', $html);
        $this->assertStringContainsString('Dimas Anggara', $html);
        $this->assertStringContainsString('Rp 8.500.000', $html);
        $this->assertStringContainsString('Mendesak', $html);
        $this->assertStringContainsString('Level 2', $html);
        $this->assertStringContainsString('Setujui', $html);
        $this->assertStringContainsString('Tolak', $html);
    }

    public function test_subscription_quota_meter_renders_usage_and_ratio(): void
    {
        $html = Blade::render(
            '<x-erp.sub.quota-meter
                label="Kuota Cabang Aktif"
                :used="3"
                :limit="5"
                unit="Cabang"
            />'
        );

        $this->assertStringContainsString('Kuota Cabang Aktif', $html);
        $this->assertStringContainsString('3', $html);
        $this->assertStringContainsString('5 Cabang', $html);
        $this->assertStringContainsString('60%', $html);
    }

    public function test_subscription_plan_card_renders_pricing_and_features(): void
    {
        $html = Blade::render(
            '<x-erp.sub.plan-card
                name="Professional"
                price="Rp 299.000"
                period="/bulan"
                description="Untuk bisnis multi-outlet berkembang"
                :features="[\'Hingga 5 Cabang Fisik\', \'Modul Akuntansi GL Penuh\', \'Integrasi KDS &amp; POS\']"
                :is-popular="true"
            />'
        );

        $this->assertStringContainsString('Professional', $html);
        $this->assertStringContainsString('Rp 299.000', $html);
        $this->assertStringContainsString('/bulan', $html);
        $this->assertStringContainsString('Paling Populer', $html);
        $this->assertStringContainsString('Hingga 5 Cabang Fisik', $html);
    }

    public function test_integration_cards_render_webhook_and_api_token(): void
    {
        $webhookHtml = Blade::render(
            '<x-erp.int.webhook-log-card
                event="order.paid"
                url="https://api.merchant.com/webhooks/orders"
                :status="200"
                :duration-ms="145"
            />'
        );
        $this->assertStringContainsString('order.paid', $webhookHtml);
        $this->assertStringContainsString('200', $webhookHtml);
        $this->assertStringContainsString('145 ms', $webhookHtml);
        $this->assertStringContainsString('https://api.merchant.com/webhooks/orders', $webhookHtml);

        $tokenHtml = Blade::render(
            '<x-erp.int.api-token-card
                name="n8n Production Key"
                token="apeiron_sec_test_998811"
                :scopes="[\'orders:read\', \'inventory:write\']"
            />'
        );
        $this->assertStringContainsString('n8n Production Key', $tokenHtml);
        $this->assertStringContainsString('Salin', $tokenHtml);
        $this->assertStringContainsString('orders:read', $tokenHtml);
        $this->assertStringContainsString('inventory:write', $tokenHtml);
    }
}
