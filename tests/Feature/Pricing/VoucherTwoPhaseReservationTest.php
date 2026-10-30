<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Models\Customer;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Enums\VoucherDiscountTypeEnum;
use App\Modules\Customers\Enums\VoucherStatusEnum;
use App\Modules\Customers\Models\Voucher;
use App\Modules\Customers\Services\VoucherService;
use App\Modules\Pricing\Models\PromotionReservation;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class VoucherTwoPhaseReservationTest extends TestCase
{
    use RefreshDatabase;

    private VoucherService $voucherService;

    private Tenant $tenant;

    private Store $store;

    private User $user;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->voucherService = app(VoucherService::class);

        $this->tenant = Tenant::create([
            'name' => 'Voucher 2Phase Tenant',
            'slug' => 'voucher-2phase-tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store Voucher',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
        ]);
    }

    public function test_voucher_two_phase_reservation_commit_and_release_lifecycle(): void
    {
        $voucher = Voucher::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'DISC20K',
            'name' => 'Voucher Diskon Rp20.000',
            'discount_type' => VoucherDiscountTypeEnum::FIXED_AMOUNT,
            'discount_value' => 20000.00,
            'min_order_amount' => 50000.00,
            'max_uses' => 5,
            'used_count' => 0,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addDays(10),
            'status' => VoucherStatusEnum::ACTIVE,
        ]);

        $cartUuid = Str::uuid()->toString();

        // Fase 1: Reserve kuota voucher
        $reservation = $this->voucherService->reserveVoucher(
            code: 'DISC20K',
            cartUuid: $cartUuid,
            storeId: $this->store->id,
            customerId: null,
            reservedAmount: 20000.00
        );

        $this->assertEquals(PromotionReservation::STATUS_RESERVED, $reservation->status);
        $this->assertEquals($cartUuid, $reservation->cart_uuid);
        $this->assertGreaterThan(now()->addSeconds(110), $reservation->expires_at);

        // Fase 2: Commit kuota saat pesanan berhasil terbayar
        $this->voucherService->commitVoucher($cartUuid, 101);

        $this->assertEquals(PromotionReservation::STATUS_COMMITTED, $reservation->fresh()->status);
        $this->assertEquals(101, $reservation->fresh()->order_id);
        $this->assertEquals(1, $voucher->fresh()->used_count);
    }

    public function test_release_voucher_cancels_reservation(): void
    {
        Voucher::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'BATAL10K',
            'name' => 'Voucher Batal',
            'discount_type' => VoucherDiscountTypeEnum::FIXED_AMOUNT,
            'discount_value' => 10000.00,
            'min_order_amount' => 20000.00,
            'max_uses' => 2,
            'used_count' => 0,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addDays(10),
            'status' => VoucherStatusEnum::ACTIVE,
        ]);

        $cartUuid = Str::uuid()->toString();

        $reservation = $this->voucherService->reserveVoucher(
            code: 'BATAL10K',
            cartUuid: $cartUuid,
            storeId: $this->store->id,
        );

        $this->assertEquals(PromotionReservation::STATUS_RESERVED, $reservation->status);

        // Kasir membatalkan cart / melepaskan kupon
        $this->voucherService->releaseVoucher($cartUuid);

        $this->assertEquals(PromotionReservation::STATUS_RELEASED, $reservation->fresh()->status);
    }

    public function test_artisan_command_releases_expired_reservations(): void
    {
        $voucher = Voucher::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'EXPIRE-CMD',
            'name' => 'Voucher Expire',
            'discount_type' => VoucherDiscountTypeEnum::FIXED_AMOUNT,
            'discount_value' => 5000.00,
            'max_uses' => 1,
            'used_count' => 0,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addDays(10),
            'status' => VoucherStatusEnum::ACTIVE,
        ]);

        $cartUuid = Str::uuid()->toString();

        $reservation = PromotionReservation::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'voucher_id' => $voucher->id,
            'cart_uuid' => $cartUuid,
            'reserved_amount' => 5000.00,
            'status' => PromotionReservation::STATUS_RESERVED,
            'expires_at' => now()->subSeconds(10), // Sudah kedaluwarsa 10 detik lalu
        ]);

        $exitCode = Artisan::call('promotions:release-expired');

        $this->assertEquals(0, $exitCode);
        $this->assertEquals(PromotionReservation::STATUS_RELEASED, $reservation->fresh()->status);
    }
}
