<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Enums\SupplierStatusEnum;
use App\Livewire\Purchasing\SupplierManager;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Purchasing\Services\SupplierService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class SupplierManagementTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenant;

    private Store $store;

    private User $ownerUser;

    private User $kasirUser;

    private SupplierService $service;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Kopi Sejahtera',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cabang Sudirman',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store->id);

        $ownerRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'display_name' => 'Pemilik',
            'permissions' => ['*'],
        ]);

        $kasirRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kasir',
            'display_name' => 'Kasir Kasir',
            'permissions' => ['transaction.*', 'kds.*', 'orders.*'],
        ]);

        $this->ownerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Budi Owner',
            'email' => 'owner@kopisejahtera.id',
            'password' => bcrypt('password123'),
            'role_id' => $ownerRole->id,
            'is_active' => true,
        ]);

        $this->kasirUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Siti Kasir',
            'email' => 'kasir@kopisejahtera.id',
            'password' => bcrypt('password123'),
            'role_id' => $kasirRole->id,
            'is_active' => true,
        ]);

        $this->service = app(SupplierService::class);
    }

    public function test_authorized_user_can_create_supplier_with_auto_generated_code_and_normalization(): void
    {
        $supplier = $this->service->createSupplier([
            'name' => '  PT Biji Kopi Nusantara  ',
            'email' => '  Sales+order@KopiNusantara.COM  ',
            'phone' => '021-98765432',
            'payment_terms_days' => 30,
            'currency_code' => 'idr',
        ], $this->ownerUser);

        $this->assertInstanceOf(Supplier::class, $supplier);
        $this->assertSame('SUP-0001', $supplier->code);
        $this->assertSame('PT Biji Kopi Nusantara', $supplier->name);
        $this->assertSame('pt biji kopi nusantara', $supplier->normalized_name);
        $this->assertSame('sales+order@kopinusantara.com', $supplier->email);
        $this->assertSame(30, $supplier->payment_terms_days);
        $this->assertSame('IDR', $supplier->currency_code);
        $this->assertSame(SupplierStatusEnum::Active, $supplier->status);
        $this->assertTrue($supplier->isActive());
        $this->assertTrue($supplier->canAcceptPurchaseOrder());
        $this->assertTrue($supplier->canProcessFulfillment());

        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'tenant_id' => $this->tenant->id,
            'code' => 'SUP-0001',
            'name' => 'PT Biji Kopi Nusantara',
            'status' => 'active',
        ]);
    }

    public function test_user_without_permission_is_denied_from_creating_or_updating_supplier(): void
    {
        $supplier = $this->service->createSupplier([
            'name' => 'Vendor Test',
        ], $this->ownerUser);

        $this->assertFalse(Gate::forUser($this->kasirUser)->allows('create', Supplier::class));
        $this->assertFalse(Gate::forUser($this->kasirUser)->allows('update', $supplier));
        $this->assertFalse(Gate::forUser($this->kasirUser)->allows('archive', $supplier));
        $this->assertFalse(Gate::forUser($this->kasirUser)->allows('delete', $supplier));
        $this->assertFalse(Gate::forUser($this->kasirUser)->allows('manageCatalog', $supplier));
    }

    public function test_cannot_create_duplicate_supplier_code_within_same_tenant(): void
    {
        $this->service->createSupplier([
            'code' => 'SUP-CUSTOM',
            'name' => 'Supplier Satu',
        ], $this->ownerUser);

        $this->expectException(ValidationException::class);
        $this->service->createSupplier([
            'code' => 'SUP-CUSTOM',
            'name' => 'Supplier Dua',
        ], $this->ownerUser);
    }

    public function test_update_supplier_preserves_invariants_and_normalizes_data(): void
    {
        $supplier = $this->service->createSupplier([
            'name' => 'Supplier Awal',
            'email' => 'awal@supplier.com',
        ], $this->ownerUser);

        $updated = $this->service->updateSupplier($supplier, [
            'name' => 'Supplier Diubah',
            'email' => 'diubah@supplier.com',
            'payment_terms_days' => 14,
            'status' => SupplierStatusEnum::OnHold,
        ], $this->ownerUser);

        $this->assertSame('Supplier Diubah', $updated->name);
        $this->assertSame('supplier diubah', $updated->normalized_name);
        $this->assertSame('diubah@supplier.com', $updated->email);
        $this->assertSame(14, $updated->payment_terms_days);
        $this->assertSame(SupplierStatusEnum::OnHold, $updated->status);
        $this->assertFalse($updated->canAcceptPurchaseOrder());
        $this->assertTrue($updated->canProcessFulfillment());
    }

    public function test_archive_supplier_transitions_status_to_archived(): void
    {
        $supplier = $this->service->createSupplier([
            'name' => 'Supplier Lama',
        ], $this->ownerUser);

        $archived = $this->service->archiveSupplier($supplier, 'Sudah tutup operasional', $this->ownerUser);

        $this->assertSame(SupplierStatusEnum::Archived, $archived->status);
        $this->assertStringContainsString('Sudah tutup operasional', (string) $archived->notes);
        $this->assertFalse($archived->canAcceptPurchaseOrder());
        $this->assertFalse($archived->canProcessFulfillment());
    }

    public function test_contacts_management_and_primary_exclusivity(): void
    {
        $supplier = $this->service->createSupplier([
            'name' => 'Supplier Kontak',
        ], $this->ownerUser);

        $c1 = $this->service->addContact($supplier, [
            'name' => 'Andi Sales',
            'email' => 'andi@supplier.com',
            'is_primary' => true,
        ]);

        $this->assertTrue($c1->is_primary);
        $this->assertSame('andi@supplier.com', $c1->email);

        $c2 = $this->service->addContact($supplier, [
            'name' => 'Bambang Direktur',
            'email' => 'bambang@supplier.com',
            'is_primary' => true,
        ]);

        $this->assertTrue($c2->is_primary);
        $this->assertFalse($c1->fresh()->is_primary);

        $supplier->refresh();
        $this->assertNotNull($supplier->primaryContact);
        $this->assertSame($c2->id, $supplier->primaryContact->id);
    }

    public function test_catalog_management_with_uom_conversion_and_preferred_exclusivity(): void
    {
        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Minuman',
            'slug' => 'minuman',
        ]);

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'name' => 'Kopi Susu Literan',
            'sku' => 'SKU-KSL-01',
            'barcode' => '8991234567890',
            'cost_price' => 15000,
            'selling_price' => 25000,
            'status' => 'active',
            'is_active' => true,
        ]);

        $ingredient = Ingredient::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Susu Segar Pasteurisasi',
            'unit' => 'ml',
            'cost_per_unit' => 25.00,
            'is_active' => true,
        ]);

        $supplier1 = $this->service->createSupplier(['name' => 'Supplier Susu A'], $this->ownerUser);
        $supplier2 = $this->service->createSupplier(['name' => 'Supplier Susu B'], $this->ownerUser);

        // Tambah katalog produk ritel: Carton isi 12 botol
        $spProduct = $this->service->addOrUpdateSupplierProduct($supplier1, [
            'purchasable_type' => 'product',
            'purchasable_id' => $product->id,
            'supplier_sku' => 'VND-KSL-CTN',
            'purchase_uom' => 'CARTON',
            'uom_conversion_factor' => '12.000000',
            'standard_price' => '180000.0000',
            'min_order_qty' => '2.0000',
            'order_multiple_qty' => '1.0000',
            'lead_time_days' => 2,
            'is_preferred' => true,
        ], $this->ownerUser);

        $this->assertSame('12.000000', $spProduct->uom_conversion_factor);
        // 2 Carton x 12 = 24 botol base qty
        $this->assertSame('24.0000', $spProduct->calculateBaseQty('2.0000'));
        // 36 botol base qty / 12 = 3 Carton
        $this->assertSame('3.0000', $spProduct->calculatePurchaseQty('36.0000'));

        // Tambah katalog bahan baku (susu): Jerigen 5000 ml
        $spIng1 = $this->service->addOrUpdateSupplierProduct($supplier1, [
            'purchasable_type' => 'ingredient',
            'purchasable_id' => $ingredient->id,
            'purchase_uom' => 'JERIGEN_5L',
            'uom_conversion_factor' => '5000.000000',
            'standard_price' => '115000.0000',
            'is_preferred' => true,
        ], $this->ownerUser);
        $this->assertTrue($spIng1->is_preferred);

        // Supplier 2 juga memasok susu yang sama dan diset sebagai preferred
        $spIng2 = $this->service->addOrUpdateSupplierProduct($supplier2, [
            'purchasable_type' => 'ingredient',
            'purchasable_id' => $ingredient->id,
            'purchase_uom' => 'JERIGEN_5L',
            'uom_conversion_factor' => '5000.000000',
            'standard_price' => '110000.0000',
            'is_preferred' => true,
        ], $this->ownerUser);

        // Flag preferred pada Supplier 1 harus otomatis dimatikan
        $this->assertTrue($spIng2->is_preferred);
        $this->assertFalse($spIng1->fresh()->is_preferred);
    }

    public function test_livewire_supplier_manager_component_renders_and_executes_actions(): void
    {
        $supplier = $this->service->createSupplier([
            'name' => 'Supplier Livewire Test',
            'email' => 'livewire@test.com',
            'phone' => '0812345678',
        ], $this->ownerUser);

        Livewire::actingAs($this->ownerUser)
            ->test(SupplierManager::class)
            ->assertSee('Data Induk Pemasok')
            ->assertSee('Supplier Livewire Test')
            ->set('search', 'Livewire')
            ->assertSee('Supplier Livewire Test')
            ->set('search', 'TidakAdaPemasok')
            ->assertDontSee('Supplier Livewire Test');
    }
}
