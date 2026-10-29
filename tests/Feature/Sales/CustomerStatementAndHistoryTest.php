<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\ExportTaskStatus;
use App\Enums\StockMovementType;
use App\Jobs\ProcessCustomerStatementExportJob;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ExportTask;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Enums\CreditStatusEnum;
use App\Modules\Customers\Models\CustomerCreditAccount;
use App\Modules\Customers\Services\CustomerCreditService;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Sales\Services\AccountsReceivableService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Policies\ExportTaskPolicy;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomerStatementAndHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected AccountsReceivableService $arService;

    protected SalesOrderService $salesOrderService;

    protected InventoryLedgerService $inventoryLedgerService;

    protected CustomerCreditService $creditService;

    protected Tenant $tenantA;

    protected Store $storeA;

    protected User $ownerA;

    protected Customer $customerA;

    protected Product $product;

    protected Tenant $tenantB;

    protected Store $storeB;

    protected User $ownerB;

    protected Customer $customerB;

    protected function afterSubclassSetUp(): void
    {
        Model::unguard();

        $this->arService = app(AccountsReceivableService::class);
        $this->salesOrderService = app(SalesOrderService::class);
        $this->inventoryLedgerService = app(InventoryLedgerService::class);
        $this->creditService = app(CustomerCreditService::class);

        $context = app(TenantContext::class);

        // Setup Tenant A
        $this->tenantA = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'PT Semen Perkasa Indonesia',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);
        });

        $context->setTenantId($this->tenantA->id);

        $this->storeA = Store::create([
            'name' => 'Gudang Utama Jakarta',
        ]);
        $context->setStoreId($this->storeA->id);

        $ownerRoleA = Role::firstOrCreate(
            ['name' => 'Owner', 'tenant_id' => $this->tenantA->id],
            ['display_name' => 'Owner', 'is_admin' => true, 'is_active' => 1]
        );

        $this->ownerA = User::create([
            'name' => 'Hendra Owner A',
            'email' => 'hendra@semenperkasa.test',
            'password' => bcrypt('password'),
            'role_id' => $ownerRoleA->id,
            'is_active' => true,
        ]);
        $this->ownerA->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

        $this->customerA = Customer::create([
            'customer_code' => 'CUST-B2B-SEM01',
            'name' => 'PT Kontraktor Mandiri',
            'customer_type' => 'COMPANY',
            'company_name' => 'PT Kontraktor Mandiri Sejahtera',
            'email' => 'finance@kontraktormandiri.test',
            'phone' => '08119998881',
            'is_active' => true,
            'is_loyalty_eligible' => false,
        ]);

        CustomerCreditAccount::create([
            'tenant_id' => $this->tenantA->id,
            'customer_id' => $this->customerA->id,
            'credit_limit' => '100000000.00',
            'reserved_exposure' => '0.00',
            'invoiced_exposure' => '0.00',
            'unapplied_credit_balance' => '0.00',
            'credit_status' => CreditStatusEnum::ACTIVE,
            'payment_terms_days' => 30,
            'grace_period_days' => 7,
        ]);

        $category = Category::create([
            'category_name' => 'Bahan Bangunan',
            'category_code' => 'SEMEN',
        ]);

        $this->product = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Semen Portland 50kg',
            'product_code' => 'SMN-50KG',
            'product_price' => 75000.00,
            'is_active' => true,
            'status' => 'active',
        ]);

        $this->inventoryLedgerService->recordMovement(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            itemType: 'product',
            itemId: (int) $this->product->id,
            quantityDelta: '1000.0000',
            type: StockMovementType::InitialSync,
            unitCost: '55000.00',
            reason: 'Opening stock semen',
            userId: $this->ownerA->id
        );

        // Setup Tenant B
        $this->tenantB = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'PT Logistik Antar Nusa',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);
        });

        $context->setTenantId($this->tenantB->id);
        $this->storeB = Store::create([
            'name' => 'Gudang Surabaya B',
        ]);
        $context->setStoreId($this->storeB->id);

        $ownerRoleB = Role::firstOrCreate(
            ['name' => 'Owner', 'tenant_id' => $this->tenantB->id],
            ['display_name' => 'Owner', 'is_admin' => true, 'is_active' => 1]
        );

        $this->ownerB = User::create([
            'name' => 'Suryo Owner B',
            'email' => 'suryo@logistik.test',
            'password' => bcrypt('password'),
            'role_id' => $ownerRoleB->id,
            'is_active' => true,
        ]);
        $this->ownerB->stores()->attach($this->storeB->id, ['tenant_id' => $this->tenantB->id]);

        $this->customerB = Customer::create([
            'customer_code' => 'CUST-B2B-LOG01',
            'name' => 'PT Mitra Logistik B',
            'customer_type' => 'COMPANY',
            'email' => 'mitra@logistikb.test',
            'phone' => '08128887772',
            'is_active' => true,
            'is_loyalty_eligible' => false,
        ]);

        // Restore context to Tenant A
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);
        $this->actingAs($this->ownerA);
    }

    public function test_customer_statement_formula_reconciliation(): void
    {
        // 1. Create and confirm SO 1
        $so1 = $this->salesOrderService->createOrder([
            'customer_id' => $this->customerA->id,
            'store_id' => $this->storeA->id,
            'payment_terms_days' => 30,
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 100,
                    'unit_price' => '75000.00',
                ],
            ],
        ], $this->ownerA);

        $confirmedSO1 = $this->salesOrderService->confirmOrder($so1, $this->ownerA);
        $soLine1 = $confirmedSO1->lines->first();
        $this->salesOrderService->fulfillOrder($confirmedSO1, [
            ['line_id' => $soLine1->id, 'quantity' => '100.0000'],
        ], $this->ownerA);

        // Post Invoice 1 (Rp 7.500.000)
        $invoice1 = $this->arService->createInvoice(
            source: $confirmedSO1,
            options: ['post_immediately' => true],
            actor: $this->ownerA
        );

        // 2. Allocate partial payment (Rp 2.500.000) on Invoice 1
        $this->arService->recordAndAllocatePayment(
            customerId: (int) $this->customerA->id,
            paymentData: [
                'amount' => '2500000.00',
                'payment_reference' => 'PAY-REC-001',
            ],
            invoiceAllocations: [
                ['invoice_id' => $invoice1->id, 'amount' => '2500000.00'],
            ],
            actor: $this->ownerA
        );

        // 3. Create and confirm SO 2
        $so2 = $this->salesOrderService->createOrder([
            'customer_id' => $this->customerA->id,
            'store_id' => $this->storeA->id,
            'payment_terms_days' => 30,
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 50,
                    'unit_price' => '75000.00',
                ],
            ],
        ], $this->ownerA);

        $confirmedSO2 = $this->salesOrderService->confirmOrder($so2, $this->ownerA);
        $soLine2 = $confirmedSO2->lines->first();
        $this->salesOrderService->fulfillOrder($confirmedSO2, [
            ['line_id' => $soLine2->id, 'quantity' => '50.0000'],
        ], $this->ownerA);

        // Post Invoice 2 (Rp 3.750.000)
        $invoice2 = $this->arService->createInvoice(
            source: $confirmedSO2,
            options: ['post_immediately' => true],
            actor: $this->ownerA
        );

        // 4. Generate Statement for entire month
        $start = Carbon::now()->startOfMonth();
        $end = Carbon::now()->endOfMonth();

        $statement = $this->arService->generateCustomerStatement($this->customerA->id, $start, $end);

        $this->assertEquals('0.00', $statement['opening_balance']);
        $this->assertCount(3, $statement['transactions']); // Inv1 debit, Pay1 credit, Inv2 debit

        $sumDebit = '0.00';
        $sumCredit = '0.00';
        foreach ($statement['transactions'] as $tx) {
            $sumDebit = bcadd($sumDebit, (string) $tx['debit_amount'], 2);
            $sumCredit = bcadd($sumCredit, (string) $tx['credit_amount'], 2);
        }

        // Formula Invariant: Opening + Debit - Credit = Closing
        $expectedClosing = bcsub(bcadd($statement['opening_balance'], $sumDebit, 2), $sumCredit, 2);
        $this->assertEquals($expectedClosing, $statement['closing_balance']);
        $this->assertEquals('8750000.00', $statement['closing_balance']); // 7.500.000 - 2.500.000 + 3.750.000

        // Invariant matches credit account invoiced_exposure
        $creditAccount = CustomerCreditAccount::where('customer_id', $this->customerA->id)->first();
        $this->assertEquals($statement['closing_balance'], (string) $creditAccount->invoiced_exposure);
    }

    public function test_historical_snapshot_immutability(): void
    {
        // 1. Post Invoice with customerA
        $so = $this->salesOrderService->createOrder([
            'customer_id' => $this->customerA->id,
            'store_id' => $this->storeA->id,
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 10,
                    'unit_price' => '75000.00',
                ],
            ],
        ], $this->ownerA);

        $confirmedSO = $this->salesOrderService->confirmOrder($so, $this->ownerA);
        $soLine = $confirmedSO->lines->first();
        $this->salesOrderService->fulfillOrder($confirmedSO, [
            ['line_id' => $soLine->id, 'quantity' => '10.0000'],
        ], $this->ownerA);

        $invoice = $this->arService->createInvoice(
            source: $confirmedSO,
            options: ['post_immediately' => true],
            actor: $this->ownerA
        );

        $originalCustomerName = $invoice->customer_snapshot_json['name'] ?? null;
        $originalCustomerCode = $invoice->customer_snapshot_json['customer_code'] ?? null;
        $this->assertEquals('PT Kontraktor Mandiri', $originalCustomerName);
        $this->assertEquals('CUST-B2B-SEM01', $originalCustomerCode);

        // 2. Modify customer A's master record
        $this->customerA->update([
            'name' => 'PT Kontraktor Mandiri Persero TBK (Changed)',
            'customer_code' => 'CUST-B2B-RENAMED',
        ]);

        // 3. Assert Invoice snapshot is untouched
        $invoice->refresh();
        $this->assertEquals('PT Kontraktor Mandiri', $invoice->customer_snapshot_json['name']);
        $this->assertEquals('CUST-B2B-SEM01', $invoice->customer_snapshot_json['customer_code']);
    }

    public function test_customer_statement_export_job_execution(): void
    {
        Storage::fake('local');

        $start = Carbon::now()->startOfMonth();
        $end = Carbon::now()->endOfMonth();

        $task = ExportTask::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'requested_by' => $this->ownerA->id,
            'type' => 'customer_statement',
            'parameters' => [
                'customer_id' => $this->customerA->id,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ],
            'status' => ExportTaskStatus::Pending,
        ]);

        $job = new ProcessCustomerStatementExportJob($task, $this->tenantA->id, $this->storeA->id);
        $job->handle();

        $task->refresh();
        $this->assertEquals(ExportTaskStatus::Completed, $task->status);
        $this->assertNotNull($task->file_path);
        $this->assertStringContainsString("exports/tenant_{$this->tenantA->id}", $task->file_path);
        $this->assertNotNull($task->completed_at);
        $this->assertNull($task->error_message);
        Storage::disk('local')->assertExists($task->file_path);
    }

    public function test_cross_tenant_isolation_on_customer_statement_and_export(): void
    {
        $taskA = ExportTask::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'requested_by' => $this->ownerA->id,
            'type' => 'customer_statement',
            'parameters' => [
                'customer_id' => $this->customerA->id,
                'start_date' => Carbon::now()->startOfMonth()->toDateString(),
                'end_date' => Carbon::now()->toDateString(),
            ],
            'status' => ExportTaskStatus::Pending,
        ]);

        // 1. ExportTaskPolicy rejects cross-tenant download
        $policy = app(ExportTaskPolicy::class);
        $canDownload = $policy->download($this->ownerB, $taskA);
        $this->assertFalse($canDownload, 'User Tenant B must be denied download of Tenant A ExportTask.');

        // 2. Job throws exception if invoked with mismatched tenant
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tenant ID mismatch between job and task.');

        $job = new ProcessCustomerStatementExportJob($taskA, $this->tenantB->id, $this->storeB->id);
        $job->handle();
    }
}
