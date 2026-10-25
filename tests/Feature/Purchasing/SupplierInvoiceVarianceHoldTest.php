<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Core\Context\TenantContext;
use App\Enums\InvoiceMatchingStatusEnum;
use App\Enums\SupplierInvoiceStatusEnum;
use App\Enums\SupplierStatusEnum;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\GoodsReceivedNoteService;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\SupplierInvoiceMatchingService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierInvoiceVarianceHoldTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $ownerUser;

    private User $accountantUser;

    private Supplier $supplier;

    private Product $product;

    private PurchaseOrderService $poService;

    private GoodsReceivedNoteService $grnService;

    private SupplierInvoiceMatchingService $invoiceService;

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

        $accountantRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Staff Accounting',
            'is_admin' => false,
            'permissions' => [
                'purchasing.invoice.view',
                'purchasing.invoice.create',
                'purchasing.invoice.match',
                'purchasing.invoice.approve_payable',
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

        $this->accountantUser = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $accountantRole->id,
            'name' => 'Sari Akunting',
            'email' => 'sari.acct@test.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->ownerUser->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);
        $this->accountantUser->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);

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
            'name' => 'Bahan Baku',
            'slug' => 'bahan-baku',
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'name' => 'Daging Sapi Slice 1kg',
            'sku' => 'PRD-SAPI-1K',
            'price' => '120000.00',
        ]);

        $this->poService = app(PurchaseOrderService::class);
        $this->grnService = app(GoodsReceivedNoteService::class);
        $this->invoiceService = app(SupplierInvoiceMatchingService::class);
    }

    private function setupReleasedPoAndPostedGrn(string $qty = '10.0000', string $unitPrice = '100000.0000'): array
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
                    'purchase_uom' => 'KG',
                    'ordered_qty' => $qty,
                    'unit_price' => $unitPrice,
                ],
            ],
            $this->ownerUser
        );

        $po = $this->poService->submitPurchaseOrder($po, $this->ownerUser);
        $po = $this->poService->releasePurchaseOrder($po, $this->ownerUser);

        $poLine = $po->lines()->firstOrFail();

        $grn = $this->grnService->createGoodsReceivedNote(
            $po,
            ['supplier_delivery_note_no' => 'SJ-HOLD-001'],
            [
                [
                    'purchase_order_line_id' => $poLine->id,
                    'received_qty' => $qty,
                    'accepted_qty' => $qty,
                    'rejected_qty' => '0.0000',
                ],
            ],
            $this->ownerUser
        );

        $postedGrn = $this->grnService->postGrn($grn, $this->ownerUser);

        return ['po' => $po->fresh(), 'grn' => $postedGrn];
    }

    /**
     * AT-10: Selisih harga satuan (Price Variance) menahan faktur ke status variance_hold.
     * Faktur tidak dapat di-approve payable sebelum varians disetujui oleh pejabat berwenang.
     */
    public function test_price_variance_triggers_variance_hold(): void
    {
        // PO harga satuan Rp 100.000
        $setup = $this->setupReleasedPoAndPostedGrn('10.0000', '100000.0000');
        $po = $setup['po'];
        $grn = $setup['grn'];
        $pol = $po->lines()->firstOrFail();
        $grnl = $grn->lines()->firstOrFail();

        // Pemasok mengirim faktur dengan harga satuan Rp 110.000 (selisih Rp 10.000 / unit)
        $invoice = $this->invoiceService->createSupplierInvoice(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'purchase_order_id' => $po->id,
                'supplier_invoice_number' => 'INV-PRICE-DIFF-01',
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(14)->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'purchase_order_line_id' => $pol->id,
                    'invoiced_qty' => '10.0000',
                    'unit_price' => '110000.0000', // Selisih harga naik!
                ],
            ],
            $this->accountantUser
        );

        $invLine = $invoice->lines()->firstOrFail();

        // Jalankan 3-Way Match
        $matchedInvoice = $this->invoiceService->performThreeWayMatching(
            $invoice,
            [
                [
                    'supplier_invoice_line_id' => $invLine->id,
                    'goods_received_note_line_id' => $grnl->id,
                    'matched_qty' => '10.0000',
                ],
            ],
            $this->accountantUser
        );

        // Asersi faktur tertahan
        $this->assertEquals(SupplierInvoiceStatusEnum::VarianceHold, $matchedInvoice->status);
        $this->assertEquals(InvoiceMatchingStatusEnum::VarianceDetected, $matchedInvoice->matching_status);
        $this->assertNotNull($matchedInvoice->hold_reason);

        // Percobaan menyetujui kewajiban siap bayar saat masih tertahan varians wajib ditolak
        try {
            $this->invoiceService->approvePayable($matchedInvoice, $this->accountantUser);
            $this->fail('Harus melempar DomainException karena faktur tertahan status variance_hold.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Variance Hold', $e->getMessage());
        }

        // Pejabat berwenang (Owner) menyetujui selisih varians dengan catatan justifikasi
        $releasedInvoice = $this->invoiceService->approveVariance(
            $matchedInvoice,
            $this->ownerUser,
            'Selisih kenaikan harga Rp 10.000 telah dinegosiasikan dan disetujui karena inflasi daging impor.'
        );

        $this->assertEquals(SupplierInvoiceStatusEnum::Submitted, $releasedInvoice->status);
        $this->assertEquals(InvoiceMatchingStatusEnum::Matched, $releasedInvoice->matching_status);
        $this->assertEquals($this->ownerUser->id, $releasedInvoice->hold_approved_by_user_id);
        $this->assertNotNull($releasedInvoice->hold_approved_at);

        // Sekarang approvePayable berhasil
        $approvedInvoice = $this->invoiceService->approvePayable($releasedInvoice, $this->accountantUser);
        $this->assertEquals(SupplierInvoiceStatusEnum::ApprovedPayable, $approvedInvoice->status);
    }

    /**
     * AT-10: Selisih kuantitas (Quantity Variance) menahan faktur ke status variance_hold.
     */
    public function test_quantity_variance_triggers_variance_hold(): void
    {
        // PO dan GRN hanya menerima 5 item
        $setup = $this->setupReleasedPoAndPostedGrn('5.0000', '100000.0000');
        $po = $setup['po'];
        $grn = $setup['grn'];
        $pol = $po->lines()->firstOrFail();
        $grnl = $grn->lines()->firstOrFail();

        // Faktur pemasok menagih 8 item (melebihi kuantitas GRN yang baru diterima 5)
        $invoice = $this->invoiceService->createSupplierInvoice(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'purchase_order_id' => $po->id,
                'supplier_invoice_number' => 'INV-QTY-DIFF-01',
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(14)->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'purchase_order_line_id' => $pol->id,
                    'invoiced_qty' => '8.0000',
                    'unit_price' => '100000.0000',
                ],
            ],
            $this->accountantUser
        );

        $invLine = $invoice->lines()->firstOrFail();

        // Jalankan 3-Way Match dengan kuantitas tagihan 8 terhadap GRN yang hanya 5
        $matchedInvoice = $this->invoiceService->performThreeWayMatching(
            $invoice,
            [
                [
                    'supplier_invoice_line_id' => $invLine->id,
                    'goods_received_note_line_id' => $grnl->id,
                    'matched_qty' => '8.0000',
                ],
            ],
            $this->accountantUser
        );

        $this->assertEquals(SupplierInvoiceStatusEnum::VarianceHold, $matchedInvoice->status);
        $this->assertEquals(InvoiceMatchingStatusEnum::VarianceDetected, $matchedInvoice->matching_status);

        $matchRecord = $matchedInvoice->grnMatches()->firstOrFail();
        $this->assertEquals('quantity_variance', $matchRecord->variance_status);
        $this->assertEquals('3.0000', $matchRecord->qty_variance);
    }
}
