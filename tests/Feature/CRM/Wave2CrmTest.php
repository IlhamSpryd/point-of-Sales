<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Models\Tenant;
use App\Modules\CRM\Services\CustomerMergeService;
use App\Modules\CRM\Services\CustomerRegistrationService;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerMergeLog;
use App\Modules\Pricing\Services\LoyaltyLedgerService;
use App\Modules\Sales\Models\Order;
use App\Modules\Settings\Models\Store;
use App\Modules\Users\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Wave2CrmTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    private User $user;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = Tenant::create([
            'name' => 'Wave 2 CRM Tenant',
            'status' => 'active',
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Main Outlet',
            'code' => 'OUT-01',
        ]);

        $this->user = User::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cashier One',
            'email' => 'cashier.crm.'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);
    }

    /**
     * T-CRM-01: Registrasi pelanggan baru dengan nomor telepon yang sama
     * pada tenant yang sama ditolak oleh constraint basis data (phone_uniqueness_key).
     */
    public function test_t_crm_01_duplicate_phone_registration_in_same_tenant_is_rejected(): void
    {
        $registrationService = app(CustomerRegistrationService::class);

        // 1. Daftarkan pelanggan pertama dengan nomor HP format lokal
        $customer1 = $registrationService->register([
            'tenant_id' => $this->tenant->id,
            'name' => 'Ahmad Dahlan',
            'phone' => '081234567890', // Format 08... akan dinormalisasi ke +6281234567890
        ]);

        $this->assertEquals('+6281234567890', $customer1->phone);
        $this->assertTrue((bool) $customer1->is_active);

        // 2. Coba daftarkan pelanggan kedua dengan nomor HP yang sama persis (meski format penulisan beda: +6281234567890)
        $this->expectException(ValidationException::class);

        $registrationService->register([
            'tenant_id' => $this->tenant->id,
            'name' => 'Ahmad Duplikat',
            'phone' => '+6281234567890',
        ]);
    }

    /**
     * Buktikan bahwa nomor HP dari pelanggan yang sudah di-soft-delete TIDAK memblokir
     * pendaftaran nomor yang sama di kemudian hari (karena phone_uniqueness_key = null).
     */
    public function test_recycled_phone_allowed_after_soft_delete(): void
    {
        $registrationService = app(CustomerRegistrationService::class);

        $cust = $registrationService->register([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pelanggan Lama',
            'phone' => '087711223344',
        ]);

        // Soft delete pelanggan lama
        $cust->delete();

        // Daftarkan ulang nomor yang sama untuk pelanggan baru
        $newCust = $registrationService->register([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pelanggan Baru Pemilik Nomor Daur Ulang',
            'phone' => '+6287711223344',
        ]);

        $this->assertNotNull($newCust->id);
        $this->assertEquals('+6287711223344', $newCust->phone);
    }

    /**
     * T-CRM-02: Penggabungan profil pelanggan memindahkan seluruh transaksi
     * dan saldo poin ke pelanggan tujuan tanpa ada rekord yatim piatu.
     */
    public function test_t_crm_02_customer_merge_transfers_all_orders_and_loyalty_points(): void
    {
        $registrationService = app(CustomerRegistrationService::class);
        $loyaltyService = app(LoyaltyLedgerService::class);
        $mergeService = app(CustomerMergeService::class);

        // 1. Buat profil utama (Target)
        $targetCustomer = $registrationService->register([
            'tenant_id' => $this->tenant->id,
            'name' => 'Siti Nurhaliza (Akun Utama)',
            'phone' => '+6281122334455',
        ]);

        // Beri saldo awal 50 poin ke target
        $loyaltyService->recordPoints(
            tenantId: $this->tenant->id,
            customerId: $targetCustomer->id,
            points: 50,
            type: 'earn',
            reference: 'TARGET_EARN_INITIAL'
        );

        // 2. Buat profil duplikat (Source)
        $duplicateCustomer = $registrationService->register([
            'tenant_id' => $this->tenant->id,
            'name' => 'Siti N. (Akun Duplikat Kasir)',
            'phone' => '+6281122334499',
        ]);

        // Beri saldo 120 poin ke duplikat
        $loyaltyService->recordPoints(
            tenantId: $this->tenant->id,
            customerId: $duplicateCustomer->id,
            points: 120,
            type: 'earn',
            reference: 'DUPLICATE_EARN_INITIAL'
        );

        // 3. Buat pesanan (orders) atas nama pelanggan duplikat
        $order1 = Order::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'customer_id' => $duplicateCustomer->id,
            'user_id' => $this->user->id,
            'order_code' => 'ORD-TEST-001',
            'order_date' => now(),
            'order_amount' => 150000.00,
            'subtotal_amount' => 150000.00,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $order2 = Order::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'customer_id' => $duplicateCustomer->id,
            'user_id' => $this->user->id,
            'order_code' => 'ORD-TEST-002',
            'order_date' => now(),
            'order_amount' => 200000.00,
            'subtotal_amount' => 200000.00,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        // Verifikasi kondisi awal sebelum merge
        $this->assertEquals(50, $loyaltyService->getCurrentBalance($targetCustomer->id));
        $this->assertEquals(120, $loyaltyService->getCurrentBalance($duplicateCustomer->id));

        // 4. Eksekusi penggabungan profil (Merge)
        $mergeLog = $mergeService->merge(
            tenantId: $this->tenant->id,
            targetCustomerId: $targetCustomer->id,
            duplicateCustomerId: $duplicateCustomer->id,
            reason: 'Pelanggan meminta integrasi histori belanja cabang'
        );

        $this->assertInstanceOf(CustomerMergeLog::class, $mergeLog);

        // 5. Verifikasi pemindahan pesanan: seluruh order duplikat sekarang merujuk ke target customer
        $this->assertEquals($targetCustomer->id, $order1->fresh()->customer_id);
        $this->assertEquals($targetCustomer->id, $order2->fresh()->customer_id);

        $remainingDuplicateOrders = Order::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('customer_id', $duplicateCustomer->id)
            ->count();
        $this->assertEquals(0, $remainingDuplicateOrders, 'Tidak boleh ada pesanan yatim piatu tersisa di akun duplikat.');

        // 6. Verifikasi konsolidasi saldo poin loyalitas:
        // Target: 50 + 120 = 170 poin.
        // Duplikat: 120 - 120 = 0 poin.
        $this->assertEquals(170, $loyaltyService->getCurrentBalance($targetCustomer->id));
        $this->assertEquals(0, $loyaltyService->getCurrentBalance($duplicateCustomer->id));

        // Verifikasi integritas rantai hash kedua customer tetap 100% valid setelah merge
        $this->assertTrue(
            $loyaltyService->assertHashChainValid($targetCustomer->id),
            'Rantai hash loyalty akun target wajib tetap valid setelah transfer poin.'
        );
        $this->assertTrue(
            $loyaltyService->assertHashChainValid($duplicateCustomer->id),
            'Rantai hash loyalty akun duplikat wajib tetap valid setelah deduksi poin.'
        );

        // 7. Verifikasi profil duplikat di-soft delete dan ditandai merged_into
        $freshDuplicate = Customer::withTrashed()->find($duplicateCustomer->id);
        $this->assertTrue($freshDuplicate->trashed());
        $this->assertFalse((bool) $freshDuplicate->is_active);
        $this->assertEquals($targetCustomer->id, (int) $freshDuplicate->merged_into_customer_id);
    }

    /**
     * Verifikasi penanganan walk-in customer anonim.
     */
    public function test_walk_in_customer_provisioning(): void
    {
        $registrationService = app(CustomerRegistrationService::class);

        $walkIn = $registrationService->getOrCreateWalkInCustomer($this->tenant->id, $this->store->id);

        $this->assertNotNull($walkIn->id);
        $this->assertEquals('WALK-IN', $walkIn->customer_code);
        $this->assertNull($walkIn->phone);
        $this->assertFalse((bool) $walkIn->is_loyalty_eligible);
    }
}
