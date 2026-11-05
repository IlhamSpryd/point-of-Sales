<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class TailAdminPhaseThreeAdoptionTest extends TestCase
{
    public function test_touch_numpad_renders_display_and_quick_denomination_pills(): void
    {
        $html = Blade::render(
            '<x-erp.pos.touch-numpad
                :total="75000"
                value="100000"
                :quick-denominations="[20000, 50000, 100000]"
            />'
        );

        $this->assertStringContainsString('Uang Diterima', $html);
        $this->assertStringContainsString('Uang Pas', $html);
        $this->assertStringContainsString('Kembalian', $html);
        $this->assertStringContainsString('formatRupiah', $html);
        $this->assertStringContainsString('numeric', $html);
        $this->assertStringContainsString('backspace', $html);
    }

    public function test_tender_selector_renders_payment_methods(): void
    {
        $html = Blade::render(
            '<x-erp.pos.tender-selector value="qris" />'
        );

        $this->assertStringContainsString('Tunai', $html);
        $this->assertStringContainsString('QRIS', $html);
        $this->assertStringContainsString('Debit Card', $html);
        $this->assertStringContainsString('qr_code_scanner', $html);
        $this->assertStringContainsString('selectedMethod', $html);
    }

    public function test_table_tile_renders_table_status_and_capacity(): void
    {
        $html = Blade::render(
            '<x-erp.fnb.table-tile
                code="T-01"
                name="Meja Depan VIP"
                area="VIP Indoor"
                :capacity="6"
                status="occupied"
                occupied-since="35 mnt"
                order-total="Rp 340.000"
            />'
        );

        $this->assertStringContainsString('T-01', $html);
        $this->assertStringContainsString('Meja Depan VIP', $html);
        $this->assertStringContainsString('VIP Indoor', $html);
        $this->assertStringContainsString('6 Kursi', $html);
        $this->assertStringContainsString('Terisi', $html);
        $this->assertStringContainsString('35 mnt', $html);
        $this->assertStringContainsString('Rp 340.000', $html);
        $this->assertStringContainsString('animate-pulse', $html);
    }

    public function test_shelf_tag_renders_retail_barcode_and_price(): void
    {
        $html = Blade::render(
            '<x-erp.retail.shelf-tag
                title="Kopi Arabika Gayo 250g"
                sku="SKU-KPG-001"
                barcode="8991234567890"
                price="Rp 75.000"
                original-price="Rp 85.000"
                uom="Pack"
                promo-tag="Hemat 12%"
            />'
        );

        $this->assertStringContainsString('Kopi Arabika Gayo 250g', $html);
        $this->assertStringContainsString('SKU-KPG-001', $html);
        $this->assertStringContainsString('8991234567890', $html);
        $this->assertStringContainsString('Rp 75.000', $html);
        $this->assertStringContainsString('Rp 85.000', $html);
        $this->assertStringContainsString('Hemat 12%', $html);
        $this->assertStringContainsString('<svg', $html);
    }
}
