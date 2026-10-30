<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Enums\StoreCreditLedgerTypeEnum;
use App\Modules\Customers\Models\StoreCreditAccount;
use App\Modules\Customers\Services\StoreCreditService;
use App\Modules\Sales\Services\TransactionService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StoreCreditTenderIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected User $user;
    protected Shift $shift;
    protected Customer $customer;
    protected Product $product;
    protected StoreCreditService $storeCreditService;
    protected TransactionService $transactionService;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = Tenant::create([
            'name' => 'Store Credit Tenant',
            'domain' => 'storecredit.pos.test',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store Credit Outlet',
            'code' => 'STR-SC',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $role = Role::firstOrCreate(
            ['name' => 'Manager', 'tenant_id' => $this->tenant->id],
            ['guard_name' => 'web']
        );

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'name' => 'Cashier SC',
            'username' => 'cashier_sc',
            'email' => 'cashier_sc@test.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $this->shift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'starting_cash' => 100000,
            'opened_at' => now(),
            'status' => 'open',
        ]);

        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Charlie Credit',
            'phone' => '087788990011',
            'email' => 'charlie.credit@example.com',
            'is_active' => true,
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Food & Snacks',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'name' => 'Premium Wagyu Bowl',
            'sku' => 'SKU-WAGYU-01',
            'product_price' => 100000.00,
            'track_inventory' => false,
            'is_active' => true,
        ]);

        $this->storeCreditService = app(StoreCreditService::class);
        $this->transactionService = app(TransactionService::class);
    }

    public function test_split_tender_payment_with_store_credit_and_cash(): void
    {
        // 1. Tambah saldo Store Credit ke akun customer sebesar Rp 75.000
        $this->storeCreditService->issueStoreCredit(
            customer: $this->customer,
            amount: '75000.00',
            entryType: StoreCreditLedgerTypeEnum::OVERPAYMENT_ISSUE,
            storeId: $this->store->id,
            actor: $this->user
        );

        $account = StoreCreditAccount::where('customer_id', $this->customer->id)->first();
        $this->assertNotNull($account);
        $this->assertEquals('75000.00', $account->current_balance);

        // 2. Transaksi produk Rp 100.000 + Tax 11% (Rp 11.000) = Grand Total Rp 111.000
        // Split: Store Credit Rp 75.000 + Cash Rp 36.000
        $transactionData = [
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => 100000.00,
                ],
            ],
            'payments' => [
                [
                    'method' => 'store_credit',
                    'amount' => 75000,
                ],
                [
                    'method' => 'cash',
                    'amount' => 36000,
                ],
            ],
            'cash_received' => 36000,
        ];

        $order = $this->transactionService->createTransaction($transactionData, $this->user->id, $this->shift->id);

        $this->assertEquals(OrderStatus::Paid, $order->order_status);

        // 3. Verifikasi saldo Store Credit terpotong menjadi 0.00
        $account->refresh();
        $this->assertEquals('0.00', $account->current_balance);

        // 4. Verifikasi mutasi tercatat di store_credit_ledgers dengan entry_type REDEEM_ORDER
        $this->assertDatabaseHas('store_credit_ledgers', [
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'entry_type' => 'REDEEM_ORDER',
            'amount' => '-75000.00',
            'balance_after' => '0.00',
        ]);

        // 5. Invariant Semantik Stored Value: Store Credit adalah tender, BUKAN diskon.
        // DPP tetap utuh Rp 100.000, diskon Rp 0.
        $this->assertEquals(100000.00, (float) $order->subtotal_amount);
        $this->assertEquals(0.00, (float) $order->discount_amount);
        $this->assertEquals(111000.00, (float) $order->order_amount);
    }

    public function test_insufficient_store_credit_balance_is_rejected_without_modifying_order(): void
    {
        // Saldo customer hanya Rp 30.000
        $this->storeCreditService->issueStoreCredit(
            customer: $this->customer,
            amount: '30000.00',
            entryType: StoreCreditLedgerTypeEnum::OVERPAYMENT_ISSUE,
            storeId: $this->store->id,
            actor: $this->user
        );

        // Coba bayar Rp 50.000 menggunakan Store Credit
        $transactionData = [
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => 100000.00,
                ],
            ],
            'payments' => [
                [
                    'method' => 'store_credit',
                    'amount' => 50000,
                ],
                [
                    'method' => 'cash',
                    'amount' => 61000,
                ],
            ],
            'cash_received' => 61000,
        ];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Saldo Store Credit tidak mencukupi');

        try {
            $this->transactionService->createTransaction($transactionData, $this->user->id, $this->shift->id);
        } finally {
            $account = StoreCreditAccount::where('customer_id', $this->customer->id)->first();
            $this->assertEquals('30000.00', $account->current_balance);
        }
    }
}
