<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatusEnum;
use App\Enums\ReturnDispositionEnum;
use App\Models\Category;
use App\Models\Device;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerCreditAccount;
use App\Modules\Sales\Enums\ArLedgerEntryTypeEnum;
use App\Modules\Sales\Enums\CreditNoteStatusEnum;
use App\Modules\Sales\Enums\CustomerInvoiceStatusEnum;
use App\Modules\Sales\Models\AccountingReceivableOutbox;
use App\Modules\Sales\Models\ArLedgerEntry;
use App\Modules\Sales\Models\CustomerInvoice;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Services\AccountsReceivableService;
use App\Modules\Sales\Services\ReturnRefundService;
use App\Services\CashierPinService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class SalesReturnAndCreditNoteIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected User $cashier;

    protected User $supervisor;

    protected Device $device;

    protected Shift $shift;

    protected Product $product;

    protected Customer $customer;

    protected CustomerCreditAccount $creditAccount;

    protected ReturnRefundService $returnService;

    protected AccountsReceivableService $arService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();
            Model::unguard();

            $roleSupervisor = Role::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $this->tenantA->id, 'name' => 'Supervisor'],
                ['is_active' => 1, 'permissions' => ['can_refund_order', 'can_return_order']]
            );

            $roleCashier = Role::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $this->tenantA->id, 'name' => 'Kasir'],
                ['is_active' => 1]
            );

            $this->supervisor = User::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenantA->id,
                'role_id' => $roleSupervisor->id,
                'name' => 'Supervisor A',
                'email' => 'supervisor.a@test.com',
                'password' => bcrypt('secret123'),
                'is_active' => true,
            ]);

            app(CashierPinService::class)->setPin($this->supervisor, '123456');

            $this->cashier = User::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenantA->id,
                'role_id' => $roleCashier->id,
                'name' => 'Cashier A',
                'email' => 'cashier.a@test.com',
                'password' => bcrypt('secret123'),
                'is_active' => true,
            ]);

            $this->device = Device::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'device_name' => 'POS Terminal 01',
                'device_code' => 'POS-01',
                'device_type' => Device::TYPE_POS_TERMINAL,
                'device_fingerprint' => 'fp-term-01',
                'status' => Device::STATUS_PAIRED,
                'is_active' => true,
            ]);

            $this->shift = Shift::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'user_id' => $this->cashier->id,
                'device_id' => $this->device->id,
                'opened_at' => now(),
                'opening_balance' => 1000000.00,
                'status' => 'open',
            ]);

            $category = Category::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenantA->id,
                'category_name' => 'Retail Category',
            ]);

            $this->product = Product::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenantA->id,
                'category_id' => $category->id,
                'product_name' => 'Kemeja Katun Putih',
                'price' => 100000.00,
                'stock' => 50,
                'is_active' => true,
            ]);

            $this->customer = Customer::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenantA->id,
                'customer_code' => 'CUST-001',
                'customer_type' => 'COMPANY',
                'company_name' => 'PT Retail Sejahtera',
                'name' => 'Budi Santoso',
                'email' => 'budi@retailsejahtera.co.id',
                'phone' => '081234567890',
            ]);

            $this->creditAccount = CustomerCreditAccount::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenantA->id,
                'customer_id' => $this->customer->id,
                'credit_limit' => 20000000.00,
                'payment_terms_days' => 30,
                'credit_status' => 'ACTIVE',
                'reserved_exposure' => 0.00,
                'invoiced_exposure' => 0.00,
            ]);
        });

        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);
        $this->actingAs($this->cashier);

        $this->returnService = app(ReturnRefundService::class);
        $this->arService = app(AccountsReceivableService::class);
    }

    protected function createTestOrder(int $qty = 10, float $price = 100000.00): Order
    {
        return app(TenantContext::class)->runAs($this->tenantA->id, $this->storeA->id, function () use ($qty, $price) {
            $total = (float) ($price * $qty);

            $order = Order::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'customer_id' => $this->customer->id,
                'shift_id' => $this->shift->id,
                'created_by_device_id' => $this->device->id,
                'user_id' => $this->cashier->id,
                'order_code' => 'ORD-'.strtoupper(bin2hex(random_bytes(4))),
                'idempotency_key' => (string) Str::uuid(),
                'order_date' => now()->toDateString(),
                'order_type' => 'take_away',
                'order_status' => OrderStatus::Completed,
                'payment_method' => 'cash',
                'subtotal_amount' => $total,
                'order_amount' => $total,
                'tax_amount' => 0.00,
            ]);

            OrderItem::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'order_id' => $order->id,
                'product_id' => $this->product->id,
                'qty' => $qty,
                'order_price' => $price,
                'order_subtotal' => $total,
            ]);

            Payment::forceCreate([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'order_id' => $order->id,
                'shift_id' => $this->shift->id,
                'device_id' => $this->device->id,
                'amount' => $total,
                'payment_method' => PaymentMethod::Cash,
                'status' => PaymentStatusEnum::Captured,
                'captured_at' => now(),
                'processed_by' => $this->cashier->id,
                'idempotency_key' => 'pay-'.(string) Str::uuid(),
            ]);

            return $order;
        });
    }

    public function test_partial_and_staged_returns_validate_against_remaining_returnable_quantity(): void
    {
        $order = $this->createTestOrder(10, 100000.00);
        $orderItem = $order->orderItems()->first();

        // Retur tahap 1: 4 unit
        $result1 = $this->returnService->createReturnWithCreditNote(
            order: $order,
            returnItemsWithDisposition: [
                [
                    'order_item_id' => $orderItem->id,
                    'quantity' => 4,
                    'disposition' => ReturnDispositionEnum::Restock,
                    'disposition_reason' => 'Salah ukuran',
                ],
            ],
            actorUserId: $this->cashier->id,
            supervisorPin: '123456',
            reason: 'Pelanggan minta tukar ukuran',
            idempotencyKey: 'ret-stage-1',
            shiftId: $this->shift->id,
            deviceId: $this->device->id
        );

        $this->assertEquals(400000.00, (float) $result1['credit_note']->total_amount);
        $this->assertEquals(CreditNoteStatusEnum::ISSUED, $result1['credit_note']->status);

        // Retur tahap 2: 4 unit lagi
        $result2 = $this->returnService->createReturnWithCreditNote(
            order: $order,
            returnItemsWithDisposition: [
                [
                    'order_item_id' => $orderItem->id,
                    'quantity' => 4,
                    'disposition' => ReturnDispositionEnum::Restock,
                ],
            ],
            actorUserId: $this->cashier->id,
            supervisorPin: '123456',
            reason: 'Retur sisa batch',
            idempotencyKey: 'ret-stage-2',
            shiftId: $this->shift->id,
            deviceId: $this->device->id
        );

        $this->assertEquals(400000.00, (float) $result2['credit_note']->total_amount);

        // Retur tahap 3: coba 3 unit (padahal sisa cuma 2) -> Wajib Ditolak!
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('melebihi sisa yang dapat diretur');

        $this->returnService->createReturnWithCreditNote(
            order: $order,
            returnItemsWithDisposition: [
                [
                    'order_item_id' => $orderItem->id,
                    'quantity' => 3,
                    'disposition' => ReturnDispositionEnum::Restock,
                ],
            ],
            actorUserId: $this->cashier->id,
            supervisorPin: '123456',
            reason: 'Mencoba over return',
            idempotencyKey: 'ret-stage-3',
            shiftId: $this->shift->id,
            deviceId: $this->device->id
        );
    }

    public function test_restock_disposition_increases_inventory_while_damaged_does_not(): void
    {
        $order = $this->createTestOrder(10, 100000.00);
        $orderItem = $order->orderItems()->first();

        $initialStock = (int) $this->product->fresh()->stock;

        // Retur 2 item dengan RESTOCK -> stok harus bertambah 2
        $this->returnService->createReturnWithCreditNote(
            order: $order,
            returnItemsWithDisposition: [
                [
                    'order_item_id' => $orderItem->id,
                    'quantity' => 2,
                    'disposition' => ReturnDispositionEnum::Restock,
                ],
            ],
            actorUserId: $this->cashier->id,
            supervisorPin: '123456',
            reason: 'Bagus untuk restock',
            idempotencyKey: 'ret-restock-stock',
            shiftId: $this->shift->id,
            deviceId: $this->device->id
        );

        $this->assertEquals($initialStock + 2, (int) $this->product->fresh()->stock);

        // Retur 2 item dengan WRITE_OFF_WASTE (rusak) -> stok TIDAK boleh bertambah ke stok jual
        $this->returnService->createReturnWithCreditNote(
            order: $order,
            returnItemsWithDisposition: [
                [
                    'order_item_id' => $orderItem->id,
                    'quantity' => 2,
                    'disposition' => ReturnDispositionEnum::WriteOffWaste,
                    'disposition_reason' => 'Barang robek saat pengiriman',
                ],
            ],
            actorUserId: $this->cashier->id,
            supervisorPin: '123456',
            reason: 'Barang rusak',
            idempotencyKey: 'ret-damaged-stock',
            shiftId: $this->shift->id,
            deviceId: $this->device->id
        );

        $this->assertEquals($initialStock + 2, (int) $this->product->fresh()->stock);
    }

    public function test_recipe_fb_product_with_ingredients_cannot_be_restocked(): void
    {
        $ingredient = Ingredient::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Biji Kopi Arabika',
            'cost_per_unit' => 200.00,
            'unit' => 'gram',
            'sku' => 'ING-COFFEE-01',
        ]);

        $this->product->ingredients()->attach($ingredient->id, ['quantity_required' => 15.0000]);

        $order = $this->createTestOrder(2, 50000.00);
        $orderItem = $order->orderItems()->first();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Larangan Restock: Produk F&B berbasis resep');

        $this->returnService->createReturnWithCreditNote(
            order: $order,
            returnItemsWithDisposition: [
                [
                    'order_item_id' => $orderItem->id,
                    'quantity' => 1,
                    'disposition' => ReturnDispositionEnum::Restock,
                ],
            ],
            actorUserId: $this->cashier->id,
            supervisorPin: '123456',
            reason: 'Coba restock kopi seduh',
            idempotencyKey: 'ret-fb-fail',
            shiftId: $this->shift->id,
            deviceId: $this->device->id
        );
    }

    public function test_sales_credit_note_applied_to_customer_invoice_reduces_balance_due_and_records_ar_ledger(): void
    {
        // 1. Buat Faktur Piutang (AR) sebesar Rp 5.000.000
        $invoice = CustomerInvoice::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'invoice_number' => 'INV-2027-001',
            'customer_id' => $this->customer->id,
            'status' => CustomerInvoiceStatusEnum::DRAFT,
            'invoice_date' => Carbon::today(),
            'due_date' => Carbon::today()->addDays(30),
            'subtotal' => 5000000.00,
            'total_amount' => 5000000.00,
            'balance_due' => 5000000.00,
            'paid_amount' => 0.00,
            'credit_applied_amount' => 0.00,
            'idempotency_key' => 'inv-cn-test-01',
        ]);

        $this->arService->postInvoice($invoice, $this->supervisor);

        $this->assertEquals(5000000.00, (float) $this->creditAccount->fresh()->invoiced_exposure);
        $this->assertEquals(CustomerInvoiceStatusEnum::POSTED, $invoice->fresh()->status);

        // 2. Terbitkan SalesCreditNote sebesar Rp 2.000.000
        $creditNote = $this->returnService->issueCreditNote(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            customerId: $this->customer->id,
            amount: '2000000.00',
            reasonCode: 'PRICE_CORRECTION',
            originInvoiceId: $invoice->id,
            actorUserId: $this->supervisor->id,
            notes: 'Koreksi diskon kontrak B2B',
            idempotencyKey: 'cn-issue-01'
        );

        $this->assertEquals('2000000.00', (string) $creditNote->remaining_amount);
        $this->assertEquals(CreditNoteStatusEnum::ISSUED, $creditNote->status);

        // 3. Aplikasikan Nota Kredit ke Faktur Piutang
        $application = $this->returnService->applyCreditNoteToInvoice(
            creditNote: $creditNote,
            invoice: $invoice->fresh(),
            amount: '2000000.00',
            actorUserId: $this->supervisor->id,
            idempotencyKey: 'app-cn-01'
        );

        // 4. Verifikasi Invariant Faktur
        $refreshedInvoice = $invoice->fresh();
        $this->assertEquals(3000000.00, (float) $refreshedInvoice->balance_due);
        $this->assertEquals(2000000.00, (float) $refreshedInvoice->credit_applied_amount);
        $this->assertEquals(CustomerInvoiceStatusEnum::PARTIALLY_PAID, $refreshedInvoice->status);

        // 5. Verifikasi Nota Kredit telah habis (APPLIED)
        $refreshedCn = $creditNote->fresh();
        $this->assertEquals(0.00, (float) $refreshedCn->remaining_amount);
        $this->assertEquals(2000000.00, (float) $refreshedCn->applied_amount);
        $this->assertEquals(CreditNoteStatusEnum::APPLIED, $refreshedCn->status);

        // 6. Verifikasi Invoiced Exposure Akun Kredit Pelanggan turun
        $this->assertEquals(3000000.00, (float) $this->creditAccount->fresh()->invoiced_exposure);

        // 7. Verifikasi Buku Besar AR (ArLedgerEntry - CREDIT_NOTE)
        $ledger = ArLedgerEntry::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('reference_type', SalesCreditNote::class)
            ->where('reference_id', $creditNote->id)
            ->first();

        $this->assertNotNull($ledger);
        $this->assertEquals(ArLedgerEntryTypeEnum::CREDIT_NOTE, $ledger->entry_type);
        $this->assertEquals(2000000.00, (float) $ledger->credit_amount);
        $this->assertEquals(0.00, (float) $ledger->debit_amount);

        // 8. Verifikasi Outbox Event
        $outbox = AccountingReceivableOutbox::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('event_type', 'CREDIT_NOTE_APPLIED')
            ->where('source_document_id', $invoice->id)
            ->first();

        $this->assertNotNull($outbox);
    }

    public function test_cannot_over_apply_credit_note_or_apply_to_different_customer(): void
    {
        $invoice = CustomerInvoice::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'invoice_number' => 'INV-2027-002',
            'customer_id' => $this->customer->id,
            'status' => CustomerInvoiceStatusEnum::POSTED,
            'invoice_date' => Carbon::today(),
            'due_date' => Carbon::today()->addDays(30),
            'subtotal' => 1000000.00,
            'total_amount' => 1000000.00,
            'balance_due' => 1000000.00,
            'paid_amount' => 0.00,
            'credit_applied_amount' => 0.00,
            'idempotency_key' => 'inv-cn-over-01',
        ]);

        $creditNote = $this->returnService->issueCreditNote(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            customerId: $this->customer->id,
            amount: '500000.00',
            reasonCode: 'RETURN',
            actorUserId: $this->supervisor->id
        );

        // Coba aplikasi lebih dari sisa nota kredit (Rp 600.000 > Rp 500.000)
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('melebihi sisa nota kredit');

        $this->returnService->applyCreditNoteToInvoice(
            creditNote: $creditNote,
            invoice: $invoice,
            amount: '600000.00',
            actorUserId: $this->supervisor->id
        );
    }

    public function test_convert_credit_note_to_store_credit(): void
    {
        $creditNote = $this->returnService->issueCreditNote(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            customerId: $this->customer->id,
            amount: '750000.00',
            reasonCode: 'RETURN',
            actorUserId: $this->supervisor->id
        );

        $converted = $this->returnService->convertCreditNoteToStoreCredit(
            creditNote: $creditNote,
            actorUserId: $this->supervisor->id
        );

        $this->assertEquals(CreditNoteStatusEnum::CONVERTED_TO_STORE_CREDIT, $converted->status);
        $this->assertEquals(0.00, (float) $converted->remaining_amount);
    }
}
