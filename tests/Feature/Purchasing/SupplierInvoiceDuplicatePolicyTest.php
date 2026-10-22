<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Core\Context\TenantContext;
use App\Enums\SupplierStatusEnum;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\SupplierInvoiceMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SupplierInvoiceDuplicatePolicyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Store $storeA;

    private Store $storeB;

    private User $userA;

    private User $userB;

    private Supplier $supplierA1;

    private Supplier $supplierA2;

    private Supplier $supplierB;

    private Product $productA;

    private Product $productB;

    private SupplierInvoiceMatchingService $invoiceService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        // 1. Tenant A
        $this->tenantA = Tenant::create([
            'name' => 'Resto Alpha',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        app(TenantContext::class)->setTenantId($this->tenantA->id);

        $this->storeA = Store::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Cabang Alpha',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setStoreId($this->storeA->id);

        $roleA = Role::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Accounting Alpha',
            'is_admin' => true,
        ]);

        $this->userA = User::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'role_id' => $roleA->id,
            'name' => 'Akuntan Alpha',
            'email' => 'acct.alpha@test.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->userA->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

        $this->supplierA1 = Supplier::create([
            'tenant_id' => $this->tenantA->id,
            'code' => 'SUP-A1',
            'name' => 'PT Pangan Utama',
            'status' => SupplierStatusEnum::Active,
            'payment_terms_days' => 30,
            'currency_code' => 'IDR',
        ]);

        $this->supplierA2 = Supplier::create([
            'tenant_id' => $this->tenantA->id,
            'code' => 'SUP-A2',
            'name' => 'CV Sumber Makmur',
            'status' => SupplierStatusEnum::Active,
            'payment_terms_days' => 14,
            'currency_code' => 'IDR',
        ]);

        $catA = Category::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Bahan Baku A',
            'slug' => 'bahan-baku-a',
        ]);

        $this->productA = Product::create([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $catA->id,
            'name' => 'Minyak Goreng 2L',
            'sku' => 'PRD-MYK-2L',
            'price' => '35000.00',
        ]);

        // 2. Tenant B
        $this->tenantB = Tenant::create([
            'name' => 'Resto Beta',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'active',
        ]);

        app(TenantContext::class)->setTenantId($this->tenantB->id);

        $this->storeB = Store::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Cabang Beta',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setStoreId($this->storeB->id);

        $roleB = Role::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Accounting Beta',
            'is_admin' => true,
        ]);

        $this->userB = User::create([
            'tenant_id' => $this->tenantB->id,
            'store_id' => $this->storeB->id,
            'role_id' => $roleB->id,
            'name' => 'Akuntan Beta',
            'email' => 'acct.beta@test.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->userB->stores()->attach($this->storeB->id, ['tenant_id' => $this->tenantB->id]);

        $this->supplierB = Supplier::create([
            'tenant_id' => $this->tenantB->id,
            'code' => 'SUP-B1',
            'name' => 'PT Mitra Sejahtera',
            'status' => SupplierStatusEnum::Active,
            'payment_terms_days' => 14,
            'currency_code' => 'IDR',
        ]);

        $catB = Category::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Bahan Baku B',
            'slug' => 'bahan-baku-b',
        ]);

        $this->productB = Product::create([
            'tenant_id' => $this->tenantB->id,
            'category_id' => $catB->id,
            'name' => 'Tepung Terigu 1kg',
            'sku' => 'PRD-TPG-1K',
            'price' => '12000.00',
        ]);

        $this->invoiceService = app(SupplierInvoiceMatchingService::class);

        // Reset context to Tenant A as default
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);
    }

    /**
     * AT-09: Pengiriman nomor faktur pemasok yang sama (setelah normalisasi)
     * untuk pemasok yang sama di tenant yang sama ditolak oleh validasi dan database.
     */
    public function test_duplicate_supplier_invoice_number_within_same_supplier_is_rejected(): void
    {
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        // 1. Buat faktur pertama dengan nomor "INV / 2026-001"
        $this->invoiceService->createSupplierInvoice(
            [
                'store_id' => $this->storeA->id,
                'supplier_id' => $this->supplierA1->id,
                'supplier_invoice_number' => 'INV / 2026-001',
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->productA->id,
                    'invoiced_qty' => '10.0000',
                    'unit_price' => '32000.0000',
                ],
            ],
            $this->userA
        );

        // 2. Upaya pembuatan faktur kedua untuk pemasok yang sama dengan variasi penulisan "inv-2026.001"
        // yang menghasilkan normalisasi identik: "INV2026001" -> Wajib ditolak!
        $this->expectException(ValidationException::class);

        $this->invoiceService->createSupplierInvoice(
            [
                'store_id' => $this->storeA->id,
                'supplier_id' => $this->supplierA1->id,
                'supplier_invoice_number' => 'inv-2026.001',
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->productA->id,
                    'invoiced_qty' => '5.0000',
                    'unit_price' => '32000.0000',
                ],
            ],
            $this->userA
        );
    }

    /**
     * AT-09: Nomor faktur yang sama dari pemasok berbeda dalam satu tenant diizinkan.
     */
    public function test_same_invoice_number_for_different_supplier_is_allowed(): void
    {
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        // Faktur untuk Supplier A1 dengan nomor "INV-100"
        $inv1 = $this->invoiceService->createSupplierInvoice(
            [
                'store_id' => $this->storeA->id,
                'supplier_id' => $this->supplierA1->id,
                'supplier_invoice_number' => 'INV-100',
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->productA->id,
                    'invoiced_qty' => '10.0000',
                    'unit_price' => '32000.0000',
                ],
            ],
            $this->userA
        );
        $this->assertGreaterThan(0, $inv1->id);

        // Faktur untuk Supplier A2 dengan nomor "INV-100" yang sama persis -> Harus berhasil
        $inv2 = $this->invoiceService->createSupplierInvoice(
            [
                'store_id' => $this->storeA->id,
                'supplier_id' => $this->supplierA2->id,
                'supplier_invoice_number' => 'INV-100',
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(14)->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->productA->id,
                    'invoiced_qty' => '5.0000',
                    'unit_price' => '32000.0000',
                ],
            ],
            $this->userA
        );
        $this->assertGreaterThan(0, $inv2->id);
        $this->assertNotEquals($inv1->id, $inv2->id);
    }

    /**
     * AT-09: Nomor faktur yang sama di tenant berbeda diizinkan.
     */
    public function test_same_invoice_number_in_different_tenants_is_allowed(): void
    {
        // 1. Tenant A buat faktur "INV-ALPHA-01"
        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);

        $invA = $this->invoiceService->createSupplierInvoice(
            [
                'store_id' => $this->storeA->id,
                'supplier_id' => $this->supplierA1->id,
                'supplier_invoice_number' => 'INV-ALPHA-01',
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->productA->id,
                    'invoiced_qty' => '10.0000',
                    'unit_price' => '32000.0000',
                ],
            ],
            $this->userA
        );
        $this->assertGreaterThan(0, $invA->id);

        // 2. Tenant B buat faktur dengan nomor yang sama persis "INV-ALPHA-01"
        app(TenantContext::class)->setTenantId($this->tenantB->id);
        app(TenantContext::class)->setStoreId($this->storeB->id);

        $invB = $this->invoiceService->createSupplierInvoice(
            [
                'store_id' => $this->storeB->id,
                'supplier_id' => $this->supplierB->id,
                'supplier_invoice_number' => 'INV-ALPHA-01',
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(14)->toDateString(),
            ],
            [
                [
                    'purchasable_type' => 'product',
                    'purchasable_id' => $this->productB->id,
                    'invoiced_qty' => '8.0000',
                    'unit_price' => '11000.0000',
                ],
            ],
            $this->userB
        );
        $this->assertGreaterThan(0, $invB->id);
        $this->assertEquals($this->tenantB->id, $invB->tenant_id);
    }
}
