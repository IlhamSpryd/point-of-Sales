<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Core\Context\TenantContext;
use App\Enums\PoReceivingStatusEnum;
use App\Enums\SupplierStatusEnum;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Purchasing\Exceptions\OverReceiptToleranceExceededException;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\GoodsReceivedNoteService;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrnPartialReceivingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $ownerUser;

    private User $warehouseUser;

    private Supplier $supplier;

    private Product $product;

    private Ingredient $ingredient;

    private PurchaseOrderService $poService;

    private GoodsReceivedNoteService $grnService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Resto Berkah Abadi',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cabang Utama Jakarta',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $ownerRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'is_admin' => true,
        ]);

        $warehouseRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Warehouse Staff',
            'is_admin' => false,
            'permissions' => [
                'purchasing.grn.view',
                'purchasing.grn.create',
                'purchasing.grn.post',
                'purchasing.grn.reverse',
                'purchasing.grn.cancel',
            ],
        ]);

        $this->ownerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $ownerRole->id,
            'name' => 'Pak Budi Pemilik',
            'email' => 'owner.berkah@test.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->warehouseUser = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $warehouseRole->id,
            'name' => 'Doni Gudang',
            'email' => 'doni.gudang@test.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->ownerUser->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);
        $this->warehouseUser->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);

        $this->supplier = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'SUP-BERKAH-01',
            'name' => 'PT Pangan Sumber Makmur',
            'status' => SupplierStatusEnum::Active,
            'payment_terms_days' => 14,
            'currency_code' => 'IDR',
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Bahan Makanan',
            'slug' => 'bahan-makanan',
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'name' => 'Beras Pandan Wangi 5kg',
            'sku' => 'PRD-BERAS-5K',
            'price' => '85000.00',
        ]);

        $this->ingredient = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Gula Pasir Industri',
            'sku' => 'ING-GULA-KG',
            'unit' => 'KG',
            'current_cost' => '15000.00',
        ]);

        $this->poService = app(PurchaseOrderService::class);
        $this->grnService = app(GoodsReceivedNoteService::class);
    }

    /**
     * Membantu membuat PO yang sudah Released.
     */
    private function createReleasedPo(string $orderedQty1 = '10.0000', string $orderedQty2 = '20.0000', string $tolerance = '0.00'): PurchaseOrder
    {
        $po = $this->poService->createDirectPurchaseOrder(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'order_date' => now()->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'purchase_uom' => 'BAG',
                    'uom_conversion_factor' => '1.000000',
                    'ordered_qty' => $orderedQty1,
                    'unit_price' => '80000.0000',
                    'over_receipt_tolerance_pct' => $tolerance,
                ],
                [
                    'purchasable_type' => 'ingredient',
                    'purchasable_id' => $this->ingredient->id,
                    'purchase_uom' => 'KG',
                    'uom_conversion_factor' => '1.000000',
                    'ordered_qty' => $orderedQty2,
                    'unit_price' => '14500.0000',
                    'over_receipt_tolerance_pct' => $tolerance,
                ],
            ],
            $this->ownerUser
        );

        $po = $this->poService->submitPurchaseOrder($po, $this->ownerUser);

        return $this->poService->releasePurchaseOrder($po, $this->ownerUser);
    }

    /**
     * AT-06: Satu PO dapat menerima barang secara bertahap melalui beberapa dokumen GRN
     * Status penerimaan PO beralih: not_received -> partially_received -> fully_received.
     */
    public function test_partial_receiving_multi_grn_lifecycle(): void
    {
        $po = $this->createReleasedPo('10.0000', '20.0000');
        $this->assertEquals(PoReceivingStatusEnum::NotReceived, $po->receiving_status);

        $lines = $po->lines()->orderBy('id', 'asc')->get();
        $line1 = $lines[0];
        $line2 = $lines[1];

        // 1. GRN-1: Penerimaan Parsial (Line 1: 4/10, Line 2: 10/20)
        $grn1 = $this->grnService->createGoodsReceivedNote(
            $po,
            ['supplier_delivery_note_no' => 'SJ-SUP-001'],
            [
                [
                    'purchase_order_line_id' => $line1->id,
                    'received_qty' => '4.0000',
                    'accepted_qty' => '4.0000',
                    'rejected_qty' => '0.0000',
                ],
                [
                    'purchase_order_line_id' => $line2->id,
                    'received_qty' => '10.0000',
                    'accepted_qty' => '10.0000',
                    'rejected_qty' => '0.0000',
                ],
            ],
            $this->warehouseUser
        );

        $this->assertTrue($grn1->isDraft());
        $postedGrn1 = $this->grnService->postGrn($grn1, $this->warehouseUser);
        $this->assertTrue($postedGrn1->isPosted());

        // Verifikasi PO setelah GRN-1
        $po->refresh();
        $this->assertEquals(PoReceivingStatusEnum::PartiallyReceived, $po->receiving_status);

        $line1->refresh();
        $line2->refresh();
        $this->assertEquals('4.0000', $line1->accepted_qty);
        $this->assertEquals('6.0000', $line1->getRemainingReceivableQty());
        $this->assertEquals('10.0000', $line2->accepted_qty);
        $this->assertEquals('10.0000', $line2->getRemainingReceivableQty());

        // 2. GRN-2: Penerimaan Sisa (Line 1: 6/6, Line 2: 10/10)
        $grn2 = $this->grnService->createGoodsReceivedNote(
            $po,
            ['supplier_delivery_note_no' => 'SJ-SUP-002'],
            [
                [
                    'purchase_order_line_id' => $line1->id,
                    'received_qty' => '6.0000',
                    'accepted_qty' => '6.0000',
                    'rejected_qty' => '0.0000',
                ],
                [
                    'purchase_order_line_id' => $line2->id,
                    'received_qty' => '10.0000',
                    'accepted_qty' => '10.0000',
                    'rejected_qty' => '0.0000',
                ],
            ],
            $this->warehouseUser
        );

        $postedGrn2 = $this->grnService->postGrn($grn2, $this->warehouseUser);
        $this->assertTrue($postedGrn2->isPosted());

        // Verifikasi PO setelah GRN-2: Menjadi Fully Received
        $po->refresh();
        $this->assertEquals(PoReceivingStatusEnum::FullyReceived, $po->receiving_status);

        $line1->refresh();
        $line2->refresh();
        $this->assertEquals('10.0000', $line1->accepted_qty);
        $this->assertEquals('0.0000', $line1->getRemainingReceivableQty());
        $this->assertEquals('20.0000', $line2->accepted_qty);
        $this->assertEquals('0.0000', $line2->getRemainingReceivableQty());
    }

    /**
     * AT-07: remaining_receivable_qty dihitung akurat memisahkan accepted_qty dan rejected_qty.
     */
    public function test_remaining_receivable_math_distinguishes_accepted_and_rejected_quantities(): void
    {
        $po = $this->createReleasedPo('10.0000', '10.0000');
        $line1 = $po->lines()->orderBy('id', 'asc')->firstOrFail();

        // Datang 10 barang, tetapi 3 rusak saat inspeksi QC di gudang (accepted=7, rejected=3)
        $grn = $this->grnService->createGoodsReceivedNote(
            $po,
            ['supplier_delivery_note_no' => 'SJ-SUP-PARTIAL-REJECT'],
            [
                [
                    'purchase_order_line_id' => $line1->id,
                    'received_qty' => '10.0000',
                    'accepted_qty' => '7.0000',
                    'rejected_qty' => '3.0000',
                    'damaged_qty' => '3.0000',
                    'damage_disposition' => 'return_to_vendor',
                    'rejection_reason' => 'Kemasan robek dan basah di perjalanan armada pemasok.',
                ],
            ],
            $this->warehouseUser
        );

        $this->grnService->postGrn($grn, $this->warehouseUser);

        $line1->refresh();
        $this->assertEquals('10.0000', $line1->received_qty);
        $this->assertEquals('7.0000', $line1->accepted_qty);
        $this->assertEquals('3.0000', $line1->rejected_qty);

        // Hanya accepted_qty yang mengurangi pesanan; sisa yang masih dapat diterima adalah 3.0000
        $this->assertEquals('3.0000', $line1->getRemainingReceivableQty());

        $po->refresh();
        $this->assertEquals(PoReceivingStatusEnum::PartiallyReceived, $po->receiving_status);
    }

    /**
     * Validasi toleransi over-receipt:
     * Melebihi persentase toleransi ditolak; di dalam batas toleransi diizinkan dan status menjadi over_received.
     */
    public function test_over_receipt_tolerance_enforcement(): void
    {
        // PO dengan ordered_qty = 10 dan toleransi 5% (maksimum 10.5)
        $po = $this->createReleasedPo('10.0000', '10.0000', '5.00');
        $line1 = $po->lines()->orderBy('id', 'asc')->firstOrFail();

        // 1. Mencoba menerima 11 (melebihi batas 10.5) -> Wajib ditolak
        $this->expectException(OverReceiptToleranceExceededException::class);

        $this->grnService->createGoodsReceivedNote(
            $po,
            ['supplier_delivery_note_no' => 'SJ-OVER-EXCEED'],
            [
                [
                    'purchase_order_line_id' => $line1->id,
                    'received_qty' => '11.0000',
                    'accepted_qty' => '11.0000',
                    'rejected_qty' => '0.0000',
                ],
            ],
            $this->warehouseUser
        );
    }

    /**
     * Penerimaan dalam batas toleransi over-receipt berhasil dan menghasilkan status over_received.
     */
    public function test_over_receipt_within_tolerance_succeeds_and_marks_over_received(): void
    {
        // PO dengan ordered_qty = 10 dan toleransi 10% (maksimum 11.0)
        $po = $this->createReleasedPo('10.0000', '10.0000', '10.00');
        $lines = $po->lines()->orderBy('id', 'asc')->get();
        $line1 = $lines[0];
        $line2 = $lines[1];

        // Terima Line 1: 10.5 (over-received dalam batas 11.0), Line 2: 10.0 (pas)
        $grn = $this->grnService->createGoodsReceivedNote(
            $po,
            ['supplier_delivery_note_no' => 'SJ-OVER-OK'],
            [
                [
                    'purchase_order_line_id' => $line1->id,
                    'received_qty' => '10.5000',
                    'accepted_qty' => '10.5000',
                    'rejected_qty' => '0.0000',
                ],
                [
                    'purchase_order_line_id' => $line2->id,
                    'received_qty' => '10.0000',
                    'accepted_qty' => '10.0000',
                    'rejected_qty' => '0.0000',
                ],
            ],
            $this->warehouseUser
        );

        $this->grnService->postGrn($grn, $this->warehouseUser);

        $po->refresh();
        $this->assertEquals(PoReceivingStatusEnum::OverReceived, $po->receiving_status);

        $line1->refresh();
        $this->assertEquals('10.5000', $line1->accepted_qty);
        $this->assertEquals('0.0000', $line1->getRemainingReceivableQty());
    }

    /**
     * Pembalikan GRN (Controlled GRN Reversal):
     * Merestorasi akumulator PO dan status PO kembali ke sebelumnya.
     */
    public function test_grn_reversal_restores_po_receiving_status_and_accumulators(): void
    {
        $po = $this->createReleasedPo('10.0000', '20.0000');
        $lines = $po->lines()->orderBy('id', 'asc')->get();
        $line1 = $lines[0];
        $line2 = $lines[1];

        $grn = $this->grnService->createGoodsReceivedNote(
            $po,
            ['supplier_delivery_note_no' => 'SJ-TO-REVERSE'],
            [
                [
                    'purchase_order_line_id' => $line1->id,
                    'received_qty' => '10.0000',
                    'accepted_qty' => '10.0000',
                    'rejected_qty' => '0.0000',
                ],
                [
                    'purchase_order_line_id' => $line2->id,
                    'received_qty' => '20.0000',
                    'accepted_qty' => '20.0000',
                    'rejected_qty' => '0.0000',
                ],
            ],
            $this->warehouseUser
        );

        $postedGrn = $this->grnService->postGrn($grn, $this->warehouseUser);
        $po->refresh();
        $this->assertEquals(PoReceivingStatusEnum::FullyReceived, $po->receiving_status);

        // Lakukan pembalikan (Reversal)
        $reversedGrn = $this->grnService->reverseGrn(
            $postedGrn,
            $this->warehouseUser,
            'Barang salah kirim dari armada supplier pusat, seluruh muatan ditarik kembali.'
        );

        $this->assertTrue($reversedGrn->isReversed());
        $this->assertNotNull($reversedGrn->reversed_at);
        $this->assertEquals('Barang salah kirim dari armada supplier pusat, seluruh muatan ditarik kembali.', $reversedGrn->reversal_reason);

        // Asersi akumulator dan receiving status PO kembali ke NotReceived
        $po->refresh();
        $this->assertEquals(PoReceivingStatusEnum::NotReceived, $po->receiving_status);

        $line1->refresh();
        $line2->refresh();
        $this->assertEquals('0.0000', $line1->accepted_qty);
        $this->assertEquals('10.0000', $line1->getRemainingReceivableQty());
        $this->assertEquals('0.0000', $line2->accepted_qty);
        $this->assertEquals('20.0000', $line2->getRemainingReceivableQty());
    }
}
