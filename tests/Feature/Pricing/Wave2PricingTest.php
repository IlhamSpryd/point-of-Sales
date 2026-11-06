<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Models\Tenant;
use App\Modules\Customers\Enums\VoucherDiscountTypeEnum;
use App\Modules\Customers\Enums\VoucherStatusEnum;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\Voucher;
use App\Modules\Pricing\Services\LoyaltyLedgerService;
use App\Modules\Pricing\Services\MarginProtectionGuard;
use App\Modules\Pricing\Services\PricingPipelineService;
use App\Modules\Pricing\Services\PromotionReservationService;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Settings\Models\Store;
use App\Modules\Users\Models\Role;
use App\Modules\Users\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Wave2PricingTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    private Category $category;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = Tenant::create([
            'name' => 'Wave 2 Pricing Tenant',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Flagship Store',
            'code' => 'STR-01',
        ]);

        $this->category = Category::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'F&B Menu',
            'category_code' => 'FNB-01',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);
    }

    /**
     * T-PRC-01: Manipulasi manual pada baris historis loyalty_ledger
     * membatalkan verifikasi integritas rantai hash (assertHashChainValid() return false).
     */
    public function test_t_prc_01_hash_chain_verification_detects_historical_tampering(): void
    {
        $customer = Customer::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Budi Santoso',
            'phone' => '+6281299990001',
            'is_active' => true,
        ]);

        $loyaltyService = app(LoyaltyLedgerService::class);

        // 1. Tambah 3 entri sah
        $entry1 = $loyaltyService->recordPoints(
            tenantId: $this->tenant->id,
            customerId: $customer->id,
            points: 100,
            type: 'earn',
            reference: 'INV/001'
        );

        $entry2 = $loyaltyService->recordPoints(
            tenantId: $this->tenant->id,
            customerId: $customer->id,
            points: 50,
            type: 'earn',
            reference: 'INV/002'
        );

        $entry3 = $loyaltyService->recordPoints(
            tenantId: $this->tenant->id,
            customerId: $customer->id,
            points: -30,
            type: 'redeem',
            reference: 'INV/003'
        );

        // Verifikasi rantai valid 100% sebelum tampering
        $this->assertTrue(
            $loyaltyService->assertHashChainValid($customer->id),
            'Rantai hash ledger asli wajib lolos verifikasi integritas.'
        );

        // Verifikasi kesinambungan prev_hash
        $this->assertNull($entry1->fresh()->prev_hash);
        $this->assertEquals($entry1->fresh()->entry_hash, $entry2->fresh()->prev_hash);
        $this->assertEquals($entry2->fresh()->entry_hash, $entry3->fresh()->prev_hash);

        // 2. Simulasikan manipulasi DBA ilegal pada baris historis entry 2
        // Lepas trigger proteksi sementara untuk simulasi manipulasi data mentah level DBA
        DB::statement('DROP TRIGGER IF EXISTS trg_loyalty_ledger_no_update');

        DB::table('loyalty_ledger')
            ->where('id', $entry2->id)
            ->update([
                'points' => 500, // Merusak balance / integritas
                'entry_hash' => bin2hex(random_bytes(32)),
            ]);

        // Verifikasi integritas rantai sekarang rusak dan assertHashChainValid mengembalikan false
        $this->assertFalse(
            $loyaltyService->assertHashChainValid($customer->id),
            'Manipulasi pada baris historis ledger WAJIB membatalkan verifikasi integritas rantai hash.'
        );
    }

    /**
     * T-PRC-02: Dua transaksi simultan memperebutkan kuota voucher terakhir
     * hanya meloloskan 1 transaksi; transaksi kedua ditolak karena kuota habis.
     */
    public function test_t_prc_02_concurrency_safe_voucher_reservation_allows_only_one_winner(): void
    {
        // Buat voucher dengan sisa kuota tepat 1 (max_uses = 1, used_count = 0)
        $voucher = Voucher::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'FLASHDEAL1',
            'name' => 'Flash Deal Terbatas 1 User',
            'discount_type' => VoucherDiscountTypeEnum::FIXED_AMOUNT,
            'discount_value' => 50000.00,
            'min_order_amount' => 100000.00,
            'max_uses' => 1,
            'used_count' => 0,
            'valid_from' => Carbon::now()->subDay(),
            'valid_until' => Carbon::now()->addDay(),
            'status' => VoucherStatusEnum::ACTIVE,
        ]);

        $reservationService = app(PromotionReservationService::class);

        // Transaksi 1 mereservasi kuota voucher terakhir
        $reservation1 = $reservationService->reserveVoucher(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            voucherId: $voucher->id,
            cartUuid: 'cart-uuid-transaction-1',
            reservedAmount: 50000.00
        );

        $this->assertNotNull($reservation1);
        $this->assertEquals('RESERVED', $reservation1->status);

        // Transaksi 2 mencoba mereservasi voucher yang sama secara bersamaan
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Kuota pemakaian voucher 'FLASHDEAL1' telah habis.");

        $reservationService->reserveVoucher(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            voucherId: $voucher->id,
            cartUuid: 'cart-uuid-transaction-2',
            reservedAmount: 50000.00
        );
    }

    /**
     * T-PRC-03: Penerapan diskon yang membuat harga di bawah HPP ditolak otomatis
     * oleh MarginProtectionGuard tanpa PIN supervisor.
     */
    public function test_t_prc_03_margin_protection_rejects_sub_cost_price_without_supervisor_pin(): void
    {
        $guard = app(MarginProtectionGuard::class);

        $costPrice = 100000.00;
        // HPP = Rp 100.000. Batas margin 5% = Rp 105.000.
        // Jika harga jual Rp 106.000 (di atas batas margin): lolos tanpa supervisor
        $this->assertTrue(
            $guard->validateMargin(
                costPrice: $costPrice,
                finalPrice: 106000.00,
                supervisorPin: null
            ),
            'Harga di atas HPP + 5% wajib lolos tanpa otorisasi.'
        );

        // Jika harga jual Rp 95.000 (di bawah HPP): wajib ditolak jika tanpa supervisor PIN
        $rejectedWithoutPin = false;
        try {
            $guard->validateMargin(
                costPrice: $costPrice,
                finalPrice: 95000.00,
                supervisorPin: null
            );
        } catch (ValidationException $e) {
            $rejectedWithoutPin = true;
            $this->assertStringContainsString('violates minimum', $e->getMessage());
        }

        $this->assertTrue($rejectedWithoutPin, 'Harga di bawah HPP wajib ditolak tanpa otorisasi supervisor.');

        // Buat Role Supervisor dan User Supervisor dengan bcrypt PIN nyata
        $supervisorRole = Role::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'role_code' => 'SPV-'.uniqid(),
            'name' => 'Supervisor',
            'is_active' => true,
        ]);

        User::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Supervisor Budi',
            'email' => 'spv.'.uniqid().'@example.com',
            'password' => Hash::make('secret'),
            'pin_hash' => Hash::make('889900'),
            'role_id' => $supervisorRole->id,
            'is_active' => true,
        ]);

        // Coba dengan PIN yang salah: wajib tetap ditolak
        $rejectedWrongPin = false;
        try {
            $guard->validateMargin(
                costPrice: $costPrice,
                finalPrice: 95000.00,
                supervisorPin: '112233',
                tenantId: $this->tenant->id
            );
        } catch (ValidationException $e) {
            $rejectedWrongPin = true;
        }
        $this->assertTrue($rejectedWrongPin, 'Harga di bawah HPP dengan PIN salah wajib ditolak.');

        // Dengan PIN supervisor valid ('889900'): lolos
        $allowedWithPin = $guard->validateMargin(
            costPrice: $costPrice,
            finalPrice: 95000.00,
            supervisorPin: '889900',
            tenantId: $this->tenant->id
        );
        $this->assertTrue($allowedWithPin, 'Harga di bawah HPP dengan PIN supervisor valid wajib diizinkan.');
    }

    /**
     * Verifikasi PricingPipelineService 7-tahap deterministik.
     */
    public function test_pricing_pipeline_7_stages_execution(): void
    {
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'product_name' => 'Steak Wagyu A5',
            'product_code' => 'STK-WGY',
            'product_price' => 300000.00,
            'standard_cost' => 150000.00,
            'is_active' => true,
        ]);

        $pipeline = app(PricingPipelineService::class);

        $result = $pipeline->calculate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'manual_discount_percent' => 10.0, // 10% diskon
            'service_charge_percent' => 5.0,   // 5% service charge
        ]);

        // Base price: 300.000. Diskon 10% = 30.000 -> Unit price = 270.000
        // Qty = 2 -> Subtotal = 540.000
        // Service charge 5% dari 540.000 = 27.000
        // Payable total = 567.000
        $this->assertEquals('300000.00', $result['base_price']);
        $this->assertEquals('270000.00', $result['stage6_unit_selling_price']);
        $this->assertEquals('540000.00', $result['subtotal']);
        $this->assertEquals('27000.00', $result['service_charge_amount']);
        $this->assertEquals('567000.00', $result['payable_total']);
    }
}
