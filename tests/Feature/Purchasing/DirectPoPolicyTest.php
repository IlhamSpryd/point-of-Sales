<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Enums\PurchaseOrderSourceTypeEnum;
use App\Enums\PurchaseOrderStatusEnum;
use App\Enums\SupplierStatusEnum;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DirectPoPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $ownerUser;

    private User $purchasingOfficer;

    private User $cashierUser;

    private Supplier $activeSupplier;

    private Supplier $onHoldSupplier;

    private Product $product;

    private PurchaseOrderService $poService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Kopi Nusantara Group',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Roastery Senopati',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $ownerRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'permissions' => ['*'],
            'is_admin' => true,
        ]);

        $purchasingRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Purchasing Officer',
            'permissions' => ['purchasing.po.create_direct', 'purchasing.po.view'],
            'is_admin' => false,
        ]);

        $cashierRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cashier',
            'permissions' => ['pos.cashier'],
            'is_admin' => false,
        ]);

        $this->ownerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $ownerRole->id,
            'name' => 'Owner Kopi',
            'email' => 'owner.kopi@test.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->purchasingOfficer = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $purchasingRole->id,
            'name' => 'Bambang Purchasing',
            'email' => 'bambang.po@test.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->cashierUser = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $cashierRole->id,
            'name' => 'Siti Kasir',
            'email' => 'siti.kasir@test.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->ownerUser->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);
        $this->purchasingOfficer->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);
        $this->cashierUser->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);

        $this->activeSupplier = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'SUP-BEANS-01',
            'name' => 'Petani Kopi Aceh Gayo',
            'status' => SupplierStatusEnum::Active,
        ]);

        $this->onHoldSupplier = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'SUP-BEANS-HOLD',
            'name' => 'Supplier Bermasalah',
            'status' => SupplierStatusEnum::OnHold,
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Biji Kopi',
            'slug' => 'biji-kopi',
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'name' => 'Green Beans Gayo 1kg',
            'sku' => 'BEANS-GAYO-1K',
            'price' => '120000.00',
        ]);

        $this->poService = app(PurchaseOrderService::class);
    }

    /**
     * AT-05: Direct PO berhasil dibuat jika require_pr=false dan user memiliki izin.
     */
    public function test_direct_po_allowed_when_policy_permits_and_user_has_permission(): void
    {
        $this->assertTrue($this->purchasingOfficer->can('createDirect', PurchaseOrder::class));

        $po = $this->poService->createDirectPurchaseOrder(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->activeSupplier->id,
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'purchase_uom' => 'KG',
                    'ordered_qty' => '25.0000',
                    'unit_price' => '110000.0000',
                ],
            ],
            $this->purchasingOfficer
        );

        $this->assertInstanceOf(PurchaseOrder::class, $po);
        $this->assertSame(PurchaseOrderSourceTypeEnum::Direct, $po->source_type);
        $this->assertSame(PurchaseOrderStatusEnum::Draft, $po->status);
        $this->assertSame('2750000.00', $po->total_amount);
    }

    /**
     * AT-05: Direct PO ditolak jika user tidak memiliki izin purchasing.po.create_direct.
     */
    public function test_direct_po_denied_when_user_lacks_permission(): void
    {
        $this->assertFalse($this->cashierUser->can('createDirect', PurchaseOrder::class));

        $this->expectException(ValidationException::class);
        $this->poService->createDirectPurchaseOrder(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->activeSupplier->id,
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'purchase_uom' => 'KG',
                    'ordered_qty' => '5.0000',
                    'unit_price' => '110000.0000',
                ],
            ],
            $this->cashierUser
        );
    }

    /**
     * AT-05: Direct PO ditolak jika tenant mewajibkan PR (purchasing.po.require_pr = true).
     */
    public function test_direct_po_denied_when_tenant_setting_requires_pr(): void
    {
        // Set konfigurasi tenant require_pr = true
        Setting::query()->updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'key' => 'purchasing.po.require_pr'],
            ['value' => 'true', 'type' => 'boolean']
        );

        // Sekalipun user memegang izin purchasing.po.create_direct, policy menolak
        $this->assertFalse($this->purchasingOfficer->can('createDirect', PurchaseOrder::class));

        try {
            $this->poService->createDirectPurchaseOrder(
                [
                    'store_id' => $this->store->id,
                    'supplier_id' => $this->activeSupplier->id,
                ],
                [
                    [
                        'purchasable_type' => 'product',
                        'purchasable_id' => $this->product->id,
                        'purchase_uom' => 'KG',
                        'ordered_qty' => '10.0000',
                        'unit_price' => '110000.0000',
                    ],
                ],
                $this->purchasingOfficer
            );
            $this->fail('Harus gagal validasi kebijakan tenant require_pr.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('policy', $e->validator->getMessageBag()->toArray());
        }
    }

    /**
     * Validasi status pemasok: Pembuatan PO ditolak jika pemasok berstatus on_hold atau inactive.
     */
    public function test_purchase_order_creation_rejected_for_on_hold_supplier(): void
    {
        $this->expectException(ValidationException::class);
        $this->poService->createDirectPurchaseOrder(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->onHoldSupplier->id,
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'purchase_uom' => 'KG',
                    'ordered_qty' => '10.0000',
                    'unit_price' => '110000.0000',
                ],
            ],
            $this->ownerUser
        );
    }
}
