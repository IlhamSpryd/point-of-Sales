<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Enums\PurchaseOrderSourceTypeEnum;
use App\Enums\PurchaseOrderStatusEnum;
use App\Enums\PurchaseRequestFulfillmentStatusEnum;
use App\Enums\PurchaseRequestStatusEnum;
use App\Enums\SupplierStatusEnum;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderRevision;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseRequestService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PrToPoConversionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $ownerUser;

    private User $staffUser;

    private Supplier $supplier;

    private Product $product;

    private Ingredient $ingredient;

    private PurchaseRequestService $prService;

    private PurchaseOrderService $poService;

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

        $staffRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Staff Purchasing',
            'is_admin' => false,
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

        $this->staffUser = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $staffRole->id,
            'name' => 'Andi Staff',
            'email' => 'andi.staff@test.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->ownerUser->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);
        $this->staffUser->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);

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

        $this->prService = app(PurchaseRequestService::class);
        $this->poService = app(PurchaseOrderService::class);
    }

    /**
     * AT-04: PR yang approved dapat dikonversi menjadi PO secara parsial dan penuh.
     */
    public function test_pr_to_po_partial_and_full_conversion_lifecycle(): void
    {
        // 1. Buat PR dengan 2 baris
        $pr = $this->prService->createPurchaseRequest(
            [
                'store_id' => $this->store->id,
                'required_by_date' => now()->addDays(5)->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'requested_qty' => '10.0000',
                    'uom' => 'BAG',
                    'estimated_unit_price' => '80000.0000',
                ],
                [
                    'purchasable_type' => 'ingredient',
                    'purchasable_id' => $this->ingredient->id,
                    'requested_qty' => '50.0000',
                    'uom' => 'KG',
                    'estimated_unit_price' => '14000.0000',
                ],
            ],
            $this->staffUser
        );

        // Submit PR (Auto-approved karena belum ada aturan batas)
        $submittedPr = $this->prService->submitPurchaseRequest($pr, $this->staffUser);
        $this->assertSame(PurchaseRequestStatusEnum::Approved, $submittedPr->status);
        $this->assertSame(PurchaseRequestFulfillmentStatusEnum::Unfulfilled, $submittedPr->fulfillment_status);

        $line1 = $submittedPr->lines->where('purchasable_type', 'product')->firstOrFail();
        $line2 = $submittedPr->lines->where('purchasable_type', 'ingredient')->firstOrFail();

        $this->assertSame('10.0000', $line1->remainingOrderableQty());
        $this->assertSame('50.0000', $line2->remainingOrderableQty());

        // 2. Konversi Parsial Tahap 1: Buat PO-1 untuk sebagian kuantitas
        // Line 1: 4 dari 10. Line 2: 20 dari 50.
        $po1 = $this->poService->createFromPurchaseRequests(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'order_date' => now()->toDateString(),
            ],
            [
                [
                    'purchase_request_line_id' => $line1->id,
                    'ordered_qty' => '4.0000',
                    'unit_price' => '78000.0000',
                    'discount_pct' => '5.0000', // Diskon 5%
                    'tax_rate' => '11.0000', // PPN 11%
                ],
                [
                    'purchase_request_line_id' => $line2->id,
                    'ordered_qty' => '20.0000',
                    'unit_price' => '14000.0000',
                    'tax_rate' => '0.0000',
                ],
            ],
            $this->staffUser
        );

        $this->assertInstanceOf(PurchaseOrder::class, $po1);
        $this->assertSame(PurchaseOrderSourceTypeEnum::FromPr, $po1->source_type);
        $this->assertSame(PurchaseOrderStatusEnum::Draft, $po1->status);

        // Verifikasi kalkulasi matematis BCMath baris 1:
        // Gross: 4 * 78,000 = 312,000.00
        // Diskon 5%: 312,000 * 0.05 = 15,600.00
        // Taxable Base: 312,000 - 15,600 = 296,400.00
        // Tax 11%: 296,400 * 0.11 = 32,604.00
        // Line Total: 296,400 + 32,604 = 329,004.00
        $poLine1 = $po1->lines->firstOrFail();
        $this->assertSame('312000.00', $poLine1->unit_price ? bcmul($poLine1->ordered_qty, $poLine1->unit_price, 2) : '0.00');
        $this->assertSame('15600.00', $poLine1->discount_amount);
        $this->assertSame('32604.00', $poLine1->tax_amount);
        $this->assertSame('329004.00', $poLine1->line_total);

        // Verifikasi sisa kuantitas pada PR dan transisi fulfillment status
        $line1->refresh();
        $line2->refresh();
        $submittedPr->refresh();

        $this->assertSame('4.0000', $line1->converted_to_po_qty);
        $this->assertSame('6.0000', $line1->remainingOrderableQty());
        $this->assertSame('20.0000', $line2->converted_to_po_qty);
        $this->assertSame('30.0000', $line2->remainingOrderableQty());

        // Status PR menjadi PartiallyOrdered
        $this->assertSame(PurchaseRequestFulfillmentStatusEnum::PartiallyOrdered, $submittedPr->fulfillment_status);

        // 3. Konversi Penuh Tahap 2: Buat PO-2 untuk sisa seluruh kuantitas
        $po2 = $this->poService->createFromPurchaseRequests(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
            ],
            [
                [
                    'purchase_request_line_id' => $line1->id,
                    'ordered_qty' => '6.0000',
                    'unit_price' => '78000.0000',
                ],
                [
                    'purchase_request_line_id' => $line2->id,
                    'ordered_qty' => '30.0000',
                    'unit_price' => '14000.0000',
                ],
            ],
            $this->staffUser
        );

        $line1->refresh();
        $line2->refresh();
        $submittedPr->refresh();

        $this->assertSame('10.0000', $line1->converted_to_po_qty);
        $this->assertSame('0.0000', $line1->remainingOrderableQty());
        $this->assertSame('50.0000', $line2->converted_to_po_qty);
        $this->assertSame('0.0000', $line2->remainingOrderableQty());

        // Status PR beralih menjadi FullyOrdered
        $this->assertSame(PurchaseRequestFulfillmentStatusEnum::FullyOrdered, $submittedPr->fulfillment_status);

        // 4. Percobaan alokasi berlebih harus ditolak
        try {
            $this->poService->createFromPurchaseRequests(
                [
                    'store_id' => $this->store->id,
                    'supplier_id' => $this->supplier->id,
                ],
                [
                    [
                        'purchase_request_line_id' => $line1->id,
                        'ordered_qty' => '1.0000',
                        'unit_price' => '78000.0000',
                    ],
                ],
                $this->staffUser
            );
            $this->fail('Harus gagal validasi karena kuantitas sisa sudah 0.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allocations.0.ordered_qty', $e->validator->getMessageBag()->toArray());
        }

        // 5. Pembatalan PO-2 harus mengembalikan hak sisa kuantitas pada PR
        $this->poService->cancelPurchaseOrder($po2, $this->staffUser, 'Pembatalan pesanan kedua.');
        $this->assertSame(PurchaseOrderStatusEnum::Cancelled, $po2->fresh()->status);

        $line1->refresh();
        $line2->refresh();
        $submittedPr->refresh();

        $this->assertSame('4.0000', $line1->converted_to_po_qty);
        $this->assertSame('6.0000', $line1->remainingOrderableQty());
        $this->assertSame('20.0000', $line2->converted_to_po_qty);
        $this->assertSame('30.0000', $line2->remainingOrderableQty());
        $this->assertSame(PurchaseRequestFulfillmentStatusEnum::PartiallyOrdered, $submittedPr->fulfillment_status);
    }

    /**
     * Pengujian amandemen PO yang telah dirilis (Revisi & Snapshot).
     */
    public function test_purchase_order_amendment_creates_snapshot_and_increments_revision(): void
    {
        // Buat Direct PO
        $po = $this->poService->createDirectPurchaseOrder(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'purchase_uom' => 'BAG',
                    'ordered_qty' => '10.0000',
                    'unit_price' => '80000.0000',
                ],
            ],
            $this->ownerUser
        );

        // Submit -> Auto-approved
        $this->poService->submitPurchaseOrder($po, $this->ownerUser);
        $this->assertSame(PurchaseOrderStatusEnum::Approved, $po->fresh()->status);

        // Release PO
        $this->poService->releasePurchaseOrder($po, $this->ownerUser);
        $this->assertSame(PurchaseOrderStatusEnum::Released, $po->fresh()->status);
        $this->assertSame(0, $po->revision_number);

        $poLine = $po->lines->firstOrFail();

        // Lakukan amandemen: Tambah kuantitas menjadi 15 dan ubah harga menjadi 75,000
        $amendedPo = $this->poService->amendPurchaseOrder(
            $po,
            [
                [
                    'id' => $poLine->id,
                    'ordered_qty' => '15.0000',
                    'unit_price' => '75000.0000',
                ],
            ],
            'Penambahan kuantitas sesuai permintaan promo mingguan',
            $this->ownerUser
        );

        $this->assertSame(1, $amendedPo->revision_number);
        $this->assertSame('1125000.00', $amendedPo->total_amount);

        // Verifikasi snapshot pada purchase_order_revisions
        $revision = PurchaseOrderRevision::query()
            ->where('purchase_order_id', $po->id)
            ->where('revision_number', 1)
            ->first();

        $this->assertNotNull($revision);
        $this->assertSame('800000.00', $revision->total_amount_before);
        $this->assertSame('1125000.00', $revision->total_amount_after);
        $this->assertSame('Penambahan kuantitas sesuai permintaan promo mingguan', $revision->reason);
        $this->assertSame($this->ownerUser->id, $revision->amended_by_user_id);
    }
}
