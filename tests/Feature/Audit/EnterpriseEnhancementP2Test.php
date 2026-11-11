<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Enums\JournalTypeEnum;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use App\Modules\HR\Enums\EmploymentTypeEnum;
use App\Modules\HR\Enums\GenderEnum;
use App\Modules\HR\Enums\MaritalStatusEnum;
use App\Modules\HR\Enums\PayrollBatchStatusEnum;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobTitle;
use App\Modules\HR\Models\PayrollBatch;
use App\Modules\Inventory\Models\IngredientStockBalance;
use App\Modules\Inventory\Models\ProductLot;
use App\Modules\Inventory\Services\LotFefoAllocationService;
use App\Modules\Manufacturing\Models\IngredientRecipe;
use App\Modules\Manufacturing\Models\ManufacturingOrder;
use App\Modules\Manufacturing\Services\ManufacturingOrderService;
use App\Modules\Purchasing\Enums\SupplierInvoiceStatusEnum;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierInvoice;
use App\Modules\Retail\Services\RetailBarcodeService;
use App\Modules\Sales\Models\CustomerInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class EnterpriseEnhancementP2Test extends TestCase
{
    use DatabaseTransactions;

    protected Tenant $tenant;
    protected Company $company;
    protected Store $store;
    protected User $managerUser;
    protected Category $category;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        $this->company = Company::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'company_code' => 'PT-APN-01'],
            [
                'company_name' => 'PT Apeiron Boga Retail',
                'legal_name' => 'PT Apeiron Boga Retail Nusantara',
                'npwp' => '01.234.567.8-901.000',
                'is_active' => true,
                'is_default' => true,
            ]
        );

        $roleManager = Role::firstOrCreate(
            ['name' => 'Manager'],
            ['description' => 'Store Manager', 'is_admin' => false]
        );

        $this->managerUser = User::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->managerUser->update([
            'role_id' => $roleManager->id,
            'store_id' => $this->store->id,
            'email_verified_at' => now(),
            'onboarding_completed' => true,
        ]);
        $this->managerUser->stores()->sync([
            $this->store->id => ['tenant_id' => $this->tenant->id],
        ]);

        $this->category = Category::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Enterprise Category',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store->id);
    }

    /**
     * TASK-P2-01: Multi-Level BOM Kitchen Prep Order (Resep Bertingkat Dapur).
     */
    public function test_task_p2_01_multi_level_bom_kitchen_prep_order(): void
    {
        $this->actingAs($this->managerUser);

        // 1. Buat bahan mentah (Raw Ingredients)
        $bawang = Ingredient::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'ingredient_code' => 'ING-RAW-001',
            'name' => 'Bawang Merah Kupas',
            'unit' => 'kg',
            'current_stock' => 10.0000,
            'cost_per_unit' => 40000.00, // Rp 40.000 / kg
            'is_active' => true,
        ]);

        $cabai = Ingredient::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'ingredient_code' => 'ING-RAW-002',
            'name' => 'Cabai Rawit Merah',
            'unit' => 'kg',
            'current_stock' => 10.0000,
            'cost_per_unit' => 60000.00, // Rp 60.000 / kg
            'is_active' => true,
        ]);

        // Saldo stok cabang bahan mentah
        IngredientStockBalance::updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'ingredient_id' => $bawang->id],
            ['quantity' => 10.0000]
        );
        IngredientStockBalance::updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'ingredient_id' => $cabai->id],
            ['quantity' => 10.0000]
        );

        // 2. Buat bahan olahan (Kitchen Prep Item)
        $sambal = Ingredient::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'ingredient_code' => 'ING-PREP-001',
            'name' => 'Sambal Matah Bali (Prep)',
            'unit' => 'kg',
            'current_stock' => 0.0000,
            'cost_per_unit' => 0.00,
            'is_active' => true,
        ]);

        IngredientStockBalance::updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'ingredient_id' => $sambal->id],
            ['quantity' => 0.0000]
        );

        // 3. Konfigurasi Multi-Level BOM pada IngredientRecipe:
        // 1 kg Sambal Matah butuh: 0.6 kg Bawang Merah (Rp 24.000) + 0.4 kg Cabai Rawit (Rp 24.000) = HPP Rp 48.000 / kg
        IngredientRecipe::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'parent_ingredient_id' => $sambal->id,
            'component_ingredient_id' => $bawang->id,
            'quantity_required' => '0.6000',
            'unit' => 'kg',
        ]);

        IngredientRecipe::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'parent_ingredient_id' => $sambal->id,
            'component_ingredient_id' => $cabai->id,
            'quantity_required' => '0.4000',
            'unit' => 'kg',
        ]);

        $mfgService = app(ManufacturingOrderService::class);

        // 4. Buat Perintah Kerja Produksi Bahan Olahan (Prep Order 2 kg Sambal Matah)
        $mo = $mfgService->createPrepOrder(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            ingredientId: $sambal->id,
            plannedQuantity: '2.0000',
            userId: $this->managerUser->id
        );

        $this->assertTrue($mo->isPrepOrder());
        $this->assertEquals('ingredient', $mo->destination_type);
        $this->assertEquals($sambal->id, $mo->destination_ingredient_id);

        $mfgService->startProduction($mo->id);

        // 5. Selesaikan produksi (Complete Production)
        $completedMO = $mfgService->completeProduction($mo->id, '2.0000');
        $this->assertEquals('COMPLETED', $completedMO->status);

        // Verifikasi pengurangan bahan mentah (10 - 2*0.6 = 8.8 kg bawang, 10 - 2*0.4 = 9.2 kg cabai)
        $bawangBalance = IngredientStockBalance::where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('ingredient_id', $bawang->id)
            ->value('quantity');
        $this->assertEquals(8.8000, (float) $bawangBalance);

        $cabaiBalance = IngredientStockBalance::where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('ingredient_id', $cabai->id)
            ->value('quantity');
        $this->assertEquals(9.2000, (float) $cabaiBalance);

        // Verifikasi penambahan stok bahan olahan (0 + 2 = 2.0 kg sambal matah)
        $sambalBalance = IngredientStockBalance::where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('ingredient_id', $sambal->id)
            ->value('quantity');
        $this->assertEquals(2.0000, (float) $sambalBalance);

        // Verifikasi kalkulasi dinamis HPP per kg: (2 * 0.6 * 40.000 + 2 * 0.4 * 60.000) / 2 = 96.000 / 2 = Rp 48.000 / kg
        $sambalUpdated = Ingredient::find($sambal->id);
        $this->assertEquals(48000.00, (float) $sambalUpdated->cost_per_unit);
    }

    /**
     * TASK-P2-02: Retail Digital Scale Barcode Parser & POS Scan-to-Cart.
     */
    public function test_task_p2_02_scale_barcode_parser_and_pos_scan(): void
    {
        $this->actingAs($this->managerUser);

        $barcodeService = app(RetailBarcodeService::class);

        // Validasi format barcode timbangan
        $weightBarcode = '0200123015004'; // Prefix 02, PLU 00123, Bobot 1500g (1.500 kg), Check digit 4
        $this->assertTrue($barcodeService->isScaleBarcode($weightBarcode));

        $parsed = $barcodeService->parseScaleBarcode($weightBarcode);
        $this->assertNotNull($parsed);
        $this->assertEquals('02', $parsed['prefix']);
        $this->assertEquals('00123', $parsed['item_code']);
        $this->assertEquals(1500, $parsed['weight_grams']);
        $this->assertEquals('1.500', $parsed['weight_kg']);

        // Buat produk timbangan buah di katalog
        $fruit = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Apel Fuji Super',
            'product_code' => '00123',
            'barcode' => '0200123000000',
            'product_price' => 40000, // Rp 40.000 / kg
            'is_active' => true,
        ]);

        // Simulasikan scan barcode timbangan pada Kasir POS (CreateOrder)
        // 1.500 kg x Rp 40.000 = Subtotal Rp 60.000
        Livewire::test(\App\Livewire\Kasir\CreateOrder::class)
            ->call('scanBarcode', $weightBarcode)
            ->assertSet('cart.0.product_id', $fruit->id)
            ->assertSet('cart.0.custom_price', 60000.0)
            ->assertSet('subtotal', 60000);
    }

    /**
     * TASK-P2-03: Pelacakan Persediaan Berbasis Batch/Lot & Algoritma FEFO.
     */
    public function test_task_p2_03_lot_fefo_allocation_service(): void
    {
        $this->actingAs($this->managerUser);

        $milk = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Susu UHT Fresh 1L',
            'product_code' => 'MILK-001',
            'product_price' => 20000,
            'is_active' => true,
        ]);

        $fefoService = app(LotFefoAllocationService::class);

        // Buat 3 batch persediaan dengan tanggal kadaluwarsa berbeda
        // Batch 1: Kadaluwarsa 2027-05-15, Qty 10
        $lot1 = $fefoService->recordLotReceipt(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            productId: $milk->id,
            lotNumber: 'LOT-MAY-2027',
            expiryDate: '2027-05-15',
            quantity: '10.0000',
            unitCost: 15000.0
        );

        // Batch 2: Kadaluwarsa 2027-03-20 (PALING AWAL KADALUWARSA), Qty 15
        $lot2 = $fefoService->recordLotReceipt(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            productId: $milk->id,
            lotNumber: 'LOT-MAR-2027',
            expiryDate: '2027-03-20',
            quantity: '15.0000',
            unitCost: 15000.0
        );

        // Batch 3: Kadaluwarsa 2027-08-01, Qty 25
        $lot3 = $fefoService->recordLotReceipt(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            productId: $milk->id,
            lotNumber: 'LOT-AUG-2027',
            expiryDate: '2027-08-01',
            quantity: '25.0000',
            unitCost: 15000.0
        );

        // Alokasikan pengeluaran stok 20 unit dengan FEFO
        // Harusnya: 15 unit habis dari Lot 2 (karena paling cepat kadaluwarsa), dan 5 unit dari Lot 1!
        $allocationResult = $fefoService->allocateFefo(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            productId: $milk->id,
            quantityNeeded: '20.0000'
        );

        $this->assertTrue($allocationResult['is_fully_allocated']);
        $this->assertEquals('20.0000', $allocationResult['total_allocated']);

        $allocs = $allocationResult['allocations'];
        $this->assertCount(2, $allocs);

        // Alokasi 1: Lot 2 (Maret 2027)
        $this->assertEquals($lot2->id, $allocs[0]['lot_id']);
        $this->assertEquals('15.0000', $allocs[0]['allocated_qty']);
        $this->assertEquals('0.0000', $allocs[0]['remaining_lot_qty']);

        // Alokasi 2: Lot 1 (Mei 2027)
        $this->assertEquals($lot1->id, $allocs[1]['lot_id']);
        $this->assertEquals('5.0000', $allocs[1]['allocated_qty']);
        $this->assertEquals('5.0000', $allocs[1]['remaining_lot_qty']);

        // Eksekusi pemotongan saldo lot
        $fefoService->deductAllocatedLots($allocs);

        $lot2Fresh = ProductLot::find($lot2->id);
        $this->assertEquals(0.0000, (float) $lot2Fresh->current_quantity);
        $this->assertEquals('depleted', $lot2Fresh->status);

        $lot1Fresh = ProductLot::find($lot1->id);
        $this->assertEquals(5.0000, (float) $lot1Fresh->current_quantity);
        $this->assertEquals('active', $lot1Fresh->status);
    }

    /**
     * TASK-P2-04: Penerapan Tarif Kurir Logistik 3PL pada Sales Order.
     */
    public function test_task_p2_04_sales_order_3pl_shipping_rate_application(): void
    {
        $this->actingAs($this->managerUser);

        $customer = Customer::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'PT Distribusi Logistik Sejahtera',
            'customer_code' => 'CUST-3PL-01',
            'is_active' => true,
        ]);

        $so = SalesOrder::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'so_number' => 'SO-P2-3PL-001',
            'order_date' => now(),
            'subtotal' => '500000.00',
            'discount_amount' => '0.00',
            'tax_amount' => '55000.00',
            'shipping_amount' => '0.00',
            'total_amount' => '555000.00',
            'created_by_user_id' => $this->managerUser->id,
            'payment_terms_days' => 30,
        ]);

        // Simulasikan pemilihan tarif kirim JNE REG sebesar Rp 25.000 pada Livewire SalesOrderManager
        Livewire::test(\App\Livewire\Sales\SalesOrderManager::class)
            ->call('viewOrder', $so->id)
            ->call('applyShippingRate', 'JNE', 'REG', 25000.0);

        $soUpdated = SalesOrder::find($so->id);
        $this->assertEquals('25000.00', $soUpdated->shipping_amount);
        $this->assertEquals('580000.00', $soUpdated->total_amount); // 500.000 + 55.000 + 25.000
        $this->assertStringContainsString('JNE - REG (Rp 25.000)', (string) $soUpdated->notes);
    }

    /**
     * TASK-P2-05: Penegakan Multi-Company Legal Entity (DEC-001) pada Model Statutori.
     */
    public function test_task_p2_05_multi_company_entity_associations(): void
    {
        $this->actingAs($this->managerUser);

        $dept = Department::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Divisi Keuangan & Pajak',
            'department_code' => 'FIN-TAX',
            'is_active' => true,
        ]);

        $job = JobTitle::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $dept->id,
            'name' => 'Senior Tax Analyst',
            'title_code' => 'TAX-SR',
            'is_active' => true,
        ]);

        // 1. Employee
        $employee = Employee::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'employee_number' => 'EMP-P2-001',
            'full_name' => 'Rahmat Hidayat, S.E., Ak.',
            'phone_number' => '081234567890',
            'department_id' => $dept->id,
            'job_title_id' => $job->id,
            'employment_type' => EmploymentTypeEnum::PERMANENT,
            'employment_status' => EmploymentStatusEnum::ACTIVE,
            'gender' => GenderEnum::MALE,
            'marital_status' => MaritalStatusEnum::MARRIED,
            'hire_date' => now()->subYear(),
        ]);

        $this->assertEquals($this->company->id, $employee->company->id);
        $this->assertEquals('PT Apeiron Boga Retail', $employee->company->company_name);

        // 2. PayrollBatch
        $payrollBatch = PayrollBatch::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'batch_number' => 'PB-2027-04',
            'period_start_date' => now()->startOfMonth(),
            'period_end_date' => now()->endOfMonth(),
            'cutoff_date' => now()->endOfMonth(),
            'payment_scheduled_date' => now()->endOfMonth(),
            'status' => PayrollBatchStatusEnum::DRAFT,
            'total_employees' => 1,
            'total_gross_amount' => 15000000,
            'total_tax_amount' => 750000,
            'total_bpjs_employer' => 600000,
            'total_bpjs_employee' => 150000,
            'total_other_deductions' => 0,
            'total_net_amount' => 14100000,
            'created_by_user_id' => $this->managerUser->id,
        ]);

        $this->assertEquals($this->company->id, $payrollBatch->company->id);

        // 3. JournalEntry
        $period = \App\Modules\Finance\Models\AccountingPeriod::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'period_code' => 'P2-' . rand(10000, 99999),
            'fiscal_year' => 2027,
            'period_number' => 4,
            'start_date' => now()->startOfMonth(),
            'end_date' => now()->endOfMonth(),
            'status' => 'OPEN',
        ]);

        $journal = JournalEntry::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'journal_number' => 'JV-2027-04-001',
            'accounting_period_id' => $period->id,
            'posting_date' => now(),
            'journal_type' => JournalTypeEnum::MANUAL_ADJUSTMENT,
            'status' => JournalStatusEnum::POSTED,
            'source_module' => 'finance',
            'source_type' => 'manual',
            'idempotency_key' => 'idemp-jv-p2-'.uniqid(),
            'currency_code' => 'IDR',
            'total_debit' => '1000000.00',
            'total_credit' => '1000000.00',
        ]);

        $this->assertEquals($this->company->id, $journal->company->id);

        // 4. CustomerInvoice
        $customer = Customer::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'PT Invoice Customer',
            'is_active' => true,
        ]);
        $invoice = CustomerInvoice::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-2027-001',
            'invoice_date' => now(),
            'due_date' => now()->addDays(30),
            'status' => \App\Modules\Sales\Enums\CustomerInvoiceStatusEnum::POSTED,
            'subtotal' => '500000.00',
            'discount_amount' => '0.00',
            'tax_amount' => '55000.00',
            'shipping_amount' => '0.00',
            'total_amount' => '555000.00',
            'paid_amount' => '0.00',
            'balance_due' => '555000.00',
            'payment_terms_days' => 30,
        ]);

        $this->assertEquals($this->company->id, $invoice->company->id);

        // 5. SupplierInvoice
        $supplier = Supplier::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'PT Mitra Pangan Lestari',
            'code' => 'SUP-P2-001',
            'is_active' => true,
        ]);

        $suppInvoice = SupplierInvoice::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'supplier_id' => $supplier->id,
            'internal_invoice_number' => 'SINV-2027-001',
            'supplier_invoice_number' => 'MPL-88990',
            'normalized_supplier_invoice_number' => 'MPL-88990',
            'invoice_date' => now(),
            'due_date' => now()->addDays(30),
            'currency_code' => 'IDR',
            'subtotal_amount' => '1000000.00',
            'total_amount' => '1110000.00',
            'tax_amount' => '110000.00',
            'status' => \App\Enums\SupplierInvoiceStatusEnum::Draft,
        ]);

        $this->assertEquals($this->company->id, $suppInvoice->company->id);
    }
}
