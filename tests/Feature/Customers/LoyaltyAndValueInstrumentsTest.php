<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Livewire\Customers\LoyaltyAndValueInstrumentManager;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreCreditAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use App\Modules\Customers\Enums\GiftCardStatusEnum;
use App\Modules\Customers\Enums\StoreCreditStatusEnum;
use App\Modules\Customers\Enums\VoucherDiscountTypeEnum;
use App\Modules\Customers\Services\GiftCardService;
use App\Modules\Customers\Services\LoyaltyService;
use App\Modules\Customers\Services\StoreCreditService;
use App\Modules\Customers\Services\VoucherService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoyaltyAndValueInstrumentsTest extends TestCase
{
    use RefreshDatabase;

    protected LoyaltyService $loyaltyService;

    protected VoucherService $voucherService;

    protected GiftCardService $giftCardService;

    protected StoreCreditService $storeCreditService;

    protected Tenant $tenantA;

    protected Store $storeA;

    protected User $ownerA;

    protected Customer $customerA;

    protected Tenant $tenantB;

    protected Store $storeB;

    protected User $ownerB;

    protected Customer $customerB;

    protected function afterSubclassSetUp(): void
    {
        $this->loyaltyService = app(LoyaltyService::class);
        $this->voucherService = app(VoucherService::class);
        $this->giftCardService = app(GiftCardService::class);
        $this->storeCreditService = app(StoreCreditService::class);

        $context = app(TenantContext::class);

        // Setup Tenant A
        $this->tenantA = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'Distribusi Retail Prima',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);
        });

        $context->setTenantId($this->tenantA->id);

        $this->storeA = Store::create([
            'name' => 'Store Jakarta Prima',
        ]);
        $context->setStoreId($this->storeA->id);

        $ownerRoleA = Role::firstOrCreate(
            ['name' => 'Owner', 'tenant_id' => $this->tenantA->id],
            ['display_name' => 'Owner', 'is_admin' => true]
        );

        $this->ownerA = User::create([
            'name' => 'Owner Prima',
            'email' => 'owner@prima.test',
            'password' => bcrypt('password'),
            'role_id' => $ownerRoleA->id,
            'is_active' => true,
        ]);
        $this->ownerA->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

        $this->customerA = Customer::create([
            'customer_code' => 'CUST-A-001',
            'name' => 'Pelanggan Setia A',
            'phone' => '+6281234567890',
            'email' => 'pelanggan.a@prima.test',
            'is_active' => true,
        ]);

        // Setup Tenant B for isolation checks
        $this->tenantB = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'Kompetitor Retail B',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);
        });

        $context->setTenantId($this->tenantB->id);

        $this->storeB = Store::create([
            'name' => 'Store Bandung Retail',
        ]);
        $context->setStoreId($this->storeB->id);

        $ownerRoleB = Role::firstOrCreate(
            ['name' => 'Owner', 'tenant_id' => $this->tenantB->id],
            ['display_name' => 'Owner', 'is_admin' => true]
        );

        $this->ownerB = User::create([
            'name' => 'Owner Bandung',
            'email' => 'owner@bandung.test',
            'password' => bcrypt('password'),
            'role_id' => $ownerRoleB->id,
            'is_active' => true,
        ]);
        $this->ownerB->stores()->attach($this->storeB->id, ['tenant_id' => $this->tenantB->id]);

        $this->customerB = Customer::create([
            'customer_code' => 'CUST-B-001',
            'name' => 'Pelanggan Bandung B',
            'phone' => '+6289876543210',
            'email' => 'pelanggan.b@bandung.test',
            'is_active' => true,
        ]);

        // Reset to Tenant A
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);
    }

    public function test_loyalty_earn_redeem_and_reversal(): void
    {
        $this->actingAs($this->ownerA);

        // 1. Earn 500 poin
        $earnResult = $this->loyaltyService->earnPoints(
            customer: $this->customerA,
            points: 500,
            orderId: null,
            reference: 'Pembelian Minuman Botol',
            actor: $this->ownerA
        );

        $this->assertEquals(500, $earnResult['balance_after']);
        $this->assertEquals(500, $earnResult['points_delta']);

        // 2. Redeem 200 poin
        $redeemResult = $this->loyaltyService->redeemPoints(
            customer: $this->customerA,
            points: 200,
            orderId: null,
            reference: 'Tukar Diskon Rp 20.000',
            actor: $this->ownerA
        );

        $this->assertEquals(300, $redeemResult['balance_after']);
        $this->assertEquals(-200, $redeemResult['points_delta']);

        // 3. Reversal 100 poin (misal pembatalan pesanan yang sempat menghasilkan poin)
        $reverseResult = $this->loyaltyService->reversePoints(
            customer: $this->customerA,
            points: 100,
            orderId: null,
            reason: 'Pembatalan transaksi',
            actor: $this->ownerA
        );

        $this->assertEquals(200, $reverseResult['balance_after']);
        $this->assertEquals(-100, $reverseResult['points_delta']);
    }

    public function test_voucher_lifecycle_and_validation_rules(): void
    {
        $this->actingAs($this->ownerA);

        // 1. Buat Voucher Diskon Persentase 20%, Maks Rp 50.000, Min Belanja Rp 100.000
        $voucherPercent = $this->voucherService->createVoucher([
            'code' => 'HEMAT20',
            'name' => 'Promo Diskon 20 Persen',
            'discount_type' => VoucherDiscountTypeEnum::PERCENTAGE->value,
            'discount_value' => '20.00',
            'max_discount_amount' => '50000.00',
            'min_order_amount' => '100000.00',
            'max_uses' => 2,
            'valid_until' => Carbon::now()->addDays(7)->toDateString(),
        ], $this->ownerA);

        $this->assertDatabaseHas('vouchers', [
            'id' => $voucherPercent->id,
            'tenant_id' => $this->tenantA->id,
            'code' => 'HEMAT20',
            'used_count' => 0,
        ]);

        // 2. Validasi gagal jika belanja kurang dari minimum
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('belum memenuhi syarat minimum voucher');
        $this->voucherService->validateVoucher('HEMAT20', $this->customerA, '80000.00');
    }

    public function test_voucher_discount_calculation_and_cap(): void
    {
        $this->actingAs($this->ownerA);

        $voucher = $this->voucherService->createVoucher([
            'code' => 'DISKONCAP50',
            'name' => 'Diskon 20% Maks 50k',
            'discount_type' => VoucherDiscountTypeEnum::PERCENTAGE->value,
            'discount_value' => '20.00',
            'max_discount_amount' => '50000.00',
            'min_order_amount' => '100000.00',
            'max_uses' => 5,
        ], $this->ownerA);

        // Kasus 1: Belanja 200.000 * 20% = 40.000 (tidak kena cap)
        $eval1 = $this->voucherService->validateVoucher('DISKONCAP50', $this->customerA, '200000.00');
        $this->assertEquals('40000.00', $eval1['discount_amount']);

        // Kasus 2: Belanja 500.000 * 20% = 100.000 -> kena cap 50.000
        $eval2 = $this->voucherService->validateVoucher('DISKONCAP50', $this->customerA, '500000.00');
        $this->assertEquals('50000.00', $eval2['discount_amount']);

        // Redeem voucher pertama
        $result = $this->voucherService->redeemVoucher('DISKONCAP50', $this->customerA, '200000.00', 1, $this->ownerA);
        $this->assertEquals('40000.00', $result['discount_amount']);
        $this->assertEquals(1, $result['used_count']);

        $voucher->refresh();
        $this->assertEquals(1, $voucher->used_count);
    }

    public function test_gift_card_issuance_redemption_and_pin_verification(): void
    {
        $this->actingAs($this->ownerA);

        // 1. Terbitkan Gift Card Rp 250.000 dengan PIN 1234
        $giftCard = $this->giftCardService->issueGiftCard([
            'card_number' => 'GC-TEST-123456',
            'amount' => '250000.00',
            'pin' => '1234',
            'customer_id' => $this->customerA->id,
            'notes' => 'Hadiah Kontes Loyalty',
        ], $this->ownerA);

        $this->assertEquals('250000.00', $giftCard->current_balance);
        $this->assertEquals(GiftCardStatusEnum::ACTIVE, $giftCard->status);
        $this->assertDatabaseHas('gift_card_ledgers', [
            'gift_card_id' => $giftCard->id,
            'entry_type' => 'ISSUE',
            'amount' => 250000.00,
            'balance_after' => 250000.00,
        ]);

        // 2. Tebus Rp 100.000 dengan PIN yang benar
        $redemptionResult = $this->giftCardService->redeemGiftCard(
            cardNumber: $giftCard->card_number,
            amount: '100000.00',
            storeId: $this->storeA->id,
            pin: '1234',
            referenceType: 'ORDER',
            referenceId: 99,
            actor: $this->ownerA
        );

        $giftCard->refresh();
        $this->assertEquals('150000.00', $giftCard->current_balance);
        $this->assertEquals('150000.00', $redemptionResult['balance_after']);
        $this->assertDatabaseHas('gift_card_ledgers', [
            'gift_card_id' => $giftCard->id,
            'entry_type' => 'REDEEM',
            'amount' => -100000.00,
            'balance_after' => 150000.00,
        ]);

        // 3. Tebus dengan PIN salah harus ditolak
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('PIN Gift Card yang dimasukkan salah');
        $this->giftCardService->redeemGiftCard(
            cardNumber: $giftCard->card_number,
            amount: '50000.00',
            storeId: $this->storeA->id,
            pin: '9999',
            actor: $this->ownerA
        );
    }

    public function test_gift_card_cannot_exceed_balance(): void
    {
        $this->actingAs($this->ownerA);

        $giftCard = $this->giftCardService->issueGiftCard([
            'card_number' => 'GC-TEST-BALANCE',
            'amount' => '50000.00',
            'pin' => '8888',
        ], $this->ownerA);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Saldo Gift Card tidak mencukupi');
        $this->giftCardService->redeemGiftCard(
            cardNumber: $giftCard->card_number,
            amount: '60000.00',
            storeId: $this->storeA->id,
            pin: '8888',
            actor: $this->ownerA
        );
    }

    public function test_store_credit_deposit_and_redemption(): void
    {
        $this->actingAs($this->ownerA);

        // 1. Deposit Store Credit Rp 300.000
        $depositLedger = $this->storeCreditService->issueStoreCredit(
            customer: $this->customerA,
            amount: '300000.00',
            actor: $this->ownerA,
            referenceType: 'MANUAL_DEPOSIT'
        );

        $this->assertEquals('300000.00', $depositLedger->balance_after);

        $account = StoreCreditAccount::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('customer_id', $this->customerA->id)
            ->firstOrFail();

        $this->assertEquals('300000.00', $account->current_balance);
        $this->assertEquals(StoreCreditStatusEnum::ACTIVE, $account->status);

        // 2. Redeem Store Credit Rp 125.000
        $redeemLedger = $this->storeCreditService->redeemStoreCredit(
            customer: $this->customerA,
            amount: '125000.00',
            actor: $this->ownerA,
            referenceType: 'SALES_ORDER',
            referenceId: 501
        );

        $this->assertEquals('175000.00', $redeemLedger->balance_after);

        $account->refresh();
        $this->assertEquals('175000.00', $account->current_balance);
        $this->assertDatabaseHas('store_credit_ledgers', [
            'store_credit_account_id' => $account->id,
            'entry_type' => 'REDEEM_ORDER',
            'amount' => -125000.00,
            'balance_after' => 175000.00,
        ]);
    }

    public function test_store_credit_rejects_overdraft(): void
    {
        $this->actingAs($this->ownerA);

        $this->storeCreditService->issueStoreCredit(
            customer: $this->customerA,
            amount: '50000.00',
            actor: $this->ownerA
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Saldo Store Credit tidak mencukupi');
        $this->storeCreditService->redeemStoreCredit(
            customer: $this->customerA,
            amount: '75000.00',
            actor: $this->ownerA
        );
    }

    public function test_tenant_isolation_for_value_instruments(): void
    {
        $this->actingAs($this->ownerA);

        // Buat Voucher dan Gift Card di Tenant A
        $voucherA = $this->voucherService->createVoucher([
            'code' => 'TENANTAONLY',
            'name' => 'Voucher Eksklusif A',
            'discount_type' => VoucherDiscountTypeEnum::FIXED_AMOUNT->value,
            'discount_value' => '25000.00',
            'min_order_amount' => '50000.00',
        ], $this->ownerA);

        $giftCardA = $this->giftCardService->issueGiftCard([
            'card_number' => 'GC-TENANT-A-999',
            'amount' => '100000.00',
            'pin' => '0000',
        ], $this->ownerA);

        // Beralih ke Tenant B
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantB->id);
        $context->setStoreId($this->storeB->id);
        $this->actingAs($this->ownerB);

        // Coba validasi voucher Tenant A dari konteks Tenant B -> Harus Ditolak
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("Voucher dengan kode 'TENANTAONLY' tidak ditemukan");
        $this->voucherService->validateVoucher('TENANTAONLY', $this->customerB, '100000.00');
    }

    public function test_livewire_manager_renders_and_executes_actions(): void
    {
        $this->actingAs($this->ownerA);

        Livewire::test(LoyaltyAndValueInstrumentManager::class)
            ->assertOk()
            ->assertSee('Loyalty')
            ->set('voucher_code', 'TESTDISC')
            ->set('voucher_name', 'Test Diskon Livewire')
            ->set('voucher_discount_type', 'PERCENTAGE')
            ->set('voucher_discount_value', '10')
            ->set('voucher_min_order', '50000')
            ->call('createVoucher')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('vouchers', [
            'tenant_id' => $this->tenantA->id,
            'code' => 'TESTDISC',
        ]);
    }
}
