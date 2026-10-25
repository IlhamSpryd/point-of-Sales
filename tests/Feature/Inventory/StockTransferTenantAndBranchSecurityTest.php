<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Role;
use App\Models\StockTransfer;
use App\Models\User;
use App\Modules\Inventory\Services\StockTransferService;
use App\Services\Context\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class StockTransferTenantAndBranchSecurityTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected StockTransferService $service;

    protected User $cashierUser;

    protected User $managerStoreA;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->setupTwoTenants();

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        Model::unguard();

        $cashierRole = Role::withoutTenantScope()->firstOrCreate([
            'name' => 'Cashier',
            'tenant_id' => $this->tenantA->id,
        ], ['is_active' => 1]);

        $managerRole = Role::withoutTenantScope()->firstOrCreate([
            'name' => 'Manager',
            'tenant_id' => $this->tenantA->id,
        ], ['is_active' => 1]);

        $this->cashierUser = User::create([
            'name' => 'Cashier A',
            'email' => 'cashier_st_test@test.com',
            'password' => bcrypt('password'),
            'pin_hash' => bcrypt('123456'),
            'email_verified_at' => now(),
            'tenant_id' => $this->tenantA->id,
            'role_id' => $cashierRole->id,
            'is_active' => 1,
        ]);
        $this->cashierUser->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

        $this->managerStoreA = User::create([
            'name' => 'Manager Store A Only',
            'email' => 'mgr_a_only@test.com',
            'password' => bcrypt('password'),
            'pin_hash' => bcrypt('123456'),
            'email_verified_at' => now(),
            'tenant_id' => $this->tenantA->id,
            'role_id' => $managerRole->id,
            'is_active' => 1,
        ]);
        $this->managerStoreA->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

        $this->service = app(StockTransferService::class);
    }

    public function test_cashier_cannot_create_ship_or_receive_transfer(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Buat dummy transfer
        $transfer = $this->service->createDraft(
            tenantId: (int) $this->tenantA->id,
            fromStoreId: (int) $this->storeA->id,
            toStoreId: (int) $this->storeA2->id,
            data: [
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productA->id,
                        'quantity_requested' => '5.0000',
                    ],
                ],
            ],
            userId: (int) $this->userAOwner->id
        );

        $this->assertFalse(Gate::forUser($this->cashierUser)->allows('create', StockTransfer::class));
        $this->assertFalse(Gate::forUser($this->cashierUser)->allows('ship', $transfer));
        $this->assertFalse(Gate::forUser($this->cashierUser)->allows('receive', $transfer));
        $this->assertFalse(Gate::forUser($this->cashierUser)->allows('cancel', $transfer));
    }

    public function test_store_manager_cannot_receive_transfer_destined_for_another_store(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Transfer dikirim dari Store A ke Store A2
        $transfer = $this->service->createDraft(
            tenantId: (int) $this->tenantA->id,
            fromStoreId: (int) $this->storeA->id,
            toStoreId: (int) $this->storeA2->id,
            data: [
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productA->id,
                        'quantity_requested' => '5.0000',
                    ],
                ],
            ],
            userId: (int) $this->userAOwner->id
        );

        // Manager Store A berhak mengirim transfer dari Store A
        $this->assertTrue(Gate::forUser($this->managerStoreA)->allows('ship', $transfer));

        // Tetapi Manager Store A TIDAK berhak menerima transfer yang ditujukan ke Store A2
        $this->assertFalse(Gate::forUser($this->managerStoreA)->allows('receive', $transfer));
    }

    public function test_cannot_create_transfer_across_tenants_due_to_validation_and_foreign_key(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Coba transfer dari Tenant A (storeA) ke Tenant B (storeB)
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tidak ditemukan untuk tenant ini');

        $this->service->createDraft(
            tenantId: (int) $this->tenantA->id,
            fromStoreId: (int) $this->storeA->id,
            toStoreId: (int) $this->storeB->id, // Store milik Tenant B!
            data: [
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productA->id,
                        'quantity_requested' => '5.0000',
                    ],
                ],
            ],
            userId: (int) $this->userAOwner->id
        );
    }

    public function test_cannot_transfer_to_the_same_store(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // 1. Layer aplikasi menolak
        try {
            $this->service->createDraft(
                tenantId: (int) $this->tenantA->id,
                fromStoreId: (int) $this->storeA->id,
                toStoreId: (int) $this->storeA->id, // Cabang asal == Cabang tujuan
                data: [
                    'items' => [
                        [
                            'item_type' => 'product',
                            'product_id' => (int) $this->productA->id,
                            'quantity_requested' => '5.0000',
                        ],
                    ],
                ],
                userId: (int) $this->userAOwner->id
            );
            $this->fail('Harus gagal validasi cabang asal sama dengan cabang tujuan');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak boleh sama', $e->getMessage());
        }

        // 2. Layer basis data (MariaDB Trigger) menolak jika ada bypass
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('cannot be the same as');

        DB::table('stock_transfers')->insert([
            'tenant_id' => $this->tenantA->id,
            'from_store_id' => $this->storeA->id,
            'to_store_id' => $this->storeA->id,
            'transfer_number' => 'TRF-TEST-SAME-STORE',
            'status' => 'DRAFT',
            'created_by' => $this->userAOwner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_mariadb_trigger_enforces_item_xor_constraint(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $transfer = $this->service->createDraft(
            tenantId: (int) $this->tenantA->id,
            fromStoreId: (int) $this->storeA->id,
            toStoreId: (int) $this->storeA2->id,
            data: [
                'items' => [
                    [
                        'item_type' => 'product',
                        'product_id' => (int) $this->productA->id,
                        'quantity_requested' => '5.0000',
                    ],
                ],
            ],
            userId: (int) $this->userAOwner->id
        );

        // Percobaan bypass: insert item tanpa product_id dan tanpa ingredient_id
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('XOR invariant violated');

        DB::table('stock_transfer_items')->insert([
            'tenant_id' => $this->tenantA->id,
            'stock_transfer_id' => $transfer->id,
            'item_type' => 'product',
            'product_id' => null,
            'ingredient_id' => null,
            'quantity_requested' => '5.0000',
            'quantity_shipped' => '0.0000',
            'quantity_received' => '0.0000',
            'discrepancy_quantity' => '0.0000',
            'carrying_unit_cost' => '0.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
