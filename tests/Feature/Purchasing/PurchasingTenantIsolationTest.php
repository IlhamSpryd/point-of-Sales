<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Livewire\Purchasing\SupplierManager;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Purchasing\Models\GoodsReceivedNote;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\SupplierInvoice;
use App\Modules\Purchasing\Services\GoodsReceivedNoteService;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseRequestService;
use App\Modules\Purchasing\Services\PurchaseReturnService;
use App\Modules\Purchasing\Services\SupplierInvoiceMatchingService;
use App\Modules\Purchasing\Services\SupplierService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PurchasingTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Store $storeA;

    private Store $storeB;

    private User $userA;

    private User $userB;

    private SupplierService $service;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenantA = Tenant::create([
            'name' => 'Tenant Alpha',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        $this->tenantB = Tenant::create([
            'name' => 'Tenant Beta',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        // Setup Tenant A resources
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        $this->storeA = Store::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Store Alpha Central',
            'status' => 'active',
        ]);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        $roleA = Role::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Owner',
            'display_name' => 'Owner Alpha',
            'permissions' => ['*'],
        ]);

        $this->userA = User::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Owner Alpha User',
            'email' => 'owner@alpha.test',
            'password' => bcrypt('password123'),
            'role_id' => $roleA->id,
            'is_active' => true,
        ]);

        // Setup Tenant B resources
        app(TenantContext::class)->setTenantId($this->tenantB->id);
        $this->storeB = Store::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Store Beta Central',
            'status' => 'active',
        ]);
        app(TenantContext::class)->setStoreId($this->storeB->id);

        $roleB = Role::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Owner',
            'display_name' => 'Owner Beta',
            'permissions' => ['*'],
        ]);

        $this->userB = User::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Owner Beta User',
            'email' => 'owner@beta.test',
            'password' => bcrypt('password123'),
            'role_id' => $roleB->id,
            'is_active' => true,
        ]);

        $this->service = app(SupplierService::class);
    }

    public function test_same_supplier_code_can_exist_in_different_tenants(): void
    {
        // Tenant A creates SUP-0001
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        $suppA = $this->service->createSupplier([
            'code' => 'SUP-0001',
            'name' => 'Vendor Alpha',
        ], $this->userA);

        $this->assertSame('SUP-0001', $suppA->code);
        $this->assertSame((int) $this->tenantA->id, (int) $suppA->tenant_id);

        // Tenant B creates SUP-0001
        app(TenantContext::class)->setTenantId($this->tenantB->id);
        app(TenantContext::class)->setStoreId($this->storeB->id);

        $suppB = $this->service->createSupplier([
            'code' => 'SUP-0001',
            'name' => 'Vendor Beta',
        ], $this->userB);

        $this->assertSame('SUP-0001', $suppB->code);
        $this->assertSame((int) $this->tenantB->id, (int) $suppB->tenant_id);

        // Assert both coexist independently in DB
        $this->assertDatabaseHas('suppliers', [
            'id' => $suppA->id,
            'tenant_id' => $this->tenantA->id,
            'code' => 'SUP-0001',
        ]);
        $this->assertDatabaseHas('suppliers', [
            'id' => $suppB->id,
            'tenant_id' => $this->tenantB->id,
            'code' => 'SUP-0001',
        ]);
    }

    public function test_tenant_a_cannot_view_or_query_tenant_b_suppliers(): void
    {
        // Create supplier in Tenant B
        app(TenantContext::class)->setTenantId($this->tenantB->id);
        app(TenantContext::class)->setStoreId($this->storeB->id);

        $suppB = $this->service->createSupplier([
            'name' => 'Vendor Rahasia Beta',
        ], $this->userB);

        // Switch to Tenant A context
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        // Querying Supplier model in Tenant A context should not return Tenant B supplier
        $found = Supplier::query()->find($suppB->id);
        $this->assertNull($found);

        $allSuppliers = Supplier::query()->get();
        $this->assertFalse($allSuppliers->contains('id', $suppB->id));

        // Route binding resolution should return null
        $model = new Supplier;
        $resolved = $model->resolveRouteBinding($suppB->id);
        $this->assertNull($resolved);
    }

    public function test_tenant_a_user_updating_tenant_b_supplier_throws_exception(): void
    {
        // Create supplier in Tenant B
        app(TenantContext::class)->setTenantId($this->tenantB->id);
        app(TenantContext::class)->setStoreId($this->storeB->id);

        $suppB = $this->service->createSupplier([
            'name' => 'Vendor Beta Asli',
        ], $this->userB);

        // Switch to Tenant A context and try to update suppB
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Pemasok tidak ditemukan pada tenant aktif.');

        $this->service->updateSupplier($suppB, [
            'name' => 'Manipulasi Ilegal',
        ], $this->userA);
    }

    public function test_livewire_supplier_manager_blocks_cross_tenant_manipulation(): void
    {
        // Create supplier in Tenant B
        app(TenantContext::class)->setTenantId($this->tenantB->id);
        app(TenantContext::class)->setStoreId($this->storeB->id);

        $suppB = $this->service->createSupplier([
            'name' => 'Vendor Beta Exclusive',
        ], $this->userB);

        // Switch to Tenant A
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        // User A opens Livewire and attempts to edit/access Tenant B's supplier
        Livewire::actingAs($this->userA)
            ->test(SupplierManager::class)
            ->assertDontSee('Vendor Beta Exclusive')
            ->call('edit', $suppB->id)
            ->assertNotFound();
    }

    public function test_cross_tenant_purchase_request_isolation(): void
    {
        // Setup Product in Tenant B
        app(TenantContext::class)->setTenantId($this->tenantB->id);
        app(TenantContext::class)->setStoreId($this->storeB->id);

        $categoryB = Category::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Kategori Beta',
            'slug' => 'kategori-beta',
        ]);

        $prodB = Product::create([
            'tenant_id' => $this->tenantB->id,
            'category_id' => $categoryB->id,
            'name' => 'Produk Beta',
            'sku' => 'SKU-B-01',
            'cost_price' => 10000,
            'selling_price' => 15000,
            'status' => 'active',
            'is_active' => true,
        ]);

        $prService = app(PurchaseRequestService::class);
        $prB = $prService->createPurchaseRequest(
            ['store_id' => $this->storeB->id],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $prodB->id,
                    'requested_qty' => '5.0000',
                    'uom' => 'PCS',
                    'estimated_unit_price' => '10000.0000',
                ],
            ],
            $this->userB
        );

        // Switch to Tenant A
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        // 1. Eloquent ScopedToTenant blocks viewing
        $foundPr = PurchaseRequest::query()->find($prB->id);
        $this->assertNull($foundPr);

        // 2. Route binding resolution blocks access
        $model = new PurchaseRequest;
        $resolved = $model->resolveRouteBinding($prB->id);
        $this->assertNull($resolved);

        // 3. Gate Policy blocks view & approve
        $this->assertFalse(Gate::forUser($this->userA)->allows('view', $prB));
        $this->assertFalse(Gate::forUser($this->userA)->allows('approve', $prB));
        $this->assertFalse(Gate::forUser($this->userA)->allows('cancel', $prB));
    }

    public function test_cross_tenant_purchase_order_isolation(): void
    {
        // 1. Setup Master & PO in Tenant B
        app(TenantContext::class)->setTenantId($this->tenantB->id);
        app(TenantContext::class)->setStoreId($this->storeB->id);

        $suppB = $this->service->createSupplier([
            'code' => 'SUP-B-ISOLATION',
            'name' => 'Supplier Beta PO',
        ], $this->userB);

        $categoryB = Category::create([
            'tenant_id' => $this->tenantB->id,
            'category_name' => 'Kategori Beta PO',
            'category_code' => 'CAT-B-PO',
        ]);

        $prodB = Product::create([
            'tenant_id' => $this->tenantB->id,
            'category_id' => $categoryB->id,
            'name' => 'Produk Beta PO',
            'sku' => 'SKU-B-PO-01',
            'cost_price' => 10000,
            'selling_price' => 15000,
            'status' => 'active',
            'is_active' => true,
        ]);

        $poService = app(PurchaseOrderService::class);
        $poB = $poService->createDirectPurchaseOrder(
            [
                'store_id' => $this->storeB->id,
                'supplier_id' => $suppB->id,
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $prodB->id,
                    'purchase_uom' => 'PCS',
                    'ordered_qty' => '10.0000',
                    'unit_price' => '10000.0000',
                ],
            ],
            $this->userB
        );

        // Switch to Tenant A
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        // 2. Eloquent ScopedToTenant blocks viewing
        $foundPo = PurchaseOrder::query()->find($poB->id);
        $this->assertNull($foundPo);

        // 3. Route binding resolution blocks access
        $model = new PurchaseOrder;
        $resolved = $model->resolveRouteBinding($poB->id);
        $this->assertNull($resolved);

        // 4. Gate Policy blocks view, release, amend, cancel
        $this->assertFalse(Gate::forUser($this->userA)->allows('view', $poB));
        $this->assertFalse(Gate::forUser($this->userA)->allows('release', $poB));
        $this->assertFalse(Gate::forUser($this->userA)->allows('amend', $poB));
        $this->assertFalse(Gate::forUser($this->userA)->allows('cancel', $poB));

        // 5. Cross-tenant supplier injection: User A attempting to create PO referencing Tenant B's supplier must fail
        $categoryA = Category::create([
            'tenant_id' => $this->tenantA->id,
            'category_name' => 'Kategori Alpha PO',
            'category_code' => 'CAT-A-PO',
        ]);

        $prodA = Product::create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $categoryA->id,
            'name' => 'Produk Alpha',
            'sku' => 'SKU-A-01',
            'cost_price' => 5000,
            'selling_price' => 10000,
            'status' => 'active',
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);
        $poService->createDirectPurchaseOrder(
            [
                'store_id' => $this->storeA->id,
                'supplier_id' => $suppB->id, // Pemasok milik Tenant B!
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $prodA->id,
                    'purchase_uom' => 'PCS',
                    'ordered_qty' => '5.0000',
                    'unit_price' => '5000.0000',
                ],
            ],
            $this->userA
        );
    }

    public function test_cross_tenant_grn_isolation(): void
    {
        // Setup PO & GRN in Tenant B
        app(TenantContext::class)->setTenantId($this->tenantB->id);
        app(TenantContext::class)->setStoreId($this->storeB->id);

        $suppB = $this->service->createSupplier([
            'name' => 'Supplier Beta GRN',
        ], $this->userB);

        $categoryB = Category::create([
            'tenant_id' => $this->tenantB->id,
            'category_name' => 'Kategori Beta GRN',
            'category_code' => 'CAT-B-GRN',
        ]);

        $prodB = Product::create([
            'tenant_id' => $this->tenantB->id,
            'category_id' => $categoryB->id,
            'name' => 'Produk Beta GRN',
            'sku' => 'SKU-B-GRN-01',
            'cost_price' => 10000,
            'selling_price' => 15000,
            'status' => 'active',
            'is_active' => true,
        ]);

        $poService = app(PurchaseOrderService::class);
        $grnService = app(GoodsReceivedNoteService::class);

        $poB = $poService->createDirectPurchaseOrder(
            [
                'store_id' => $this->storeB->id,
                'supplier_id' => $suppB->id,
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $prodB->id,
                    'purchase_uom' => 'PCS',
                    'ordered_qty' => '10.0000',
                    'unit_price' => '10000.0000',
                ],
            ],
            $this->userB
        );

        $poB = $poService->submitPurchaseOrder($poB, $this->userB);
        $poB = $poService->releasePurchaseOrder($poB, $this->userB);

        $polB = $poB->lines()->firstOrFail();

        $grnB = $grnService->createGoodsReceivedNote(
            $poB,
            ['supplier_delivery_note_no' => 'SJ-BETA-001'],
            [
                [
                    'purchase_order_line_id' => $polB->id,
                    'received_qty' => '10.0000',
                    'accepted_qty' => '10.0000',
                    'rejected_qty' => '0.0000',
                ],
            ],
            $this->userB
        );

        // Switch to Tenant A
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        // 1. ScopedToTenant ensures find returns null for Tenant A
        $this->assertNull(GoodsReceivedNote::find($grnB->id));

        // 2. Policy forbids User A from viewing Tenant B's GRN
        $this->assertFalse(Gate::forUser($this->userA)->allows('view', $grnB));

        // 3. User A cannot post Tenant B's GRN
        $this->assertFalse(Gate::forUser($this->userA)->allows('post', $grnB));
    }

    public function test_cross_tenant_supplier_invoice_isolation(): void
    {
        // Setup Invoice in Tenant B
        app(TenantContext::class)->setTenantId($this->tenantB->id);
        app(TenantContext::class)->setStoreId($this->storeB->id);

        $suppB = $this->service->createSupplier([
            'name' => 'Supplier Beta Inv',
        ], $this->userB);

        $categoryB = Category::create([
            'tenant_id' => $this->tenantB->id,
            'category_name' => 'Kategori Beta Inv',
            'category_code' => 'CAT-B-INV',
        ]);

        $prodB = Product::create([
            'tenant_id' => $this->tenantB->id,
            'category_id' => $categoryB->id,
            'name' => 'Produk Beta Inv',
            'sku' => 'SKU-B-INV-01',
            'cost_price' => 10000,
            'selling_price' => 15000,
            'status' => 'active',
            'is_active' => true,
        ]);

        $invoiceService = app(SupplierInvoiceMatchingService::class);

        $invB = $invoiceService->createSupplierInvoice(
            [
                'store_id' => $this->storeB->id,
                'supplier_id' => $suppB->id,
                'supplier_invoice_number' => 'INV-BETA-SEC-01',
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(14)->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $prodB->id,
                    'invoiced_qty' => '10.0000',
                    'unit_price' => '10000.0000',
                ],
            ],
            $this->userB
        );

        // Switch to Tenant A
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        // 1. ScopedToTenant ensures find returns null for Tenant A
        $this->assertNull(SupplierInvoice::find($invB->id));

        // 2. Policy forbids User A from viewing Tenant B's invoice
        $this->assertFalse(Gate::forUser($this->userA)->allows('view', $invB));

        // 3. User A cannot approve payable on Tenant B's invoice
        $this->assertFalse(Gate::forUser($this->userA)->allows('approvePayable', $invB));
    }

    public function test_cross_tenant_purchase_return_isolation(): void
    {
        // 1. Create a PurchaseReturn in Tenant B
        app(TenantContext::class)->setTenantId($this->tenantB->id);
        app(TenantContext::class)->setStoreId($this->storeB->id);

        $suppB = Supplier::create([
            'tenant_id' => $this->tenantB->id,
            'code' => 'SUP-RET-B',
            'name' => 'Supplier Ret B',
            'status' => 'active',
            'payment_terms_days' => 30,
            'currency_code' => 'IDR',
        ]);

        $poB = app(PurchaseOrderService::class)->createDirectPurchaseOrder(
            [
                'store_id' => $this->storeB->id,
                'supplier_id' => $suppB->id,
                'order_date' => now()->toDateString(),
                'expected_delivery_date' => now()->addDays(5)->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => Product::create([
                        'tenant_id' => $this->tenantB->id,
                        'category_id' => Category::create(['tenant_id' => $this->tenantB->id, 'name' => 'Cat B Ret', 'slug' => 'cat-b-ret'])->id,
                        'name' => 'Barang Ret B',
                        'sku' => 'PRD-RET-B',
                        'price' => '20000.00',
                    ])->id,
                    'purchase_uom' => 'PCS',
                    'uom_conversion_factor' => '1.000000',
                    'ordered_qty' => '10.0000',
                    'unit_price' => '15000.0000',
                ],
            ],
            $this->userB
        );
        $poB = app(PurchaseOrderService::class)->submitPurchaseOrder($poB, $this->userB);
        $poB = app(PurchaseOrderService::class)->releasePurchaseOrder($poB, $this->userB);

        $grnB = app(GoodsReceivedNoteService::class)->createGoodsReceivedNote(
            $poB,
            [
                'received_at' => now()->toDateTimeString(),
            ],
            [
                [
                    'purchase_order_line_id' => $poB->lines()->firstOrFail()->id,
                    'received_qty' => '10.0000',
                    'accepted_qty' => '10.0000',
                    'rejected_qty' => '0.0000',
                ],
            ],
            $this->userB
        );
        app(GoodsReceivedNoteService::class)->postGrn($grnB, $this->userB);

        $returnB = app(PurchaseReturnService::class)->createPurchaseReturn(
            [
                'store_id' => $this->storeB->id,
                'supplier_id' => $suppB->id,
                'goods_received_note_id' => $grnB->id,
                'reason' => 'Barang retur B cacat pabrik',
            ],
            [
                [
                    'goods_received_note_line_id' => $grnB->lines->first()->id,
                    'return_qty' => '4.0000',
                ],
            ],
            $this->userB
        );

        // Switch to Tenant A
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        // 1. ScopedToTenant ensures find returns null for Tenant A
        $this->assertNull(PurchaseReturn::find($returnB->id));

        // 2. Policy forbids User A from viewing Tenant B's return
        $this->assertFalse(Gate::forUser($this->userA)->allows('view', $returnB));

        // 3. Policy forbids User A from posting Tenant B's return
        $this->assertFalse(Gate::forUser($this->userA)->allows('post', $returnB));
    }
}
