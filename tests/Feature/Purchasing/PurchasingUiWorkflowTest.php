<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Core\Context\TenantContext;
use App\Enums\SupplierStatusEnum;
use App\Livewire\Purchasing\GoodsReceivedNoteManager;
use App\Livewire\Purchasing\PurchaseOrderManager;
use App\Livewire\Purchasing\PurchaseReturnManager;
use App\Livewire\Purchasing\SupplierInvoiceManager;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PurchasingUiWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $owner;

    private Supplier $supplier;

    private Product $product;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Resto Maju Berkah',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store Pusat',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'is_admin' => true,
        ]);

        $this->owner = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
            'name' => 'Pak Owner',
            'email' => 'owner.purchasing@test.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->owner->stores()->attach($this->store->id, ['tenant_id' => $this->tenant->id]);

        $this->supplier = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'SUP-TEST-01',
            'name' => 'PT Pangan Segar Sejahtera',
            'status' => SupplierStatusEnum::Active,
            'payment_terms_days' => 14,
            'currency_code' => 'IDR',
        ]);

        $cat = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kategori Utama',
            'slug' => 'kategori-utama',
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $cat->id,
            'name' => 'Kopi Robusta 1kg',
            'sku' => 'PRD-ROBUSTA-1K',
            'price' => '90000.00',
            'cost_price' => '60000.00',
        ]);
    }

    public function test_purchasing_routes_return_ok_for_authorized_user(): void
    {
        $this->actingAs($this->owner)
            ->get(route('purchasing.suppliers.index'))
            ->assertOk()
            ->assertSee('Data Induk Pemasok');

        $this->actingAs($this->owner)
            ->get(route('purchasing.orders.index'))
            ->assertOk()
            ->assertSee('Pesanan Pembelian');

        $this->actingAs($this->owner)
            ->get(route('purchasing.grns.index'))
            ->assertOk()
            ->assertSee('Penerimaan Barang');

        $this->actingAs($this->owner)
            ->get(route('purchasing.invoices.index'))
            ->assertOk()
            ->assertSee('Faktur Pemasok');

        $this->actingAs($this->owner)
            ->get(route('purchasing.returns.index'))
            ->assertOk()
            ->assertSee('Retur Pembelian');
    }

    public function test_purchase_order_manager_livewire_can_create_and_submit_po(): void
    {
        Livewire::actingAs($this->owner)
            ->test(PurchaseOrderManager::class)
            ->assertSee('Pesanan Pembelian')
            ->call('openCreateModal')
            ->assertSet('showCreateModal', true)
            ->set('supplierId', $this->supplier->id)
            ->set('storeId', $this->store->id)
            ->set('orderDate', now()->toDateString())
            ->set('items.0.purchasable_type', 'product')
            ->set('items.0.purchasable_id', $this->product->id)
            ->set('items.0.purchase_uom', 'PCS')
            ->set('items.0.ordered_qty', '5')
            ->set('items.0.unit_price', '60000')
            ->call('savePurchaseOrder')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('purchase_orders', [
            'tenant_id' => $this->tenant->id,
            'supplier_id' => $this->supplier->id,
            'store_id' => $this->store->id,
        ]);
    }

    public function test_goods_received_note_manager_livewire_can_render(): void
    {
        // Buat PO untuk GRN
        $poService = app(PurchaseOrderService::class);
        $po = $poService->createDirectPurchaseOrder(
            [
                'store_id' => $this->store->id,
                'supplier_id' => $this->supplier->id,
                'order_date' => now()->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->product->id,
                    'purchase_uom' => 'PCS',
                    'ordered_qty' => '10.0000',
                    'unit_price' => '60000.0000',
                ],
            ],
            $this->owner
        );

        $poService->submitPurchaseOrder($po, $this->owner);
        $poService->releasePurchaseOrder($po, $this->owner);

        Livewire::actingAs($this->owner)
            ->test(GoodsReceivedNoteManager::class)
            ->assertSee('Penerimaan Barang')
            ->call('openCreateModal')
            ->assertSet('showCreateModal', true)
            ->set('selectedPoId', $po->id)
            ->assertCount('grnLines', 1);
    }

    public function test_supplier_invoice_manager_livewire_can_render(): void
    {
        Livewire::actingAs($this->owner)
            ->test(SupplierInvoiceManager::class)
            ->assertSee('Faktur Pemasok & 3-Way Match')
            ->call('openCreateModal')
            ->assertSet('showCreateModal', true);
    }

    public function test_purchase_return_manager_livewire_can_render(): void
    {
        Livewire::actingAs($this->owner)
            ->test(PurchaseReturnManager::class)
            ->assertSee('Retur Pembelian & Nota Debit')
            ->call('openCreateModal')
            ->assertSet('showCreateModal', true);
    }
}
