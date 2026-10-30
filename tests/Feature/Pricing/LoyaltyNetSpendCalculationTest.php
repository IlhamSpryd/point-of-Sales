<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Models\Customer;
use App\Models\CustomerLoyaltyAccount;
use App\Models\LoyaltyLedger;
use App\Models\LoyaltyTier;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Services\LoyaltyService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LoyaltyNetSpendCalculationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected User $user;
    protected Customer $customer;
    protected LoyaltyService $loyaltyService;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = Tenant::create([
            'name' => 'Loyalty Net Spend Tenant',
            'domain' => 'loyalty-spend.pos.test',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Loyalty Store',
            'code' => 'STR-LOYALTY',
            'is_active' => true,
        ]);

        $role = Role::firstOrCreate(
            ['name' => 'Manager', 'tenant_id' => $this->tenant->id],
            ['guard_name' => 'web']
        );

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'name' => 'Cashier Loyalty',
            'username' => 'cashier_loyalty',
            'email' => 'cashier_loyalty@test.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'John Loyalty',
            'phone' => '081234567890',
            'email' => 'john.loyalty@example.com',
            'is_active' => true,
        ]);

        $this->loyaltyService = app(LoyaltyService::class);
    }

    public function test_loyalty_points_earned_strictly_from_dpp_ignoring_tax_and_service(): void
    {
        // Subtotal: 100.000, Diskon: 20.000 -> DPP = 80.000
        // Service (5%): 4.000, Tax (10%): 8.400 -> Grand Total: 92.400
        // Poin harus dihitung dari DPP (80.000), BUKAN dari Grand Total (92.400)!
        // Formula: floor(80000 / 10000) * 1.00 = 8 poin (bukan 9 poin).
        $dpp = 80000.00;
        $points = $this->loyaltyService->calculateEarnablePoints($this->customer, $dpp);

        $this->assertEquals(8, $points);

        // Grand Total 92.400 jika salah hitung akan menghasilkan 9 poin
        $grandTotalPoints = $this->loyaltyService->calculateEarnablePoints($this->customer, 92400.00);
        $this->assertEquals(9, $grandTotalPoints);
        $this->assertNotEquals($grandTotalPoints, $points, 'Komponen pajak dan service charge dilarang menambah poin loyalitas!');
    }

    public function test_loyalty_points_with_tier_multiplier(): void
    {
        // Buat tier Silver dengan multiplier 1.25
        $silverTier = LoyaltyTier::create([
            'tenant_id' => $this->tenant->id,
            'tier_code' => 'TIER-SLV',
            'name' => 'Silver',
            'min_points' => 500,
            'points_multiplier' => 1.25,
            'is_active' => true,
        ]);

        // Simulasikan customer memiliki akun dengan tier Silver
        DB::table('customer_loyalty_accounts')->insert([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'current_points' => 100,
            'lifetime_points_earned' => 500,
            'current_tier_id' => $silverTier->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // DPP: 80.000 -> floor(80000 / 10000) * 1.25 = 8 * 1.25 = 10 poin
        $points = $this->loyaltyService->calculateEarnablePoints($this->customer, 80000.00);
        $this->assertEquals(10, $points);
    }

    public function test_loyalty_mutation_is_append_only_via_ledger_and_synced_by_db_trigger(): void
    {
        $result = $this->loyaltyService->earnPoints(
            customer: $this->customer,
            points: 15,
            reference: 'Poin Belanja Perdana'
        );

        $this->assertEquals(15, $result['points_delta']);
        $this->assertEquals(15, $result['balance_after']);

        // Verifikasi entri tercatat di loyalty_ledger
        $this->assertDatabaseHas('loyalty_ledger', [
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'type' => 'earn',
            'points' => 15,
            'balance_after' => 15,
        ]);

        // Verifikasi trigger DB trg_loyalty_ledger_sync_account menyinkronkan saldo
        $account = DB::table('customer_loyalty_accounts')
            ->where('customer_id', $this->customer->id)
            ->first();

        $this->assertNotNull($account);
        $this->assertEquals(15, (int) $account->current_points);
        $this->assertEquals(15, (int) $account->lifetime_points_earned);
    }

    public function test_point_redemption_conversion_rate(): void
    {
        // 1 Poin = Rp100
        $discountAmount = $this->loyaltyService->calculateRedeemValue(50);
        $this->assertEquals(5000.00, $discountAmount);

        $requiredPoints = $this->loyaltyService->calculatePointsForDiscount(7500.00);
        $this->assertEquals(75, $requiredPoints);
    }
}
