<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Enums\VoucherDiscountTypeEnum;
use App\Modules\Customers\Enums\VoucherStatusEnum;
use App\Modules\Customers\Models\Voucher;
use App\Modules\Customers\Services\VoucherService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VoucherQuotaConcurrencyTest extends TestCase
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
            'name' => 'Voucher Concurrency Tenant',
            'slug' => 'voucher-concurrency-tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store Concurrency',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
        ]);
    }

    public function test_second_cashier_rejected_when_voucher_quota_is_reserved_by_first_cashier(): void
    {
        // Voucher dengan kuota hanya 1
        $voucher = Voucher::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'LIMITED1',
            'name' => 'Voucher Langka Kuota 1',
            'discount_type' => VoucherDiscountTypeEnum::FIXED_AMOUNT,
            'discount_value' => 50000.00,
            'min_order_amount' => 100000.00,
            'max_uses' => 1,
            'used_count' => 0,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addDays(10),
            'status' => VoucherStatusEnum::ACTIVE,
        ]);

        $cart1 = Str::uuid()->toString();
        $cart2 = Str::uuid()->toString();

        // Kasir 1 berhasil mereserve kuota sisa 1
        $reservation1 = $this->voucherService->reserveVoucher(
            code: 'LIMITED1',
            cartUuid: $cart1,
            storeId: $this->store->id,
            reservedAmount: 50000.00
        );
        $this->assertNotNull($reservation1);

        // Kasir 2 mencoba validasi / mereserve kuota yang sama saat reservasi Kasir 1 masih aktif (TTL 120s)
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("Kuota voucher 'LIMITED1' telah habis.");

        $this->voucherService->reserveVoucher(
            code: 'LIMITED1',
            cartUuid: $cart2,
            storeId: $this->store->id,
            reservedAmount: 50000.00
        );
    }

    public function test_second_cashier_can_reserve_after_first_cashier_releases_voucher(): void
    {
        $voucher = Voucher::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'LIMITED-REL',
            'name' => 'Voucher Release Test',
            'discount_type' => VoucherDiscountTypeEnum::FIXED_AMOUNT,
            'discount_value' => 25000.00,
            'max_uses' => 1,
            'used_count' => 0,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addDays(10),
            'status' => VoucherStatusEnum::ACTIVE,
        ]);

        $cart1 = Str::uuid()->toString();
        $cart2 = Str::uuid()->toString();

        // Kasir 1 mereserve
        $this->voucherService->reserveVoucher(
            code: 'LIMITED-REL',
            cartUuid: $cart1,
            storeId: $this->store->id,
        );

        // Kasir 1 membatalkan cart
        $this->voucherService->releaseVoucher($cart1);

        // Kasir 2 sekarang berhasil mereserve karena kuota sudah dilepaskan
        $reservation2 = $this->voucherService->reserveVoucher(
            code: 'LIMITED-REL',
            cartUuid: $cart2,
            storeId: $this->store->id,
        );

        $this->assertNotNull($reservation2);
        $this->assertEquals($cart2, $reservation2->cart_uuid);
    }
}
