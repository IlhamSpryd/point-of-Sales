<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\CustomerLoyaltyAccount;
use App\Models\LoyaltyTier;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Services\LoyaltyService;
use App\Modules\Sales\Services\TransactionService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MembershipTierUpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected User $user;
    protected Shift $shift;
    protected Customer $customer;
    protected LoyaltyTier $bronzeTier;
    protected LoyaltyTier $silverTier;
    protected LoyaltyTier $goldTier;
    protected LoyaltyService $loyaltyService;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = Tenant::create([
            'name' => 'Tier Upgrade Tenant',
            'domain' => 'tier-upgrade.pos.test',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store Tier',
            'code' => 'STR-TIER',
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
            'name' => 'Cashier Tier',
            'username' => 'cashier_tier',
            'email' => 'cashier_tier@test.com',
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
            'name' => 'Alice Tier',
            'phone' => '085566778899',
            'email' => 'alice.tier@example.com',
            'is_active' => true,
        ]);

        $this->bronzeTier = LoyaltyTier::create([
            'tenant_id' => $this->tenant->id,
            'tier_code' => 'TIER-BRZ',
            'name' => 'Bronze',
            'min_points' => 0,
            'points_multiplier' => 1.00,
            'is_active' => true,
        ]);

        $this->silverTier = LoyaltyTier::create([
            'tenant_id' => $this->tenant->id,
            'tier_code' => 'TIER-SLV',
            'name' => 'Silver',
            'min_points' => 500,
            'points_multiplier' => 1.25,
            'is_active' => true,
        ]);

        $this->goldTier = LoyaltyTier::create([
            'tenant_id' => $this->tenant->id,
            'tier_code' => 'TIER-GLD',
            'name' => 'Gold',
            'min_points' => 2500,
            'points_multiplier' => 1.50,
            'is_active' => true,
        ]);

        $this->loyaltyService = app(LoyaltyService::class);
    }

    public function test_customer_tier_upgrades_progressively_based_on_accumulated_points(): void
    {
        // Earn 200 poin (belum mencapai 500 poin untuk Silver)
        $this->loyaltyService->earnPoints(
            customer: $this->customer,
            points: 200,
            reference: 'Belanja Batch 1'
        );

        $tier = $this->loyaltyService->checkAndUpgradeTier($this->customer);
        $this->assertNotNull($tier);
        $this->assertEquals($this->bronzeTier->id, $tier->id);

        $account = DB::table('customer_loyalty_accounts')->where('customer_id', $this->customer->id)->first();
        $this->assertEquals($this->bronzeTier->id, (int) $account->current_tier_id);

        // Earn tambahan 350 poin -> Total lifetime_points_earned = 550 (melebihi syarat Silver 500)
        $this->loyaltyService->earnPoints(
            customer: $this->customer,
            points: 350,
            reference: 'Belanja Batch 2'
        );

        $upgradedTier = $this->loyaltyService->checkAndUpgradeTier($this->customer);
        $this->assertNotNull($upgradedTier);
        $this->assertEquals($this->silverTier->id, $upgradedTier->id);

        $account = DB::table('customer_loyalty_accounts')->where('customer_id', $this->customer->id)->first();
        $this->assertEquals($this->silverTier->id, (int) $account->current_tier_id);

        // Earn tambahan 2000 poin -> Total lifetime_points_earned = 2550 (melebihi syarat Gold 2500)
        $this->loyaltyService->earnPoints(
            customer: $this->customer,
            points: 2000,
            reference: 'Belanja Batch 3'
        );

        $goldUpgradedTier = $this->loyaltyService->checkAndUpgradeTier($this->customer);
        $this->assertNotNull($goldUpgradedTier);
        $this->assertEquals($this->goldTier->id, $goldUpgradedTier->id);

        $account = DB::table('customer_loyalty_accounts')->where('customer_id', $this->customer->id)->first();
        $this->assertEquals($this->goldTier->id, (int) $account->current_tier_id);
    }

    public function test_transaction_service_triggers_tier_upgrade_on_order_completion(): void
    {
        // Buat kategori dan produk dengan harga Rp 5.000.000 -> menghasilkan 500 poin (naik ke Silver)
        $category = \App\Models\Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Elektronik',
            'is_active' => true,
        ]);

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'name' => 'Laptop High End',
            'sku' => 'SKU-LAPTOP-01',
            'product_price' => 5000000.00,
            'track_inventory' => false,
            'is_active' => true,
        ]);

        $transactionService = app(TransactionService::class);

        $transactionData = [
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 5000000.00,
                ],
            ],
            'payments' => [
                [
                    'method' => 'cash',
                    'payment_method' => 'cash',
                    'amount' => 5550000.00,
                ],
            ],
            'cash_received' => 5550000.00,
        ];

        $order = $transactionService->createTransaction($transactionData, $this->user->id, $this->shift->id);

        $this->assertEquals(OrderStatus::Paid, $order->order_status);

        // Cek akun loyalitas customer sudah otomatis naik ke Silver
        $account = DB::table('customer_loyalty_accounts')->where('customer_id', $this->customer->id)->first();
        $this->assertNotNull($account);
        $this->assertEquals(500, (int) $account->current_points);
        $this->assertEquals(500, (int) $account->lifetime_points_earned);
        $this->assertEquals($this->silverTier->id, (int) $account->current_tier_id);
    }
}
