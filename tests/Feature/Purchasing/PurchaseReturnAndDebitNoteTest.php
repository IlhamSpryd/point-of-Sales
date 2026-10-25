<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Core\Context\TenantContext;
use App\Enums\DebitNoteStatusEnum;
use App\Enums\GrnStatusEnum;
use App\Enums\InvoiceSettlementStatusEnum;
use App\Enums\PurchaseReturnStatusEnum;
use App\Enums\SupplierInvoiceStatusEnum;
use App\Enums\SupplierStatusEnum;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Purchasing\Models\AccountingPayableOutbox;
use App\Modules\Purchasing\Models\GoodsReceivedNote;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\GoodsReceivedNoteService;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReturnService;
use App\Modules\Purchasing\Services\SupplierInvoiceMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseReturnAndDebitNoteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $purchasingUser;

    private Supplier $supplier;

    private Product $product;

    private PurchaseOrderService $poService;

    private GoodsReceivedNoteService $grnService;

    private PurchaseReturnService $returnService;

    private SupplierInvoiceMatchingService $invoiceService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Resto Sentosa Utama',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store Pusat Sentosa',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'permissions' => ['*'],
            'is_admin' => true,
        ]);

        $this->purchasingUser = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
            'name' => 'Purchasing Officer Sentosa',
            'email' => 'purchasing.officer@sentosa.test',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->purchasingUser->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);

        $this->supplier = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'SUP-SNT-01',
            'name' => 'PT Suplai Sentosa Perkasa',
            'status' => SupplierStatusEnum::Active,
            'payment_terms_days' => 30,
            'currency_code' => 'IDR',
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Bahan Baku Sentosa',
            'slug' => 'bahan-baku-sentosa',
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'name' => 'Kopi Arabika 1kg',
            'sku' => 'PRD-KPI-ARB-1K',
            'price' => '120000.00',
        ]);

        $this->poService = app(PurchaseOrderService::class);
        $this->grnService = app(GoodsReceivedNoteService::class);
        $this->returnService = app(PurchaseReturnService::class);
        $this->invoiceService = app(SupplierInvoiceMatchingService::class);
    }

    /**
     * Helper membuat PO dan memposting GRN.
     *
     * @return array{po: PurchaseOrder, grn: GoodsReceivedNote}
     */
    private function createAndPostGrn(string $orderedQty, string $unitPrice, string $receivedQty): array
    {
        $po = $this->poService->createDirectPurchaseOrder(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'order_date' => now()->toDateString(),
                'expected_delivery_date' => now()->addDays(7)->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'purchase_uom' => 'PCS',
                    'uom_conversion_factor' => '1.000000',
                    'ordered_qty' => $orderedQty,
                    'unit_price' => $unitPrice,
                ],
            ],
            $this->purchasingUser
        );

        $po = $this->poService->submitPurchaseOrder($po, $this->purchasingUser);
        $po = $this->poService->releasePurchaseOrder($po, $this->purchasingUser);

        $poLine = $po->lines()->firstOrFail();

        $grn = $this->grnService->createGoodsReceivedNote(
            $po,
            [
                'received_at' => now()->toDateTimeString(),
                'supplier_delivery_note_no' => 'SJ-'.rand(1000, 9999),
            ],
            [
                [
                    'purchase_order_line_id' => $poLine->id,
                    'received_qty' => $receivedQty,
                    'accepted_qty' => $receivedQty,
                    'rejected_qty' => '0.0000',
                ],
            ],
            $this->purchasingUser
        );

        $postedGrn = $this->grnService->postGrn($grn, $this->purchasingUser);

        return ['po' => $po->fresh(['lines']), 'grn' => $postedGrn->fresh(['lines'])];
    }

    /**
     * AT-12: Retur pembelian membatasi kuantitas retur hingga eligible_returnable_qty.
     */
    public function test_purchase_return_creation_respects_eligible_returnable_qty(): void
    {
        // 1. Terima 50 unit pada GRN
        $setup = $this->createAndPostGrn('50.0000', '50000.0000', '50.0000');
        $grn = $setup['grn'];
        $grnLine = $grn->lines->first();

        // 2. Upaya membuat retur 60 unit (melebihi 50) wajib ditolak
        try {
            $this->returnService->createPurchaseReturn(
                [
                    'store_id' => $this->store->id,
                    'supplier_id' => $this->supplier->id,
                    'goods_received_note_id' => $grn->id,
                    'reason' => 'Kopi berjamur sebagian',
                ],
                [
                    [
                        'goods_received_note_line_id' => $grnLine->id,
                        'return_qty' => '60.0000',
                    ],
                ],
                $this->purchasingUser
            );
            $this->fail('Harus gagal karena retur melebihi accepted_qty GRN.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines.1.return_qty', $e->errors());
        }

        // 3. Buat retur 30 unit -> Harus berhasil dengan status draft
        $ret1 = $this->returnService->createPurchaseReturn(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'goods_received_note_id' => $grn->id,
                'reason' => 'Kopi berjamur 30 bungkus',
            ],
            [
                [
                    'goods_received_note_line_id' => $grnLine->id,
                    'return_qty' => '30.0000',
                ],
            ],
            $this->purchasingUser
        );

        $this->assertEquals(PurchaseReturnStatusEnum::Draft, $ret1->status);
        $this->assertEquals('1500000.00', $ret1->total_amount);

        // 4. Buat retur kedua 25 unit -> Harus ditolak karena 30 sudah dipakai (sisa hanya 20)
        try {
            $this->returnService->createPurchaseReturn(
                [
                    'store_id' => $this->store->id,
                    'supplier_id' => $this->supplier->id,
                    'goods_received_note_id' => $grn->id,
                    'reason' => 'Retur susulan',
                ],
                [
                    [
                        'goods_received_note_line_id' => $grnLine->id,
                        'return_qty' => '25.0000',
                    ],
                ],
                $this->purchasingUser
            );
            $this->fail('Harus gagal karena sisa returnable tinggal 20 unit.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines.1.return_qty', $e->errors());
        }

        // 5. Buat retur kedua 20 unit -> Harus berhasil pas 100% dari sisa
        $ret2 = $this->returnService->createPurchaseReturn(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'goods_received_note_id' => $grn->id,
                'reason' => 'Retur sisa 20 unit',
            ],
            [
                [
                    'goods_received_note_line_id' => $grnLine->id,
                    'return_qty' => '20.0000',
                ],
            ],
            $this->purchasingUser
        );

        $this->assertEquals('1000000.00', $ret2->total_amount);
    }

    /**
     * AT-12: Posting retur memotong stok M4, mempertahankan histori GRN,
     * mengupdate returned_qty PO, dan otomatis menerbitkan PurchaseDebitNote.
     */
    public function test_post_purchase_return_deducts_m4_inventory_and_generates_debit_note(): void
    {
        // 1. Terima 100 unit pada GRN seharga Rp 50.000/unit
        $setup = $this->createAndPostGrn('100.0000', '50000.0000', '100.0000');
        $po = $setup['po'];
        $grn = $setup['grn'];
        $grnLine = $grn->lines->first();

        // Verifikasi saldo awal di M4 StockBalance = 100
        $balance = StockBalance::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertNotNull($balance);
        $this->assertEquals('100.0000', (string) $balance->quantity);

        // 2. Buat draf retur 40 unit
        $ret = $this->returnService->createPurchaseReturn(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'goods_received_note_id' => $grn->id,
                'reason' => 'Kemasan rusak saat inspeksi pasca-bongkar',
            ],
            [
                [
                    'goods_received_note_line_id' => $grnLine->id,
                    'return_qty' => '40.0000',
                ],
            ],
            $this->purchasingUser
        );

        // 3. Posting Retur Pembelian
        $result = $this->returnService->postPurchaseReturn($ret->id, $this->purchasingUser);
        $postedReturn = $result['purchase_return'];
        $debitNote = $result['debit_note'];

        // Verifikasi status retur menjadi posted
        $this->assertEquals(PurchaseReturnStatusEnum::Posted, $postedReturn->status);
        $this->assertNotNull($postedReturn->posted_at);

        // Verifikasi saldo M4 berkurang secara akurat (100 - 40 = 60)
        $balance->refresh();
        $this->assertEquals('60.0000', (string) $balance->quantity);

        // Verifikasi histori GRN tidak terhapus dan tetap posted
        $grn->refresh();
        $this->assertEquals(GrnStatusEnum::Posted, $grn->status);
        $this->assertEquals('100.0000', (string) $grnLine->fresh()->accepted_qty);

        // Verifikasi returned_qty pada PO terakumulasi 40.0000
        $poLine = $po->lines->first()->fresh();
        $this->assertEquals('40.0000', (string) $poLine->returned_qty);

        // Verifikasi penerbitan otomatis PurchaseDebitNote (status issued)
        $this->assertEquals(DebitNoteStatusEnum::Issued, $debitNote->status);
        $this->assertEquals($postedReturn->id, $debitNote->purchase_return_id);
        $this->assertEquals('2000000.00', $debitNote->total_amount);
        $this->assertEquals('2000000.00', $debitNote->remaining_amount);
        $this->assertEquals('0.00', $debitNote->applied_amount);
        $this->assertStringStartsWith('DN-', $debitNote->debit_note_number);
    }

    /**
     * AT-12: Retur pembelian ditolak jika stok fisik saat ini tidak mencukupi (non-negative stock invariant).
     */
    public function test_purchase_return_is_rejected_when_physical_stock_is_insufficient(): void
    {
        // 1. Terima 10 unit pada GRN
        $setup = $this->createAndPostGrn('10.0000', '50000.0000', '10.0000');
        $grn = $setup['grn'];
        $grnLine = $grn->lines->first();

        // 2. Simulasikan penjualan/pemakaian stok sehingga sisa stok di toko hanya 2 unit
        $balance = StockBalance::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('product_id', $this->product->id)
            ->first();
        $balance->quantity = '2.0000';
        $balance->save();

        // 3. Buat draf retur 5 unit (padahal stok fisik hanya 2)
        $ret = $this->returnService->createPurchaseReturn(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'goods_received_note_id' => $grn->id,
                'reason' => 'Retur barang rusak',
            ],
            [
                [
                    'goods_received_note_line_id' => $grnLine->id,
                    'return_qty' => '5.0000',
                ],
            ],
            $this->purchasingUser
        );

        // 4. Eksekusi posting retur wajib ditolak oleh Invariant INV-M4-05
        try {
            $this->returnService->postPurchaseReturn($ret->id, $this->purchasingUser);
            $this->fail('Posting retur harus gagal karena stok di M4 tidak mencukupi (akan menjadi negatif).');
        } catch (ValidationException $e) {
            $this->assertInstanceOf(ValidationException::class, $e);
        }

        // Verifikasi retur tetap dalam status draft dan saldo stok tidak berubah negatif
        $ret->refresh();
        $this->assertEquals(PurchaseReturnStatusEnum::Draft, $ret->status);

        $balance->refresh();
        $this->assertEquals('2.0000', (string) $balance->quantity);
    }

    /**
     * AT-12 & AT-17: Pengaplikasian Nota Debit ke Faktur Pemasok memotong saldo hutang
     * dan menerbitkan rekaman outbox akuntansi M7.
     */
    public function test_apply_debit_note_to_supplier_invoice_and_outbox_contract(): void
    {
        // 1. Terima barang 100 unit seharga Rp 50.000
        $setup = $this->createAndPostGrn('100.0000', '50000.0000', '100.0000');
        $grn = $setup['grn'];
        $grnLine = $grn->lines->first();

        // 2. Buat Faktur Pemasok untuk 100 unit seharga Rp 50.000 = Rp 5.000.000
        $invoice = $this->invoiceService->createSupplierInvoice(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'supplier_invoice_number' => 'INV-SUP-SNT-889',
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'invoiced_qty' => '100.0000',
                    'unit_price' => '50000.0000',
                ],
            ],
            $this->purchasingUser
        );

        $invLine = $invoice->lines->first();

        // Lakukan 3-Way matching dan persetujuan bayar
        $this->invoiceService->performThreeWayMatching(
            $invoice,
            [
                [
                    'supplier_invoice_line_id' => $invLine->id,
                    'goods_received_note_line_id' => $grnLine->id,
                    'matched_qty' => '100.0000',
                ],
            ],
            $this->purchasingUser
        );

        $approvedInvoice = $this->invoiceService->approvePayable($invoice, $this->purchasingUser);
        $this->assertEquals(SupplierInvoiceStatusEnum::ApprovedPayable, $approvedInvoice->status);
        $this->assertEquals('5000000.00', $approvedInvoice->outstanding_amount);

        // 3. Buat dan posting retur untuk 30 unit = Rp 1.500.000
        $ret = $this->returnService->createPurchaseReturn(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'goods_received_note_id' => $grn->id,
                'reason' => '30 botol kempes',
            ],
            [
                [
                    'goods_received_note_line_id' => $grnLine->id,
                    'return_qty' => '30.0000',
                ],
            ],
            $this->purchasingUser
        );

        $postResult = $this->returnService->postPurchaseReturn($ret->id, $this->purchasingUser);
        $debitNote = $postResult['debit_note'];
        $this->assertEquals('1500000.00', $debitNote->remaining_amount);

        // 4. Aplikasikan Rp 1.000.000 dari Nota Debit ke Faktur Pemasok
        $application1 = $this->returnService->applyDebitNoteToInvoice(
            $debitNote->id,
            $approvedInvoice->id,
            '1000000.00',
            $this->purchasingUser,
            'Aplikasi tahap 1'
        );

        $debitNote->refresh();
        $approvedInvoice->refresh();

        $this->assertEquals('1000000.00', $debitNote->applied_amount);
        $this->assertEquals('500000.00', $debitNote->remaining_amount);
        $this->assertEquals(DebitNoteStatusEnum::PartiallyApplied, $debitNote->status);

        $this->assertEquals('1000000.00', $approvedInvoice->debit_note_applied_amount);
        $this->assertEquals('4000000.00', $approvedInvoice->outstanding_amount);
        $this->assertEquals(InvoiceSettlementStatusEnum::PartiallyPaid, $approvedInvoice->settlement_status);

        // Verifikasi kontrak outbox M7 diterbitkan
        $outbox1 = AccountingPayableOutbox::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('idempotency_key', "dn:apply:{$this->tenant->id}:{$application1->id}")
            ->first();

        $this->assertNotNull($outbox1);
        $this->assertEquals('purchase_debit_note', $outbox1->source_document_type);
        $payload1 = $outbox1->payload;
        $this->assertEquals('DEBIT_NOTE_APPLIED', $payload1['event_type']);
        $this->assertEquals('1000000.00', $payload1['applied_amount']);
        $this->assertEquals('500000.00', $payload1['remaining_debit_note_amount']);
        $this->assertEquals('4000000.00', $payload1['remaining_invoice_outstanding_amount']);

        // 5. Aplikasikan sisa Rp 500.000 dari Nota Debit ke Faktur Pemasok
        $this->returnService->applyDebitNoteToInvoice(
            $debitNote->id,
            $approvedInvoice->id,
            '500000.00',
            $this->purchasingUser,
            'Aplikasi tahap 2 (pelunasan nota debit)'
        );

        $debitNote->refresh();
        $approvedInvoice->refresh();

        $this->assertEquals('1500000.00', $debitNote->applied_amount);
        $this->assertEquals('0.00', $debitNote->remaining_amount);
        $this->assertEquals(DebitNoteStatusEnum::FullyApplied, $debitNote->status);

        $this->assertEquals('1500000.00', $approvedInvoice->debit_note_applied_amount);
        $this->assertEquals('3500000.00', $approvedInvoice->outstanding_amount);
    }

    /**
     * AT-12: Pencatatan nomor referensi nota kredit resmi pemasok.
     */
    public function test_supplier_credit_note_acknowledgement(): void
    {
        $setup = $this->createAndPostGrn('20.0000', '15000.0000', '20.0000');
        $grn = $setup['grn'];
        $grnLine = $grn->lines->first();

        $ret = $this->returnService->createPurchaseReturn(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'goods_received_note_id' => $grn->id,
                'reason' => 'Retur barang rusak',
            ],
            [
                [
                    'goods_received_note_line_id' => $grnLine->id,
                    'return_qty' => '10.0000',
                ],
            ],
            $this->purchasingUser
        );

        $postResult = $this->returnService->postPurchaseReturn($ret->id, $this->purchasingUser);
        $debitNote = $postResult['debit_note'];

        $updatedDn = $this->returnService->acknowledgeSupplierCreditNote(
            $debitNote->id,
            'SCN-2026-X99',
            $this->purchasingUser
        );

        $this->assertEquals('SCN-2026-X99', $updatedDn->supplier_credit_note_number);
    }
}
