<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\HR\Enums\CommissionBasisTypeEnum;
use App\Modules\HR\Enums\CommissionRecordTypeEnum;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use App\Modules\HR\Enums\EmploymentTypeEnum;
use App\Modules\HR\Enums\GenderEnum;
use App\Modules\HR\Enums\MaritalStatusEnum;
use App\Modules\HR\Enums\ReligionEnum;
use App\Modules\HR\Enums\SalaryComponentTypeEnum;
use App\Modules\HR\Models\CommissionRule;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeCompensation;
use App\Modules\HR\Models\JobTitle;
use App\Modules\HR\Models\SalaryComponent;
use App\Modules\HR\Services\CommissionService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CommissionAndCompensationTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    protected Tenant $tenant;

    protected Store $store;

    protected User $user;

    protected Employee $employee;

    protected CommissionService $service;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Commission Corp',
            'code' => 'COMM-CORP',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
            'status' => 'ACTIVE',
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Outlet Sudirman',
            'code' => 'SDR-01',
            'status' => 'ACTIVE',
        ]);
        $context->setStoreId($this->store->id);

        $dept = Department::create([
            'tenant_id' => $this->tenant->id,
            'department_code' => 'SLS-01',
            'name' => 'Sales',
        ]);

        $jobTitle = JobTitle::create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $dept->id,
            'title_code' => 'KASIR-01',
            'name' => 'Senior Cashier',
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'department_id' => $dept->id,
            'job_title_id' => $jobTitle->id,
            'employee_number' => 'EMP-COMM-001',
            'full_name' => 'Siti Rahma',
            'email' => 'siti.comm@corp.com',
            'phone_number' => '081299990003',
            'national_id_number' => '3171010101900003',
            'tax_id_number' => '091234567011003',
            'birth_date' => '1995-08-10',
            'hire_date' => '2023-01-01',
            'gender' => GenderEnum::FEMALE,
            'marital_status' => MaritalStatusEnum::SINGLE,
            'religion' => ReligionEnum::ISLAM,
            'employment_type' => EmploymentTypeEnum::PERMANENT,
            'employment_status' => EmploymentStatusEnum::ACTIVE,
            'is_active' => true,
        ]);

        $this->service = app(CommissionService::class);
    }

    public function test_salary_components_and_employee_compensations(): void
    {
        $baseSalary = SalaryComponent::create([
            'tenant_id' => $this->tenant->id,
            'component_code' => 'BASE_SALARY',
            'component_name' => 'Gaji Pokok',
            'type' => SalaryComponentTypeEnum::EARNING,
            'is_taxable' => true,
            'is_bpjs_kesehatan_subject' => true,
            'is_bpjs_tk_subject' => true,
            'is_fixed' => true,
            'is_active' => true,
        ]);

        $positionAllowance = SalaryComponent::create([
            'tenant_id' => $this->tenant->id,
            'component_code' => 'FIXED_ALLOWANCE',
            'component_name' => 'Tunjangan Jabatan',
            'type' => SalaryComponentTypeEnum::EARNING,
            'is_taxable' => true,
            'is_bpjs_kesehatan_subject' => true,
            'is_bpjs_tk_subject' => true,
            'is_fixed' => true,
            'is_active' => true,
        ]);

        EmployeeCompensation::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'salary_component_id' => $baseSalary->id,
            'amount' => 6000000.00,
            'effective_date' => '2027-01-01',
            'is_active' => true,
        ]);

        EmployeeCompensation::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'salary_component_id' => $positionAllowance->id,
            'amount' => 1500000.00,
            'effective_date' => '2027-01-01',
            'is_active' => true,
        ]);

        $activeComps = EmployeeCompensation::where('employee_id', $this->employee->id)
            ->activeOn('2027-02-15')
            ->get();

        $this->assertCount(2, $activeComps);
        $totalFixed = $activeComps->sum('amount');
        $this->assertEquals(7500000.00, $totalFixed);
    }

    public function test_calculate_and_award_order_commission_with_reversal(): void
    {
        // 1. Buat Category dan Product Promo
        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Beverages',
            'is_active' => true,
        ]);

        $product = Product::forceCreate([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Signature Dark Mocha',
            'product_code' => 'PRD-MOCHA',
            'sku' => 'SKU-MOCHA-01',
            'price' => 35000.00,
            'status' => 'active',
        ]);

        // 2. Buat Aturan Komisi: 2% Omzet Toko & Rp 2.500 per cup Signature Mocha
        $salesRule = CommissionRule::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'rule_name' => 'Komisi Omzet 2%',
            'basis_type' => CommissionBasisTypeEnum::PERCENTAGE_OF_SALES,
            'rate_percent' => 2.0000,
            'min_sales_threshold' => 100000.00,
            'is_active' => true,
            'valid_from' => '2027-01-01',
        ]);

        $productRule = CommissionRule::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'rule_name' => 'Promo Signature Mocha',
            'basis_type' => CommissionBasisTypeEnum::PRODUCT_SPECIFIC,
            'rate_amount' => 2500.00,
            'product_id' => $product->id,
            'is_active' => true,
            'valid_from' => '2027-01-01',
        ]);

        // 3. Buat Order Completed dengan 2 cup mocha
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'order_code' => 'ORD-COMM-001',
            'idempotency_key' => 'idemp-comm-001',
            'order_date' => '2027-02-15',
            'order_amount' => 500000.00,
            'subtotal_amount' => 500000.00,
            'order_status' => OrderStatus::Completed,
        ]);

        $item = OrderItem::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'qty' => 2,
            'order_price' => 35000.00,
            'order_subtotal' => 70000.00,
        ]);

        // 4. Hitung & Berikan Komisi
        $order->load('orderItems');
        $records = $this->service->calculateAndAwardOrderCommission($order, $this->employee->id);

        $this->assertCount(2, $records);

        // Komisi Omzet: 2% x 500.000 = 10.000
        $salesRec = collect($records)->firstWhere('commission_rule_id', $salesRule->id);
        $this->assertNotNull($salesRec);
        $this->assertEquals(10000.00, $salesRec->commission_amount);
        $this->assertEquals(CommissionRecordTypeEnum::STANDARD, $salesRec->type);

        // Komisi Produk: 2 cup x 2.500 = 5.000
        $prodRec = collect($records)->firstWhere('commission_rule_id', $productRule->id);
        $this->assertNotNull($prodRec);
        $this->assertEquals(5000.00, $prodRec->commission_amount);

        // Total periode sebelum retur: 15.000
        $totalPeriod = $this->service->calculateEmployeePeriodCommission(
            $this->tenant->id,
            $this->employee->id,
            now()->subDay(),
            now()->addDay()
        );
        $this->assertEquals(15000.00, $totalPeriod);

        // 5. Retur Pesanan -> Reversal Protokol
        $reversals = $this->service->processReturnReversal((int) $order->id);
        $this->assertCount(2, $reversals);

        $this->assertEquals(-10000.00, $reversals[0]->commission_amount);
        $this->assertEquals(CommissionRecordTypeEnum::REVERSAL, $reversals[0]->type);
        $this->assertEquals(-5000.00, $reversals[1]->commission_amount);

        // Total periode setelah retur penuh: 0.00
        $totalPeriodAfterReturn = $this->service->calculateEmployeePeriodCommission(
            $this->tenant->id,
            $this->employee->id,
            now()->subDay(),
            now()->addDay()
        );
        $this->assertEquals(0.00, $totalPeriodAfterReturn);
    }
}
