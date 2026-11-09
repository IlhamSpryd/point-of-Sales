<?php

declare(strict_types=1);

namespace Tests\Feature\Challenger;

use App\Models\Role;
use App\Models\Store;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\PayrollBatch;
use App\Modules\HR\Models\PayrollItem;
use App\Modules\HR\Models\PayrollRecord;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\SupplierInvoice;
use App\Modules\Retail\Models\ShelfLabel;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class Milestone1EmpiricalStressTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    protected Tenant $tenantAlpha;
    protected Tenant $tenantBeta;
    protected Store $storeAlpha1;
    protected Store $storeAlpha2;
    protected Store $storeBeta1;

    protected User $userOwner;
    protected User $userManagerAlpha1;
    protected User $userCashier;
    protected User $userCustomer;

    protected Table $tableAlpha1;
    protected Table $tableAlpha2;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        app(TenantContext::class)->clear();

        // 1. Setup Tenant Alpha
        $this->tenantAlpha = Tenant::create([
            'name' => 'Challenger Alpha Tenant',
            'slug' => 'challenger-alpha-'.uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
            'subscription_plan' => 'standard_trial',
            'country_code' => 'ID',
        ]);

        Artisan::call('tenant:provision-system-users', ['tenant' => $this->tenantAlpha->id]);

        $this->storeAlpha1 = Store::withoutTenantScope()->create([
            'tenant_id' => $this->tenantAlpha->id,
            'name' => 'Store Alpha 1',
            'code' => 'ALP-01',
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
        ]);

        $this->storeAlpha2 = Store::withoutTenantScope()->create([
            'tenant_id' => $this->tenantAlpha->id,
            'name' => 'Store Alpha 2',
            'code' => 'ALP-02',
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
        ]);

        // 2. Setup Tenant Beta
        $this->tenantBeta = Tenant::create([
            'name' => 'Challenger Beta Tenant',
            'slug' => 'challenger-beta-'.uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
            'subscription_plan' => 'standard_trial',
            'country_code' => 'ID',
        ]);

        Artisan::call('tenant:provision-system-users', ['tenant' => $this->tenantBeta->id]);

        $this->storeBeta1 = Store::withoutTenantScope()->create([
            'tenant_id' => $this->tenantBeta->id,
            'name' => 'Store Beta 1',
            'code' => 'BET-01',
            'timezone' => 'Asia/Jakarta',
            'business_date_cutoff' => '00:00',
            'default_channel' => 'dine_in',
            'is_active' => true,
        ]);

        // Roles
        $roleOwner = Role::firstOrCreate(
            ['tenant_id' => $this->tenantAlpha->id, 'name' => 'Owner'],
            ['permissions' => ['*'], 'is_active' => true]
        );

        $roleManager = Role::firstOrCreate(
            ['tenant_id' => $this->tenantAlpha->id, 'name' => 'Manager'],
            ['permissions' => ['tables.manage'], 'is_active' => true]
        );

        $roleKasir = Role::firstOrCreate(
            ['tenant_id' => $this->tenantAlpha->id, 'name' => 'Kasir'],
            ['permissions' => ['pos.order', 'pos.payment'], 'is_active' => true]
        );

        $roleCustomer = Role::firstOrCreate(
            ['tenant_id' => $this->tenantAlpha->id, 'name' => 'Customer'],
            ['permissions' => [], 'is_active' => true]
        );

        // Users
        $this->userOwner = User::create([
            'tenant_id' => $this->tenantAlpha->id,
            'role_id' => $roleOwner->id,
            'name' => 'Owner Alpha',
            'email' => 'owner-alpha-'.uniqid().'@test.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $this->userOwner->stores()->sync([
            $this->storeAlpha1->id => ['tenant_id' => $this->tenantAlpha->id],
            $this->storeAlpha2->id => ['tenant_id' => $this->tenantAlpha->id],
        ]);

        $this->userManagerAlpha1 = User::create([
            'tenant_id' => $this->tenantAlpha->id,
            'role_id' => $roleManager->id,
            'name' => 'Manager Alpha Store 1',
            'email' => 'mgr-alpha1-'.uniqid().'@test.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $this->userManagerAlpha1->stores()->sync([
            $this->storeAlpha1->id => ['tenant_id' => $this->tenantAlpha->id],
        ]);

        $this->userCashier = User::create([
            'tenant_id' => $this->tenantAlpha->id,
            'role_id' => $roleKasir->id,
            'name' => 'Kasir Alpha',
            'email' => 'kasir-alpha-'.uniqid().'@test.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $this->userCashier->stores()->sync([
            $this->storeAlpha1->id => ['tenant_id' => $this->tenantAlpha->id],
        ]);

        $this->userCustomer = User::create([
            'tenant_id' => $this->tenantAlpha->id,
            'role_id' => $roleCustomer->id,
            'name' => 'Customer Alpha',
            'email' => 'customer-alpha-'.uniqid().'@test.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        // Tables
        $this->tableAlpha1 = Table::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantAlpha->id,
            'store_id' => $this->storeAlpha1->id,
            'table_name' => 'T-Alpha-1',
            'table_code' => 'TBL-A1',
            'capacity' => 4,
            'area' => 'Indoor Main',
            'is_active' => true,
            'operational_status' => 'available',
            'qr_token' => bin2hex(random_bytes(32)),
            'qr_token_version' => 1,
            'secure_token' => (string) Str::uuid(),
        ]);

        $this->tableAlpha2 = Table::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantAlpha->id,
            'store_id' => $this->storeAlpha2->id,
            'table_name' => 'T-Alpha-2',
            'table_code' => 'TBL-A2',
            'capacity' => 6,
            'area' => 'Outdoor Patio',
            'is_active' => true,
            'operational_status' => 'available',
            'qr_token' => bin2hex(random_bytes(32)),
            'qr_token_version' => 1,
            'secure_token' => (string) Str::uuid(),
        ]);
    }

    /**
     * DEF-04: Empirical Mass-Assignment Stress Test.
     * Hostile injection attack payload containing primary key, timestamps, and privilege escalation fields.
     */
    public function test_def04_mass_assignment_adversarial_rejection_across_critical_models(): void
    {
        $attackPayload = [
            'id' => 999999,
            'is_admin' => true,
            'role_id' => 1,
            'super_admin_bypass' => 'EVIL',
            'created_at' => '1999-01-01 00:00:00',
            'updated_at' => '1999-01-01 00:00:00',
            'deleted_at' => '1999-01-01 00:00:00',
        ];

        // 1. Customer
        $customer = new Customer(array_merge($attackPayload, ['name' => 'Test Customer Corp']));
        $this->assertSame('Test Customer Corp', $customer->name);
        $this->assertNull($customer->id);
        $this->assertFalse(isset($customer->is_admin));
        $this->assertFalse(isset($customer->role_id));
        $this->assertFalse(isset($customer->super_admin_bypass));
        $this->assertFalse(isset($customer->created_at));
        $this->assertFalse($customer->isFillable('id'));
        $this->assertFalse($customer->isFillable('is_admin'));
        $this->assertFalse($customer->isFillable('created_at'));

        // 2. PurchaseOrder
        $po = new PurchaseOrder(array_merge($attackPayload, ['po_number' => 'PO-STRESS-001']));
        $this->assertSame('PO-STRESS-001', $po->po_number);
        $this->assertNull($po->id);
        $this->assertFalse(isset($po->is_admin));
        $this->assertFalse(isset($po->super_admin_bypass));
        $this->assertFalse($po->isFillable('id'));

        // 3. SupplierInvoice
        $invoice = new SupplierInvoice(array_merge($attackPayload, ['internal_invoice_number' => 'INV-STRESS-001']));
        $this->assertSame('INV-STRESS-001', $invoice->internal_invoice_number);
        $this->assertNull($invoice->id);
        $this->assertFalse(isset($invoice->is_admin));
        $this->assertFalse(isset($invoice->super_admin_bypass));
        $this->assertFalse($invoice->isFillable('id'));

        // 4. JournalEntry
        $journal = new JournalEntry(array_merge($attackPayload, ['journal_number' => 'JE-STRESS-001']));
        $this->assertSame('JE-STRESS-001', $journal->journal_number);
        $this->assertNull($journal->id);
        $this->assertFalse(isset($journal->is_admin));
        $this->assertFalse(isset($journal->super_admin_bypass));
        $this->assertFalse($journal->isFillable('id'));

        // 5. Employee
        $employee = new Employee(array_merge($attackPayload, ['full_name' => 'John Doe Stress']));
        $this->assertSame('John Doe Stress', $employee->full_name);
        $this->assertNull($employee->id);
        $this->assertFalse(isset($employee->is_admin));
        $this->assertFalse(isset($employee->super_admin_bypass));
        $this->assertFalse($employee->isFillable('id'));

        // 6. Ingredient
        $ingredient = new Ingredient(array_merge($attackPayload, ['name' => 'Robusta Coffee Bean']));
        $this->assertSame('Robusta Coffee Bean', $ingredient->name);
        $this->assertNull($ingredient->id);
        $this->assertFalse(isset($ingredient->is_admin));
        $this->assertFalse(isset($ingredient->super_admin_bypass));
        $this->assertFalse($ingredient->isFillable('id'));
    }

    /**
     * DEF-07: Empirical Retail ShelfLabel Scoping & Raw Query Scoping.
     */
    public function test_def07_shelf_label_tenant_scoping_and_raw_query_behavior(): void
    {
        $context = app(TenantContext::class);

        // Populate ShelfLabel in Tenant Alpha
        $context->setTenantId($this->tenantAlpha->id);
        $catAlpha = Category::create([
            'tenant_id' => $this->tenantAlpha->id,
            'category_name' => 'General Alpha',
        ]);
        $prodAlpha = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantAlpha->id,
            'category_id' => $catAlpha->id,
            'product_name' => 'Alpha Product 1',
            'product_price' => '25000.00',
        ]);
        $labelAlpha = ShelfLabel::create([
            'store_id' => $this->storeAlpha1->id,
            'product_id' => $prodAlpha->id,
            'old_price' => '25000.00',
            'new_price' => '28000.00',
            'status' => ShelfLabel::STATUS_PENDING,
            'batch_number' => 'BATCH-ALPHA',
        ]);
        $this->assertSame($this->tenantAlpha->id, (int) $labelAlpha->tenant_id);

        // Populate ShelfLabel in Tenant Beta
        $context->setTenantId($this->tenantBeta->id);
        $catBeta = Category::create([
            'tenant_id' => $this->tenantBeta->id,
            'category_name' => 'General Beta',
        ]);
        $prodBeta = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantBeta->id,
            'category_id' => $catBeta->id,
            'product_name' => 'Beta Product 1',
            'product_price' => '35000.00',
        ]);
        $labelBeta = ShelfLabel::create([
            'store_id' => $this->storeBeta1->id,
            'product_id' => $prodBeta->id,
            'old_price' => '35000.00',
            'new_price' => '38000.00',
            'status' => ShelfLabel::STATUS_PENDING,
            'batch_number' => 'BATCH-BETA',
        ]);
        $this->assertSame($this->tenantBeta->id, (int) $labelBeta->tenant_id);

        // 1. Fail-closed verification: Context cleared (unauthenticated)
        $context->clear();
        $this->assertCount(0, ShelfLabel::all(), 'Unauthenticated model query must return 0 results');
        $this->assertEquals(0, ShelfLabel::count());
        $this->assertNull(ShelfLabel::find($labelAlpha->id));
        $this->assertNull(ShelfLabel::find($labelBeta->id));
        $this->assertEquals(0, ShelfLabel::where('batch_number', 'BATCH-ALPHA')->count());

        // 2. Scoped isolation verification: Context set to Alpha
        $context->setTenantId($this->tenantAlpha->id);
        $alphaResults = ShelfLabel::all();
        $this->assertCount(1, $alphaResults);
        $this->assertSame($labelAlpha->id, $alphaResults->first()->id);
        $this->assertNull(ShelfLabel::find($labelBeta->id));

        // 3. Scoped isolation verification: Context set to Beta
        $context->setTenantId($this->tenantBeta->id);
        $betaResults = ShelfLabel::all();
        $this->assertCount(1, $betaResults);
        $this->assertSame($labelBeta->id, $betaResults->first()->id);
        $this->assertNull(ShelfLabel::find($labelAlpha->id));

        // 4. Intentional bypass using withoutTenantScope
        $allBypassed = ShelfLabel::withoutTenantScope()->get();
        $this->assertGreaterThanOrEqual(2, $allBypassed->count());
        $this->assertTrue($allBypassed->contains('id', $labelAlpha->id));
        $this->assertTrue($allBypassed->contains('id', $labelBeta->id));
    }

    /**
     * DEF-03: HR Payroll Decimal Precision Stress Test with 5,000 summation loop.
     * Proves that converted decimal casts eliminate floating-point drift.
     */
    public function test_def03_payroll_decimal_precision_in_high_iteration_summation_oracle(): void
    {
        // 1. Verify exact decimal casts on HR Payroll Record
        $record = new PayrollRecord([
            'basic_salary_amount' => 7500000.33,
            'fixed_allowances_amount' => 1250000.67,
            'variable_allowances_amount' => 500000.25,
            'overtime_amount' => 350000.75,
            'gross_earnings_amount' => 9600002.00,
            'tax_pph21_amount' => 450000.50,
            'net_pay_amount' => 9149999.50,
        ]);

        $this->assertSame('7500000.33', $record->basic_salary_amount);
        $this->assertSame('1250000.67', $record->fixed_allowances_amount);
        $this->assertSame('500000.25', $record->variable_allowances_amount);
        $this->assertSame('350000.75', $record->overtime_amount);
        $this->assertSame('9600002.00', $record->gross_earnings_amount);
        $this->assertSame('450000.50', $record->tax_pph21_amount);
        $this->assertSame('9149999.50', $record->net_pay_amount);

        // 2. High-iteration summation loop oracle: 5,000 items with non-binary fractions (0.10 and 0.20)
        // In IEEE 754 float: 0.10 + 0.20 = 0.30000000000000004
        // Over 5,000 iterations: 5,000 * 0.10 + 5,000 * 0.20 = 1,500.00 exactly.
        $floatSum = 0.0;
        $bcSum = '0.00';

        for ($i = 0; $i < 5000; $i++) {
            $item1 = new PayrollItem(['amount' => 0.10]);
            $item2 = new PayrollItem(['amount' => 0.20]);

            // Model cast yields exact strings
            $this->assertSame('0.10', $item1->amount);
            $this->assertSame('0.20', $item2->amount);

            $bcSum = bcadd($bcSum, $item1->amount, 2);
            $bcSum = bcadd($bcSum, $item2->amount, 2);
            $floatSum += (float) $item1->amount + (float) $item2->amount;
        }

        // Expected oracle sum is 1500.00
        $this->assertSame('1500.00', $bcSum);

        // Assign sum to PayrollBatch decimal cast attribute
        $batch = new PayrollBatch(['total_gross_amount' => $bcSum]);
        $this->assertSame('1500.00', $batch->total_gross_amount);

        // Test extreme values (IDR 50 billion with 99 cents)
        $largeRecord = new PayrollRecord(['basic_salary_amount' => '50000000000.99']);
        $this->assertSame('50000000000.99', $largeRecord->basic_salary_amount);
    }

    /**
     * DEF-10: TableController 9 Endpoints Full Authorization Matrix Across 5 Roles.
     * Roles: Unauthenticated, Customer, Cashier (Kasir), Cross-Store Manager, Valid Store Manager.
     */
    public function test_def10_table_controller_nine_endpoints_across_five_role_matrix(): void
    {
        // -------------------------------------------------------------
        // ROLE 1: Unauthenticated Guest -> All 9 endpoints return 302 (Redirect to login)
        // -------------------------------------------------------------
        $this->get(route('tables.index'))->assertRedirect(route('login'));
        $this->get(route('tables.create'))->assertRedirect(route('login'));
        $this->post(route('tables.store'), ['table_name' => 'T-Guest'])->assertRedirect(route('login'));
        $this->get(route('tables.show', $this->tableAlpha1->id))->assertRedirect(route('login'));
        $this->get(route('tables.edit', $this->tableAlpha1->id))->assertRedirect(route('login'));
        $this->put(route('tables.update', $this->tableAlpha1->id), ['table_name' => 'T-Guest'])->assertRedirect(route('login'));
        $this->delete(route('tables.destroy', $this->tableAlpha1->id))->assertRedirect(route('login'));
        $this->post(route('tables.rotate-qr', $this->tableAlpha1->id))->assertRedirect(route('login'));
        $this->post(route('tables.release', $this->tableAlpha1->id))->assertRedirect(route('login'));

        // -------------------------------------------------------------
        // ROLE 2: Customer Role -> All 9 endpoints return 403 Forbidden
        // -------------------------------------------------------------
        $this->actingAs($this->userCustomer);
        session(['active_store_id' => $this->storeAlpha1->id]);
        app(TenantContext::class)->setTenantId($this->tenantAlpha->id);
        app(TenantContext::class)->setStoreId($this->storeAlpha1->id);

        $this->get(route('tables.index'))->assertStatus(403);
        $this->get(route('tables.create'))->assertStatus(403);
        $this->post(route('tables.store'), ['table_name' => 'T-Cust'])->assertStatus(403);
        $this->get(route('tables.show', $this->tableAlpha1->id))->assertStatus(403);
        $this->get(route('tables.edit', $this->tableAlpha1->id))->assertStatus(403);
        $this->put(route('tables.update', $this->tableAlpha1->id), ['table_name' => 'T-Cust'])->assertStatus(403);
        $this->delete(route('tables.destroy', $this->tableAlpha1->id))->assertStatus(403);
        $this->post(route('tables.rotate-qr', $this->tableAlpha1->id))->assertStatus(403);
        $this->post(route('tables.release', $this->tableAlpha1->id))->assertStatus(403);

        // -------------------------------------------------------------
        // ROLE 3: Cashier (Kasir) Role -> All 9 endpoints return 403 Forbidden
        // -------------------------------------------------------------
        $this->actingAs($this->userCashier);

        $this->get(route('tables.index'))->assertStatus(403);
        $this->get(route('tables.create'))->assertStatus(403);
        $this->post(route('tables.store'), ['table_name' => 'T-Cashier'])->assertStatus(403);
        $this->get(route('tables.show', $this->tableAlpha1->id))->assertStatus(403);
        $this->get(route('tables.edit', $this->tableAlpha1->id))->assertStatus(403);
        $this->put(route('tables.update', $this->tableAlpha1->id), ['table_name' => 'T-Cashier'])->assertStatus(403);
        $this->delete(route('tables.destroy', $this->tableAlpha1->id))->assertStatus(403);
        $this->post(route('tables.rotate-qr', $this->tableAlpha1->id))->assertStatus(403);
        $this->post(route('tables.release', $this->tableAlpha1->id))->assertStatus(403);

        // -------------------------------------------------------------
        // ROLE 4: Cross-Store Manager (Assigned only to Store Alpha 1, targeting Table Alpha 2)
        // -------------------------------------------------------------
        $this->actingAs($this->userManagerAlpha1);
        session(['active_store_id' => $this->storeAlpha1->id]);
        app(TenantContext::class)->setTenantId($this->tenantAlpha->id);
        app(TenantContext::class)->setStoreId($this->storeAlpha1->id);

        // Can access index and create for own store context
        $this->get(route('tables.index'))->assertStatus(200);
        $this->get(route('tables.create'))->assertStatus(200);

        // Prohibited from viewing, editing, updating, deleting, rotating, releasing Table in Store Alpha 2
        $respShowCross = $this->get(route('tables.show', $this->tableAlpha2->id));
        $this->assertTrue(in_array($respShowCross->getStatusCode(), [403, 404], true));

        $respEditCross = $this->get(route('tables.edit', $this->tableAlpha2->id));
        $this->assertTrue(in_array($respEditCross->getStatusCode(), [403, 404], true));

        $respUpdateCross = $this->put(route('tables.update', $this->tableAlpha2->id), [
            'table_name' => 'T-Alpha-2-Hijack',
            'capacity' => 12,
            'area' => 'Patio',
            'operational_status' => 'available',
            'is_active' => 1,
        ]);
        $this->assertTrue(in_array($respUpdateCross->getStatusCode(), [403, 404], true));

        $respDeleteCross = $this->delete(route('tables.destroy', $this->tableAlpha2->id));
        $this->assertTrue(in_array($respDeleteCross->getStatusCode(), [403, 404], true));

        $respRotateCross = $this->post(route('tables.rotate-qr', $this->tableAlpha2->id));
        $this->assertTrue(in_array($respRotateCross->getStatusCode(), [403, 404], true));

        $respReleaseCross = $this->post(route('tables.release', $this->tableAlpha2->id));
        $this->assertTrue(in_array($respReleaseCross->getStatusCode(), [403, 404], true));

        // -------------------------------------------------------------
        // ROLE 5: Valid Store Manager (Assigned to Store Alpha 1, targeting Table Alpha 1)
        // -------------------------------------------------------------
        // View endpoints return 200 OK
        $this->get(route('tables.index'))->assertStatus(200);
        $this->get(route('tables.create'))->assertStatus(200);
        $this->get(route('tables.show', $this->tableAlpha1->id))->assertStatus(200);
        $this->get(route('tables.edit', $this->tableAlpha1->id))->assertStatus(200);

        // Store new table in own store
        $respStore = $this->post(route('tables.store'), [
            'store_id' => $this->storeAlpha1->id,
            'table_name' => 'T-Alpha-1-New',
            'capacity' => 4,
            'area' => 'Indoor Main',
            'operational_status' => 'available',
            'is_active' => 1,
        ]);
        $respStore->assertRedirect(route('tables.index'));
        $this->assertDatabaseHas('tables', [
            'tenant_id' => $this->tenantAlpha->id,
            'store_id' => $this->storeAlpha1->id,
            'table_name' => 'T-Alpha-1-New',
        ]);

        // Update own store table
        $respUpdate = $this->put(route('tables.update', $this->tableAlpha1->id), [
            'table_name' => 'T-Alpha-1-Updated',
            'capacity' => 8,
            'area' => 'VIP Corner',
            'operational_status' => 'available',
            'is_active' => 1,
        ]);
        $respUpdate->assertRedirect(route('tables.index'));
        $this->assertDatabaseHas('tables', [
            'id' => $this->tableAlpha1->id,
            'table_name' => 'T-Alpha-1-Updated',
            'capacity' => 8,
        ]);

        // Rotate QR for own store table
        $respRotate = $this->post(route('tables.rotate-qr', $this->tableAlpha1->id));
        $respRotate->assertRedirect(route('tables.index'));

        // Release own store table
        $respRelease = $this->post(route('tables.release', $this->tableAlpha1->id));
        $respRelease->assertRedirect(route('tables.index'));

        // Destroy own store table
        $respDestroy = $this->delete(route('tables.destroy', $this->tableAlpha1->id));
        $respDestroy->assertRedirect(route('tables.index'));
        $this->assertSoftDeleted('tables', ['id' => $this->tableAlpha1->id]);
    }

    /**
     * DEF-11: Validate Test Database Migrations & Schema Invariants in yovel_pos_test.
     */
    public function test_def11_test_database_schema_and_migration_integrity(): void
    {
        $this->assertSame('yovel_pos_test', DB::connection()->getDatabaseName());

        // 1. Table operational_units must exist
        $this->assertTrue(Schema::hasTable('operational_units'), 'operational_units table must exist');

        // 2. Required columns in operational_units
        $requiredColumns = ['id', 'tenant_id', 'store_id', 'name', 'code', 'unit_type', 'is_default', 'is_active', 'created_at', 'updated_at'];
        foreach ($requiredColumns as $col) {
            $this->assertTrue(
                Schema::hasColumn('operational_units', $col),
                "operational_units table must have column '{$col}'"
            );
        }

        // 3. Check for broken foreign keys in test database pointing to non-existent tables
        $brokenFks = DB::select("
            SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND REFERENCED_TABLE_NAME IS NOT NULL
              AND REFERENCED_TABLE_NAME NOT IN (
                  SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()
              )
        ");

        $this->assertEmpty(
            $brokenFks,
            'There must be 0 broken foreign key constraints pointing to missing tables in yovel_pos_test'
        );
    }
}
