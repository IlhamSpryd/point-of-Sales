<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Models\Approval;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Pricing\Models\PromotionCampaign;
use App\Modules\Pricing\Models\PromotionReservation;
use App\Modules\Pricing\Models\PromotionReward;
use App\Modules\Pricing\Models\PromotionRule;
use App\Modules\Pricing\Models\PromotionStoreMapping;
use App\Services\Context\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PromotionSchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $user;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Promotion Test Tenant',
            'slug' => 'promo-test-tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cabang Promosi',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
        ]);
    }

    public function test_campaign_code_unique_per_tenant(): void
    {
        PromotionCampaign::create([
            'tenant_id' => $this->tenant->id,
            'campaign_code' => 'PROMO-MERDEKA',
            'name' => 'Promo Kemerdekaan',
            'type' => PromotionCampaign::TYPE_AUTOMATIC_DISCOUNT,
            'start_datetime' => now(),
            'end_datetime' => now()->addDays(7),
            'status' => PromotionCampaign::STATUS_ACTIVE,
        ]);

        $this->expectException(QueryException::class);

        // Mencoba membuat kode yang sama pada tenant yang sama harus gagal
        PromotionCampaign::create([
            'tenant_id' => $this->tenant->id,
            'campaign_code' => 'PROMO-MERDEKA',
            'name' => 'Promo Duplikat',
            'type' => PromotionCampaign::TYPE_AUTOMATIC_DISCOUNT,
            'start_datetime' => now(),
            'end_datetime' => now()->addDays(7),
            'status' => PromotionCampaign::STATUS_ACTIVE,
        ]);
    }

    public function test_campaign_rules_and_rewards_cascade_deletion(): void
    {
        $campaign = PromotionCampaign::create([
            'tenant_id' => $this->tenant->id,
            'campaign_code' => 'HAPPY-HOUR-JUMAT',
            'name' => 'Happy Hour Hari Jumat',
            'type' => PromotionCampaign::TYPE_HAPPY_HOUR,
            'start_datetime' => now(),
            'end_datetime' => now()->addDays(30),
            'status' => PromotionCampaign::STATUS_ACTIVE,
        ]);

        $rule = PromotionRule::create([
            'tenant_id' => $this->tenant->id,
            'campaign_id' => $campaign->id,
            'rule_type' => PromotionRule::RULE_CART_TOTAL,
            'min_order_amount' => 50000.00,
        ]);

        $reward = PromotionReward::create([
            'tenant_id' => $this->tenant->id,
            'rule_id' => $rule->id,
            'reward_type' => PromotionReward::REWARD_PERCENTAGE_DISCOUNT,
            'discount_percentage' => 15.00,
            'max_discount_amount' => 20000.00,
        ]);

        $mapping = PromotionStoreMapping::create([
            'tenant_id' => $this->tenant->id,
            'campaign_id' => $campaign->id,
            'store_id' => $this->store->id,
        ]);

        $this->assertDatabaseHas('promotion_campaigns', ['id' => $campaign->id]);
        $this->assertDatabaseHas('promotion_rules', ['id' => $rule->id]);
        $this->assertDatabaseHas('promotion_rewards', ['id' => $reward->id]);
        $this->assertDatabaseHas('promotion_store_mappings', ['id' => $mapping->id]);

        // Force delete campaign untuk menguji ON DELETE CASCADE di database
        $campaign->forceDelete();

        $this->assertDatabaseMissing('promotion_campaigns', ['id' => $campaign->id]);
        $this->assertDatabaseMissing('promotion_rules', ['id' => $rule->id]);
        $this->assertDatabaseMissing('promotion_rewards', ['id' => $reward->id]);
        $this->assertDatabaseMissing('promotion_store_mappings', ['id' => $mapping->id]);
    }

    public function test_approvals_cannot_be_deleted_due_to_immutability_trigger(): void
    {
        $approver = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
        ]);

        $approval = Approval::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'requester_user_id' => $this->user->id,
            'approver_user_id' => $approver->id,
            'action_code' => Approval::ACTION_MANUAL_DISCOUNT,
            'amount' => 15000.00,
            'reason' => 'Pelanggan VIP',
            'status' => Approval::STATUS_APPROVED,
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Approvals are immutable audit records and cannot be deleted.');

        $approval->delete();
    }

    public function test_consumed_approval_is_immutable_against_modifications(): void
    {
        $approver = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
        ]);

        $approval = Approval::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'requester_user_id' => $this->user->id,
            'approver_user_id' => $approver->id,
            'action_code' => Approval::ACTION_MANUAL_DISCOUNT,
            'amount' => 25000.00,
            'reason' => 'Diskon Event',
            'status' => Approval::STATUS_APPROVED,
        ]);

        // Konsumsi token persetujuan
        $approval->update([
            'status' => Approval::STATUS_CONSUMED,
            'consumed_at' => now(),
            'consumed_by_order_id' => 999,
        ]);

        $this->assertEquals(Approval::STATUS_CONSUMED, $approval->fresh()->status);

        // Mencoba memanipulasi approval yang sudah CONSUMED harus ditolak oleh trigger SQL
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Approval record is immutable once CONSUMED.');

        $approval->update([
            'amount' => 10000.00,
        ]);
    }

    public function test_promotion_reservation_lifecycle_and_indexes(): void
    {
        $reservation = PromotionReservation::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'cart_uuid' => Str::uuid()->toString(),
            'reserved_amount' => 10000.00,
            'status' => PromotionReservation::STATUS_RESERVED,
            'expires_at' => now()->addSeconds(120),
        ]);

        $this->assertDatabaseHas('promotion_reservations', [
            'id' => $reservation->id,
            'status' => PromotionReservation::STATUS_RESERVED,
        ]);

        $reservation->update([
            'status' => PromotionReservation::STATUS_COMMITTED,
            'order_id' => 555,
        ]);

        $this->assertEquals(PromotionReservation::STATUS_COMMITTED, $reservation->fresh()->status);
        $this->assertEquals(555, $reservation->fresh()->order_id);
    }
}
