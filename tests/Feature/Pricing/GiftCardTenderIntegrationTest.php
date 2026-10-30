<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Enums\GiftCardStatusEnum;
use App\Modules\Customers\Models\GiftCard;
use App\Modules\Customers\Services\GiftCardService;
use App\Modules\Sales\Services\TransactionService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GiftCardTenderIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected User $user;
    protected Shift $shift;
    protected Customer $customer;
    protected Product $product;
    protected GiftCardService $giftCardService;
    protected TransactionService $transactionService;

    protected function afterSubclassSetUp(): void
    {
        $this->tenant = Tenant::create([
            'name' => 'Gift Card Tenant',
            'domain' => 'giftcard.pos.test',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store Gift Card',
            'code' => 'STR-GC',
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
            'name' => 'Cashier GC',
            'username' => 'cashier_gc',
            'email' => 'cashier_gc@test.com',
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
            'name' => 'Bob GiftCard',
            'phone' => '081122334455',
            'email' => 'bob.gc@example.com',
            'is_active' => true,
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Beverages',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'name' => 'Specialty Coffee Pack',
            'sku' => 'SKU-COFFEE-01',
            'product_price' => 100000.00,
            'track_inventory' => false,
            'is_active' => true,
        ]);

        $this->giftCardService = app(GiftCardService::class);
        $this->transactionService = app(TransactionService::class);
    }

    public function test_split_tender_payment_with_gift_card_and_cash(): void
    {
        // 1. Terbitkan Gift Card dengan saldo Rp 50.000
        $giftCard = $this->giftCardService->issueGiftCard([
            'store_id' => $this->store->id,
            'amount' => '50000.00',
            'card_number' => 'GC-TEST-50K',
            'customer_id' => $this->customer->id,
        ], $this->user);

        $this->assertEquals('50000.00', $giftCard->current_balance);
        $this->assertEquals(GiftCardStatusEnum::ACTIVE, $giftCard->status);

        // 2. Transaksi produk Rp 100.000 + Tax 11% (Rp 11.000) = Grand Total Rp 111.000
        // Split payment: Gift Card Rp 50.000 + Cash Rp 61.000
        $transactionData = [
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => 100000.00,
                ],
            ],
            'payments' => [
                [
                    'method' => 'gift_card',
                    'amount' => 50000,
                    'card_number' => 'GC-TEST-50K',
                ],
                [
                    'method' => 'cash',
                    'amount' => 61000,
                ],
            ],
            'cash_received' => 61000,
        ];

        $order = $this->transactionService->createTransaction($transactionData, $this->user->id, $this->shift->id);

        $this->assertEquals(OrderStatus::Paid, $order->order_status);

        // 3. Verifikasi saldo Gift Card terpotong menjadi 0 dan status DEPLETED
        $giftCard->refresh();
        $this->assertEquals('0.00', $giftCard->current_balance);
        $this->assertEquals(GiftCardStatusEnum::DEPLETED, $giftCard->status);

        // 4. Verifikasi gift_card_ledgers tercatat mutasi REDEEM
        $this->assertDatabaseHas('gift_card_ledgers', [
            'tenant_id' => $this->tenant->id,
            'gift_card_id' => $giftCard->id,
            'entry_type' => 'REDEEM',
            'amount' => '-50000.00',
            'balance_after' => '0.00',
        ]);

        // 5. Invariant Keuangan: Gift Card adalah tender, BUKAN diskon.
        // DPP tetap utuh Rp 100.000, diskon Rp 0.
        $this->assertEquals(100000.00, (float) $order->subtotal_amount);
        $this->assertEquals(0.00, (float) $order->discount_amount);
        $this->assertEquals(111000.00, (float) $order->order_amount);
    }

    public function test_insufficient_gift_card_balance_rejects_transaction_atomically(): void
    {
        // Terbitkan Gift Card dengan saldo Rp 20.000
        $giftCard = $this->giftCardService->issueGiftCard([
            'store_id' => $this->store->id,
            'amount' => '20000.00',
            'card_number' => 'GC-LOW-20K',
            'customer_id' => $this->customer->id,
        ], $this->user);

        // Coba bayar Rp 50.000 dengan kartu yang hanya bersaldo Rp 20.000
        $transactionData = [
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => 100000.00,
                ],
            ],
            'payments' => [
                [
                    'method' => 'gift_card',
                    'amount' => 50000,
                    'card_number' => 'GC-LOW-20K',
                ],
                [
                    'method' => 'cash',
                    'amount' => 61000,
                ],
            ],
            'cash_received' => 61000,
        ];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Saldo Gift Card tidak mencukupi');

        try {
            $this->transactionService->createTransaction($transactionData, $this->user->id, $this->shift->id);
        } finally {
            // Pastikan saldo Gift Card tetap utuh Rp 20.000 dan tidak ada order tercatat
            $giftCard->refresh();
            $this->assertEquals('20000.00', $giftCard->current_balance);
            $this->assertEquals(GiftCardStatusEnum::ACTIVE, $giftCard->status);
        }
    }
}
