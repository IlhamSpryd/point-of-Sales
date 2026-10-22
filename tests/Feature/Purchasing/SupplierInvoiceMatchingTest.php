<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Core\Context\TenantContext;
use App\Enums\InvoiceMatchingStatusEnum;
use App\Enums\PoInvoicingStatusEnum;
use App\Enums\SupplierInvoiceStatusEnum;
use App\Enums\SupplierStatusEnum;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Purchasing\Models\GoodsReceivedNote;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\GoodsReceivedNoteService;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\SupplierInvoiceMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierInvoiceMatchingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $ownerUser;

    private User $accountantUser;

    private Supplier $supplier;

    private Product $product;

    private Ingredient $ingredient;

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
            'name' => 'Accounting Officer',
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
        $this->invoiceService = app(SupplierInvoiceMatchingService::class);
    }

    /**
     * Membantu membuat PO dan GRN yang telah diterima penuh.
     *
     * @return array{po: PurchaseOrder, grn: GoodsReceivedNote}
     */
    private function setupReleasedPoAndPostedGrn(): array
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
                    'ordered_qty' => '10.0000',
                    'unit_price' => '80000.0000',
                ],
                [
                    'purchasable_type' => 'ingredient',
                    'purchasable_id' => $this->ingredient->id,
                    'purchase_uom' => 'KG',
                    'ordered_qty' => '20.0000',
                    'unit_price' => '14500.0000',
                ],
            ],
            $this->ownerUser
        );

        $po = $this->poService->submitPurchaseOrder($po, $this->ownerUser);
        $po = $this->poService->releasePurchaseOrder($po, $this->ownerUser);

        $lines = $po->lines()->orderBy('id', 'asc')->get();

        $grn = $this->grnService->createGoodsReceivedNote(
            $po,
            ['supplier_delivery_note_no' => 'SJ-MATCH-001'],
            [
                [
                    'purchase_order_line_id' => $lines[0]->id,
                    'received_qty' => '10.0000',
                    'accepted_qty' => '10.0000',
                    'rejected_qty' => '0.0000',
                ],
                [
                    'purchase_order_line_id' => $lines[1]->id,
                    'received_qty' => '20.0000',
                    'accepted_qty' => '20.0000',
                    'rejected_qty' => '0.0000',
                ],
            ],
            $this->ownerUser
        );

        $postedGrn = $this->grnService->postGrn($grn, $this->ownerUser);

        return ['po' => $po->fresh(), 'grn' => $postedGrn];
    }

    /**
     * AT-08: Penagihan faktur pemasok secara parsial dan bertahap memperbarui
     * remaining_invoiceable_qty dan status invoicing_status PO.
     */
    public function test_partial_supplier_invoicing_updates_po_accumulators_and_status(): void
    {
        $setup = $this->setupReleasedPoAndPostedGrn();
        $po = $setup['po'];
        $grn = $setup['grn'];

        $this->assertEquals(PoInvoicingStatusEnum::NotInvoiced, $po->invoicing_status);

        $poLines = $po->lines()->orderBy('id', 'asc')->get();
        $pol1 = $poLines[0];
        $pol2 = $poLines[1];

        $grnLines = $grn->lines()->orderBy('id', 'asc')->get();
        $grnl1 = $grnLines[0];
        $grnl2 = $grnLines[1];

        // 1. Faktur Parsial Pertama: Menagih Line 1 penuh (10 BAG) dan Line 2 sebagian (10 KG)
        $inv1 = $this->invoiceService->createSupplierInvoice(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'purchase_order_id' => $po->id,
                'supplier_invoice_number' => 'INV-PARTIAL-01',
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(14)->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'purchase_order_line_id' => $pol1->id,
                    'invoiced_qty' => '10.0000',
                    'unit_price' => '80000.0000',
                ],
                [
                    'purchasable_type' => 'ingredient',
                    'purchasable_id' => $this->ingredient->id,
                    'purchase_order_line_id' => $pol2->id,
                    'invoiced_qty' => '10.0000',
                    'unit_price' => '14500.0000',
                ],
            ],
            $this->accountantUser
        );

        $inv1Lines = $inv1->lines()->orderBy('id', 'asc')->get();

        // Eksekusi 3-Way Match untuk Faktur 1
        $matchedInv1 = $this->invoiceService->performThreeWayMatching(
            $inv1,
            [
                [
                    'supplier_invoice_line_id' => $inv1Lines[0]->id,
                    'goods_received_note_line_id' => $grnl1->id,
                    'matched_qty' => '10.0000',
                ],
                [
                    'supplier_invoice_line_id' => $inv1Lines[1]->id,
                    'goods_received_note_line_id' => $grnl2->id,
                    'matched_qty' => '10.0000',
                ],
            ],
            $this->accountantUser
        );

        $this->assertEquals(InvoiceMatchingStatusEnum::Matched, $matchedInv1->matching_status);

        // Approve Payable untuk Faktur 1
        $approvedInv1 = $this->invoiceService->approvePayable($matchedInv1, $this->accountantUser);
        $this->assertEquals(SupplierInvoiceStatusEnum::ApprovedPayable, $approvedInv1->status);

        // Verifikasi PO setelah Faktur 1: Status menjadi Partially Invoiced
        $po->refresh();
        $this->assertEquals(PoInvoicingStatusEnum::PartiallyInvoiced, $po->invoicing_status);

        $pol1->refresh();
        $pol2->refresh();
        $this->assertEquals('10.0000', $pol1->invoiced_qty);
        $this->assertEquals('0.0000', $pol1->getRemainingInvoiceableQty());
        $this->assertEquals('10.0000', $pol2->invoiced_qty);
        $this->assertEquals('10.0000', $pol2->getRemainingInvoiceableQty());

        // 2. Faktur Parsial Kedua: Menagih sisa Line 2 (10 KG)
        $inv2 = $this->invoiceService->createSupplierInvoice(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'purchase_order_id' => $po->id,
                'supplier_invoice_number' => 'INV-PARTIAL-02',
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(14)->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'ingredient',
                    'purchasable_id' => $this->ingredient->id,
                    'purchase_order_line_id' => $pol2->id,
                    'invoiced_qty' => '10.0000',
                    'unit_price' => '14500.0000',
                ],
            ],
            $this->accountantUser
        );

        $inv2Lines = $inv2->lines()->orderBy('id', 'asc')->get();

        // Eksekusi 3-Way Match untuk Faktur 2
        $matchedInv2 = $this->invoiceService->performThreeWayMatching(
            $inv2,
            [
                [
                    'supplier_invoice_line_id' => $inv2Lines[0]->id,
                    'goods_received_note_line_id' => $grnl2->id,
                    'matched_qty' => '10.0000',
                ],
            ],
            $this->accountantUser
        );

        $this->assertEquals(InvoiceMatchingStatusEnum::Matched, $matchedInv2->matching_status);

        // Approve Payable untuk Faktur 2
        $approvedInv2 = $this->invoiceService->approvePayable($matchedInv2, $this->accountantUser);
        $this->assertEquals(SupplierInvoiceStatusEnum::ApprovedPayable, $approvedInv2->status);

        // Verifikasi PO setelah Faktur 2: Status menjadi Fully Invoiced
        $po->refresh();
        $this->assertEquals(PoInvoicingStatusEnum::FullyInvoiced, $po->invoicing_status);

        $pol2->refresh();
        $this->assertEquals('20.0000', $pol2->invoiced_qty);
        $this->assertEquals('0.0000', $pol2->getRemainingInvoiceableQty());
    }
}
