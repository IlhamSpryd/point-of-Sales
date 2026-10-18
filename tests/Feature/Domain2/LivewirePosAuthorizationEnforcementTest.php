<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Enums\OrderStatus;
use App\Livewire\Kasir\CreateOrder;
use App\Livewire\Kasir\OrderHistory;
use App\Livewire\ShiftManager;
use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class LivewirePosAuthorizationEnforcementTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected User $cashier;

    protected User $unauthorizedUser;

    protected User $supervisor;

    protected Device $device;

    protected Product $product;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();
            Model::unguard();

            $this->device = Device::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'device_name' => 'POS Terminal 1',
                'device_code' => 'POS-T1',
                'device_type' => Device::TYPE_POS_TERMINAL,
                'device_fingerprint' => 'fp-pos-1',
                'status' => Device::STATUS_PAIRED,
                'is_active' => true,
            ]);

            $roleKasir = Role::withoutTenantScope()->firstOrCreate(
                ['name' => 'Kasir', 'tenant_id' => $this->tenantA->id],
                ['is_active' => 1]
            );

            $roleWaiter = Role::withoutTenantScope()->firstOrCreate(
                ['name' => 'Waiter', 'tenant_id' => $this->tenantA->id],
                ['is_active' => 1]
            );

            $roleSupervisor = Role::withoutTenantScope()->firstOrCreate(
                ['name' => 'Supervisor', 'tenant_id' => $this->tenantA->id],
                ['is_active' => 1, 'permissions' => ['can_void_order', 'orders.void']]
            );

            $this->cashier = User::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Kasir POS',
                'email' => 'cashier@pos.test',
                'password' => bcrypt('password'),
                'pin_hash' => bcrypt('123456'),
                'role_id' => $roleKasir->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $this->cashier->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

            $this->unauthorizedUser = User::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Pelayan Resto',
                'email' => 'waiter@pos.test',
                'password' => bcrypt('password'),
                'pin_hash' => bcrypt('111111'),
                'role_id' => $roleWaiter->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $this->unauthorizedUser->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

            $this->supervisor = User::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Supervisor Toko',
                'email' => 'supervisor@pos.test',
                'password' => bcrypt('password'),
                'pin_hash' => bcrypt('999999'),
                'role_id' => $roleSupervisor->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $this->supervisor->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

            $this->product = $this->productA;

            Model::reguard();
        });
    }

    /**
     * AC-1.5: Pengguna tanpa hak akses kasir ditolak saat mengakses CreateOrder
     * dan tidak dapat memanggil submitOrder().
     */
    public function test_ac_1_5_unauthorized_user_cannot_submit_order(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Login sebagai Waiter (tanpa izin kasir)
        $this->actingAs($this->unauthorizedUser);

        $initialOrdersCount = Order::count();

        // Mengakses komponen CreateOrder diblokir oleh boot guard (403)
        Livewire::test(CreateOrder::class)
            ->assertForbidden();

        $this->assertEquals($initialOrdersCount, Order::count());
    }

    /**
     * AC-1.5: Void order pada OrderHistory memerlukan izin policy void
     * dan tantangan PIN Supervisor yang valid.
     */
    public function test_ac_1_5_order_history_void_order_requires_permission_and_valid_pin(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Buat pesanan Paid
        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashier->id,
            'order_code' => 'POS-VOID-TEST-01',
            'idempotency_key' => (string) Str::uuid(),
            'order_date' => now()->toDateString(),
            'order_type' => 'dine_in',
            'order_status' => OrderStatus::Paid,
            'payment_method' => 'cash',
            'subtotal_amount' => 25000,
            'tax_amount' => 2750,
            'order_amount' => 27750,
        ]);

        Payment::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'payment_method' => 'cash',
            'amount' => 27750,
            'status' => 'captured',
            'idempotency_key' => 'pmt-void-01',
        ]);

        // 1. Kasir tanpa wewenang void mencoba memanggil voidOrder -> ditolak oleh Policy
        $this->actingAs($this->cashier);

        Livewire::test(OrderHistory::class)
            ->call('voidOrder', $order->id, 'Kasir coba void tanpa izin', '123456')
            ->assertForbidden();

        $order->refresh();
        $this->assertEquals(OrderStatus::Paid, $order->order_status);

        // 2. Supervisor dengan wewenang void memasukkan PIN yang SALAH -> ditolak
        $this->actingAs($this->supervisor);

        Livewire::test(OrderHistory::class)
            ->call('voidOrder', $order->id, 'Alasan void pembeli salah menu', '000000')
            ->assertHasErrors(['supervisorPin']);

        $order->refresh();
        $this->assertEquals(OrderStatus::Paid, $order->order_status);

        // 3. Supervisor dengan PIN BENAR ('999999') -> Sukses void
        Livewire::test(OrderHistory::class)
            ->call('voidOrder', $order->id, 'Alasan void pembeli salah menu', '999999')
            ->assertHasNoErrors();

        $order->refresh();
        $this->assertEquals(OrderStatus::Void, $order->order_status);
        $this->assertEquals('Alasan void pembeli salah menu', $order->void_reason);
    }

    /**
     * AC-1.5: Force close shift pada ShiftManager memerlukan peran Supervisor/Manager
     * dan tantangan PIN yang sah.
     */
    public function test_ac_1_5_shift_manager_force_close_requires_supervisor_role_and_pin(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $shift = Shift::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'device_id' => $this->device->id,
            'user_id' => $this->cashier->id,
            'active_cashier_id' => $this->cashier->id,
            'opening_balance' => 100000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        // 1. Kasir biasa mencoba forceCloseShift -> ditolak oleh Gate/Policy
        $this->actingAs($this->cashier);

        Livewire::test(ShiftManager::class)
            ->call('forceCloseShift', 'Kasir coba paksa tutup', '123456', $shift->id)
            ->assertForbidden();

        $shift->refresh();
        $this->assertEquals('open', $shift->status);

        // 2. Supervisor memanggil forceCloseShift dengan PIN sah -> Berhasil
        $this->actingAs($this->supervisor);

        Livewire::test(ShiftManager::class)
            ->call('forceCloseShift', 'Kasir sakit mendadak', '999999', $shift->id);

        $shift->refresh();
        $this->assertEquals('closed', $shift->status);
        $this->assertEquals('force_closed', $shift->close_type);
        $this->assertEquals($this->supervisor->id, $shift->closed_by_user_id);
    }

    /**
     * AC-1.5: Properti sensitif dengan atribut #[Locked] tidak dapat dimodifikasi
     * dari payload klien di browser.
     */
    public function test_ac_1_5_locked_properties_cannot_be_tampered(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $this->actingAs($this->cashier);

        // Uji properti locked pada CreateOrder
        try {
            Livewire::test(CreateOrder::class)
                ->set('idempotencyKey', 'hacked-idempotency-key');
            $this->fail('Properti #[Locked] idempotencyKey tidak boleh dapat dimodifikasi klien.');
        } catch (CannotUpdateLockedPropertyException $e) {
            $this->assertStringContainsString('idempotencyKey', $e->getMessage());
        }

        try {
            Livewire::test(CreateOrder::class)
                ->set('deviceId', 99999);
            $this->fail('Properti #[Locked] deviceId tidak boleh dapat dimodifikasi klien.');
        } catch (CannotUpdateLockedPropertyException $e) {
            $this->assertStringContainsString('deviceId', $e->getMessage());
        }

        // Uji properti locked pada ShiftManager
        try {
            Livewire::test(ShiftManager::class)
                ->set('deviceId', 99999);
            $this->fail('Properti #[Locked] deviceId pada ShiftManager tidak boleh dapat dimodifikasi klien.');
        } catch (CannotUpdateLockedPropertyException $e) {
            $this->assertStringContainsString('deviceId', $e->getMessage());
        }
    }
}
